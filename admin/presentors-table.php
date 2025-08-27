<?php
defined('ABSPATH') || exit ;

function PD_presentors_table_page() {
    if (!current_user_can('manage_options')) return ;

    //PHP POST processing

    // variables for use inside html here
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));

    //PHP preprocessing


    // hotlink variables here
    $PD_home_url = admin_url('admin.php?page=profdef_home') ;
    // $attendees_table_url = admin_url("admin.php?page=profdef_attendees_table") ;
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    $presentors_table_url = admin_url("admin.php?page=profdef_presentors_table") ;
    

    ?>
    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Admin Presenters Table</h1>

            <!-- Navigation Links -->
            <div class="nav-links">
                <a href="<?php echo esc_url($sessions_table_url); ?>" class="nav-link">Session Table</a>
                <a href="<?php echo esc_url($members_table_url); ?>" class="nav-link">Attendee Table</a>
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
                            <!-- <th onclick="sortPresenters('type')" style="cursor:pointer;">Presenter Type <span id="sort-arrow-type"></span></th> -->
                            <!-- <th onclick="sortPresenters('organization')" style="cursor:pointer;">Organization <span id="sort-arrow-organization"></span></th> -->
                            <th onclick="sortPresenters('sessions')" style="cursor:pointer;">Registered Session(s) <span id="sort-arrow-sessions"></span></th>
                            <!-- <th onclick="sortPresenters('attendanceStatus')" style="cursor:pointer;">Attendance Status <span id="sort-arrow-attendanceStatus"></span></th> -->
                            <!-- <th onclick="sortPresenters('ceuEligible')" style="cursor:pointer;">CEU Eligible? <span id="sort-arrow-ceuEligible"></span></th> -->
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
            
            <!-- <form id="addPresenterForm">
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
                        <input type="email" class="form-input" id="presenterEmail">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" class="form-input" id="presenterPhone">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Registered Session(s)</label>
                    <input type="text" class="form-input" id="presenterSessions" value="!--Add presentors to session in the sessions page--!" disabled aria-disabled="true"/>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddPresenterModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Presenter</button>
                </div>
            </form> -->

            <form id="addPresenterForm" autocomplete="off" novalidate>
                <div class="form-row">
                    <div class="form-group">
                    <label class="form-label" for="presenterFirstName">First Name</label>
                    <input type="text" class="form-input" id="presenterFirstName"
                            maxlength="60" autocomplete="given-name" required>
                    </div>
                    <div class="form-group">
                    <label class="form-label" for="presenterLastName">Last Name</label>
                    <input type="text" class="form-input" id="presenterLastName"
                            maxlength="60" autocomplete="family-name" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                    <label class="form-label" for="presenterEmail">Email</label>
                    <input type="email" class="form-input" id="presenterEmail"
                            maxlength="254" autocomplete="email" inputmode="email" required>
                    </div>
                    <div class="form-group">
                    <label class="form-label" for="presenterPhone">Phone</label>
                    <input type="tel" class="form-input" id="presenterPhone"
                            maxlength="20" autocomplete="tel" inputmode="tel"
                            pattern="^[0-9()+.\-\s]{7,20}$" title="7–20 digits and ().-+ spaces">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="presenterSessions">Registered Session(s)</label>
                    <input type="text" class="form-input" id="presenterSessions"
                        value="Add presentors to session in the sessions page"
                        disabled aria-disabled="true">
                </div>

                <!-- Honeypot: real users never see/fill this -->
                <div class="hp-wrap" aria-hidden="true" style="position:absolute;left:-9999px;">
                    <label for="pd_hp">Leave this field empty</label>
                    <input type="text" id="pd_hp" name="pd_hp" tabindex="-1" autocomplete="off">
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