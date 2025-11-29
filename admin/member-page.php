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

    // Variables for use inside HTML
    $members_table_url = admin_url("admin.php?page=profdef_members_table");

    // For this page, the "member" query param is the external person_id
    // from beta_2.person, not a WordPress user ID.
    $person_id = isset($_GET['member']) ? absint($_GET['member']) : 0;

    if (!$person_id) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Missing member ID.', 'professional-development')
        );
        return;
    }

    // Look up the member from the external beta_2.person table via the signed API.
    $person_row = null;
    try {
        if (!function_exists('aslta_signed_query')) {
            $plugin_root   = dirname(dirname(__DIR__)); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if (is_readable($skeleton_path)) {
                require_once $skeleton_path;
            }
        }

        if (function_exists('aslta_signed_query')) {
            $sql = sprintf(
                'SELECT id, first_name, last_name, email, phone_number, wp_id FROM beta_2.person WHERE id = %d',
                $person_id
            );
            $result = aslta_signed_query($sql);

            if ($result['status'] >= 200 && $result['status'] < 300) {
                $decoded = json_decode($result['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (isset($decoded['rows']) && is_array($decoded['rows'])) {
                        $rows_raw = $decoded['rows'];
                    } elseif (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)) {
                        $rows_raw = $decoded;
                    } else {
                        $rows_raw = [$decoded];
                    }

                    foreach ($rows_raw as $row) {
                        if (is_array($row)) {
                            $person_row = $row;
                            break;
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $person_row = null;
    }

    if (!$person_row) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Member not found in external database.', 'professional-development')
        );
        return;
    }

    // Derive display name + email, preferring external data and falling back to linked WP user when present.
    $first_name   = isset($person_row['first_name']) ? trim((string) $person_row['first_name']) : '';
    $last_name    = isset($person_row['last_name']) ? trim((string) $person_row['last_name']) : '';
    $member_email = isset($person_row['email']) ? trim((string) $person_row['email']) : '';

    $wp_user = null;
    if (isset($person_row['wp_id']) && (int) $person_row['wp_id'] > 0) {
        $wp_user = get_userdata((int) $person_row['wp_id']);
        if ($wp_user instanceof WP_User) {
            if ($first_name === '' && !empty($wp_user->first_name)) {
                $first_name = (string) $wp_user->first_name;
            }
            if ($last_name === '' && !empty($wp_user->last_name)) {
                $last_name = (string) $wp_user->last_name;
            }
            if ($member_email === '' && !empty($wp_user->user_email)) {
                $member_email = (string) $wp_user->user_email;
            }
        }
    }

    $display_name = trim($first_name . ' ' . $last_name);
    if ($display_name === '' && $wp_user instanceof WP_User) {
        $display_name = $wp_user->display_name ?: $wp_user->user_login;
    }
    if ($display_name === '') {
        $display_name = __('Member', 'professional-development');
    }

    $member_initials = pd_member_get_initials($display_name);
    if ($member_initials === '' && $member_email !== '') {
        $member_initials = strtoupper(substr($member_email, 0, 1));
    }

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
