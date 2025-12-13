<?php
/*
Plugin Name: Professional Development
Plugin URI: 
Description: Integration Plugin for Professional Development tracking and logging.
Version: 0.8.3
Author: Parallel Solvit LLC
Author URI: https://parallelsolvit.com/
License: 
*/



// Initialize
$plugin_dir = plugin_dir_path(__FILE__);

// Centralized external DB schema name used in signed SQL queries.
// Change this value if the schema name changes on the remote DB.
if ( ! defined( 'PD_DB_SCHEMA' ) ) {
    define( 'PD_DB_SCHEMA', 'beta_2' );
}

require_once $plugin_dir . 'includes/functions.php' ;
require_once $plugin_dir . 'includes/short_code_metaData.php' ;
require_once $plugin_dir . 'includes/short_code_client.php' ;
require_once $plugin_dir . 'includes/ar_member_usrID.php' ;
require_once $plugin_dir . 'includes/rest-presenters.php';
require_once $plugin_dir . 'includes/rest-sessions.php';
require_once $plugin_dir . 'includes/REST/membershome.php';
require_once $plugin_dir . 'includes/REST/memberspage.php';
require_once $plugin_dir . 'includes/REST/sessionhome.php';
require_once $plugin_dir . 'includes/REST/sessionhome2.php';
require_once $plugin_dir . 'includes/REST/sessionhome3.php';
require_once $plugin_dir . 'includes/REST/sessionhome4.php';
require_once $plugin_dir . 'includes/REST/sessionhome5.php';
require_once $plugin_dir . 'includes/REST/sessionhome6.php';
require_once $plugin_dir . 'includes/REST/sessionhome7.php';
require_once $plugin_dir . 'includes/REST/sessionhome12.php';
require_once $plugin_dir . 'includes/REST/sessionhome13.php';
require_once $plugin_dir . 'includes/REST/sessionhome8.php';
require_once $plugin_dir . 'includes/REST/sessionhome9.php';
require_once $plugin_dir . 'includes/REST/sessionhome11.php';
require_once $plugin_dir . 'includes/REST/sessionhome10.php';
require_once $plugin_dir . 'includes/REST/GET_presenters_table.php';
require_once $plugin_dir . 'includes/REST/GET_presenter_table_count.php';
require_once $plugin_dir . 'includes/REST/GET_attendee_table_count.php';
require_once $plugin_dir . 'includes/REST/GET_attendees_table.php';
require_once $plugin_dir . 'includes/REST/GET_session_table_count.php';
require_once $plugin_dir . 'includes/REST/POST_presenter.php';
require_once $plugin_dir . 'includes/REST/POST_attendee.php';
require_once $plugin_dir . 'includes/REST/GET_presenter_sessions.php';
require_once $plugin_dir . 'includes/REST/GET_member_admin_service.php';
require_once $plugin_dir . 'includes/REST/GET_member_me.php';
require_once $plugin_dir . 'includes/REST/PUT_session.php';
require_once $plugin_dir . 'includes/REST/PUT_session_presenters.php';
require_once $plugin_dir . 'includes/REST/PUT_member_mark_attendee.php';
require_once $plugin_dir . 'includes/REST/PUT_member_mark_presenter.php';
require_once $plugin_dir . 'includes/REST/PUT_member_link_wp.php';
require_once $plugin_dir . 'admin/main-page.php' ;
require_once $plugin_dir . 'admin/members-table.php' ;
require_once $plugin_dir . 'admin/member-page.php' ;
require_once $plugin_dir . 'admin/sessions-table.php' ;
require_once $plugin_dir . 'admin/session-page.php' ;
require_once $plugin_dir . 'admin/presenters-table.php' ;
// Presenter profile page retired; loader kept in tools/admin/presenters-table.php.retired

// Utility/test admin helper
require_once $plugin_dir . 'admin/skeleton2.php' ;

require_once $plugin_dir . 'includes/ApiRequestSigner.php' ;


// define( 'ASLTA_API_BASE_URL', 'https://aslta.parallelsolvit.com' );
// define( 'ASLTA_API_CLIENT_ID', 'wp-plugin' );
// define( 'ASLTA_API_SECRET', 'Wp5v2fRtVtjUUFmxkT-tMiCCPX1DJUrT5SvUq7WZfC-9mplRnp_O4BuDMNdayvViSBnNdd_RPJlOFPWeGLcFiw' );


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
        'profdef_home', 
        'Test Connection', 
        'Test Connection', 
        'manage_options', 
        'testing_connection_to_db', 
        'Testing_Connection_To_DB', 
        4 
    ) ;

    add_submenu_page( 
        null, 
        'Attendee Page', 
        'Attendee Page', 
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

    // Presenter profile page retired; keep slug unused for now.
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
                '0.8',
                'all'
            );
        }

        if ($_GET['page'] === 'profdef_members_table') {
            wp_enqueue_style(
                'PD-admin-members-table-css',
                plugin_dir_url(__FILE__) . 'css/PD-admin-members-table.css',
                array(),
                '0.12',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_sessions_table') {
            wp_enqueue_style(
                'PD-admin-sessions-table-css',
                plugin_dir_url(__FILE__) . 'css/PD-admin-sessions-table.css',
                array(),
                '0.77',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_presenters_table') {
            wp_enqueue_style(
                'PD-admin-presenters-table-css',
                plugin_dir_url( __FILE__ ) . 'css/PD-admin-presenter-table.css',
                array(),
                '0.14',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_member_page') {
            wp_enqueue_style(
                'PD-admin-member-page-css',
                plugin_dir_url( __FILE__ ) . 'css/PD-admin-member.css',
                array(),
                '0.8',
                'all'
            ) ;
        }

        if($_GET['page'] === 'profdef_session_page') {
            wp_enqueue_style(
                'PD-admin-session-page-css',
                plugin_dir_url( __FILE__) . 'css/PD-admin-session-page.css',
                array(),
                '0.2',
                'all'
            ) ;
        }

        // Presenter profile page CSS retired.
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
            '0.2',
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
            '0.6',
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
            '0.35',
            true
        );

        wp_localize_script(
            'PD-admin-members-table-js',
            'PDMembers',
            array(
                'ajaxurl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('pd_members_nonce'),
                'restRoot'  => esc_url_raw( rest_url( 'profdef/v2/' ) ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'attendeesCountRoute' => 'attendees/ct',
                'attendeesRoute' => 'attendees_table',
                'perPage' => 20,
            )
        );
    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_sessions_table') {
        // Load shared utilities first so table script can depend on them
        wp_enqueue_script(
            'PD-admin-sessions-utils-js',
            plugin_dir_url(__FILE__) . 'js/PD-sessions-utils.js',
            array(),
            '0.19',
            true
        );

        // Add Session modal logic (separate from table)
        wp_enqueue_script(
            'PD-admin-sessions-modal-js',
            plugin_dir_url(__FILE__) . 'js/PD-sessions-modal.js',
            array('jquery', 'PD-admin-sessions-utils-js'),
            '0.19',
            true
        );

        wp_enqueue_script(
            'PD-admin-sessions-table-js',
            plugin_dir_url( __FILE__) . 'js/PD-sessions-table.js',
            array('jquery', 'PD-admin-sessions-utils-js', 'PD-admin-sessions-modal-js'),
            '0.742',
            true
        );

        // Attendance modal (bulk register attendees)
        wp_enqueue_script(
            'PD-admin-attendance-modal-js',
            plugin_dir_url(__FILE__) . 'js/PD-attendance-modal.js',
            array('jquery', 'PD-admin-sessions-utils-js', 'PD-admin-sessions-table-js'),
            '0.25',
            true
        );

        
        wp_localize_script(
            'PD-admin-sessions-table-js',
            'PDSessions',
            array(
                'restRoot'       => esc_url_raw( rest_url( 'profdef/v2/' ) ),
                'sessionsRoute'  => 'sessionhome',
                'sessionsRoute2' => 'sessionhome2',
                'sessionsRoute3' => 'sessionhome3',
                'sessionsRoute4' => 'sessionhome4',
                'sessionsRoute5' => 'sessionhome5',
                'sessionsRoute6' => 'sessionhome6',
                'sessionsRoute7' => 'sessionhome7',
                'sessionsRoute12' => 'sessionhome12',
                'sessionsRoute13' => 'sessionhome13',
                'sessionsRoute8' => 'sessionhome8',
                'sessionsRoute9' => 'sessionhome9',
                'sessionsRoute10' => 'sessionhome10',
                'sessionsRoute11' => 'sessionhome11',
                'sessionsRoutePut' => 'session',
                'sessionsRoutePresenters' => 'session/presenters',
                'sessionsCountRoute' => 'sessions/ct',
                'nonce'          => wp_create_nonce( 'wp_rest' ),
                'detailPageBase' => admin_url( 'admin.php?page=profdef_session_page' ),
                'attendeeTTLms'  => 15000
            )
        );

    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_presenters_table') {

        // Enqueue script
        wp_enqueue_script(
            'PD-admin-presenters-table-js',
            plugins_url('js/PD-presenters-table.js', __FILE__),
            [], // no jquery needed
            37,
            true
        );

        // Pass REST info + nonce for authenticated requests
        wp_localize_script(
            'PD-admin-presenters-table-js',
            'PDPresenters',
            [
                'root'     => esc_url_raw( rest_url('profdef/v1/') ),
                'route'    => '/presenters',
                // v2 list for new presenters table view
                'listRoot' => esc_url_raw( rest_url('profdef/v2/') ),
                'listRoute'=> 'presenters_table',
                // v2 create presenter endpoint
                'postRoot' => esc_url_raw( rest_url('profdef/v2/') ),
                'postRoute'=> 'presenter',
                // v2 presenter sessions endpoint for Details
                'presenterSessionsRoute' => 'presenter/sessions',
                // v2 count endpoint for pagination labels
                'countRoot' => esc_url_raw( rest_url('profdef/v2/') ),
                'countRoute'=> 'presenters/ct',
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );

    }

    if (isset($_GET['page']) && $_GET['page'] === 'profdef_member_page') {
        wp_enqueue_script(
            'PD-admin-member-page-js',
            plugin_dir_url(__FILE__) . 'js/PD-Member-metadata.js',
            array('jquery'),
            '0.33',
            true
        );

    
        wp_localize_script(
            'PD-admin-member-page-js',
            'PDMembers',
            [
                'root'  => esc_url_raw( rest_url('profdef/v2/') ), // note trailing slash
                'route' => 'memberspage',
                'nonce' => wp_create_nonce('wp_rest'),
                // Prefer "member" param used by navigation; fallback to legacy "members_id"
                // This value is the external person_id from beta_2.person, not a WordPress user ID.
                'id'    => isset($_GET['member']) ? (int) $_GET['member'] : ( isset($_GET['members_id']) ? (int) $_GET['members_id'] : 0 ),
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
            '0.2',
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

    if ( is_singular() && has_shortcode( get_post()->post_content, 'PD_Member_Profile_Modal' ) ) {
        wp_enqueue_script(
            'PD-member-profile-modal-js',
            plugin_dir_url(__FILE__) . 'js/PD-member-profile-modal.js',
            array('jquery'),
            '0.2',
            true
        );

        wp_localize_script(
            'PD-member-profile-modal-js',
            'PDMemberProfile',
            array(
                'restRoot' => esc_url_raw( rest_url( 'profdef/v2/' ) ),
                'routeMe'  => 'member/me',
                'nonce'    => wp_create_nonce( 'wp_rest' ),
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

    // Public test modal shortcode
    add_shortcode('PD_User_Test_Modal', 'PD_user_test_modal_shortcode');

    // Public member profile modal shortcode
    add_shortcode('PD_Member_Profile_Modal', 'PD_member_profile_modal_shortcode');

}
add_action( 'init', 'PD_shortcode_init') ;


// 

// activation and deactivation hooks
register_activation_hook( __FILE__, function () {

}) ;

register_deactivation_hook(__FILE__, function () {

}) ;

// Shortcode handler: renders the public test modal markup
function PD_user_test_modal_shortcode($atts = [], $content = null, $tag = '') {
    ob_start();
    // Include the modal template from public path
    $modal_path = plugin_dir_path(__FILE__) . 'public/user-modal.php';
    if (file_exists($modal_path)) {
        include $modal_path;
    } else {
        echo '<!-- PD_User_Test_Modal: template not found -->';
    }
    return ob_get_clean();
}

// Shortcode handler: renders the public member profile modal markup
function PD_member_profile_modal_shortcode($atts = [], $content = null, $tag = '') {
    ob_start();
    $modal_path = plugin_dir_path(__FILE__) . 'public/member-profile-modal.php';
    if (file_exists($modal_path)) {
        include $modal_path;
    } else {
        echo '<!-- PD_Member_Profile_Modal: template not found -->';
    }
    return ob_get_clean();
}
