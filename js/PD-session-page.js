// Dynamically loads session details based on the session index in the URL

let sessionIndex = null;
let sessions = [];

document.addEventListener('DOMContentLoaded', function() {
    // Get session index from URL
    const params = new URLSearchParams(window.location.search);
    sessionIndex = parseInt(params.get('session'), 10);

    // Get sessions from localStorage
    sessions = JSON.parse(localStorage.getItem('sessions') || '[]');
    const session = sessions[sessionIndex];

    if (!session) {
        document.querySelector('.main-title').textContent = 'Session Not Found';
        document.querySelector('.table-container').innerHTML = '<p style="color:#e11d48;">Session data not found.</p>';
        return;
    }

    // Fill in the session details
    document.querySelector('.main-title').textContent = 'Session Profile';
    document.querySelector('.session-title').textContent = session.title;
    document.querySelector('.session-date').textContent = session.date;
    document.querySelector('.session-length').textContent = session.length + ' minutes';
    document.querySelector('.session-type').textContent = session.type;
    document.querySelector('.event-type').textContent = session.eventType;
    document.querySelector('.ceu-weight').textContent = session.ceuWeight;
    document.querySelector('.qualify-ceus').textContent = session.qualifyForCeus;
    document.querySelector('.ceu-considerations').textContent = session.ceuConsiderations;
    document.querySelector('.presenters').textContent = session.presenters;

    // Edit button handler
    document.getElementById('editSessionBtn').addEventListener('click', function() {
        openEditSessionModal(session);
    });
});

// Open modal and pre-fill form
function openEditSessionModal(session) {
    document.getElementById('editSessionModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    document.getElementById('editSessionDate').value = session.date.split('/').reverse().join('-'); // Convert MM/DD/YYYY to YYYY-MM-DD
    document.getElementById('editSessionLength').value = session.length;
    document.getElementById('editSessionTitle').value = session.title;
    document.getElementById('editSessionType').value = session.type;
    document.getElementById('editEventType').value = session.eventType;
    document.getElementById('editCeuWeight').value = session.ceuWeight;
    document.getElementById('editQualifyForCeus').value = session.qualifyForCeus;
    document.getElementById('editCeuConsiderations').value = session.ceuConsiderations;
    document.getElementById('editPresenters').value = session.presenters;
}

// Close modal
function closeEditSessionModal() {
    document.getElementById('editSessionModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Save changes
document.getElementById('editSessionForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Get updated values
    const updatedSession = {
        date: formatDateForDisplay(document.getElementById('editSessionDate').value),
        length: document.getElementById('editSessionLength').value,
        title: document.getElementById('editSessionTitle').value,
        type: document.getElementById('editSessionType').value,
        eventType: document.getElementById('editEventType').value,
        ceuWeight: document.getElementById('editCeuWeight').value,
        qualifyForCeus: document.getElementById('editQualifyForCeus').value,
        ceuConsiderations: document.getElementById('editCeuConsiderations').value,
        presenters: document.getElementById('editPresenters').value,
        attendees: sessions[sessionIndex].attendees || []
    };

    // Update and save
    sessions[sessionIndex] = updatedSession;
    localStorage.setItem('sessions', JSON.stringify(sessions));

    // Update UI
    document.querySelector('.session-title').textContent = updatedSession.title;
    document.querySelector('.session-date').textContent = updatedSession.date;
    document.querySelector('.session-length').textContent = updatedSession.length + ' minutes';
    document.querySelector('.session-type').textContent = updatedSession.type;
    document.querySelector('.event-type').textContent = updatedSession.eventType;
    document.querySelector('.ceu-weight').textContent = updatedSession.ceuWeight;
    document.querySelector('.qualify-ceus').textContent = updatedSession.qualifyForCeus;
    document.querySelector('.ceu-considerations').textContent = updatedSession.ceuConsiderations;
    document.querySelector('.presenters').textContent = updatedSession.presenters;

    closeEditSessionModal();
});

// Helper to convert YYYY-MM-DD to MM/DD/YYYY
function formatDateForDisplay(dateStr) {
    if (!dateStr) return '';
    const [year, month, day] = dateStr.split('-');
    return `${parseInt(month)}/${parseInt(day)}/${year}`;
}

// Close modal when clicking outside
document.getElementById('editSessionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditSessionModal();
    }
});
