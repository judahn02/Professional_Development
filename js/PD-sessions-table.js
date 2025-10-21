// PDSessionsTable module organizes fetching, formatting, rendering, and events
(function () {
  'use strict';

  const Module = {
    // ----- Config -----
    // Current attendee sort mode: 'name' | 'last' | 'email'
    attendeeSort: (window.PDSessions && window.PDSessions.attendeeSort) || 'name',
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

    // ----- Formatting -----
    toDateOnly(iso) {
      if (!iso) return '';
      const d = String(iso).split('T')[0];
      return d || String(iso);
    },
    minutesToHoursLabel(minutes) {
      const m = Number(minutes);
      if (!Number.isFinite(m)) return '';
      const h = m / 60;
      return `${h.toFixed(2)}h`;
    },

    // Normalize to the v2/sessionhome response shape only
    normalizeRow(row) {
      const r = row || {};
      return {
        id: r.id ?? null,
        date: this.toDateOnly(r['Date'] || ''),
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
        .map((it) => this.formatAttendeeItem(it))
        .filter((x) => x && (x.name || x.email));
    },
    formatAttendeeItem(item) {
      if (!Array.isArray(item)) return { name: '', email: '' };
      const [name, email] = item;
      return {
        name: name ? String(name) : '',
        email: email ? String(email) : '',
      };
    },

    // ----- DOM Helpers -----
    getLastName(name) {
      const n = (name || '').trim();
      if (!n) return '';
      // Support "Last, First" by splitting on comma first
      if (n.includes(',')) {
        const [last] = n.split(',');
        return last.trim();
      }
      // Otherwise take final token as last name
      const parts = n.split(/\s+/);
      return parts.length ? parts[parts.length - 1] : n;
    },
    sortAttendees(list, mode) {
      const arr = Array.isArray(list) ? list.slice() : [];
      const sensitivity = { sensitivity: 'base' };
      if (mode === 'email') {
        arr.sort((a, b) => {
          const ae = (a.email || '').toString();
          const be = (b.email || '').toString();
          const byEmail = ae.localeCompare(be, undefined, sensitivity);
          if (byEmail !== 0) return byEmail;
          const an = (a.name || '').toString();
          const bn = (b.name || '').toString();
          return an.localeCompare(bn, undefined, sensitivity);
        });
        return arr;
      }
      if (mode === 'last') {
        arr.sort((a, b) => {
          const al = this.getLastName(a.name);
          const bl = this.getLastName(b.name);
          const byLast = al.localeCompare(bl, undefined, sensitivity);
          if (byLast !== 0) return byLast;
          const an = (a.name || '').toString();
          const bn = (b.name || '').toString();
          const byName = an.localeCompare(bn, undefined, sensitivity);
          if (byName !== 0) return byName;
          const ae = (a.email || '').toString();
          const be = (b.email || '').toString();
          return ae.localeCompare(be, undefined, sensitivity);
        });
        return arr;
      }
      // default: name
      arr.sort((a, b) => {
        const an = (a.name || '').toString();
        const bn = (b.name || '').toString();
        const byName = an.localeCompare(bn, undefined, sensitivity);
        if (byName !== 0) return byName;
        const ae = (a.email || '').toString();
        const be = (b.email || '').toString();
        return ae.localeCompare(be, undefined, sensitivity);
      });
      return arr;
    },
    renderAttendeeListItems(ul, attendees) {
      if (!ul) return;
      ul.innerHTML = '';
      if (!attendees || attendees.length === 0) {
        const li = document.createElement('li');
        li.className = 'no-attendees';
        li.textContent = 'No attendees found.';
        ul.appendChild(li);
        return;
      }
      for (const a of attendees) {
        const li = document.createElement('li');
        const nameSpan = document.createElement('span');
        nameSpan.className = 'attendee-name';
        nameSpan.textContent = a.name || '';
        const emailSpan = document.createElement('span');
        emailSpan.className = 'attendee-email';
        emailSpan.textContent = a.email || '';
        li.appendChild(nameSpan);
        li.appendChild(emailSpan);
        ul.appendChild(li);
      }
    },
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
        this.renderAttendeeListItems(ul, this.sortAttendees(attendees, this.attendeeSort));
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
      row.style.display = hidden ? 'table-row' : 'none';

      if (hidden) {
        // About to show; ensure attendees are loaded
        const mainRow = row.previousElementSibling; // the session's main <tr>
        if (!mainRow) return;
        // The rendering order ensures we can retrieve the id from dataset if needed; we keep it simple by mapping index -> id
        const id = this.rowIndexToId.get(index);
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
          this.renderAttendeeListItems(ul, this.sortAttendees(attendees, this.attendeeSort));
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
        tr.appendChild(this.makeCell(this.minutesToHoursLabel(r.lengthMin)));
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
      // Update top scrollbar width and ensure listeners are bound
      this.setupTopScrollbar();
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

    async init() {
      try {
        const rows = await this.fetchSessions();
        this.renderSessionsTable(rows);
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
        const sorted = Module.sortAttendees(raw, Module.attendeeSort);
        Module.renderAttendeeListItems(ul, sorted);
      });
    }
  };

  // Optional: expose toggle for inline handlers (legacy)
  window.toggleAttendeeDropdown = Module.toggleAttendeeDropdown.bind(Module);

  // Auto-init when script loads (footer)
  Module.init();
})();
