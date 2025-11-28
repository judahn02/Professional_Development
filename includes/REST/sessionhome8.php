<?php
/**
 * ProfDef REST: sessionhome8
 * Purpose: Create a session via stored procedure Test_Database.sp_create_session
 * Endpoint: POST /wp-json/profdef/v2/sessionhome8
 * Input (JSON body preferred, falls back to form params):
 * {
 *   session_date: 'YYYY-MM-DD',
 *   length_minutes: 90,
 *   session_title: 'Title',
 *   specific_event: 'Parent Event' | null,
 *   type_of_session_id: 1,
 *   event_type_id: 2,
 *   ceu_id: 3 | null,
 *   presenters_csv: '1,2' | null
 * }
 * Returns: { success: true, id: <new session id> }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal SQL string escaper for values embedded into a CALL statement.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string|null $value
 * @return string SQL literal or NULL
 */
function pd_sessionhome8_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome8',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            // 'permission_callback' => 'pd_sessions_permission',
            'permission_callback' => '__return_true',
            'callback'            => 'pd_sessionhome8_create_session',
            'args'                => [
                'session_date' => [ 'type' => 'string', 'required' => true ],
                'length_minutes' => [
                    'type' => 'integer', 'required' => true,
                    'sanitize_callback' => function( $v ) { return (int) $v; },
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                ],
                'session_title' => [ 'type' => 'string', 'required' => true ],
                'specific_event' => [ 'type' => 'string', 'required' => false ],
                'type_of_session_id' => [
                    'type' => 'integer', 'required' => true,
                    'sanitize_callback' => function( $v ) { return (int) $v; },
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                ],
                'event_type_id' => [
                    'type' => 'integer', 'required' => true,
                    'sanitize_callback' => function( $v ) { return (int) $v; },
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                ],
                'ceu_id' => [
                    'type' => 'integer', 'required' => false,
                    'sanitize_callback' => function( $v ) {
                        if ($v === null || $v === '') return null;
                        return (int) $v;
                    },
                    'validate_callback' => function( $v ) {
                        if ($v === null || $v === '') return true;
                        return is_numeric( $v ) && (int) $v > 0;
                    },
                ],
                'presenters_csv' => [
                    'type' => 'string', 'required' => false,
                    'sanitize_callback' => function( $v ) {
                        $s = preg_replace( '/[^0-9,]+/', '', (string) $v );
                        $s = preg_replace( '/,+/', ',', $s );
                        return trim( $s, ',' );
                    },
                ],
            ],
        ]
    );
} );

function pd_sessionhome8_get_param( WP_REST_Request $req, $json, $keys, $default = null ) {
    foreach ( (array) $keys as $k ) {
        if ( is_array( $json ) && array_key_exists( $k, $json ) ) {
            return $json[ $k ];
        }
        $v = $req->get_param( $k );
        if ( null !== $v ) return $v;
    }
    return $default;
}

/**
 * Handler: POST /profdef/v2/sessionhome8
 */
function pd_sessionhome8_create_session( WP_REST_Request $req ) {
    $json = (array) $req->get_json_params();

    // 0) Collect + sanitize inputs (accept both snake_case and camelCase)
    $date_raw  = pd_sessionhome8_get_param( $req, $json, [ 'session_date', 'sessionDate' ] );
    $len_raw   = pd_sessionhome8_get_param( $req, $json, [ 'length_minutes', 'lengthMinutes' ] );
    $title_raw = pd_sessionhome8_get_param( $req, $json, [ 'session_title', 'sessionTitle' ] );
    $event_raw = pd_sessionhome8_get_param( $req, $json, [ 'specific_event', 'specificEvent' ] );
    $stype_raw = pd_sessionhome8_get_param( $req, $json, [ 'type_of_session_id', 'typeOfSessionId' ] );
    $etype_raw = pd_sessionhome8_get_param( $req, $json, [ 'event_type_id', 'eventTypeId' ] );
    $ceu_raw   = pd_sessionhome8_get_param( $req, $json, [ 'ceu_id', 'ceuId' ] );
    $pcsv_raw  = pd_sessionhome8_get_param( $req, $json, [ 'presenters_csv', 'presentersCsv' ] );

    // Validate session_date (YYYY-MM-DD)
    $session_date = is_string( $date_raw ) ? trim( $date_raw ) : '';
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $session_date ) ) {
        return new WP_Error( 'bad_param', 'session_date must be YYYY-MM-DD.', [ 'status' => 400 ] );
    }
    $parts = explode( '-', $session_date );
    if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
        return new WP_Error( 'bad_param', 'session_date is not a valid date.', [ 'status' => 400 ] );
    }

    // Validate length_minutes
    $length_minutes = (int) $len_raw;
    if ( $length_minutes <= 0 ) {
        return new WP_Error( 'bad_param', 'length_minutes must be a positive integer.', [ 'status' => 400 ] );
    }

    // Sanitize session_title
    $session_title = sanitize_text_field( wp_unslash( (string) $title_raw ) );
    $session_title = trim( $session_title );
    if ( $session_title === '' ) {
        return new WP_Error( 'bad_param', 'session_title is required.', [ 'status' => 400 ] );
    }
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $session_title ) > 256 ) $session_title = mb_substr( $session_title, 0, 256 );
    } else {
        if ( strlen( $session_title ) > 256 ) $session_title = substr( $session_title, 0, 256 );
    }

    // Sanitize specific_event (nullable)
    $specific_event = null;
    if ( null !== $event_raw ) {
        $tmp = sanitize_text_field( wp_unslash( (string) $event_raw ) );
        $tmp = trim( $tmp );
        $specific_event = ($tmp === '') ? null : $tmp;
        if ( $specific_event !== null ) {
            if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                if ( mb_strlen( $specific_event ) > 256 ) $specific_event = mb_substr( $specific_event, 0, 256 );
            } else {
                if ( strlen( $specific_event ) > 256 ) $specific_event = substr( $specific_event, 0, 256 );
            }
        }
    }

    // Validate type_of_session_id, event_type_id
    $type_of_session_id = (int) $stype_raw;
    $event_type_id      = (int) $etype_raw;
    if ( $type_of_session_id <= 0 || $event_type_id <= 0 ) {
        return new WP_Error( 'bad_param', 'type_of_session_id and event_type_id must be positive integers.', [ 'status' => 400 ] );
    }

    // Sanitize ceu_id (nullable, allow 'NA')
    $ceu_id = null;
    if ( null !== $ceu_raw ) {
        $str = strtolower( trim( (string) $ceu_raw ) );
        if ( $str !== '' && $str !== 'na' && $str !== 'null' ) {
            $tmp = (int) $ceu_raw;
            $ceu_id = $tmp > 0 ? $tmp : null;
        }
    }

    // Sanitize presenters_csv (CSV of IDs; nullable)
    $presenters_csv = null;
    if ( null !== $pcsv_raw ) {
        $s = preg_replace( '/[^0-9,]+/', '', (string) $pcsv_raw );
        $s = preg_replace( '/,+/', ',', $s );
        $s = trim( $s, ',' );
        $presenters_csv = ($s === '') ? null : $s;
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted values, matching sp_create_session signature:
    // (session_date, length_minutes, session_title, specific_event, type_of_session_id, event_type_id, ceu_id, presenters_csv)
    $q_date      = pd_sessionhome8_sql_quote( $session_date );
    $q_title     = pd_sessionhome8_sql_quote( $session_title );
    $q_event     = pd_sessionhome8_sql_quote( $specific_event );
    $q_ceu       = pd_sessionhome8_sql_quote( $ceu_id === null ? null : (string) (int) $ceu_id );
    $q_presenter = pd_sessionhome8_sql_quote( $presenters_csv );

    $sql = sprintf(
        'CALL beta_2.sp_create_session(%s, %d, %s, %s, %d, %d, %s, %s);',
        $q_date,
        $length_minutes,
        $q_title,
        $q_event,
        $type_of_session_id,
        $event_type_id,
        $q_ceu,
        $q_presenter
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
        return new WP_Error(
            'aslta_remote_error',
            'Failed to create session via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote session creation endpoint returned an HTTP error.',
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
            'Failed to decode session creation JSON response.',
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
    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        if ( isset( $row['id'] ) ) {
            $new_id = (int) $row['id'];
            break;
        }
        if ( isset( $row['session_id'] ) ) {
            $new_id = (int) $row['session_id'];
            break;
        }
        if ( isset( $row['sessionId'] ) ) {
            $new_id = (int) $row['sessionId'];
            break;
        }
    }

    if ( $new_id <= 0 ) {
        return new WP_Error(
            'profdef_no_id',
            'Stored procedure did not return a new session id. Ensure sp_create_session SELECTs the created session_id.',
            [ 'status' => 500 ]
        );
    }

    return new WP_REST_Response( [ 'success' => true, 'id' => $new_id ], 201 );

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
     *
     * if ( ! $host || ! $name || ! $user ) {
     *     return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
     * }
     *
     * // 2) Connect to external DB
     * $conn = @new mysqli( $host, $user, $pass, $name );
     * if ( $conn->connect_error ) {
     *     return new WP_Error(
     *         'mysql_not_connect',
     *         'Database connection failed.',
     *         [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->connect_error : null ]
     *     );
     * }
     * $conn->set_charset( 'utf8mb4' );
     *
     * // 3) Prepare + execute stored procedure
     * $stmt = $conn->prepare( 'CALL Test_Database.sp_create_session(?, ?, ?, ?, ?, ?, ?, ?)' );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
     * }
     *
     * // Bind params (s i s s i i i s)
     * $stmt->bind_param(
     *     'sissiiis',
     *     $session_date,
     *     $length_minutes,
     *     $session_title,
     *     $specific_event,
     *     $type_of_session_id,
     *     $event_type_id,
     *     $ceu_id,
     *     $presenters_csv
     * );
     *
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
     * }
     *
     * // 4) Read new id from first result set (stored procedure must return it)
     * $new_id = 0;
     * if ( $result = $stmt->get_result() ) {
     *     if ( $row = $result->fetch_assoc() ) {
     *         if ( isset( $row['id'] ) )         $new_id = (int) $row['id'];
     *         elseif ( isset( $row['session_id'] ) ) $new_id = (int) $row['session_id'];
     *         elseif ( isset( $row['sessionId'] ) )  $new_id = (int) $row['sessionId'];
     *     }
     *     $result->free();
     * }
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $extra = $stmt->get_result() ) { $extra->free(); }
     * }
     * $stmt->close();
     * $conn->close();
     *
     * if ( $new_id <= 0 ) {
     *     return new WP_Error(
     *         'profdef_no_id',
     *         'Stored procedure did not return a new session id. Ensure sp_create_session SELECTs the created session_id.',
     *         [ 'status' => 500 ]
     *     );
     * }
     *
     * return new WP_REST_Response( [ 'success' => true, 'id' => $new_id ], 201 );
     */
}
