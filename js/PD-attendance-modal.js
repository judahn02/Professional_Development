// PDAttendanceModal: bulk-register attendees for a session via /sessionhome9
(function(){
  'use strict';

  const ALLOWED = ['Certified','Master','None',''];

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

  function getMemberSearchUrl(term, limit = 20) {
    const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
    const route = (window.PDSessions && window.PDSessions.sessionsRoute10 || 'sessionhome10').replace(/^\/+/, '');
    const q = encodeURIComponent(term);
    return `${root}/${route}?search_p=${q}&limit=${encodeURIComponent(limit)}`;
  }

  function getBatchSaveUrl() {
    const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
    const route = (window.PDSessions && window.PDSessions.sessionsRoute11 || 'sessionhome11').replace(/^\/+/, '');
    return `${root}/${route}`;
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

  function createStatusSelect(current, memberId, sessionId) {
    const sel = document.createElement('select');
    sel.className = 'attendees-status-select';
    sel.dataset.memberId = String(memberId || 0);
    const make = (value, text) => {
      const o = document.createElement('option');
      o.value = value;
      o.textContent = text;
      return o;
    };
    sel.appendChild(make('Certified', 'Certified'));
    sel.appendChild(make('Master', 'Master'));
    sel.appendChild(make('None', 'None'));
    sel.appendChild(make('', 'Not Assigned'));
    sel.value = (current || '');
    sel.addEventListener('change', () => {
      const Table = window.PDSessionsTable;
      if (!Table || !(Table.attendeesCache instanceof Map)) return;
      const entry = Table.attendeesCache.get(sessionId);
      if (!entry || !Array.isArray(entry.items)) return;
      const mid = Number(sel.dataset.memberId || 0);
      const idx = entry.items.findIndex(it => Number(it.memberId||0) === mid);
      if (idx >= 0) {
        entry.items[idx].status = sel.value;
        Table.attendeesCache.set(sessionId, { items: entry.items, at: entry.at });
      }
    });
    return sel;
  }

  async function saveAttendees(ev) {
    if (ev && ev.preventDefault) ev.preventDefault();
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const btn = overlay.querySelector('#btnAttendanceSave');
    const sid = state.sessionId;
    const Table = window.PDSessionsTable;
    const entry = Table && Table.attendeesCache instanceof Map ? Table.attendeesCache.get(sid) : null;
    let items = entry && Array.isArray(entry.items) ? entry.items : [];
    // Fallback: if cache empty or missing memberIds, derive from DOM rows
    if (!items.length || items.every(it => !Number(it.memberId||0))) {
      const tableEl = document.getElementById('attendees-table');
      const tbodyEl = tableEl ? (tableEl.querySelector('#attendeesBody') || tableEl.querySelector('tbody')) : null;
      const rows = tbodyEl ? Array.from(tbodyEl.querySelectorAll('tr')) : [];
      items = rows.map(tr => {
        const mid = Number(tr.dataset.memberId || 0);
        const sel = tr.querySelector('select.attendees-status-select');
        const st = sel ? sel.value : '';
        const nameCell = tr.querySelector('td');
        const nm = nameCell ? nameCell.textContent : '';
        return { memberId: mid, status: st, name: nm, email: '' };
      }).filter(it => Number(it.memberId||0) > 0);
      if (Table && Table.attendeesCache instanceof Map) {
        Table.attendeesCache.set(sid, { items: items.slice(), at: Date.now() });
      }
    }
    const payload = {
      session_id: sid,
      attendees: items.filter(it => Number(it.memberId||0) > 0).map(it => ({ member_id: Number(it.memberId), status: (it.status || '') }))
    };
    if (!sid) { showToast('Missing session id.', 'error'); return; }
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
    try {
      const url = getBatchSaveUrl();
      const res = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        },
        body: JSON.stringify(payload),
      });
      if (!res.ok) {
        const text = await res.text().catch(()=> '');
        throw new Error(`Save failed (${res.status}): ${text.slice(0,300)}`);
      }
      await res.json().catch(()=>({}));
      // Invalidate cache for this session so Details refetches from server (trust server state)
      try { if (Table && Table.attendeesCache instanceof Map) Table.attendeesCache.delete(sid); } catch(_) {}
      // Update attendee count cell in main table immediately
      try {
        const idx = state && typeof state.index === 'number' ? state.index : null;
        if (idx != null) {
          const detailsRow = document.getElementById(`attendee-row-${idx}`);
          const mainRow = detailsRow ? detailsRow.previousElementSibling : null;
          if (mainRow) {
            const cells = mainRow.querySelectorAll('td');
            if (cells && cells[10]) cells[10].textContent = String(payload.attendees.length);
          }
          // Do not render list from client state; rely on refetch after refresh
        }
      } catch (_) {}
      // Clear search so the row is visible after refresh (without triggering a queued re-render that could close Details)
      try {
        const si = document.getElementById('searchInput');
        if (si) si.value = '';
        if (Table && typeof Table.setSearchImmediate === 'function') {
          Table.setSearchImmediate('');
        } else if (Table && typeof Table.queueSearch === 'function') {
          // Fallback, but might cause a second re-render; prefer setSearchImmediate when available
          Table.queueSearch('');
        }
      } catch(_) {}

      // Full refresh for counts/session data; request to open and scroll to this session after refresh
      try {
        if (Table) { Table._pendingOpenId = sid; }
        if (Table && typeof Table.refresh === 'function') Table.refresh();
      } catch(_) {}
      closeModal();
      showToast('Attendees saved.', 'success');
    } catch (err) {
      console.error(err);
      showToast(err.message || 'Failed to save attendees.', 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    }
  }

  async function fetchAndSetSessionName(sessionId) {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const nameEl = overlay.querySelector('#attSessionNameLabel');
    if (nameEl) nameEl.textContent = 'Loading...';
    try {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute || '').replace(/^\/+/, '');
      const url = `${root}/${route}?session_id=${encodeURIComponent(sessionId)}`;
      const res = await fetch(url, {
        method: 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Accept': 'application/json', ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}) }
      });
      if (!res.ok) throw new Error('session fetch failed');
      const data = await res.json().catch(()=>null);
      let title = '';
      if (Array.isArray(data) && data.length) {
        const raw = data[0] || {};
        title = String(raw['Title'] || raw['title'] || '').trim();
      }
      if (!title) title = `Session ${sessionId}`;
      if (nameEl) nameEl.textContent = title;
    } catch (err) {
      if (nameEl) nameEl.textContent = `Session ${sessionId}`;
    }
  }

  function bindSearchFilter(sessionId) {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const input = overlay.querySelector('#attendeesSearchInput');
    const table = overlay.querySelector('#attendees-table');
    const tbody = table ? (table.querySelector('#attendeesBody') || table.querySelector('tbody')) : null;
    if (!input || !tbody) return;
    if (input._pdBound) return;
    input._pdBound = true;
    const apply = () => {
      const q = (input.value || '').trim().toLowerCase();
      const rows = tbody.querySelectorAll('tr');
      rows.forEach(tr => {
        const name = (tr.dataset.name || '');
        const email = (tr.dataset.email || '');
        const show = !q || name.includes(q) || email.includes(q);
        tr.style.display = show ? '' : 'none';
      });
    };
    input.addEventListener('input', apply);
  }

  // Simple toast helper inside the overlay
  function showToast(message, type = 'error') {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const toast = document.createElement('div');
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.top = '16px';
    toast.style.right = '16px';
    toast.style.zIndex = '1001';
    toast.style.padding = '10px 12px';
    toast.style.borderRadius = '8px';
    toast.style.color = type === 'error' ? '#7f1d1d' : '#065f46';
    toast.style.background = type === 'error' ? '#fee2e2' : '#d1fae5';
    toast.style.border = type === 'error' ? '1px solid #fecaca' : '1px solid #a7f3d0';
    overlay.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 3500);
  }

  // Debounce helper
  function debounce(fn, ms) {
    let t = null;
    return function(...args) {
      if (t) clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  function setupAttendeeAutocomplete(sessionId) {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const input = overlay.querySelector('#attendeeNewName');
    const statusSel = overlay.querySelector('#attendeeNewStatus');
    if (!input) return;
    // Ensure suggestions list exists directly under the input wrapper so it drops below the textbox
    let wrap = input.parentElement;
    if (!wrap || !wrap.classList.contains('autocomplete-wrap')) {
      const parent = input.parentNode;
      const newWrap = document.createElement('div');
      newWrap.className = 'autocomplete-wrap';
      parent.insertBefore(newWrap, input);
      newWrap.appendChild(input);
      wrap = newWrap;
    }
    let list = wrap.querySelector('#attendeesSuggestions');
    if (!list) {
      list = document.createElement('ul');
      list.className = 'suggestions-list';
      list.id = 'attendeesSuggestions';
      list.setAttribute('role', 'listbox');
      list.style.display = 'none';
      wrap.appendChild(list);
    }
    const hideList = () => { list.style.display = 'none'; list.innerHTML = ''; };

    const onSelect = (member) => {
      // Block add if already an attendee
      const Table = window.PDSessionsTable;
      const entry = Table && Table.attendeesCache instanceof Map ? Table.attendeesCache.get(sessionId) : null;
      const items = entry && Array.isArray(entry.items) ? entry.items : [];
      const exists = items.some(it => Number(it.memberId||0) === Number(member.id||0));
      if (exists) {
        showToast('That member is already an attendee.', 'error');
        hideList();
        input.value = '';
        input.focus();
        return;
      }
      // Determine initial status
      const initialStatus = statusSel ? statusSel.value : '';
      // Add new row in table
      const table = overlay.querySelector('#attendees-table');
      const tbody = table ? (table.querySelector('#attendeesBody') || table.querySelector('tbody')) : null;
      if (tbody) {
        const tr = document.createElement('tr');
        tr.dataset.name = String(member.name||'').toLowerCase();
        tr.dataset.email = String(member.email||'').toLowerCase();
        tr.dataset.memberId = String(member.id || 0);
        const tdName = document.createElement('td');
        tdName.textContent = member.name || '';
        tr.appendChild(tdName);
        const tdStatus = document.createElement('td');
        const sel = createStatusSelect(initialStatus, member.id, sessionId);
        tdStatus.appendChild(sel);
        tr.appendChild(tdStatus);
        const tdDel = document.createElement('td');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'delete-btn';
        btn.setAttribute('aria-label', 'Delete row');
        btn.textContent = '×';
        btn.addEventListener('click', () => {
          const ent = Table2 && Table2.attendeesCache instanceof Map ? Table2.attendeesCache.get(sessionId) : null;
          if (ent && Array.isArray(ent.items)) {
            ent.items = ent.items.filter(it => Number(it.memberId||0) !== Number(member.id||0));
            Table2.attendeesCache.set(sessionId, ent);
          }
          tr.remove();
        });
        tdDel.appendChild(btn);
        tr.appendChild(tdDel);
        // Insert at the top of the attendees body (just under the add row section)
        tbody.insertBefore(tr, tbody.firstChild);
      }
      // Update cache
      const Table2 = window.PDSessionsTable;
      if (Table2 && Table2.attendeesCache instanceof Map) {
        const entry2 = Table2.attendeesCache.get(sessionId) || { items: [], at: Date.now() };
        // Put the new attendee at the beginning of the list
        entry2.items.unshift({ name: member.name || '', email: member.email || '', status: initialStatus || '', memberId: Number(member.id||0) });
        Table2.attendeesCache.set(sessionId, entry2);
      }
      // Reset input and list; focus for next add
      hideList();
      input.value = '';
      input.focus();
    };

    const renderSuggestions = (items) => {
      list.innerHTML = '';
      if (!Array.isArray(items) || items.length === 0) {
        // show helpful note when no results
        const li = document.createElement('li');
        li.className = 'no-results-row';
        li.textContent = 'member might not be synced to external database, please sync in the members page';
        list.appendChild(li);
        list.style.display = 'block';
        return;
      }
      // sort A->Z by name
      items.sort((a,b) => (a.name||'').localeCompare(b.name||'', undefined, { sensitivity: 'base' }));
      // cap 20
      const limited = items.slice(0, 20);
      for (const m of limited) {
        const li = document.createElement('li');
        li.textContent = m.name + (m.email ? ` — ${m.email}` : '');
        li.setAttribute('role', 'option');
        li.addEventListener('click', () => onSelect(m));
        list.appendChild(li);
      }
      list.style.display = 'block';
    };

    const doSearch = async () => {
      const q = (input.value || '').trim();
      if (q.length < 2) { hideList(); return; }
      try {
        const url = getMemberSearchUrl(q, 20);
        const res = await fetch(url, {
          method: 'GET', credentials: 'same-origin',
          headers: { 'Accept': 'application/json', ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}) },
          cache: 'no-store',
        });
        if (res.status === 422) {
          hideList();
          showToast('there is a missmatch of name for member in external database and wp database that was not resolved internally, please contact your support: parallelsovit.', 'error');
          return;
        }
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json().catch(()=>[]);
        // Expect [[name, members_id, email], ...]
        const suggestions = Array.isArray(data)
          ? data.map(row => ({ name: String(row[0]||''), id: Number(row[1]||0), email: String(row[2]||'') }))
          : [];

        // Determine existing attendees based on the current table DOM (source of truth for the modal UI),
        // with a fallback to the shared attendees cache when needed.
        const existingIds = new Set();
        try {
          const tableEl = overlay.querySelector('#attendees-table');
          const tbodyEl = tableEl ? (tableEl.querySelector('#attendeesBody') || tableEl.querySelector('tbody')) : null;
          if (tbodyEl) {
            const rows = tbodyEl.querySelectorAll('tr');
            rows.forEach((tr) => {
              const mid = Number(tr.dataset.memberId || 0);
              if (Number.isFinite(mid) && mid > 0) existingIds.add(mid);
            });
          }
        } catch (_) {}

        // Fallback to cache only if DOM didn't give us anything
        if (existingIds.size === 0) {
          const Table = window.PDSessionsTable;
          const entry = Table && Table.attendeesCache instanceof Map ? Table.attendeesCache.get(sessionId) : null;
          const existing = entry && Array.isArray(entry.items) ? entry.items : [];
          existing.forEach((it) => {
            const mid = Number(it && it.memberId != null ? it.memberId : 0);
            if (Number.isFinite(mid) && mid > 0) existingIds.add(mid);
          });
        }

        const filtered = suggestions.filter((s) => s.id > 0 && !existingIds.has(s.id));

        // If there were matches but all of them are already attendees in the current UI,
        // show a clear message instead of an empty list.
        if (suggestions.length > 0 && filtered.length === 0) {
          showToast('That member is already an attendee of this session.', 'error');
          hideList();
          return;
        }

        renderSuggestions(filtered);
      } catch (err) {
        console.error('member search failed', err);
        hideList();
      }
    };

    if (!input._pdBound) {
      input._pdBound = true;
      input.addEventListener('input', debounce(doSearch, 300));
      // Dismiss suggestions by clicking outside
      document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) hideList();
      });
    }
  }

  async function loadAttendeesIntoTable(sessionId) {
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    const table = overlay.querySelector('#attendees-table');
    if (!table) return;
    const tbody = table.querySelector('#attendeesBody') || table.querySelector('tbody');
    const addTbody = table.querySelector('#attendeesAddBody');
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
      // Show loading row and hide add-new row until response returns
      if (addTbody) addTbody.style.display = 'none';
      const loadingTr = document.createElement('tr');
      const loadingTd = document.createElement('td');
      loadingTd.colSpan = 3;
      loadingTd.textContent = 'Loading attendees...';
      loadingTr.appendChild(loadingTd);
      tbody.appendChild(loadingTr);
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
        tbody.innerHTML = '';
        tbody.appendChild(tr);
        if (addTbody) addTbody.style.display = '';
        return;
      }
      // Remove loading row
      tbody.innerHTML = '';
    }
    // If cache was used, ensure add-new row is visible
    if (usedCache && addTbody) addTbody.style.display = '';

    // Compare actual list size to expected count from the main sessions table (if available)
    const getExpectedCount = () => {
      try {
        const idx = state && typeof state.index === 'number' ? state.index : null;
        if (idx == null) return null;
        const detailsRow = document.getElementById(`attendee-row-${idx}`);
        const mainRow = detailsRow ? detailsRow.previousElementSibling : null;
        if (!mainRow) return null;
        const cells = mainRow.querySelectorAll('td');
        const cell = cells && cells[10] ? cells[10] : null; // Attendees column
        if (!cell) return null;
        const n = parseInt((cell.textContent || '').trim(), 10);
        return Number.isFinite(n) ? n : null;
      } catch (_) {
        return null;
      }
    };
    const expectedCount = getExpectedCount();

    if (!items.length) {
      if (expectedCount === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.textContent = 'No attendees found.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        if (addTbody) addTbody.style.display = '';
        return;
      }
      // If expected is not zero but list is empty, show mismatch message
      if (expectedCount !== null && expectedCount !== 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.textContent = 'Attendee count mismatch. Please refresh the sessions table and try again.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        if (addTbody) addTbody.style.display = '';
        return;
      }
      // Fallback friendly empty
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 3;
      td.textContent = 'No attendees found.';
      tr.appendChild(td);
      tbody.appendChild(tr);
      if (addTbody) addTbody.style.display = '';
      return;
    }

    if (expectedCount !== null && Number(expectedCount) !== Number(items.length)) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 3;
      td.textContent = 'Attendee count mismatch. Please refresh the sessions table and try again.';
      tr.appendChild(td);
      tbody.appendChild(tr);
      if (addTbody) addTbody.style.display = '';
      return;
    }

    for (const a of items) {
      const tr = document.createElement('tr');
      tr.dataset.name = (a && a.name) ? String(a.name).toLowerCase() : '';
      tr.dataset.email = (a && a.email) ? String(a.email).toLowerCase() : '';
      tr.dataset.memberId = String((a && a.memberId) ? a.memberId : 0);
      const tdName = document.createElement('td');
      tdName.textContent = a && a.name ? a.name : '';
      tr.appendChild(tdName);
      const tdStatus = document.createElement('td');
      const sel = createStatusSelect(a && a.status ? a.status : '', a && a.memberId ? a.memberId : 0, sessionId);
      tdStatus.appendChild(sel);
      tr.appendChild(tdStatus);
      const tdDel = document.createElement('td');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'delete-btn';
      btn.setAttribute('aria-label', 'Delete row');
      btn.textContent = '×';
      btn.addEventListener('click', () => {
        if (Table && Table.attendeesCache instanceof Map) {
          const ent = Table.attendeesCache.get(sessionId);
          if (ent && Array.isArray(ent.items)) {
            const mid = Number(a && a.memberId ? a.memberId : 0);
            ent.items = ent.items.filter(it => Number(it.memberId||0) !== mid);
            Table.attendeesCache.set(sessionId, ent);
          }
        }
        tr.remove();
      });
      tdDel.appendChild(btn);
      tr.appendChild(tdDel);
      tbody.appendChild(tr);
    }

    // Apply any existing search filter
    bindSearchFilter(sessionId);
    const searchInput = overlay && overlay.querySelector('#attendeesSearchInput');
    if (searchInput && searchInput.value) {
      const event = new Event('input');
      searchInput.dispatchEvent(event);
    }
    // Show add-new row once list is rendered
    if (addTbody) addTbody.style.display = '';
  }

  function openModal(sessionId, index) {
    state.sessionId = Number(sessionId) || 0;
    state.index = Number.isFinite(index) ? index : null;
    const overlay = document.getElementById('editAttendeesModal');
    if (!overlay) return;
    // Update session name in header area
    fetchAndSetSessionName(state.sessionId);
    // Populate Version7-style table from the same REST endpoint used by the main page
    if (state.sessionId) {
      loadAttendeesIntoTable(state.sessionId);
    }
    overlay.classList.add('active');
    const first = overlay.querySelector('#attendeesSearchInput, input, select, button');
    if (first && typeof first.focus === 'function') first.focus();
    // Setup autocomplete for new attendee add
    setupAttendeeAutocomplete(state.sessionId);
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
      if (!stat && stat !== '') {
        throw new Error(`Line ${i+1}: status must be Certified | Master | None | ''`);
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
    // Bind Save button to batch save API
    const btnSave = overlay.querySelector('#btnAttendanceSave');
    if (btnSave && !btnSave._pdBound) {
      btnSave._pdBound = true;
      btnSave.addEventListener('click', saveAttendees);
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
