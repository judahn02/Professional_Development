let sessionSortKey = null;
let sessionSortAsc = true;

function sortSessions(key) {
    if (sessionSortKey === key) {
        sessionSortAsc = !sessionSortAsc;
    } else {
        sessionSortKey = key;
        sessionSortAsc = true;
    }
    filteredSessions.sort((a, b) => {
        if (key === 'date') {
            // Try to parse as date (MM/DD/YYYY or M/D/YYYY)
            const parse = s => {
                if (!s) return 0;
                const parts = s.split('/');
                if (parts.length === 3) {
                    // new Date(year, monthIndex, day)
                    return new Date(parseInt(parts[2]), parseInt(parts[0]) - 1, parseInt(parts[1])).getTime();
                }
                return new Date(s).getTime();
            };
            const valA = parse(a[key]);
            const valB = parse(b[key]);
            return sessionSortAsc ? valA - valB : valB - valA;
        } else {
            let valA = (a[key] || '').toLowerCase();
            let valB = (b[key] || '').toLowerCase();
            if (valA < valB) return sessionSortAsc ? -1 : 1;
            if (valA > valB) return sessionSortAsc ? 1 : -1;
            return 0;
        }
    });
    updateSessionSortArrows();
    renderSessions();
}

function updateSessionSortArrows() {
    const keys = ['date','title','length','stype','ceuWeight','ceuConsiderations','qualifyForCeus','eventType','presenters'];
    keys.forEach(k => {
        const el = document.getElementById('sort-arrow-' + k);
        if (el) {
            if (sessionSortKey === k) {
                el.textContent = sessionSortAsc ? '▲' : '▼';
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
document.addEventListener('DOMContentLoaded', updateSessionSortArrows);
// Sample session data with members
const sessions = [
    {
        date: "3/14/2025",
        title: "ASL Conference 1",
        length: "60",
        stype: "Workshop",
        ceuWeight: "6.0",
        ceuConsiderations: "Standard workshop requirements",
        qualifyForCeus: "Yes",
        eventType: "Webinar",
        presenters: "Billy Bob Joe",
        members: [
            { name: "Alice Smith", email: "alice@example.com" },
            { name: "Bob Lee", email: "bob@example.com" },
            { name: "Carlos Rivera", email: "carlos@example.com" }
        ]
    },
    {
        date: "2/15/2025",
        title: "Advanced Fingerspelling Techniques",
        length: "90",
        stype: "Training",
        ceuWeight: "4.0",
        ceuConsiderations: "Requires completion certificate",
        qualifyForCeus: "Yes",
        eventType: "In-Person",
        presenters: "Sarah Johnson, Mike Chen",
        members: [
            { name: "Diana Prince", email: "diana@example.com" },
            { name: "Evan Tran", email: "evan@example.com" }
        ]
    },
    {
        date: "1/20/2025",
        title: "Deaf Culture and Community",
        length: "30",
        stype: "Conference",
        ceuWeight: "8.0",
        ceuConsiderations: "Full day attendance required",
        qualifyForCeus: "Yes",
        eventType: "Hybrid",
        presenters: "Dr. Amanda Rodriguez",
        members: [
            { name: "Fiona Zhang", email: "fiona@example.com" },
            { name: "George Patel", email: "george@example.com" },
            { name: "Hannah Kim", email: "hannah@example.com" },
            { name: "Ivan Petrov", email: "ivan@example.com" }
        ]
    },
    {
        date: "12/10/2024",
        title: "Teaching Methods for ASL",
        length: "75",
        stype: "Workshop",
        ceuWeight: "5.0",
        ceuConsiderations: "Interactive participation required",
        qualifyForCeus: "Yes",
        eventType: "Webinar",
        presenters: "Jennifer Lee, David Kim",
        members: []
    }
];

let filteredSessions = [...sessions];

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    renderSessions();
});

// Render sessions table
function renderSessions() {
    const tbody = document.getElementById('sessionsTableBody');
    
    if (filteredSessions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" style="text-align: center; padding: 2rem; color: #6b7280;">
                    No sessions found matching your criteria.
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    filteredSessions.forEach((session, index) => {
        const members = session.members && session.members.length > 0
            ? session.members.map(a => `<li><span class="member-name">${a.name}</span><span class="member-email">${a.email}</span></li>`).join('')
            : '<li class="no-members">No members yet.</li>';
        html += `
        <tr class="session-row" style="cursor:pointer;">
            <td onclick="goToSessionProfile(${index})">${session.date}</td>
            <td onclick="goToSessionProfile(${index})" style="font-weight: 600;">${session.title}</td>
            <td onclick="goToSessionProfile(${index})">${session.length}m</td>
            <td onclick="goToSessionProfile(${index})">${session.stype}</td>
            <td onclick="goToSessionProfile(${index})">${session.ceuWeight}</td>
            <td onclick="goToSessionProfile(${index})">${session.ceuConsiderations}</td>
            <td onclick="goToSessionProfile(${index})">${session.qualifyForCeus}</td>
            <td onclick="goToSessionProfile(${index})">${session.eventType}</td>
            <td onclick="goToSessionProfile(${index})">${session.presenters}</td>
            <td>
                <span class="details-dropdown" data-index="${index}" onclick="toggleMemberDropdown(event, ${index})">
                    <svg class="dropdown-icon" width="18" height="18" fill="none" stroke="#e11d48" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle; margin-right:4px;"><path d="M6 9l6 6 6-6"/></svg>
                    Details
                </span>
            </td>
        </tr>
        <tr class="member-row" id="member-row-${index}" style="display:none;">
            <td colspan="10" style="background:#fef2f2; padding:0; border-top:1px solid #fecaca;">
                <div class="member-list-block">
                    <ul>${members}</ul>
                </div>
            </td>
        </tr>
        `;
    });
    tbody.innerHTML = html;
}

// Filter sessions based on search
function filterSessions() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    
    filteredSessions = sessions.filter(session => {
        return session.date.toLowerCase().includes(searchTerm) ||
                session.title.toLowerCase().includes(searchTerm) ||
                session.stype.toLowerCase().includes(searchTerm) ||
                session.presenters.toLowerCase().includes(searchTerm) ||
                session.eventType.toLowerCase().includes(searchTerm);
    });
    
    renderSessions();
}


// Toggle member row below the session row
function toggleMemberDropdown(event, sessionIndex) {
    event.stopPropagation();
    // Hide all member rows except this one
    document.querySelectorAll('.member-row').forEach((el, idx) => {
        if (idx !== sessionIndex) el.style.display = 'none';
    });
    const row = document.getElementById(`member-row-${sessionIndex}`);
    if (row) {
        row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
    }
}

// Close member rows when clicking outside the table
document.addEventListener('click', function(e) {
    const isDropdown = e.target.classList.contains('details-dropdown') || e.target.closest('.member-list-block');
    if (!isDropdown) {
        document.querySelectorAll('.member-row').forEach(el => {
            el.style.display = 'none';
        });
    }
});

// Modal functions
function openAddSessionModal() {
    document.getElementById('addSessionModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeAddSessionModal() {
    document.getElementById('addSessionModal').classList.remove('active');
    document.body.style.overflow = 'auto';
    document.getElementById('addSessionForm').reset();
}

function goToSessionProfile(index) {
    // Store sessions in localStorage for access in the target page
    localStorage.setItem('sessions', JSON.stringify(sessions));

    // Redirect to the admin page, replacing admin-ajax.php with your page slug + param
    window.location.href = ajaxurl.replace(
        'admin-ajax.php',
        'admin.php?page=profdef_session_page&session=' + encodeURIComponent(index)
    );
}


// Handle form submission
document.getElementById('addSessionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Show loading state
    const submitBtn = document.querySelector('.btn-save');
    const originalText = submitBtn.textContent;
    submitBtn.innerHTML = '<span class="loading"></span> Saving...';
    submitBtn.disabled = true;
    
    // Simulate API call delay
    setTimeout(() => {
        // Get form data
        const formData = {
            date: document.getElementById('sessionDate').value,
            title: document.getElementById('sessionTitle').value,
            length: document.getElementById('sessionLength').value,
            stype: document.getElementById('sessionType').value,
            ceuWeight: document.getElementById('ceuWeight').value,
            ceuConsiderations: document.getElementById('ceuConsiderations').value,
            qualifyForCeus: document.getElementById('qualifyForCeus').value,
            eventType: document.getElementById('eventType').value,
            presenters: document.getElementById('presenters').value
        };
        
        // Add to sessions array (in real app, this would be sent to backend)
        sessions.unshift(formData);
        filteredSessions = [...sessions];
        
        // Show success message
        const modal = document.querySelector('.modal');
        const successMsg = document.createElement('div');
        successMsg.className = 'success-message';
        successMsg.textContent = 'Session added successfully!';
        modal.insertBefore(successMsg, modal.firstChild);
        
        // Re-render table
        renderSessions();
        
        // Reset form and close modal after delay
        setTimeout(() => {
            closeAddSessionModal();
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }, 1500);
        
    }, 2000);
});

// Close modal when clicking outside
document.getElementById('addSessionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddSessionModal();
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // ESC to close modal
    if (e.key === 'Escape') {
        closeAddSessionModal();
    }
    
    // Ctrl+N to add new session
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openAddSessionModal();
    }
});
