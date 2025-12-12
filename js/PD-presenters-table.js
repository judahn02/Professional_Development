let presenterSortKey = 'name';
let presenterSortAsc = true;
let presentersCurrentPage = 1;
let presentersPerPage = 25;
let presentersTotalCount = null; // from /presenters/ct
const presenterSessionsCache = new Map(); // id -> { items, at }
let attendeeSearchState = { timer: null, selectedId: null, results: [] };
let presenterLinkWpState = { personId: null, currentWpId: null, selectedWpId: null };

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

function getPresentersRestRoot() {
  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const root = String(cfg.listRoot || cfg.root || '/wp-json/profdef/v2/').replace(/\/+$/, '');
  return root;
}

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

    // 3) Map to your presenters shape (include wp_id so ARMember links work)
    const mapped = rows.map((r, i) => {
      const obj = {
        id: r.id ?? null,
        name: r.name ?? '',
        email: r.email ?? '',
        phone_number: r.phone_number ?? '',
        session_count: Number(r.session_count ?? 0) || 0,
        wp_id: r.wp_id != null ? (Number(r.wp_id) || 0) : null,
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

async function fetchPresentersTotalCount({ debug = true } = {}) {
  const log = (...args) => debug && console.log('[presentersCount]', ...args);
  const warn = (...args) => debug && console.warn('[presentersCount]', ...args);

  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || null;
  if (!cfg) return null;

  const base = String(cfg.countRoot || cfg.listRoot || cfg.root || '/wp-json/profdef/v2/').replace(/\/+$/, '');
  const route = String(cfg.countRoute || 'presenters/ct').replace(/^\/+/, '');
  const url = base + '/' + route;

  try {
    const res = await fetch(url, {
      headers: { 'X-WP-Nonce': cfg.nonce || '' },
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const raw = await res.text();
    let data; try { data = JSON.parse(raw); } catch { data = null; }
    if (!res.ok) {
      warn('Non-OK count response', res.status, data || raw);
      return null;
    }
    const ct = data && typeof data.count === 'number' ? data.count : parseInt(data && data.count, 10);
    if (!Number.isFinite(ct)) return null;
    log('count:', ct);
    return ct;
  } catch (e) {
    warn('Count fetch failed', e);
    return null;
  }
}

// No splitName needed; view provides full name

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    fetchPresentersTotalCount({ debug: false })
      .then((ct) => { presentersTotalCount = ct; })
      .catch(() => {});

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
    const personId = presenter.id != null ? Number(presenter.id) : 0;
    const wpId = presenter.wp_id != null ? Number(presenter.wp_id) : 0;
    const hasPersonId = Number.isFinite(personId) && personId > 0;
    const hasWpId = Number.isFinite(wpId) && wpId > 0;

    const linkButton = hasPersonId
      ? `<button type="button"
                 class="link-wp-btn${hasWpId ? ' linked' : ''}"
                 data-presenter-id="${personId}"
                 data-wp-id="${hasWpId ? wpId : ''}"
                 onclick="openPresenterLinkWpModal(event, ${personId})">
           ${hasWpId ? `WP #${wpId}` : 'Link account'}
         </button>`
      : 'not linked';

    return `
    <tr class="presenter-row">
      <td style=\"font-weight: 600;\">${presenter.name || ''}</td>
      <td>${presenter.email || ''}</td>
      <td>${presenter.phone_number || ''}</td>
      <td>${presenter.session_count ?? 0}</td>
      <td>
        ${linkButton}
      </td>
      <td>
        <span class=\"details-dropdown\" data-index=\"${i}\" role=\"button\" tabindex=\"0\" onclick=\"togglePresenterDetails(${presenter.id}, ${i})\">
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

// Attendee → Presenter helper modal
async function searchAttendeesForPresenters(term) {
  const cleaned = String(term || '')
    .replace(/[^a-zA-Z\s]+/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  if (!cleaned) return [];

  const root = getPresentersRestRoot();
  const q = encodeURIComponent(cleaned);
  // For the presenters table helper, restrict search to presenter records (presenter = 1).
  const url = `${root}/sessionhome10?search_p=${q}&limit=20&presenter=1`;

  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const headers = { 'Accept': 'application/json' };
  if (cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.nonce;
  }

  const res = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers
  });
  if (!res.ok) return [];
  const data = await res.json().catch(() => []);
  if (!Array.isArray(data)) return [];
  // sessionhome10 returns [[name, members_id, email], ...]
  return data.map(row => ({
    id: Number(row[1]) || 0,
    name: String(row[0] || ''),
    email: String(row[2] || '')
  })).filter(r => r.id > 0 && r.name);
}

function renderAttendeeSearchResults(items) {
  const container = document.getElementById('attendeeSearchResults');
  if (!container) return;
  attendeeSearchState.results = Array.isArray(items) ? items.slice() : [];
  attendeeSearchState.selectedId = null;

  if (!items || !items.length) {
    container.innerHTML = '<div style="color:#6b7280;">No attendee found matching that name who is not already a presenter.</div>';
    return;
  }

  const list = document.createElement('ul');
  list.className = 'presenter-results-list';

  items.forEach((item, idx) => {
    const li = document.createElement('li');
    const label = item.email ? `${item.name} — ${item.email}` : item.name;
    li.textContent = label;
    li.dataset.id = String(item.id);
    li.className = 'presenter-result-item';
    if (idx === 0) {
      li.classList.add('selected');
      attendeeSearchState.selectedId = item.id;
    }
    li.addEventListener('click', () => {
      const all = list.querySelectorAll('li.presenter-result-item');
      all.forEach(el => el.classList.remove('selected'));
      li.classList.add('selected');
      attendeeSearchState.selectedId = item.id;
    });
    list.appendChild(li);
  });

  container.innerHTML = '';
  container.appendChild(list);
}

function bindAttendeeSearchInput() {
  const input = document.getElementById('attendeeSearchInput');
  if (!input || input._pdBound) return;
  input._pdBound = true;

  const container = document.getElementById('attendeeSearchResults');
  if (container) {
    container.innerHTML = '<div style="color:#6b7280;">Start typing an attendee name...</div>';
  }

  input.addEventListener('input', () => {
    const term = input.value || '';
    if (attendeeSearchState.timer) {
      clearTimeout(attendeeSearchState.timer);
    }
    attendeeSearchState.timer = setTimeout(async () => {
      const cleaned = term.replace(/[^a-zA-Z\s]+/g, '').replace(/\s+/g, ' ').trim();
      if (!cleaned || cleaned.length < 2) {
        if (container) {
          container.innerHTML = '<div style="color:#6b7280;">Type at least 2 letters.</div>';
        }
        attendeeSearchState.results = [];
        attendeeSearchState.selectedId = null;
        return;
      }
      try {
        const items = await searchAttendeesForPresenters(cleaned);
        renderAttendeeSearchResults(items);
      } catch (err) {
        console.error('Attendee search failed', err);
        if (container) {
          container.innerHTML = '<div style="color:#b91c1c;">Error searching attendees.</div>';
        }
      }
    }, 300);
  });
}

function openAttendeeCheckModal() {
  const overlay = document.getElementById('attendeeCheckModal');
  if (!overlay) return;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');

  attendeeSearchState.selectedId = null;
  attendeeSearchState.results = [];

  bindAttendeeSearchInput();

  const input = document.getElementById('attendeeSearchInput');
  const container = document.getElementById('attendeeSearchResults');
  if (input) {
    input.value = '';
    setTimeout(() => { try { input.focus(); } catch (_) {} }, 10);
  }
  if (container) {
    container.innerHTML = '<div style="color:#6b7280;">Start typing an attendee name...</div>';
  }

  const onOverlay = (e) => { if (e.target === overlay) closeAttendeeCheckModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);

  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closeAttendeeCheckModal(); };
  document._pdEscAttendeeCheck && document.removeEventListener('keydown', document._pdEscAttendeeCheck);
  document._pdEscAttendeeCheck = onEsc;
  document.addEventListener('keydown', onEsc);
}

function closeAttendeeCheckModal() {
  const overlay = document.getElementById('attendeeCheckModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) {
    overlay.removeEventListener('click', overlay._pdOverlayHandler);
    overlay._pdOverlayHandler = null;
  }
  if (document._pdEscAttendeeCheck) {
    document.removeEventListener('keydown', document._pdEscAttendeeCheck);
    document._pdEscAttendeeCheck = null;
  }
}

async function markAttendeeAsPresenter() {
  const results = attendeeSearchState.results || [];
  if (!attendeeSearchState.selectedId && results.length === 1) {
    attendeeSearchState.selectedId = results[0].id;
  }
  const personId = attendeeSearchState.selectedId;
  if (!personId || !Number.isFinite(Number(personId))) {
    alert('Please select an attendee from the list first.');
    return;
  }

  const root = getPresentersRestRoot();
  const url = `${root}/member/mark_presenter`;

  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  };
  if (cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.nonce;
  }

  try {
    const res = await fetch(url, {
      method: 'PUT',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify({ person_id: Number(personId) })
    });
    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`Failed to mark as presenter (${res.status}): ${txt.slice(0,200)}`);
    }
    closeAttendeeCheckModal();
    try {
      await fillPresenters({ debug: false });
      renderPresenters();
    } catch (e) {
      console.error('Failed to refresh presenters after marking presenter', e);
    }
    alert('Attendee marked as presenter. They will now appear in the presenters table.');
  } catch (err) {
    console.error(err);
    alert(err && err.message ? err.message : 'Failed to mark attendee as presenter.');
  }
}

// Expose for inline handlers
window.openAttendeeCheckModal = openAttendeeCheckModal;
window.closeAttendeeCheckModal = closeAttendeeCheckModal;
window.markAttendeeAsPresenter = markAttendeeAsPresenter;

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

function togglePresenterDetails(id, index) {
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

  // Use the provided presenter id directly; avoids sort/filter index desync
  if (id == null || id === '' || (typeof id === 'number' && !isFinite(id))) {
    ul.innerHTML = '<li>Unable to determine presenter id.</li>';
    return;
  }
  // Ensure primitive number where possible
  if (typeof id === 'string' && /^\d+$/.test(id)) id = parseInt(id, 10);

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
    const labelTotal = (() => {
      const hasFilter = Array.isArray(window.filteredPresenters) && window.filteredPresenters.length > 0;
      if (!hasFilter && Number.isFinite(presentersTotalCount) && presentersTotalCount >= 0) return presentersTotalCount;
      return total || 0;
    })();
    const totalPages = Math.max(1, Math.ceil((labelTotal || 0) / presentersPerPage));
    const label = document.createElement('span'); label.textContent = `Page ${presentersCurrentPage} of ${totalPages}`; label.style.minWidth = '10ch'; label.style.textAlign = 'center'; label.style.color = '#6b7280'; label.style.fontWeight = '600';
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
      // New REST error shape (WP_Error): { code, message, data: { status } }
      if (data && data.code === 'email_already_used') {
        throw new Error(data.message || 'The email is already used.');
      }
      // Backward-compat: older endpoint used 409 for duplicates
      if (res.status === 409) throw new Error('The email is already used.');
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

// ARMember link modal helpers (Presenters)
async function presenterSearchWpUsers(term) {
  const cleaned = String(term || '').trim();
  if (!cleaned) return [];

  const root = '/wp-json/wp/v2';
  const q = encodeURIComponent(cleaned);
  const url = `${root}/users?search=${q}&per_page=20&_fields=id,name,slug`;

  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const headers = { 'Accept': 'application/json' };
  if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;

  const res = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers,
  });
  if (!res.ok) return [];
  const data = await res.json().catch(() => []);
  if (!Array.isArray(data)) return [];
  return data.map(u => ({
    id: Number(u.id) || 0,
    name: String(u.name || u.slug || ''),
  })).filter(u => u.id > 0 && u.name);
}

function renderPresenterWpUserResults(items) {
  const container = document.getElementById('presenterLinkWpSearchResults');
  if (!container) return;
  presenterLinkWpState.selectedWpId = null;

  if (!items || !items.length) {
    container.innerHTML = '<div style="color:#6b7280;">No ARMember accounts found. Create the account first, then link.</div>';
    return;
  }

  const list = document.createElement('ul');
  list.className = 'wp-user-results-list';

  items.forEach((item, idx) => {
    const li = document.createElement('li');
    li.textContent = `${item.name} (ID #${item.id})`;
    li.dataset.id = String(item.id);
    li.className = 'wp-user-result-item';
    if (idx === 0) {
      li.classList.add('selected');
      presenterLinkWpState.selectedWpId = item.id;
    }
    li.addEventListener('click', () => {
      const all = list.querySelectorAll('li.wp-user-result-item');
      all.forEach(el => el.classList.remove('selected'));
      li.classList.add('selected');
      presenterLinkWpState.selectedWpId = item.id;
    });
    list.appendChild(li);
  });

  container.innerHTML = '';
  container.appendChild(list);
}

function bindPresenterLinkWpSearchInput() {
  const input = document.getElementById('presenterLinkWpSearchInput');
  if (!input || input._pdBound) return;
  input._pdBound = true;

  const container = document.getElementById('presenterLinkWpSearchResults');
  if (container) {
    container.innerHTML = '<div style="color:#6b7280;">Start typing an ARMember account name or email...</div>';
  }

  let timer = null;
  input.addEventListener('input', () => {
    const term = input.value || '';
    if (timer) clearTimeout(timer);
    timer = setTimeout(async () => {
      const cleaned = term.trim();
      if (!cleaned || cleaned.length < 2) {
        if (container) {
          container.innerHTML = '<div style="color:#6b7280;">Type at least 2 characters.</div>';
        }
        presenterLinkWpState.selectedWpId = null;
        return;
      }
      try {
        const users = await presenterSearchWpUsers(cleaned);
        renderPresenterWpUserResults(users);
      } catch (err) {
        console.error('Presenter ARMember account search failed', err);
        if (container) {
          container.innerHTML = '<div style="color:#b91c1c;">Error searching ARMember accounts.</div>';
        }
      }
    }, 300);
  });
}

function openPresenterLinkWpModal(event, personId) {
  if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
  const overlay = document.getElementById('presenterLinkWpModal');
  if (!overlay) return;

  const pid = Number(personId || 0);
  if (!Number.isFinite(pid) || pid <= 0) return;

  const presenter = (window.presenters || []).find(p => Number(p.id || 0) === pid);
  const summaryEl = document.getElementById('presenterLinkWpSummary');
  const currentEl = document.getElementById('presenterLinkWpCurrent');
  const searchInput = document.getElementById('presenterLinkWpSearchInput');
  const resultsEl = document.getElementById('presenterLinkWpSearchResults');
  const unlinkBtn = document.getElementById('presenterLinkWpUnlinkBtn');

  presenterLinkWpState.personId = pid;
  presenterLinkWpState.currentWpId = presenter && Number(presenter.wp_id || 0) > 0 ? Number(presenter.wp_id) : null;
  presenterLinkWpState.selectedWpId = null;

  if (summaryEl) {
    const name = presenter && presenter.name ? presenter.name : '';
    const email = presenter && presenter.email ? ` <${presenter.email}>` : '';
    summaryEl.textContent = name || presenter?.email || `Presenter #${pid}`;
    if (email) summaryEl.textContent += email;
  }

  if (currentEl) {
    if (presenterLinkWpState.currentWpId) {
      currentEl.textContent = `Currently linked to ARMember account #${presenterLinkWpState.currentWpId}.`;
    } else {
      currentEl.textContent = 'This presenter is not linked to any ARMember account.';
    }
  }

  if (unlinkBtn) {
    unlinkBtn.style.display = presenterLinkWpState.currentWpId ? '' : 'none';
  }

  if (searchInput) {
    searchInput.value = '';
  }
  if (resultsEl) {
    resultsEl.innerHTML = '<div style="color:#6b7280;">Start typing an ARMember account name or email...</div>';
  }

  bindPresenterLinkWpSearchInput();

  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');

  const onOverlay = (e) => { if (e.target === overlay) closePresenterLinkWpModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);

  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closePresenterLinkWpModal(); };
  document._pdEscPresenterLinkWp && document.removeEventListener('keydown', document._pdEscPresenterLinkWp);
  document._pdEscPresenterLinkWp = onEsc;
  document.addEventListener('keydown', onEsc);

  if (searchInput) {
    setTimeout(() => { try { searchInput.focus(); } catch (_) {} }, 10);
  }
}

function closePresenterLinkWpModal() {
  const overlay = document.getElementById('presenterLinkWpModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) {
    overlay.removeEventListener('click', overlay._pdOverlayHandler);
    overlay._pdOverlayHandler = null;
  }
  if (document._pdEscPresenterLinkWp) {
    document.removeEventListener('keydown', document._pdEscPresenterLinkWp);
    document._pdEscPresenterLinkWp = null;
  }
  presenterLinkWpState.personId = null;
  presenterLinkWpState.currentWpId = null;
  presenterLinkWpState.selectedWpId = null;
}

async function submitPresenterLinkWp() {
  const personId = Number(presenterLinkWpState.personId || 0);
  const wpId = Number(presenterLinkWpState.selectedWpId || 0);
  if (!personId || !Number.isFinite(personId)) {
    alert('Missing presenter.');
    return;
  }
  if (!wpId || !Number.isFinite(wpId)) {
    alert('Please select an ARMember account to link.');
    return;
  }

  const rootApi = getPresentersRestRoot();
  const url = `${rootApi}/member/link_wp`;

  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    ...(cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {}),
  };

  try {
    const res = await fetch(url, {
      method: 'PUT',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify({ person_id: personId, wp_id: wpId })
    });
    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`Failed to link account (${res.status}): ${txt.slice(0,200)}`);
    }
    closePresenterLinkWpModal();
    try {
      await fillPresenters({ debug: false });
      renderPresenters();
    } catch (e) {
      console.error('Failed to refresh presenters after linking ARMember account', e);
    }
    alert(`Linked presenter to ARMember account #${wpId}.`);
  } catch (err) {
    console.error(err);
    alert(err && err.message ? err.message : 'Failed to link ARMember account.');
  }
}

async function submitPresenterLinkWpUnlink() {
  const personId = Number(presenterLinkWpState.personId || 0);
  if (!personId || !Number.isFinite(personId)) {
    alert('Missing presenter.');
    return;
  }

  const rootApi = getPresentersRestRoot();
  const url = `${rootApi}/member/link_wp`;

  const cfg = (typeof PDPresenters !== 'undefined' && PDPresenters) || {};
  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    ...(cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {}),
  };

  try {
    const res = await fetch(url, {
      method: 'PUT',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify({ person_id: personId, wp_id: null })
    });
    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`Failed to unlink account (${res.status}): ${txt.slice(0,200)}`);
    }
    closePresenterLinkWpModal();
    try {
      await fillPresenters({ debug: false });
      renderPresenters();
    } catch (e) {
      console.error('Failed to refresh presenters after unlinking ARMember account', e);
    }
    alert('ARMember account unlinked from presenter.');
  } catch (err) {
    console.error(err);
    alert(err && err.message ? err.message : 'Failed to unlink ARMember account.');
  }
}

window.openPresenterLinkWpModal = openPresenterLinkWpModal;
window.closePresenterLinkWpModal = closePresenterLinkWpModal;
window.submitPresenterLinkWp = submitPresenterLinkWp;
window.submitPresenterLinkWpUnlink = submitPresenterLinkWpUnlink;


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
