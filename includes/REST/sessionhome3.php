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
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_sessionhome3_get_options',
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome3
 */

/**
 * Execute a read-only SQL query against the external PD database via the signed API.
 *
 * @param string $sql
 * @return array|\WP_Error
 */
function pd_sessionhome3_remote_query( string $sql ) {
    try {
        if ( ! function_exists( 'aslta_signed_query' ) ) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
            $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if ( is_readable( $skeleton_path ) ) {
                require_once $skeleton_path;
            }
        }

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            return new WP_Error(
                'aslta_helper_missing',
                'Signed query helper is not available.',
                [ 'status' => 500 ]
            );
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to query options via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote options endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( $result['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'aslta_json_error',
            'Failed to decode options JSON response.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
            ]
        );
    }

    if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
        return $decoded['rows'];
    }

    if ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
        return $decoded;
    }

    return [ $decoded ];
}

function pd_sessionhome3_get_options( WP_REST_Request $request ) {
    $session_types = [];
    $event_types   = [];
    $ceu_options   = [];

    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';

    // Remote query 1: session types
    $rows1 = pd_sessionhome3_remote_query(
        sprintf(
            'SELECT id AS session_id, name AS session_name FROM %s.session_type;',
            $schema
        )
    );
    if ( is_wp_error( $rows1 ) ) {
        return $rows1;
    }
    foreach ( $rows1 as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $row['session_id'] = isset( $row['session_id'] ) ? (int) $row['session_id'] : 0;
        $session_types[]   = $row;
    }

    // Remote query 2: event types
    $rows2 = pd_sessionhome3_remote_query(
        sprintf(
            'SELECT id AS event_id, name AS event_name FROM %s.event_type;',
            $schema
        )
    );
    if ( is_wp_error( $rows2 ) ) {
        return $rows2;
    }
    foreach ( $rows2 as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $row['event_id'] = isset( $row['event_id'] ) ? (int) $row['event_id'] : 0;
        $event_types[]   = $row;
    }

    // Remote query 3: CEU considerations
    $rows3 = pd_sessionhome3_remote_query(
        sprintf(
            'SELECT id AS ceu_id, name AS ceu_name FROM %s.ceu_type;',
            $schema
        )
    );
    if ( is_wp_error( $rows3 ) ) {
        return $rows3;
    }
    foreach ( $rows3 as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $row['ceu_id']    = isset( $row['ceu_id'] ) ? (int) $row['ceu_id'] : 0;
        $ceu_options[]    = $row;
    }

    $payload = [
        'session_types'       => $session_types,
        'event_types'         => $event_types,
        // Front-end expects `ceu_considerations`; keep `ceu_types` as a backwards-compatible alias.
        'ceu_considerations'  => $ceu_options,
        'ceu_types'           => $ceu_options,
    ];

    return new WP_REST_Response( $payload, 200 );
}
