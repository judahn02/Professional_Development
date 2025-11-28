<?php
/**
 * ProfDef REST: POST_presenter
 * Endpoint: POST /wp-json/profdef/v2/presenter
 * Purpose: Create a presenter via stored procedure beta_2.POST_presenter (signed API)
 *
 * Accepts JSON (or form) params:
 * - name (string, required; if absent, combines firstname + lastname)
 * - firstname (string, optional)
 * - lastname (string, optional)
 * - email (string, optional; NULL allowed)
 * - phone (string, optional; NULL allowed)
 *
 * Returns: { success: true, id, name, email, phone_number }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/presenter',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'permission_callback' => 'pd_presenters_permission', // reuse presenters nonce + capability
            'callback'            => 'pd_post_presenter_create',
            'args'                => [
                'name' => [ 'type' => 'string', 'required' => false ],
                'firstname' => [ 'type' => 'string', 'required' => false ],
                'lastname'  => [ 'type' => 'string', 'required' => false ],
                'email' => [ 'type' => 'string', 'required' => false ],
                'phone' => [ 'type' => 'string', 'required' => false ],
            ],
        ]
    );
} );

function pd_post_presenter_get( WP_REST_Request $req, array $json, $keys, $default = null ) {
    foreach ( (array) $keys as $k ) {
        if ( array_key_exists( $k, $json ) ) return $json[ $k ];
        $v = $req->get_param( $k );
        if ( null !== $v ) return $v;
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
function pd_post_presenter_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    // Escape backslashes first, then single quotes.
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

function pd_post_presenter_create( WP_REST_Request $req ) {
    $json = (array) $req->get_json_params();

    // Collect + sanitize
    $name_in  = pd_post_presenter_get( $req, $json, [ 'name' ], '' );
    $first_in = pd_post_presenter_get( $req, $json, [ 'firstname', 'first_name' ], '' );
    $last_in  = pd_post_presenter_get( $req, $json, [ 'lastname', 'last_name' ], '' );
    $email_in = pd_post_presenter_get( $req, $json, [ 'email' ], '' );
    $phone_in = pd_post_presenter_get( $req, $json, [ 'phone' ], '' );

    $first = sanitize_text_field( wp_unslash( (string) $first_in ) );
    $last  = sanitize_text_field( wp_unslash( (string) $last_in ) );
    $name  = sanitize_text_field( wp_unslash( (string) $name_in ) );
    if ( $name === '' ) {
        $name = trim( $first . ' ' . $last );
    }
    // Enforce reasonable length limits for DB columns
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $name ) > 60 ) {
            $name = mb_substr( $name, 0, 60 );
        }
        if ( mb_strlen( $first ) > 45 ) {
            $first = mb_substr( $first, 0, 45 );
        }
        if ( mb_strlen( $last ) > 45 ) {
            $last = mb_substr( $last, 0, 45 );
        }
    } else {
        if ( strlen( $name ) > 60 ) {
            $name = substr( $name, 0, 60 );
        }
        if ( strlen( $first ) > 45 ) {
            $first = substr( $first, 0, 45 );
        }
        if ( strlen( $last ) > 45 ) {
            $last = substr( $last, 0, 45 );
        }
    }

    // If only a combined name was provided, fall back to using it as first_name
    if ( $first === '' && $last === '' && $name !== '' ) {
        $first = $name;
    }

    $email = sanitize_email( wp_unslash( (string) $email_in ) );
    if ( $email === '' ) $email = null; // normalize empty to NULL
    if ( $email !== null ) {
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_request', 'Invalid email.', [ 'status' => 400 ] );
        }
        if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
            if ( mb_strlen( $email ) > 254 ) $email = mb_substr( $email, 0, 254 );
        } else {
            if ( strlen( $email ) > 254 ) $email = substr( $email, 0, 254 );
        }
    }

    $phone = wp_unslash( (string) $phone_in );
    $phone = preg_replace( '/[^0-9()+.\-\s]/', '', $phone );
    if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
        if ( mb_strlen( $phone ) > 20 ) $phone = mb_substr( $phone, 0, 20 );
    } else {
        if ( strlen( $phone ) > 20 ) $phone = substr( $phone, 0, 20 );
    }
    if ( $phone === '' ) $phone = null; // normalize empty to NULL

    if ( $name === '' ) {
        return new WP_Error( 'bad_request', 'Presenter name is required.', [ 'status' => 400 ] );
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted values, matching procedure:
    // POST_presenter(IN p_first_name, IN p_last_name, IN p_email, IN p_phone, OUT p_idPresentor)
    $q_first = pd_post_presenter_sql_quote( $first !== '' ? $first : null );
    $q_last  = pd_post_presenter_sql_quote( $last !== '' ? $last : null );
    $q_email = pd_post_presenter_sql_quote( $email );
    $q_phone = pd_post_presenter_sql_quote( $phone );

    $sql = sprintf(
        'CALL beta_2.POST_presenter(%s, %s, %s, %s, @new_id);',
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

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        // Preserve semantics for missing name if backend echoes that error.
        $msg = $e->getMessage();
        if ( strpos( (string) $msg, 'POST_presenter: first_name or last_name is required' ) !== false ) {
            return new WP_Error( 'bad_request', 'Presenter name is required.', [ 'status' => 400 ] );
        }

        return new WP_Error(
            'aslta_remote_error',
            'Failed to create presenter via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $msg : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote presenter creation endpoint returned an HTTP error.',
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
            'Failed to decode presenter creation JSON response.',
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

    $new_id  = 0;
    $out     = [ 'name' => $name, 'email' => $email, 'phone_number' => $phone ];
    $first   = null;

    foreach ( $rows_raw as $row ) {
        if ( is_array( $row ) ) {
            $first = $row;
            break;
        }
    }

    if ( $first !== null ) {
        if ( array_key_exists( 'idPresentor', $first ) ) {
            $new_id = (int) $first['idPresentor'];
        } elseif ( array_key_exists( 'id', $first ) ) {
            $new_id = (int) $first['id'];
        } elseif ( array_key_exists( 'presenter_id', $first ) ) {
            $new_id = (int) $first['presenter_id'];
        }

        // Remote proc returns first_name / last_name; combine for display name.
        if ( array_key_exists( 'first_name', $first ) || array_key_exists( 'last_name', $first ) ) {
            $remote_first = isset( $first['first_name'] ) ? (string) $first['first_name'] : '';
            $remote_last  = isset( $first['last_name'] ) ? (string) $first['last_name'] : '';
            $combined     = trim( $remote_first . ' ' . $remote_last );
            if ( $combined !== '' ) {
                $out['name'] = $combined;
            }
        } elseif ( array_key_exists( 'name', $first ) ) {
            $out['name'] = (string) $first['name'];
        }

        if ( array_key_exists( 'email', $first ) ) {
            $out['email'] = $first['email'] === '' ? null : (string) $first['email'];
        }

        if ( array_key_exists( 'phone_number', $first ) ) {
            $out['phone_number'] = $first['phone_number'] === '' ? null : (string) $first['phone_number'];
        } elseif ( array_key_exists( 'phone', $first ) ) {
            $out['phone_number'] = $first['phone'] === '' ? null : (string) $first['phone'];
        }
    }

    if ( $new_id <= 0 ) {
        return new WP_Error( 'profdef_no_id', 'Did not receive a new presenter id from remote API.', [ 'status' => 500 ] );
    }

    return new WP_REST_Response(
        [
            'success'      => true,
            'id'           => (int) $new_id,
            'name'         => (string) $out['name'],
            'email'        => $out['email'],       // may be null
            'phone_number' => $out['phone_number'] // may be null
        ],
        201
    );

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // 1) Decrypt external DB creds
     * $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
     * $dbname = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
     * $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
     * $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
     * if ( ! $host || ! $dbname || ! $user ) {
     *     return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
     * }
     *
     * // 2) Connect and call stored procedure
     * $conn = @new mysqli( $host, $user, $pass, $dbname );
     * if ( $conn->connect_error ) {
     *     return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
     * }
     * $conn->set_charset( 'utf8mb4' );
     *
     * $stmt = $conn->prepare( 'CALL Test_Database.POST_presenter(?, ?, ?, @new_id)' );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
     * }
     * // bind NULLs allowed
     * $stmt->bind_param( 'sss', $name, $email, $phone );
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     if ( strpos( (string) $err, 'POST_presenter: name is required' ) !== false ) {
     *         return new WP_Error( 'bad_request', 'Presenter name is required.', [ 'status' => 400 ] );
     *     }
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
     * }
     *
     * // First result set should contain the selected payload
     * $new_id = 0; $out = [ 'name' => $name, 'email' => $email, 'phone_number' => $phone ];
     * if ( $result = $stmt->get_result() ) {
     *     if ( $row = $result->fetch_assoc() ) {
     *         $new_id = isset( $row['idPresentor'] ) ? (int) $row['idPresentor'] : 0;
     *         $out['name'] = isset( $row['name'] ) ? (string) $row['name'] : $out['name'];
     *         $out['email'] = isset( $row['email'] ) ? ( $row['email'] === '' ? null : (string) $row['email'] ) : $out['email'];
     *         $out['phone_number'] = isset( $row['phone_number'] ) ? ( $row['phone_number'] === '' ? null : (string) $row['phone_number'] ) : $out['phone_number'];
     *     }
     *     $result->free();
     * }
     * // Drain any extra results
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $extra = $stmt->get_result() ) { $extra->free(); }
     * }
     * $stmt->close();
     *
     * if ( $new_id <= 0 ) {
     *     // Fallback to OUT var
     *     if ( $res = $conn->query( 'SELECT @new_id AS id' ) ) {
     *         if ( $r = $res->fetch_assoc() ) {
     *             $new_id = isset( $r['id'] ) ? (int) $r['id'] : 0;
     *         }
     *         $res->free();
     *     }
     * }
     *
     * $conn->close();
     *
     * if ( $new_id <= 0 ) {
     *     return new WP_Error( 'profdef_no_id', 'Did not receive a new presenter id.', [ 'status' => 500 ] );
     * }
     *
     * return new WP_REST_Response( [
     *     'success'      => true,
     *     'id'           => (int) $new_id,
     *     'name'         => (string) $out['name'],
     *     'email'        => $out['email'],      // may be null
     *     'phone_number' => $out['phone_number'] // may be null
     * ], 201 );
     */
}
