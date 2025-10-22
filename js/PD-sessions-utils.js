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
      if (!Array.isArray(item)) return { name: '', email: '' };
      const [name, email] = item;
      return {
        name: name ? String(name) : '',
        email: email ? String(email) : '',
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
        li.appendChild(nameSpan);
        li.appendChild(emailSpan);
        ul.appendChild(li);
      }
    },

    // Populate a <select> with placeholder + id->value, label->text
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
        for (const item of items) {
          if (!item) continue;
          const opt = document.createElement('option');
          opt.value = String(item[idKey]);
          opt.textContent = String(item[nameKey]);
          select.appendChild(opt);
        }
      }
    },
  };

  window.PDSessionsUtils = Utils;
})();
