// Simple, unified implementation for the Members table
// - Fetches member rows (identity + totals) via REST (`/wp-json/profdef/v2/membershome`)
// - Supports sorting, filtering, CSV export, and navigation to member profile

let memberSortKey = 'id';
let memberSortAsc = true;
let members = [];
let filteredMembers = [];

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
    const idNum = Number(m.id || 0);
    const hasWpId = Number.isFinite(idNum) && idNum > 0;
    const idDisplay = hasWpId ? idNum : 'not linked';
    const rowAttrs = hasWpId
      ? `class="member-row" style="cursor:pointer;" onclick="goToMemberProfile(${idNum})"`
      : 'class="member-row"';

    return `
      <tr ${rowAttrs}>
        <td style="font-weight:600;">${m.firstname ?? ''}</td>
        <td style="font-weight:600;">${m.lastname ?? ''}</td>
        <td>${m.email ?? ''}</td>
        <td>${idDisplay}</td>
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
    (m.id != null && String(m.id).toLowerCase().includes(term)) ||
    (!m.id && 'not linked'.includes(term))
  );

  renderMembers();
}

function goToMemberProfile(id) {
  try { localStorage.setItem('members', JSON.stringify(members)); } catch (e) {}
  const base = (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
  window.location.href = base.replace('admin-ajax.php', 'admin.php?page=profdef_member_page&member=' + id);
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

      // Normalise ID: prefer explicit WordPress ID (wp_id).
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
  const res = await fetch('/wp-json/profdef/v2/membershome', { credentials: 'same-origin' });
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
