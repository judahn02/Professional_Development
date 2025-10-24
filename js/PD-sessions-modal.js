// PDSessionsModal: encapsulates Add Session modal logic (open/close, populate selects, CEU visibility, inline "Add new")
(function(){
  'use strict';

  const utils = (window.PDSessionsUtils || {});

  const Modal = {
    _escHandler: null,

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

      btnOk.addEventListener('click', () => {
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
        // Keep input visible for now; saving to DB is out-of-scope for this modal utility
        try { console.log('PDSessionsModal: add-new pending', { field: label, value: v }); } catch(_){}
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

      const onOverlayClick = (e) => { if (e.target === overlay) this.close(); };
      overlay.addEventListener('click', onOverlayClick, { once: true });

      this._escHandler = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') this.close(); };
      document.addEventListener('keydown', this._escHandler);

      const first = overlay.querySelector('input, select, textarea, button');
      if (first && typeof first.focus === 'function') first.focus();

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

  window.PDSessionsModal = Modal;
  // Keep legacy inline handlers working if referenced directly
  window.openAddSessionModal = Modal.open.bind(Modal);
  window.closeAddSessionModal = Modal.close.bind(Modal);
})();

