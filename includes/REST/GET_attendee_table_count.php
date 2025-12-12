<?php
/**
 * ProfDef REST: GET_attendee_table_count
 * Endpoint: GET /wp-json/profdef/v2/attendees/ct
 * Purpose: Return total count of attendees from beta_2.GET_attendees_table_count view (via signed API).
 *
 * Returns:
 * { "count": <int> }
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/attendees/ct',
        [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_get_attendees_table_count',
        ]
    );
} );

function pd_get_attendees_table_count( WP_REST_Request $request ) {
    try {
        $schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';
        $sql    = sprintf( 'SELECT * FROM %s.GET_attendees_table_count;', $schema );

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if ( is_readable( $skeleton_path ) ) {
                require_once $skeleton_path;
            }
        }

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            return new WP_Error( 'aslta_helper_missing', 'Signed query helper is not available.', [ 'status' => 500 ] );
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to query attendees count via remote API.',
            [ 'status' => 500, 'debug' => ( WP_DEBUG ? $e->getMessage() : null ) ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote attendees count endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( (string) ( $result['body'] ?? '' ), true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'aslta_json_error',
            'Failed to decode attendees count JSON response.',
            [ 'status' => 500, 'debug' => ( WP_DEBUG ? json_last_error_msg() : null ) ]
        );
    }

    $rows = [];
    if ( is_array( $decoded ) ) {
        if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
            $rows = $decoded['rows'];
        } elseif ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
            $rows = $decoded;
        } else {
            $rows = [ $decoded ];
        }
    }

    $count = 0;
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        foreach ( $row as $v ) {
            if ( is_numeric( $v ) ) {
                $count = (int) $v;
                break 2;
            }
        }
    }

    return new WP_REST_Response( [ 'count' => $count ], 200 );
}

