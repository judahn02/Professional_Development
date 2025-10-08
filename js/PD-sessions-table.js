let sessionSortKey = null;
let sessionSortAsc = true;

const PDSessionsConfig = window.PDSessions || {};
const PD_SESSIONS_ENDPOINT = (() => {
    const root = (PDSessionsConfig.restRoot || '').replace(/\/?$/, '/');
    const route = (PDSessionsConfig.sessionsRoute || 'sessions').replace(/^\/?/, '');
    return root + route;
})();
const PD_SESSIONS_DETAIL_BASE = PDSessionsConfig.detailPageBase || '';
const PD_SESSIONS_NONCE = PDSessionsConfig.nonce || '';

const sessionsState = {
    list: [],
    filtered: []
};

function normalizeSession(raw, index) {
    const normalized = { ...raw };

    const possibleId = raw.id ?? raw.session_id ?? raw.ID;
    if (typeof possibleId === 'number') {
        normalized.id = possibleId;
    } else if (typeof possibleId === 'string' && possibleId.trim() !== '' && !Number.isNaN(parseInt(possibleId, 10))) {
        normalized.id = parseInt(possibleId, 10);
    } else {
        normalized.id = null;
    }

    const isoDate = normalizeToISO(raw.isoDate || raw.date || raw.session_date || '');
    normalized.isoDate = isoDate;
    normalized.date = isoDate ? formatDateForDisplay(isoDate) : (raw.date || raw.session_date || '');

    const lengthValue = raw.length ?? raw.length_minutes ?? raw.duration;
    normalized.length = Number.isFinite(lengthValue) ? Number(lengthValue) : parseInt(lengthValue, 10) || 0;

    normalized.title = (raw.title || raw.session_title || '').toString();
    normalized.stype = (raw.stype || raw.session_type || raw.type || '').toString();
    normalized.eventType = (raw.eventType || raw.event_type || '').toString();
    normalized.ceuWeight = (raw.ceuWeight || raw.ceu_weight || '').toString();
    normalized.ceuConsiderations = (raw.ceuConsiderations || raw.ceu_considerations || '').toString();
    normalized.qualifyForCeus = (raw.qualifyForCeus || raw.qualify_for_ceus || raw.qualify || '').toString() || 'No';
    normalized.presenters = (raw.presenters || raw.presenter_names || '').toString();

    if (Array.isArray(raw.members)) {
        normalized.members = raw.members;
    } else if (typeof raw.members === 'string' && raw.members.trim() !== '') {
        try {
            const parsed = JSON.parse(raw.members);
            normalized.members = Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            normalized.members = [];
        }
    } else if (typeof raw.members_json === 'string' && raw.members_json.trim() !== '') {
        try {
            const parsed = JSON.parse(raw.members_json);
            normalized.members = Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            normalized.members = [];
        }
    } else {
        normalized.members = [];
    }

    normalized.rowKey = normalized.id !== null && normalized.id !== undefined ? `id-${normalized.id}` : `idx-${index}`;

    return normalized;
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

function updateSessionSortArrows() {
    const keys = ['date', 'title', 'length', 'stype', 'ceuWeight', 'ceuConsiderations', 'qualifyForCeus', 'eventType', 'presenters'];
    keys.forEach((key) => {
        const el = document.getElementById(`sort-arrow-${key}`);
        if (!el) {
            return;
        }
        if (sessionSortKey === key) {
            el.textContent = sessionSortAsc ? '▲' : '▼';
            el.style.color = '#e11d48';
            el.style.fontSize = '1em';
            el.style.marginLeft = '0.2em';
        } else {
            el.textContent = '';
        }
    });
}

async function fetchSessions() {
    setTableMessage('Loading sessions…');
    try {
        const response = await fetch(PD_SESSIONS_ENDPOINT, {
            headers: buildHeaders(),
            credentials: 'same-origin'
        });
        if (!response.ok) {
            throw await toFetchError(response);
        }
        const data = await response.json();
        const list = Array.isArray(data) ? data : [];
        sessionsState.list = list.map((item, index) => normalizeSession(item, index));
        sessionsState.filtered = [...sessionsState.list];
        if (sessionsState.list.length === 0) {
            setTableMessage('No sessions found.');
        } else {
            updateSessionSortArrows();
            renderSessions();
        }
    } catch (error) {
        console.error('Failed to load sessions', error);
        setTableMessage('Unable to load sessions. Check the database connection.');
    }
}

function buildHeaders() {
    const headers = {
        'X-WP-Nonce': PD_SESSIONS_NONCE
    };
    return headers;
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

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    };
    return value.toString().replace(/[&<"']/g, (char) => map[char] || char);
}


function setTableMessage(message) {
    const tbody = document.getElementById('sessionsTableBody');
    if (!tbody) {
        return;
    }
    const safeMessage = escapeHtml(message || '');
    tbody.innerHTML = `
        <tr>
            <td colspan="10" style="text-align:center; padding:2rem; color:#6b7280;">${safeMessage}</td>
        </tr>
    `;
}



function renderSessions() {
    const tbody = document.getElementById('sessionsTableBody');
    if (!tbody) {
        return;
    }
    if (sessionsState.filtered.length === 0) {
        setTableMessage('No sessions found matching your criteria.');
        return;
    }

    const rows = sessionsState.filtered.map((session) => {
        const displayLength = session.length ? `${session.length}m` : '';
        const safeValues = {
            date: escapeHtml(session.date || ''),
            title: escapeHtml(session.title || ''),
            length: escapeHtml(displayLength),
            stype: escapeHtml(session.stype || ''),
            ceuWeight: escapeHtml(session.ceuWeight || ''),
            ceuConsiderations: escapeHtml(session.ceuConsiderations || ''),
            qualifyForCeus: escapeHtml(session.qualifyForCeus || ''),
            eventType: escapeHtml(session.eventType || ''),
            presenters: escapeHtml(session.presenters || '')
        };

        const members = Array.isArray(session.members) && session.members.length > 0
            ? session.members.map((member) => {
                const name = escapeHtml(member.name ? member.name.toString() : '');
                const email = escapeHtml(member.email ? member.email.toString() : '');
                return `<li><span class="member-name">${name}</span><span class="member-email">${email}</span></li>`;
            }).join('')
            : '<li class="no-members">No members yet.</li>';

        const memberRowId = `member-row-${session.rowKey}`;
        const rowClick = session.id ? `goToSessionProfile(${session.id})` : '';
        const cellAttr = session.id ? `onclick="${rowClick}"` : '';

        return `
        <tr class="session-row" style="cursor:pointer;">
            <td ${cellAttr}>${safeValues.date}</td>
            <td ${cellAttr} style="font-weight:600;">${safeValues.title}</td>
            <td ${cellAttr}>${safeValues.length}</td>
            <td ${cellAttr}>${safeValues.stype}</td>
            <td ${cellAttr}>${safeValues.ceuWeight}</td>
            <td ${cellAttr}>${safeValues.ceuConsiderations}</td>
            <td ${cellAttr}>${safeValues.qualifyForCeus}</td>
            <td ${cellAttr}>${safeValues.eventType}</td>
            <td ${cellAttr}>${safeValues.presenters}</td>
            <td>
                <span class="details-dropdown" data-row-key="${session.rowKey}" onclick="toggleMemberDropdown(event, '${session.rowKey}')">
                    <svg class="dropdown-icon" width="18" height="18" fill="none" stroke="#e11d48" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle; margin-right:4px;"><path d="M6 9l6 6 6-6"/></svg>
                    Details
                </span>
            </td>
        </tr>
        <tr class="member-row" id="${memberRowId}" style="display:none;">
            <td colspan="10" style="background:#fef2f2; padding:0; border-top:1px solid #fecaca;">
                <div class="member-list-block">
                    <ul>${members}</ul>
                </div>
            </td>
        </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
}




function sortSessions(key) {
    if (sessionSortKey === key) {
        sessionSortAsc = !sessionSortAsc;
    } else {
        sessionSortKey = key;
        sessionSortAsc = true;
    }

    sessionsState.filtered.sort((a, b) => compareSessions(a, b, key, sessionSortAsc));
    updateSessionSortArrows();
    renderSessions();
}

function compareSessions(a, b, key, asc) {
    const direction = asc ? 1 : -1;
    if (key === 'date') {
        const valA = a.isoDate ? new Date(a.isoDate).getTime() : 0;
        const valB = b.isoDate ? new Date(b.isoDate).getTime() : 0;
        return direction * (valA - valB);
    }
    if (key === 'length') {
        return direction * ((a.length || 0) - (b.length || 0));
    }
    const valueA = (a[key] || '').toString().toLowerCase();
    const valueB = (b[key] || '').toString().toLowerCase();
    if (valueA < valueB) {
        return -1 * direction;
    }
    if (valueA > valueB) {
        return 1 * direction;
    }
    return 0;
}

function filterSessions() {
    const searchInput = document.getElementById('searchInput');
    const term = searchInput ? searchInput.value.toLowerCase() : '';
    if (!term) {
        sessionsState.filtered = [...sessionsState.list];
        renderSessions();
        return;
    }
    sessionsState.filtered = sessionsState.list.filter((session) => {
        return [
            session.date,
            session.title,
            session.stype,
            session.presenters,
            session.eventType
        ].some((value) => (value || '').toString().toLowerCase().includes(term));
    });
    renderSessions();
}

function openAddSessionModal() {
    const modal = document.getElementById('addSessionModal');
    if (!modal) {
        return;
    }
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeAddSessionModal() {
    const modal = document.getElementById('addSessionModal');
    const form = document.getElementById('addSessionForm');
    if (modal) {
        modal.classList.remove('active');
    }
    document.body.style.overflow = 'auto';
    if (form) {
        form.reset();
        clearFormNotice(form);
    }
}

async function handleAddSession(event) {
    event.preventDefault();
    const form = event.target;
    const submitBtn = form.querySelector('.btn-save');
    const originalHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.innerHTML = '<span class="loading"></span> Saving...';
        submitBtn.disabled = true;
    }
    clearFormNotice(form);

    const payload = collectSessionPayload(form);

    try {
        const response = await fetch(PD_SESSIONS_ENDPOINT, {
            method: 'POST',
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
        const normalized = normalizeSession(data, -1);
        sessionsState.list.unshift(normalized);
        sessionsState.filtered = [...sessionsState.list];
        renderSessions();
        closeAddSessionModal();
        showFormNotice(form, 'Session added successfully.');
    } catch (error) {
        console.error('Failed to add session', error);
        showFormNotice(form, error.message || 'Unable to save the session.', true);
    } finally {
        if (submitBtn) {
            submitBtn.innerHTML = originalHtml;
            submitBtn.disabled = false;
        }
    }
}

function collectSessionPayload(form) {
    const getValue = (selector) => {
        const field = form.querySelector(selector);
        return field ? field.value : '';
    };
    const dateValue = getValue('#sessionDate');
    return {
        date: dateValue,
        title: getValue('#sessionTitle'),
        length: parseInt(getValue('#sessionLength'), 10) || 0,
        stype: getValue('#sessionType'),
        ceuWeight: getValue('#ceuWeight'),
        ceuConsiderations: getValue('#ceuConsiderations'),
        qualifyForCeus: getValue('#qualifyForCeus'),
        eventType: getValue('#eventType'),
        presenters: getValue('#presenters')
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

function toggleMemberDropdown(event, rowKey) {
    event.stopPropagation();
    document.querySelectorAll('.member-row').forEach((row) => {
        if (!row.id.endsWith(rowKey)) {
            row.style.display = 'none';
        }
    });
    const row = document.getElementById(`member-row-${rowKey}`);
    if (row) {
        row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
    }
}

function goToSessionProfile(sessionId) {
    if (!sessionId || !PD_SESSIONS_DETAIL_BASE) {
        return;
    }
    const url = `${PD_SESSIONS_DETAIL_BASE}&session=${encodeURIComponent(sessionId)}`;
    window.location.href = url;
}

document.addEventListener('DOMContentLoaded', () => {
    updateSessionSortArrows();
    fetchSessions();
    const form = document.getElementById('addSessionForm');
    if (form) {
        form.addEventListener('submit', handleAddSession);
    }
    const modalOverlay = document.getElementById('addSessionModal');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeAddSessionModal();
            }
        });
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeAddSessionModal();
    }
    if (event.ctrlKey && (event.key === 'n' || event.key === 'N')) {
        event.preventDefault();
        openAddSessionModal();
    }
});

document.addEventListener('click', (event) => {
    const isDropdown = event.target.classList && (event.target.classList.contains('details-dropdown') || event.target.closest('.member-list-block'));
    if (!isDropdown) {
        document.querySelectorAll('.member-row').forEach((row) => {
            row.style.display = 'none';
        });
    }
});

window.sortSessions = sortSessions;
window.filterSessions = filterSessions;
window.openAddSessionModal = openAddSessionModal;
window.closeAddSessionModal = closeAddSessionModal;
window.toggleMemberDropdown = toggleMemberDropdown;
window.goToSessionProfile = goToSessionProfile;
