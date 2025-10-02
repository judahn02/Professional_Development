<?php

if (!defined('ABSPATH')) {
    exit ;
}

// for debugging only, show all meta varabiles the plugin in using.
function Professional_Development_show_all_meta_variables ($atts = [], $content= null, $tag = '') {

    if (!defined('PROFDEV_ENABLE_DEBUG_SHORTCODE') || !PROFDEV_ENABLE_DEBUG_SHORTCODE) {
        return "<!-- PD_metaData shortcode disabled. Add define('PROFDEV_ENABLE_DEBUG_SHORTCODE', true); to wp-config.php to enable. -->";
    }

    if (!current_user_can('manage_options')) {
    return 'Access denied'; // or return 'Access denied';
    }
    $usr_ID = get_current_user_id() ;
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));


    ob_start(); // Start output buffering
    ?>
    <div class="pd-metaData-box">
        <p>current user ID: <?php echo esc_html($usr_ID); ?></p>
        <p>host: <?php echo esc_html($host); ?></p>
        <p>name: <?php echo esc_html($name); ?></p>
        <p>user: <?php echo esc_html($user); ?></p>
        <p>pass: <?php echo esc_html($pass); ?></p>
    </div>
    <?php
    return ob_get_clean(); // Return the buffered content
}
