// Initialize dashboard
document.addEventListener('DOMContentLoaded', function () {
            initializeDashboard();
        });

function initializeDashboard() {
    // Simulate checking database connection status
    checkDatabaseStatus();

    // Add click handlers for navigation buttons
    setupNavigationHandlers();

    // Initialize any dynamic content
    loadDashboardData();
}

function checkDatabaseStatus() {
    const statusIndicator = document.getElementById('dbStatus');

    console.log("Checking database status...");

    // Show loading status while the request is being made
    statusIndicator.className = 'status-indicator loading';
    statusIndicator.title = 'Checking database connection...';

    // Use localized ajaxurl if available
    const endpoint = (typeof PDAdminHome !== 'undefined' && PDAdminHome.ajaxurl)
        ? PDAdminHome.ajaxurl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

    // Build form-encoded body so PHP can read $_REQUEST for nonce
    const params = new URLSearchParams();
    params.set('action', 'check_db_connection');
    if (typeof PDAdminHome !== 'undefined' && PDAdminHome.nonce) {
        params.set('nonce', PDAdminHome.nonce);
    }

    fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: params.toString()
    })
    .then(res => {
        console.log("Response received:", res);
        return res.json();
    })
    .then(data => {
        console.log("Parsed JSON:", data);

        if (data.success) {
            statusIndicator.className = 'status-indicator';
            statusIndicator.title = 'Database Connected';
        } else {
            statusIndicator.className = 'status-indicator error';
            statusIndicator.title = 'Database Connection Error: ' + (data.error || 'Unknown error');
        }

        console.log("Status updated:", statusIndicator.className, statusIndicator.title);
    })
    .catch(err => {
        console.error("Fetch error:", err);
        statusIndicator.className = 'status-indicator error';
        statusIndicator.title = 'Fetch Error: ' + err.message;

        console.log("Status updated:", statusIndicator.className, statusIndicator.title);
    });
}


function setupNavigationHandlers() {
    const buttons = document.querySelectorAll('.nav-button');

    buttons.forEach(button => {
        button.addEventListener('click', function (e) {
            // // For now, prevent navigation and show alert
            // // Remove this when actual pages are created
            // e.preventDefault();

            // const buttonText = this.textContent.trim();
            // alert(`Navigating to ${buttonText} page...\n\nThis would redirect to: ${this.href}`);

            // Add visual feedback
            this.style.background = '#be123c';
            setTimeout(() => {
                this.style.background = '#e11d48';
            }, 200);
        });
    });
}

function loadDashboardData() {
    // This would load actual dashboard statistics
    // For now, just show loading state briefly
    const contentAreas = document.querySelectorAll('.content-area');

    contentAreas.forEach(area => {
        area.classList.add('loading');
    });

    setTimeout(() => {
        contentAreas.forEach(area => {
            area.classList.remove('loading');
        });
    }, 1500);
}

// Function to update content areas dynamically
function updateConfigurationPanel() {
    const configArea = document.querySelector('.section:nth-child(2) .content-area');

    // This would be populated with actual DB configuration content
    configArea.innerHTML = `
                <div class="status-indicator" id="dbStatus"></div>
                <div class="placeholder-text">
                    Database configuration loaded successfully<br>
                    <small>Ready for administrative tasks</small>
                </div>
            `;
}

function updateTutorialPanel() {
    const tutorialArea = document.querySelector('.section:nth-child(3) .content-area');

    // This would be populated with actual tutorial content
    tutorialArea.innerHTML = `
                <div class="placeholder-text">
                    Tutorial content loaded<br>
                    <small>Interactive guides available</small>
                </div>
            `;
}

// Utility function for future API calls
async function makeAPICall(endpoint, method = 'GET', data = null) {
    try {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            }
        };

        if (data) {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(endpoint, options);
        return await response.json();
    } catch (error) {
        console.error('API call failed:', error);
        return null;
    }
}
