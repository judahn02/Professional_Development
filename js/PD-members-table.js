// Simple, unified implementation for the Members table
// - Fetches member rows (identity + totals) via REST (`/wp-json/profdef/v2/membershome`)
// - Supports sorting, filtering, CSV export, and navigation to member profile

let memberSortKey = 'id';
let memberSortAsc = true;
let members = [];
let filteredMembers = [];
let presenterSearchState = { timer: null, selectedId: null, results: [] };
let linkWpState = { personId: null, currentWpId: null, selectedWpId: null };

const minutesToHours = m => Math.round((Number(m || 0) / 60) * 100) / 100; // 2-dec float

function num(v) { return Number(v) || 0; }

function getTotalHours(member) {
  if (member && typeof member.total_length !== 'undefined') {
    // total_length is in minutes → convert to hours
    return minutesToHours(member.total_length);
  }
  if (member && typeof member.totalHours !== 'undefined') {
    // It comes in as minutes
    return minutesToHours(member.totalHours) || 0;
  }
  return 0;
}

function getTotalCEUs(member) {
  if (typeof member.totalCEUs !== 'undefined') return num(member.totalCEUs);
  if (typeof member.total_ceu !== 'undefined') return num(member.total_ceu);
  return 0;
}

function getRestRoot() {
  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  const root = String(cfg.restRoot || '/wp-json/profdef/v2/').replace(/\/+$/, '');
  return root;
}

function updateMemberSortArrows() {
  const keys = ['firstname','lastname','email','id','totalHours','totalCEUs'];
  keys.forEach(k => {
    const el = document.getElementById('sort-arrow-' + k);
    if (!el) return;
    if (memberSortKey === k) {
      el.textContent = memberSortAsc ? '▲' : '▼';
      el.style.color = '#e11d48';
      el.style.fontSize = '1em';
      el.style.marginLeft = '0.2em';
    } else {
      el.textContent = '';
    }
  });
}

function renderMembers() {
  const tbody = document.getElementById('MembersTableBody');
  if (!tbody) return;

  if (!filteredMembers.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align:center; padding:2rem; color:#6b7280;">
          No members found matching your criteria.
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = filteredMembers.map(m => {
    const wpId = Number(m.id || 0);
    const memberId = Number(
      (m.members_id != null ? m.members_id :
       m.member_id  != null ? m.member_id  :
       m.person_id  != null ? m.person_id  :
       0)
    ) || 0;
    const hasMemberId = Number.isFinite(memberId) && memberId > 0;
    const hasWpId = Number.isFinite(wpId) && wpId > 0;
    const rowAttrs = hasMemberId
      ? `class="member-row" style="cursor:pointer;" onclick="goToMemberProfile(${memberId})"`
      : 'class="member-row"';

    const idButton = hasMemberId
      ? `<button type="button"
                 class="link-wp-btn${hasWpId ? ' linked' : ''}"
                 data-member-id="${memberId}"
                 data-wp-id="${hasWpId ? wpId : ''}"
                 onclick="openLinkWpModal(event, ${memberId})">
           ${hasWpId ? `WP #${wpId}` : 'Link account'}
         </button>`
      : 'not linked';

    return `
      <tr ${rowAttrs}>
        <td style="font-weight:600;">${m.firstname ?? ''}</td>
        <td style="font-weight:600;">${m.lastname ?? ''}</td>
        <td>${m.email ?? ''}</td>
        <td>${idButton}</td>
        <td>${getTotalHours(m)}</td>
        <td>${getTotalCEUs(m)}</td>
      </tr>
    `;
  }).join('');
}

function sortMembers(key) {
  if (memberSortKey === key) {
    memberSortAsc = !memberSortAsc;
  } else {
    memberSortKey = key;
    memberSortAsc = true;
  }

  const dir = memberSortAsc ? 1 : -1;

  filteredMembers.sort((a, b) => {
    if (key === 'id') return (num(a.id) - num(b.id)) * dir;
    if (key === 'totalHours') return (getTotalHours(a) - getTotalHours(b)) * dir;
    if (key === 'totalCEUs') return (getTotalCEUs(a) - getTotalCEUs(b)) * dir;

    const valA = String(a[key] ?? '').toLowerCase();
    const valB = String(b[key] ?? '').toLowerCase();
    return valA.localeCompare(valB) * dir;
  });

  updateMemberSortArrows();
  renderMembers();
}

function filterMembers() {
  const el = document.getElementById('searchInput');
  const term = (el ? el.value : '').toLowerCase();

  filteredMembers = members.filter(m =>
    (m.firstname && m.firstname.toLowerCase().includes(term)) ||
    (m.lastname && m.lastname.toLowerCase().includes(term)) ||
    (m.email && m.email.toLowerCase().includes(term)) ||
    (m.members_id != null && String(m.members_id).toLowerCase().includes(term)) ||
    (m.member_id  != null && String(m.member_id).toLowerCase().includes(term)) ||
    (m.person_id  != null && String(m.person_id).toLowerCase().includes(term)) ||
    (m.id         != null && String(m.id).toLowerCase().includes(term)) ||
    ((m.members_id == null && m.member_id == null && m.person_id == null && !m.id) && 'not linked'.includes(term))
  );

  renderMembers();
}

function goToMemberProfile(id) {
  try { localStorage.setItem('members', JSON.stringify(members)); } catch (e) {}
  const base = (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
  window.location.href = base.replace('admin-ajax.php', 'admin.php?page=profdef_member_page&member=' + id);
}

// Link ARMember account modal helpers
async function searchWpUsers(term) {
  const cleaned = String(term || '').trim();
  if (!cleaned) return [];

  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  const root = '/wp-json/wp/v2';
  const q = encodeURIComponent(cleaned);
  const url = `${root}/users?search=${q}&per_page=20&_fields=id,name,slug`;

  const headers = { 'Accept': 'application/json' };
  if (cfg.restNonce || cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.restNonce || cfg.nonce;
  }

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

function renderWpUserSearchResults(items) {
  const container = document.getElementById('linkWpSearchResults');
  if (!container) return;
  linkWpState.selectedWpId = null;

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
      linkWpState.selectedWpId = item.id;
    }
    li.addEventListener('click', () => {
      const all = list.querySelectorAll('li.wp-user-result-item');
      all.forEach(el => el.classList.remove('selected'));
      li.classList.add('selected');
      linkWpState.selectedWpId = item.id;
    });
    list.appendChild(li);
  });

  container.innerHTML = '';
  container.appendChild(list);
}

function bindLinkWpSearchInput() {
  const input = document.getElementById('linkWpSearchInput');
  if (!input || input._pdBound) return;
  input._pdBound = true;

  const container = document.getElementById('linkWpSearchResults');
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
        linkWpState.selectedWpId = null;
        return;
      }
      try {
        const users = await searchWpUsers(cleaned);
        renderWpUserSearchResults(users);
      } catch (err) {
        console.error('ARMember account search failed', err);
        if (container) {
          container.innerHTML = '<div style="color:#b91c1c;">Error searching WordPress users.</div>';
        }
      }
    }, 300);
  });
}

function openLinkWpModal(event, personId) {
  if (event && typeof event.stopPropagation === 'function') event.stopPropagation();
  const overlay = document.getElementById('linkWpModal');
  if (!overlay) return;

  const mid = Number(personId || 0);
  if (!Number.isFinite(mid) || mid <= 0) return;

  const person = (members || []).find(m => Number(m.members_id || 0) === mid);
  const summaryEl = document.getElementById('linkWpPersonSummary');
  const currentEl = document.getElementById('linkWpCurrent');
  const searchInput = document.getElementById('linkWpSearchInput');
  const resultsEl = document.getElementById('linkWpSearchResults');
  const unlinkBtn = document.getElementById('linkWpUnlinkBtn');

  linkWpState.personId = mid;
  linkWpState.currentWpId = person && Number(person.id || 0) > 0 ? Number(person.id) : null;
  linkWpState.selectedWpId = null;

  if (summaryEl) {
    const name = `${person && person.firstname ? person.firstname : ''} ${person && person.lastname ? person.lastname : ''}`.trim();
    const email = person && person.email ? ` <${person.email}>` : '';
    summaryEl.textContent = name || person?.email || `Person #${mid}`;
    if (email) summaryEl.textContent += email;
  }

  if (currentEl) {
    if (linkWpState.currentWpId) {
      currentEl.textContent = `Currently linked to ARMember account #${linkWpState.currentWpId}.`;
    } else {
      currentEl.textContent = 'This attendee is not linked to any ARMember account.';
    }
  }

  if (unlinkBtn) {
    unlinkBtn.style.display = linkWpState.currentWpId ? '' : 'none';
  }

  if (searchInput) {
    searchInput.value = '';
  }
  if (resultsEl) {
    resultsEl.innerHTML = '<div style="color:#6b7280;">Start typing an ARMember account name or email...</div>';
  }

  bindLinkWpSearchInput();

  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');

  const onOverlay = (e) => { if (e.target === overlay) closeLinkWpModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);

  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closeLinkWpModal(); };
  document._pdEscLinkWp && document.removeEventListener('keydown', document._pdEscLinkWp);
  document._pdEscLinkWp = onEsc;
  document.addEventListener('keydown', onEsc);

  if (searchInput) {
    setTimeout(() => { try { searchInput.focus(); } catch (_) {} }, 10);
  }
}

function closeLinkWpModal() {
  const overlay = document.getElementById('linkWpModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) {
    overlay.removeEventListener('click', overlay._pdOverlayHandler);
    overlay._pdOverlayHandler = null;
  }
  if (document._pdEscLinkWp) {
    document.removeEventListener('keydown', document._pdEscLinkWp);
    document._pdEscLinkWp = null;
  }
  linkWpState.personId = null;
  linkWpState.currentWpId = null;
  linkWpState.selectedWpId = null;
}

async function submitLinkWp() {
  const personId = Number(linkWpState.personId || 0);
  const wpId = Number(linkWpState.selectedWpId || 0);
  if (!personId || !Number.isFinite(personId)) {
    alert('Missing attendee.');
    return;
  }
  if (!wpId || !Number.isFinite(wpId)) {
    alert('Please select an ARMember account to link.');
    return;
  }

  const root = getRestRoot();
  const url = `${root}/member/link_wp`;

  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  };
  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  if (cfg.restNonce || cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.restNonce || cfg.nonce;
  }

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
    closeLinkWpModal();
    try {
      await loadAndRenderMembers();
    } catch (e) {
      console.error('Failed to refresh members after linking WP account', e);
    }
    alert(`Linked attendee to ARMember account #${wpId}.`);
  } catch (err) {
    console.error(err);
    alert(err && err.message ? err.message : 'Failed to link ARMember account.');
  }
}

async function submitLinkWpUnlink() {
  const personId = Number(linkWpState.personId || 0);
  if (!personId || !Number.isFinite(personId)) {
    alert('Missing attendee.');
    return;
  }

  const root = getRestRoot();
  const url = `${root}/member/link_wp`;

  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  };
  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  if (cfg.restNonce || cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.restNonce || cfg.nonce;
  }

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
    closeLinkWpModal();
    try {
      await loadAndRenderMembers();
    } catch (e) {
      console.error('Failed to refresh members after unlinking WP account', e);
    }
    alert('ARMember account unlinked from attendee.');
  } catch (err) {
    console.error(err);
    alert(err && err.message ? err.message : 'Failed to unlink ARMember account.');
  }
}

// Expose link modal helpers for inline handlers
window.openLinkWpModal = openLinkWpModal;
window.closeLinkWpModal = closeLinkWpModal;
window.submitLinkWp = submitLinkWp;
window.submitLinkWpUnlink = submitLinkWpUnlink;

// Presenter → Attendee helper modal
async function searchPresentersForMembers(term) {
  const cleaned = String(term || '')
    .replace(/[^a-zA-Z\s]+/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  if (!cleaned) return [];

  const root = getRestRoot();
  const q = encodeURIComponent(cleaned);
  const url = `${root}/sessionhome4?term=${q}&only_non_attendees=1`;

  const headers = { 'Accept': 'application/json' };
  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  if (cfg.restNonce || cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.restNonce || cfg.nonce;
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
  return data
    .map(r => ({ id: Number(r.id) || 0, name: String(r.name || '') }))
    .filter(r => r.id > 0 && r.name);
}

function renderPresenterSearchResults(items) {
  const container = document.getElementById('presenterSearchResults');
  if (!container) return;
  presenterSearchState.results = Array.isArray(items) ? items.slice() : [];
  presenterSearchState.selectedId = null;

  if (!items || !items.length) {
    container.innerHTML = '<div style="color:#6b7280;">No registered presenter found matching that name.</div>';
    return;
  }

  const list = document.createElement('ul');
  list.className = 'presenter-results-list';

  items.forEach((item, idx) => {
    const li = document.createElement('li');
    li.textContent = item.name;
    li.dataset.id = String(item.id);
    li.className = 'presenter-result-item';
    if (idx === 0) {
      li.classList.add('selected');
      presenterSearchState.selectedId = item.id;
    }
    li.addEventListener('click', () => {
      const all = list.querySelectorAll('li.presenter-result-item');
      all.forEach(el => el.classList.remove('selected'));
      li.classList.add('selected');
      presenterSearchState.selectedId = item.id;
    });
    list.appendChild(li);
  });

  container.innerHTML = '';
  container.appendChild(list);
}

function bindPresenterSearchInput() {
  const input = document.getElementById('presenterSearchInput');
  if (!input || input._pdBound) return;
  input._pdBound = true;

  const container = document.getElementById('presenterSearchResults');
  if (container) {
    container.innerHTML = '<div style="color:#6b7280;">Start typing a presenter name...</div>';
  }

  input.addEventListener('input', () => {
    const term = input.value || '';
    if (presenterSearchState.timer) {
      clearTimeout(presenterSearchState.timer);
    }
    presenterSearchState.timer = setTimeout(async () => {
      const cleaned = term.replace(/[^a-zA-Z\s]+/g, '').replace(/\s+/g, ' ').trim();
      if (!cleaned || cleaned.length < 2) {
        if (container) {
          container.innerHTML = '<div style="color:#6b7280;">Type at least 2 letters.</div>';
        }
        presenterSearchState.results = [];
        presenterSearchState.selectedId = null;
        return;
      }
      try {
        const items = await searchPresentersForMembers(cleaned);
        renderPresenterSearchResults(items);
      } catch (err) {
        console.error('Presenter search failed', err);
        if (container) {
          container.innerHTML = '<div style="color:#b91c1c;">Error searching presenters.</div>';
        }
      }
    }, 300);
  });
}

function openPresenterCheckModal() {
  const overlay = document.getElementById('checkPresenterModal');
  if (!overlay) return;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');

  presenterSearchState.selectedId = null;
  presenterSearchState.results = [];

  bindPresenterSearchInput();

  const input = document.getElementById('presenterSearchInput');
  const container = document.getElementById('presenterSearchResults');
  if (input) {
    input.value = '';
    setTimeout(() => { try { input.focus(); } catch (_) {} }, 10);
  }
  if (container) {
    container.innerHTML = '<div style="color:#6b7280;">Start typing a presenter name...</div>';
  }

  const onOverlay = (e) => { if (e.target === overlay) closePresenterCheckModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);

  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closePresenterCheckModal(); };
  document._pdEscPresenterCheck && document.removeEventListener('keydown', document._pdEscPresenterCheck);
  document._pdEscPresenterCheck = onEsc;
  document.addEventListener('keydown', onEsc);
}

function closePresenterCheckModal() {
  const overlay = document.getElementById('checkPresenterModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) {
    overlay.removeEventListener('click', overlay._pdOverlayHandler);
    overlay._pdOverlayHandler = null;
  }
  if (document._pdEscPresenterCheck) {
    document.removeEventListener('keydown', document._pdEscPresenterCheck);
    document._pdEscPresenterCheck = null;
  }
}

async function markPresenterAsAttendee() {
  const results = presenterSearchState.results || [];
  if (!presenterSearchState.selectedId && results.length === 1) {
    presenterSearchState.selectedId = results[0].id;
  }
  const personId = presenterSearchState.selectedId;
  if (!personId || !Number.isFinite(Number(personId))) {
    alert('Please select a presenter from the list first.');
    return;
  }

  const root = getRestRoot();
  const url = `${root}/member/mark_attendee`;

  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  };
  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  if (cfg.restNonce || cfg.nonce) {
    headers['X-WP-Nonce'] = cfg.restNonce || cfg.nonce;
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
      throw new Error(`Failed to mark as attendee (${res.status}): ${txt.slice(0,200)}`);
    }
    closePresenterCheckModal();
    try {
      await loadAndRenderMembers();
    } catch (e) {
      console.error('Failed to refresh members after marking attendee', e);
    }
    alert('Presenter marked as attendee. They will now appear in the attendees table.');
  } catch (err) {
    console.error(err);
    alert(err && err.message ? err.message : 'Failed to mark presenter as attendee.');
  }
}

// Add Member modal controls
function openAddMemberModal() {
  const overlay = document.getElementById('addMemberModal');
  if (!overlay) return;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');

  // Reset form fields
  const form = overlay.querySelector('#addMemberForm');
  if (form) form.reset();

  // Focus first field
  const first = overlay.querySelector('#memberFirstName');
  if (first) {
    try { first.focus(); } catch (e) {}
  }

  // Close on overlay click
  const onOverlay = (e) => { if (e.target === overlay) closeAddMemberModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);

  // Close on ESC
  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closeAddMemberModal(); };
  document._pdEscAddMember && document.removeEventListener('keydown', document._pdEscAddMember);
  document._pdEscAddMember = onEsc;
  document.addEventListener('keydown', onEsc);
}

function closeAddMemberModal() {
  const overlay = document.getElementById('addMemberModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) {
    overlay.removeEventListener('click', overlay._pdOverlayHandler);
    overlay._pdOverlayHandler = null;
  }
  if (document._pdEscAddMember) {
    document.removeEventListener('keydown', document._pdEscAddMember);
    document._pdEscAddMember = null;
  }
}

async function handleAddMemberSubmit(event) {
  if (!event) return;
  event.preventDefault();

  const form = event.target;
  const first = (form.memberFirstName?.value || '').trim();
  const last = (form.memberLastName?.value || '').trim();
  const email = (form.memberEmail?.value || '').trim();
  const phone = (form.memberPhone?.value || '').trim();

  if (!first || !last || !email) {
    alert('First name, last name, and email are required.');
    return;
  }

  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  const root = String(cfg.restRoot || '').replace(/\/+$/, '') || '/wp-json/profdef/v2';
  const url  = root.replace(/\/+$/, '') + '/attendee';

  const payload = {
    first_name: first,
    last_name: last,
    email,
    phone
  };

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.restNonce || cfg.nonce || ''
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });

    if (!res.ok) {
      let msg = 'Failed to create member.';
      try {
        const errJson = await res.json();
        if (errJson && errJson.message) {
          msg = errJson.message;
        }
      } catch (_) {
        // ignore parse errors; use default msg
      }
      alert(msg);
      return;
    }

    const data = await res.json();
    console.log('[AddMember] Created', data);
    alert('Member created successfully.');

    closeAddMemberModal();
    try {
      await loadAndRenderMembers();
    } catch (e) {
      console.error('Failed to refresh members after create', e);
    }
  } catch (err) {
    console.error('[AddMember] Network or server error', err);
    alert('Unexpected error while creating member.');
  }
}

// Administrative Service modal controls
function openAdminServiceModal() {
  const overlay = document.getElementById('adminServiceModal');
  if (!overlay) return;
  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');
  // Close on overlay click
  const onOverlay = (e) => { if (e.target === overlay) closeAdminServiceModal(); };
  overlay._pdOverlayHandler && overlay.removeEventListener('click', overlay._pdOverlayHandler);
  overlay._pdOverlayHandler = onOverlay;
  overlay.addEventListener('click', onOverlay);
  // Close on ESC
  const onEsc = (ev) => { if (ev.key === 'Escape' || ev.key === 'Esc') closeAdminServiceModal(); };
  document._pdEscAdminService && document.removeEventListener('keydown', document._pdEscAdminService);
  document._pdEscAdminService = onEsc;
  document.addEventListener('keydown', onEsc);
}
function closeAdminServiceModal() {
  const overlay = document.getElementById('adminServiceModal');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  if (overlay._pdOverlayHandler) {
    overlay.removeEventListener('click', overlay._pdOverlayHandler);
    overlay._pdOverlayHandler = null;
  }
  if (document._pdEscAdminService) {
    document.removeEventListener('keydown', document._pdEscAdminService);
    document._pdEscAdminService = null;
  }
}

// Data loading (identities + totals)
function normalizeMembersFromRest(totalRows) {
  const rows = Array.isArray(totalRows) ? totalRows : [];

  const pickFirst = (obj, keys) => {
    for (const k of keys) {
      if (Object.prototype.hasOwnProperty.call(obj, k) && obj[k] != null && obj[k] !== '') {
        return obj[k];
      }
    }
    return null;
  };

  return rows
    .filter(r => r && typeof r === 'object')
    .map(row => {
      const out = { ...row };

      // Normalise WordPress user ID when available (wp_id).
      const rawWpId = pickFirst(row, ['wp_id', 'wpId', 'WP_ID']);
      if (rawWpId !== null && rawWpId !== '') {
        const n = Number(rawWpId);
        if (Number.isFinite(n) && n > 0) {
          out.id = n;
          out.wp_id = n;
        } else {
          out.id = null;
        }
      } else {
        out.id = null;
      }

      // Normalise external member/person ID when available.
      const rawMemberId = pickFirst(row, [
        'members_id',
        'member_id',
        'person_id',
        'Members_id',
        'MembersId',
        'MEMBERS_ID'
      ]);
      if (rawMemberId !== null && rawMemberId !== '') {
        const mid = Number(rawMemberId);
        if (Number.isFinite(mid) && mid > 0) {
          out.members_id = mid;
        }
      }

      // Normalise name + email fields if missing.
      if (typeof out.firstname === 'undefined' || out.firstname === null || out.firstname === '') {
        out.firstname = pickFirst(row, [
          'firstname',
          'first_name',
          'FirstName',
          'firstName',
          'FIRST_NAME',
          'first',
          'First',
          'First Name'
        ]);
      }

      if (typeof out.lastname === 'undefined' || out.lastname === null || out.lastname === '') {
        out.lastname = pickFirst(row, [
          'lastname',
          'last_name',
          'LastName',
          'lastName',
          'LAST_NAME',
          'last',
          'Last',
          'Last Name'
        ]);
      }

      if (typeof out.email === 'undefined' || out.email === null || out.email === '') {
        out.email = pickFirst(row, [
          'email',
          'Email',
          'EMAIL',
          'user_email',
          'userEmail'
        ]);
      }

      // Provide canonical totals in minutes for consumers that expect totalHours/totalCEUs.
      if (typeof out.totalHours === 'undefined') {
        if (typeof row.total_length !== 'undefined') {
          out.totalHours = num(row.total_length);
        } else if (typeof row.totalHours !== 'undefined') {
          out.totalHours = num(row.totalHours);
        }
      }

      if (typeof out.totalCEUs === 'undefined') {
        if (typeof row.total_ceu !== 'undefined') {
          out.totalCEUs = num(row.total_ceu);
        } else if (typeof row.totalCEUs !== 'undefined') {
          out.totalCEUs = num(row.totalCEUs);
        }
      }

      return out;
    });
}

async function fetchTotals() {
  // Use REST API to request totals (and identity data) for all members.
  const cfg = (typeof PDMembers !== 'undefined' && PDMembers) || {};
  const root = String(cfg.restRoot || '/wp-json/profdef/v2/').replace(/\/+$/, '');
  const url = `${root}/membershome`;
  const headers = {};
  if (cfg.restNonce) headers['X-WP-Nonce'] = cfg.restNonce;
  const res = await fetch(url, { credentials: 'same-origin', headers });
  if (!res.ok) throw new Error('Failed to load member totals');
  const data = await res.json();
  return normalizeMembersFromRest(data);
}

async function loadAndRenderMembers() {
  try {
    members = await fetchTotals();
    filteredMembers = members.slice();
    sortMembers('id'); // sets arrows + renders
  } catch (e) {
    console.error(e);
    const tbody = document.getElementById('MembersTableBody');
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:2rem; color:#b91c1c;">Failed to load members.</td></tr>';
    }
  }
}

document.addEventListener('DOMContentLoaded', loadAndRenderMembers);
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('addMemberForm');
  if (form) {
    form.addEventListener('submit', handleAddMemberSubmit);
  }
});
