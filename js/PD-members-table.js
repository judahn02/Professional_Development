let attendeeSortKey = null;
let attendeeSortAsc = true;


function sortMembers(key) {
  if (attendeeSortKey === key) {
    attendeeSortAsc = !attendeeSortAsc;
  } else {
    attendeeSortKey = key;
    attendeeSortAsc = true;
  }

  const dir = attendeeSortAsc ? 1 : -1;

  filteredAttendees.sort((a, b) => {
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

  updateAttendeeSortArrows();
  renderAttendees();
}

function updateAttendeeSortArrows() {
    const keys = ['firstname','lastname','email','id','totalHours'];
    keys.forEach(k => {
        const el = document.getElementById('sort-arrow-' + k);
        if (el) {
            if (attendeeSortKey === k) {
                el.textContent = attendeeSortAsc ? '▲' : '▼';
                el.style.color = '#e11d48';
                el.style.fontSize = '1em';
                el.style.marginLeft = '0.2em';
            } else {
                el.textContent = '';
            }
        }
    });
}
// Helper to calculate total hours for an attendee
function getTotalHours(attendee) {
    if (typeof attendee.totalHours !== "undefined") {
        return Number(attendee.totalHours) || 0 ;
    }
    if (!attendee.sessionsData || !Array.isArray(attendee.sessionsData)) return 0;
    return attendee.sessionsData.reduce((sum, s) => sum + (parseFloat(s.hours) || 0), 0);
}

// Show initial sort arrows on load
document.addEventListener('DOMContentLoaded', updateAttendeeSortArrows);

// Fetch attendee data from the server
function fetchAttendeesFromServer() {
    const endpoint = (typeof PDMembers !== 'undefined' && PDMembers.ajaxurl)
        ? PDMembers.ajaxurl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

    const payload = {
        action: 'get_attendees'
    };

    if (typeof PDMembers !== 'undefined' && PDMembers.nonce) {
        payload.nonce = PDMembers.nonce;
    }

    jQuery.post(endpoint, payload)
        .done(function(response) {
            attendees = response;
            filteredAttendees = [...attendees];
            //renderAttendees();
            sortMembers('id');
        })
        .fail(function() {
            alert('Error fetching attendees. Please try again.');
        });
}


document.addEventListener('DOMContentLoaded', () => {
    fetchAttendeesFromServer();
});

// Render attendees table
function renderAttendees() {
    const tbody = document.getElementById('MembersTableBody');
    
    if (filteredAttendees.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 2rem; color: #6b7280;">
                    No attendees found matching your criteria.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = filteredAttendees.map((attendee, index) => `
        <tr class="attendee-row" style="cursor:pointer;" onclick="goToAttendeeProfile(${attendee.id})">
            <td style="font-weight: 600;">${attendee.firstname}</td>
            <td style="font-weight: 600;">${attendee.lastname}</td>
            <td>${attendee.email || ''}</td>
            <td>${attendee.id}</td>
            <td>${getTotalHours(attendee)}</td>
        </tr>
    `).join('');
}

// Go to attendee profile page
function goToAttendeeProfile(id) {
    // Store attendees in localStorage for access in attendee-profile.html
    localStorage.setItem('attendees', JSON.stringify(attendees));
    window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php?page=profdef_member_page&attendee=' + id);
}

// Filter attendees based on search
function filterMembers() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    
    filteredAttendees = attendees.filter(attendee => {
        return (
            (attendee.firstname && attendee.firstname.toLowerCase().includes(searchTerm)) ||
            (attendee.lastname && attendee.lastname.toLowerCase().includes(searchTerm)) ||
            (attendee.email && attendee.email.toLowerCase().includes(searchTerm)) ||
            //(attendee.id)
            (attendee.id != null && String(attendee.id).toLowerCase().includes(searchTerm))
            // (attendee.certificationType && attendee.certificationType.toLowerCase().includes(searchTerm))
        );
    });
    
    renderAttendees();
}

// Debugging functions
function downloadAllUsersCSV(filename = 'attendees_with_metadata.csv') {
  if (!Array.isArray(attendees) || attendees.length === 0) {
    alert('No attendees to export.');
    return;
  }

  // 1) Normalize + flatten metaData for each attendee
  const flattenedMetaPerAttendee = attendees.map(a => {
    let m = a.metaData ?? a.metadata ?? a.meta ?? {};
    if (typeof m === 'string') { // handle serialized metadata
      try { m = JSON.parse(m); } catch { m = { metaData: m }; }
    }
    return flattenObject(m);
  });

  // 2) Build the union of all metadata keys (stable, sorted)
  const metaKeys = Array.from(
    flattenedMetaPerAttendee.reduce((set, obj) => {
      Object.keys(obj).forEach(k => set.add(k));
      return set;
    }, new Set())
  ).sort();

  // 3) Base columns + dynamic metadata columns
  const headers = ['ID', 'First Name', 'Last Name', 'Email', 'Total Hours', ...metaKeys];

  // 4) Build rows
  const rows = attendees.map((a, i) => {
    const meta = flattenedMetaPerAttendee[i];
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
