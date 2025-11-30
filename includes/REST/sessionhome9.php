<?php
/**
 * ProfDef REST: sessionhome9
 * Purpose: Register attendance entries via stored procedure sp_register_attendance
 * Endpoint: POST /wp-json/profdef/v2/sessionhome9
 * Input: JSON array body like [[member_id, session_id, status], ...]
 * Returns: { success: true, added: N, failed: M, results: [{index, ok, error?}] }
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
function pd_sessionhome9_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome9',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            // 'permission_callback' => 'pd_sessions_permission',
            'permission_callback' => '__return_true',
            'callback'            => 'pd_sessionhome9_register_attendance',
        ]
    );
} );

/**
 * Handler: POST /profdef/v2/sessionhome9
 */
function pd_sessionhome9_register_attendance( WP_REST_Request $req ) {
    // 0) Parse body: expect top-level array of [member_id, session_id, status]
    $payload = $req->get_json_params();
    if ( ! is_array( $payload ) ) {
        // Fallback: allow 'items' param as JSON string or array
        $items = $req->get_param( 'items' );
        if ( is_string( $items ) ) {
            $decoded = json_decode( $items, true );
            $payload = is_array( $decoded ) ? $decoded : null;
        } elseif ( is_array( $items ) ) {
            $payload = $items;
        }
    }
    if ( ! is_array( $payload ) ) {
        return new WP_Error( 'bad_request', 'Body must be an array of [member_id, session_id, status] lists.', [ 'status' => 400 ] );
    }

    // Pre-validate everything for all-or-nothing
    $allowed_status = [ 'Certified', 'Master', 'None' ];
    $normalized = [];
    foreach ( $payload as $idx => $triple ) {
        if ( ! is_array( $triple ) || count( $triple ) < 3 ) {
            return new WP_Error( 'bad_request', "Invalid item at index {$idx}: must be [member_id, session_id, status]", [ 'status' => 400, 'index' => $idx ] );
        }
        $mid = (int) $triple[0];
        $sid = (int) $triple[1];
        $status_raw = (string) $triple[2];
        $status_trim = trim( $status_raw );
        if ( $mid <= 0 || $sid <= 0 || $status_trim === '' ) {
            return new WP_Error( 'bad_request', "Invalid values at index {$idx}.", [ 'status' => 400, 'index' => $idx ] );
        }
        $status = null;
        foreach ( $allowed_status as $label ) {
            if ( strtolower( $status_trim ) === strtolower( $label ) ) { $status = $label; break; }
        }
        if ( $status === null ) {
            return new WP_Error( 'bad_request', "Bad status at index {$idx}. Must be one of: Certified, Master, None.", [ 'status' => 400, 'index' => $idx ] );
        }
        $normalized[] = [ $mid, $sid, $status ];
    }

    // Execute each registration via the signed API.
    // Note: previously this was wrapped in a single DB transaction; now each
    // sp_register_attendance call is executed independently via the remote API.
    // The function still fails fast on the first error and reports the index.
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
            [ 'status' => 500 ]
        );
    }

    // Execute all items; stop on first failure.
    foreach ( $normalized as $idx => $triple ) {
        [ $mid, $sid, $status ] = $triple;

        $status_lit = pd_sessionhome9_sql_quote( $status );
        $schema     = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
        $sql        = sprintf(
            'CALL %s.sp_register_attendance(%d, %d, %s);',
            $schema,
            $mid,
            $sid,
            $status_lit
        );

        try {
            $result = aslta_signed_query( $sql );
        } catch ( \Throwable $e ) {
            return new WP_Error(
                'aslta_remote_error',
                'Attendance registration failed via remote API.',
                [
                    'status' => 400,
                    'index'  => $idx,
                    'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
                ]
            );
        }

        if ( $result['status'] < 200 || $result['status'] >= 300 ) {
            return new WP_Error(
                'aslta_remote_http_error',
                'Attendance registration failed via remote API.',
                [
                    'status' => 400,
                    'index'  => $idx,
                    'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
                ]
            );
        }
    }

    return new WP_REST_Response( [
        'success' => true,
        'added'   => count( $normalized ),
        'failed'  => 0,
        'results' => array_map( function( $i ) { return [ 'index' => $i, 'ok' => true ]; }, array_keys( $normalized ) ),
    ], 200 );
}
