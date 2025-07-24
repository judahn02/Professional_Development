<?php

defined('ABSPATH') || exit;

require 'database.php' ;


// function custom_admin_menu_page() {
//     add_menu_page(
//         'Admin Tools',             // Page title
//         'Admin Tools',             // Menu title
//         'manage_options',          // Capability required to see it
//         'custom-admin-tools',      // Menu slug (used in URL)
//         'custom_admin_page_html',  // Callback to display content
//         'dashicons-admin-tools',   // Icon (see https://developer.wordpress.org/resource/dashicons/)
//         60                         // Position in menu
//     );
// }
// add_action('admin_menu', 'custom_admin_menu_page');

// // Page content
// function custom_admin_page_html() {
//     if ( !current_user_can('manage_options') ) {
//         return;
//     }

//     echo '<div class="wrap">';
//     echo '<h1>Welcome to Admin Tools</h1>';
//     echo '<p>This is a custom admin page.</p>';
//     echo '</div>';
// }
