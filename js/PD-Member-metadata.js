
// =========================
// Variables
// =========================

// Session data (fallback until REST loads)
let sessions = [
  { date: "3/14/2025", title: "ASL Conference 1", type: "Workshops", hours: 8 },
  { date: "2/15/2025", title: "Advanced Fingerspelling", type: "Training", hours: 4 },
  { date: "1/20/2025", title: "Deaf Culture Seminar", type: "Conference", hours: 6 },
  { date: "12/10/2024", title: "Teaching Methods Workshop", type: "Workshops", hours: 5 }
];

let currentFilter = "all";
let currentSearch = "";

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
