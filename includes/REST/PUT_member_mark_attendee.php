<?php
/**
 * ProfDef REST: Mark existing person as attendee
 * Endpoint: PUT /wp-json/profdef/v2/member/mark_attendee
 * Purpose: Flip beta_2.person.attendee from 0 to 1 for an existing person row.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'profdef/v2',
            '/member/mark_attendee',
            [
                'methods'             => [ 'PUT', 'POST' ],
                'permission_callback' => 'pd_presenters_permission',
                'callback'            => 'pd_member_mark_attendee',
                'args'                => [
                    'person_id' => [
                        'description' => 'External person_id from beta_2.person.',
                        'type'        => 'integer',
                        'required'    => true,
                    ],
                ],
            ]
        );
    }
);

/**
 * Handler: mark an existing person as attendee (attendee = 1).
 *
 * @param WP_REST_Request $request
 *
 * @return WP_REST_Response|WP_Error
 */
function pd_member_mark_attendee( WP_REST_Request $request ) {
    $json = (array) $request->get_json_params();

    $id_raw = null;
    if ( array_key_exists( 'person_id', $json ) ) {
        $id_raw = $json['person_id'];
    } elseif ( array_key_exists( 'members_id', $json ) ) {
        $id_raw = $json['members_id'];
    } elseif ( array_key_exists( 'id', $json ) ) {
        $id_raw = $json['id'];
    } else {
        $id_raw = $request->get_param( 'person_id' );
        if ( $id_raw === null ) {
            $id_raw = $request->get_param( 'members_id' );
        }
        if ( $id_raw === null ) {
            $id_raw = $request->get_param( 'id' );
        }
    }

    $person_id = (int) $id_raw;
    if ( $person_id <= 0 ) {
        return new WP_Error(
            'invalid_person_id',
            'person_id must be a positive integer.',
            [ 'status' => 400 ]
        );
    }

    // Build a simple UPDATE statement; if the row already has attendee=1
    // this is effectively a no-op.
    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql    = sprintf(
        'UPDATE %s.person SET attendee = 1 WHERE id = %d;',
        $schema,
        $person_id
    );

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
            'Failed to update attendee flag via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote attendee update endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    return new WP_REST_Response(
        [
            'success'   => true,
            'person_id' => $person_id,
        ],
        200
    );
}
