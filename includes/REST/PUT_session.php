<?php
/**
 * ProfDef REST: PUT_session
 * Endpoint: PUT /wp-json/profdef/v2/session
 * Purpose: Update a session via stored procedure beta_2.PUT_session (signed API)
 *
 * Expects JSON body (or form params fallback) with fields:
 * {
 *   session_id: number,                 // required
 *   title: string,                      // required (<=256)
 *   date: 'YYYY-MM-DD' | null,          // optional; null/'' => no date
 *   length: number,                     // required (minutes)
 *   specific_event: string|null,        // optional, '' -> NULL
 *   session_type: string,               // required (name; proc resolves/creates)
 *   ceu_consideration: string|null,     // optional; 'NA' or '' -> NULL (clears)
 *   event_type: string                  // required (name; proc resolves/creates)
 * }
 * Returns: { success: true, session_id, rows_updated }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/session',
        [
            'methods'             => [ 'PUT', 'POST' ], // allow POST for clients that cannot send PUT
            'callback'            => 'pd_put_session_update',
            'permission_callback' => 'pd_presenters_permission',
        ]
    );
} );

function pd_put_session_get_param( WP_REST_Request $req, array $json, $keys, $default = null ) {
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
function pd_put_session_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

function pd_put_session_update( WP_REST_Request $request ) {
    // Body may be JSON or form-encoded
    $json = (array) $request->get_json_params();

    // Collect inputs (allow camelCase or snake_case)
    $session_id = (int) pd_put_session_get_param( $request, $json, [ 'session_id', 'sessionId' ], 0 );
    $title_raw  = pd_put_session_get_param( $request, $json, [ 'title' ], '' );
    $date_raw   = pd_put_session_get_param( $request, $json, [ 'date' ], '' );
    $length     = (int) pd_put_session_get_param( $request, $json, [ 'length', 'length_minutes', 'lengthMinutes' ], 0 );
    $sp_event   = pd_put_session_get_param( $request, $json, [ 'specific_event', 'specificEvent' ], null );
    $stype_raw  = pd_put_session_get_param( $request, $json, [ 'session_type', 'sessionType' ], '' );
    $ceu_raw    = pd_put_session_get_param( $request, $json, [ 'ceu_consideration', 'ceuConsideration' ], null );
    $etype_raw  = pd_put_session_get_param( $request, $json, [ 'event_type', 'eventType' ], '' );

    if ( $session_id <= 0 ) {
        return new WP_Error( 'bad_param', 'session_id must be a positive integer.', [ 'status' => 400 ] );
    }

    // Validate date (YYYY-MM-DD) – now optional.
    // If provided and non-empty, it must be a valid calendar date; otherwise we treat it as NULL.
    $date     = null;
    $date_str = is_string( $date_raw ) ? trim( $date_raw ) : '';
    if ( $date_str !== '' ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
            return new WP_Error( 'bad_param', 'date must be YYYY-MM-DD.', [ 'status' => 400 ] );
        }
        $parts = explode( '-', $date_str );
        if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
            return new WP_Error( 'bad_param', 'date is not a valid calendar date.', [ 'status' => 400 ] );
        }
        $date = $date_str;
    }

    if ( $length <= 0 ) {
        return new WP_Error( 'bad_param', 'length must be a positive integer (minutes).', [ 'status' => 400 ] );
    }

    // Sanitize strings
    $title = sanitize_text_field( wp_unslash( (string) $title_raw ) );
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $title ) > 256 ) $title = mb_substr( $title, 0, 256 );
    } else {
        if ( strlen( $title ) > 256 ) $title = substr( $title, 0, 256 );
    }
    if ( $title === '' ) {
        return new WP_Error( 'bad_param', 'title is required.', [ 'status' => 400 ] );
    }

    // specific_event: nullable, trim/empty => null, cap len 256
    $specific_event = null;
    if ( null !== $sp_event ) {
        $tmp = sanitize_text_field( wp_unslash( (string) $sp_event ) );
        $tmp = trim( $tmp );
        if ( $tmp !== '' ) {
            if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                if ( mb_strlen( $tmp ) > 256 ) $tmp = mb_substr( $tmp, 0, 256 );
            } else {
                if ( strlen( $tmp ) > 256 ) $tmp = substr( $tmp, 0, 256 );
            }
            $specific_event = $tmp;
        }
    }

    // Names (type/event/ceu) – sanitize; map NA/empty to NULL for CEU
    $session_type = sanitize_text_field( wp_unslash( (string) $stype_raw ) );
    $event_type   = sanitize_text_field( wp_unslash( (string) $etype_raw ) );
    $session_type = trim( $session_type );
    $event_type   = trim( $event_type );
    if ( $session_type === '' || $event_type === '' ) {
        return new WP_Error( 'bad_param', 'session_type and event_type are required.', [ 'status' => 400 ] );
    }

    $ceu_consideration = null; // NULL clears CEU
    if ( null !== $ceu_raw ) {
        $c = trim( (string) $ceu_raw );
        if ( $c !== '' && strcasecmp( $c, 'NA' ) !== 0 && strcasecmp( $c, 'null' ) !== 0 ) {
            $ceu_consideration = sanitize_text_field( wp_unslash( $c ) );
        }
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted values, matching procedure:
    // PUT_session(IN session_id, IN title, IN date (nullable), IN length, IN specific_event, IN session_type, IN ceu_consideration, IN event_type)
    $q_title            = pd_put_session_sql_quote( $title );
    $q_date             = pd_put_session_sql_quote( $date );
    $q_specific_event   = pd_put_session_sql_quote( $specific_event );
    $q_session_type     = pd_put_session_sql_quote( $session_type );
    $q_ceu_consideration = pd_put_session_sql_quote( $ceu_consideration );
    $q_event_type       = pd_put_session_sql_quote( $event_type );

    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql = sprintf(
        'CALL %s.PUT_session(%d, %s, %s, %d, %s, %s, %s, %s);',
        $schema,
        $session_id,
        $q_title,
        $q_date,
        $length,
        $q_specific_event,
        $q_session_type,
        $q_ceu_consideration,
        $q_event_type
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
        $msg = $e->getMessage();
        // Map custom SIGNAL to 404 if session not found
        if ( strpos( (string) $msg, 'PUT_session: session not found' ) !== false ) {
            return new WP_Error( 'rest_not_found', 'Session not found.', [ 'status' => 404 ] );
        }

        return new WP_Error(
            'aslta_remote_error',
            'Failed to update session via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $msg : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        // Try to preserve "session not found" semantics if message is bubbled up
        if ( strpos( (string) $result['body'], 'PUT_session: session not found' ) !== false ) {
            return new WP_Error( 'rest_not_found', 'Session not found.', [ 'status' => 404 ] );
        }

        return new WP_Error(
            'aslta_remote_http_error',
            'Remote session update endpoint returned an HTTP error.',
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
            'Failed to decode session update JSON response.',
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

    $rows_updated = 0;
    $sid_out      = $session_id;
    $first        = null;

    foreach ( $rows_raw as $row ) {
        if ( is_array( $row ) ) {
            $first = $row;
            break;
        }
    }

    if ( $first !== null ) {
        if ( array_key_exists( 'rows_updated', $first ) ) {
            $rows_updated = (int) $first['rows_updated'];
        } elseif ( array_key_exists( 'rows_affected', $first ) ) {
            $rows_updated = (int) $first['rows_affected'];
        }

        if ( array_key_exists( 'session_id', $first ) ) {
            $sid_out = (int) $first['session_id'];
        } elseif ( array_key_exists( 'id', $first ) ) {
            $sid_out = (int) $first['id'];
        }
    }

    return new WP_REST_Response(
        [
            'success'      => true,
            'session_id'   => (int) $sid_out,
            'rows_updated' => (int) $rows_updated,
        ],
        200
    );

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // 1) Decrypt external DB creds
     * $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
     * $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
     * $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
     * $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
     * if ( ! $host || ! $name || ! $user ) {
     *     return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
     * }
     *
     * // 2) Connect
     * $conn = @new mysqli( $host, $user, $pass, $name );
     * if ( $conn->connect_error ) {
     *     return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
     * }
     * $conn->set_charset( 'utf8mb4' );
     *
     * // 3) Prepare + execute stored procedure
     * $sql = 'CALL Test_Database.PUT_session(?, ?, ?, ?, ?, ?, ?, ?)';
     * $stmt = $conn->prepare( $sql );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
     * }
     *
     * // Bind params: i s s i s s s s (NULLs allowed for specific_event and ceu_consideration)
     * $stmt->bind_param(
     *     'ississss',
     *     $session_id,
     *     $title,
     *     $date,
     *     $length,
     *     $specific_event,
     *     $session_type,
     *     $ceu_consideration,
     *     $event_type
     * );
     *
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     // Map custom SIGNAL to 404 if session not found
     *     if ( strpos( (string) $err, 'PUT_session: session not found' ) !== false ) {
     *         return new WP_Error( 'rest_not_found', 'Session not found.', [ 'status' => 404 ] );
     *     }
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
     * }
     *
     * // 4) Read the result row (rows_updated, session_id)
     * $rows_updated = 0; $sid_out = $session_id;
     * if ( $result = $stmt->get_result() ) {
     *     if ( $row = $result->fetch_assoc() ) {
     *         if ( isset( $row['rows_updated'] ) ) $rows_updated = (int) $row['rows_updated'];
     *         if ( isset( $row['session_id'] ) ) $sid_out = (int) $row['session_id'];
     *     }
     *     $result->free();
     * }
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $extra = $stmt->get_result() ) { $extra->free(); }
     * }
     * $stmt->close();
     * $conn->close();
     *
     * return new WP_REST_Response( [
     *     'success'      => true,
     *     'session_id'   => (int) $sid_out,
     *     'rows_updated' => (int) $rows_updated,
     * ], 200 );
     */
}
