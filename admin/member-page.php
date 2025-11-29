<?php
defined('ABSPATH') || exit ;

/**
 * Build a set of initials from a display name.
 *
 * @param string $name
 * @return string
 */
if (!function_exists('pd_member_get_initials')) {
    function pd_member_get_initials($name) {
        $initials = '';
        $parts = preg_split('/[\s\-]+/', trim($name));

        if (empty($parts)) {
            return '';
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials;
    }
}

function PD_member_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // add any post handles here.

    // https://aslta.judahsbase.com/wp-admin/admin.php?page=profdef_member_page&member=2
    // Variables for use inside HTML
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $member_id = isset($_GET['member']) ? absint($_GET['member']) : 0;

    if (!$member_id) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Missing member ID.', 'professional-development')
        );
        return;
    }

    $member_user = get_userdata($member_id);

    if (!$member_user) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Member not found.', 'professional-development')
        );
        return;
    }

    $first_name = (string) $member_user->first_name;
    $last_name = (string) $member_user->last_name;
    $display_name = trim($first_name . ' ' . $last_name);

    if ($display_name === '') {
        $display_name = $member_user->display_name ?: $member_user->user_login;
    }

    $member_initials = pd_member_get_initials($display_name);

    if ($member_initials === '') {
        $member_initials = strtoupper(substr($member_user->user_login, 0, 1));
    }

    $member_email = $member_user->user_email;

    ?>
    <div class="container">
        <div class="max-width">
            <a href="<?php echo esc_url($members_table_url); ?>" class="back-link">&larr; Back to Attendees Table</a>
            <div class="profile-container">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar" id="profileAvatar"><?php echo esc_html($member_initials); ?></div>
                    <div class="profile-info">
                        <div class="profile-name" id="profileName"><?php echo esc_html($display_name); ?></div>
                        <div class="profile-email" id="profileEmail"><?php echo esc_html($member_email); ?></div>
                    </div>
                    <button type="button" class="btn admin-service-btn" style="margin-left:auto;" onclick="openMemberAdminServiceModal()">Administrative Service</button>
                </div>

                <!-- Progress Summary -->
                <div class="summary-card">
                    <div class="summary-title">Progress Summary</div>
                    <div class="summary-row">
                        <div class="summary-item"><span class="summary-label">Total Hours all-time:</span> <span class="summary-value" id="totalHours">0</span></div>
                        <div class="summary-item"><span class="summary-label">Most recent session:</span> <span class="summary-value" id="recentSession"></span></div>
                    </div>
                    <div class="range-control">
                        <label for="yearRange"><span class="summary-label">Total Hours in the last </span>
                            <span class="summary-value" id="yearLabel">1</span>
                            <span class="summary-label">year(s):</span>
                        </label>
                        <span class="summary-value" id="filteredHours">0</span>
                        <input type="range" min="1" max="11" value="11" id="yearRange" style="width: 100%; margin-top:0.5rem;">
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
                                    <!-- Keep your original four, in order -->
                                    <th>Date</th>
                                    <th>Session Title</th>
                                    <th>Type</th>
                                    <th>Hours</th>

                                    <!-- Append the rest of the fields -->
                                    <!-- <th>Session Id</th> -->
                                    <th>CEU Capable</th>
                                    <th>CEU Weight</th>
                                    <th>Parent Event</th>
                                    <th>Event Type</th>
                                    <!-- <th>Members ID</th> -->
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

    <!-- Administrative Service modal -->
    <div class="modal-overlay" id="memberAdminServiceModal" aria-hidden="true">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="memberAdminServiceTitle">
        <div class="modal-header">
          <h2 class="modal-title" id="memberAdminServiceTitle">Administrative Service</h2>
          <button type="button" class="close-btn" aria-label="Close modal" onclick="closeMemberAdminServiceModal()">&times;</button>
        </div>
        <div class="modal-body">
          <div class="attendees-table-wrap">
            <table class="attendees-table" id="adminServiceTable">
              <thead>
                <tr>
                  <th style="cursor:pointer;" onclick="sortAdminService('start')">Start <span id="as-arrow-start"></span></th>
                  <th style="cursor:pointer;" onclick="sortAdminService('end')">End <span id="as-arrow-end"></span></th>
                  <th style="cursor:pointer;" onclick="sortAdminService('type')">Type <span id="as-arrow-type"></span></th>
                  <th style="cursor:pointer;" onclick="sortAdminService('ceu')">CEU Weight <span id="as-arrow-ceu"></span></th>
                  <th>Delete?</th>
                </tr>
              </thead>
              <tbody>
                <!-- Filled by JS -->
              </tbody>
              <tfoot>
                <tr>
                  <td><input type="date" id="mas-add-start" class="form-input" /></td>
                  <td><input type="date" id="mas-add-end" class="form-input" /></td>
                  <td>
                    <select id="mas-add-type" class="form-select">
                      <!-- Options filled by JS (getAdminServiceTypes) on modal open -->
                    </select>
                  </td>
                  <td><input type="number" id="mas-add-ceu" class="form-input" step="0.01" min="0" placeholder="0.0" /></td>
                  <td><button type="button" class="btn btn-sm" onclick="addAdminServiceRow()">Add</button></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeMemberAdminServiceModal()">Cancel</button>
            <button type="button" class="btn" onclick="saveMemberAdminService()">Save</button>
          </div>
        </div>
      </div>
    </div>

    <?php
}
