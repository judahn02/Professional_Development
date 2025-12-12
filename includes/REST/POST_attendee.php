<?php
/**
 * ProfDef REST: POST_attendee
 * Endpoint: POST /wp-json/profdef/v2/attendee
 * Purpose: Create an attendee/member via stored procedure beta_2.POST_attendee (signed API).
 *
 * Accepts JSON (or form) params:
 * - first_name (string, optional but at least one of first_name/last_name required)
 * - last_name  (string, optional but at least one of first_name/last_name required)
 * - email      (string, optional; NULL allowed)
 * - phone      (string, optional; NULL allowed)
 *
 * Returns (201 on success):
 * {
 *   "success": true,
 *   "id":        <int>,   // new member id
 *   "first_name": "...",
 *   "last_name":  "...",
 *   "email":      "... or null",
 *   "phone_number": "... or null",
 *   "attendee":   1,
 *   "presenter":  0
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/attendee',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'permission_callback' => 'pd_presenters_permission', // reuse REST nonce + admin capability
            'callback'            => 'pd_post_attendee_create',
            'args'                => [
                'first_name' => [ 'type' => 'string', 'required' => true ],
                'last_name'  => [ 'type' => 'string', 'required' => true ],
                'email'      => [ 'type' => 'string', 'required' => false ],
                'phone'      => [ 'type' => 'string', 'required' => false ],
            ],
        ]
    );
} );

/**
 * Helper to fetch a parameter from JSON body or request params (first non-null wins).
 *
 * @param WP_REST_Request $req
 * @param array           $json
 * @param string|array    $keys
 * @param mixed           $default
 *
 * @return mixed
 */
function pd_post_attendee_get( WP_REST_Request $req, array $json, $keys, $default = null ) {
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
 * Minimal SQL string escaper for values embedded into a CALL statement.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string|null $value
 * @return string SQL literal or NULL
 */
function pd_post_attendee_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

/**
 * Turn a decoded aslta response into a list of rows for inspection.
 *
 * @param mixed $decoded Parsed JSON from a signed query result.
 * @return array<int, mixed> Row arrays.
 */
function pd_post_attendee_extract_rows( $decoded ): array {
    if ( is_array( $decoded ) ) {
        if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
            return $decoded['rows'];
        }
        if ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
            return $decoded;
        }
        return [ $decoded ];
    }
    return [];
}

/**
 * Create attendee via beta_2.POST_attendee.
 *
 * Stored procedure signature:
 *   POST_attendee(
 *     IN  p_first_name  VARCHAR(45),
 *     IN  p_last_name   VARCHAR(45),
 *     IN  p_email       VARCHAR(254),
 *     IN  p_phone       VARCHAR(20),
 *     OUT p_idMember    INT
 *   )
 */
function pd_post_attendee_create( WP_REST_Request $req ) {
    $json = (array) $req->get_json_params();

    // Collect + sanitize inputs
    $first_in = pd_post_attendee_get( $req, $json, [ 'first_name', 'firstname' ], '' );
    $last_in  = pd_post_attendee_get( $req, $json, [ 'last_name', 'lastname' ], '' );
    $email_in = pd_post_attendee_get( $req, $json, [ 'email' ], '' );
    $phone_in = pd_post_attendee_get( $req, $json, [ 'phone', 'phone_number' ], '' );

    $first = sanitize_text_field( wp_unslash( (string) $first_in ) );
    $last  = sanitize_text_field( wp_unslash( (string) $last_in ) );

    // Enforce reasonable length limits for DB columns
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $first ) > 45 ) {
            $first = mb_substr( $first, 0, 45 );
        }
        if ( mb_strlen( $last ) > 45 ) {
            $last = mb_substr( $last, 0, 45 );
        }
    } else {
        if ( strlen( $first ) > 45 ) {
            $first = substr( $first, 0, 45 );
        }
        if ( strlen( $last ) > 45 ) {
            $last = substr( $last, 0, 45 );
        }
    }

    // Email: optional, validate if present
    $email = sanitize_email( wp_unslash( (string) $email_in ) );
    if ( $email === '' ) {
        $email = null;
    } else {
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_request', 'Invalid email.', [ 'status' => 400 ] );
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $email ) > 254 ) {
                $email = mb_substr( $email, 0, 254 );
            }
        } else {
            if ( strlen( $email ) > 254 ) {
                $email = substr( $email, 0, 254 );
            }
        }
    }

    // Phone: strip non-dialable chars, optional
    $phone = wp_unslash( (string) $phone_in );
    $phone = preg_replace( '/[^0-9()+.\-\s]/', '', $phone );
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $phone ) > 20 ) {
            $phone = mb_substr( $phone, 0, 20 );
        }
    } else {
        if ( strlen( $phone ) > 20 ) {
            $phone = substr( $phone, 0, 20 );
        }
    }
    if ( $phone === '' ) {
        $phone = null;
    }

    // Build CALL statement with safely quoted values, matching procedure.
    $q_first = pd_post_attendee_sql_quote( $first !== '' ? $first : null );
    $q_last  = pd_post_attendee_sql_quote( $last !== '' ? $last : null );
    $q_email = pd_post_attendee_sql_quote( $email );
    $q_phone = pd_post_attendee_sql_quote( $phone );

    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql = sprintf(
        'CALL %s.POST_attendee(%s, %s, %s, %s, @new_member_id);',
        $schema,
        $q_first,
        $q_last,
        $q_email,
        $q_phone
    );

    try {
        if ( ! function_exists( 'aslta_signed_query' ) ) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
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

        if ( $email !== null ) {
            $lookup_sql = sprintf(
                'CALL %s.GET_Email_Lookup(%s);',
                $schema,
                pd_post_attendee_sql_quote( $email )
            );

            $lookup_result = aslta_signed_query( $lookup_sql );
            if ( $lookup_result['status'] < 200 || $lookup_result['status'] >= 300 ) {
                return new WP_Error(
                    'aslta_email_lookup_http_error',
                    'Remote email lookup endpoint returned an HTTP error.',
                    [
                        'status' => 500,
                        'debug'  => ( WP_DEBUG ? [ 'http_code' => $lookup_result['status'], 'body' => $lookup_result['body'] ] : null ),
                    ]
                );
            }

            $decoded_lookup = json_decode( (string) ( $lookup_result['body'] ?? '' ), true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error(
                    'aslta_email_lookup_json_error',
                    'Failed to decode email lookup response.',
                    [
                        'status' => 500,
                        'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
                    ]
                );
            }

            foreach ( pd_post_attendee_extract_rows( $decoded_lookup ) as $row ) {
                if ( is_array( $row ) && array_key_exists( 'id', $row ) ) {
                    return new WP_Error(
                        'email_already_used',
                        'The email is already used.',
                        [ 'status' => 400 ]
                    );
                }
            }
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        $msg = $e->getMessage();

        // Mirror stored proc’s validation message if surfaced.
        if ( strpos( (string) $msg, 'POST_attendee: first_name or last_name is required' ) !== false ) {
            return new WP_Error(
                'bad_request',
                'First name or last name is required.',
                [ 'status' => 400 ]
            );
        }

        return new WP_Error(
            'aslta_remote_error',
            'Failed to create attendee via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $msg : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        $body_str = isset( $result['body'] ) ? (string) $result['body'] : '';

        // Special-case duplicate name constraint (e.g. attempting to add someone who already exists
        // as a presenter/member with the same unique name key).
        if (
            $body_str !== '' &&
            strpos( $body_str, '1062' ) !== false &&
            strpos( $body_str, 'name_UNIQUE' ) !== false
        ) {
            return new WP_Error(
                'attendee_duplicate_name',
                'This user already exists as a presenter. Please proceed using the "Are they a registered Presenter?" button.',
                [
                    'status' => 400,
                ]
            );
        }

        return new WP_Error(
            'aslta_remote_http_error',
            'Remote attendee creation endpoint returned an HTTP error.',
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
            'Failed to decode attendee creation JSON response.',
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

    $new_id = 0;
    $out    = [
        'first_name'   => $first,
        'last_name'    => $last,
        'email'        => $email,
        'phone_number' => $phone,
        'attendee'     => 1,
        'presenter'    => 0,
    ];

    $first_row = null;
    foreach ( $rows_raw as $row ) {
        if ( is_array( $row ) ) {
            $first_row = $row;
            break;
        }
    }

    if ( $first_row !== null ) {
        if ( array_key_exists( 'idMember', $first_row ) ) {
            $new_id = (int) $first_row['idMember'];
        } elseif ( array_key_exists( 'id', $first_row ) ) {
            $new_id = (int) $first_row['id'];
        } elseif ( array_key_exists( 'member_id', $first_row ) ) {
            $new_id = (int) $first_row['member_id'];
        }

        if ( array_key_exists( 'first_name', $first_row ) ) {
            $out['first_name'] = (string) $first_row['first_name'];
        }
        if ( array_key_exists( 'last_name', $first_row ) ) {
            $out['last_name'] = (string) $first_row['last_name'];
        }
        if ( array_key_exists( 'email', $first_row ) ) {
            $out['email'] = $first_row['email'] === '' ? null : (string) $first_row['email'];
        }
        if ( array_key_exists( 'phone_number', $first_row ) ) {
            $out['phone_number'] = $first_row['phone_number'] === '' ? null : (string) $first_row['phone_number'];
        } elseif ( array_key_exists( 'phone', $first_row ) ) {
            $out['phone_number'] = $first_row['phone'] === '' ? null : (string) $first_row['phone'];
        }
        if ( array_key_exists( 'attendee', $first_row ) ) {
            $out['attendee'] = (int) $first_row['attendee'];
        }
        if ( array_key_exists( 'presenter', $first_row ) ) {
            $out['presenter'] = (int) $first_row['presenter'];
        }
    }

    if ( $new_id <= 0 ) {
        return new WP_Error(
            'profdef_no_id',
            'Did not receive a new member id from remote API.',
            [ 'status' => 500 ]
        );
    }

    return new WP_REST_Response(
        [
            'success'      => true,
            'id'           => (int) $new_id,
            'first_name'   => (string) $out['first_name'],
            'last_name'    => (string) $out['last_name'],
            'email'        => $out['email'],        // may be null
            'phone_number' => $out['phone_number'], // may be null
            'attendee'     => (int) $out['attendee'],
            'presenter'    => (int) $out['presenter'],
        ],
        201
    );
}
