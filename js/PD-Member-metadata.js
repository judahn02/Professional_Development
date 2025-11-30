
// =========================
// Variables
// =========================

// Session data (fallback until REST loads)
let sessions = [
];

let currentFilter = "all";
let currentSearch = "";
let adminServiceItems = [];
let adminServiceSortKey = 'start';
let adminServiceSortAsc = true;
let adminServiceTypeOptions = null;

// =========================
// Function definitions
// =========================

const toDateOnly = d => (typeof d === "string" ? d.split("T")[0] : "");
const minutesToHours = m => Math.round((Number(m || 0) / 60) * 100) / 100; // 2-dec float

async function loadMemberSessions() {
  const url = `${PDMembers.root}${PDMembers.route}?members_id=${encodeURIComponent(PDMembers.id)}`;
  const res = await fetch(url, { headers: { "X-WP-Nonce": PDMembers.nonce } });
  if (!res.ok) throw new Error(`REST ${res.status}: ${await res.text()}`);
  const data = await res.json();
  return Array.isArray(data.sessions) ? data.sessions : [];
}

// Initialize the page
async function init() {
  try {
    const apiSessions = await loadMemberSessions(); // fetch from REST
    if (Array.isArray(apiSessions) && apiSessions.length) {
      // Map API fields -> UI shape
      sessions = apiSessions.map(s => ({
        date: toDateOnly(s['Date']),
        title: s['Title'] ?? '',
        type: s['Session Type'] ?? '',
        hours: minutesToHours(s['Length']),
        ceuCapable: (s['CEU Capable'] === true || s['CEU Capable'] === 'True'),
        ceuWeight: s['CEU Weight'] ?? '',
        parentEvent: s['Parent Event'] ?? '',
        eventType: s['Event Type'] ?? '',
        sessionId: s['Session Id'] ?? '',
        membersId: s['Members_id'] ?? ''
      }));
    }
  } catch (e) {
    // Using default sessions due to REST error
  }
  // Initial hours will be calculated by the slider's applyRange()
  renderSessions();
  //Line getting most recent session by date, then my order
  const mostRecentSession = sessions.length
    ? (sessions.reduce((best, s, idx) => {
      if (!best) return { s, idx };
      // Assuming date is "YYYY-MM-DD" (safe to compare as a string)
      const cmp = String(s.date).localeCompare(String(best.s.date));
      if (cmp > 0) return { s, idx };          // s has a later date
      if (cmp === 0 && idx < best.idx) return { s, idx }; // same date → keep earlier in original order
      return best;
    }, null)).s
    : null;

const mostRecent = document.getElementById('recentSession');
if (mostRecent && mostRecentSession) {
  mostRecent.textContent = mostRecentSession.title + " | " + mostRecentSession.date + " | " + mostRecentSession.parentEvent
    || 'No title';
}


}

// Calculate total hours
function calculateHours(years = 1) {
  const totalHours = sessions.reduce((sum, session) => sum + (Number(session.hours) || 0), 0);
  const currentDate = new Date();
  const cutoffDate = new Date();
  cutoffDate.setFullYear(currentDate.getFullYear() - years);

  const yearHours = sessions
    .filter(s => new Date(s.date) >= cutoffDate)
    .reduce((sum, session) => sum + (Number(session.hours) || 0), 0);

  const totalEl = document.getElementById('totalHours');
  const filteredEl = document.getElementById('filteredHours');
  if (totalEl) totalEl.textContent = totalHours;
  if (filteredEl) filteredEl.textContent = yearHours;
  document.getElementById('yearHours')?.remove(); // remove static one if still exists
}

// Filter sessions based on search and filter
function getFilteredSessions() {
  const q = (currentSearch || '').toLowerCase();
  const f = (currentFilter || 'all').toLowerCase();
  return sessions.filter(session => {
    const matchesSearch = String(session.title || '').toLowerCase().includes(q);
    const matchesFilter = f === 'all' || String(session.type || '').toLowerCase() === f;
    return matchesSearch && matchesFilter;
  });
}

// Render sessions table
function renderSessions() {
  const filteredSessions = getFilteredSessions();
  const tbody = document.getElementById('sessionsTable');
  const emptyState = document.getElementById('emptyState');
  if (!tbody) return;

  if (filteredSessions.length === 0) {
    tbody.innerHTML = '';
    if (emptyState) emptyState.style.display = 'block';
    return;
  }

  if (emptyState) emptyState.style.display = 'none';
  tbody.innerHTML = filteredSessions.map(session => `
    <tr>
      <td>${session.date}</td>
      <td class="font-semibold">${session.title}</td>
      <td><span class="badge">${session.type}</span></td>
      <td class="text-primary font-bold">${session.hours}h</td>
      <td>${session.ceuCapable ? 'Yes' : 'No'}</td>
      <td>${session.ceuWeight}</td>
      <td>${session.parentEvent}</td>
      <td>${session.eventType}</td>
    </tr>
  `).join('');
}

// Handle search input
function filterSessions() {
  const el = document.getElementById('searchInput');
  currentSearch = el ? el.value : '';
  renderSessions();
}

// Handle filter buttons
function setFilter(filter) {
  currentFilter = filter;
  document.querySelectorAll('[data-filter]').forEach(btn => {
    if (btn.dataset.filter === filter) {
      btn.className = 'btn btn-primary btn-sm';
    } else {
      btn.className = 'btn btn-sm';
    }
  });
  renderSessions();
}

// Export report function
function csvEscape(v) {
  if (v === null || v === undefined) v = '';
  const s = String(v).replace(/"/g, '""');
  return /[",\r\n]/.test(s) ? `"${s}"` : s;
}

function exportReport() {
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

  const rows = sessions.map(s => [
    s.sessionId ?? '',
    s.membersId ?? '',
    s.date ?? '',
    s.title ?? '',
    s.type ?? '',
    (Number(s.hours) || 0),
    (typeof s.ceuCapable === 'boolean' ? (s.ceuCapable ? 'Yes' : 'No') : (s.ceuCapable ?? '')),
    s.ceuWeight ?? '',
    s.parentEvent ?? '',
    s.eventType ?? ''
  ]);

  const csv = [headers, ...rows]
    .map(r => r.map(csvEscape).join(','))
    .join('\r\n');

  const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'aslta-training-report.csv';
  document.body.appendChild(a);
  a.click();
  URL.revokeObjectURL(a.href);
  a.remove();
}

// =========================
// Hook up DOM + run init
// =========================

async function setupUI() {
  // Run initial data load + render and wait for it to finish
  try {
    await init();
  } catch (e) {
    // init failed; will use fallback sessions
  }

  // Slider wiring (years range)
  const yearRange = document.getElementById('yearRange');
  const yearLabel = document.getElementById('yearLabel');

  if (yearRange) {
    const applyRange = () => {
      const raw = yearRange.value || yearRange.getAttribute('value') || yearRange.min || '1';
      const min = parseInt(yearRange.min || '1', 10) || 1;
      const val = Math.max(parseInt(raw, 10) || min, min);
      if (yearLabel) yearLabel.textContent = String(val);
      calculateHours(val);
    };

    yearRange.addEventListener('input', applyRange);
    // Ensure initial label/hours are set even if DOMContentLoaded already fired
    applyRange();
  }
}

// Run immediately if DOM is ready; otherwise wait
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setupUI);
} else {
  setupUI();
}

// Administrative Service modal (member page)
function openMemberAdminServiceModal() {
  const overlay = document.getElementById('memberAdminServiceModal');
  if (!overlay) return;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');
  // Render placeholder then load data
  try {
    const table = overlay.querySelector('.attendees-table');
    const thead = table ? table.querySelector('thead') : null;
    const tbody = table ? table.querySelector('tbody') : null;
    if (thead) {
      thead.innerHTML = `
        <tr>
          <th>Start</th>
          <th>End</th>
          <th>Type</th>
          <th>CEU Weight</th>
          <th>Delete?</th>
        </tr>
      `;
    }
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="5">Loading administrative service...</td></tr>';
    }
    // Fill add-row type select with options
    try { fillAdminServiceTypeSelect(); } catch(_) {}
    loadMemberAdminService().catch(() => {
      if (tbody) tbody.innerHTML = '<tr><td colspan="5">Failed to load administrative service.</td></tr>';
    });
  } catch (_) {}
  const onOverlay = (e) => { if (e.target === overlay) closeMemberAdminServiceModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);
  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closeMemberAdminServiceModal(); };
  document._pdEscMemberService && document.removeEventListener('keydown', document._pdEscMemberService);
  document._pdEscMemberService = onEsc;
  document.addEventListener('keydown', onEsc);
}
function closeMemberAdminServiceModal() {
  const overlay = document.getElementById('memberAdminServiceModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) { overlay.removeEventListener('click', overlay._pdOverlayHandler); overlay._pdOverlayHandler = null; }
  if (document._pdEscMemberService) { document.removeEventListener('keydown', document._pdEscMemberService); document._pdEscMemberService = null; }
}

function getMemberAdminServiceUrl() {
  const root = (typeof PDMembers !== 'undefined' && PDMembers.root) ? String(PDMembers.root).replace(/\/+$/, '') : '/wp-json/profdef/v2';
  const id = (typeof PDMembers !== 'undefined' && PDMembers.id != null) ? PDMembers.id : 0;
  return `${root}/member/administrative_service?members_id=${encodeURIComponent(id)}`;
}

async function fetchMemberAdminService() {
  const url = getMemberAdminServiceUrl();
  const headers = { 'Accept': 'application/json' };
  if (typeof PDMembers !== 'undefined' && PDMembers.nonce) headers['X-WP-Nonce'] = PDMembers.nonce;
  const res = await fetch(url, { method: 'GET', headers, credentials: 'same-origin', cache: 'no-store' });
  if (!res.ok) {
    const txt = await res.text().catch(()=>'');
    throw new Error(`REST ${res.status}: ${txt.slice(0,200)}`);
  }
  const data = await res.json().catch(()=>[]);
  return Array.isArray(data) ? data : [];
}

async function loadMemberAdminService() {
  const overlay = document.getElementById('memberAdminServiceModal');
  if (!overlay) return;
  const table = overlay.querySelector('.attendees-table');
  const tbody = table ? table.querySelector('tbody') : null;
  if (!tbody) return;
  const items = await fetchMemberAdminService();
  adminServiceItems = (Array.isArray(items) ? items : []).map(r => ({
    start: (r.start_service || ''),
    end: (r.end_service || ''),
    type: (r.type || ''),
    ceu: (r.ceu_weight || '')
  }));
  renderMemberAdminService();
}

function getAdminServiceTypeOptions() {
  if (Array.isArray(adminServiceTypeOptions) && adminServiceTypeOptions.length) return adminServiceTypeOptions.slice();
  if (typeof PDMembers !== 'undefined' && Array.isArray(PDMembers.adminServiceTypes) && PDMembers.adminServiceTypes.length) {
    adminServiceTypeOptions = PDMembers.adminServiceTypes.slice();
    return adminServiceTypeOptions.slice();
  }
  adminServiceTypeOptions = ['Board', 'Committee', 'Officer', 'Volunteer', 'Other'];
  return adminServiceTypeOptions.slice();
}

function fillAdminServiceTypeSelect() {
  const sel = document.getElementById('mas-add-type');
  if (!sel) return;
  const opts = getAdminServiceTypeOptions();
  sel.innerHTML = '';
  opts.forEach(o => {
    const opt = document.createElement('option');
    opt.value = String(o);
    opt.textContent = String(o);
    sel.appendChild(opt);
  });
}

function renderMemberAdminService() {
  const overlay = document.getElementById('memberAdminServiceModal');
  if (!overlay) return;
  const table = overlay.querySelector('.attendees-table');
  const tbody = table ? table.querySelector('tbody') : null;
  if (!tbody) return;
  if (!adminServiceItems.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:#6b7280;">No administrative service entries found.</td></tr>';
    return;
  }
  const esc = (v) => v == null ? '' : String(v);
  tbody.innerHTML = adminServiceItems.map((row, idx) => {
    const start = esc(row.start).split('T')[0] || esc(row.start);
    const end   = esc(row.end).split('T')[0] || esc(row.end);
    const type  = esc(row.type);
    const ceu   = esc(row.ceu);
    const opts = (getAdminServiceTypeOptions() || []).map(o => {
      const sel = (String(o).toLowerCase() === type.toLowerCase()) ? ' selected' : '';
      const safe = String(o).replace(/"/g, '&quot;');
      return `<option value="${safe}"${sel}>${safe}</option>`;
    }).join('');
    return `
      <tr data-index="${idx}">
        <td><input type="date" class="form-input" value="${start}" data-field="start" /></td>
        <td><input type="date" class="form-input" value="${end}" data-field="end" /></td>
        <td><select class="form-select" data-field="type">${opts}</select></td>
        <td><input type="number" step="0.01" min="0" class="form-input" value="${ceu}" data-field="ceu" /></td>
        <td><button type="button" class="delete-btn" onclick="deleteAdminServiceRow(${idx})">✕</button></td>
      </tr>
    `;
  }).join('');
  // Bind change handlers to update the array
  tbody.querySelectorAll('input, select').forEach(input => {
    input.addEventListener('change', (e) => {
      const tr = e.target.closest('tr');
      const i = Number(tr && tr.getAttribute('data-index')) || 0;
      const field = e.target.getAttribute('data-field');
      if (!field || isNaN(i)) return;
      adminServiceItems[i] = { ...adminServiceItems[i], [field]: e.target.value };
    });
  });
}

function addAdminServiceRow() {
  const start = document.getElementById('mas-add-start');
  const end   = document.getElementById('mas-add-end');
  const type  = document.getElementById('mas-add-type');
  const ceu   = document.getElementById('mas-add-ceu');
  const row = {
    start: start ? start.value : '',
    end: end ? end.value : '',
    type: type ? String(type.value||'').trim() : '',
    ceu: ceu ? ceu.value : ''
  };
  // Basic validation: require type and start
  if (!row.type || !row.start) {
    alert('Please enter at least Start and Type.');
    return;
  }
  adminServiceItems.unshift(row);
  if (start) start.value = '';
  if (end) end.value = '';
  if (type) type.value = '';
  if (ceu) ceu.value = '';
  renderMemberAdminService();
}

function deleteAdminServiceRow(index) {
  if (index < 0 || index >= adminServiceItems.length) return;
  adminServiceItems.splice(index, 1);
  renderMemberAdminService();
}

function sortAdminService(key) {
  const map = { start: 'start', end: 'end', type: 'type', ceu: 'ceu' };
  const k = map[key] || 'start';
  if (adminServiceSortKey === k) {
    adminServiceSortAsc = !adminServiceSortAsc;
  } else {
    adminServiceSortKey = k;
    adminServiceSortAsc = true;
  }
  const dir = adminServiceSortAsc ? 1 : -1;
  adminServiceItems.sort((a, b) => {
    if (k === 'ceu') {
      const av = Number(a.ceu||0), bv = Number(b.ceu||0);
      if (av < bv) return -1*dir; if (av > bv) return 1*dir; return 0;
    }
    const va = String(a[k]||'');
    const vb = String(b[k]||'');
    return va.localeCompare(vb) * dir;
  });
  // Update arrows
  const keys = ['start','end','type','ceu'];
  keys.forEach(key => {
    const el = document.getElementById('as-arrow-' + key);
    if (!el) return;
    if (adminServiceSortKey === key) {
      el.textContent = adminServiceSortAsc ? '▲' : '▼';
      el.style.color = '#e11d48';
      el.style.marginLeft = '0.25rem';
    } else {
      el.textContent = '';
    }
  });
  renderMemberAdminService();
}

async function saveMemberAdminService() {
  try {
    const url = getMemberAdminServiceUrl();
    const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
    if (typeof PDMembers !== 'undefined' && PDMembers.nonce) headers['X-WP-Nonce'] = PDMembers.nonce;
    const payload = {
      members_id: (typeof PDMembers !== 'undefined' && PDMembers.id != null) ? PDMembers.id : 0,
      items: adminServiceItems.map(r => ({
        start_service: r.start || null,
        end_service: r.end || null,
        type: r.type || '',
        ceu_weight: r.ceu || ''
      }))
    };
    const res = await fetch(url, { method: 'PUT', headers, body: JSON.stringify(payload), credentials: 'same-origin' });
    if (!res.ok) {
      const txt = await res.text().catch(()=> '');
      throw new Error(`Save failed (${res.status}): ${txt.slice(0,200)}`);
    }
    alert('Administrative service saved.');
    closeMemberAdminServiceModal();
  } catch (e) {
    console.error(e);
    alert(e && e.message ? e.message : 'Failed to save administrative service.');
  }
}
