<?php
/**
 * ProfDef REST: GET_presenters_table
 * Endpoint: GET /wp-json/profdef/v2/presenters_table
 * Returns rows from beta_2.GET_presenters_table view (via signed API).
 * Optional query param: q (case-insensitive substring over name/email/phone_number)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/presenters_table',
        [
            'methods'             => WP_REST_Server::READABLE,
            // 'permission_callback' => 'pd_presenters_permission', // reuse existing nonce+cap check
            'permission_callback' => '__return_true',
            'callback'            => 'pd_get_presenters_table_view',
            'args'                => [
                'q' => [
                    'description' => 'Optional search term to filter by name/email/phone.',
                    'type'        => 'string',
                    'required'    => false,
                ],
            ],
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/presenters_table
 */
function pd_get_presenters_table_view( WP_REST_Request $request ) {
    // Optional search term
    $q_raw = (string) $request->get_param( 'q' );
    // Allow letters/digits/spaces/basic punctuation for email and phone; collapse whitespace
    $q_sanitized = preg_replace( "/[^\p{L}\p{N}\s@._+'’()\-]+/u", '', $q_raw );
    $q_sanitized = trim( preg_replace( '/\s+/u', ' ', (string) $q_sanitized ) );

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    try {
        // Fetch full presenters table; search is applied in PHP for safety.
        $sql = 'SELECT idPresentor AS id, name, email, phone_number, session_count
                FROM beta_2.GET_presenters_table
                ORDER BY name ASC;';

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
            'Failed to query presenters table via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote presenters table endpoint returned an HTTP error.',
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
            'Failed to decode presenters table JSON response.',
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
        // Already a list
        $rows_raw = $decoded;
    } else {
        // Single object or unexpected shape; wrap in an array so downstream code still works.
        $rows_raw = [ $decoded ];
    }

    $rows = [];
    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        // Support both canonical and raw DB column names.
        $id_raw = null;
        if ( array_key_exists( 'id', $row ) ) {
            $id_raw = $row['id'];
        } elseif ( array_key_exists( 'idPresentor', $row ) ) {
            $id_raw = $row['idPresentor'];
        }

        $name_raw  = array_key_exists( 'name', $row ) ? $row['name'] : '';
        $email_raw = array_key_exists( 'email', $row ) ? $row['email'] : '';

        $phone_raw = '';
        if ( array_key_exists( 'phone_number', $row ) ) {
            $phone_raw = $row['phone_number'];
        } elseif ( array_key_exists( 'phone', $row ) ) {
            $phone_raw = $row['phone'];
        }

        $session_count_raw = array_key_exists( 'session_count', $row ) ? $row['session_count'] : 0;

        $rows[] = [
            'id'            => $id_raw === null ? 0 : (int) $id_raw,
            'name'          => (string) $name_raw,
            'email'         => $email_raw === null ? '' : (string) $email_raw,
            'phone_number'  => $phone_raw === null ? '' : (string) $phone_raw,
            'session_count' => (int) $session_count_raw,
        ];
    }

    // Apply search filtering in PHP, case-insensitive over name/email/phone_number.
    if ( $q_sanitized !== '' ) {
        $needle = mb_strtolower( $q_sanitized );
        $rows   = array_values(
            array_filter(
                $rows,
                static function ( $r ) use ( $needle ) {
                    $name  = mb_strtolower( (string) ( $r['name'] ?? '' ) );
                    $email = mb_strtolower( (string) ( $r['email'] ?? '' ) );
                    $phone = mb_strtolower( (string) ( $r['phone_number'] ?? '' ) );

                    return ( $name !== ''  && mb_strpos( $name, $needle )  !== false )
                        || ( $email !== '' && mb_strpos( $email, $needle ) !== false )
                        || ( $phone !== '' && mb_strpos( $phone, $needle ) !== false );
                }
            )
        );
    }

    return new WP_REST_Response( $rows, 200 );

    /*
     * Previous implementation (direct MySQL connection and view query)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // 1) Decrypt DB creds
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
     * // 3) Optional search
     * $q_raw = (string) $request->get_param( 'q' );
     * // Allow letters/digits/spaces/basic punctuation for email and phone; collapse whitespace
     * $q_sanitized = preg_replace( \"/[^\\p{L}\\p{N}\\s@._+'’()\\-]+/u\", '', $q_raw );
     * $q_sanitized = trim( preg_replace( '/\\s+/u', ' ', (string) $q_sanitized ) );
     *
     * $rows = [];
     * if ( $q_sanitized !== '' ) {
     *     $like = '%' . strtolower( $q_sanitized ) . '%';
     *     $sql  = 'SELECT idPresentor AS id, name, email, phone_number, session_count
     *              FROM Test_Database.GET_presenters_table
     *              WHERE (LOWER(name) LIKE ? OR LOWER(email) LIKE ? OR LOWER(phone_number) LIKE ?)
     *              ORDER BY name ASC';
     *     $stmt = $conn->prepare( $sql );
     *     if ( ! $stmt ) {
     *         $conn->close();
     *         return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare query.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
     *     }
     *     $stmt->bind_param( 'sss', $like, $like, $like );
     *     if ( ! $stmt->execute() ) {
     *         $err = $stmt->error ?: $conn->error;
     *         $stmt->close();
     *         $conn->close();
     *         return new WP_Error( 'profdef_execute_failed', 'Failed to execute query.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
     *     }
     *     if ( $result = $stmt->get_result() ) {
     *         while ( $row = $result->fetch_assoc() ) {
     *             $row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;
     *             $row['session_count'] = isset( $row['session_count'] ) ? (int) $row['session_count'] : 0;
     *             $rows[] = $row;
     *         }
     *         $result->free();
     *     }
     *     $stmt->close();
     * } else {
     *     // No search → simple query
     *     $sql = 'SELECT idPresentor AS id, name, email, phone_number, session_count
     *             FROM Test_Database.GET_presenters_table
     *             ORDER BY name ASC';
     *     $res = $conn->query( $sql );
     *     if ( ! $res ) {
     *         $err = $conn->error;
     *         $conn->close();
     *         return new WP_Error( 'profdef_query_failed', 'Failed to query presenters table view.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
     *     }
     *     while ( $row = $res->fetch_assoc() ) {
     *         $row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;
     *         $row['session_count'] = isset( $row['session_count'] ) ? (int) $row['session_count'] : 0;
     *         $rows[] = $row;
     *     }
     *     $res->free();
     * }
     *
     * $conn->close();
     * return new WP_REST_Response( $rows, 200 );
     */
}
