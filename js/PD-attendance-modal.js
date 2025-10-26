// PDAttendanceModal: bulk-register attendees for a session via /sessionhome9
(function(){
  'use strict';

  const ALLOWED = ['Certified','Master','None'];

  const state = {
    sessionId: null,
    index: null,
  };

  function getBulkUrl() {
    const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
    const route = (window.PDSessions && window.PDSessions.sessionsRoute9 || '').replace(/^\/+/, '');
    return `${root}/${route}`;
  }

  // New: fetch attendees for this session using the same endpoint as the main table (sessionhome2)
  function getAttendeesUrl(id) {
    const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
    const route = (window.PDSessions && window.PDSessions.sessionsRoute2 || '').replace(/^\/+/, '');
    return `${root}/${route}?sessionid=${encodeURIComponent(id)}`;
  }

  function statusClassFromLabel(label) {
    const v = String(label || '').trim().toLowerCase();
    if (v === 'certified') return 'certified';
    if (v === 'master') return 'master';
    if (v === 'none') return 'none';
    return 'none';
  }

  function statusDisplay(label) {
    const v = String(label || '');
    return v === '' ? 'Not Assigned' : v;
  }

  async function loadAttendeesIntoTable(sessionId) {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const table = overlay.querySelector('#attendees-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    const fmt = (window.PDSessionsUtils && typeof window.PDSessionsUtils.formatAttendeeItem === 'function')
      ? window.PDSessionsUtils.formatAttendeeItem
      : (x) => Array.isArray(x) ? { name: x[0]||'', email: x[1]||'', status: x[2]||'', memberId: x[3]||0 } : { name: '', email: '', status: '', memberId: 0 };

    // Use shared cache from PDSessionsTable when available
    const Table = window.PDSessionsTable;
    let items = [];
    let usedCache = false;
    if (Table && Table.attendeesCache instanceof Map && typeof Table.isAttendeeCacheFresh === 'function') {
      if (Table.attendeesCache.has(sessionId) && Table.isAttendeeCacheFresh(sessionId)) {
        const entry = Table.attendeesCache.get(sessionId);
        if (entry && Array.isArray(entry.items)) {
          items = entry.items.slice();
          usedCache = true;
        }
      }
    }

    if (!usedCache) {
      const url = getAttendeesUrl(sessionId);
      if (!url) return;
      try {
        const res = await fetch(url, {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
          },
          cache: 'no-store',
        });
        if (!res.ok) throw new Error(`REST ${res.status}`);
        const raw = await res.json();
        items = Array.isArray(raw) ? raw.map(fmt) : [];
        // Save into shared cache for reuse
        if (Table && Table.attendeesCache instanceof Map) {
          Table.attendeesCache.set(sessionId, { items: items.slice(), at: Date.now() });
        }
      } catch (err) {
        console.error('PDAttendanceModal load error', err);
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.textContent = 'Failed to load attendees.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
      }
    }

    if (!items.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 3;
      td.textContent = 'No attendees found.';
      tr.appendChild(td);
      tbody.appendChild(tr);
      return;
    }

    for (const a of items) {
      const tr = document.createElement('tr');
      const tdName = document.createElement('td');
      tdName.textContent = a && a.name ? a.name : '';
      tr.appendChild(tdName);
      const tdStatus = document.createElement('td');
      const span = document.createElement('span');
      span.className = `status ${statusClassFromLabel(a && a.status ? a.status : '')}`;
      span.textContent = statusDisplay(a && a.status ? a.status : '');
      tdStatus.appendChild(span);
      tr.appendChild(tdStatus);
      const tdDel = document.createElement('td');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'delete-btn';
      btn.setAttribute('aria-label', 'Delete row');
      btn.textContent = '×';
      tdDel.appendChild(btn);
      tr.appendChild(tdDel);
      tbody.appendChild(tr);
    }
  }

  function openModal(sessionId, index) {
    state.sessionId = Number(sessionId) || 0;
    state.index = Number.isFinite(index) ? index : null;
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const idSpan = overlay.querySelector('#attSessionIdLabel');
    if (idSpan) idSpan.textContent = String(state.sessionId || '');
    // Populate Version7-style table from the same REST endpoint used by the main page
    if (state.sessionId) {
      loadAttendeesIntoTable(state.sessionId);
    }
    overlay.classList.add('active');
    const first = overlay.querySelector('textarea, input, select, button');
    if (first && typeof first.focus === 'function') first.focus();
  }

  function closeModal() {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    overlay.classList.remove('active');
  }

  function normalizeStatus(v) {
    const s = String(v||'').trim().toLowerCase();
    for (const label of ALLOWED) {
      if (s === label.toLowerCase()) return label;
    }
    return null;
  }

  function parseLines(text) {
    const lines = String(text||'').split(/\r?\n/);
    const out = [];
    for (let i = 0; i < lines.length; i++) {
      const raw = lines[i].trim();
      if (!raw) continue;
      // Accept CSV or whitespace separated: member_id,status
      const parts = raw.split(/[\s,]+/).map(s => s.trim()).filter(Boolean);
      if (parts.length < 2) {
        throw new Error(`Line ${i+1}: expected "member_id,status"`);
      }
      const mid = parseInt(parts[0], 10);
      const stat = normalizeStatus(parts[1]);
      if (!Number.isFinite(mid) || mid <= 0) {
        throw new Error(`Line ${i+1}: invalid member id`);
      }
      if (!stat) {
        throw new Error(`Line ${i+1}: status must be Certified | Master | None`);
      }
      out.push([mid, state.sessionId, stat]);
    }
    return out;
  }

  async function submitBulk(ev) {
    if (ev && ev.preventDefault) ev.preventDefault();
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const ta = overlay.querySelector('#attendanceBulkInput');
    const btn = overlay.querySelector('#btnAttendanceSave');
    const bodyText = ta ? ta.value : '';
    if (!state.sessionId) { alert('Missing session id'); return; }
    let items;
    try {
      items = parseLines(bodyText);
    } catch (err) {
      alert(err.message || 'Invalid input');
      return;
    }
    if (items.length === 0) {
      alert('Add at least one attendee line.');
      return;
    }

    const url = getBulkUrl();
    if (!url) { alert('Bulk endpoint not configured.'); return; }
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
    try {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        },
        body: JSON.stringify(items),
      });
      if (!res.ok) {
        const text = await res.text().catch(()=> '');
        throw new Error(`Save failed (${res.status}): ${text.slice(0,300)}`);
      }
      const data = await res.json().catch(()=>({}));
      const added = (data && typeof data.added === 'number') ? data.added : items.length;
      alert(`Registered ${added} attendee(s).`);
      closeModal();
      // Refresh the table view so attendee counts/details reflect changes
      try { if (window.PDSessionsTable && typeof window.PDSessionsTable.refresh === 'function') window.PDSessionsTable.refresh(); } catch(_) {}
    } catch (err) {
      console.error(err);
      alert(err.message || 'Failed to save attendees.');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    }
  }

  function bindOnce() {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const form = overlay.querySelector('#editAttendeesForm');
    if (form && !form._pdSubmitBound) {
      form._pdSubmitBound = true;
      form.addEventListener('submit', submitBulk);
    }
    const btnCancel = overlay.querySelector('#btnAttendanceCancel');
    if (btnCancel && !btnCancel._pdBound) {
      btnCancel._pdBound = true;
      btnCancel.addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
    }
    const closeBtn = overlay.querySelector('#btnAttendanceClose');
    if (closeBtn && !closeBtn._pdBound) {
      closeBtn._pdBound = true;
      closeBtn.addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
    }
  }

  // Listen for the table’s edit-attendees event
  document.addEventListener('pd:edit-attendees', (e) => {
    const d = (e && e.detail) || {};
    bindOnce();
    openModal(d.id, d.index);
  });

  // Expose API
  window.PDAttendanceModal = { open: (id, idx) => { bindOnce(); openModal(id, idx); }, close: closeModal };
})();
