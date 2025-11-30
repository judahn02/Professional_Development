<?php
/**
 * ProfDef REST: sessionhome6
 * Purpose: Add a lookup value via stored procedure Test_Database.sp_add_lookup_value
 * Endpoint: POST /wp-json/profdef/v2/sessionhome6
 * Params: target (string: 'ceu_consideration' | 'event_type' | 'type_of_session'), value (string)
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
function pd_sessionhome6_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome6',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_sessionhome6_add_lookup_value',
            'args'                => [
                'target' => [
                    'description' => "Target table: 'ceu_consideration' | 'event_type' | 'type_of_session'",
                    'type'        => 'string',
                    'required'    => true,
                    'sanitize_callback' => 'pd_sessionhome6_sanitize_target',
                    'validate_callback' => 'pd_sessionhome6_validate_target',
                ],
                'value'  => [
                    'description' => 'Value to insert into the selected lookup table',
                    'type'        => 'string',
                    'required'    => true,
                    'sanitize_callback' => 'pd_sessionhome6_sanitize_value',
                    'validate_callback' => 'pd_sessionhome6_validate_value',
                ],
            ],
        ]
    );
} );

/**
 * Sanitize and validate helpers for sessionhome6
 */
function pd_sessionhome6_sanitize_target( $value ) {
    $v = strtolower( trim( (string) $value ) );
    // Cap to proc arg length (32)
    if ( strlen( $v ) > 32 ) {
        $v = substr( $v, 0, 32 );
    }
    return $v;
}
function pd_sessionhome6_validate_target( $value, WP_REST_Request $request, $param ) {
    $allowed = [ 'ceu_consideration', 'event_type', 'type_of_session' ];
    return in_array( strtolower( (string) $value ), $allowed, true );
}

function pd_sessionhome6_sanitize_value( $value ) {
    $v = sanitize_text_field( wp_unslash( (string) $value ) );
    $v = trim( $v );
    // Cap to proc arg length (64), support mb when available
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $v ) > 64 ) {
            $v = mb_substr( $v, 0, 64 );
        }
    } else {
        if ( strlen( $v ) > 64 ) {
            $v = substr( $v, 0, 64 );
        }
    }
    return $v;
}
function pd_sessionhome6_validate_value( $value, WP_REST_Request $request, $param ) {
    $v = (string) $value;
    return $v !== '';
}

/**
 * Handler: POST /profdef/v2/sessionhome6
 */
function pd_sessionhome6_add_lookup_value( WP_REST_Request $req ) {
    // 0) Sanitize input
    $target_raw = (string) $req->get_param( 'target' );
    $value_raw  = (string) $req->get_param( 'value' );

    $target = strtolower( trim( $target_raw ) );
    // Only allow exact SP targets
    $allowed = [ 'ceu_consideration', 'event_type', 'type_of_session' ];
    if ( ! in_array( $target, $allowed, true ) ) {
        return new WP_Error(
            'bad_request',
            "Invalid target. Use: ceu_consideration | event_type | type_of_session",
            [ 'status' => 400 ]
        );
    }

    $value = sanitize_text_field( wp_unslash( $value_raw ) );
    $value = trim( $value );
    if ( $value === '' ) {
        return new WP_Error( 'bad_request', 'value is required', [ 'status' => 400 ] );
    }

    // Enforce proc arg length limits (p_target VARCHAR(32), p_value VARCHAR(64))
    if ( strlen( $target ) > 32 ) {
        $target = substr( $target, 0, 32 );
    }
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $value ) > 64 ) {
            $value = mb_substr( $value, 0, 64 );
        }
    } else {
        if ( strlen( $value ) > 64 ) {
            $value = substr( $value, 0, 64 );
        }
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted values, matching sp_add_lookup_value(p_target, p_value).
    $q_target = pd_sessionhome6_sql_quote( $target );
    $q_value  = pd_sessionhome6_sql_quote( $value );

    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql = sprintf(
        'CALL %s.sp_add_lookup_value(%s, %s);',
        $schema,
        $q_target,
        $q_value
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
            'Failed to add lookup value via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        $body_str = isset( $result['body'] ) ? (string) $result['body'] : '';
        $detail   = $body_str;

        if ( $body_str !== '' ) {
            $inner = json_decode( $body_str, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $inner ) && isset( $inner['detail'] ) ) {
                $detail = (string) $inner['detail'];
            }
        }

        // Detect duplicate-key error from remote API (MySQL error 1062 on name_UNIQUE).
        if ( strpos( (string) $detail, '1062' ) !== false && strpos( (string) $detail, 'Duplicate entry' ) !== false ) {
            $label_map = [
                'type_of_session'   => 'Session Type',
                'event_type'        => 'Event Type',
                'ceu_consideration' => 'CEU Consideration',
            ];
            $label = isset( $label_map[ $target ] ) ? $label_map[ $target ] : 'lookup value';

            $message = sprintf(
                "The %s '%s' already exists.",
                $label,
                $value
            );

            return new WP_Error(
                'lookup_conflict',
                $message,
                [
                    'status' => 409,
                    'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
                ]
            );
        }

        return new WP_Error(
            'aslta_remote_http_error',
            'Remote lookup creation endpoint returned an HTTP error.',
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
            'Failed to decode lookup creation JSON response.',
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
        if ( array_key_exists( 'id', $row ) ) {
            $new_id = (int) $row['id'];
            break;
        }
    }

    return new WP_REST_Response(
        [
            'success' => true,
            'id'      => $new_id,
            'target'  => $target,
            'value'   => $value,
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
     * $stmt = $conn->prepare( 'CALL Test_Database.sp_add_lookup_value(?, ?)' );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
     * }
     * $stmt->bind_param( 'ss', $target, $value );
     *
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
     * }
     *
     * // 4) Read returned id from first result set (SELECT LAST_INSERT_ID() AS id)
     * $new_id = 0;
     * if ( $res = $stmt->get_result() ) {
     *     if ( $row = $res->fetch_assoc() ) {
     *         $new_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
     *     }
     *     $res->free();
     * }
     * // Drain additional result sets if any
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $extra = $stmt->get_result() ) {
     *         $extra->free();
     *     }
     * }
     * $stmt->close();
     * $conn->close();
     *
     * return new WP_REST_Response( [
     *     'success' => true,
     *     'id'      => $new_id,
     *     'target'  => $target,
     *     'value'   => $value,
     * ], 201 );
     */
}
