// Simple, unified implementation for the Members table
// - Fetches identities via admin-ajax (`get_members`)
// - Fetches totals via REST (`/wp-json/profdef/v2/membershome/0`)
// - Merges results, supports sorting and filtering, and renders 6 columns

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

  tbody.innerHTML = filteredMembers.map(m => `
    <tr class="member-row" style="cursor:pointer;" onclick="goToMemberProfile(${m.id})">
      <td style="font-weight:600;">${m.firstname ?? ''}</td>
      <td style="font-weight:600;">${m.lastname ?? ''}</td>
      <td>${m.email ?? ''}</td>
      <td>${m.id ?? ''}</td>
      <td>${getTotalHours(m)}</td>
      <td>${getTotalCEUs(m)}</td>
    </tr>
  `).join('');
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
    (m.id != null && String(m.id).toLowerCase().includes(term))
  );

  renderMembers();
}

function goToMemberProfile(id) {
  try { localStorage.setItem('members', JSON.stringify(members)); } catch (e) {}
  const base = (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
  window.location.href = base.replace('admin-ajax.php', 'admin.php?page=profdef_member_page&member=' + id);
}

// Debugging / CSV export
function downloadAllUsersCSV(filename = 'members_with_metadata.csv') {
  if (!Array.isArray(members) || members.length === 0) {
    alert('No members to export.');
    return;
  }

  const flattenedMetaPerMember = members.map(a => {
    let m = a.metaData ?? a.metadata ?? a.meta ?? {};
    if (typeof m === 'string') {
      try { m = JSON.parse(m); } catch { m = { metaData: m }; }
    }
    return flattenObject(m);
  });

  const metaKeys = Array.from(
    flattenedMetaPerMember.reduce((set, obj) => {
      Object.keys(obj).forEach(k => set.add(k));
      return set;
    }, new Set())
  ).sort();

  const headers = ['ID', 'First Name', 'Last Name', 'Email', 'Total Hours', 'Total CEUs', ...metaKeys];

  const rows = members.map((a, i) => {
    const meta = flattenedMetaPerMember[i];
    return [
      a.id ?? '',
      a.firstname ?? '',
      a.lastname ?? '',
      a.email ?? '',
      getTotalHours(a),
      getTotalCEUs(a),
      ...metaKeys.map(k => meta[k] ?? '')
    ];
  });

  const csv = [headers, ...rows].map(r => r.map(csvEscape).join(',')).join('\r\n');
  const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' });

  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  URL.revokeObjectURL(a.href);
  a.remove();
}

function csvEscape(v) {
  if (v === null || v === undefined) v = '';
  const s = String(v).replace(/"/g, '""');
  return /[",\r\n]/.test(s) ? `"${s}"` : s;
}

function flattenObject(obj, prefix = '', out = {}) {
  if (!obj || typeof obj !== 'object') return out;

  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k;

    if (v && typeof v === 'object') {
      if (Array.isArray(v)) {
        out[key] = JSON.stringify(v);
      } else {
        flattenObject(v, key, out);
      }
    } else {
      out[key] = v;
    }
  }
  return out;
}

// Data loading (identities + totals)
function fetchIdentities() {
  const endpoint = (typeof PDMembers !== 'undefined' && PDMembers.ajaxurl)
    ? PDMembers.ajaxurl
    : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
  const payload = { action: 'get_members' };
  if (typeof PDMembers !== 'undefined' && PDMembers.nonce) payload.nonce = PDMembers.nonce;
  return jQuery.post(endpoint, payload);
}

async function fetchTotals() {
  // Use id=0 to request totals for all members
  const res = await fetch('/wp-json/profdef/v2/membershome', { credentials: 'same-origin' });
  if (!res.ok) throw new Error('Failed to load member totals');
  return await res.json();
}

function mergeIdentitiesWithTotals(identityRows, totalRows) {
  const totalsById = new Map();
  (Array.isArray(totalRows) ? totalRows : []).forEach(r => {
    const id = r.members_id ?? r.id ?? null;
    if (id == null) return;
    totalsById.set(Number(id), {
      totalHours: num(r.total_length),
      totalCEUs: num(r.total_ceu)
    });
  });

  return (Array.isArray(identityRows) ? identityRows : []).map(row => {
    const id = Number(row.id);
    const totals = totalsById.get(id) || { totalHours: 0, totalCEUs: 0 };
    return { ...row, ...totals };
  });
}

async function loadAndRenderMembers() {
  try {
    const [ids, totals] = await Promise.all([
      fetchIdentities(),
      fetchTotals()
    ]);
    members = mergeIdentitiesWithTotals(ids, totals);
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
