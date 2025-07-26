<?php
/*
Plugin Name: Professional Development
Plugin URI: 
Description: Integration Plugin for Professional Development tracking and logging.
Version: 0.2
Author: Parallel Solvit LLC
Author URI: https://parallelsolvit.com/
License: 
*/

defined('ABSPATH') || exit ;

//Initialize

require_once plugin_dir_path( __FILE__) . 'includes/functions.php' ;
require_once plugin_dir_path( __FILE__) . 'includes/short_code_metaData.php' ;
require_once plugin_dir_path( __FILE__) . 'admin/main-page.php' ;

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
add_shortcode( 'PD_metaData', 'show_all_meta_variables' ) ;


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
