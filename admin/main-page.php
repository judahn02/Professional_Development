<?php
defined('ABSPATH') || exit ;

function PD_main_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Save form
    if (isset($_POST['ProfessionalDevelopment_save'])) {
        check_admin_referer('ProfessionalDevelopment_save_db_config');

        update_option('ProfessionalDevelopment_db_host', ProfessionalDevelopment_encrypt(sanitize_text_field($_POST['db_host'])));
        update_option('ProfessionalDevelopment_db_name', ProfessionalDevelopment_encrypt(sanitize_text_field($_POST['db_name'])));
        update_option('ProfessionalDevelopment_db_user', ProfessionalDevelopment_encrypt(sanitize_text_field($_POST['db_user'])));
        update_option('ProfessionalDevelopment_db_pass', ProfessionalDevelopment_encrypt(sanitize_text_field($_POST['db_pass'])));

        echo '<div class="updated"><p>Saved!</p></div>';
    }

    // Get current (decrypted) values
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));

    $PD_home_url = admin_url('admin.php?page=profdef_home') ;
    // $attendees_table_url = admin_url("admin.php?page=profdef_attendees_table") ;
    $members_table_url = admin_url("admin.php?page=profdef_members_table");
    $sessions_table_url = admin_url("admin.php?page=profdef_sessions_table") ;
    $presentors_table_url = admin_url("admin.php?page=profdef_presentors_table") ;
    
    ?>
    
    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Admin Home Dashboard</h1>

            <!-- Main Navigation Buttons -->
            <div class="nav-buttons">
                <a href="<?php echo esc_url($sessions_table_url); ?>" class="nav-button" id="sessionTableBtn">
                    SESSION TABLE
                </a>
                <a href="<?php echo esc_url($members_table_url); ?>" class="nav-button" id="attendeeTableBtn">
                    ATTENDEE TABLE
                </a>
                <a href="<?php echo esc_url($presentors_table_url); ?>" class="nav-button" id="presenterTableBtn">
                    PRESENTER TABLE
                </a>
            </div>
            <div class="section">
                <h2 class="section-title">DB Configuration Settings</h2>
                <div class="content-area large">
                    <div class="status-indicator" id="dbStatus"></div>

                    <form method="post">
                        <?php wp_nonce_field('ProfessionalDevelopment_save_db_config'); ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="db_host">DB Host</label></th>
                                <td><input type="text" name="db_host" value="<?php echo esc_attr($host); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="db_name">DB Name</label></th>
                                <td><input type="text" name="db_name" value="<?php echo esc_attr($name); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="db_user">DB User</label></th>
                                <td><input type="text" name="db_user" value="<?php echo esc_attr($user); ?>"
                                        class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="db_pass">DB Password</label></th>
                                <td><input type="password" name="db_pass" value="no...stoop :("
                                        class="regular-text" /></td>
                            </tr>
                        </table>

                        <?php submit_button('Save Credentials', 'primary', 'ProfessionalDevelopment_save'); ?>
                    </form>
                </div>
            </div>


            <!-- Tutorial/Guide Section -->
            <div class="section">
                <h2 class="section-title">Short Tutorial / Guide</h2>
                <div class="content-area large">
                    <div class="placeholder-text">
                        Quick start guide and tutorial content will be displayed here<br>
                        <small>Step-by-step instructions for admin tasks</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
}

