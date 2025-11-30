<?php
/**
 * ProfDef REST: sessionhome5
 * Purpose: Add a presenter using stored procedure Test_Database.sp_add_presentor(name, email, number)
 * Endpoint: POST /wp-json/profdef/v2/sessionhome5
 * Params (form/query): name, email, number
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
function pd_sessionhome5_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome5',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            // 'permission_callback' => 'pd_sessions_permission',
            'permission_callback' => '__return_true',
            'callback'            => 'pd_sessionhome5_add_presenter',
            'args'                => [
                'name' => [
                    'description' => 'Presenter full name',
                    'type'        => 'string',
                    'required'    => true,
                ],
                'email' => [
                    'description' => 'Presenter email',
                    'type'        => 'string',
                    'required'    => false,
                ],
                'number' => [
                    'description' => 'Presenter phone number',
                    'type'        => 'string',
                    'required'    => false,
                ],
            ],
        ]
    );
} );

/**
 * Handler: POST /profdef/v2/sessionhome5
 */
function pd_sessionhome5_add_presenter( WP_REST_Request $req ) {
    // 0) Sanitize and validate params
    $name  = sanitize_text_field( wp_unslash( (string) $req->get_param( 'name' ) ) );
    $email = sanitize_email( wp_unslash( (string) $req->get_param( 'email' ) ) );
    $phone = wp_unslash( (string) $req->get_param( 'number' ) );
    $phone = preg_replace( '/[^0-9()+.\-\s]/', '', $phone );

    if ( $name === '' ) {
        return new WP_Error( 'bad_request', 'Parameter "name" is required.', [ 'status' => 400 ] );
    }
    if ( $email !== '' && ! is_email( $email ) ) {
        return new WP_Error( 'bad_request', 'Invalid email.', [ 'status' => 400 ] );
    }
    if ( mb_strlen( $name ) > 255 ) {
        $name = mb_substr( $name, 0, 255 );
    }
    if ( mb_strlen( $email ) > 254 ) {
        $email = mb_substr( $email, 0, 254 );
    }
    if ( mb_strlen( $phone ) > 32 ) {
        $phone = mb_substr( $phone, 0, 32 );
    }

    // Derive first/last name from the full name for the stored procedure.
    $first = '';
    $last  = '';
    $parts = preg_split( '/\s+/', $name );
    if ( is_array( $parts ) && count( $parts ) > 0 ) {
        $first = array_shift( $parts );
        $last  = trim( implode( ' ', $parts ) );
    } else {
        $first = $name;
        $last  = '';
    }

    // Enforce first/last length limits to match VARCHAR(45)
    if ( mb_strlen( $first ) > 45 ) {
        $first = mb_substr( $first, 0, 45 );
    }
    if ( mb_strlen( $last ) > 45 ) {
        $last = mb_substr( $last, 0, 45 );
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted values, matching sp_add_presentor(first_name, last_name, email, phone)
    $q_first = pd_sessionhome5_sql_quote( $first );
    $q_last  = pd_sessionhome5_sql_quote( $last === '' ? null : $last );
    $q_email = pd_sessionhome5_sql_quote( $email === '' ? null : $email );
    $q_phone = pd_sessionhome5_sql_quote( $phone === '' ? null : $phone );

    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql = sprintf(
        'CALL %s.sp_add_presentor(%s, %s, %s, %s);',
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

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to add presenter via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
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

    $new_id = 0;
    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        if ( array_key_exists( 'id', $row ) ) {
            $new_id = (int) $row['id'];
            break;
        } elseif ( array_key_exists( 'idPresentor', $row ) ) {
            $new_id = (int) $row['idPresentor'];
            break;
        }
    }

    return new WP_REST_Response(
        [
            'success' => true,
            'id'      => $new_id,
            'name'    => $name,
            'email'   => $email === '' ? null : $email,
            'number'  => $phone === '' ? null : $phone,
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
     * $name_db = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
     * $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
     * $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
     *
     * if ( ! $host || ! $name_db || ! $user ) {
     *     return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
     * }
     *
     * // 2) Connect to external DB
     * $conn = @new mysqli( $host, $user, $pass, $name_db );
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
     * $stmt = $conn->prepare( 'CALL Test_Database.sp_add_presentor(?, ?, ?)' );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
     * }
     * // Normalize empties to NULL? Stored proc signature uses strings; pass empty strings if not provided
     * $email_param = $email === '' ? null : $email;
     * $phone_param = $phone === '' ? null : $phone;
     * // For mysqli bind_param with NULLs, we need to set to null and adjust types: still use 'sss' and allow nulls
     * $stmt->bind_param( 'sss', $name, $email_param, $phone_param );
     *
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
     * }
     *
     * // 4) Drain results if any
     * if ( $result = $stmt->get_result() ) {
     *     $result->free();
     * }
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $res2 = $stmt->get_result() ) {
     *         $res2->free();
     *     }
     * }
     * $stmt->close();
     *
     * // 5) Try to fetch the new ID
     * $new_id = 0;
     * if ( $res = $conn->query( 'SELECT LAST_INSERT_ID() AS id' ) ) {
     *     if ( $row = $res->fetch_assoc() ) {
     *         $new_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
     *     }
     *     $res->free();
     * }
     * $conn->close();
     *
     * return new WP_REST_Response( [
     *     'success' => true,
     *     'id'      => $new_id,
     *     'name'    => $name,
     *     'email'   => $email === '' ? null : $email,
     *     'number'  => $phone === '' ? null : $phone,
     * ], 201 );
     */
}
