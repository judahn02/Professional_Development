<?php
defined('ABSPATH') || exit ;

function PD_attendee_admin_page() {
    if (!current_user_can('manage_options')) return;

    // add any post handles here.


    // Variables for use inside HTML
    $members_table_url = admin_url("admin.php?page=profdef_members_table");

    ?>
    <div class="container">
        <div class="max-width">
            <a href="<?php echo esc_url($members_table_url); ?>" class="back-link">&larr; Back to Members Table</a>
            <div class="profile-container">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar" id="profileAvatar"></div>
                    <div class="profile-info">
                        <div class="profile-name" id="profileName"></div>
                        <div class="profile-email" id="profileEmail"></div>
                    </div>
                </div>

                <!-- Contact & Org -->
                <div class="profile-section">
                    <div class="profile-section-title">Contact</div>
                    <ul class="profile-details-list">
                        <li><strong>Attendee Type:</strong> <span id="profileType" class="badge"></span></li>
                    </ul>
                </div>

                <!-- Progress Summary -->
                <div class="summary-card">
                    <div class="summary-title">Progress Summary</div>
                    <div class="summary-row">
                        <div class="summary-item"><span class="summary-label">Total Hours all-time:</span> <span class="summary-value" id="totalHours">0</span></div>
                        <div class="summary-item"><span class="summary-label">Most recently attended:</span> <span class="summary-value" id="recentSession"></span></div>
                    </div>
                    <div class="range-control">
                        <label for="yearRange"><span class="summary-label">Total Hours in the last </span>
                            <span class="summary-value" id="yearLabel">1</span>
                            <span class="summary-label">year(s):</span>
                        </label>
                        <span class="summary-value" id="filteredHours">0</span>
                        <input type="range" min="1" max="10" value="1" id="yearRange" style="width: 100%; margin-top:0.5rem;">
                    </div>
                    <button class="btn" onclick="exportReport()">Export Report</button>
                </div>

                <!-- Training & Conference History -->
                <div class="profile-section">
                    <div class="profile-section-title">Training & Conference History</div>
                    <div class="search-container">
                        <input type="text" class="search-input" placeholder="Search sessions..." id="searchInput" oninput="filterSessions()">
                        <div class="filter-buttons">
                            <button class="btn" data-filter="all" onclick="setFilter('all');return false;">All</button>
                            <button class="btn" data-filter="workshops" onclick="setFilter('workshops');return false;">Workshops</button>
                            <button class="btn" data-filter="training" onclick="setFilter('training');return false;">Training</button>
                            <button class="btn" data-filter="conference" onclick="setFilter('conference');return false;">Conference</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Session Title</th>
                                    <th>Type</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody id="sessionsTable"></tbody>
                        </table>
                        <div id="emptyState" class="empty-state" style="display: none;">
                            No sessions found matching your criteria.
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <?php
}