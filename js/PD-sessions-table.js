// PDSessionsTable module organizes fetching, formatting, rendering, and events
(function () {
  'use strict';

  const Module = {
    // ----- Config -----
    // Current attendee sort mode: 'name' | 'last' | 'email'
    attendeeSort: (window.PDSessions && window.PDSessions.attendeeSort) || 'name',
    utils: (window.PDSessionsUtils || {}),
    // Sessions data + sort state
    rawRows: [],
    currentSort: { key: null, dir: 'asc' }, // date,title,lengthMin,stype,ceuWeight,ceuConsiderations,ceuCapable,eventType,parentEvent,presenters
    _headersBound: false,
    // Live search state
    searchTerm: '',
    _searchTimer: null,
    // Modal state
    _escHandler: null,
    // Attendee cache TTL (ms). Override via window.PDSessions.attendeeTTLms
    get attendeeCacheTTLms() {
      const v = window.PDSessions && window.PDSessions.attendeeTTLms;
      const n = Number(v);
      return Number.isFinite(n) && n > 0 ? n : 5 * 60 * 1000; // 5 minutes
    },
    get colSpan() {
      const cnt = document.querySelectorAll('.table thead th').length;
      return cnt || 11;
    },
    getRestUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },

    getAttendeesUrl(id) {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute2 || '').replace(/^\/+/, '');
      return `${root}/${route}?sessionid=${encodeURIComponent(id)}`;
    },
    getFormOptionsUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute3 || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },

    // ----- API -----
    async fetchSessions() {
      const url = this.getRestUrl();
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
      });
      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`REST error ${res.status}: ${body.slice(0, 300)}`);
      }
      return res.json();
    },

    // ----- Formatting (moved to utils) -----

    // Normalize to the v2/sessionhome response shape only
    normalizeRow(row) {
      const r = row || {};
      return {
        id: r.id ?? null,
        date: this.utils.toDateOnly(r['Date'] || ''),
        title: r['Title'] || '',
        lengthMin: Number(r['Length'] || 0) || 0,
        stype: r['Session Type'] || '',
        ceuWeight: r['CEU Weight'] || '',
        ceuConsiderations: r['CEU Const'] || '',
        ceuCapable: r['CEU Capable'] || '',
        eventType: r['Event Type'] || '',
        parentEvent: r['Parent Event'] || '',
        presenters: r['presenters'] || '',
        attendeesCt: (r['attendees_ct'] !== undefined && r['attendees_ct'] !== null)
          ? Number(r['attendees_ct'])
          : null,
        raw: r,
      };
    },

    attendeesCache: new Map(), // id -> { items: array<{name,email}>, at: ms }
    _scrollSyncBound: false,
    async fetchAttendees(id) {
      const url = this.getAttendeesUrl(id);
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache',
          // Include nonce if your endpoint requires auth
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        },
        cache: 'no-store',
      });
      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`REST error ${res.status}: ${body.slice(0, 300)}`);
      }
      const data = await res.json();
      if (!Array.isArray(data)) return [];
      // expected: [["Name","email"], ...] -> [{name, email}] (unsorted; render path applies current sort)
      return data
        .map((it) => this.utils.formatAttendeeItem(it))
        .filter((x) => x && (x.name || x.email));
    },
    // formatAttendeeItem moved to utils

    // Cache helpers
    isAttendeeCacheFresh(id) {
      const entry = this.attendeesCache.get(id);
      if (!entry || !Array.isArray(entry.items)) return false;
      const age = Date.now() - (entry.at || 0);
      return age >= 0 && age < this.attendeeCacheTTLms;
    },

    // ----- Form options loading -----
    async fetchFormOptions() {
      const url = this.getFormOptionsUrl();
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
      });
      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`REST error ${res.status}: ${body.slice(0, 300)}`);
      }
      const data = await res.json();
      if (!data || typeof data !== 'object') return { session_types: [], event_types: [], ceu_considerations: [] };
      return data;
    },
    // clearAndFillSelect moved to utils

    // ----- DOM Helpers -----
    // Sorting and rendering helpers moved to utils
    makeCell(text) {
      const td = document.createElement('td');
      td.textContent = (text ?? '').toString();
      return td;
    },
    makeActionsCell(index, id) {
      const td = document.createElement('td');
      const span = document.createElement('span');
      span.className = 'details-dropdown';
      span.dataset.index = String(index);
      span.style.cursor = 'pointer';
      span.addEventListener('click', (ev) => this.toggleAttendeeDropdown(ev, index));
      span.innerHTML = `
        <svg class="dropdown-icon" width="18" height="18" fill="none" stroke="#e11d48" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle; margin-right:4px;"><path d="M6 9l6 6 6-6"/></svg>
        Details
      `;
      td.appendChild(span);
      return td;
    },
    makeAttendeeCountCell(count) {
      const td = document.createElement('td');
      const n = Number(count);
      td.textContent = Number.isFinite(n) ? String(n) : '—';
      return td;
    },
    makeAttendeeRow(index, attendees) {
      const tr = document.createElement('tr');
      tr.className = 'attendee-row';
      tr.id = `attendee-row-${index}`;
      tr.style.display = 'none';

      const td = document.createElement('td');
      td.colSpan = this.colSpan;
      td.style.background = '#fef2f2';
      td.style.padding = '0';
      td.style.borderTop = '1px solid #fecaca';

      const block = document.createElement('div');
      block.className = 'attendee-list-block';
      // Actions (top-right)
      const actions = document.createElement('div');
      actions.className = 'attendee-actions';
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'edit-attendees-btn';
      editBtn.textContent = 'Edit Attendees';
      editBtn.addEventListener('click', (e) => this.onEditAttendees(e, index));
      actions.appendChild(editBtn);
      block.appendChild(actions);
      const ul = document.createElement('ul');
      ul.id = `attendee-list-${index}`;
      // Initial placeholder; will be replaced upon first toggle if fetch configured
      if (Array.isArray(attendees) && attendees.length) {
        this.utils.renderAttendeeListItems(ul, this.utils.sortAttendees(attendees, this.attendeeSort));
      } else {
        const li = document.createElement('li');
        li.textContent = 'Click Details to load attendees...';
        ul.appendChild(li);
      }
      block.appendChild(ul);
      td.appendChild(block);
      tr.appendChild(td);
      return tr;
    },
    onEditAttendees(event, index) {
      if (event && typeof event.preventDefault === 'function') event.preventDefault();
      const id = this.rowIndexToId ? this.rowIndexToId.get(index) : undefined;
      try {
        console.log('PDSessionsTable: Edit Attendees clicked', { index, id });
      } catch (_) {}
      // Placeholder for future navigation or modal
      // Example: if (window.PDSessions && PDSessions.detailPageBase && id) {
      //   window.location.href = `${PDSessions.detailPageBase}&session_id=${encodeURIComponent(id)}`;
      // }
      const ev = new CustomEvent('pd:edit-attendees', { detail: { index, id } });
      document.dispatchEvent(ev);
    },

    // ----- Events -----
    async toggleAttendeeDropdown(event, index) {
      if (event && typeof event.preventDefault === 'function') event.preventDefault();
      const row = document.getElementById(`attendee-row-${index}`);
      if (!row) return;
      const hidden = row.style.display === 'none' || getComputedStyle(row).display === 'none';
      const id = this.rowIndexToId ? this.rowIndexToId.get(index) : undefined;
      try {
        console.log('PDSessionsTable: Details clicked ->', hidden ? 'open' : 'close', { index, id });
      } catch (_) {}
      // Radio behavior: only one open at a time
      if (hidden) {
        const all = document.querySelectorAll('tr.attendee-row');
        all.forEach((tr) => {
          if (tr && tr.id !== `attendee-row-${index}`) tr.style.display = 'none';
        });
      }
      row.style.display = hidden ? 'table-row' : 'none';

      if (hidden) {
        // About to show; ensure attendees are loaded
        const mainRow = row.previousElementSibling; // the session's main <tr>
        if (!mainRow) return;
        // The rendering order ensures we can retrieve the id from dataset if needed; we keep it simple by mapping index -> id
        if (id == null) return;

        const ul = document.getElementById(`attendee-list-${index}`);
        if (!ul) return;
        // If cached and fresh, render from cache
        if (this.attendeesCache.has(id) && this.isAttendeeCacheFresh(id)) {
          const cachedEntry = this.attendeesCache.get(id);
          const cached = (cachedEntry && cachedEntry.items) || [];
          ul.innerHTML = '';
          this.utils.renderAttendeeListItems(ul, this.utils.sortAttendees(cached, this.attendeeSort));
          return;
        }
        ul.innerHTML = '';
        const loading = document.createElement('li');
        loading.textContent = 'Loading attendees...';
        ul.appendChild(loading);
        try {
          const attendees = await this.fetchAttendees(id);
          this.attendeesCache.set(id, { items: attendees, at: Date.now() }); // cache with timestamp
          this.utils.renderAttendeeListItems(ul, this.utils.sortAttendees(attendees, this.attendeeSort));
        } catch (err) {
          console.error(err);
          ul.innerHTML = '';
          const li = document.createElement('li');
          li.textContent = 'Error loading attendees.';
          ul.appendChild(li);
        }
      }
    },

    // ----- Render -----
    renderSessionsTable(rows) {
      const tbody = document.getElementById('sessionsTableBody');
      if (!tbody) return;
      tbody.innerHTML = '';

      if (!Array.isArray(rows) || rows.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = this.colSpan;
        td.textContent = 'No sessions found.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
      }

      const frag = document.createDocumentFragment();
      this.rowIndexToId = new Map();
      rows.forEach((raw, index) => {
        const r = this.normalizeRow(raw);
        this.rowIndexToId.set(index, r.id);
        const tr = document.createElement('tr');
        tr.appendChild(this.makeCell(r.date));
        tr.appendChild(this.makeCell(r.title));
        tr.appendChild(this.makeCell(this.utils.minutesToHoursLabel(r.lengthMin)));
        tr.appendChild(this.makeCell(r.stype));
        tr.appendChild(this.makeCell(r.ceuWeight));
        tr.appendChild(this.makeCell(r.ceuConsiderations));
        tr.appendChild(this.makeCell(r.ceuCapable));
        tr.appendChild(this.makeCell(r.eventType));
        tr.appendChild(this.makeCell(r.parentEvent));
        tr.appendChild(this.makeCell(r.presenters));
        tr.appendChild(this.makeAttendeeCountCell(r.attendeesCt));
        tr.appendChild(this.makeActionsCell(index, r.id));
        frag.appendChild(tr);

        // Initially attach a placeholder; attendees will be fetched on first expand
        frag.appendChild(this.makeAttendeeRow(index, []));
      });
      tbody.appendChild(frag);
      // Update headers + top scrollbar
      this.updateSortArrows();
      this.setupHeaderSorting();
      this.setupTopScrollbar();
    },

    // Build filtered + sorted view of rawRows
    getRowsToRender() {
      let rows = Array.isArray(this.rawRows) ? this.rawRows.slice() : [];
      const q = (this.searchTerm || '').trim().toLowerCase();
      if (q) {
        rows = rows.filter((raw) => {
          const r = this.normalizeRow(raw);
          const hay = [
            r.date || '',
            r.title || '',
            r.presenters || '',
            r.stype || '',
            r.eventType || '',
          ].join(' \u2002 ').toLowerCase();
          return hay.includes(q);
        });
      }
      const { key, dir } = this.currentSort || {};
      if (key) {
        rows.sort((a, b) => this.compareRawRows(a, b, key, dir || 'asc'));
      }
      return rows;
    },
    refreshTable() {
      const view = this.getRowsToRender();
      this.renderSessionsTable(view);
    },

    // Live search handlers (1s debounce)
    queueSearchFromDom() {
      const el = document.getElementById('searchInput');
      const val = el ? el.value : '';
      this.queueSearch(val);
    },
    queueSearch(val) {
      this.searchTerm = (val || '').toString();
      if (this._searchTimer) {
        clearTimeout(this._searchTimer);
        this._searchTimer = null;
      }
      this._searchTimer = setTimeout(() => {
        this._searchTimer = null;
        this.refreshTable();
      }, 1000); // 1 second debounce
    },

    // ----- Header sorting -----
    setupHeaderSorting() {
      if (this._headersBound) return;
      this._headersBound = true;

      const map = [
        { span: 'sort-arrow-date', key: 'date' },
        { span: 'sort-arrow-title', key: 'title' },
        { span: 'sort-arrow-length', key: 'lengthMin' },
        { span: 'sort-arrow-stype', key: 'stype' },
        { span: 'sort-arrow-ceuWeight', key: 'ceuWeight' },
        { span: 'sort-arrow-ceuConsiderations', key: 'ceuConsiderations' },
        { span: 'sort-arrow-qualifyForCeus', key: 'ceuCapable' },
        { span: 'sort-arrow-eventType', key: 'eventType' },
        { span: 'sort-arrow-parentEvent', key: 'parentEvent' },
        { span: 'sort-arrow-presenters', key: 'presenters' },
        { span: 'sort-arrow-attendees', key: 'attendeesCt' },
      ];
      map.forEach(({ span, key }) => {
        const arrow = document.getElementById(span);
        if (!arrow) return;
        const th = arrow.closest('th');
        if (!th) return;
        th.addEventListener('click', () => this.applySort(key));
      });
    },
    applySort(key) {
      if (!Array.isArray(this.rawRows) || this.rawRows.length === 0) return;
      const state = this.currentSort || { key: null, dir: 'asc' };
      let dir;
      if (state.key !== key) {
        // First click on this column => ascending
        dir = 'asc';
      } else if (state.dir === 'asc') {
        // Second click => descending
        dir = 'desc';
      } else if (state.dir === 'desc') {
        // Third click => clear sort to original order
        this.currentSort = { key: null, dir: 'asc' };
        this.refreshTable();
        return;
      } else {
        dir = 'asc';
      }
      this.currentSort = { key, dir };
      this.refreshTable();
    },
    compareRawRows(a, b, key, dir) {
      return this.utils.compareRows(a, b, key, dir, (row) => this.normalizeRow(row));
    },
    updateSortArrows() {
      const arrows = [
        'sort-arrow-date',
        'sort-arrow-title',
        'sort-arrow-length',
        'sort-arrow-stype',
        'sort-arrow-ceuWeight',
        'sort-arrow-ceuConsiderations',
        'sort-arrow-qualifyForCeus',
        'sort-arrow-eventType',
        'sort-arrow-parentEvent',
        'sort-arrow-presenters',
        'sort-arrow-attendees',
      ];
      arrows.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '';
      });
      const keyToSpan = {
        date: 'sort-arrow-date',
        title: 'sort-arrow-title',
        lengthMin: 'sort-arrow-length',
        stype: 'sort-arrow-stype',
        ceuWeight: 'sort-arrow-ceuWeight',
        ceuConsiderations: 'sort-arrow-ceuConsiderations',
        ceuCapable: 'sort-arrow-qualifyForCeus',
        eventType: 'sort-arrow-eventType',
        parentEvent: 'sort-arrow-parentEvent',
        presenters: 'sort-arrow-presenters',
        attendeesCt: 'sort-arrow-attendees',
      };
      const { key, dir } = this.currentSort || {};
      if (!key) return;
      const spanId = keyToSpan[key];
      const el = spanId ? document.getElementById(spanId) : null;
      if (el) el.textContent = dir === 'asc' ? '▲' : '▼';
    },

    // ----- Top scrollbar sync -----
    setupTopScrollbar() {
      const top = document.getElementById('sessionsTopScroll');
      const spacer = document.getElementById('sessionsTopScrollSpacer');
      const container = document.getElementById('sessionsTableContainer');
      const table = document.getElementById('sessionsTable') || document.querySelector('.table');
      this.utils.syncHorizontalScroll(top, container, table, spacer);
    },

    // ----- Add Session Modal -----
    openAddSessionModal() {
      const overlay = document.getElementById('addSessionModal');
      if (!overlay) return;
      overlay.classList.add('active');
      // Populate dynamic selects from REST (sessionhome3) — refreshed on each open
      this.fetchFormOptions().then((opts) => {
        try {
          const sessionType = overlay.querySelector('#sessionType');
          const eventType = overlay.querySelector('#eventType');
          const ceuSelect = overlay.querySelector('#ceuConsiderations');
          // Fill Session Type (id->session_id, label->session_name)
          if (sessionType) {
            this.utils.clearAndFillSelect(sessionType, 'Select Type', opts.session_types || [], 'session_id', 'session_name');
          }
          // Fill Event Type (id->event_id, label->event_name)
          if (eventType) {
            this.utils.clearAndFillSelect(eventType, 'Select Event Type', opts.event_types || [], 'event_id', 'event_name');
          }
          // Fill CEU Considerations (id->ceu_id, label->ceu_name) + ensure NA present
          if (ceuSelect) {
            this.utils.clearAndFillSelect(ceuSelect, 'Select CEU Consideration', opts.ceu_considerations || [], 'ceu_id', 'ceu_name');
            if (![...ceuSelect.options].some(o => o.value === 'NA')) {
              const na = document.createElement('option');
              na.value = 'NA';
              na.textContent = 'NA';
              ceuSelect.appendChild(na);
            }
          }
        } catch (err) { console.error(err); }
      });
      // Qualify for CEUs -> control CEU Considerations visibility
      const qualify = overlay.querySelector('#qualifyForCeus');
      const ceuGroup = overlay.querySelector('#ceuConsiderationsGroup');
      const ceuSelect = overlay.querySelector('#ceuConsiderations');
      if (qualify && ceuGroup && ceuSelect) {
        // Ensure NA option exists (in case markup changes)
        if (![...ceuSelect.options].some(o => o.value === 'NA')) {
          const o = document.createElement('option');
          o.value = 'NA';
          o.textContent = 'NA';
          ceuSelect.appendChild(o);
        }
        const applyVisibility = () => {
          const val = qualify.value;
          const naOpt = [...ceuSelect.options].find(o => o.value === 'NA');
          if (val === 'Yes') {
            ceuGroup.style.display = '';
            if (naOpt) { naOpt.hidden = true; naOpt.disabled = true; }
            if (ceuSelect.value === 'NA') ceuSelect.value = '';
          } else {
            ceuGroup.style.display = 'none';
            if (naOpt) { naOpt.hidden = false; naOpt.disabled = false; }
            ceuSelect.value = 'NA';
          }
        };
        // Default to No on open
        qualify.value = 'No';
        applyVisibility();
        // Bind change
        qualify.removeEventListener('change', qualify._pdCeuHandler || (()=>{}));
        qualify._pdCeuHandler = applyVisibility;
        qualify.addEventListener('change', applyVisibility);
      }
      const onOverlayClick = (e) => {
        if (e.target === overlay) {
          this.closeAddSessionModal();
        }
      };
      // Allow closing by clicking the overlay (outside modal)
      overlay.addEventListener('click', onOverlayClick, { once: true });
      // Close on ESC
      this._escHandler = (ev) => {
        if (ev.key === 'Escape' || ev.key === 'Esc') this.closeAddSessionModal();
      };
      document.addEventListener('keydown', this._escHandler);
      // Focus first input for convenience
      const first = overlay.querySelector('input, select, textarea, button');
      if (first && typeof first.focus === 'function') first.focus();
    },
    closeAddSessionModal() {
      const overlay = document.getElementById('addSessionModal');
      if (!overlay) return;
      overlay.classList.remove('active');
      if (this._escHandler) {
        document.removeEventListener('keydown', this._escHandler);
        this._escHandler = null;
      }
    },

    async init() {
      try {
        const rows = await this.fetchSessions();
        this.rawRows = Array.isArray(rows) ? rows.slice() : [];
        this.refreshTable();
      } catch (err) {
        console.error(err);
        const tbody = document.getElementById('sessionsTableBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = this.colSpan;
        td.textContent = 'Error loading sessions.';
        tr.appendChild(td);
        tbody.appendChild(tr);
      }
    }
  };

  // Expose a small API if needed elsewhere
  window.PDSessionsTable = {
    init: Module.init.bind(Module),
    refresh: Module.init.bind(Module),
    toggleAttendeeDropdown: Module.toggleAttendeeDropdown.bind(Module),
    // Live search API
    queueSearch: Module.queueSearch.bind(Module),
    queueSearchFromDom: Module.queueSearchFromDom.bind(Module),
    // UI hook: call setAttendeeSort('name'|'last'|'email') from your future buttons,
    // then call refreshVisibleAttendees() to re-render any open attendee lists.
    setAttendeeSort: function(mode) {
      const allowed = ['name', 'last', 'email'];
      if (allowed.includes(mode)) {
        Module.attendeeSort = mode;
      }
    },
    getAttendeeSort: function() { return Module.attendeeSort; },
    refreshVisibleAttendees: function() {
      if (!Module.rowIndexToId) return;
      Module.rowIndexToId.forEach((id, index) => {
        const row = document.getElementById(`attendee-row-${index}`);
        if (!row) return;
        const visible = row.style.display !== 'none' && getComputedStyle(row).display !== 'none';
        if (!visible) return;
        const ul = document.getElementById(`attendee-list-${index}`);
        if (!ul) return;
        const entry = Module.attendeesCache.get(id);
        const raw = entry && Array.isArray(entry.items) ? entry.items : [];
        const sorted = Module.utils.sortAttendees(raw, Module.attendeeSort);
        Module.utils.renderAttendeeListItems(ul, sorted);
      });
    }
  };

  // Optional: expose toggle for inline handlers (legacy)
  window.toggleAttendeeDropdown = Module.toggleAttendeeDropdown.bind(Module);
  // Legacy inline handler for search input oninput="filterSessions()"
  window.filterSessions = Module.queueSearchFromDom.bind(Module);
  // Inline handlers for Add Session modal open/close
  window.openAddSessionModal = Module.openAddSessionModal.bind(Module);
  window.closeAddSessionModal = Module.closeAddSessionModal.bind(Module);

  // Auto-init when script loads (footer)
  Module.init();
})();
