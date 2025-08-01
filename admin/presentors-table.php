<?php
defined('ABSPATH') || exit ;

function PD_presentors_table_page() {
    if (!current_user_can('manage_options')) return ;

    //PHP POST processing

    // variables for use inside html here

    // hotlink variables here
    $PD_home_url = admin_url('admin.php?page=profdef_home') ;
    $attendees_table_url = admin_url("admin.php?page=profdef_attendees_table") ;
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    $presentors_table_url = admin_url("admin.php?page=profdef_presentors_table") ;
    

    ?>
    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Admin Presenters Table</h1>

            <!-- Navigation Links -->
            <div class="nav-links">
                <a href="<?php echo esc_url($sessions_table_url); ?>" class="nav-link">Session Table</a>
                <a href="<?php echo esc_url($attendees_table_url); ?>" class="nav-link">Attendee Table</a>
                <a href="<?php echo esc_url($PD_home_url); ?>" class="nav-link">Home</a>
            </div>

            <!-- Controls Section -->
            <div class="controls-section">
                <div class="search-container">
                    <input 
                        type="text" 
                        class="search-input" 
                        placeholder="Search by... name/title/type"
                        id="searchInput"
                        oninput="filterPresenters()"
                    >
                </div>
                <button class="add-presenter-btn" onclick="openAddPresenterModal()">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                    </svg>
                    Add Presenter
                </button>
            </div>

            <!-- Presenters Table -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th onclick="sortPresenters('firstname')" style="cursor:pointer;">First Name <span id="sort-arrow-firstname"></span></th>
                            <th onclick="sortPresenters('lastname')" style="cursor:pointer;">Last Name <span id="sort-arrow-lastname"></span></th>
                            <th onclick="sortPresenters('email')" style="cursor:pointer;">Email <span id="sort-arrow-email"></span></th>
                            <th onclick="sortPresenters('phone')" style="cursor:pointer;">Phone <span id="sort-arrow-phone"></span></th>
                            <th onclick="sortPresenters('type')" style="cursor:pointer;">Presenter Type <span id="sort-arrow-type"></span></th>
                            <th onclick="sortPresenters('organization')" style="cursor:pointer;">Organization <span id="sort-arrow-organization"></span></th>
                            <th onclick="sortPresenters('sessions')" style="cursor:pointer;">Registered Session(s) <span id="sort-arrow-sessions"></span></th>
                            <th onclick="sortPresenters('attendanceStatus')" style="cursor:pointer;">Attendance Status <span id="sort-arrow-attendanceStatus"></span></th>
                            <th onclick="sortPresenters('ceuEligible')" style="cursor:pointer;">CEU Eligible? <span id="sort-arrow-ceuEligible"></span></th>
                        </tr>
                    </thead>
                    <tbody id="presentersTableBody">
                        <!-- Presenters will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Presenter Modal -->
    <div class="modal-overlay" id="addPresenterModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Add New Presenter</h2>
                <button class="close-btn" onclick="closeAddPresenterModal()">&times;</button>
            </div>
            
            <form id="addPresenterForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-input" id="presenterFirstName" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-input" id="presenterLastName" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-input" id="presenterEmail" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" class="form-input" id="presenterPhone">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Presenter Type</label>
                        <select class="form-select" id="presenterType" required>
                            <option value="">Select Type</option>
                            <option value="Professional">Professional</option>
                            <option value="Student">Student</option>
                            <option value="Presenter">Presenter</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Organization</label>
                        <input type="text" class="form-input" id="presenterOrganization">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Registered Session(s)</label>
                    <input type="text" class="form-input" id="presenterSessions" placeholder="e.g. ASL Conference 1, Deaf Culture and Community">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Attendance Status</label>
                        <select class="form-select" id="attendanceStatus" required>
                            <option value="">Select Status</option>
                            <option value="Registered">Registered</option>
                            <option value="Attended">Attended</option>
                            <option value="No Show">No Show</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">CEU Eligible?</label>
                        <select class="form-select" id="ceuEligible" required>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddPresenterModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Presenter</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}