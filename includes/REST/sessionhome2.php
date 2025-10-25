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
			'permission_callback' => '__return_true',
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

	// 1) Decrypt external DB creds
	$host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
	$name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
	$user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
	$pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );

	if ( ! $host || ! $name || ! $user ) {
		return new \WP_Error(
			'profdef_db_creds_missing',
			'Database credentials are not configured.',
			[ 'status' => 500 ]
		);
	}

	// 2) Connect to the EXTERNAL DB (not $wpdb)
	$conn = @new mysqli( $host, $user, $pass, $name );
	if ( $conn->connect_error ) {
		return new \WP_Error(
			'mysql_not_connect',
			'Database connection failed.',
			[
				'status' => 500,
				'debug'  => WP_DEBUG ? $conn->connect_error : null,
			]
		);
	}
	$conn->set_charset( 'utf8mb4' );

	// 3) Prepare + execute stored procedure (REQUIRES sessionid)
	$sql  = 'CALL sp_get_session_attendees(?)';
	$stmt = $conn->prepare( $sql );
	if ( ! $stmt ) {
		$conn->close();
		return new \WP_Error(
			'profdef_prepare_failed',
			'Failed to prepare stored procedure.',
			[
				'status' => 500,
				'debug'  => WP_DEBUG ? $conn->error : null,
			]
		);
	}
	$stmt->bind_param( 'i', $session_id );

	if ( ! $stmt->execute() ) {
		$err = $stmt->error ?: $conn->error;
		$stmt->close();
		$conn->close();
		return new \WP_Error(
			'profdef_execute_failed',
			'Failed to execute stored procedure.',
			[
				'status' => 500,
				'debug'  => WP_DEBUG ? $err : null,
			]
		);
	}

	// 4) Build attendees and backfill from WordPress users when needed
	$attendees = [];

	if ( $result = $stmt->get_result() ) {
		while ( $row = $result->fetch_assoc() ) {
			$uid = (int) ( $row['members_id'] ?? $row['member_id'] ?? 0 );

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

			// Certification Status from proc (may be null)
			$status = '';
			if ( array_key_exists( 'Certification Status', $row ) ) {
				$status = trim( (string) $row['Certification Status'] );
			}

			// Include attendee even if one of name/email is missing; keep both fields present
			if ( $full_name !== '' || $email !== '' ) {
				$attendees[] = [ $full_name, $email, $status ];
			}
		}
		$result->free();
	}

	// 5) Drain any additional result sets using the statement API
	while ( $stmt->more_results() && $stmt->next_result() ) {
		if ( $extra = $stmt->get_result() ) {
			$extra->free();
		}
	}

	$stmt->close();
	$conn->close();

	// 6) Respond with a plain array [["First Last","email"], ...]
	return new WP_REST_Response( $attendees, 200 );
}
