let presenterSortKey = 'name';
let presenterSortAsc = true;
let presentersCurrentPage = 1;
let presentersPerPage = 25;
const presenterSessionsCache = new Map(); // id -> { items, at }

function sortPresenters(key) {
    if (presenterSortKey === key) {
        presenterSortAsc = !presenterSortAsc;
    } else {
        presenterSortKey = key;
        presenterSortAsc = true;
    }
    // Sorting is applied at render time over the filtered list
    updatePresenterSortArrows();
    renderPresenters();
}

function updatePresenterSortArrows() {
    const keys = ['name','email','phone_number','session_count'];
    keys.forEach(k => {
        const el = document.getElementById('sort-arrow-' + k);
        if (el) {
            if (presenterSortKey === k) {
                el.textContent = presenterSortAsc ? '▲' : '▼';
                el.style.color = '#e11d48';
                el.style.fontSize = '1em';
                el.style.marginLeft = '0.2em';
            } else {
                el.textContent = '';
            }
        }
    });
}

// Show initial sort arrows on load
document.addEventListener('DOMContentLoaded', updatePresenterSortArrows);

// Array populated by fetch
const presenters = [];

window.presenters = presenters;

async function fillPresenters({ debug = true } = {}) {
  const log = (...args) => debug && console.log('[fillPresenters]', ...args);
  const warn = (...args) => debug && console.warn('[fillPresenters]', ...args);
  const errLog = (...args) => console.error('[fillPresenters]', ...args);

  try {
    // 1) Find REST config localized from PHP
  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || null;

    if (!cfg) {
      errLog('No REST config found. Expected `pdRest` or `PDPresenters` from wp_localize_script.');
      throw new Error('Missing REST config object');
    }

    if (!cfg.root) warn('Config has no `root` property. Value:', cfg);
    if (!cfg.nonce) warn('Config has no `nonce`. You may hit 401/403 if the route requires auth.');

    // Prefer v2 list endpoint when available
    const listBase = String(cfg.listRoot || '').replace(/\/+$/, '');
    const listRoute = (cfg.listRoute || 'presenters_table').replace(/^\/+/, '');
    const url = listBase + '/' + listRoute;

    log('Using URL:', url);
    log('Nonce present?', !!cfg.nonce);

    // 2) Fetch
    const res = await fetch(url, {
      headers: { 'X-WP-Nonce': cfg.nonce || '' },
      credentials: 'same-origin', // include cookies for auth
    });

    log('HTTP status:', res.status, res.statusText);

    // If WP errored, try to read the body either as JSON or text for diagnostics
    const raw = await res.text();
    log('Raw response (first 300 chars):', raw.slice(0, 300));

    let rows;
    try {
      rows = JSON.parse(raw);
    } catch (e) {
      errLog('JSON parse failed. Body not valid JSON. Status:', res.status);
      throw new Error('Failed to parse JSON from REST response');
    }

    if (!Array.isArray(rows)) {
      errLog('Response JSON is not an array:', rows);
      throw new Error('REST response is not an array');
    }

    log('Parsed rows count:', rows.length);
    if (rows.length) {
      log('Row[0] keys:', Object.keys(rows[0]));
    }

    // 3) Map to your presenters shape
    const mapped = rows.map((r, i) => {
      const obj = {
        id: r.id ?? null,
        name: r.name ?? '',
        email: r.email ?? '',
        phone_number: r.phone_number ?? '',
        session_count: Number(r.session_count ?? 0) || 0,
      };
      if (i < 3) log('Mapped sample', i, obj);
      return obj;
    });

    // 4) Mutate existing const array in place
    if (Array.isArray(window.presenters)) {
      log('Existing presenters length (before):', presenters.length);
      presenters.length = 0;
      presenters.push(...mapped);
      log('Presenters length (after):', presenters.length);
      return presenters;
    } else {
      warn('`presenters` was not defined; creating window.presenters');
      window.presenters = mapped;
      log('Created presenters with length:', window.presenters.length);
      return window.presenters;
    }
  } catch (e) {
    errLog('Error during fill:', e);
    throw e;
  }
}

// No splitName needed; view provides full name

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    fillPresenters().then(() => {
        renderPresenters();
        setupTopScrollbar();
    }).catch(console.error);

   
});

// Render presenters table
function renderPresenters() {
  const tbody = document.getElementById('presentersTableBody');
  let data = Array.isArray(window.filteredPresenters) && window.filteredPresenters.length
    ? window.filteredPresenters.slice()
    : window.presenters.slice();

  // Apply sort
  data.sort((a, b) => {
    if (presenterSortKey === 'session_count') {
      const av = Number(a.session_count||0), bv = Number(b.session_count||0);
      if (av < bv) return presenterSortAsc ? -1 : 1;
      if (av > bv) return presenterSortAsc ? 1 : -1;
      return 0;
    }
    const va = String(a[presenterSortKey] || '').toLowerCase();
    const vb = String(b[presenterSortKey] || '').toLowerCase();
    if (va < vb) return presenterSortAsc ? -1 : 1;
    if (va > vb) return presenterSortAsc ? 1 : -1;
    return 0;
  });

  // Pagination slice
  const total = data.length;
  const start = Math.max(0, (presentersCurrentPage - 1) * presentersPerPage);
  const end = start + presentersPerPage;
  const pageItems = data.slice(start, end);

  if (!data || data.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="9" style="text-align: center; padding: 2rem; color: #6b7280;">
          No presenters found matching your criteria.
        </td>
      </tr>`;
    return;
  }

  tbody.innerHTML = pageItems.map((presenter, idx) => {
    const i = start + idx;
    return `
    <tr class="presenter-row">
      <td style=\"font-weight: 600;\">${presenter.name || ''}</td>
      <td>${presenter.email || ''}</td>
      <td>${presenter.phone_number || ''}</td>
      <td>${presenter.session_count ?? 0}</td>
      <td>
        <span class=\"details-dropdown\" data-index=\"${i}\" role=\"button\" tabindex=\"0\" onclick=\"togglePresenterDetails(${i})\">
          <svg class=\"dropdown-icon\" width=\"18\" height=\"18\" fill=\"none\" stroke=\"#e11d48\" stroke-width=\"2\" viewBox=\"0 0 24 24\" style=\"vertical-align:middle; margin-right:4px;\"><path d=\"M6 9l6 6 6-6\"/></svg>
          Details
        </span>
      </td>
    </tr>
    <tr class=\"presenter-detail-row\" id=\"presenter-detail-row-${i}\" style=\"display:none; background:#fef2f2;\">
      <td colspan=\"5\" style=\"padding:0; border-top:1px solid #fecaca;\">
        <div class=\"attendee-list-block\" style=\"padding: 1.0rem 1.25rem;\">
          <ul id=\"presenter-sessions-${i}\">\n            <li>Click Details to load sessions...</li>\n          </ul>
        </div>
      </td>
    </tr>
    `;
  }).join('');

  renderPresentersPager(total);
  // Keep top scrollbar spacer in sync with table width
  try { setupTopScrollbar(); } catch(_) {}
}


// Go to presenter profile page
function goToPresenterProfile(id) {
    // Store presenters in localStorage for access in presenter-profile.html
    localStorage.setItem('presenters', JSON.stringify(window.presenters));
    // swindow.location.href = `presenter-profile.html?presenter=${id}`;
    window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php?page=profdef_presenter_page&presenter=' + id);
}

// Filter presenters based on search
function filterPresenters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (!searchTerm) {
    window.filteredPresenters = null;
    presentersCurrentPage = 1;
    renderPresenters();
    return;
  }
    
    window.filteredPresenters = window.presenters.filter(presenter => {
        return (
            (presenter.name && presenter.name.toLowerCase().includes(searchTerm)) ||
            (presenter.email && presenter.email.toLowerCase().includes(searchTerm)) ||
            (presenter.phone_number && presenter.phone_number.toLowerCase().includes(searchTerm))

        );
    });
    presentersCurrentPage = 1;
    renderPresenters();
}

function setupTopScrollbar() {
  const top = document.getElementById('presentersTopScroll');
  const spacer = document.getElementById('presentersTopScrollSpacer');
  const container = document.getElementById('presentersTableContainer');
  const table = container ? container.querySelector('.table') : null;
  if (!top || !spacer || !container || !table) return;
  // Sync widths
  spacer.style.width = table.scrollWidth + 'px';
  const onScrollTop = () => { container.scrollLeft = top.scrollLeft; };
  const onScrollContainer = () => { top.scrollLeft = container.scrollLeft; };
  if (!top._pdBound) { top.addEventListener('scroll', onScrollTop); top._pdBound = true; }
  if (!container._pdBound) { container.addEventListener('scroll', onScrollContainer); container._pdBound = true; }
  // Observe table width changes
  if (!table._pdRO) {
    try {
      table._pdRO = new ResizeObserver(() => { spacer.style.width = table.scrollWidth + 'px'; });
      table._pdRO.observe(table);
    } catch(_) {
      // Fallback: update spacer after slight delay
      setTimeout(() => { spacer.style.width = table.scrollWidth + 'px'; }, 100);
    }
  }
}

function getPresenterSessionsUrl(id) {
  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const base = String(cfg.listRoot || '').replace(/\/+$/, '');
  const route = (cfg.presenterSessionsRoute || 'presenter/sessions').replace(/^\/+/, '');
  return `${base}/${route}?presenter_id=${encodeURIComponent(id)}`;
}

async function fetchPresenterSessions(id) {
  const url = getPresenterSessionsUrl(id);
  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const res = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', ...(cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {}) },
    cache: 'no-store',
  });
  if (!res.ok) {
    const txt = await res.text().catch(()=> '');
    throw new Error(`Sessions fetch failed (${res.status}): ${txt.slice(0,200)}`);
  }
  const data = await res.json().catch(()=>[]);
  return Array.isArray(data) ? data : [];
}

function renderPresenterSessionsList(ul, items) {
  ul.innerHTML = '';
  if (!Array.isArray(items) || items.length === 0) {
    const li = document.createElement('li'); li.textContent = 'No sessions found for this presenter.'; ul.appendChild(li); return;
  }
  const truncate = (text, max) => {
    const s = String(text || '');
    const limit = Number(max) || 50;
    if (s.length <= limit) return s;
    // keep whole words when possible
    const cut = s.slice(0, limit);
    const lastSpace = cut.lastIndexOf(' ');
    const base = lastSpace > limit - 12 ? cut.slice(0, lastSpace) : cut;
    return base.replace(/[\s.,-]+$/, '') + '...';
  };
  items.forEach((s) => {
    const li = document.createElement('li');
    const name = document.createElement('span');
    name.className = 'attendee-name';
    const fullTitle = s.session_title || '';
    name.textContent = truncate(fullTitle, 50);
    if (fullTitle) name.title = fullTitle; // hover shows full name
    const meta = document.createElement('span');
    meta.className = 'attendee-email';
    const date = (s.session_date || '').split('T')[0] || (s.session_date || '');
    const pe = s.session_parent_event ? ` — ${s.session_parent_event}` : '';
    meta.textContent = `${date}${pe}`;
    li.appendChild(name);
    li.appendChild(meta);
    ul.appendChild(li);
  });
}

function togglePresenterDetails(index) {
  const tbody = document.getElementById('presentersTableBody');
  if (!tbody) return;
  // Close others (radio behavior)
  tbody.querySelectorAll('tr.presenter-detail-row').forEach(row => { if (row.id !== `presenter-detail-row-${index}`) row.style.display = 'none'; });
  const row = document.getElementById(`presenter-detail-row-${index}`);
  if (!row) return;
  const hidden = row.style.display === 'none' || getComputedStyle(row).display === 'none';
  if (!hidden) { row.style.display = 'none'; return; }

  // Open this row
  row.style.display = 'table-row';
  const ul = document.getElementById(`presenter-sessions-${index}`);
  if (!ul) return;

  // Derive presenter id from current page+index
  const all = Array.isArray(window.filteredPresenters) && window.filteredPresenters.length ? window.filteredPresenters : window.presenters;
  const id = (all[index] && all[index].id != null) ? all[index].id : null;
  if (id == null) { ul.innerHTML = '<li>Unable to determine presenter id.</li>'; return; }

  // If cached and fresh (5 minutes), reuse
  const entry = presenterSessionsCache.get(id);
  const fresh = entry && Array.isArray(entry.items) && (Date.now() - (entry.at||0)) < (5*60*1000);
  if (fresh) { renderPresenterSessionsList(ul, entry.items); return; }

  ul.innerHTML = '<li>Loading sessions...</li>';
  fetchPresenterSessions(id)
    .then(items => { presenterSessionsCache.set(id, { items, at: Date.now() }); renderPresenterSessionsList(ul, items); })
    .catch(err => { console.error(err); ul.innerHTML = '<li>Error loading sessions.</li>'; });
}

// Expose for inline handler
window.togglePresenterDetails = togglePresenterDetails;

function renderPresentersPager(total) {
  const canPrev = presentersCurrentPage > 1;
  const canNext = (presentersCurrentPage * presentersPerPage) < (total || 0);
  const make = (el) => {
    el.innerHTML = '';
    const mkBtn = (id, label, disabled, onClick) => {
      const b = document.createElement('button');
      b.type = 'button'; b.id = id; b.textContent = label; b.className = 'pager-btn';
      b.disabled = !!disabled; b.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      b.style.padding = '6px 10px'; b.style.border = '1px solid #e5e7eb'; b.style.background = '#ffffff'; b.style.cursor = 'pointer'; b.style.borderRadius = '4px';
      b.addEventListener('click', onClick);
      return b;
    };
    const prev = mkBtn('presentersPagerPrev', 'Prev', !canPrev, () => { if (presentersCurrentPage>1){ presentersCurrentPage--; renderPresenters(); }});
    const label = document.createElement('span'); label.textContent = `Page ${presentersCurrentPage}`; label.style.minWidth = '6ch'; label.style.textAlign = 'center'; label.style.color = '#6b7280'; label.style.fontWeight = '600';
    const next = mkBtn('presentersPagerNext', 'Next', !canNext, () => { if (canNext){ presentersCurrentPage++; renderPresenters(); }});
    el.appendChild(prev); el.appendChild(label); el.appendChild(next);
  };
  const top = document.getElementById('presentersPagerTop'); if (top) make(top);
  const bot = document.getElementById('presentersPager'); if (bot) make(bot);
}

// Modal functions
function openAddPresenterModal() {
    document.getElementById('addPresenterModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeAddPresenterModal() {
    document.getElementById('addPresenterModal').classList.remove('active');
    document.body.style.overflow = 'auto';
    document.getElementById('addPresenterForm').reset();
}

document.getElementById('addPresenterForm').addEventListener('submit', async function (e) {
  e.preventDefault();

  const submitBtn = document.querySelector('.btn-save');
  const originalText = submitBtn.textContent;
  submitBtn.innerHTML = '<span class="loading"></span> Saving...';
  submitBtn.disabled = true;

  try {
    // Honeypot check
    const hp = document.getElementById('pd_hp');
    if (hp && hp.value.trim() !== '') throw new Error('Spam detected.');

    // Gather & trim
    const firstname = document.getElementById('presenterFirstName').value.trim();
    const lastname  = document.getElementById('presenterLastName').value.trim();
    const email     = document.getElementById('presenterEmail').value.trim().toLowerCase();
    const phoneRaw  = document.getElementById('presenterPhone').value.trim();

    // Basic client validation (server still validates)
    if (!firstname || !lastname) throw new Error('First and last name are required.');
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        throw new Error('If provided, email must be valid.');
    }
    if ((firstname.length + lastname.length) > 60) throw new Error('Name too long.');
    if (email.length > 254) throw new Error('Email too long.');

    // Normalize phone to allowed chars and clamp length
    let phone = phoneRaw.replace(/[^\d()+.\-\s]/g, '');
    if (phone.length > 20) phone = phone.slice(0, 20);

    // REST config
    const cfg = (typeof pdRest !== 'undefined' && pdRest) ||
                (typeof PDPresenters !== 'undefined' && PDPresenters) ||
                (typeof PDPresenters !== 'undefined' && PDPresenters) || null;
    if (!cfg) throw new Error('Missing REST config (pdRest/PDPresenters).');

    // POST to REST endpoint
    // Prefer v2 create endpoint when available
    const postBase  = String(cfg.postRoot || cfg.listRoot || cfg.root || '').replace(/\/+$/, '');
    const postRoute = (cfg.postRoute || 'presenter').replace(/^\/+/, '');
    const postUrl   = postBase + '/' + postRoute;
    const res = await fetch(postUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      cache: 'no-store',
      body: JSON.stringify({ firstname, lastname, email, phone })
    });

    const raw = await res.text();
    let data; try { data = JSON.parse(raw); } catch { data = { raw }; }

    if (!res.ok) {
      if (res.status === 409) throw new Error('A presenter with this email already exists.');
      const msg = data && (data.message || data.raw) || `HTTP ${res.status}`;
      throw new Error('Could not save presenter. ' + msg);
    }

    // // Refresh canonical list & re-render
    // await fillPresenters();
    // if (typeof renderPresenters === 'function') renderPresenters();
    /* ✅ OPTIMISTIC UPDATE (no page refresh) */
    const newPresenter = {
    firstname,
    lastname,
    email,
    phone,
    sessions: '',           // you add sessions elsewhere
    id: (data && data.id) || null
    };

    // keep your arrays in sync (always mutate the canonical window.presenters)
    if (Array.isArray(window.presenters)) {
    window.presenters.unshift(newPresenter);
    } else {
    window.presenters = [newPresenter];
    }

    // keep filtered view (if you use one)
    if (Array.isArray(window.filteredPresenters)) {
    window.filteredPresenters = [...window.presenters];
    }

    // if you use a grouping step, apply it before rendering
    if (typeof groupPresentersInPlace === 'function') {
    groupPresentersInPlace();
    }

    // re-render immediately
    if (typeof renderPresenters === 'function') {
    renderPresenters();
    }

    /* 🔄 optional: reconcile with server to ensure canonical data */
    fillPresenters({ debug: false })
    .then(() => {
        // re-render again with authoritative data if it changed
        if (typeof renderPresenters === 'function') renderPresenters();
    })
    .catch(console.warn);


    // Optional UX notice
    const modal = document.querySelector('.modal');
    if (modal) {
      const successMsg = document.createElement('div');
      successMsg.className = 'success-message';
      successMsg.textContent = 'Presenter added successfully!';
      modal.insertBefore(successMsg, modal.firstChild);
      setTimeout(() => successMsg.remove(), 2000);
    }

    if (typeof closeAddPresenterModal === 'function') {
      setTimeout(() => closeAddPresenterModal(), 500);
    }
  } catch (err) {
    console.error(err);
    const modal = document.querySelector('.modal');
    if (modal) {
      const errorMsg = document.createElement('div');
      errorMsg.className = 'error-message';
      errorMsg.textContent = err.message || 'Failed to add presenter.';
      modal.insertBefore(errorMsg, modal.firstChild);
      setTimeout(() => errorMsg.remove(), 3000);
    }
  } finally {
    submitBtn.textContent = originalText;
    submitBtn.disabled = false;
  }
});


document.getElementById('addPresenterModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddPresenterModal();
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // ESC to close modal
    if (e.key === 'Escape') {
        closeAddPresenterModal();
    }
    
    // Ctrl+N to add new presenter
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openAddPresenterModal();
    }
});
