let presenterSortKey = null;
let presenterSortAsc = true;

function sortPresenters(key) {
    if (presenterSortKey === key) {
        presenterSortAsc = !presenterSortAsc;
    } else {
        presenterSortKey = key;
        presenterSortAsc = true;
    }
    window.presenters.sort((a, b) => {
        let valA = (a[key] || '').toLowerCase();
        let valB = (b[key] || '').toLowerCase();
        if (valA < valB) return presenterSortAsc ? -1 : 1;
        if (valA > valB) return presenterSortAsc ? 1 : -1;
        return 0;
    });
    updatePresenterSortArrows();
    renderPresenters();
}

function updatePresenterSortArrows() {
    // const keys = ['firstname','lastname','email','phone','type','organization','sessions','attendanceStatus','ceuEligible'];
    const keys = ['firstname','lastname','email','phone','sessions'];
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

// Dumb data, gets overwritten quickly
const presenters = [
    {
        firstname: "error",
        lastname: "error",
        email: "error@example.com",
        phone: "555-1234",
        sessions: "ASL Conference 1, Deaf Culture and Community",
    }
];

window.presenters = presenters;

async function fillPresenters({ debug = true } = {}) {
  const log = (...args) => debug && console.log('[fillPresenters]', ...args);
  const warn = (...args) => debug && console.warn('[fillPresenters]', ...args);
  const errLog = (...args) => console.error('[fillPresenters]', ...args);

  try {
    // 1) Find REST config localized from PHP
  const cfg =
      (typeof pdRest !== 'undefined' && pdRest) ||
      (typeof PDPresenters !== 'undefined' && PDPresenters) ||
      null;

    if (!cfg) {
      errLog('No REST config found. Expected `pdRest` or `PDPresenters` from wp_localize_script.');
      throw new Error('Missing REST config object');
    }

    if (!cfg.root) warn('Config has no `root` property. Value:', cfg);
    if (!cfg.nonce) warn('Config has no `nonce`. You may hit 401/403 if the route requires auth.');

    const base = String(cfg.root || '').replace(/\/+$/, ''); // trim trailing slashes
    const route = cfg.route || '/presenters';
    const url = base + route;

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
      const { firstname, lastname } = splitName(r.name);
      const obj = {
        firstname,
        lastname,
        email: r.email ?? '',
        phone: r.phone_number ?? '',
        sessions: r.session_name ?? '',
        id: r.id ?? null,
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

// Helper: split "First Last ..." into { firstname, lastname }
function splitName(fullName) {
  const parts = String(fullName || '').trim().split(/\s+/);
  return {
    firstname: parts[0] || '',
    lastname: parts.slice(1).join(' ') || '',
  };
}

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    fillPresenters().then(() => {
        renderPresenters();
    }).catch(console.error);

   
});

// Render presenters table
function renderPresenters() {
  const tbody = document.getElementById('presentersTableBody');
  const data = Array.isArray(window.filteredPresenters) && window.filteredPresenters.length
    ? window.filteredPresenters
    : window.presenters;

  if (!data || data.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="9" style="text-align: center; padding: 2rem; color: #6b7280;">
          No presenters found matching your criteria.
        </td>
      </tr>`;
    return;
  }

  tbody.innerHTML = data.map((presenter) => `
    <tr class="presenter-row" style="cursor:pointer;" onclick="goToPresenterProfile(${presenter.id ?? 'null'})">
      <td style="font-weight: 600;">${presenter.firstname || ''}</td>
      <td style="font-weight: 600;">${presenter.lastname || ''}</td>
      <td>${presenter.email || ''}</td>
      <td>${presenter.phone || ''}</td>
      <td>${presenter.sessions || ''}</td>
    </tr>
  `).join('');
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
    renderPresenters();
    return;
  }
    
    window.filteredPresenters = window.presenters.filter(presenter => {
        return (
            (presenter.firstname && presenter.firstname.toLowerCase().includes(searchTerm)) ||
            (presenter.lastname && presenter.lastname.toLowerCase().includes(searchTerm)) ||
            (presenter.email && presenter.email.toLowerCase().includes(searchTerm)) ||
            (presenter.phone && presenter.phone.toLowerCase().includes(searchTerm)) ||
            (presenter.sessions && presenter.sessions.toLowerCase().includes(searchTerm))

        );
    });
    
    renderPresenters();
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
    const res = await fetch(cfg.root.replace(/\/+$/, '') + (cfg.route || '/presenters'), {
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
