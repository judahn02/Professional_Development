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

    // 1) Decrypt external DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );

    if ( ! $host || ! $name || ! $user ) {
        return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
    }

    // 2) Connect to external DB
    $conn = @new mysqli( $host, $user, $pass, $name );
    if ( $conn->connect_error ) {
        return new WP_Error(
            'mysql_not_connect',
            'Database connection failed.',
            [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->connect_error : null ]
        );
    }
    $conn->set_charset( 'utf8mb4' );

    // 3) Start transaction + prepare the stored procedure call once
    $conn->begin_transaction();
    $stmt = $conn->prepare( 'CALL Test_Database.sp_register_attendance(?, ?, ?)' );
    if ( ! $stmt ) {
        $conn->rollback();
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
    }
    // Execute all-or-nothing
    foreach ( $normalized as $idx => $triple ) {
        [$mid, $sid, $status] = $triple;
        if ( ! $stmt->bind_param( 'iis', $mid, $sid, $status ) ) {
            $err = $stmt->error ?: $conn->error;
            $stmt->close();
            $conn->rollback();
            $conn->close();
            return new WP_Error( 'profdef_bind_failed', 'Failed to bind parameters.', [ 'status' => 500, 'index' => $idx, 'debug' => WP_DEBUG ? $err : null ] );
        }
        if ( ! $stmt->execute() ) {
            $err = $stmt->error ?: $conn->error;
            $stmt->close();
            $conn->rollback();
            $conn->close();
            return new WP_Error( 'transaction_rollback', 'Attendance registration failed. Transaction rolled back.', [ 'status' => 400, 'index' => $idx, 'debug' => WP_DEBUG ? $err : null ] );
        }
        // Drain any result sets (if driver produces them)
        while ( $stmt->more_results() && $stmt->next_result() ) {
            if ( $extra = $stmt->get_result() ) { $extra->free(); }
        }
    }

    $stmt->close();
    $conn->commit();
    $conn->close();

    return new WP_REST_Response( [
        'success' => true,
        'added'   => count( $normalized ),
        'failed'  => 0,
        'results' => array_map( function( $i ) { return [ 'index' => $i, 'ok' => true ]; }, array_keys( $normalized ) ),
    ], 200 );
}
