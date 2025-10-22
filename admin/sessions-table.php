<?php

defined('ABSPATH') || exit ;

function PD_sessions_page() {
    if(!current_user_can( 'manage_options')) return;

    //POST php processing of form here


    // variables for use inside html here



    // hotlink variables here
    $PD_home_url = admin_url('admin.php?page=profdef_home') ;
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    $presenters_table_url = admin_url("admin.php?page=profdef_presenters_table") ;
    

    ?>

    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Admin Sessions Table</h1>

            <!-- Navigation Links -->
            <div class="nav-links">
                <a href="<?php echo esc_url($members_table_url) ; ?>" class="nav-link">Member Table</a>
                <a href="<?php echo esc_url($presenters_table_url) ; ?>" class="nav-link">Presenter Table</a>
                <a href="<?php echo esc_url($PD_home_url); ?>" class="nav-link">Home</a>
            </div>

            <!-- Controls Section -->
            <div class="controls-section">
                <div class="search-container">
                    <input 
                        type="text" 
                        class="search-input" 
                        placeholder="Search by... date/title/presenter/type"
                        id="searchInput"
                        oninput="filterSessions()"
                    >
                </div>
                <!-- Dev note: Attendee sorting support is built in.
                     Example future buttons:
                     <button onclick="PDSessionsTable.setAttendeeSort('last'); PDSessionsTable.refreshVisibleAttendees();">Sort by Last</button>
                     <button onclick="PDSessionsTable.setAttendeeSort('email'); PDSessionsTable.refreshVisibleAttendees();">Sort by Email</button>
                     Default mode is 'name'. No UI added yet. -->
                <button class="add-session-btn" onclick="openAddSessionModal()">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                    </svg>
                    Add Session
                </button>
            </div>

            <!-- sessions table -->
            <div class="table-scroll-top" id="sessionsTopScroll"><div class="table-scroll-spacer" id="sessionsTopScrollSpacer"></div></div>
            <div class="table-container" id="sessionsTableContainer">
                <table class="table" id="sessionsTable">
                    <thead>
                        <tr>
                            <th style="cursor:pointer;">Date <span id="sort-arrow-date"></span></th>
                            <th style="cursor:pointer;">Session Title <span id="sort-arrow-title"></span></th>
                            <th style="cursor:pointer;">Length <span id="sort-arrow-length"></span></th>
                            <th style="cursor:pointer;">Session Type <span id="sort-arrow-stype"></span></th>
                            <th style="cursor:pointer;">CEU Weight <span id="sort-arrow-ceuWeight"></span></th>
                            <th style="cursor:pointer;">CEU Considerations <span id="sort-arrow-ceuConsiderations"></span></th>
                            <th style="cursor:pointer;">Qualify for CEUs? <span id="sort-arrow-qualifyForCeus"></span></th>
                            <th style="cursor:pointer;">Event Type <span id="sort-arrow-eventType"></span></th>
                            <th style="cursor:pointer;">Parent Event <span id="sort-arrow-parentEvent"></span></th>
                            <th style="cursor:pointer;">Presenter(s) <span id="sort-arrow-presenters"></span></th>
                            <th style="cursor:pointer;">Attendees <span id="sort-arrow-attendees"></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sessionsTableBody">
                        <!-- Sessions will be populated by js -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add session modal -->
    <div class="modal-overlay" id="addSessionModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Add New Session</h2>
                <button class="close-btn" onclick="closeAddSessionModal()">&times;</button>
            </div>
            
            <form id="addSessionForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-input" id="sessionDate" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Length (minutes)</label>
                        <input type="number" class="form-input" id="sessionLength" min="0" step="15" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Session Title</label>
                    <input type="text" class="form-input" id="sessionTitle" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Session Type</label>
                        <select class="form-select" id="sessionType" required>
                            <option value="">Select Type</option>
                            <!-- <option value="Workshop">Workshop</option>
                            <option value="Panel Discussion">Panel Discussion</option>
                            <option value="Plenary">Plenary</option>
                            <option value="Endnote">Endnote</option>
                            <option value="Keynote">Keynote</option>
                            <option value="Demonstration">Demonstration</option>
                            <option value="ShareShop">ShareShop</option>
                            <option value="Other">Other</option> -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Event Type</label>
                        <select class="form-select" id="eventType" required>
                            <option value="">Select Event Type</option>
                            <!-- <option value="NPDC">NPDC</option>
                            <option value="Symposium">Symposium</option>
                            <option value="Webinar">Webinar</option>
                            <option value="External Group">External Group</option>
                            <option value="Other">Other</option> -->
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">CEU Weight</label>
                        <input type="number" class="form-input" id="ceuWeight" min="0" step="0.1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Qualify for CEUs?</label>
                        <select class="form-select" id="qualifyForCeus">
                            <option value="Yes">Yes</option>
                            <option value="No" selected>No</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="ceuConsiderationsGroup">
                    <label class="form-label">CEU Considerations</label>
                    <select class="form-select" id="ceuConsiderations">
                        <option value="">Select CEU Consideration</option>
                        <!-- <option value="PPO">PPO</option>
                        <option value="SJ">SJ</option>
                        <option value="Other">Other</option> -->
                        <option value="NA">NA</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Presenter(s)</label>
                    <input type="text" class="form-input" id="presenters" placeholder="Enter presenter names" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddSessionModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Session</button>
                </div>
            </form>
        </div>
    </div>
    <?php 
}
