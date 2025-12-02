<?php
defined('ABSPATH') || exit ;

function PD_presenters_table_page() {
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
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    $presenters_table_url = admin_url("admin.php?page=profdef_presenters_table") ;
    

    ?>
    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Presenters Table</h1>

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
                        placeholder="Search by... name/email/phone"
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

            <!-- Pager (top) -->
            <div id="presentersPagerTop" class="sessions-pager" aria-label="Pagination controls (top)"></div>

            <!-- Top horizontal scrollbar (synced) -->
            <div class="table-scroll-top" id="presentersTopScroll"><div class="table-scroll-spacer" id="presentersTopScrollSpacer"></div></div>

            <!-- Presenters Table -->
            <div class="table-container" id="presentersTableContainer">
                <table class="table">
                    <colgroup>
                    <col class="col-name">
                    <col class="col-email">
                    <col class="col-phone">
                    <col class="col-sessions">
                    <col class="col-armember">
                    <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th onclick="sortPresenters('name')" style="cursor:pointer;">Name <span id="sort-arrow-name"></span></th>
                            <th onclick="sortPresenters('email')" style="cursor:pointer;">Email <span id="sort-arrow-email"></span></th>
                            <th onclick="sortPresenters('phone_number')" style="cursor:pointer;">Phone <span id="sort-arrow-phone_number"></span></th>
                            <th onclick="sortPresenters('session_count')" style="cursor:pointer;">Registered Session Count <span id="sort-arrow-session_count"></span></th>
                            <th>ARMember ID</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="presentersTableBody">
                        <!-- Presenters will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
            <!-- Pager (bottom) -->
            <div id="presentersPager" class="sessions-pager" aria-label="Pagination controls (bottom)"></div>
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
                    <input type="text" class="form-input" id="presenterSessions" value="!--Add presenters to session in the sessions page--!" disabled aria-disabled="true"/>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddPresenterModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Presenter</button>
                </div>
            </form> -->

            <form id="addPresenterForm" autocomplete="off" novalidate>
                <div class="form-group">
                    <button type="button"
                            class="btn-save"
                            style="width:100%;"
                            onclick="openAttendeeCheckModal();">
                        Are they already an Attendee?
                    </button>
                </div>
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

    <!-- Link ARMember Account Modal (Presenters) -->
    <div class="modal-overlay" id="presenterLinkWpModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="presenterLinkWpTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="presenterLinkWpTitle">Link ARMember Account</h2>
                <button type="button" class="close-btn" aria-label="Close modal" onclick="closePresenterLinkWpModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <div class="form-label">Presenter</div>
                    <div id="presenterLinkWpSummary" style="font-weight:600; color:#111827;"></div>
                </div>
                <div class="form-group">
                    <div class="form-label">Current Link</div>
                    <div id="presenterLinkWpCurrent" style="color:#4b5563;">Loading...</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="presenterLinkWpSearchInput">Search ARMember Accounts</label>
                    <input type="text"
                           class="form-input"
                           id="presenterLinkWpSearchInput"
                           placeholder="Search by name or email..."
                           autocomplete="off">
                </div>
                <div id="presenterLinkWpSearchResults" style="max-height: 260px; overflow-y: auto;">
                    <!-- Filled by JS -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closePresenterLinkWpModal()">Cancel</button>
                <button type="button" class="btn-cancel" id="presenterLinkWpUnlinkBtn" onclick="submitPresenterLinkWpUnlink()">Unlink</button>
                <button type="button" class="btn-save" onclick="submitPresenterLinkWp()">Link Account</button>
            </div>
        </div>
    </div>
    <!-- Attendee → Presenter Modal -->
    <div class="modal-overlay" id="attendeeCheckModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="attendeeCheckTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="attendeeCheckTitle">Existing Attendee Check</h2>
                <button type="button" class="close-btn" aria-label="Close modal" onclick="closeAttendeeCheckModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="attendeeSearchInput">Attendee Name</label>
                    <input type="text"
                           class="form-input"
                           id="attendeeSearchInput"
                           placeholder="Start typing an attendee name..."
                           autocomplete="off">
                </div>
                <div id="attendeeSearchResults" style="max-height: 240px; overflow-y: auto;">
                    <!-- Filled by JS -->
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeAttendeeCheckModal()">Close</button>
                <button type="button" class="btn-save" onclick="markAttendeeAsPresenter()">Mark as Presenter</button>
            </div>
        </div>
    </div>
    <?php
}
