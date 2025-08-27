<?php

defined('ABSPATH') || exit ;

function PD_session_individual_page() {
    if(!current_user_can( 'manage_options')) return;

    //POST php processing of form here


    // variables for use inside html here



    // hotlink variables here
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    // $attendees_table_url = admin_url("admin.php?page=profdef_attendees_table") ;
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $presentors_table_url = admin_url("admin.php?page=profdef_presentors_table") ;
    $PD_home_url = admin_url('admin.php?page=profdef_home') ;

    ?>

    <div class="container">
        <div class="max-width">
            <div class="nav-links">
                <a href="<?php echo esc_url($sessions_table_url); ?>" class="nav-link">Back to Sessions</a>
                <a href="<?php echo esc_url($members_table_url); ?>" class="nav-link">Attendee Table</a>
                <a href="<?php echo esc_url($presentors_table_url); ?>" class="nav-link">Presenter Table</a>
                <a href="<?php echo esc_url($PD_home_url); ?>" class="nav-link">Home</a>
            </div>
            <h1 class="main-title">Session Profile</h1>
            <div class="table-container" style="padding:2rem;">
                <button class="add-session-btn" id="editSessionBtn" style="margin-bottom:2rem; float:right;">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20" style="vertical-align:middle;">
                        <path d="M17.414 2.586a2 2 0 00-2.828 0l-9.9 9.9A2 2 0 004 14v2a1 1 0 001 1h2a2 2 0 001.414-.586l9.9-9.9a2 2 0 000-2.828zM5 16v-2l9.293-9.293 2 2L7 16H5z"/>
                    </svg>
                    Edit Session
                </button>
                <!-- Session Title -->
                <h2 class="session-title" style="color:#e11d48; font-size:2rem; margin-bottom:1rem;"></h2>
                <!-- Metadata -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:2rem; margin-bottom:2rem;">
                    <div>
                        <p><strong>Date:</strong> <span class="session-date"></span></p>
                        <p><strong>Length:</strong> <span class="session-length"></span></p>
                        <p><strong>Session Type:</strong> <span class="session-type"></span></p>
                        <p><strong>Event Type:</strong> <span class="event-type"></span></p>
                    </div>
                    <div>
                        <p><strong>CEU Weight:</strong> <span class="ceu-weight"></span></p>
                        <p><strong>Qualify for CEUs?</strong> <span class="qualify-ceus"></span></p>
                        <p><strong>CEU Considerations:</strong> <span class="ceu-considerations"></span></p>
                    </div>
                </div>
                <!-- Presenters -->
                <div style="margin-bottom:2rem;">
                    <h3 style="color:#be123c; margin-bottom:0.5rem;">Presenter(s)</h3>
                    <p class="presenters"></p>
                </div>
                <!-- Attendees (placeholder) -->
                <div>
                    <h3 style="color:#be123c; margin-bottom:0.5rem;">Attendees</h3>
                    <p>List of attendees will be shown here (to be integrated with database).</p>
                </div>
            </div>

            <div class="modal-overlay" id="editSessionModal">
                <div class="modal">
                    <div class="modal-header">
                        <h2 class="modal-title">Edit Session</h2>
                        <button class="close-btn" onclick="closeEditSessionModal()">&times;</button>
                    </div>
                    <form id="editSessionForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-input" id="editSessionDate" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Length (minutes)</label>
                                <input type="number" class="form-input" id="editSessionLength" min="0" step="15" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Session Title</label>
                            <input type="text" class="form-input" id="editSessionTitle" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Session Type</label>
                                <input type="text" class="form-input" id="editSessionType" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Event Type</label>
                                <input type="text" class="form-input" id="editEventType" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">CEU Weight</label>
                                <input type="number" class="form-input" id="editCeuWeight" min="0" step="0.1">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Qualify for CEUs?</label>
                                <select class="form-select" id="editQualifyForCeus">
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">CEU Considerations</label>
                            <input type="text" class="form-input" id="editCeuConsiderations">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Presenter(s)</label>
                            <input type="text" class="form-input" id="editPresenters" required>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="closeEditSessionModal()">Cancel</button>
                            <button type="submit" class="btn-save">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}