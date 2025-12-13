<?php
/**
 * ProfDef REST: sessionhome13
 * Purpose: Return latest certification status for a person (from attending table).
 * Endpoint: GET /wp-json/profdef/v2/sessionhome13?person_id=123
 * SQL: CALL {schema}.GET_Latest_Cert_Status(person_id);
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome13',
        [
            'methods'             => WP_REST_Server::READABLE, // GET
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_sessionhome13_get_latest_cert_status',
            'args'                => [
                'person_id' => [
                    'description' => 'Person ID (person.id) to lookup latest certification_status for.',
                    'type'        => 'integer',
                    'required'    => true,
                ],
            ],
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome13
 */
function pd_sessionhome13_get_latest_cert_status( WP_REST_Request $request ) {
    $person_id = (int) $request->get_param( 'person_id' );
    if ( $person_id <= 0 ) {
        return new WP_Error( 'bad_param', 'person_id must be a positive integer.', [ 'status' => 400 ] );
    }

    $schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';
    $sql    = sprintf( 'CALL %s.GET_Latest_Cert_Status(%d);', $schema, $person_id );

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
            'Failed to load latest certification status via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote latest cert status endpoint returned an HTTP error.',
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
            'Failed to decode latest cert status JSON response.',
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

    $row0 = null;
    foreach ( $rows_raw as $row ) {
        if ( is_array( $row ) ) {
            $row0 = $row;
            break;
        }
    }

    if ( ! is_array( $row0 ) ) {
        return new WP_REST_Response(
            [
                'person_id'             => $person_id,
                'certification_status'  => '',
                'created_at'            => null,
                'sessions_id'           => null,
            ],
            200
        );
    }

    return new WP_REST_Response(
        [
            'person_id'            => isset( $row0['person_id'] ) ? (int) $row0['person_id'] : $person_id,
            'certification_status' => isset( $row0['certification_status'] ) ? (string) $row0['certification_status'] : '',
            'created_at'           => $row0['created_at'] ?? null,
            'sessions_id'          => isset( $row0['sessions_id'] ) ? (int) $row0['sessions_id'] : null,
        ],
        200
    );
}

