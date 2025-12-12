<?php
/**
 * ProfDef REST: GET member administrative service
 * Endpoint: GET /wp-json/profdef/v2/member/administrative_service?members_id=123
 * Calls: CALL Test_Database.GET_presenter_administrative_service(p_members_id)
 * Returns: [{ start_service, end_service, type, ceu_weight }, ...]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/member/administrative_service',
        [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_get_member_admin_service',
            'args'                => [
                'members_id' => [
                    'description' => 'WordPress user ID (members_id) to fetch administrative service for.',
                    'type'        => 'integer',
                    'required'    => true,
                ],
            ],
        ]
    );
} );

function pd_get_member_admin_service( WP_REST_Request $request ) {
    $mid = (int) $request->get_param( 'members_id' );
    if ( $mid <= 0 ) {
        return new WP_Error( 'bad_param', 'members_id must be a positive integer.', [ 'status' => 400 ] );
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    try {
        // Build stored-procedure call as a simple SQL string; members_id is already validated/int-cast.
        $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
        $sql = sprintf( 'CALL %s.GET_presenter_administrative_service(%d);', $schema, $mid );

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
            $plugin_root  = dirname( dirname( __DIR__ ) ); // .../Professional_Development
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
            'Failed to query administrative service via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote administrative service returned an HTTP error.',
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
            'Failed to decode administrative service JSON response.',
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
        // Already a list
        $rows_raw = $decoded;
    } else {
        // Single object or unexpected shape; wrap in an array so downstream code still works.
        $rows_raw = [ $decoded ];
    }

    $rows = [];
    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        // Support both the canonical API field names and raw DB column names.
        $start_raw = null;
        if ( array_key_exists( 'start_service', $row ) ) {
            $start_raw = $row['start_service'];
        } elseif ( array_key_exists( 'start_date', $row ) ) {
            $start_raw = $row['start_date'];
        }

        $end_raw = null;
        if ( array_key_exists( 'end_service', $row ) ) {
            $end_raw = $row['end_service'];
        } elseif ( array_key_exists( 'end_date', $row ) ) {
            $end_raw = $row['end_date'];
        }

        $type_raw = '';
        if ( array_key_exists( 'type', $row ) ) {
            $type_raw = $row['type'];
        } elseif ( array_key_exists( 'serving_type', $row ) ) {
            $type_raw = $row['serving_type'];
        }

        $ceu_raw = array_key_exists( 'ceu_weight', $row ) ? $row['ceu_weight'] : '';

        $rows[] = [
            'start_service' => ( $start_raw === null ? null : (string) $start_raw ),
            'end_service'   => ( $end_raw === null ? null : (string) $end_raw ),
            'type'          => (string) $type_raw,
            'ceu_weight'    => ( $ceu_raw === null ? '' : (string) $ceu_raw ),
        ];
    }

    return new WP_REST_Response( $rows, 200 );
}
