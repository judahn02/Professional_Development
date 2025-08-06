<?php
defined('ABSPATH') || exit ;

function PD_attendees_table_admin_page() {
    if (!current_user_can('manage_options')) return;

    // add any post handles here.


    // add any variables needed for the html here
    $PD_home_url = admin_url('admin.php?page=profdef_home') ;
    $attendees_table_url = admin_url("admin.php?page=profdef_attendees_table") ;
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    $presentors_table_url = admin_url("admin.php?page=profdef_presentors_table") ;
    

    ?>

    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Admin Attendees Table</h1>

            <!-- Navigation Links -->
            <div class="nav-links">
                <a href="<?php echo esc_url($sessions_table_url); ?>" class="nav-link">Session Table</a>
                <a href="<?php echo esc_url($presentors_table_url); ?>" class="nav-link">Presenter Table</a>
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
                        oninput="filterAttendees()"
                    >
                </div>
            </div>

            <!-- Attendees Table -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th onclick="sortAttendees('firstname')" style="cursor:pointer;">First Name <span id="sort-arrow-firstname"></span></th>
                            <th onclick="sortAttendees('lastname')" style="cursor:pointer;">Last Name <span id="sort-arrow-lastname"></span></th>
                            <th onclick="sortAttendees('email')" style="cursor:pointer;">Email <span id="sort-arrow-email"></span></th>
                            <th onclick="sortAttendees('totalHours')" style="cursor:pointer;">Total Hours <span id="sort-arrow-totalHours"></span></th>
                        </tr>
                    </thead>
                    <tbody id="attendeesTableBody">
                        <!-- Attendees will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Attendee Modal -->
    <div class="modal-overlay" id="addAttendeeModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Add New Attendee</h2>
                <button class="close-btn" onclick="closeAddAttendeeModal()">&times;</button>
            </div>
            
            <form id="addAttendeeForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-input" id="attendeeFirstName" name="firstname" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-input" id="attendeeLastName" name="lastname" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-input" id="attendeeEmail" name="email" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Certification Type</label>
                        <select class="form-select" id="attendeeCertificationType" name="certificationType" required>
                            <option value="">Select Type</option>
                            <option value="Master">Master</option>
                            <option value="Certified">Certified</option>
                            <option value="None">None</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddAttendeeModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Attendee</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}