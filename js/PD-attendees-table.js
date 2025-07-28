let attendeeSortKey = null;
let attendeeSortAsc = true;

function sortAttendees(key) {
    if (attendeeSortKey === key) {
        attendeeSortAsc = !attendeeSortAsc;
    } else {
        attendeeSortKey = key;
        attendeeSortAsc = true;
    }
    filteredAttendees.sort((a, b) => {
        let valA, valB;
        if (key === 'totalHours') {
            valA = getTotalHours(a);
            valB = getTotalHours(b);
        } else {
            valA = (a[key] || '').toLowerCase();
            valB = (b[key] || '').toLowerCase();
        }
        if (valA < valB) return attendeeSortAsc ? -1 : 1;
        if (valA > valB) return attendeeSortAsc ? 1 : -1;
        return 0;
    });
    updateAttendeeSortArrows();
    renderAttendees();
}

function updateAttendeeSortArrows() {
    const keys = ['firstname','lastname','email','certificationType','totalHours'];
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
    jQuery.post(ajaxurl, { action: 'get_attendees' })
        .done(function(response) {
            attendees = response;
            filteredAttendees = [...attendees];
            renderAttendees();
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
    const tbody = document.getElementById('attendeesTableBody');
    
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
        <tr class="attendee-row" style="cursor:pointer;" onclick="goToAttendeeProfile(${index})">
            <td style="font-weight: 600;">${attendee.firstname}</td>
            <td style="font-weight: 600;">${attendee.lastname}</td>
            <td>${attendee.email || ''}</td>
            <td>${getTotalHours(attendee)}</td>
        </tr>
    `).join('');
    // <td>${attendee.certificationType || ''}</td>
}

// Go to attendee profile page
function goToAttendeeProfile(index) {
    // Store attendees in localStorage for access in attendee-profile.html
    localStorage.setItem('attendees', JSON.stringify(attendees));
    window.location.href = `attendee-profile.html?attendee=${index}`;
}

// Filter attendees based on search
function filterAttendees() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    
    filteredAttendees = attendees.filter(attendee => {
        return (
            (attendee.firstname && attendee.firstname.toLowerCase().includes(searchTerm)) ||
            (attendee.lastname && attendee.lastname.toLowerCase().includes(searchTerm)) ||
            (attendee.email && attendee.email.toLowerCase().includes(searchTerm)) ||
            (attendee.certificationType && attendee.certificationType.toLowerCase().includes(searchTerm))
        );
    });
    
    renderAttendees();
}

// Modal functions
// function openAddAttendeeModal() {
//     document.getElementById('addAttendeeModal').classList.add('active');
//     document.body.style.overflow = 'hidden';
// }

// function closeAddAttendeeModal() {
//     document.getElementById('addAttendeeModal').classList.remove('active');
//     document.body.style.overflow = 'auto';
//     document.getElementById('addAttendeeForm').reset();
// }

// Handle form submission
// document.getElementById('addAttendeeForm').addEventListener('submit', function(e) {
//     e.preventDefault();
    
//     // Show loading state
//     const submitBtn = document.querySelector('.btn-save');
//     const originalText = submitBtn.textContent;
//     submitBtn.innerHTML = '<span class="loading"></span> Saving...';
//     submitBtn.disabled = true;

//     const formData = new FormData() ;
//     formData.append("firstname", document.getElementById('attendeeFirstName').value) ;
//     formData.append("lastname", document.getElementById('attendeeLastName').value);
//     formData.append("email", document.getElementById('attendeeEmail').value);
//     formData.append("certificationType", document.getElementById('attendeeCertificationType').value);

//     fetch('add_attendee.php', {
//         method: 'POST',
//         body: formData
//     })
//     .then(res => res.json())
//     .then(data => {
//         console.log("Backend response:", data) ;
//         if (data.success) {
//             fetchAttendeesFromServer() ;
//             closeAddAttendeeModal() ;
//         } else {
//             alert('Error adding attendee: ' + data.error) ;
//         }
//         submitBtn.textContent = originalText ;
//         submitBtn.disabled = false ;
//     })
//     .catch(err => {
//         console.error('Fetch failed:', err);
//         alert('An error occurred while adding the attendee.');
//         submitBtn.textContent = originalText;
//         submitBtn.disabled = false;
//     }) ;
// });

// Close modal when clicking outside
// document.getElementById('addAttendeeModal').addEventListener('click', function(e) {
//     if (e.target === this) {
//         closeAddAttendeeModal();
//     }
// });

// Keyboard shortcuts
// document.addEventListener('keydown', function(e) {
//     // ESC to close modal
//     if (e.key === 'Escape') {
//         closeAddAttendeeModal();
//     }
    
    // Ctrl+N to add new attendee
    // if (e.ctrlKey && e.key === 'n') {
    //     e.preventDefault();
    //     openAddAttendeeModal();
    // }
// });