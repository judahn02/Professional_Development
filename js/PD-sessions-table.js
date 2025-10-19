
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

  for (const r of rows) {
    const tr = document.createElement('tr');

    tr.appendChild(makeCell(toDateOnly(r['Date'])));
    tr.appendChild(makeCell(r['Title']));
    tr.appendChild(makeCell(r['Length']));           // minutes or hours, per your SP
    tr.appendChild(makeCell(r['Session Type']));
    tr.appendChild(makeCell(r['CEU Weight']));
    tr.appendChild(makeCell(r['CEU Const']));        // "CEU Considerations" column header
    tr.appendChild(makeCell(r['CEU Capable']));      // "True"/"False"
    tr.appendChild(makeCell(r['Event Type']));
    tr.appendChild(makeCell(r['Parent Event']));
    tr.appendChild(makeCell(r['presenters']));

    // Actions column (customize as needed)
    const actions = document.createElement('td');
    // Example: view button using returned id (if present)
    if (r.id != null) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = 'View';
      btn.addEventListener('click', () => {
        // TODO: implement your action (modal, navigate, etc.)
        console.log('View session id:', r.id);
      });
      actions.appendChild(btn);
    }
    tr.appendChild(actions);

    tbody.appendChild(tr);
  }
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
