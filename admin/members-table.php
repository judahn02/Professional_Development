<?php
defined('ABSPATH') || exit ;

function PD_members_table_admin_page() {
    if (!current_user_can('manage_options')) return;

    // add any post handles here.

    // add any variables needed for the html here
    $PD_home_url         = admin_url('admin.php?page=profdef_home');
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $sessions_table_url  = admin_url("admin.php?page=profdef_sessions_table");
    $presenters_table_url= admin_url("admin.php?page=profdef_presenters_table");
    ?>
    <div class="container">
      <div class="max-width">
        <h1 class="main-title">Attendees Table</h1>

        <!-- Navigation Links -->
        <div class="nav-links">
          <a href="<?php echo esc_url($sessions_table_url); ?>" class="nav-link">Session Table</a>
          <a href="<?php echo esc_url($presenters_table_url); ?>" class="nav-link">Presenter Table</a>
          <a href="<?php echo esc_url($PD_home_url); ?>" class="nav-link">Home</a>
        </div>

        <!-- Controls Section -->
        <div class="controls-section">
          <div class="search-container">
            <input type="text" class="search-input" placeholder="Search by... name/email/phone/wp id" id="searchInput"
                   oninput="filterMembers()">
          </div>
          <button class="add-member-btn" onclick="openAddMemberModal()">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                    d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                    clip-rule="evenodd" />
            </svg>
            Add Member
          </button>
        </div>

        <!-- Pager (top) -->
        <div id="membersPagerTop" class="sessions-pager" aria-label="Pagination controls (top)"></div>

        <!-- Attendees Table -->
        <div class="table-container">
          <table class="table">
            <thead>
              <tr>
                <th onclick="sortMembers('firstname')" style="cursor:pointer;">First Name <span id="sort-arrow-firstname"></span></th>
                <th onclick="sortMembers('lastname')"  style="cursor:pointer;">Last Name <span id="sort-arrow-lastname"></span></th>
                <th onclick="sortMembers('email')"     style="cursor:pointer;">Email <span id="sort-arrow-email"></span></th>
                <th>ARMember ID</th>
                <th onclick="sortMembers('totalHours')"style="cursor:pointer;">Total Hours <span id="sort-arrow-totalHours"></span></th>
                <th onclick="sortMembers('totalCEUs')" style="cursor:pointer;">Total CEUs  <span id="sort-arrow-totalCEUs"></span></th>
              </tr>
            </thead>
            <tbody id="MembersTableBody">
              <!-- Members will be populated by JavaScript -->
            </tbody>
            </table>
        </div>

        <!-- Pager (bottom) -->
        <div id="membersPager" class="sessions-pager" aria-label="Pagination controls (bottom)"></div>
      </div>
    </div>

    <!-- Add Member Modal -->
    <div class="modal-overlay" id="addMemberModal" aria-hidden="true">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addMemberTitle">
        <div class="modal-header">
          <h2 class="modal-title" id="addMemberTitle">Add New Member</h2>
          <button type="button" class="close-btn" aria-label="Close modal" onclick="closeAddMemberModal()">&times;</button>
        </div>

        <form id="addMemberForm" autocomplete="off" novalidate>
          <div class="form-group">
            <button type="button"
                    class="btn-save"
                    style="width:100%;"
                    onclick="openPresenterCheckModal();">
              Are they a registered Presenter?
            </button>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="memberFirstName">First Name</label>
              <input type="text" class="form-input" id="memberFirstName"
                     name="first_name" maxlength="60" autocomplete="given-name" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="memberLastName">Last Name</label>
              <input type="text" class="form-input" id="memberLastName"
                     name="last_name" maxlength="60" autocomplete="family-name" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="memberEmail">Email</label>
              <input type="email" class="form-input" id="memberEmail"
                     name="email" maxlength="254" autocomplete="email" inputmode="email" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="memberPhone">Phone Number</label>
              <input type="tel" class="form-input" id="memberPhone"
                     name="phone" maxlength="20" autocomplete="tel" inputmode="tel"
                     pattern="^[0-9()+.\\-\\s]{7,20}$" title="7–20 digits and ().-+ spaces">
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeAddMemberModal()">Cancel</button>
            <button type="submit" class="btn-save">Save Member</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Link ARMember Account Modal -->
    <div class="modal-overlay" id="linkWpModal" aria-hidden="true">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="linkWpTitle">
        <div class="modal-header">
          <h2 class="modal-title" id="linkWpTitle">Link ARMember Account</h2>
          <button type="button" class="close-btn" aria-label="Close modal" onclick="closeLinkWpModal()">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <div class="form-label">Attendee</div>
            <div id="linkWpPersonSummary" style="font-weight:600; color:#111827;"></div>
          </div>
          <div class="form-group">
            <div class="form-label">Current Link</div>
            <div id="linkWpCurrent" style="color:#4b5563;">Loading...</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="linkWpSearchInput">Search ARMember Accounts</label>
            <input type="text"
                   class="form-input"
                   id="linkWpSearchInput"
                   placeholder="Search by name or email..."
                   autocomplete="off">
          </div>
          <div id="linkWpSearchResults" style="max-height: 260px; overflow-y: auto;">
            <!-- Filled by JS -->
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeLinkWpModal()">Cancel</button>
          <button type="button" class="btn-cancel" id="linkWpUnlinkBtn" onclick="submitLinkWpUnlink()">Unlink</button>
          <button type="button" class="btn-save" onclick="submitLinkWp()">Link Account</button>
        </div>
      </div>
    </div>

    <!-- Check Presenter Modal -->
    <div class="modal-overlay" id="checkPresenterModal" aria-hidden="true">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="checkPresenterTitle">
        <div class="modal-header">
          <h2 class="modal-title" id="checkPresenterTitle">Registered Presenter Check</h2>
          <button type="button" class="close-btn" aria-label="Close modal" onclick="closePresenterCheckModal()">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="presenterSearchInput">Presenter Name</label>
            <input type="text"
                   class="form-input"
                   id="presenterSearchInput"
                   placeholder="Start typing a presenter name..."
                   autocomplete="off">
          </div>
          <div id="presenterSearchResults" style="max-height: 240px; overflow-y: auto;">
            <!-- Filled by JS -->
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closePresenterCheckModal()">Close</button>
          <button type="button" class="btn-save" onclick="markPresenterAsAttendee()">Mark as Attendee</button>
        </div>
      </div>
    </div>

    <?php
}
