const PDSessionConfig = window.PDSessionpage || {};
const PD_SESSION_ENDPOINT = (() => {
    const root = (PDSessionConfig.restRoot || '').replace(/\/?$/, '/');
    const route = (PDSessionConfig.sessionsRoute || 'sessions').replace(/^\/?/, '');
    return root + route;
})();
const PD_SESSION_NONCE = PDSessionConfig.nonce || '';
const PD_SESSION_LIST_BASE = PDSessionConfig.listPageBase || '';

let currentSessionId = null;
let currentSession = null;

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const idParam = params.get('session');
    const parsedId = parseInt(idParam, 10);
    if (!Number.isInteger(parsedId) || parsedId <= 0) {
        renderSessionNotFound('Invalid session reference.');
        return;
    }
    currentSessionId = parsedId;
    bindModalControls();
    loadSession(parsedId);
});

function bindModalControls() {
    const editButton = document.getElementById('editSessionBtn');
    if (editButton) {
        editButton.addEventListener('click', () => {
            if (!currentSession) {
                return;
            }
            populateEditForm(currentSession);
            openEditSessionModal();
        });
    }
    const modalOverlay = document.getElementById('editSessionModal');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeEditSessionModal();
            }
        });
    }
    const form = document.getElementById('editSessionForm');
    if (form) {
        form.addEventListener('submit', handleEditSubmit);
    }
}

async function loadSession(id) {
    setSessionMessage('Loading session…');
    try {
        const response = await fetch(`${PD_SESSION_ENDPOINT}/${id}`, {
            headers: buildHeaders(),
            credentials: 'same-origin'
        });
        if (!response.ok) {
            throw await toFetchError(response);
        }
        const data = await response.json();
        currentSession = normalizeSession(data);
        renderSession(currentSession);
    } catch (error) {
        console.error('Failed to load session', error);
        renderSessionNotFound(error.message || 'Unable to load the requested session.');
    }
}

function buildHeaders() {
    return {
        'X-WP-Nonce': PD_SESSION_NONCE
    };
}

async function toFetchError(response) {
    let detail = '';
    try {
        const data = await response.json();
        if (data && data.message) {
            detail = data.message;
        }
    } catch (error) {
        detail = response.statusText;
    }
    const err = new Error(detail || 'Request failed');
    err.status = response.status;
    return err;
}

function normalizeSession(raw) {
    const isoDate = normalizeToISO(raw.isoDate || raw.date || raw.session_date || '');
    return {
        id: raw.id ?? raw.session_id ?? raw.ID ?? null,
        isoDate,
        date: isoDate ? formatDateForDisplay(isoDate) : (raw.date || raw.session_date || ''),
        title: (raw.title || raw.session_title || '').toString(),
        length: Number.isFinite(raw.length) ? Number(raw.length) : parseInt(raw.length || raw.length_minutes || raw.duration || '0', 10) || 0,
        stype: (raw.stype || raw.session_type || raw.type || '').toString(),
        eventType: (raw.eventType || raw.event_type || '').toString(),
        ceuWeight: (raw.ceuWeight || raw.ceu_weight || '').toString(),
        ceuConsiderations: (raw.ceuConsiderations || raw.ceu_considerations || '').toString(),
        qualifyForCeus: (raw.qualifyForCeus || raw.qualify_for_ceus || raw.qualify || '').toString() || 'No',
        presenters: (raw.presenters || raw.presenter_names || '').toString(),
        members: Array.isArray(raw.members) ? raw.members : []
    };
}

function renderSessionNotFound(message) {
    const title = document.querySelector('.main-title');
    if (title) {
        title.textContent = 'Session Not Found';
    }
    const tableContainer = document.querySelector('.table-container');
    if (tableContainer) {
        tableContainer.innerHTML = `<p style="color:#e11d48;">${message}</p>`;
    }
}

function setSessionMessage(message) {
    const title = document.querySelector('.session-title');
    if (title) {
        title.textContent = message;
    }
}

function renderSession(session) {
    const mainTitle = document.querySelector('.main-title');
    if (mainTitle) {
        mainTitle.textContent = 'Session Profile';
    }
    setText('.session-title', session.title || '');
    setText('.session-date', session.date || '');
    setText('.session-length', session.length ? `${session.length} minutes` : '');
    setText('.session-type', session.stype || '');
    setText('.event-type', session.eventType || '');
    setText('.ceu-weight', session.ceuWeight || '');
    setText('.qualify-ceus', session.qualifyForCeus || '');
    setText('.ceu-considerations', session.ceuConsiderations || '');
    setText('.presenters', session.presenters || '');
}

function setText(selector, value) {
    const el = document.querySelector(selector);
    if (el) {
        el.textContent = value;
    }
}

function openEditSessionModal() {
    const modal = document.getElementById('editSessionModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeEditSessionModal() {
    const modal = document.getElementById('editSessionModal');
    if (modal) {
        modal.classList.remove('active');
    }
    document.body.style.overflow = 'auto';
}

function populateEditForm(session) {
    setInputValue('#editSessionDate', session.isoDate ? formatDateForInput(session.isoDate) : '');
    setInputValue('#editSessionLength', session.length || 0);
    setInputValue('#editSessionTitle', session.title || '');
    setInputValue('#editSessionType', session.stype || '');
    setInputValue('#editEventType', session.eventType || '');
    setInputValue('#editCeuWeight', session.ceuWeight || '');
    setSelectValue('#editQualifyForCeus', session.qualifyForCeus || 'No');
    setInputValue('#editCeuConsiderations', session.ceuConsiderations || '');
    setInputValue('#editPresenters', session.presenters || '');
    clearFormNotice(document.getElementById('editSessionForm'));
}

function setInputValue(selector, value) {
    const field = document.querySelector(selector);
    if (field) {
        field.value = value;
    }
}

function setSelectValue(selector, value) {
    const field = document.querySelector(selector);
    if (field) {
        field.value = value;
    }
}

function formatDateForInput(iso) {
    if (!iso) {
        return '';
    }
    const parts = iso.split('-');
    if (parts.length === 3) {
        const [year, month, day] = parts;
        return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
    }
    const parsed = new Date(iso);
    if (!Number.isNaN(parsed.getTime())) {
        const month = (parsed.getMonth() + 1).toString().padStart(2, '0');
        const day = parsed.getDate().toString().padStart(2, '0');
        return `${parsed.getFullYear()}-${month}-${day}`;
    }
    return '';
}

function normalizeToISO(value) {
    const trimmed = (value || '').toString().trim();
    if (trimmed === '') {
        return '';
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
        return trimmed;
    }
    if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(trimmed)) {
        const [month, day, year] = trimmed.split('/').map((part) => parseInt(part, 10));
        return `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`;
    }
    const parsed = new Date(trimmed);
    if (!Number.isNaN(parsed.getTime())) {
        const month = (parsed.getMonth() + 1).toString().padStart(2, '0');
        const day = parsed.getDate().toString().padStart(2, '0');
        return `${parsed.getFullYear()}-${month}-${day}`;
    }
    return '';
}

function formatDateForDisplay(isoDate) {
    if (!isoDate) {
        return '';
    }
    const parts = isoDate.split('-');
    if (parts.length === 3) {
        const [year, month, day] = parts;
        return `${parseInt(month, 10)}/${parseInt(day, 10)}/${year}`;
    }
    const parsed = new Date(isoDate);
    if (!Number.isNaN(parsed.getTime())) {
        return `${parsed.getMonth() + 1}/${parsed.getDate()}/${parsed.getFullYear()}`;
    }
    return isoDate;
}

async function handleEditSubmit(event) {
    event.preventDefault();
    if (!currentSessionId) {
        return;
    }
    const form = event.target;
    clearFormNotice(form);
    const submitBtn = form.querySelector('.btn-save');
    const originalText = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) {
        submitBtn.textContent = 'Saving...';
        submitBtn.disabled = true;
    }

    const payload = collectEditPayload(form);

    try {
        const response = await fetch(`${PD_SESSION_ENDPOINT}/${currentSessionId}`, {
            method: 'PATCH',
            headers: {
                ...buildHeaders(),
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        if (!response.ok) {
            throw await toFetchError(response);
        }
        const data = await response.json();
        currentSession = normalizeSession(data);
        renderSession(currentSession);
        closeEditSessionModal();
        showFormNotice(form, 'Session updated successfully.');
    } catch (error) {
        console.error('Failed to update session', error);
        showFormNotice(form, error.message || 'Unable to update the session.', true);
    } finally {
        if (submitBtn) {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    }
}

function collectEditPayload(form) {
    const getValue = (selector) => {
        const field = form.querySelector(selector);
        return field ? field.value : '';
    };
    return {
        date: getValue('#editSessionDate'),
        length: parseInt(getValue('#editSessionLength'), 10) || 0,
        title: getValue('#editSessionTitle'),
        stype: getValue('#editSessionType'),
        eventType: getValue('#editEventType'),
        ceuWeight: getValue('#editCeuWeight'),
        qualifyForCeus: getValue('#editQualifyForCeus'),
        ceuConsiderations: getValue('#editCeuConsiderations'),
        presenters: getValue('#editPresenters')
    };
}

function showFormNotice(form, message, isError = false) {
    if (!form) {
        return;
    }
    let note = form.querySelector('.session-form-notice');
    if (!note) {
        note = document.createElement('div');
        note.className = 'session-form-notice';
        form.insertBefore(note, form.firstChild);
    }
    note.textContent = message;
    note.style.color = isError ? '#b91c1c' : '#047857';
    note.style.marginBottom = '1rem';
}

function clearFormNotice(form) {
    if (!form) {
        return;
    }
    const note = form.querySelector('.session-form-notice');
    if (note) {
        note.remove();
    }
}

window.closeEditSessionModal = closeEditSessionModal;
