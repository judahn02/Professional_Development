<?php
/*
Plugin Name: Professional Development
Plugin URI: 
Description: Integration Plugin for Professional Development tracking and logging.
Version: 0.3
Author: Parallel Solvit LLC
Author URI: https://parallelsolvit.com/
License: 
*/

defined('ABSPATH') || exit ;

//Initialize

require_once plugin_dir_path( __FILE__) . 'includes/functions.php' ;
require_once plugin_dir_path( __FILE__) . 'includes/short_code_metaData.php' ;
require_once plugin_dir_path(__FILE__) . 'includes/ar_member_usrID.php' ;
require_once plugin_dir_path( __FILE__) . 'admin/main-page.php' ;
require_once plugin_dir_path(__FILE__) . 'admin/attendees-table.php' ;

// section to add new admin pages to admin menu.
function Professional_Development_admin_menu_page() {
    add_menu_page( 
        "Prof Dev", 
        "Professional Development Tracking", 
        "manage_options", 
        "profdef_home",
        "PD_main_admin_page", 
        "dashicons-database", 
        60 
    ) ;

    add_submenu_page(
        'profdef_home',
        'Attendees Page',
        'Attendees Page',
        "manage_options",
        "profdef_attendees_table",
        "PD_attendees_table_admin_page",
        2
    ) ;
}
add_action( 'admin_menu', 'Professional_Development_admin_menu_page') ;

// Custom !ADMIN! CSS slug control
function slug_specific_admin_css_loader($hook) {
    // Check the page slug via $_GET
    if (isset($_GET['page'])) {
        if ($_GET['page'] === 'profdef_home') {
            wp_enqueue_style(
                'PD-admin-home-css',
                plugin_dir_url(__FILE__) . 'css/PD-admin-home.css',
                array(),
                '0.6',
                'all'
            );
        }

        if ($_GET['page'] === 'profdef_attendees_table') {
            wp_enqueue_style(
                'PD-admin-attendees-table-css',
                plugin_dir_url(__FILE__) . 'css/PD-admin-attendees-table.css',
                array(),
                '0.2',
                'all'
            );
        }
    }
}
add_action('admin_enqueue_scripts', 'slug_specific_admin_css_loader');

// Custom !ADMIN! JS slug control
function slug_specific_admin_js_loader($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'profdef_home') {
        wp_enqueue_script(
            'PD-admin-home-js',
            plugin_dir_url(__FILE__) . 'js/PD-admin-home.js',
            array('jquery'),
            '0.5',
            true
        );

        wp_localize_script(
            'PD-admin-home-js',
            'PDAdminHome',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('pd_home_nonce')
            )
        );
    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_attendees_table') {
        wp_enqueue_script(
            'PD-admin-attendees-table-js',
            plugin_dir_url(__FILE__) . 'js/PD-attendees-table.js',
            array('jquery'),
            '0.2',
            true
        );

        wp_localize_script(
            'PD-admin-attendees-table-js',
            'PDAttendees',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('pd_attendees_nonce')
            )
        );
    }
}
add_action('admin_enqueue_scripts', 'slug_specific_admin_js_loader');


//JS related action controls
add_action('wp_ajax_check_db_connection', function () {
    include plugin_dir_path(__FILE__) . 'includes/check_connection.php';
    exit; // Required to end AJAX call
});

// ShortCode
add_shortcode( 'PD_metaData', 'Professional_Development_show_all_meta_variables' ) ;
add_shortcode('PD_metaData_nonAdmin', 'Professional_Development_show_user_id') ;


// 

// activation, deactivation, and uninstall hooks
register_activation_hook( __FILE__, function () {

}) ;

register_deactivation_hook(__FILE__, function () {

}) ;

function ProfessionalDevelopment_uninstall_hook_function () {
    delete_option( 'ProfessionalDevelopment_db_host') ;
    delete_option( 'ProfessionalDevelopment_db_name') ;
    delete_option( 'ProfessionalDevelopment_db_user') ;
    delete_option( 'ProfessionalDevelopment_db_pass') ;
}
register_uninstall_hook( __FILE__, 'PofessionalDevelopment_uninstall_hook_function') ;


// Ajax POST handlers for dynamic JS calls
add_action('wp_ajax_get_attendees', 'get_attendees_callback');

function get_attendees_callback() {
    $args = array(
        'orderby'  => 'last_name',
        'order'    => 'ASC',
    );

    $user_query = new WP_User_Query($args);

    $attendees = array();

    foreach ($user_query->get_results() as $user) {
        $attendees[] = array(
            'id'               => $user->ID,
            'firstname'        => get_user_meta($user->ID, 'first_name', true),
            'lastname'         => get_user_meta($user->ID, 'last_name', true),
            'email'            => $user->user_email,
            // 'certificationType'=> get_user_meta($user->ID, 'certificationType', true),
            // 'totalHours'       => get_user_meta($user->ID, 'totalHours', true),
        );
    }

    wp_send_json($attendees);
}
