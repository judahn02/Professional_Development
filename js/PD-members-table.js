let memberSortKey = null;
let memberSortAsc = true;
let members = [];
let filteredMembers = [];


function sortMembers(key) {
  if (memberSortKey === key) {
    memberSortAsc = !memberSortAsc;
  } else {
    memberSortKey = key;
    memberSortAsc = true;
  }

  const dir = memberSortAsc ? 1 : -1;

  filteredMembers.sort((a, b) => {
    let valA, valB;

    if (key === 'totalHours') {
      valA = Number(getTotalHours(a)) || 0;
      valB = Number(getTotalHours(b)) || 0;
      return (valA - valB) * dir; // numeric compare
    }

    if (key === 'id') {
      valA = Number(a.id) || 0;
      valB = Number(b.id) || 0;
      return (valA - valB) * dir; // numeric compare
    }

    // string compare (case-insensitive)
    valA = String(a[key] ?? '').toLowerCase();
    valB = String(b[key] ?? '').toLowerCase();
    return valA.localeCompare(valB) * dir;
  });

  updateMemberSortArrows();
  renderMembers();
}

function updateMemberSortArrows() {
    const keys = ['firstname','lastname','email','id','totalHours'];
    keys.forEach(k => {
        const el = document.getElementById('sort-arrow-' + k);
        if (el) {
            if (memberSortKey === k) {
                el.textContent = memberSortAsc ? '▲' : '▼';
                el.style.color = '#e11d48';
                el.style.fontSize = '1em';
                el.style.marginLeft = '0.2em';
            } else {
                el.textContent = '';
            }
        }
    });
}
// Helper to calculate total hours for an member
function getTotalHours(member) {
    if (typeof member.totalHours !== "undefined") {
        return Number(member.totalHours) || 0 ;
    }
    if (!member.sessionsData || !Array.isArray(member.sessionsData)) return 0;
    return member.sessionsData.reduce((sum, s) => sum + (parseFloat(s.hours) || 0), 0);
}

// Show initial sort arrows on load
document.addEventListener('DOMContentLoaded', updateMemberSortArrows);

// Fetch member data from the server
function fetchMembersFromServer() {
    const endpoint = (typeof PDMembers !== 'undefined' && PDMembers.ajaxurl)
        ? PDMembers.ajaxurl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

    const payload = {
        action: 'get_members'
    };

    if (typeof PDMembers !== 'undefined' && PDMembers.nonce) {
        payload.nonce = PDMembers.nonce;
    }

    jQuery.post(endpoint, payload)
        .done(function(response) {
            members = response;
            filteredMembers = [...members];
            //renderMembers();
            sortMembers('id');
        })
        .fail(function() {
            alert('Error fetching members. Please try again.');
        });
}


document.addEventListener('DOMContentLoaded', () => {
    fetchMembersFromServer();
});

// Render members table
function renderMembers() {
    const tbody = document.getElementById('MembersTableBody');
    
    if (filteredMembers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 2rem; color: #6b7280;">
                    No members found matching your criteria.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = filteredMembers.map((member, index) => `
        <tr class="member-row" style="cursor:pointer;" onclick="goToMemberProfile(${member.id})">
            <td style="font-weight: 600;">${member.firstname}</td>
            <td style="font-weight: 600;">${member.lastname}</td>
            <td>${member.email || ''}</td>
            <td>${member.id}</td>
            <td>${getTotalHours(member)}</td>
        </tr>
    `).join('');
}

// Go to member profile page
function goToMemberProfile(id) {
    // Store members in localStorage for access in member-profile.html
    localStorage.setItem('members', JSON.stringify(members));
    window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php?page=profdef_member_page&member=' + id);
}

// Filter members based on search
function filterMembers() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    
    filteredMembers = members.filter(member => {
        return (
            (member.firstname && member.firstname.toLowerCase().includes(searchTerm)) ||
            (member.lastname && member.lastname.toLowerCase().includes(searchTerm)) ||
            (member.email && member.email.toLowerCase().includes(searchTerm)) ||
            //(member.id)
            (member.id != null && String(member.id).toLowerCase().includes(searchTerm))
            // (member.certificationType && member.certificationType.toLowerCase().includes(searchTerm))
        );
    });
    
    renderMembers();
}

// Debugging functions
function downloadAllUsersCSV(filename = 'members_with_metadata.csv') {
  if (!Array.isArray(members) || members.length === 0) {
    alert('No members to export.');
    return;
  }

  // 1) Normalize + flatten metaData for each member
  const flattenedMetaPerMember = members.map(a => {
    let m = a.metaData ?? a.metadata ?? a.meta ?? {};
    if (typeof m === 'string') { // handle serialized metadata
      try { m = JSON.parse(m); } catch { m = { metaData: m }; }
    }
    return flattenObject(m);
  });

  // 2) Build the union of all metadata keys (stable, sorted)
  const metaKeys = Array.from(
    flattenedMetaPerMember.reduce((set, obj) => {
      Object.keys(obj).forEach(k => set.add(k));
      return set;
    }, new Set())
  ).sort();

  // 3) Base columns + dynamic metadata columns
  const headers = ['ID', 'First Name', 'Last Name', 'Email', 'Total Hours', ...metaKeys];

  // 4) Build rows
  const rows = members.map((a, i) => {
    const meta = flattenedMetaPerMember[i];
    return [
      a.id ?? '',
      a.firstname ?? '',
      a.lastname ?? '',
      a.email ?? '',
      (Number(getTotalHours(a)) || 0),
      ...metaKeys.map(k => meta[k] ?? '')
    ];
  });

  // 5) CSV encode + download (with BOM so Excel opens as UTF-8)
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

// --- helpers ---
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
      // Arrays or nested objects → keep as JSON string to avoid losing structure
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
