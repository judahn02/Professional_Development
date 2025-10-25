// PDSessionsModal: encapsulates Add Session modal logic (open/close, populate selects, CEU visibility, inline "Add new")
(function(){
  'use strict';

  const utils = (window.PDSessionsUtils || {});

  const Modal = {
    _escHandler: null,
    _ceuBindDone: false,
    _parentEvents: null,
    _parentDebounce: null,

    getFormOptionsUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute3 || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },

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

    getAddLookupUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute6 || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },
    async addLookupValue(target, value) {
      const url = this.getAddLookupUrl();
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        },
        body: JSON.stringify({ target, value })
      });
      if (!res.ok) {
        const body = await res.text().catch(()=> '');
        throw new Error(`Add lookup failed ${res.status}: ${body.slice(0,300)}`);
      }
      return res.json();
    },

    getCreateSessionUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute8 || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },
    async createSessionAPI(payload) {
      const url = this.getCreateSessionUrl();
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
        const body = await res.text().catch(()=> '');
        throw new Error(`Create session failed ${res.status}: ${body.slice(0,300)}`);
      }
      return res.json();
    },

    getParentEventsUrl() {
      const root = (window.PDSessions && window.PDSessions.restRoot || '').replace(/\/+$/, '');
      const route = (window.PDSessions && window.PDSessions.sessionsRoute7 || '').replace(/^\/+/, '');
      return `${root}/${route}`;
    },
    async fetchParentEvents() {
      if (Array.isArray(this._parentEvents)) return this._parentEvents.slice();
      const url = this.getParentEventsUrl();
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          ...(window.PDSessions && window.PDSessions.nonce ? { 'X-WP-Nonce': window.PDSessions.nonce } : {}),
        }
      });
      if (!res.ok) {
        const body = await res.text().catch(()=> '');
        throw new Error(`Parent events fetch failed ${res.status}: ${body.slice(0,300)}`);
      }
      const data = await res.json().catch(()=> []);
      const arr = Array.isArray(data) ? data.filter(v => typeof v === 'string') : [];
      // Deduplicate + sort case-insensitive
      const uniq = Array.from(new Set(arr)).sort((a,b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
      this._parentEvents = uniq;
      return uniq.slice();
    },

    setupParentEventAutocomplete(overlay) {
      const input = overlay.querySelector('#parentEvent');
      if (!input) return;
      // Wrap input to anchor suggestions list
      let wrap = input.closest('.autocomplete-wrap');
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.className = 'autocomplete-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
      }
      // Create suggestions list once
      let list = wrap.querySelector('.suggestions-list');
      if (!list) {
        list = document.createElement('ul');
        list.className = 'suggestions-list';
        list.style.display = 'none';
        wrap.appendChild(list);
      }

      const hide = () => {
        list.style.display = 'none';
        // clear active states
        list.querySelectorAll('li[aria-selected="true"]').forEach(li => li.setAttribute('aria-selected','false'));
      };
      const show = (items) => {
        if (!items || items.length === 0) { hide(); return; }
        list.innerHTML = '';
        items.forEach((name, idx) => {
          const li = document.createElement('li');
          li.textContent = name;
          li.setAttribute('role', 'option');
          if (idx === 0) li.setAttribute('aria-selected', 'true');
          li.addEventListener('mousedown', (e) => {
            e.preventDefault();
            input.value = name;
            hide();
          });
          list.appendChild(li);
        });
        list.style.display = '';
      };

      const filter = (q, all) => {
        const s = (q || '').toLowerCase();
        if (!s) return all;
        return all.filter(v => v.toLowerCase().includes(s));
      };

      const debounced = async () => {
        if (this._parentDebounce) clearTimeout(this._parentDebounce);
        this._parentDebounce = setTimeout(async () => {
          try {
            const all = await this.fetchParentEvents();
            show(filter(input.value, all));
          } catch (err) { console.error(err); hide(); }
        }, 300);
      };

      // Bind events (idempotent)
      input.removeEventListener('focus', input._pdPEFocus || (()=>{}));
      input._pdPEFocus = () => { debounced(); };
      input.addEventListener('focus', input._pdPEFocus);

      input.removeEventListener('input', input._pdPEInput || (()=>{}));
      input._pdPEInput = () => { debounced(); };
      input.addEventListener('input', input._pdPEInput);

      input.removeEventListener('keydown', input._pdPEKeys || (()=>{}));
      input._pdPEKeys = (ev) => {
        const key = ev.key;
        if (list.style.display === 'none') return;
        const items = Array.from(list.querySelectorAll('li'));
        if (items.length === 0) return;
        const idx = items.findIndex(li => li.getAttribute('aria-selected') === 'true');
        if (key === 'ArrowDown') {
          const next = Math.min(items.length - 1, idx + 1);
          items.forEach(li => li.setAttribute('aria-selected','false'));
          items[next].setAttribute('aria-selected','true');
          ev.preventDefault();
        } else if (key === 'ArrowUp') {
          const prev = Math.max(0, idx - 1);
          items.forEach(li => li.setAttribute('aria-selected','false'));
          items[prev].setAttribute('aria-selected','true');
          ev.preventDefault();
        } else if (key === 'Enter') {
          const li = items.find(li => li.getAttribute('aria-selected') === 'true') || items[0];
          if (li) {
            input.value = li.textContent || '';
            hide();
            ev.preventDefault();
          }
        } else if (key === 'Escape') {
          hide();
          ev.preventDefault();
        }
      };
      input.addEventListener('keydown', input._pdPEKeys);

      input.removeEventListener('blur', input._pdPEBlur || (()=>{}));
      input._pdPEBlur = () => { setTimeout(hide, 120); };
      input.addEventListener('blur', input._pdPEBlur);
    },

    setupAddNewForSelect(select, label) {
      if (!select) return;
      const ADD_VAL = '__ADD_NEW__';
      if (![...select.options].some(o => o.value === ADD_VAL)) {
        const opt = document.createElement('option');
        opt.value = ADD_VAL;
        opt.textContent = 'Add new';
        select.appendChild(opt);
      }
      if (select._pdAddNewHandler) select.removeEventListener('change', select._pdAddNewHandler);
      const handler = () => { if (select.value === ADD_VAL) Modal.showAddNewInput(select, label); };
      select._pdAddNewHandler = handler;
      select.addEventListener('change', handler);
    },

    showAddNewInput(select, label) {
      if (!select) return;
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
      input.addEventListener('input', clearErrorIfTyped);

      btnCancel.addEventListener('click', () => {
        wrap.remove();
        select.value = '';
        select.style.display = '';
        select.focus();
      });

      btnOk.addEventListener('click', async () => {
        const v = (input.value || '').trim();
        if (!v) {
          try { console.warn('PDSessionsModal: add-new empty value', { field: label }); } catch(_) {}
          input.dataset.origPh = input.placeholder;
          input.placeholder = 'please enter something';
          input.classList.add('addnew-error');
          input.setAttribute('aria-invalid', 'true');
          input.focus();
          return;
        }
        // Map select id -> target string used by the stored procedure
        let target = '';
        if (select.id === 'sessionType') target = 'type_of_session';
        else if (select.id === 'eventType') target = 'event_type';
        else if (select.id === 'ceuConsiderations') target = 'ceu_consideration';
        else target = '';

        if (!target) {
          try { console.error('PDSessionsModal: unknown select for add-new', select.id); } catch(_) {}
          return;
        }
        // Call REST to add lookup value and receive new id
        try {
          const resp = await Modal.addLookupValue(target, v);
          const newId = resp && resp.id ? String(resp.id) : '';
          if (!newId) throw new Error('Missing id in response');
          // Insert option at alphabetically sorted position and select it
          const opt = document.createElement('option');
          opt.value = newId;
          opt.textContent = v;
          const addOpt = [...select.options].find(o => o.value === '__ADD_NEW__');
          const placeholderOpt = select.options[0] && select.options[0].value === '' ? select.options[0] : null;
          // Determine insertion point among existing options (excluding placeholder and add-new)
          let inserted = false;
          const compare = (a, b) => String(a).localeCompare(String(b), undefined, { sensitivity: 'base' });
          for (let i = 0; i < select.options.length; i++) {
            const o = select.options[i];
            if (o === placeholderOpt) continue;
            if (o === addOpt) { select.insertBefore(opt, o); inserted = true; break; }
            if (o && o.value !== '__ADD_NEW__' && o.value !== '') {
              if (compare(v, o.textContent) < 0) { select.insertBefore(opt, o); inserted = true; break; }
            }
          }
          if (!inserted) {
            if (addOpt) select.insertBefore(opt, addOpt); else select.appendChild(opt);
          }
          // Teardown inline input UI and restore select
          wrap.remove();
          select.style.display = '';
          select.value = newId;
          // fire change for any dependent logic
          try { select.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {}
          select.focus();
        } catch (err) {
          console.error(err);
          alert('Failed to add value.');
        }
      });
    },

    applyCeuVisibility(overlay) {
      const qualify = overlay.querySelector('#qualifyForCeus');
      const ceuGroup = overlay.querySelector('#ceuConsiderationsGroup');
      const ceuSelect = overlay.querySelector('#ceuConsiderations');
      if (!(qualify && ceuGroup && ceuSelect)) return;

      if (![...ceuSelect.options].some(o => o.value === 'NA')) {
        const o = document.createElement('option');
        o.value = 'NA';
        o.textContent = 'NA';
        ceuSelect.appendChild(o);
      }
      const handler = () => {
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
      qualify.value = 'No';
      handler();
      qualify.removeEventListener('change', qualify._pdCeuHandler || (()=>{}));
      qualify._pdCeuHandler = handler;
      qualify.addEventListener('change', handler);
    },

    bindCeuWeight(overlay) {
      const lenEl = overlay.querySelector('#sessionLength');
      const qualify = overlay.querySelector('#qualifyForCeus');
      const weightEl = overlay.querySelector('#ceuWeight');
      if (!weightEl || !(lenEl && qualify)) return;

      const update = () => {
        const minutes = Number(lenEl.value || 0);
        let value = 0;
        if (qualify.value === 'Yes' && Number.isFinite(minutes) && minutes > 0) {
          const w = (minutes / 60) * 0.10;
          value = Math.max(0, w);
        }
        // Use fixed 2 decimals for display consistency
        weightEl.value = value.toFixed(2);
      };

      // Avoid duplicate bindings
      lenEl.removeEventListener('input', lenEl._pdLenHandler || (()=>{}));
      lenEl._pdLenHandler = update;
      lenEl.addEventListener('input', update);

      qualify.removeEventListener('change', qualify._pdQualHandler2 || (()=>{}));
      qualify._pdQualHandler2 = update;
      qualify.addEventListener('change', update);

      // Initial compute
      update();
    },

    async open() {
      const overlay = document.getElementById('addSessionModal');
      if (!overlay) return;
      overlay.classList.add('active');

      try {
        const opts = await this.fetchFormOptions();
        const sessionType = overlay.querySelector('#sessionType');
        const eventType = overlay.querySelector('#eventType');
        const ceuSelect = overlay.querySelector('#ceuConsiderations');
        if (sessionType) {
          utils.clearAndFillSelect(sessionType, 'Select Type', opts.session_types || [], 'session_id', 'session_name');
          this.setupAddNewForSelect(sessionType, 'Session Type');
        }
        if (eventType) {
          utils.clearAndFillSelect(eventType, 'Select Event Type', opts.event_types || [], 'event_id', 'event_name');
          this.setupAddNewForSelect(eventType, 'Event Type');
        }
        if (ceuSelect) {
          utils.clearAndFillSelect(ceuSelect, 'Select CEU Consideration', opts.ceu_considerations || [], 'ceu_id', 'ceu_name');
          if (![...ceuSelect.options].some(o => o.value === 'NA')) {
            const na = document.createElement('option');
            na.value = 'NA';
            na.textContent = 'NA';
            ceuSelect.appendChild(na);
          }
          this.setupAddNewForSelect(ceuSelect, 'CEU Consideration');
        }
      } catch (err) {
        console.error(err);
      }

      this.applyCeuVisibility(overlay);
      this.setupParentEventAutocomplete(overlay);
      this.bindCeuWeight(overlay);

      const onOverlayClick = (e) => { if (e.target === overlay) this.close(); };
      overlay.addEventListener('click', onOverlayClick, { once: true });

      this._escHandler = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') this.close(); };
      document.addEventListener('keydown', this._escHandler);

      const first = overlay.querySelector('input, select, textarea, button');
      if (first && typeof first.focus === 'function') first.focus();

      // Bind Add Session form submit
      this.bindAddSessionForm(overlay);

      // Notify listeners so other modules (e.g., token input) can initialize
      try { document.dispatchEvent(new CustomEvent('pd:add-session-modal-opened')); } catch(_) {}
    },

    close() {
      const overlay = document.getElementById('addSessionModal');
      if (!overlay) return;
      overlay.classList.remove('active');
      if (this._escHandler) {
        document.removeEventListener('keydown', this._escHandler);
        this._escHandler = null;
      }
      try { document.dispatchEvent(new CustomEvent('pd:add-session-modal-closed')); } catch(_) {}
    },
  };

  Modal.bindAddSessionForm = function(overlay) {
    const form = overlay.querySelector('#addSessionForm');
    if (!form || form._pdSubmitBound) return;
    form._pdSubmitBound = true;
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = form.querySelector('.btn-save');
      const orig = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
      try {
        const payload = collectAddPayload(overlay);
        const resp = await Modal.createSessionAPI(payload);
        // Close modal and refresh table
        Modal.close();
        try {
          if (window.PDSessionsTable && typeof window.PDSessionsTable.refresh === 'function') {
            window.PDSessionsTable.refresh();
          }
        } catch(_) {}
        alert('Session created. ID: ' + (resp && resp.id));
      } catch (err) {
        console.error(err);
        alert(err.message || 'Failed to create session.');
      } finally {
        if (btn) { btn.disabled = false; btn.textContent = orig; }
      }
    });

    function collectAddPayload(overlay) {
      const v = (sel) => {
        const el = overlay.querySelector(sel);
        return el ? el.value : '';
      };
      const payload = {
        session_date: v('#sessionDate'),
        length_minutes: parseInt(v('#sessionLength'), 10) || 0,
        session_title: v('#sessionTitle'),
        type_of_session_id: parseInt(v('#sessionType'), 10) || 0,
        event_type_id: parseInt(v('#eventType'), 10) || 0,
      };

      // Optional: specific_event only if non-empty (avoid sending null to REST schema type string)
      const parentEvent = (v('#parentEvent') || '').trim();
      if (parentEvent !== '') payload.specific_event = parentEvent;

      // Optional: ceu_id only when qualifying and numeric
      const qualify = v('#qualifyForCeus');
      const ceuSel = overlay.querySelector('#ceuConsiderations');
      const ceuRaw = ceuSel ? ceuSel.value : '';
      if (qualify === 'Yes') {
        const ceuNum = parseInt(ceuRaw, 10);
        if (Number.isFinite(ceuNum) && ceuNum > 0) payload.ceu_id = ceuNum;
      }

      // Optional: presenters_csv from hidden inputs (comma-separated)
      const wrap = overlay.querySelector('#presenters') ? overlay.querySelector('#presenters').closest('.token-input') : null;
      const ids = wrap ? Array.from(wrap.querySelectorAll('input.presenter-id-hidden')).map(i => i.value).filter(Boolean) : [];
      if (ids.length) payload.presenters_csv = ids.join(',');

      return payload;
    }
  };

  window.PDSessionsModal = Modal;
  // Keep legacy inline handlers working if referenced directly
  window.openAddSessionModal = Modal.open.bind(Modal);
  window.closeAddSessionModal = Modal.close.bind(Modal);
})();
