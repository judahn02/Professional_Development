let presenterSortKey = null;
let presenterSortAsc = true;

function sortPresenters(key) {
    if (presenterSortKey === key) {
        presenterSortAsc = !presenterSortAsc;
    } else {
        presenterSortKey = key;
        presenterSortAsc = true;
    }
    filteredPresenters.sort((a, b) => {
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
    const keys = ['firstname','lastname','email','phone','type','organization','sessions','attendanceStatus','ceuEligible'];
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

// Sample presenter data TO BE CHANGED LATER
// This should be replaced with actual data from backend
const presenters = [
    {
        firstname: "Alice",
        lastname: "Smith",
        email: "alice@example.com",
        phone: "555-1234",
        type: "Professional",
        organization: "Acme Corp",
        sessions: "ASL Conference 1, Deaf Culture and Community",
        attendanceStatus: "Attended",
        ceuEligible: "Yes"
    },
    {
        firstname: "Bob",
        lastname: "Lee",
        email: "bob@example.com",
        phone: "555-5678",
        type: "Student",
        organization: "University of Example",
        sessions: "Advanced Fingerspelling Techniques",
        attendanceStatus: "Registered",
        ceuEligible: "No"
    },
    {
        firstname: "Carlos",
        lastname: "Rivera",
        email: "carlos@example.com",
        phone: "555-8765",
        type: "Presenter",
        organization: "",
        sessions: "Teaching Methods for ASL",
        attendanceStatus: "Attended",
        ceuEligible: "Yes"
    }
];

let filteredPresenters = [...presenters];

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    renderPresenters();
});

// Render presenters table
function renderPresenters() {
    const tbody = document.getElementById('presentersTableBody');
    
    if (filteredPresenters.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 2rem; color: #6b7280;">
                    No presenters found matching your criteria.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = filteredPresenters.map((presenter, index) => `
        <tr class="presenter-row" style="cursor:pointer;" onclick="goToPresenterProfile(${index})">
            <td style="font-weight: 600;">${presenter.firstname}</td>
            <td style="font-weight: 600;">${presenter.lastname}</td>
            <td>${presenter.email || ''}</td>
            <td>${presenter.phone || ''}</td>
            <td>${presenter.type || ''}</td>
            <td>${presenter.organization || ''}</td>
            <td>${presenter.sessions || ''}</td>
            <td>${presenter.attendanceStatus || ''}</td>
            <td>${presenter.ceuEligible || ''}</td>
        </tr>
    `).join('');
}

// Go to presenter profile page
function goToPresenterProfile(index) {
    // Store presenters in localStorage for access in presenter-profile.html
    localStorage.setItem('presenters', JSON.stringify(presenters));
    window.location.href = `presenter-profile.html?presenter=${index}`;
}

// Filter presenters based on search
function filterPresenters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    
    filteredPresenters = presenters.filter(presenter => {
        return (
            (presenter.firstname && presenter.firstname.toLowerCase().includes(searchTerm)) ||
            (presenter.lastname && presenter.lastname.toLowerCase().includes(searchTerm)) ||
            (presenter.email && presenter.email.toLowerCase().includes(searchTerm)) ||
            (presenter.phone && presenter.phone.toLowerCase().includes(searchTerm)) ||
            (presenter.type && presenter.type.toLowerCase().includes(searchTerm)) ||
            (presenter.organization && presenter.organization.toLowerCase().includes(searchTerm)) ||
            (presenter.sessions && presenter.sessions.toLowerCase().includes(searchTerm)) ||
            (presenter.attendanceStatus && presenter.attendanceStatus.toLowerCase().includes(searchTerm)) ||
            (presenter.ceuEligible && presenter.ceuEligible.toLowerCase().includes(searchTerm))
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

// Handle form submission
document.getElementById('addPresenterForm').addEventListener('submit', function(e) {
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
            firstname: document.getElementById('presenterFirstName').value,
            lastname: document.getElementById('presenterLastName').value,
            email: document.getElementById('presenterEmail').value,
            phone: document.getElementById('presenterPhone').value,
            type: document.getElementById('presenterType').value,
            organization: document.getElementById('presenterOrganization').value,
            sessions: document.getElementById('presenterSessions').value,
            attendanceStatus: document.getElementById('attendanceStatus').value,
            ceuEligible: document.getElementById('ceuEligible').value
        };
        
        // Add to presenters array (in real app, this would be sent to backend)
        presenters.unshift(formData);
        filteredPresenters = [...presenters];
        
        // Show success message
        const modal = document.querySelector('.modal');
        const successMsg = document.createElement('div');
        successMsg.className = 'success-message';
        successMsg.textContent = 'Presenter added successfully!';
        modal.insertBefore(successMsg, modal.firstChild);
        
        // Re-render table
        renderPresenters();
        
        // Reset form and close modal after delay
        setTimeout(() => {
            closeAddPresenterModal();
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }, 1500);
        
    }, 2000);
});

// Close modal when clicking outside
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