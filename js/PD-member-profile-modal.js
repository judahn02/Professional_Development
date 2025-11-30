(function() {
  'use strict';

  const cfg = (typeof window.PDMemberProfile !== 'undefined' && window.PDMemberProfile) || {};
  const restRoot = String(cfg.restRoot || '/wp-json/profdef/v2/').replace(/\/+$/, '');
  const routeMe = String(cfg.routeMe || 'member/me').replace(/^\/+/, '');
  const meUrl = restRoot + '/' + routeMe;
  const nonce = cfg.nonce || '';

  const openBtn = document.getElementById('pdMemberProfileOpenBtn');
  const overlay = document.getElementById('pdMemberProfileModal');
  const modal = overlay ? overlay.querySelector('.pdmp-modal') : null;
  const closeBtn = overlay ? overlay.querySelector('.pdmp-modal-close') : null;
  const closeFooterBtn = document.getElementById('pdMemberProfileCloseBtn');
  const exportBtn = document.getElementById('pdMemberProfileExportBtn');

  if (!openBtn || !overlay || !modal) return;

  let lastFocused = null;
  let lastProfileData = null;

  function toDateOnly(d) {
    if (!d) return '';
    const s = String(d);
    if (s.includes('T')) return s.split('T')[0];
    return s;
  }

  function minutesToHours(mins) {
    const m = Number(mins || 0);
    if (!Number.isFinite(m) || m <= 0) return '0';
    return String(Math.round((m / 60) * 100) / 100);
  }

  async function fetchMemberProfile() {
    const headers = { 'Accept': 'application/json' };
    if (nonce) headers['X-WP-Nonce'] = nonce;
    const res = await fetch(meUrl, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers
    });

    if (!res.ok) {
      let bodyText = '';
      try { bodyText = await res.text(); } catch (_) {}
      let json = null;
      try { json = bodyText ? JSON.parse(bodyText) : null; } catch (_) {}

      const code = json && json.code ? String(json.code) : '';
      const message = json && json.message ? String(json.message) : '';

      if (code === 'pd_not_linked' || res.status === 404) {
        throw new Error('Ask administrator to link your ARMember account to the Professional Development Database.');
      }
      if (res.status === 401 || res.status === 403) {
        throw new Error(message || 'You must be logged in to view your Professional Development record.');
      }
      if (message) {
        throw new Error(message);
      }
      throw new Error('Failed to load Professional Development record.');
    }

    let data = null;
    try {
      data = await res.json();
    } catch (_) {
      throw new Error('Failed to parse Professional Development record.');
    }
    if (!data || typeof data !== 'object') {
      throw new Error('Unexpected response from Professional Development API.');
    }
    return data;
  }

  function renderMemberProfile(data) {
    lastProfileData = data && typeof data === 'object' ? data : null;
    const person = data && data.person ? data.person : {};
    const sessions = Array.isArray(data && data.sessions) ? data.sessions : [];
    const admin = Array.isArray(data && data.admin_service) ? data.admin_service : [];

    const nameEl = document.getElementById('pdMemberProfileName');
    const emailEl = document.getElementById('pdMemberProfileEmail');
    const phoneEl = document.getElementById('pdMemberProfilePhone');

    const first = (person.first_name || '').trim();
    const last = (person.last_name || '').trim();
    const fullName = (first || last) ? (first + ' ' + last).trim() : '';
    const email = (person.email || '').trim();
    const phone = (person.phone_number || '').trim();

    if (nameEl) nameEl.textContent = fullName || 'Member';
    if (emailEl) emailEl.textContent = email ? 'Email: ' + email : '';
    if (phoneEl) phoneEl.textContent = phone ? 'Phone: ' + phone : '';

    // Sessions table
    const sessionsBody = document.getElementById('pdMemberProfileSessionsBody');
    if (sessionsBody) {
      if (!sessions.length) {
        sessionsBody.innerHTML = '<tr><td colspan="8" class="pdmp-cell-muted">No sessions found.</td></tr>';
      } else {
        sessionsBody.innerHTML = sessions.map((s) => {
          const date = toDateOnly(s['Date'] || s['date'] || s['session_date'] || '');
          const title = s['Title'] || s['title'] || s['session_title'] || '';
          const type = s['Session Type'] || s['Session type'] || s['session_type'] || '';
          const lengthMin = s['Length'] || s['length'] || s['length_minutes'] || 0;
          const hours = minutesToHours(lengthMin);
          const ceuCapable = (s['CEU Capable'] === true || s['CEU Capable'] === 'True' || s['CEU Capable'] === 'true' || s['ceu_capable'] === true);
          const ceuWeight = s['CEU Weight'] != null ? s['CEU Weight'] : s['ceu_weight'];
          const parentEvent = s['Parent Event'] != null ? s['Parent Event'] : (s['parent_event'] || s['specific_event'] || '');
          const eventType = s['Event Type'] != null ? s['Event Type'] : (s['event_type'] || '');
          return (
            '<tr>' +
              '<td>' + (date || '') + '</td>' +
              '<td>' + (title || '') + '</td>' +
              '<td>' + (type || '') + '</td>' +
              '<td>' + hours + '</td>' +
              '<td>' + (ceuCapable ? 'Yes' : 'No') + '</td>' +
              '<td>' + (ceuWeight != null ? ceuWeight : '') + '</td>' +
              '<td>' + (parentEvent || '') + '</td>' +
              '<td>' + (eventType || '') + '</td>' +
            '</tr>'
          );
        }).join('');
      }
    }

    // Administrative service table (read-only)
    const adminBody = document.getElementById('pdMemberProfileAdminBody');
    if (adminBody) {
      if (!admin.length) {
        adminBody.innerHTML = '<tr><td colspan="4" class="pdmp-cell-muted">No administrative service entries found.</td></tr>';
      } else {
        adminBody.innerHTML = admin.map((r) => {
          const start = toDateOnly(r.start_service || r.start_date || '');
          const end = toDateOnly(r.end_service || r.end_date || '');
          const type = r.type || '';
          const ceu = r.ceu_weight != null ? r.ceu_weight : '';
          return (
            '<tr>' +
              '<td>' + (start || '') + '</td>' +
              '<td>' + (end || '') + '</td>' +
              '<td>' + (type || '') + '</td>' +
              '<td>' + (ceu || '') + '</td>' +
            '</tr>'
          );
        }).join('');
      }
    }
  }

  function openModal() {
    lastFocused = document.activeElement;
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
    document.addEventListener('keydown', onKeyDown);
    const focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    setTimeout(function() {
      if (focusables.length) focusables[0].focus();
      else if (closeBtn) closeBtn.focus();
      else modal.focus();
    }, 0);
  }

  function closeModal() {
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
    document.removeEventListener('keydown', onKeyDown);
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      closeModal();
      return;
    }
    if (e.key === 'Tab') {
      const focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }

  async function onOpenClick(e) {
    e.preventDefault();
    // Clear any previous error
    const errEl = document.getElementById('pdMemberProfileError');
    if (errEl) {
      errEl.style.display = 'none';
      errEl.textContent = '';
    }
    openBtn.disabled = true;

    try {
      const data = await fetchMemberProfile();
      renderMemberProfile(data);
      openModal();
    } catch (err) {
      const msg = (err && err.message) ? String(err.message) : 'Failed to load Professional Development record.';
      alert(msg);
    } finally {
      openBtn.disabled = false;
    }
  }

  openBtn.addEventListener('click', onOpenClick);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (closeFooterBtn) closeFooterBtn.addEventListener('click', function(e) { e.preventDefault(); closeModal(); });
  overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
  if (exportBtn) exportBtn.addEventListener('click', function(e) {
    e.preventDefault();
    exportMemberProfileCsv();
  });

  function csvEscape(v) {
    if (v === null || v === undefined) v = '';
    const s = String(v).replace(/"/g, '""');
    return /[",\r\n]/.test(s) ? `"${s}"` : s;
  }

  function exportMemberProfileCsv() {
    const data = lastProfileData;
    const sessions = data && Array.isArray(data.sessions) ? data.sessions : [];
    const admin = data && Array.isArray(data.admin_service) ? data.admin_service : [];
    if (!sessions.length && !admin.length) {
      alert('No Professional Development data available to export.');
      return;
    }

    const headers = [
      'Session Id',
      'Members ID',
      'Date',
      'Session Title',
      'Type',
      'Hours',
      'CEU Capable',
      'CEU Weight',
      'Parent Event',
      'Event Type'
    ];

    const rows = sessions.map((s) => {
      const date = toDateOnly(s['Date'] || s['date'] || s['session_date'] || '');
      const title = s['Title'] || s['title'] || s['session_title'] || '';
      const type = s['Session Type'] || s['Session type'] || s['session_type'] || '';
      const lengthMin = s['Length'] || s['length'] || s['length_minutes'] || 0;
      const hours = minutesToHours(lengthMin);
      const ceuCapable = (s['CEU Capable'] === true || s['CEU Capable'] === 'True' || s['CEU Capable'] === 'true' || s['ceu_capable'] === true);
      const ceuWeight = s['CEU Weight'] != null ? s['CEU Weight'] : s['ceu_weight'];
      const parentEvent = s['Parent Event'] != null ? s['Parent Event'] : (s['parent_event'] || s['specific_event'] || '');
      const eventType = s['Event Type'] != null ? s['Event Type'] : (s['event_type'] || '');
      const sessionId = s['Session Id'] != null ? s['Session Id'] : (s['session_id'] || s['id'] || '');
      const membersId = s['Members_id'] != null ? s['Members_id'] : (s['members_id'] || s['member_id'] || '');

      return [
        sessionId,
        membersId,
        date,
        title,
        type,
        hours,
        ceuCapable ? 'Yes' : 'No',
        ceuWeight != null ? ceuWeight : '',
        parentEvent,
        eventType
      ];
    });

    const csvRows = [headers, ...rows];

    if (admin.length) {
      const adminHeaders = [
        'Admin Start',
        'Admin End',
        'Admin Type',
        'Admin CEU Weight'
      ];
      const adminRows = admin.map((r) => {
        const start = toDateOnly(r.start_service || r.start_date || '');
        const end = toDateOnly(r.end_service || r.end_date || '');
        const type = r.type || '';
        const ceu = r.ceu_weight != null ? r.ceu_weight : '';
        return [start, end, type, ceu];
      });
      // Blank line separator then admin section
      csvRows.push([]);
      csvRows.push(adminHeaders);
      csvRows.push(...adminRows);
    }

    const csv = csvRows
      .map((r) => r.map(csvEscape).join(','))
      .join('\r\n');

    const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'professional-development-sessions.csv';
    document.body.appendChild(a);
    a.click();
    URL.revokeObjectURL(a.href);
    a.remove();
  }
})();
