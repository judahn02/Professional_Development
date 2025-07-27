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

// section to add new admin pages to admin menu.
function Professional_Development_admin_menu_page() {
    add_menu_page( 
        "Prof Dev", 
        "Professional Development Tracking", 
        "manage_options", 
        "ProfDef home",
        "PD_main_admin_page", 
        "dashicons-database", 
        60 
    ) ;
}
add_action( 'admin_menu', 'Professional_Development_admin_menu_page') ;

// Custom !ADMIN! CSS slug control
function slug_specific_admin_css_loader($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'ProfDef home') {
        wp_enqueue_style(
            'PD-admin-home-css',
            plugin_dir_url(__FILE__) . 'css/PD-admin-home.css',
            array(),
            '0.3',
            'all'
        );
    }
}
add_action('admin_enqueue_scripts', 'slug_specific_admin_css_loader');

function splug_specific_admin_js_loader($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'ProfDef home') {
        wp_enqueue_script(
            'PD-admin-home-js',
            plugin_dir_url( __FILE__). 'js/PD-admin-home.js',
            array('jquery'),
            '0.2',
            true
        );
    }
}


add_shortcode( 'PD_metaData', 'Professional_Development_show_all_meta_variables' ) ;
add_shortcode('PD_metaData_nonAdmin', 'Professional_Development_show_user_id') ;


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
