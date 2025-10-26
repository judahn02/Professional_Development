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

  function openModal(sessionId, index) {
    state.sessionId = Number(sessionId) || 0;
    state.index = Number.isFinite(index) ? index : null;
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const idSpan = overlay.querySelector('#attSessionIdLabel');
    if (idSpan) idSpan.textContent = String(state.sessionId || '');
    const textarea = overlay.querySelector('#attendanceBulkInput');
    if (textarea) textarea.value = '';
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

