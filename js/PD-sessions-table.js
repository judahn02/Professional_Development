/*
PD Sessions Table (Admin)

What this module does
- Renders the Sessions admin table, handles sorting, live search, and lazy attendee loading.
- Talks to WP REST endpoints to fetch: sessions list, attendees per session, and Add Session form options.

REST endpoints (configured via localized window.PDSessions)
- GET `${restRoot}/${sessionsRoute}`           -> sessions list (v2/sessionhome)
- GET `${restRoot}/${sessionsRoute2}?sessionid=ID` -> attendees as [["Name","email"], ...] (v2/sessionhome2)
- GET `${restRoot}/${sessionsRoute3}`         -> dropdown options for Add Session (v2/sessionhome3)

Config injected from PHP (window.PDSessions)
- restRoot, sessionsRoute, sessionsRoute2, sessionsRoute3, nonce, detailPageBase, attendeeTTLms, [attendeeSort]
  • `attendeeTTLms` controls attendee cache freshness (default 5 minutes).
  • `attendeeSort` is one of 'name' | 'last' | 'email'.

Utilities expected (window.PDSessionsUtils)
- toDateOnly(str): normalize date string to "YYYY-MM-DD"
- minutesToHoursLabel(mins): render minutes as hours string
- formatAttendeeItem(item): [name,email] -> { name, email }
- sortAttendees(list, mode): sort attendees by name/last/email
- compareRows(a,b,key,dir,normalizeFn): generic row compare
- syncHorizontalScroll(top, container, table, spacer): top scrollbar sync
- clearAndFillSelect(select, placeholder, items, valueKey, labelKey): fill selects

Rendering and behavior
- normalizeRow(): maps API fields -> table columns used here.
- Sorting: click header cycles asc -> desc -> none; arrows update via updateSortArrows().
- Live search: 1s debounce; filters by date, title, presenters, session type, and event type.
- Attendees panel: per-row "Details" toggles a sibling row; only one open at a time (radio behavior).
  • Fetches attendees on first open via sessionhome2, caches results for `attendeeTTLms`.
  • Attendees are rendered and can be re-sorted by `attendeeSort`.
- Add Session modal: openAddSessionModal() fetches options every open from sessionhome3.
  • CEU visibility tied to #qualifyForCeus; ensures 'NA' option exists and hides it when qualifying.
  • setupAddNewForSelect(): appends an "Add new" option that reveals an inline text input.

Accessibility and UX
- Keyboard: Details supports Enter/Space; ARIA attributes (aria-controls/expanded) update on toggle.
- Error states: shows friendly rows for failed loads (sessions/attendees) and validation hint in inline add-new.

DOM contracts (expected ids/classes)
- Table/container: #sessionsTable, #sessionsTableBody, #sessionsTableContainer
- Top scrollbar: #sessionsTopScroll, #sessionsTopScrollSpacer
- Search input: #searchInput
- Sort arrow spans by id: #sort-arrow-date|title|length|stype|ceuWeight|ceuConsiderations|qualifyForCeus|eventType|parentEvent|presenters|attendees
- Modal: #addSessionModal with #sessionType, #eventType, #ceuConsiderations, #qualifyForCeus, #ceuConsiderationsGroup
- Row details trigger: span.details-dropdown; attendee rows use tr.attendee-row and ul#attendee-list-{index}

Public API (window.PDSessionsTable)
- init(), refresh(), toggleAttendeeDropdown(index)
- queueSearch(val), queueSearchFromDom()
- setAttendeeSort(mode), getAttendeeSort(), refreshVisibleAttendees()

Inline handlers exposed for legacy markup
- window.toggleAttendeeDropdown, window.filterSessions
- window.openAddSessionModal, window.closeAddSessionModal
*/
// PDSessionsTable module organizes fetching, formatting, rendering, and events
(function () {
  'use strict';

  const Module = {
    // ----- Config -----
    // Current attendee sort mode: 'name' | 'last' | 'email'
    attendeeSort: (window.PDSessions && window.PDSessions.attendeeSort) || 'name',
    utils: (window.PDSessionsUtils || {}),
    // Sessions data + sort/paging/search state
    rawRows: [],
    // Default sort newest-first by date
    currentSort: { key: 'date', dir: 'desc' }, // date,title,lengthMin,stype,ceuWeight,ceuConsiderations,ceuCapable,eventType,parentEvent,presenters
    currentPage: 1,
    perPage: 25,
    _lastFetchCount: 0,
    _totalPagesAll: null, // from /sessions/ct, only used when unfiltered
    _totalPagesSearch: null, // from /sessions/ct?q=..., used when searching
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

    getSessionsCountUrl(q) {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsCountRoute || 'sessions/ct').replace(/^\/+/, '');
      const per = (this.perPage > 0 ? this.perPage : 25);
      const params = new URLSearchParams();
      params.set('per_page', String(per));
      const term = (q || '').toString().trim();
      if (term) params.set('q', term);
      return `${root}/${route}?${params.toString()}`;
    },

    async fetchSessionsTotalPages(q) {
      const url = this.getSessionsCountUrl(q);
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) return null;
      const data = await res.json().catch(() => null);
      const tp = data && data.total_pages != null ? Number(data.total_pages) : null;
      if (!Number.isFinite(tp) || tp <= 0) return null;
      return Math.floor(tp);
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
    getSessionPresentersBaseUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoutePresenters)
        ? String(window.PDSessions.sessionsRoutePresenters).replace(/^\/+/, '')
        : 'session/presenters';
      return `${root}/${route}`;
    },
    getSessionPresentersUrl(id) {
      const base = this.getSessionPresentersBaseUrl();
      return `${base}?session_id=${encodeURIComponent(id)}`;
    },
    getUpdateSessionPresentersUrl() {
      return this.getSessionPresentersBaseUrl();
    },
    getPresenterSearchUrl(term) {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute4 || '').replace(/^\/+/, '');
      const q = encodeURIComponent(term);
      return `${root}/${route}?term=${q}`;
    },
    getPresenterCreateUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute5 || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },
    getUpdateSessionUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoutePut) ? String(window.PDSessions.sessionsRoutePut).replace(/^\/+/, '') : 'session';
      return `${root}/${route}`;
    },

    // Map client sort keys -> REST sort param
    _toServerSortKey(key) {
      const map = {
        date: 'date',
        title: 'title',
        lengthMin: 'length',
        stype: 'stype',
        ceuWeight: 'ceuWeight',
        ceuConsiderations: 'ceuConst',
        ceuCapable: 'ceuCapable',
        eventType: 'eventType',
        parentEvent: 'parentEvent',
        presenters: 'presenters',
        attendeesCt: 'attendees_ct',
      };
      return map[key] || 'date';
    },

    // ----- API -----
    async fetchSessions() {
      // Build query with server-side sort + paging + search
      const base = this.getRestUrl();
      const params = new URLSearchParams();
      // explicit paging
      params.set('per_page', String(this.perPage > 0 ? this.perPage : 25));
      params.set('page', String(this.currentPage > 0 ? this.currentPage : 1));
      const s = this.currentSort || { key: 'date', dir: 'desc' };
      if (s && s.key) {
        params.set('sort', this._toServerSortKey(s.key));
        params.set('dir', (s.dir || 'desc'));
      }
      const q = (this.searchTerm || '').toString().trim();
      if (q) params.set('q', q);
      const url = params.toString() ? `${base}?${params.toString()}` : base;
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`REST error ${res.status}: ${body.slice(0, 300)}`);
      }
      return res.json();
    },
    async fetchSessionById(id) {
      if (!id) return null;
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute || '').replace(/^\/+/, '');
      const url = `${root}/${route}?session_id=${encodeURIComponent(id)}`;
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) return null;
      const data = await res.json().catch(() => null);
      if (Array.isArray(data) && data.length) return data[0];
      return null;
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
        headers: {
          'Accept': 'application/json',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`REST error ${res.status}: ${body.slice(0, 300)}`);
      }
      const data = await res.json();
      if (!data || typeof data !== 'object') return { session_types: [], event_types: [], ceu_considerations: [] };
      return data;
    },
    // Presenter search (letters + spaces)
    async searchPresenters(term) {
      const cleaned = (term || '')
        .replace(/[^a-zA-Z\s]+/g, '')
        .replace(/\s+/g, ' ')
        .trim();
      if (!cleaned) return [];
      const url = this.getPresenterSearchUrl(cleaned);
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) return [];
      const data = await res.json().catch(() => []);
      if (!Array.isArray(data)) return [];
      return data
        .map((r) => ({ id: Number(r.id) || 0, name: String(r.name || '') }))
        .filter((r) => r.name !== '');
    },
    async fetchSessionPresenters(id) {
      if (!id) return [];
      const url = this.getSessionPresentersUrl(id);
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`Presenters fetch error ${res.status}: ${body.slice(0, 300)}`);
      }
      const data = await res.json().catch(() => []);
      if (!Array.isArray(data)) return [];
      return data
        .map((row) => ({
          id: Number(row && row.id != null ? row.id : 0) || 0,
          name: String(row && row.name != null ? row.name : '').trim(),
        }))
        .filter((p) => p.id > 0 && p.name !== '');
    },
    async createPresenterAPI(name, email, number) {
      const url = this.getPresenterCreateUrl();
      const payload = { name: String(name || '').trim(), email: String(email || '').trim(), number: String(number || '').trim() };
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        },
        body: JSON.stringify(payload),
      });
      if (!res.ok) {
        const text = await res.text().catch(() => '');
        throw new Error(`Create presenter failed (${res.status}): ${text.slice(0, 200)}`);
      }
      const data = await res.json().catch(() => ({}));
      return data;
    },
    async updateSessionPresenters(id, presenterIds) {
      const url = this.getUpdateSessionPresentersUrl();
      const ids = Array.isArray(presenterIds)
        ? presenterIds
          .map((v) => Number(v) || 0)
          .filter((v, idx, arr) => v > 0 && arr.indexOf(v) === idx)
        : [];
      const payload = {
        session_id: id,
        presenter_ids: ids,
      };
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
        const text = await res.text().catch(() => '');
        let message = `Presenter update failed (${res.status}).`;
        if (text) {
          try {
            const err = JSON.parse(text);
            if (err && err.message) {
              message = err.message;
            }
          } catch (_) {
            message = `${message} ${text.slice(0, 160)}`;
          }
        }
        throw new Error(message);
      }
      // Response body is not currently used; return parsed JSON in case callers want it.
      return res.json().catch(() => ({}));
    },
    async _parseUpdateSessionErrorMessage(res) {
      const status = res && typeof res.status === 'number' ? res.status : 0;
      let message = `Update failed (${status}).`;
      let text = '';
      try {
        text = await res.text();
      } catch (_) {
        text = '';
      }
      if (!text) return message;
      try {
        const err = JSON.parse(text);
        if (err && err.message) {
          message = err.message;
        }
        const data = err && err.data;
        const debug = data && data.debug;
        const body = debug && debug.body;
        let detail = null;
        if (typeof body === 'string' && body) {
          try {
            const inner = JSON.parse(body);
            if (inner && inner.detail) {
              detail = String(inner.detail);
            }
          } catch (_) {
            // ignore JSON parse issues in nested debug body
          }
        }
        if (detail) {
          if (detail.includes('Duplicate entry') && detail.includes('title_date_UNIQUE')) {
            message = 'Another session already exists with the same title and date. Please change the title or date to make this session unique.';
          } else if (!err.message) {
            message = detail;
          }
        }
      } catch (_) {
        message = `${message} ${text.slice(0, 160)}`;
      }
      return message;
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
      span.setAttribute('role', 'button');
      span.setAttribute('tabindex', '0');
      span.setAttribute('aria-controls', `attendee-row-${index}`);
      span.setAttribute('aria-expanded', 'false');
      span.style.cursor = 'pointer';
      span.addEventListener('click', (ev) => this.toggleAttendeeDropdown(ev, index));
      span.addEventListener('keydown', (ev) => {
        const k = ev.key;
        if (k === 'Enter' || k === ' ' || k === 'Spacebar') {
          ev.preventDefault();
          this.toggleAttendeeDropdown(ev, index);
        }
      });
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

      // Sort button: toggle attendee list sort between first-name ('name') and last-name ('last')
      const sortBtn = document.createElement('button');
      sortBtn.type = 'button';
      sortBtn.className = 'sort-attendees-btn';
      sortBtn.textContent = 'Sort';
      sortBtn.addEventListener('click', () => {
        try {
          const Table = window.PDSessionsTable;
          if (!Table ||
            typeof Table.getAttendeeSort !== 'function' ||
            typeof Table.setAttendeeSort !== 'function' ||
            typeof Table.refreshVisibleAttendees !== 'function') {
            return;
          }
          const current = Table.getAttendeeSort();
          const next = current === 'last' ? 'name' : 'last';
          Table.setAttendeeSort(next);
          Table.refreshVisibleAttendees();
        } catch (_) { }
      });
      actions.appendChild(sortBtn);

      // Edit Session button
      const editSessionBtn = document.createElement('button');
      editSessionBtn.type = 'button';
      editSessionBtn.className = 'edit-session-btn';
      editSessionBtn.textContent = 'Edit Details';
      editSessionBtn.addEventListener('click', (e) => this.onEditSession(e, index));
      actions.appendChild(editSessionBtn);

      // Existing: Edit Attendees button
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
    onEditSession(event, index) {
      if (event && typeof event.preventDefault === 'function') event.preventDefault();
      const id = this.rowIndexToId ? this.rowIndexToId.get(index) : undefined;
      if (id == null) return;
      // Find row data and open edit modal using shared modal logic
      let raw = null;
      if (Array.isArray(this.rawRows)) raw = this.rawRows.find(r => (r && r.id != null) ? r.id === id : false) || null;
      const session = this.normalizeRow(raw || {});
      if (window.PDEditSessionModal && typeof window.PDEditSessionModal.open === 'function') {
        window.PDEditSessionModal.open(session);
      } else {
        // fallback to legacy inline modal if edit modal lib missing
        this.openEditSessionModalById(id);
      }
    },
    async prefillEditSessionPresenters(overlay, id) {
      if (!overlay || !id) return;
      const input = overlay.querySelector('#editPresenters');
      if (!input) return;
      const wrap = input.closest('.token-input');
      if (!wrap || !wrap._pdAddPresenter) return;
      try {
        const presenters = await this.fetchSessionPresenters(id);
        if (!Array.isArray(presenters) || presenters.length === 0) return;
        // Replace any existing selection with the authoritative list from the API.
        wrap._selected = [];
        presenters.forEach((p) => {
          if (p && p.id > 0 && p.name) {
            wrap._pdAddPresenter({ id: p.id, name: p.name });
          }
        });
      } catch (err) {
        try { console.error(err); } catch (_) { }
      }
    },

    openEditSessionModalById(id) {
      const overlay = document.getElementById('editSessionModal');
      if (!overlay) return;

      // Find the raw row by id
      let raw = null;
      if (Array.isArray(this.rawRows)) {
        raw = this.rawRows.find(r => (r && r.id != null) ? r.id === id : false) || null;
      }
      const r = this.normalizeRow(raw || {});

      // Prefill fields
      const setVal = (sel, val) => { const el = overlay.querySelector(sel); if (el) el.value = val || ''; };
      // Date expects YYYY-MM-DD
      setVal('#sessionDate', r.date || '');
      setVal('#sessionTitle', r.title || '');
      setVal('#sessionLength', String(r.lengthMin || 0));
      setVal('#parentEvent', r.parentEvent || '');
      const pres = overlay.querySelector('#editPresenters');
      if (pres) pres.value = r.presenters || '';

      this.bindEditSessionSave(overlay, id, r);

      // Open modal
      overlay.classList.add('active');
      overlay.setAttribute('aria-hidden', 'false');
      const onOverlayClick = (e) => { if (e.target === overlay) this.closeEditSessionModal(); };
      overlay.addEventListener('click', onOverlayClick, { once: true });
      // ESC close
      this._escHandlerEdit = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') this.closeEditSessionModal(); };
      document.addEventListener('keydown', this._escHandlerEdit);

      const first = overlay.querySelector('input, select, textarea, button');
      if (first && typeof first.focus === 'function') first.focus();
    },
    bindEditSessionSave(overlay, id, originalSession) {
      if (!overlay || !id) return;
      const form = overlay.querySelector('#editSessionForm');
      if (!form || form._pdSubmitBound) return;
      form._pdSubmitBound = true;
      const origSession = originalSession && typeof originalSession === 'object' ? originalSession : null;
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btn = overlay.querySelector('#btnEditSessionSave');
        const orig = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        try {
          const val = (sel) => { const el = overlay.querySelector(sel); return el ? el.value : ''; };
          const selText = (sel) => {
            const el = overlay.querySelector(sel);
            if (!el) return '';
            const opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
            return opt ? (opt.text || opt.textContent || '').trim() : (el.value || '').trim();
          };
          const rawDate = (val('#sessionDate') || '').trim();
          const payload = {
            session_id: id,
            title: (val('#sessionTitle') || '').trim(),
            date: rawDate || null,
            length: parseInt(val('#sessionLength'), 10) || 0,
            specific_event: (val('#parentEvent').trim() || null),
            session_type: selText('#sessionType'),
            event_type: selText('#eventType'),
            ceu_consideration: null,
          };
          const qualify = val('#qualifyForCeus');
          if (qualify === 'Yes') {
            const ceuSelect = overlay.querySelector('#ceuConsiderations');
            const ceuValue = ceuSelect ? (ceuSelect.value || '').trim() : '';
            const ceuName = selText('#ceuConsiderations');
            // Require a real CEU consideration (not placeholder, not NA) when qualifying.
            if (!ceuValue || !ceuName || ceuName.toLowerCase() === 'na') {
              throw new Error('Please select a CEU Consideration when qualifying for CEUs.');
            }
            payload.ceu_consideration = ceuName;
          }
          if (!payload.title || !payload.length || !payload.session_type || !payload.event_type) {
            throw new Error('Please complete Title, Length, Session Type, and Event Type.');
          }

          // Collect presenter ids from token-input chips (source of truth).
          let presenterIds = [];
          const presInput = overlay.querySelector('#editPresenters');
          if (presInput) {
            const wrap = presInput.closest('.token-input');
            if (wrap) {
              const hidden = wrap.querySelectorAll('input.presenter-id-hidden');
              presenterIds = Array.from(hidden)
                .map((el) => Number(el.value) || 0)
                .filter((n, idx, arr) => n > 0 && arr.indexOf(n) === idx);
            }
          }

          // Determine whether any core session fields (not presenters) actually changed.
          let shouldUpdateSession = true;
          if (origSession) {
            const sameTitle = (origSession.title || '').trim() === payload.title;
            const sameDate = (origSession.date || '').trim() === payload.date;
            const sameLength = Number(origSession.lengthMin || 0) === payload.length;
            const sameStype = (origSession.stype || '').trim() === payload.session_type;
            const sameEventType = (origSession.eventType || '').trim() === payload.event_type;
            const origParent = (origSession.parentEvent || '').trim() || null;
            const newParent = payload.specific_event === null ? null : String(payload.specific_event).trim() || null;
            const sameParent = origParent === newParent;
            const origCeuRaw = (origSession.ceuConsiderations || '').trim();
            const origCeuNorm = (!origCeuRaw || origCeuRaw.toLowerCase() === 'na') ? null : origCeuRaw;
            const newCeu = payload.ceu_consideration || null;
            const sameCeu = origCeuNorm === newCeu;
            if (sameTitle && sameDate && sameLength && sameStype && sameEventType && sameParent && sameCeu) {
              shouldUpdateSession = false;
            }
          }

          let sessionError = null;
          let presentersError = null;

          if (shouldUpdateSession) {
            try {
              const url = this.getUpdateSessionUrl();
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
                sessionError = await this._parseUpdateSessionErrorMessage(res);
              }
            } catch (e) {
              sessionError = e && e.message ? e.message : 'Failed to update session.';
            }
          }

          // Always attempt presenter update so presenter-only edits still persist even
          // when the core session update fails (e.g. duplicate title/date).
          try {
            await this.updateSessionPresenters(id, presenterIds);
          } catch (e) {
            presentersError = e && e.message ? e.message : 'Failed to update presenters.';
          }

          if (sessionError && shouldUpdateSession) {
            let msg = sessionError;
            if (!presentersError && presenterIds.length > 0) {
              msg += '\n\nPresenter changes may still have been saved for this session.';
            }
            alert(msg);
            // If presenters were updated successfully, refresh the table so the
            // presenters column reflects the latest assignments, even though
            // the session core fields were rejected.
            if (!presentersError) {
              const updatedAfterError = await this.fetchSessionById(id).catch(() => null);
              if (updatedAfterError) {
                if (Array.isArray(this.rawRows)) {
                  this.rawRows = this.rawRows.map((r) => {
                    const rid = (r && r.id != null) ? r.id : null;
                    return (rid === id) ? updatedAfterError : r;
                  });
                } else {
                  this.rawRows = [updatedAfterError];
                }
                this.refreshTable();
              } else {
                await this.reloadSessionsFromServer();
              }
            }
            return;
          }

          if (presentersError) {
            alert(presentersError);
            return;
          }

          this.closeEditSessionModal();
          const updated = await this.fetchSessionById(id).catch(() => null);
          if (updated) {
            if (Array.isArray(this.rawRows)) {
              this.rawRows = this.rawRows.map((r) => {
                const rid = (r && r.id != null) ? r.id : null;
                return (rid === id) ? updated : r;
              });
            } else {
              this.rawRows = [updated];
            }
            this.refreshTable();
          } else {
            await this.reloadSessionsFromServer();
          }
        } catch (err) {
          console.error(err);
          alert(err && err.message ? err.message : 'Failed to save changes.');
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
      });
    },
    closeEditSessionModal() {
      const overlay = document.getElementById('editSessionModal');
      if (!overlay) return;
      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      if (this._escHandlerEdit) {
        document.removeEventListener('keydown', this._escHandlerEdit);
        this._escHandlerEdit = null;
      }
    },
    onEditAttendees(event, index) {
      if (event && typeof event.preventDefault === 'function') event.preventDefault();
      const id = this.rowIndexToId ? this.rowIndexToId.get(index) : undefined;
      try {
        console.log('PDSessionsTable: Edit Attendees clicked', { index, id });
      } catch (_) { }
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
      } catch (_) { }
      // Radio behavior: only one open at a time
      if (hidden) {
        const all = document.querySelectorAll('tr.attendee-row');
        all.forEach((tr) => {
          if (tr && tr.id !== `attendee-row-${index}`) tr.style.display = 'none';
        });
      }
      row.style.display = hidden ? 'table-row' : 'none';
      // Update aria-expanded state on the trigger
      const trigger = document.querySelector(`span.details-dropdown[data-index="${index}"]`);
      if (trigger) trigger.setAttribute('aria-expanded', hidden ? 'true' : 'false');

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
    async openAttendeeDetails(index) {
      const row = document.getElementById(`attendee-row-${index}`);
      if (!row) return;
      const currentlyHidden = row.style.display === 'none' || getComputedStyle(row).display === 'none';
      const id = this.rowIndexToId ? this.rowIndexToId.get(index) : undefined;
      const all = document.querySelectorAll('tr.attendee-row');
      all.forEach((tr) => { if (tr && tr.id !== `attendee-row-${index}`) tr.style.display = 'none'; });
      row.style.display = 'table-row';
      const trigger = document.querySelector(`span.details-dropdown[data-index="${index}"]`);
      if (trigger) trigger.setAttribute('aria-expanded', 'true');
      if (!currentlyHidden) return;
      if (id == null) return;
      const ul = document.getElementById(`attendee-list-${index}`);
      if (!ul) return;
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
        this.attendeesCache.set(id, { items: attendees, at: Date.now() });
        this.utils.renderAttendeeListItems(ul, this.utils.sortAttendees(attendees, this.attendeeSort));
      } catch (err) {
        console.error(err);
        ul.innerHTML = '';
        const li = document.createElement('li');
        li.textContent = 'Error loading attendees.';
        ul.appendChild(li);
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
      this.renderPager();

      // If another component requested to open a specific session's Details after refresh,
      // honor it and scroll into view smoothly.
      try {
        const pendingId = (window.PDSessionsTable && window.PDSessionsTable._pendingOpenId) || null;
        if (pendingId != null) {
          // Find the row index for this session id
          let targetIndex = null;
          if (this.rowIndexToId) {
            this.rowIndexToId.forEach((id, idx) => { if (id === pendingId && targetIndex === null) targetIndex = idx; });
          }
          // Clear the pending flag regardless
          window.PDSessionsTable._pendingOpenId = null;
          if (targetIndex != null) {
            const trigger = document.querySelector(`span.details-dropdown[data-index="${targetIndex}"]`);
            const row = trigger ? trigger.closest('tr') : null;
            if (row && typeof row.scrollIntoView === 'function') {
              row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            // Open the Details panel for the target row (force open, do not toggle closed)
            this.openAttendeeDetails(targetIndex);
            // Briefly highlight the row for visibility
            if (row) {
              row.classList.add('row-highlight');
              setTimeout(() => { try { row.classList.remove('row-highlight'); } catch (_) { } }, 1600);
            }
          }
        }
      } catch (_) { }
    },

    // Server handles sorting and search; just return current page rows
    getRowsToRender() {
      return Array.isArray(this.rawRows) ? this.rawRows.slice() : [];
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
        // Reset to first page on new search and fetch server-side
        this.currentPage = 1;
        const term = (this.searchTerm || '').toString().trim();
        if (term) {
          this.fetchSessionsTotalPages(term)
            .then(tp => { this._totalPagesSearch = tp; })
            .catch(() => { this._totalPagesSearch = null; });
        } else {
          this._totalPagesSearch = null;
          if (!Number.isFinite(this._totalPagesAll)) {
            this.fetchSessionsTotalPages('')
              .then(tp => { this._totalPagesAll = tp; })
              .catch(() => { this._totalPagesAll = null; });
          }
        }
        this.reloadSessionsFromServer();
      }, 500); // 0.5s debounce
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
      // Cycle: asc -> desc -> none
      const state = this.currentSort || { key: null, dir: 'asc' };
      let dir;
      if (state.key !== key) {
        dir = 'asc';
        this.currentSort = { key, dir };
      } else if (state.dir === 'asc') {
        dir = 'desc';
        this.currentSort = { key, dir };
      } else if (state.dir === 'desc') {
        // Clear to no explicit sort; server defaults to date desc
        this.currentSort = { key: null, dir: 'asc' };
      } else {
        this.currentSort = { key, dir: 'asc' };
      }
      // Fetch rows from server using current sort
      this.reloadSessionsFromServer();
    },

    async reloadSessionsFromServer() {
      try {
        const rows = await this.fetchSessions();
        this._lastFetchCount = Array.isArray(rows) ? rows.length : 0;
        this.rawRows = Array.isArray(rows) ? rows.slice() : [];
        this.refreshTable();
      } catch (err) {
        console.error(err);
        // Fallback to current table rendering
        this.refreshTable();
      }
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

    // ----- Pagination UI -----
    renderPager() {
      const hasSearch = (this.searchTerm || '').toString().trim() !== '';
      const totalPages = hasSearch
        ? (Number.isFinite(this._totalPagesSearch) ? this._totalPagesSearch : null)
        : (Number.isFinite(this._totalPagesAll) ? this._totalPagesAll : null);
      const canNext = totalPages !== null
        ? this.currentPage < totalPages
        : (this._lastFetchCount >= this.perPage && this.perPage > 0);

      const ensurePager = (id, insertPosition) => {
        let el = document.getElementById(id);
        if (!el) {
          el = document.createElement('div');
          el.id = id;
          el.className = 'sessions-pager';
          el.style.display = 'flex';
          el.style.gap = '12px';
          el.style.alignItems = 'center';
          el.style.margin = '8px 0 16px';
          const topScroll = document.getElementById('sessionsTopScroll');
          const container = document.getElementById('sessionsTableContainer');
          if (insertPosition === 'top' && topScroll && topScroll.parentNode) {
            topScroll.parentNode.insertBefore(el, topScroll);
          } else if (container && container.parentNode) {
            container.parentNode.insertBefore(el, container.nextSibling);
          } else {
            document.body.appendChild(el);
          }
        }
        el.innerHTML = '';
        return el;
      };

      const renderInto = (el, suffix) => {
        const mkBtn = (id, label) => {
          const b = document.createElement('button');
          b.type = 'button';
          b.id = id;
          b.textContent = label;
          b.className = 'pager-btn';
          b.style.padding = '6px 10px';
          b.style.border = '1px solid #e5e7eb';
          b.style.background = '#ffffff';
          b.style.cursor = 'pointer';
          b.style.borderRadius = '4px';
          return b;
        };

        const prev = mkBtn(`sessionsPager${suffix}Prev`, 'Prev');
        prev.disabled = this.currentPage <= 1;
        prev.setAttribute('aria-disabled', prev.disabled ? 'true' : 'false');
        prev.addEventListener('click', () => this.prevPage());
        el.appendChild(prev);

        const label = document.createElement('span');
        label.id = `sessionsPager${suffix}Label`;
        label.textContent = totalPages !== null
          ? `Page ${this.currentPage} of ${totalPages}`
          : `Page ${this.currentPage}`;
        el.appendChild(label);

        const next = mkBtn(`sessionsPager${suffix}Next`, 'Next');
        next.disabled = !canNext;
        next.setAttribute('aria-disabled', next.disabled ? 'true' : 'false');
        next.addEventListener('click', () => this.nextPage());
        el.appendChild(next);
      };

      // Top pager: before the horizontal top scrollbar
      const topEl = ensurePager('sessionsPagerTop', 'top');
      renderInto(topEl, 'Top');
      // Bottom pager: after the table container (kept legacy id 'sessionsPager')
      const bottomEl = ensurePager('sessionsPager', 'bottom');
      renderInto(bottomEl, '');
    },

    nextPage() {
      const hasSearch = (this.searchTerm || '').toString().trim() !== '';
      const totalPages = hasSearch
        ? (Number.isFinite(this._totalPagesSearch) ? this._totalPagesSearch : null)
        : (Number.isFinite(this._totalPagesAll) ? this._totalPagesAll : null);
      const canNext = totalPages !== null
        ? this.currentPage < totalPages
        : (this._lastFetchCount >= this.perPage && this.perPage > 0);
      if (!canNext) return;
      this.currentPage += 1;
      this.reloadSessionsFromServer();
    },
    prevPage() {
      if (this.currentPage <= 1) return;
      this.currentPage -= 1;
      this.reloadSessionsFromServer();
    },

    // ----- Add Session Modal -----
    openAddSessionModal() {
      if (window.PDSessionsModal && typeof window.PDSessionsModal.open === 'function') {
        window.PDSessionsModal.open();
      }
      // Ensure presenter token input is initialized each open
      this.setupPresenterTokenInput();
    },
    // ----- Add Presenter Modal -----
    openAddPresenterModal(name) {
      const overlay = document.getElementById('addPresenterModal');
      if (!overlay) return;
      const nameBox = document.getElementById('addPresenterName');
      if (nameBox) nameBox.textContent = String(name || '').trim();
      const email = document.getElementById('presenterEmail');
      const phone = document.getElementById('presenterPhone');
      if (email) email.value = '';
      if (phone) phone.value = '';
      overlay.classList.add('active');
      overlay.setAttribute('aria-hidden', 'false');

      // Bind buttons
      const btnCancel = document.getElementById('btnAddPresenterCancel');
      const btnOk = document.getElementById('btnAddPresenterConfirm');
      if (btnCancel && !btnCancel._pdBound) {
        btnCancel._pdBound = true;
        btnCancel.addEventListener('click', () => this.closeAddPresenterModal());
      }
      if (btnOk && !btnOk._pdBound) {
        btnOk._pdBound = true;
        btnOk.addEventListener('click', async () => {
          const nameEl = document.getElementById('addPresenterName');
          const emailEl = document.getElementById('presenterEmail');
          const phoneEl = document.getElementById('presenterPhone');
          const fullName = nameEl ? nameEl.textContent.trim() : (name || '');
          const emailVal = emailEl ? emailEl.value : '';
          const phoneVal = phoneEl ? phoneEl.value : '';
          try {
            const resp = await this.createPresenterAPI(fullName, emailVal, phoneVal);
            if (resp && resp.success && Number(resp.id) > 0) {
              const field = document.getElementById('presenters');
              const wrap = field ? field.closest('.token-input') : null;
              if (wrap && typeof wrap._pdAddPresenter === 'function') {
                wrap._pdAddPresenter({ id: Number(resp.id), name: fullName });
                const list = wrap.querySelector('.suggestions-list');
                if (list) list.style.display = 'none';
                if (field) {
                  // Ensure any typed term is cleared
                  field.value = '';
                  field.setAttribute('aria-expanded', 'false');
                  field.focus();
                }
              } else if (field) {
                const existing = (field.value || '').trim();
                field.value = existing ? `${existing}, ${fullName}` : fullName;
              }
            }
          } catch (err) {
            console.error(err);
            alert('Failed to add presenter.');
          } finally {
            this.closeAddPresenterModal();
          }
        });
      }

      // Close by clicking overlay background
      const onOverlayClick = (e) => {
        if (e.target === overlay) this.closeAddPresenterModal();
      };
      overlay.addEventListener('click', onOverlayClick, { once: true });

      // Close on ESC
      this._escHandlerPresenter = (ev) => {
        if (ev.key === 'Escape' || ev.key === 'Esc') this.closeAddPresenterModal();
      };
      document.addEventListener('keydown', this._escHandlerPresenter);

      // Focus first field
      if (email && typeof email.focus === 'function') email.focus();
    },
    closeAddPresenterModal() {
      const overlay = document.getElementById('addPresenterModal');
      if (!overlay) return;
      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      if (this._escHandlerPresenter) {
        document.removeEventListener('keydown', this._escHandlerPresenter);
        this._escHandlerPresenter = null;
      }
    },
    setupPresenterTokenInput(inputOrSelector) {
      let input = null;
      if (typeof inputOrSelector === 'string' && inputOrSelector) {
        input = document.querySelector(inputOrSelector);
      } else if (inputOrSelector && inputOrSelector.nodeType === 1) {
        input = inputOrSelector;
      } else {
        input = document.getElementById('presenters');
      }
      if (!input) return;

      // Create wrapper and suggestions list
      let wrap = input.closest('.token-input');
      const parent = input.parentNode;
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.className = 'token-input';
        // Positioning context for suggestions
        wrap.style.position = 'relative';
        parent.insertBefore(wrap, input);
        wrap.appendChild(input);
      }

      input.classList.add('token-field');
      input.setAttribute('autocomplete', 'off');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-expanded', 'false');
      // Avoid native required blocking submit before we can mirror chips into value
      try { input.removeAttribute('required'); } catch (_) { }

      // Ensure state holders
      if (!wrap._selected) wrap._selected = []; // [{id,name}]
      if (!wrap._debounce) wrap._debounce = null;

      let list = wrap.querySelector('.suggestions-list');
      if (!list) {
        list = document.createElement('ul');
        list.className = 'suggestions-list';
        list.id = 'presentersSuggestions';
        list.setAttribute('role', 'listbox');
        list.style.display = 'none';
        // Suggestion list should span the input width
        list.style.position = 'absolute';
        list.style.left = '0';
        list.style.right = '0';
        wrap.appendChild(list);
      }

      // Hidden field carries JSON selection on submit/save
      let hidden = wrap.querySelector('input.presenters-selected');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.className = 'presenters-selected';
        hidden.name = 'presentersSelected';
        wrap.appendChild(hidden);
      }

      const renderChips = () => {
        // Remove existing chips
        wrap.querySelectorAll('.token-chip').forEach(el => el.remove());
        // Remove prior hidden id inputs
        wrap.querySelectorAll('input.presenter-id-hidden').forEach(el => el.remove());
        // Insert chips before the input
        wrap._selected.forEach(({ id, name }) => {
          const chip = document.createElement('span');
          chip.className = 'token-chip';
          chip.dataset.id = String(id);
          chip.textContent = name;
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'remove-btn';
          btn.setAttribute('aria-label', `Remove ${name}`);
          btn.innerHTML = '&times;';
          btn.addEventListener('click', () => {
            wrap._selected = wrap._selected.filter(p => !(p.id === id && p.name === name));
            renderChips();
            updateHiddenValue();
            input.focus();
          });
          chip.appendChild(btn);
          wrap.insertBefore(chip, input);

          // Hidden input for each ID to submit simple arrays along with JSON
          const hid = document.createElement('input');
          hid.type = 'hidden';
          hid.className = 'presenter-id-hidden';
          hid.name = 'presenter_ids[]';
          hid.value = String(id);
          wrap.appendChild(hid);
        });
      };

      // Expose helper so other module methods (e.g., modal confirm) can push and refresh
      wrap._pdAddPresenter = ({ id, name }) => {
        if (!name) return;
        const exists = wrap._selected.some(p => (id && p.id === id) || (!id && p.name.toLowerCase() === String(name).toLowerCase()));
        if (exists) return;
        wrap._selected.push({ id: Number(id) || 0, name: String(name || '') });
        renderChips();
        updateHiddenValue();
        // Clear any typed text left in the input
        input.value = '';
      };

      const updateHiddenValue = () => {
        try {
          hidden.value = JSON.stringify(wrap._selected);
        } catch (_) {
          hidden.value = '[]';
        }
      };

      const hideSuggestions = () => {
        list.style.display = 'none';
        input.setAttribute('aria-expanded', 'false');
        // Clear active highlight
        list.querySelectorAll('li[aria-selected="true"]').forEach(li => li.setAttribute('aria-selected', 'false'));
      };

      const showSuggestions = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
          // caller should use showNoResults() when appropriate
          hideSuggestions();
          return;
        }
        list.innerHTML = '';
        items.forEach(({ id, name }, idx) => {
          const li = document.createElement('li');
          li.textContent = name;
          li.setAttribute('role', 'option');
          li.dataset.id = String(id);
          li.dataset.name = name;
          if (idx === 0) li.setAttribute('aria-selected', 'true');
          li.addEventListener('mousedown', (e) => { // mousedown to avoid blur hiding
            e.preventDefault();
            addPresenter({ id, name });
          });
          list.appendChild(li);
        });
        list.style.display = '';
        input.setAttribute('aria-expanded', 'true');
      };

      const showNoResults = (term) => {
        list.innerHTML = '';
        const li = document.createElement('li');
        li.className = 'no-results-row';
        li.setAttribute('aria-disabled', 'true');
        const msg = document.createElement('div');
        msg.className = 'no-results-msg';
        msg.textContent = 'No results';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'add-new-presentor-btn';
        btn.textContent = 'Add new presentor?';
        btn.addEventListener('mousedown', (e) => {
          e.preventDefault();
          this.openAddPresenterModal(term || '');
        });
        li.appendChild(msg);
        li.appendChild(btn);
        list.appendChild(li);
        list.style.display = '';
        input.setAttribute('aria-expanded', 'true');
      };

      const addPresenter = ({ id, name }) => {
        // Avoid duplicates by id or case-insensitive name when id=0
        const exists = wrap._selected.some(p => (id && p.id === id) || (!id && p.name.toLowerCase() === String(name).toLowerCase()));
        if (exists) {
          hideSuggestions();
          input.value = '';
          return;
        }
        wrap._selected.push({ id: Number(id) || 0, name: String(name || '') });
        renderChips();
        hideSuggestions();
        input.value = '';
        updateHiddenValue();
      };

      const debouncedSearch = (term) => {
        if (wrap._debounce) clearTimeout(wrap._debounce);
        wrap._debounce = setTimeout(async () => {
          // Enforce letters + spaces on the fly
          const q = (term || '').replace(/[^a-zA-Z\s]+/g, '').replace(/\s+/g, ' ').trim();
          if (!q) { hideSuggestions(); return; }
          try {
            const results = await Module.searchPresenters(q);
            // Filter out already selected
            const filtered = results.filter(r => !wrap._selected.some(p => (r.id && p.id === r.id) || (!r.id && p.name.toLowerCase() === r.name.toLowerCase())));
            if (filtered.length === 0) {
              showNoResults(q);
            } else {
              showSuggestions(filtered);
            }
          } catch (err) {
            console.error(err);
            hideSuggestions();
          }
        }, 500);
      };

      // Bind input handlers
      input.removeEventListener('input', input._pdInputHandler || (() => { }));
      input._pdInputHandler = (e) => {
        // Keep only letters and spaces visually while typing; collapse spaces
        const cur = input.value;
        const cleaned = cur.replace(/[^a-zA-Z\s]+/g, '').replace(/\s+/g, ' ');
        if (cur !== cleaned) {
          const pos = input.selectionStart;
          input.value = cleaned;
          try { input.setSelectionRange(cleaned.length, cleaned.length); } catch (_) { }
        }
        debouncedSearch(input.value);
      };
      input.addEventListener('input', input._pdInputHandler);

      input.removeEventListener('keydown', input._pdKeyHandler || (() => { }));
      input._pdKeyHandler = (ev) => {
        const key = ev.key;
        if (key === 'Backspace' && input.value === '' && wrap._selected.length > 0) {
          // Remove last chip
          wrap._selected.pop();
          renderChips();
          updateHiddenValue();
          hideSuggestions();
          ev.preventDefault();
          return;
        }
        if (list.style.display !== 'none') {
          const all = Array.from(list.querySelectorAll('li'));
          const cur = all.findIndex(li => li.getAttribute('aria-selected') === 'true');
          if (key === 'ArrowDown') {
            const next = Math.min(all.length - 1, cur + 1);
            all.forEach(li => li.setAttribute('aria-selected', 'false'));
            if (all[next]) all[next].setAttribute('aria-selected', 'true');
            ev.preventDefault();
          } else if (key === 'ArrowUp') {
            const prev = Math.max(0, cur - 1);
            all.forEach(li => li.setAttribute('aria-selected', 'false'));
            if (all[prev]) all[prev].setAttribute('aria-selected', 'true');
            ev.preventDefault();
          } else if (key === 'Enter') {
            const li = all.find(li => li.getAttribute('aria-selected') === 'true') || all[0];
            if (li) {
              addPresenter({ id: Number(li.dataset.id) || 0, name: li.dataset.name });
              ev.preventDefault();
            }
          } else if (key === 'Escape') {
            hideSuggestions();
            ev.preventDefault();
          }
        }
      };
      input.addEventListener('keydown', input._pdKeyHandler);

      input.removeEventListener('blur', input._pdBlurHandler || (() => { }));
      input._pdBlurHandler = (e) => {
        setTimeout(() => hideSuggestions(), 120); // allow click selection
      };
      input.addEventListener('blur', input._pdBlurHandler);

      // Initial render (if any prefilled value): turn CSV text into chips, then clear text box
      if (input.value && input.value.trim() !== '') {
        const names = input.value.split(',').map(s => s.trim()).filter(Boolean);
        wrap._selected = names.map(n => ({ id: 0, name: n }));
        // Clear the visible text to avoid duplicate display (chips + text)
        input.value = '';
      }
      renderChips();
      updateHiddenValue();

      // On submit, mirror selected names into visible input to satisfy required validation
      const form = input.closest('form');
      if (form && !form._pdPresentersSubmitBound) {
        form._pdPresentersSubmitBound = true;
        form.addEventListener('submit', () => {
          input.value = (wrap._selected || []).map(p => p.name).join(', ');
        });
      }
    },
    setupAddNewForSelect(select, label) {
      if (!select) return;
      // Ensure an Add new option exists at the end
      const ADD_VAL = '__ADD_NEW__';
      if (![...select.options].some(o => o.value === ADD_VAL)) {
        const opt = document.createElement('option');
        opt.value = ADD_VAL;
        opt.textContent = 'Add new';
        select.appendChild(opt);
      }
      // Remove prior handler if any
      if (select._pdAddNewHandler) select.removeEventListener('change', select._pdAddNewHandler);
      const handler = (e) => {
        if (select.value === ADD_VAL) {
          this.showAddNewInput(select, label);
        }
      };
      select._pdAddNewHandler = handler;
      select.addEventListener('change', handler);
    },
    showAddNewInput(select, label) {
      if (!select) return;
      // Create inline input UI adjacent to select
      const wrap = document.createElement('div');
      wrap.className = 'addnew-container';
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-input addnew-input';
      input.placeholder = `Add new ${label}...`;

      const actions = document.createElement('div');
      actions.className = 'addnew-actions';

      const btnOk = document.createElement('button');
      btnOk.type = 'button';
      btnOk.className = 'addnew-btn addnew-ok';
      btnOk.title = 'Confirm';
      btnOk.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>';

      const btnCancel = document.createElement('button');
      btnCancel.type = 'button';
      btnCancel.className = 'addnew-btn addnew-cancel';
      btnCancel.title = 'Cancel';
      btnCancel.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';

      actions.appendChild(btnOk);
      actions.appendChild(btnCancel);
      wrap.appendChild(input);
      wrap.appendChild(actions);

      // Insert and hide select
      select.style.display = 'none';
      select.parentNode.insertBefore(wrap, select.nextSibling);
      input.focus();

      const clearErrorIfTyped = () => {
        if ((input.value || '').trim().length > 0) {
          input.classList.remove('addnew-error');
          input.removeAttribute('aria-invalid');
          if (input.dataset.origPh) input.placeholder = input.dataset.origPh;
        }
      };
      // Clear error state only when the user types something
      input.addEventListener('input', clearErrorIfTyped);

      btnCancel.addEventListener('click', () => {
        // Tear down and restore select
        wrap.remove();
        select.value = '';
        select.style.display = '';
        select.focus();
      });

      btnOk.addEventListener('click', () => {
        const v = (input.value || '').trim();
        if (!v) {
          // Validation error state
          try { console.warn('PDSessionsTable: add-new empty value', { field: label }); } catch (_) { }
          input.dataset.origPh = input.placeholder;
          input.placeholder = 'please enter something';
          input.classList.add('addnew-error');
          input.setAttribute('aria-invalid', 'true');
          input.focus();
          return;
        }
        // For now, hold — no action; keep input visible
        try { console.log('PDSessionsTable: add-new pending', { field: label, value: v }); } catch (_) { }
      });
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
        try {
          this._totalPagesAll = await this.fetchSessionsTotalPages('');
        } catch (_) {
          this._totalPagesAll = null;
        }
        const rows = await this.fetchSessions();
        this._lastFetchCount = Array.isArray(rows) ? rows.length : 0;
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
    // Expose cache + helpers so other modules can manage freshness and updates
    attendeesCache: Module.attendeesCache,
    invalidateCache: function (id) { try { Module.attendeesCache.delete(id); } catch (_) { } },
    setCacheItems: function (id, items) { try { Module.attendeesCache.set(id, { items: Array.isArray(items) ? items.slice() : [], at: Date.now() }); } catch (_) { } },
    // Immediately set the search term and refresh the visible table (no debounce)
    setSearchImmediate: function (val) {
      Module.searchTerm = (val || '').toString();
      Module.currentPage = 1;
      Module.reloadSessionsFromServer();
    },
    prependSessionById: async function (id) {
      const raw = await Module.fetchSessionById(id);
      if (!raw) { await Module.init(); return; }
      // remove duplicate if exists
      const idVal = (raw && raw.id != null) ? raw.id : null;
      if (Array.isArray(Module.rawRows)) {
        Module.rawRows = Module.rawRows.filter(r => {
          const rid = (r && r.id != null) ? r.id : null;
          return idVal === null || rid !== idVal;
        });
      } else {
        Module.rawRows = [];
      }
      Module.rawRows.unshift(raw);
      Module.refreshTable();
      // Open the Details dropdown for the new top row to show attendees
      try { Module.toggleAttendeeDropdown(null, 0); } catch (_) { }
    },
    toggleAttendeeDropdown: Module.toggleAttendeeDropdown.bind(Module),
    // Live search API
    queueSearch: Module.queueSearch.bind(Module),
    queueSearchFromDom: Module.queueSearchFromDom.bind(Module),
    // UI hook: call setAttendeeSort('name'|'last'|'email') from your future buttons,
    // then call refreshVisibleAttendees() to re-render any open attendee lists.
    setAttendeeSort: function (mode) {
      const allowed = ['name', 'last', 'email'];
      if (allowed.includes(mode)) {
        Module.attendeeSort = mode;
      }
    },
    getAttendeeSort: function () { return Module.attendeeSort; },
    refreshVisibleAttendees: function () {
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
  // Presenter modal handlers (optional inline use)
  window.openAddPresenterModal = Module.openAddPresenterModal.bind(Module);
  window.closeAddPresenterModal = Module.closeAddPresenterModal.bind(Module);
  // Edit Session modal close handler
  window.closeEditSessionModal = Module.closeEditSessionModal.bind(Module);

  // Auto-init when script loads (footer)
  Module.init();

  // When Edit modal opens, set up token input for its presenters field
  try {
    document.addEventListener('pd:edit-session-modal-opened', function (e) {
      const sel = '#editSessionModal #editPresenters';
      try { Module.setupPresenterTokenInput(sel); } catch (_) { }
      // Bind save handler using the session id from event detail if available
      try {
        const overlay = document.getElementById('editSessionModal');
        const session = e && e.detail && e.detail.session ? e.detail.session : null;
        const sid = session && session.id != null ? session.id : null;
        if (overlay && sid) {
          Module.bindEditSessionSave(overlay, sid, session);
          // Prefill presenters chips from the authoritative session presenters API.
          Module.prefillEditSessionPresenters(overlay, sid);
        }
      } catch (_) { }
    });
  } catch (_) { }
})();
