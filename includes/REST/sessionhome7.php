<?php
/**
 * ProfDef REST: sessionhome7
 * Purpose: Return distinct parent events from external DB.
 * Endpoint: GET /wp-json/profdef/v2/sessionhome7
 * SQL: SELECT DISTINCT specific_event FROM Test_Database.session ORDER BY specific_event;
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal SQL string escaper for values embedded into a query.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string|null $value
 * @return string SQL literal or NULL
 */
function pd_sessionhome7_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome7',
        [
            'methods'             => WP_REST_Server::READABLE, // GET
            'permission_callback' => '__return_true',
            //'permission_callback' => 'pd_sessions_permission',
            'callback'            => 'pd_sessionhome7_get_parent_events',
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome7
 */
function pd_sessionhome7_get_parent_events( WP_REST_Request $request ) {
    $events = [];

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Query distinct specific_event values from {schema}.sessions; keep SQL semantics the same.
    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql    = 'SELECT DISTINCT parent_event FROM ' . $schema . '.sessions ORDER BY parent_event';

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
            'Failed to load parent events via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote parent events endpoint returned an HTTP error.',
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
            'Failed to decode parent events JSON response.',
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

    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        if ( array_key_exists( 'parent_event', $row ) ) {
            $val = $row['parent_event'];
            if ( is_string( $val ) && $val !== '' ) {
                $events[] = $val;
            }
        }
    }

    return new WP_REST_Response( $events, 200 );

    /*
     * Previous implementation (direct MySQL connection and SELECT DISTINCT)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // Decrypt external DB creds
     * $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
     * $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
     * $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
     * $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
     *
     * if ( ! $host || ! $name || ! $user ) {
     *     return new WP_Error(
     *         'profdef_db_creds_missing',
     *         'Database credentials are not configured.',
     *         [ 'status' => 500 ]
     *     );
     * }
     *
     * // Connect to external DB
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
     * // Query distinct specific_event values (escape DB + reserved table name)
     * // Keep SQL identical to original intent; do not TRIM or filter in SQL
     * $db  = str_replace('`', '``', $name);
     * $tbl = \"`{$db}`.`session`\";
     * $sql = \"SELECT DISTINCT specific_event FROM {$tbl} ORDER BY specific_event\";
     *
     * $events = [];
     * if ( $res = $conn->query( $sql ) ) {
     *     while ( $row = $res->fetch_assoc() ) {
     *         if ( array_key_exists( 'specific_event', $row ) ) {
     *             // Drop empty-string values; preserve others exactly as returned by SQL
     *             $val = $row['specific_event'];
     *             if (is_string($val) && $val !== '') {
     *                 $events[] = $val;
     *             }
     *         }
     *     }
     *     $res->free();
     * } else {
     *     $err = WP_DEBUG ? $conn->error : null;
     *     $conn->close();
     *     return new WP_Error( 'profdef_query_failed', 'Failed to load parent events.', [ 'status' => 500, 'debug' => $err ] );
     * }
     *
     * $conn->close();
     *
     * return new WP_REST_Response( $events, 200 );
     */
}
