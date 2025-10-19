<?php
/*
Plugin Name: Professional Development
Plugin URI: 
Description: Integration Plugin for Professional Development tracking and logging.
Version: 0.8
Author: Parallel Solvit LLC
Author URI: https://parallelsolvit.com/
License: 
*/

defined('ABSPATH') || exit ;

//Initialize

require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php' ;
require_once plugin_dir_path( __FILE__ ) . 'includes/short_code_metaData.php' ;
require_once plugin_dir_path( __FILE__ ) . 'includes/short_code_client.php' ;
require_once plugin_dir_path( __FILE__ ) . 'includes/ar_member_usrID.php' ;
require_once plugin_dir_path( __FILE__ ) . 'includes/rest-presenters.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest-sessions.php';
// require_once plugin_dir_path( __FILE__ ) . '/includes/REST/registers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/REST/membershome.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/REST/memberspage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/REST/sessionhome.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/main-page.php' ;
require_once plugin_dir_path( __FILE__ ) . 'admin/members-table.php' ;
require_once plugin_dir_path( __FILE__ ) . 'admin/member-page.php' ;
require_once plugin_dir_path( __FILE__ ) . 'admin/sessions-table.php' ;
require_once plugin_dir_path( __FILE__ ) . 'admin/session-page.php' ;
require_once plugin_dir_path( __FILE__ ) . 'admin/presenters-table.php' ;
require_once plugin_dir_path( __FILE__ ) . 'admin/presenter-page.php' ;


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
        'Members Page',
        'Members Page',
        "manage_options",
        "profdef_members_table",
        "PD_members_table_admin_page",
        2
    ) ;

    add_submenu_page( 
        'profdef_home', 
        'Sessions Page',
        'Sessions Page', 
        'manage_options', 
        'profdef_sessions_table', 
        'PD_sessions_page', 
        3
    ) ;

    add_submenu_page( 
        'profdef_home', 
        'Presenters Page', 
        'Presenters Page', 
        'manage_options', 
        'profdef_presenters_table', 
        'PD_presenters_table_page', 
        4 
    ) ;

    add_submenu_page( 
        null, 
        'Member Page', 
        'Member Page', 
        'manage_options', 
        'profdef_member_page', 
        'PD_member_admin_page', 
    ) ;

    add_submenu_page( 
        null, 
        'Session Profile', 
        'Session Profile', 
        'manage_options', 
        'profdef_session_page', 
        'PD_session_individual_page'
        
    ) ;

    add_submenu_page(
        null,
        'Presenter Page',
        'Presenter Page',
        'manage_options',
        'profdef_presenter_page',
        'PD_presenter_admin_page'
    ) ;
}
add_action( 'admin_menu', 'Professional_Development_admin_menu_page') ;

// Custom !ADMIN! CSS slug control
function slug_specific_admin_css_loader($hook) {
    error_log($hook) ;
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

        if ($_GET['page'] === 'profdef_members_table') {
            wp_enqueue_style(
                'PD-admin-members-table-css',
                plugin_dir_url(__FILE__) . 'css/PD-admin-members-table.css',
                array(),
                '0.6',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_sessions_table') {
            wp_enqueue_style(
                'PD-admin-sessions-table-css',
                plugin_dir_url(__FILE__) . 'css/PD-admin-sessions-table.css',
                array(),
                '0.1',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_presenters_table') {
            wp_enqueue_style(
                'PD-admin-presenters-table-css',
                plugin_dir_url( __FILE__ ) . 'css/PD-admin-presenter-table.css',
                array(),
                '0.3',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_member_page') {
            wp_enqueue_style(
                'PD-admin-member-page-css',
                plugin_dir_url( __FILE__ ) . 'css/PD-admin-member.css',
                array(),
                '0.3',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_session_page') {
            wp_enqueue_style(
                'PD-admin-session-page-css',
                plugin_dir_url( __FILE__) . 'css/PD-admin-session-page.css',
                array(),
                '0.1',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_presenter_page') {
            wp_enqueue_style(
                'PD-admin-presenter-page-css',
                plugin_dir_url( __FILE__ ) . 'css/PD-admin-member.css',
                array(),
                '0.1',
                'all'
            ) ;
        }
    }
}
add_action('admin_enqueue_scripts', 'slug_specific_admin_css_loader');

// Custom public CSS slug control
function slug_specific_shortcode_css_loader() {
    if ( is_singular() && has_shortcode( get_post()->post_content, 'PD_Member_Info' ) ) {
        wp_enqueue_style(
            'PD-member-info-css',
            plugin_dir_url(__FILE__) . 'css/PD-member-metainformation.css',
            array(),
            '0.1',
            'all'
        );
    }
}
add_action('wp_enqueue_scripts', 'slug_specific_shortcode_css_loader');

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

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_members_table') {
        wp_enqueue_script(
            'PD-admin-members-table-js',
            plugin_dir_url(__FILE__) . 'js/PD-members-table.js',
            array('jquery'),
            '0.17',
            true
        );

        wp_localize_script(
            'PD-admin-members-table-js',
            'PDMembers',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('pd_members_nonce')
            )
        );
    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_sessions_table') {
        wp_enqueue_script(
            'PD-admin-sessions-table-js',
            plugin_dir_url( __FILE__) . 'js/PD-sessions-table.js',
            array('jquery'),
            '0.9',
            true
        );

        
        wp_localize_script(
            'PD-admin-sessions-table-js',
            'PDSessions',
            array(
                'restRoot'       => esc_url_raw( rest_url( 'profdef/v2/' ) ),
                'sessionsRoute'  => 'sessionhome',
                'nonce'          => wp_create_nonce( 'wp_rest' ),
                'detailPageBase' => admin_url( 'admin.php?page=profdef_session_page' )
            )
        );

    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_presenters_table') {

        // Enqueue script
        wp_enqueue_script(
            'PD-admin-presenters-table-js',
            plugins_url('js/PD-presenters-table.js', __FILE__),
            [], // no jquery needed
            filemtime(plugin_dir_path( __FILE__ ) . 'js/PD-presenters-table.js'),
            true
        );

        // Pass REST info + nonce for authenticated requests
        wp_localize_script(
            'PD-admin-presenters-table-js',
            'PDPresenters',
            [
                'root'  => esc_url_raw( rest_url('profdef/v1/') ),
                'route' => '/presenters',
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );

    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_member_page') {
        wp_enqueue_script(
            'PD-admin-member-page-js',
            plugin_dir_url(__FILE__) . 'js/PD-Member-metadata.js',
            array('jquery'),
            '0.22',
            true
        );

    
        wp_localize_script(
            'PD-admin-member-page-js',
            'PDMembers',
            [
                'root'  => esc_url_raw( rest_url('profdef/v2/') ), // note trailing slash
                'route' => 'memberspage',
                'nonce' => wp_create_nonce('wp_rest'),
                'id'    => isset($_GET['members_id']) ? (int) $_GET['members_id'] : get_current_user_id(),
            ]
        );
    }


    if (isset($_GET['page']) && $_GET['page'] === 'profdef_session_page') {
        wp_enqueue_script(
            'PD-admin-session-page-js',
            plugin_dir_url(__FILE__) . 'js/PD-session-page.js',
            array('jquery'),
            '0.1',
            true
        );

        
        wp_localize_script(
            'PD-admin-session-page-js',
            'PDSessionpage',
            array(
                'restRoot'      => esc_url_raw( rest_url( 'profdef/v1/' ) ),
                'sessionsRoute' => 'sessions',
                'nonce'         => wp_create_nonce( 'wp_rest' ),
                'listPageBase'  => admin_url( 'admin.php?page=profdef_sessions_table' )
            )
        );

    }
}
add_action('admin_enqueue_scripts', 'slug_specific_admin_js_loader');

// Custom public JS slug control
function slug_specific_shortcode_js_loader() {
    // Check if this is a singular page/post and contains the shortcode
    if ( is_singular() && has_shortcode( get_post()->post_content, 'PD_Member_Info' ) ) {
        wp_enqueue_script(
            'PD-Member_Info-js',
            plugin_dir_url(__FILE__) . 'js/PD-Member-metadata.js',
            array('jquery'),
            '0.1',
            true
        );

        wp_localize_script(
            'PD-Member_Info-js',
            'PDMemberInfo',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('pd_MemberInfo_nonce')
            )
        );
    }
}
add_action('wp_enqueue_scripts', 'slug_specific_shortcode_js_loader');


//JS related action controls
add_action('wp_ajax_check_db_connection', 'pd_check_db_connection_callback');

function pd_check_db_connection_callback() {
    // Security check: nonce and capability
    // Note: A nonce isn't strictly required here since it's a GET/POST from an admin page
    // and we check capability, but it's good practice.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'Access denied.'], 403);
    }

    // Nonce verification (from PDAdminHome.nonce)
    if (!check_ajax_referer('pd_home_nonce', 'nonce', false)) {
        wp_send_json_error(['error' => 'Invalid request.'], 400);
    }

    // Get encrypted credentials from options
    $host_encrypted = get_option('ProfessionalDevelopment_db_host', '');
    $name_encrypted = get_option('ProfessionalDevelopment_db_name', '');
    $user_encrypted = get_option('ProfessionalDevelopment_db_user', '');
    $pass_encrypted = get_option('ProfessionalDevelopment_db_pass', '');

    if (!$host_encrypted || !$name_encrypted || !$user_encrypted || !$pass_encrypted) {
        wp_send_json_error(['error' => 'Missing database credentials.']);
    }

    // Decrypt values
    $host = ProfessionalDevelopment_decrypt($host_encrypted);
    $name = ProfessionalDevelopment_decrypt($name_encrypted);
    $user = ProfessionalDevelopment_decrypt($user_encrypted);
    $pass = ProfessionalDevelopment_decrypt($pass_encrypted);

    if (!$host || !$name || !$user || !$pass) {
        wp_send_json_error(['error' => 'Decryption failed or credentials incomplete.']);
    }

    // Attempt DB connection
    $mysqli = new mysqli($host, $user, $pass, $name);

    if ($mysqli->connect_error) {
        wp_send_json_error(['error' => 'Connection failed: ' . $mysqli->connect_error]);
    }

    $mysqli->close();
    wp_send_json_success();
}

// ShortCode
function PD_shortcode_init() {

    add_shortcode('PD_metaData', 'Professional_Development_show_all_meta_variables');
    add_shortcode('PD_metaData_nonAdmin', 'Professional_Development_show_user_id') ;
    add_shortcode('PD_Member_Info', 'Pofessional_Development_show_member_progress') ;

}
add_action( 'init', 'PD_shortcode_init') ;


// 

// activation and deactivation hooks
register_activation_hook( __FILE__, function () {

}) ;

register_deactivation_hook(__FILE__, function () {

}) ;


// Ajax POST handlers for dynamic JS calls
add_action('wp_ajax_get_members', 'get_members_callback');

function get_members_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'Access denied.'], 403);
    }

    if (!check_ajax_referer('pd_members_nonce', 'nonce', false)) {
        wp_send_json_error(['error' => 'Invalid request.'], 400);
    }

    $args = array(
        'orderby'  => 'last_name',
        'order'    => 'ASC',
    );

    $user_query = new WP_User_Query($args);

    $members = array();

    foreach ($user_query->get_results() as $user) {
        $members[] = array(
            'id'               => $user->ID,
            'firstname'        => get_user_meta($user->ID, 'first_name', true),
            'lastname'         => get_user_meta($user->ID, 'last_name', true),
            'email'            => $user->user_email,
            // 'certificationType'=> get_user_meta($user->ID, 'certificationType', true),
            // 'totalHours'       => get_user_meta($user->ID, 'totalHours', true),
        );
    }

    wp_send_json($members);
}
