<?php
/**
 * ProfDef REST: PUT_session_presenters
 * Endpoints:
 *   - GET  /wp-json/profdef/v2/session/presenters?session_id=123
 *       -> list presenters for a session (id + name/email/phone)
 *   - PUT  /wp-json/profdef/v2/session/presenters
 *       -> replace presenters for a session using presenter_ids[]
 *   - POST /wp-json/profdef/v2/session/presenters
 *       -> same as PUT (for clients that cannot send PUT)
 *
 * Storage:
 *   Uses beta_2.presenting (person_id, sessions_id) as the join table.
 *   All SQL is executed via aslta_signed_query() against the remote API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'profdef/v2',
			'/session/presenters',
			array(
				'methods'             => array( 'GET', 'PUT', 'POST' ),
				'callback'            => 'pd_session_presenters_route',
				'permission_callback' => function ( WP_REST_Request $request ) {
					// Reuse presenters permission helper when available; otherwise allow WP admin-only.
					if ( function_exists( 'pd_presenters_permission' ) ) {
						return pd_presenters_permission( $request );
					}
					$nonce = $request->get_header( 'X-WP-Nonce' );
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
						return new WP_Error( 'rest_forbidden', 'Bad or missing nonce.', array( 'status' => 403 ) );
					}
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id'    => array(
						'description' => 'Session id in beta_2.sessions.',
						'type'        => 'integer',
						'required'    => false, // Validated per-method in callback.
					),
					'presenter_ids' => array(
						/* phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine */
						'description'       => 'Array of presenter ids (beta_2.person.id) to assign to this session.',
						'type'              => 'array',
						'items'             => array(
							'type' => 'integer',
						),
						'required'          => false,
						'sanitize_callback' => function ( $value ) {
							if ( is_string( $value ) ) {
								// Accept simple CSV strings as a convenience.
								$parts = preg_split( '/[,\s]+/', $value );
							} elseif ( is_array( $value ) ) {
								$parts = $value;
							} else {
								$parts = array();
							}
							$out = array();
							foreach ( $parts as $v ) {
								$id = (int) $v;
								if ( $id > 0 ) {
									$out[ $id ] = $id; // de-dupe.
								}
							}
							return array_values( $out );
						},
					),
				),
			)
		);
	}
);

/**
 * Dispatch handler for /profdef/v2/session/presenters.
 *
 * GET  -> list presenters for a session.
 * PUT/POST -> replace presenters for a session.
 *
 * @param WP_REST_Request $request Request.
 *
 * @return WP_REST_Response|WP_Error
 */
function pd_session_presenters_route( WP_REST_Request $request ) {
	$method = strtoupper( $request->get_method() );
	if ( 'GET' === $method ) {
		return pd_get_session_presenters( $request );
	}
	if ( 'PUT' === $method || 'POST' === $method ) {
		return pd_put_session_presenters( $request );
	}

	return new WP_Error(
		'rest_method_not_allowed',
		'Method not allowed.',
		array( 'status' => 405 )
	);
}

/**
 * List presenters for a session.
 *
 * Response shape (array of presenters):
 * [
 *   { id, name, email, phone_number }
 * ]
 *
 * @param WP_REST_Request $request Request.
 *
 * @return WP_REST_Response|WP_Error
 */
function pd_get_session_presenters( WP_REST_Request $request ) {
	$session_id = (int) $request->get_param( 'session_id' );
	if ( $session_id <= 0 ) {
		return new WP_Error( 'bad_param', 'session_id must be a positive integer.', array( 'status' => 400 ) );
	}

	$sql = sprintf(
		'SELECT p.id, p.first_name, p.last_name, p.email, p.phone_number
         FROM beta_2.presenting AS pr
         INNER JOIN beta_2.person AS p ON p.id = pr.person_id
         WHERE pr.sessions_id = %d
         ORDER BY p.last_name, p.first_name, p.id;',
		$session_id
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
				array( 'status' => 500 )
			);
		}

		$result = aslta_signed_query( $sql );
	} catch ( \Throwable $e ) {
		return new WP_Error(
			'aslta_remote_error',
			'Failed to query session presenters via remote API.',
			array(
				'status' => 500,
				'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
			)
		);
	}

	if ( $result['status'] < 200 || $result['status'] >= 300 ) {
		return new WP_Error(
			'aslta_remote_http_error',
			'Remote session presenters endpoint returned an HTTP error.',
			array(
				'status' => 500,
				'debug'  => ( WP_DEBUG ? array( 'http_code' => $result['status'], 'body' => $result['body'] ) : null ),
			)
		);
	}

	$decoded = json_decode( $result['body'], true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error(
			'aslta_json_error',
			'Failed to decode session presenters JSON response.',
			array(
				'status' => 500,
				'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
			)
		);
	}

	if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
		$rows_raw = $decoded['rows'];
	} elseif ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
		$rows_raw = $decoded;
	} else {
		$rows_raw = array( $decoded );
	}

	$out = array();
	foreach ( $rows_raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$id        = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$first     = isset( $row['first_name'] ) ? (string) $row['first_name'] : '';
		$last      = isset( $row['last_name'] ) ? (string) $row['last_name'] : '';
		$name      = trim( $first . ' ' . $last );
		$email     = isset( $row['email'] ) ? (string) $row['email'] : '';
		$phone_raw = isset( $row['phone_number'] ) ? (string) $row['phone_number'] : '';

		if ( $id <= 0 ) {
			continue;
		}

		$out[] = array(
			'id'           => $id,
			'name'         => $name !== '' ? $name : $email,
			'email'        => $email !== '' ? $email : null,
			'phone_number' => $phone_raw !== '' ? $phone_raw : null,
		);
	}

	return new WP_REST_Response( $out, 200 );
}

/**
 * Extract a parameter from JSON body or request params.
 *
 * @param WP_REST_Request $req   Request.
 * @param array           $json  JSON params.
 * @param array|string    $keys  Keys to check in order.
 * @param mixed           $default Default when no key is present.
 *
 * @return mixed
 */
function pd_put_session_presenters_get( WP_REST_Request $req, array $json, $keys, $default = null ) {
	foreach ( (array) $keys as $k ) {
		if ( array_key_exists( $k, $json ) ) {
			return $json[ $k ];
		}
		$v = $req->get_param( $k );
		if ( null !== $v ) {
			return $v;
		}
	}
	return $default;
}

/**
 * Replace presenters for a session.
 *
 * Accepts JSON body or form params:
 * {
 *   "session_id": 123,
 *   "presenter_ids": [1,2,3]
 * }
 *
 * The presenter_ids array may be empty to clear presenters for the session.
 *
 * @param WP_REST_Request $request Request.
 *
 * @return WP_REST_Response|WP_Error
 */
function pd_put_session_presenters( WP_REST_Request $request ) {
	$json = (array) $request->get_json_params();

	$session_id_raw = pd_put_session_presenters_get( $request, $json, array( 'session_id', 'sessionId' ), 0 );
	$session_id     = (int) $session_id_raw;

	if ( $session_id <= 0 ) {
		return new WP_Error( 'bad_param', 'session_id must be a positive integer.', array( 'status' => 400 ) );
	}

	$presenters_raw = pd_put_session_presenters_get(
		$request,
		$json,
		array( 'presenter_ids', 'presenters', 'presenters_ids' ),
		array()
	);

	// Normalize presenter_ids to a de-duplicated list of positive ints.
	if ( is_string( $presenters_raw ) ) {
		$parts = preg_split( '/[,\s]+/', $presenters_raw );
	} elseif ( is_array( $presenters_raw ) ) {
		$parts = $presenters_raw;
	} else {
		$parts = array();
	}

	$presenter_ids = array();
	foreach ( $parts as $val ) {
		if ( is_array( $val ) && isset( $val['id'] ) ) {
			$id = (int) $val['id'];
		} else {
			$id = (int) $val;
		}
		if ( $id > 0 ) {
			$presenter_ids[ $id ] = $id;
		}
	}
	$presenter_ids = array_values( $presenter_ids );

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
				array( 'status' => 500 )
			);
		}

		// Optional existence check to return 404 for unknown sessions.
		$sql_check = sprintf( 'SELECT id FROM beta_2.sessions WHERE id = %d;', $session_id );
		$result    = aslta_signed_query( $sql_check );
		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return new WP_Error(
				'aslta_remote_http_error',
				'Remote sessions endpoint returned an HTTP error during presenters update.',
				array(
					'status' => 500,
					'debug'  => ( WP_DEBUG ? array( 'http_code' => $result['status'], 'body' => $result['body'] ) : null ),
				)
			);
		}

		$decoded = json_decode( $result['body'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'aslta_json_error',
				'Failed to decode session existence JSON response.',
				array(
					'status' => 500,
					'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
				)
			);
		}

		if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
			$rows_raw = $decoded['rows'];
		} elseif ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
			$rows_raw = $decoded;
		} else {
			$rows_raw = array( $decoded );
		}

		$found = false;
		foreach ( $rows_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['id'] ) && (int) $row['id'] === $session_id ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error(
				'rest_not_found',
				'Session not found.',
				array( 'status' => 404 )
			);
		}

		// 1) Clear existing presenter links for this session.
		$sql_delete = sprintf( 'DELETE FROM beta_2.presenting WHERE sessions_id = %d;', $session_id );
		$del_result = aslta_signed_query( $sql_delete );
		if ( $del_result['status'] < 200 || $del_result['status'] >= 300 ) {
			return new WP_Error(
				'aslta_remote_http_error',
				'Remote presenter delete endpoint returned an HTTP error.',
				array(
					'status' => 500,
					'debug'  => ( WP_DEBUG ? array( 'http_code' => $del_result['status'], 'body' => $del_result['body'] ) : null ),
				)
			);
		}

		// 2) Insert new links when any presenter_ids are provided.
		if ( ! empty( $presenter_ids ) ) {
			$values = array();
			foreach ( $presenter_ids as $pid ) {
				$values[] = sprintf( '(%d, %d)', (int) $pid, $session_id );
			}
			$sql_insert = 'INSERT INTO beta_2.presenting (person_id, sessions_id) VALUES ' . implode( ', ', $values ) . ';';
			$ins_result = aslta_signed_query( $sql_insert );
			if ( $ins_result['status'] < 200 || $ins_result['status'] >= 300 ) {
				return new WP_Error(
					'aslta_remote_http_error',
					'Remote presenter insert endpoint returned an HTTP error.',
					array(
						'status' => 500,
						'debug'  => ( WP_DEBUG ? array( 'http_code' => $ins_result['status'], 'body' => $ins_result['body'] ) : null ),
					)
				);
			}
		}
	} catch ( \Throwable $e ) {
		return new WP_Error(
			'aslta_remote_error',
			'Failed to update session presenters via remote API.',
			array(
				'status' => 500,
				'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
			)
		);
	}

	return new WP_REST_Response(
		array(
			'success'       => true,
			'session_id'    => (int) $session_id,
			'presenter_ids' => $presenter_ids,
		),
		200
	);
}

