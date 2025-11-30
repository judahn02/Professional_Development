<?php
/**
 * ProfDef REST: GET member "me"
 * Endpoint: GET /wp-json/profdef/v2/member/me
 *
 * Uses the current logged-in WordPress user (ARMember account) to resolve
 * the linked external person row (PD_DB_SCHEMA.person.wp_id), and returns:
 * {
 *   person: { id, first_name, last_name, email, phone_number, wp_id },
 *   sessions: [...],        // same shape as memberspage "sessions"
 *   admin_service: [...]    // same shape as GET_member_admin_service
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'profdef/v2',
			'/member/me',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'pd_member_me_permission',
				'callback'            => 'pd_get_member_me',
			]
		);
	}
);

/**
 * Permission callback: require logged-in user + valid REST nonce.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function pd_member_me_permission( WP_REST_Request $request ) {
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Bad or missing nonce.',
			[ 'status' => 403 ]
		);
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in to view your Professional Development record.',
			[ 'status' => 401 ]
		);
	}

	return true;
}

/**
 * Resolve the current user's external person row and load their sessions
 * and administrative service entries.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function pd_get_member_me( WP_REST_Request $request ) {
	$wp_id = get_current_user_id();
	if ( ! $wp_id || $wp_id <= 0 ) {
		return new WP_Error(
			'pd_not_logged_in',
			'You must be logged in to view your Professional Development record.',
			[ 'status' => 401 ]
		);
	}

	// 1) Resolve external person via wp_id.
	$schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';
	$sql    = sprintf(
		'SELECT id, first_name, last_name, email, phone_number, wp_id FROM %s.person WHERE wp_id = %d LIMIT 1;',
		$schema,
		$wp_id
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
			'Failed to resolve member via remote API.',
			[
				'status' => 500,
				'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
			]
		);
	}

	if ( $result['status'] < 200 || $result['status'] >= 300 ) {
		return new WP_Error(
			'aslta_remote_http_error',
			'Remote member lookup endpoint returned an HTTP error.',
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
			'Failed to decode member lookup JSON response.',
			[
				'status' => 500,
				'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
			]
		);
	}

	if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
		$rows_raw = $decoded['rows'];
	} elseif ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
		$rows_raw = $decoded;
	} else {
		$rows_raw = [ $decoded ];
	}

	$person_row = null;
	foreach ( $rows_raw as $row ) {
		if ( is_array( $row ) ) {
			$person_row = $row;
			break;
		}
	}

	if ( ! $person_row || empty( $person_row['id'] ) ) {
		return new WP_Error(
			'pd_not_linked',
			'Ask administrator to link your ARMember account to the Professional Development Database.',
			[ 'status' => 404 ]
		);
	}

	$person_id = (int) $person_row['id'];

	$person = [
		'id'           => $person_id,
		'first_name'   => isset( $person_row['first_name'] ) ? (string) $person_row['first_name'] : '',
		'last_name'    => isset( $person_row['last_name'] ) ? (string) $person_row['last_name'] : '',
		'email'        => isset( $person_row['email'] ) ? (string) $person_row['email'] : '',
		'phone_number' => isset( $person_row['phone_number'] ) ? (string) $person_row['phone_number'] : '',
		'wp_id'        => isset( $person_row['wp_id'] ) ? (int) $person_row['wp_id'] : 0,
	];

	// 2) Load sessions via existing memberspage callback.
	$sessions = [];
	if ( function_exists( 'pd_members_page_callback' ) ) {
		$req_sessions = new WP_REST_Request( 'GET', '/profdef/v2/memberspage' );
		$req_sessions->set_param( 'members_id', $person_id );
		$resp_sessions = pd_members_page_callback( $req_sessions );

		if ( is_wp_error( $resp_sessions ) ) {
			return $resp_sessions;
		}
		if ( $resp_sessions instanceof WP_REST_Response ) {
			$data = $resp_sessions->get_data();
			if ( is_array( $data ) && isset( $data['sessions'] ) && is_array( $data['sessions'] ) ) {
				$sessions = $data['sessions'];
			}
		}
	}

	// 3) Load administrative service via existing handler.
	$admin_service = [];
	if ( function_exists( 'pd_get_member_admin_service' ) ) {
		$req_admin = new WP_REST_Request( 'GET', '/profdef/v2/member/administrative_service' );
		$req_admin->set_param( 'members_id', $person_id );
		$resp_admin = pd_get_member_admin_service( $req_admin );

		if ( is_wp_error( $resp_admin ) ) {
			// Do not fail the whole call; surface sessions + person even if admin service fails.
			$admin_service = [];
		} elseif ( $resp_admin instanceof WP_REST_Response ) {
			$data_admin = $resp_admin->get_data();
			if ( is_array( $data_admin ) ) {
				$admin_service = $data_admin;
			}
		}
	}

	return new WP_REST_Response(
		[
			'person'         => $person,
			'sessions'       => $sessions,
			'admin_service'  => $admin_service,
		],
		200
	);
}

