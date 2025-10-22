<?php
/**
 * ProfDef REST: sessionhome3
 * Returns dropdown option data for Add Session form.
 * Endpoint: /wp-json/profdef/v2/sessionhome3
 *
 * Queries three tables in the external PD database:
 *  - type_of_session      -> [{ session_id, session_name }]
 *  - event_type           -> [{ event_id,   event_name   }]
 *  - ceu_consideration    -> [{ ceu_id,     ceu_name     }]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome3',
        [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => 'pd_sessionhome3_get_options',
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome3
 */
function pd_sessionhome3_get_options( WP_REST_Request $request ) {
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

    // Build fully-qualified table names using the configured DB name
    $db = str_replace( '`', '``', $name );
    $tbl_type = "`{$db}`.`type_of_session`";      // `db`.`type_of_session`
    $tbl_event = "`{$db}`.`event_type`";          // `db`.`event_type`
    $tbl_ceu  = "`{$db}`.`ceu_consideration`";    // `db`.`ceu_consideration`

    $session_types = [];
    $event_types   = [];
    $ceu_options   = [];

    // Query 1: session types
    $sql1 = "SELECT id AS session_id, name AS session_name FROM {$tbl_type}";
    if ( $res1 = $conn->query( $sql1 ) ) {
        while ( $row = $res1->fetch_assoc() ) {
            // Cast IDs to int
            $row['session_id'] = isset( $row['session_id'] ) ? (int) $row['session_id'] : 0;
            $session_types[] = $row;
        }
        $res1->free();
    } else {
        $err = WP_DEBUG ? $conn->error : null;
        $conn->close();
        return new WP_Error( 'profdef_query_failed', 'Failed to load session types.', [ 'status' => 500, 'debug' => $err ] );
    }

    // Query 2: event types
    $sql2 = "SELECT id AS event_id, name AS event_name FROM {$tbl_event}";
    if ( $res2 = $conn->query( $sql2 ) ) {
        while ( $row = $res2->fetch_assoc() ) {
            $row['event_id'] = isset( $row['event_id'] ) ? (int) $row['event_id'] : 0;
            $event_types[] = $row;
        }
        $res2->free();
    } else {
        $err = WP_DEBUG ? $conn->error : null;
        $conn->close();
        return new WP_Error( 'profdef_query_failed', 'Failed to load event types.', [ 'status' => 500, 'debug' => $err ] );
    }

    // Query 3: CEU considerations
    $sql3 = "SELECT id AS ceu_id, name AS ceu_name FROM {$tbl_ceu}";
    if ( $res3 = $conn->query( $sql3 ) ) {
        while ( $row = $res3->fetch_assoc() ) {
            $row['ceu_id'] = isset( $row['ceu_id'] ) ? (int) $row['ceu_id'] : 0;
            $ceu_options[] = $row;
        }
        $res3->free();
    } else {
        $err = WP_DEBUG ? $conn->error : null;
        $conn->close();
        return new WP_Error( 'profdef_query_failed', 'Failed to load CEU considerations.', [ 'status' => 500, 'debug' => $err ] );
    }

    $conn->close();

    $payload = [
        'session_types'      => $session_types,
        'event_types'        => $event_types,
        'ceu_considerations' => $ceu_options,
    ];

    return new WP_REST_Response( $payload, 200 );
}
