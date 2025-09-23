<?php
defined('ABSPATH') || exit;

function PD_presenter_admin_page() {
    if (!current_user_can( 'manage_options' )) return;

    // add any post handles here.

    // add any variables needed for the html here
    $presenters_table_url = admin_url("admin.php?page=profdef_presentors_table") ;

    ?>

    <div class="container">
        <div class="max-width">
            <a href="<?php echo esc_url($presenters_table_url); ?>" class="back-link">&larr; Back to Attendees</a>
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
                    <!-- <ul class="profile-details-list">
                            <li><strong>Presenter Type:</strong> <span id="profileType" class="badge"></span></li>
                    </ul> -->
                </div>

                <!-- Training & Confrence History -->
                <div class="profile-section">
                    <div class="profile-section-title">Conference Presentig History</div>
                    <div class="search-container">
                        <input type="text" class="search-input" placeholder="Search sessions..." id="searchInput" oninput="filterSessions()">
                        <div class="filter-buttons"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
}