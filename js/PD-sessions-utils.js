(function(){
  'use strict';

  const Utils = {
    // Date and time helpers
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

    // Attendee helpers
    formatAttendeeItem(item) {
      if (!Array.isArray(item)) return { name: '', email: '', status: '', memberId: 0 };
      const [name, email, status, memberId] = item;
      return {
        name: name ? String(name) : '',
        email: email ? String(email) : '',
        status: status ? String(status) : '',
        memberId: Number.isFinite(Number(memberId)) ? Number(memberId) : 0,
      };
    },
    getLastName(name) {
      const n = (name || '').trim();
      if (!n) return '';
      if (n.includes(',')) {
        const [last] = n.split(',');
        return last.trim();
      }
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
          const al = Utils.getLastName(a.name);
          const bl = Utils.getLastName(b.name);
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
        const statusSpan = document.createElement('span');
        statusSpan.className = 'attendee-status';
        const rawStatus = (a.status || '').toString().trim();
        const displayStatus = rawStatus !== '' ? rawStatus : 'Not Assigned';
        statusSpan.textContent = 'Cert. Status: ' + displayStatus;
        li.appendChild(nameSpan);
        li.appendChild(emailSpan);
        li.appendChild(statusSpan);
        ul.appendChild(li);
      }
    },

    // Populate a <select> with placeholder + id->value, label->text, sorted by label A->Z
    clearAndFillSelect(select, placeholder, items, idKey, nameKey) {
      if (!select) return;
      select.innerHTML = '';
      if (placeholder) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder;
        select.appendChild(opt);
      }
      if (Array.isArray(items)) {
        const sorted = items.slice().sort((a, b) => {
          const an = (a && a[nameKey] != null) ? String(a[nameKey]) : '';
          const bn = (b && b[nameKey] != null) ? String(b[nameKey]) : '';
          return an.localeCompare(bn, undefined, { sensitivity: 'base' });
        });
        for (const item of sorted) {
          if (!item) continue;
          const opt = document.createElement('option');
          opt.value = String(item[idKey]);
          opt.textContent = String(item[nameKey]);
          select.appendChild(opt);
        }
      }
    },

    // Compare helpers
    compareValues(a, b, numeric = false) {
      if (numeric) {
        const na = Number(a);
        const nb = Number(b);
        const aa = Number.isFinite(na) ? na : Number.POSITIVE_INFINITY;
        const bb = Number.isFinite(nb) ? nb : Number.POSITIVE_INFINITY;
        return aa === bb ? 0 : (aa < bb ? -1 : 1);
      }
      const sa = (a ?? '').toString();
      const sb = (b ?? '').toString();
      return sa.localeCompare(sb, undefined, { sensitivity: 'base' });
    },
    compareRows(a, b, key, dir, normalizeFn) {
      const ra = typeof normalizeFn === 'function' ? normalizeFn(a) : a;
      const rb = typeof normalizeFn === 'function' ? normalizeFn(b) : b;
      let va = ra[key];
      let vb = rb[key];
      let cmp = 0;
      if (key === 'lengthMin' || key === 'attendeesCt') {
        cmp = Utils.compareValues(va, vb, true);
      } else if (key === 'ceuWeight') {
        const pa = parseFloat(va);
        const pb = parseFloat(vb);
        cmp = Utils.compareValues(pa, pb, true);
      } else {
        cmp = Utils.compareValues(va, vb, false);
      }
      return dir === 'asc' ? cmp : -cmp;
    },

    // Sync top scrollbar with main container; idempotent
    syncHorizontalScroll(topEl, containerEl, tableEl, spacerEl) {
      if (!topEl || !containerEl || !tableEl || !spacerEl) return;
      const setWidths = () => {
        spacerEl.style.width = tableEl.scrollWidth + 'px';
        topEl.scrollLeft = containerEl.scrollLeft;
      };
      setWidths();
      if (topEl._pdSyncBound) return; // already bound
      topEl._pdSyncBound = true;
      let syncing = false;
      topEl.addEventListener('scroll', () => {
        if (syncing) return;
        syncing = true;
        containerEl.scrollLeft = topEl.scrollLeft;
        syncing = false;
      }, { passive: true });
      containerEl.addEventListener('scroll', () => {
        if (syncing) return;
        syncing = true;
        topEl.scrollLeft = containerEl.scrollLeft;
        syncing = false;
      }, { passive: true });
      window.addEventListener('resize', setWidths);
    },
  };

  window.PDSessionsUtils = Utils;
})();
