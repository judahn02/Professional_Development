<?php
/**
 * ProfDef REST: Link external person to ARMember account (WordPress user)
 * Endpoint: PUT /wp-json/profdef/v2/member/link_wp
 * Purpose: Set or clear beta_2.person.wp_id for an existing person row.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'profdef/v2',
            '/member/link_wp',
            [
                'methods'             => [ 'PUT', 'POST' ],
                'permission_callback' => 'pd_presenters_permission',
                'callback'            => 'pd_member_link_wp',
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
 * Handler: link/unlink an external person to an ARMember account via beta_2.person.wp_id.
 *
 * @param WP_REST_Request $request
 *
 * @return WP_REST_Response|WP_Error
 */
function pd_member_link_wp( WP_REST_Request $request ) {
    $json = (array) $request->get_json_params();

    $person_raw = null;
    if ( array_key_exists( 'person_id', $json ) ) {
        $person_raw = $json['person_id'];
    } elseif ( array_key_exists( 'members_id', $json ) ) {
        $person_raw = $json['members_id'];
    } elseif ( array_key_exists( 'id', $json ) ) {
        $person_raw = $json['id'];
    } else {
        $person_raw = $request->get_param( 'person_id' );
        if ( $person_raw === null ) {
            $person_raw = $request->get_param( 'members_id' );
        }
        if ( $person_raw === null ) {
            $person_raw = $request->get_param( 'id' );
        }
    }

    $person_id = (int) $person_raw;
    if ( $person_id <= 0 ) {
        return new WP_Error(
            'invalid_person_id',
            'person_id must be a positive integer.',
            [ 'status' => 400 ]
        );
    }

    $wp_raw = null;
    if ( array_key_exists( 'wp_id', $json ) ) {
        $wp_raw = $json['wp_id'];
    } else {
        $wp_raw = $request->get_param( 'wp_id' );
    }

    $wp_id = null;
    if ( $wp_raw !== null && $wp_raw !== '' ) {
        $wp_id = (int) $wp_raw;
        if ( $wp_id <= 0 ) {
            return new WP_Error(
                'invalid_wp_id',
                'wp_id must be a positive integer or null.',
                [ 'status' => 400 ]
            );
        }
        if ( ! get_userdata( $wp_id ) ) {
            return new WP_Error(
                'wp_user_not_found',
                'No ARMember account found with that ID.',
                [ 'status' => 404 ]
            );
        }
    }

    // Build UPDATE; when $wp_id is null we clear the link.
    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql    = sprintf(
        'UPDATE %s.person SET wp_id = %s WHERE id = %d;',
        $schema,
        $wp_id === null ? 'NULL' : (int) $wp_id,
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
            'Failed to update wp_id via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote link_wp endpoint returned an HTTP error.',
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
            'wp_id'     => $wp_id,
        ],
        200
    );
}
