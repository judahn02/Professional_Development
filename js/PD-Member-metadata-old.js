// Session data
let sessions = [
    { date: "3/14/2025", title: "ASL Conference 1", type: "Workshops", hours: 8 },
    { date: "2/15/2025", title: "Advanced Fingerspelling", type: "Training", hours: 4 },
    { date: "1/20/2025", title: "Deaf Culture Seminar", type: "Conference", hours: 6 },
    { date: "12/10/2024", title: "Teaching Methods Workshop", type: "Workshops", hours: 5 }
];

const toDateOnly = d => (typeof d === 'string' ? d.split('T')[0] : '');
const minutesToHours = m => Math.round((Number(m || 0) / 60) * 100) / 100; // 2-dec float


let currentFilter = 'all';
let currentSearch = '';

// Initialize the page
async function init() {
    try {
        const apiSessions = await loadMemberSessions(); // fetch from REST
        if (Array.isArray(apiSessions) && apiSessions.length) {
            // Map API fields -> your UI shape
            sessions = apiSessions.map(s => ({
            // original 4 (match your first 4 <th>)
            date:  toDateOnly(s['Date']),
            title: s['Title'] ?? '',
            type:  s['Session Type'] ?? '',
            hours: minutesToHours(s['Length']),

            // appended fields (match appended <th>)
            ceuCapable: (s['CEU Capable'] === true || s['CEU Capable'] === 'True'),
            ceuWeight:  s['CEU Weight'] ?? '',
            parentEvent: s['Parent Event'] ?? '',
            eventType:  s['Event Type'] ?? '',

            // optional (not displayed since your <th> are commented out)
            // sessionId:  s['Session Id'],
            // membersId:  s['Members_id'],
            }));
        }
    } catch (e) {
        console.warn('[PD] Using default sessions due to REST error:', e);
    }
    calculateHours(1);
    renderSessions();
}

async function loadMemberSessions() {
  const url = `${PDMembers.root}${PDMembers.route}?members_id=${encodeURIComponent(PDMembers.id)}`;
  const res = await fetch(url, { headers: { 'X-WP-Nonce': PDMembers.nonce } });
  if (!res.ok) throw new Error(`REST ${res.status}: ${await res.text()}`);
  const data = await res.json();
  return Array.isArray(data.sessions) ? data.sessions : [];
}



// Calculate total hours
function calculateHours(years = 1) {
    const totalHours = sessions.reduce((sum, session) => sum + session.hours, 0);
    const currentDate = new Date();
    const cutoffDate = new Date();
    cutoffDate.setFullYear(currentDate.getFullYear() - years);

    const yearHours = sessions
        .filter(s => new Date(s.date) >= cutoffDate)
        .reduce((sum, session) => sum + session.hours, 0);

    document.getElementById('totalHours').textContent = totalHours;
    document.getElementById('filteredHours').textContent = yearHours;
    document.getElementById('yearHours')?.remove(); // remove static one if still exists
}

// Handle slider
document.addEventListener('DOMContentLoaded', function () {
    init();
    const yearRange = document.getElementById('yearRange');
    const yearLabel = document.getElementById('yearLabel');
    const yearText = document.getElementById('yearText');

    yearRange.addEventListener('input', function () {
        const val = parseInt(this.value);
        yearLabel.textContent = val;
        yearText.textContent = val;
        calculateHours(val);
    });

    // Initialize with default 1 year
    calculateHours(1);
});

// Filter sessions based on search and filter
function getFilteredSessions() {
    return sessions.filter(session => {
        const matchesSearch = session.title.toLowerCase().includes(currentSearch.toLowerCase());
        const matchesFilter = currentFilter === 'all' ||
            session.type.toLowerCase() === currentFilter.toLowerCase();
        return matchesSearch && matchesFilter;
    });
}

// Render sessions table
function renderSessions() {
    const filteredSessions = getFilteredSessions();
    const tbody = document.getElementById('sessionsTable');
    const emptyState = document.getElementById('emptyState');

    if (filteredSessions.length === 0) {
        tbody.innerHTML = '';
        emptyState.style.display = 'block';
        return;
    }

    emptyState.style.display = 'none';
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
    currentSearch = document.getElementById('searchInput').value;
    renderSessions();
}

// Handle filter buttons
function setFilter(filter) {
    currentFilter = filter;

    // Update button styles
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
function exportReport() {
    const data = sessions.map(session =>
        `${session.date}\t${session.title}\t${session.type}\t${session.hours}h`
    ).join('\n');

    const csvContent = 'Date\tSession Title\tType\tHours\n' + data;
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'aslta-training-report.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// Initialize when page loads


const yearValues = [3, 7, 6, 5, 9, 2, 6, 8, 1, 10, 15];

const rangeEl = document.getElementById('yearRange');
const labelEl = document.getElementById('yearLabel');

function updateYearLabel() {
// Slider is 1-based; array is 0-based
const idx = Math.min(Math.max(parseInt(rangeEl.value, 10), 1), yearValues.length) - 1;
labelEl.textContent = String(yearValues[idx]);
}

// Init + live updates
rangeEl.addEventListener('input', updateYearLabel);
updateYearLabel(); // set initial label
document.addEventListener('DOMContentLoaded', init);