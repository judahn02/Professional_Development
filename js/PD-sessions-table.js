
/** Find the WP REST API root robustly.
 *  WordPress outputs <link rel="https://api.w.org" href=".../wp-json/">
 *  We use that if available; otherwise fall back to current site.
 */
function getWpApiRoot() {
  const link = document.querySelector('link[rel="https://api.w.org"]');
  if (link && link.href) return link.href; // e.g., https://site.com/subdir/wp-json/
  // Fallback: guess from current location (handles most setups)
  try {
    // Ensure single trailing slash
    const u = new URL('./wp-json/', window.location.href);
    return u.href;
  } catch {
    return window.location.origin.replace(/\/$/, '') + '/wp-json/';
  }
}

/** Build full endpoint to your plugin route */
function getSessionsUrl() {
  const root = (window.PDSessions?.restRoot || '').replace(/\/+$/, ''); // trim trailing slash
  const route = (window.PDSessions?.sessionsRoute || '').replace(/^\/+/, ''); // trim leading slash
  return `${root}/${route}`;
}

/** Fetch rows from the REST API */
async function fetchSessions() {
  const url = getSessionsUrl();
  console.log('Fetching sessions from:', url); // helpful while testing

  const res = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      // Only needed if your route requires auth (permission_callback !== __return_true)
      // 'X-WP-Nonce': window.PDSessions?.nonce,
    }
  });

  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`REST error ${res.status}: ${body.slice(0, 300)}`);
  }
  return res.json();
}

/** Safe cell helper (avoids HTML injection) */
function makeCell(text) {
  const td = document.createElement('td');
  td.textContent = (text ?? '').toString();
  return td;
}

/** Optionally format ISO-like dates (YYYY-MM-DDTHH:mm:ss) to YYYY-MM-DD */
function toDateOnly(iso) {
  if (!iso) return '';
  const d = iso.split('T')[0];
  return d || iso;
}

/** Convert minutes to hours string like "1.12h" */
function minutesToHoursLabel(minutes) {
  const m = Number(minutes);
  if (!Number.isFinite(m)) return '';
  const h = m / 60;
  return `${h.toFixed(2)}h`;
}

/** Extract attendee names from various possible formats */
function getAttendeeNames(row) {
  // Common keys that might contain attendee info
  const candidates = [
    row.members,
    row.attendees,
    row.Attendees,
    row['Attendees'],
    row['members'],
    row['attendee_names'],
  ];

  // Attempt to parse possible JSON strings
  const jsonCandidates = [row.attendees_json, row.members_json];
  for (const js of jsonCandidates) {
    if (typeof js === 'string' && js.trim()) {
      try {
        const parsed = JSON.parse(js);
        if (Array.isArray(parsed)) {
          return parsed.map(formatAttendeeItem).filter(Boolean);
        }
      } catch {}
    }
  }

  // First array-like candidate wins
  for (const c of candidates) {
    if (Array.isArray(c)) {
      return c.map(formatAttendeeItem).filter(Boolean);
    }
    if (typeof c === 'string' && c.trim()) {
      // Comma or semicolon separated fallback
      return c.split(/[;,]/).map(s => s.trim()).filter(Boolean);
    }
  }

  return [];
}

function formatAttendeeItem(item) {
  if (item == null) return '';
  if (typeof item === 'string') return item.trim();
  if (typeof item === 'object') {
    // Try common name fields; fallback to JSON
    const fn = item.first_name || item.firstname || '';
    const ln = item.last_name || item.lastname || '';
    const full = item.full_name || item.name || `${fn} ${ln}`.trim();
    if (full && full.trim()) return full.trim();
    if (item.email && typeof item.email === 'string') return item.email.trim();
    try { return JSON.stringify(item); } catch { return ''; }
  }
  return String(item);
}

/** Toggle the attendee details row for a given index */
function toggleAttendeeDropdown(event, index) {
  if (event && typeof event.preventDefault === 'function') event.preventDefault();
  const row = document.getElementById(`attendee-row-${index}`);
  if (!row) return;
  const isHidden = row.style.display === 'none' || getComputedStyle(row).display === 'none';
  row.style.display = isHidden ? 'table-row' : 'none';
}

// Expose for inline handlers if needed
window.toggleAttendeeDropdown = toggleAttendeeDropdown;

/** Render table body */
function renderSessionsTable(rows) {
  const tbody = document.getElementById('sessionsTableBody');
  if (!tbody) return;
  tbody.innerHTML = ''; // clear

  if (!Array.isArray(rows) || rows.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 11;
    td.textContent = 'No sessions found.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  rows.forEach((r, index) => {
    const tr = document.createElement('tr');

    tr.appendChild(makeCell(toDateOnly(r['Date'])));
    tr.appendChild(makeCell(r['Title']));
    tr.appendChild(makeCell(minutesToHoursLabel(r['Length'])));           // display as hours with 2 decimals
    tr.appendChild(makeCell(r['Session Type']));
    tr.appendChild(makeCell(r['CEU Weight']));
    tr.appendChild(makeCell(r['CEU Const']));        // "CEU Considerations" column header
    tr.appendChild(makeCell(r['CEU Capable']));      // "True"/"False"
    tr.appendChild(makeCell(r['Event Type']));
    tr.appendChild(makeCell(r['Parent Event']));
    tr.appendChild(makeCell(r['presenters']));

    // Actions column (customize as needed)
    const actions = document.createElement('td');
    // Details dropdown trigger
    const details = document.createElement('span');
    details.className = 'details-dropdown';
    details.dataset.index = String(index);
    details.style.cursor = 'pointer';
    details.addEventListener('click', (ev) => toggleAttendeeDropdown(ev, index));
    // Icon + label
    details.innerHTML = `
      <svg class="dropdown-icon" width="18" height="18" fill="none" stroke="#e11d48" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle; margin-right:4px;"><path d="M6 9l6 6 6-6"/></svg>
      Details
    `;
    actions.appendChild(details);

    tr.appendChild(actions);

    tbody.appendChild(tr);
    
    // Attendee details row (hidden by default)
    const detailsTr = document.createElement('tr');
    detailsTr.className = 'attendee-row';
    detailsTr.id = `attendee-row-${index}`;
    detailsTr.style.display = 'none';

    const detailsTd = document.createElement('td');
    detailsTd.colSpan = 11; // span the full table width
    detailsTd.style.background = '#fef2f2';
    detailsTd.style.padding = '0';
    detailsTd.style.borderTop = '1px solid #fecaca';

    const block = document.createElement('div');
    block.className = 'attendee-list-block';
    const ul = document.createElement('ul');

    const names = getAttendeeNames(r);
    if (names.length === 0) {
      const li = document.createElement('li');
      li.textContent = 'No attendees found.';
      ul.appendChild(li);
    } else {
      for (const name of names) {
        const li = document.createElement('li');
        li.textContent = name;
        ul.appendChild(li);
      }
    }

    block.appendChild(ul);
    detailsTd.appendChild(block);
    detailsTr.appendChild(detailsTd);
    tbody.appendChild(detailsTr);
  });
}

/** Kickoff on DOM ready */
(async function initSessionsTable() {
  try {
    const rows = await fetchSessions();
    renderSessionsTable(rows);
  } catch (err) {
    console.error(err);
    // Show a friendly error row
    const tbody = document.getElementById('sessionsTableBody');
    if (tbody) {
      tbody.innerHTML = '';
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 11;
      td.textContent = 'Error loading sessions.';
      tr.appendChild(td);
      tbody.appendChild(tr);
    }
  }
})();
