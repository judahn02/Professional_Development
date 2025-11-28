<?php
/**
 * ProfDef REST: GET_presenter_sessions
 * Endpoint: GET /wp-json/profdef/v2/presenter/sessions?presenter_id=123
 * Calls stored procedure beta_2.GET_presenter_sessions(IN p_idPresentor INT)
 * Returns: [{ session_id, session_title, session_date, session_parent_event }, ...]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/presenter/sessions',
        [
            'methods'             => WP_REST_Server::READABLE,
            // 'permission_callback' => 'pd_presenters_permission',
            'permission_callback' => '__return_true',
            'callback'            => 'pd_get_presenter_sessions',
            'args'                => [
                'presenter_id' => [
                    'description' => 'Presenter identifier',
                    'type'        => 'integer',
                    'required'    => true,
                ],
            ],
        ]
    );
} );

function pd_get_presenter_sessions( WP_REST_Request $request ) {
    $pid = (int) $request->get_param( 'presenter_id' );
    if ( $pid <= 0 ) {
        return new WP_Error( 'bad_param', 'presenter_id must be a positive integer.', [ 'status' => 400 ] );
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    try {
        // Build stored-procedure call as a simple SQL string; presenter_id is already validated/int-cast.
        $sql = sprintf( 'CALL beta_2.GET_presenter_sessions(%d);', $pid );

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
            'Failed to query presenter sessions via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote presenter sessions endpoint returned an HTTP error.',
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
            'Failed to decode presenter sessions JSON response.',
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
        $session_id_raw = null;
        if ( array_key_exists( 'session_id', $row ) ) {
            $session_id_raw = $row['session_id'];
        } elseif ( array_key_exists( 'id', $row ) ) {
            $session_id_raw = $row['id'];
        }

        $session_title_raw = '';
        if ( array_key_exists( 'session_title', $row ) ) {
            $session_title_raw = $row['session_title'];
        } elseif ( array_key_exists( 'title', $row ) ) {
            $session_title_raw = $row['title'];
        }

        $session_date_raw = '';
        if ( array_key_exists( 'session_date', $row ) ) {
            $session_date_raw = $row['session_date'];
        } elseif ( array_key_exists( 'date', $row ) ) {
            $session_date_raw = $row['date'];
        }

        $session_parent_event_raw = null;
        if ( array_key_exists( 'session_parent_event', $row ) ) {
            $session_parent_event_raw = $row['session_parent_event'];
        } elseif ( array_key_exists( 'specific_event', $row ) ) {
            $session_parent_event_raw = $row['specific_event'];
        }

        $rows[] = [
            'session_id'           => $session_id_raw === null ? 0 : (int) $session_id_raw,
            'session_title'        => (string) $session_title_raw,
            'session_date'         => $session_date_raw === null ? '' : (string) $session_date_raw,
            'session_parent_event' => $session_parent_event_raw === null ? null : (string) $session_parent_event_raw,
        ];
    }

    return new WP_REST_Response( $rows, 200 );

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // Decrypt DB creds
     * $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
     * $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
     * $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
     * $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
     * if ( ! $host || ! $name || ! $user ) {
     *     return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
     * }
     *
     * $conn = @new mysqli( $host, $user, $pass, $name );
     * if ( $conn->connect_error ) {
     *     return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
     * }
     * $conn->set_charset( 'utf8mb4' );
     *
     * $stmt = $conn->prepare( 'CALL Test_Database.GET_presenter_sessions(?)' );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
     * }
     * $stmt->bind_param( 'i', $pid );
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
     * }
     *
     * $rows = [];
     * if ( $result = $stmt->get_result() ) {
     *     while ( $row = $result->fetch_assoc() ) {
     *         $rows[] = [
     *             'session_id'           => isset( $row['session_id'] ) ? (int) $row['session_id'] : 0,
     *             'session_title'        => isset( $row['session_title'] ) ? (string) $row['session_title'] : '',
     *             'session_date'         => isset( $row['session_date'] ) ? (string) $row['session_date'] : '',
     *             'session_parent_event' => isset( $row['session_parent_event'] ) ? (string) $row['session_parent_event'] : null,
     *         ];
     *     }
     *     $result->free();
     * }
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $extra = $stmt->get_result() ) { $extra->free(); }
     * }
     * $stmt->close();
     * $conn->close();
     *
     * return new WP_REST_Response( $rows, 200 );
     */
}
