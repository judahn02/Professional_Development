<?php
/**
 * ProfDef REST: sessionhome12
 * Purpose: Return distinct organizer values from external DB.
 * Endpoint: GET /wp-json/profdef/v2/sessionhome12
 * SQL: SELECT DISTINCT organizer FROM {schema}.sessions ORDER BY organizer;
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome12',
        [
            'methods'             => WP_REST_Server::READABLE, // GET
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_sessionhome12_get_organizers',
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome12
 */
function pd_sessionhome12_get_organizers( WP_REST_Request $request ) {
    $organizers = [];

    $schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';
    $sql    = 'SELECT DISTINCT organizer FROM ' . $schema . '.sessions WHERE organizer IS NOT NULL AND TRIM(organizer) <> \'\' ORDER BY organizer';

    try {
        if ( ! function_exists( 'aslta_signed_query' ) ) {
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
            'Failed to load organizers via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote organizers endpoint returned an HTTP error.',
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
            'Failed to decode organizers JSON response.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
            ]
        );
    }

    // Normalise to a list of row arrays.
    if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
        $rows_raw = $decoded['rows'];
    } elseif ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
        $rows_raw = $decoded;
    } else {
        $rows_raw = [ $decoded ];
    }

    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        if ( array_key_exists( 'organizer', $row ) ) {
            $val = $row['organizer'];
            if ( is_string( $val ) && $val !== '' ) {
                $organizers[] = $val;
            }
        }
    }

    return new WP_REST_Response( $organizers, 200 );
}

