<?php
/**
 * Plugin Name: ASLTA Attendees Endpoint
 * Description: Exposes a REST API endpoint that returns session attendees as [["Name","email"], ...] using a MariaDB stored procedure.
 * Version:     1.0.0
 * Author:      You
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
    $ns = 'profdef/v2';

	register_rest_route(
		'profdef/v2',
		'/sessionhome2',
		[
			'methods'             => 'GET',
			'callback'            => 'aslta_get_session_attendees_by_query',
			'permission_callback' => 'pd_presenters_permission',
			'args'                => [
				'sessionid' => [
					'description' => 'Session ID (provided as sessionid query param)',
					'type'        => 'integer',
					'required'    => true,
				],
			],
		]
	);
} );

function aslta_get_session_attendees_by_query( WP_REST_Request $request ) {

	// 0) Require sessionid
	$member_id_param = $request->get_param( 'sessionid' );
	if ( $member_id_param === null || $member_id_param === '' ) {
		return new \WP_Error( 'bad_param', 'sessionid is required.', [ 'status' => 400 ] );
	}
	$session_id = (int) $member_id_param;
	if ( $session_id <= 0 ) {
		return new \WP_Error( 'bad_param', 'sessionid must be a positive integer.', [ 'status' => 400 ] );
	}

    // 1) Build attendees and backfill from WordPress users when needed
    $attendees = [];
    $missing_member_id = false;

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    try {
        $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
        $sql = sprintf( 'CALL %s.sp_get_session_attendees(%d);', $schema, $session_id );

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
            $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if ( is_readable( $skeleton_path ) ) {
                require_once $skeleton_path;
            }
        }

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            return new \WP_Error(
                'aslta_helper_missing',
                'Signed query helper is not available.',
                [ 'status' => 500 ]
            );
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new \WP_Error(
            'aslta_remote_error',
            'Failed to query session attendees via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new \WP_Error(
            'aslta_remote_http_error',
            'Remote session attendees endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( $result['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new \WP_Error(
            'aslta_json_error',
            'Failed to decode session attendees JSON response.',
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

        $uid = (int) ( $row['person_id'] ?? $row['members_id'] ?? $row['member_id'] ?? 0 );
        if ( $uid <= 0 ) {
            // Flag rows with missing member id; handled after draining results
            $missing_member_id = true;
        }

        $full_name = '';
        $email     = '';

        // Prefer external values
        if ( ! empty( $row['name'] ) ) {
            $full_name = trim( (string) $row['name'] );
        }
        if ( ! empty( $row['email'] ) ) {
            $email = trim( (string) $row['email'] );
        }

        // Backfill from WP if missing
        if ( ( $full_name === '' || $email === '' ) && $uid > 0 ) {
            $wp_user = get_userdata( $uid );
            if ( $wp_user instanceof WP_User ) {
                if ( $email === '' && ! empty( $wp_user->user_email ) ) {
                    $email = trim( (string) $wp_user->user_email );
                }
                if ( $full_name === '' ) {
                    $first = trim( (string) get_user_meta( $uid, 'first_name', true ) );
                    $last  = trim( (string) get_user_meta( $uid, 'last_name', true ) );
                    $combo = trim( $first . ' ' . $last );
                    if ( $combo !== '' ) {
                        $full_name = $combo;
                    } elseif ( ! empty( $wp_user->display_name ) ) {
                        $full_name = trim( (string) $wp_user->display_name );
                    } elseif ( ! empty( $wp_user->user_nicename ) ) {
                        $full_name = trim( (string) $wp_user->user_nicename );
                    }
                }
            }
        }

        // Certification Status from proc (may be null).
        // Support both legacy label and raw column name.
        $status = '';
        if ( array_key_exists( 'Certification Status', $row ) ) {
            $status = trim( (string) $row['Certification Status'] );
        } elseif ( array_key_exists( 'certification_status', $row ) ) {
            $status = trim( (string) $row['certification_status'] );
        }

        // Include attendee even if one of name/email is missing; keep both fields present
        // Return shape (array for backward compat): [ name, email, status, members_id ]
        if ( $full_name !== '' || $email !== '' ) {
            $attendees[] = [ $full_name, $email, $status, $uid ];
        }
    }

    // If nothing usable came back, return an empty array with 201 instead of erroring
    if ( empty( $attendees ) ) {
        return new WP_REST_Response( [], 201 );
    }

    // If some rows were missing members_id, still return what we could build
    // (composite PK should prevent this; keep as safety without failing the request)
    // 6) Respond with a plain array [["First Last","email","status",members_id], ...]
    return new WP_REST_Response( $attendees, 200 );
}
