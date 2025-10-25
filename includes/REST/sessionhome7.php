<?php
/**
 * ProfDef REST: sessionhome7
 * Purpose: Return distinct parent events from external DB.
 * Endpoint: GET /wp-json/profdef/v2/sessionhome7
 * SQL: SELECT DISTINCT specific_event FROM Test_Database.session ORDER BY specific_event;
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome7',
        [
            'methods'             => WP_REST_Server::READABLE, // GET
            // 'permission_callback' => '__return_true',
            'permission_callback' => 'pd_sessions_permission',
            'callback'            => 'pd_sessionhome7_get_parent_events',
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome7
 */
function pd_sessionhome7_get_parent_events( WP_REST_Request $request ) {
    // Decrypt external DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );

    if ( ! $host || ! $name || ! $user ) {
        return new WP_Error(
            'profdef_db_creds_missing',
            'Database credentials are not configured.',
            [ 'status' => 500 ]
        );
    }

    // Connect to external DB
    $conn = @new mysqli( $host, $user, $pass, $name );
    if ( $conn->connect_error ) {
        return new WP_Error(
            'mysql_not_connect',
            'Database connection failed.',
            [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->connect_error : null ]
        );
    }
    $conn->set_charset( 'utf8mb4' );

    // Query distinct specific_event values (escape DB + reserved table name)
    // Keep SQL identical to original intent; do not TRIM or filter in SQL
    $db  = str_replace('`', '``', $name);
    $tbl = "`{$db}`.`session`";
    $sql = "SELECT DISTINCT specific_event FROM {$tbl} ORDER BY specific_event";

    $events = [];
    if ( $res = $conn->query( $sql ) ) {
        while ( $row = $res->fetch_assoc() ) {
            if ( array_key_exists( 'specific_event', $row ) ) {
                // Drop empty-string values; preserve others exactly as returned by SQL
                $val = $row['specific_event'];
                if (is_string($val) && $val !== '') {
                    $events[] = $val;
                }
            }
        }
        $res->free();
    } else {
        $err = WP_DEBUG ? $conn->error : null;
        $conn->close();
        return new WP_Error( 'profdef_query_failed', 'Failed to load parent events.', [ 'status' => 500, 'debug' => $err ] );
    }

    $conn->close();

    return new WP_REST_Response( $events, 200 );
}
