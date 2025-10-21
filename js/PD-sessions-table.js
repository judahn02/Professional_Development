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

    // ----- API -----
    async fetchSessions() {
      const url = this.getRestUrl();
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
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
        raw: r,
      };
    },

    attendeesCache: new Map(), // id -> array of {name,email} (raw; render path applies sort)
    _scrollSyncBound: false,
    async fetchAttendees(id) {
      const url = this.getAttendeesUrl(id);
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          // Include nonce if your endpoint requires auth
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
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
      row.style.display = hidden ? 'table-row' : 'none';

      if (hidden) {
        // About to show; ensure attendees are loaded
        const mainRow = row.previousElementSibling; // the session's main <tr>
        if (!mainRow) return;
        // The rendering order ensures we can retrieve the id from dataset if needed; we keep it simple by mapping index -> id
        if (id == null) return;

        if (this.attendeesCache.has(id)) return; // already loaded (content already rendered)
        const ul = document.getElementById(`attendee-list-${index}`);
        if (!ul) return;
        ul.innerHTML = '';
        const loading = document.createElement('li');
        loading.textContent = 'Loading attendees...';
        ul.appendChild(loading);
        try {
          const attendees = await this.fetchAttendees(id);
          this.attendeesCache.set(id, attendees); // cache raw
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
      }, 500); // 1 second debounce
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
      const ra = this.normalizeRow(a);
      const rb = this.normalizeRow(b);
      let va = ra[key];
      let vb = rb[key];
      if (key === 'lengthMin') {
        va = Number(va);
        vb = Number(vb);
      } else if (key === 'ceuWeight') {
        va = parseFloat(va);
        vb = parseFloat(vb);
      } else {
        va = (va ?? '').toString();
        vb = (vb ?? '').toString();
      }
      let cmp = 0;
      if (typeof va === 'number' && typeof vb === 'number') {
        const na = Number.isFinite(va) ? va : Number.POSITIVE_INFINITY;
        const nb = Number.isFinite(vb) ? vb : Number.POSITIVE_INFINITY;
        cmp = na === nb ? 0 : (na < nb ? -1 : 1);
      } else {
        cmp = va.localeCompare(vb, undefined, { sensitivity: 'base' });
      }
      return dir === 'asc' ? cmp : -cmp;
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
      if (!top || !spacer || !container || !table) return;

      const setWidths = () => {
        const width = table.scrollWidth;
        spacer.style.width = width + 'px';
        top.scrollLeft = container.scrollLeft;
      };
      setWidths();

      if (this._scrollSyncBound) return;
      this._scrollSyncBound = true;
      let syncing = false;
      top.addEventListener('scroll', () => {
        if (syncing) return;
        syncing = true;
        container.scrollLeft = top.scrollLeft;
        syncing = false;
      }, { passive: true });
      container.addEventListener('scroll', () => {
        if (syncing) return;
        syncing = true;
        top.scrollLeft = container.scrollLeft;
        syncing = false;
      }, { passive: true });
      window.addEventListener('resize', setWidths);
    },

    // ----- Add Session Modal -----
    openAddSessionModal() {
      const overlay = document.getElementById('addSessionModal');
      if (!overlay) return;
      overlay.classList.add('active');
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
        const raw = Module.attendeesCache.get(id) || [];
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
