<?php
/**
 * ProfDef REST: PUT_session
 * Endpoint: PUT /wp-json/profdef/v2/session
 * Purpose: Update a session via stored procedure Test_Database.PUT_session
 *
 * Expects JSON body (or form params fallback) with fields:
 * {
 *   session_id: number,                 // required
 *   title: string,                      // required (<=256)
 *   date: 'YYYY-MM-DD',                 // required
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
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
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

    // Validate date (YYYY-MM-DD)
    $date = is_string( $date_raw ) ? trim( $date_raw ) : '';
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        return new WP_Error( 'bad_param', 'date must be YYYY-MM-DD.', [ 'status' => 400 ] );
    }
    $parts = explode( '-', $date );
    if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
        return new WP_Error( 'bad_param', 'date is not a valid calendar date.', [ 'status' => 400 ] );
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

    // 1) Decrypt external DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
    if ( ! $host || ! $name || ! $user ) {
        return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
    }

    // 2) Connect
    $conn = @new mysqli( $host, $user, $pass, $name );
    if ( $conn->connect_error ) {
        return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
    }
    $conn->set_charset( 'utf8mb4' );

    // 3) Prepare + execute stored procedure
    $sql = 'CALL Test_Database.PUT_session(?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $conn->prepare( $sql );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
    }

    // Bind params: i s s i s s s s (NULLs allowed for specific_event and ceu_consideration)
    $stmt->bind_param(
        'ississss',
        $session_id,
        $title,
        $date,
        $length,
        $specific_event,
        $session_type,
        $ceu_consideration,
        $event_type
    );

    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        // Map custom SIGNAL to 404 if session not found
        if ( strpos( (string) $err, 'PUT_session: session not found' ) !== false ) {
            return new WP_Error( 'rest_not_found', 'Session not found.', [ 'status' => 404 ] );
        }
        return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
    }

    // 4) Read the result row (rows_updated, session_id)
    $rows_updated = 0; $sid_out = $session_id;
    if ( $result = $stmt->get_result() ) {
        if ( $row = $result->fetch_assoc() ) {
            if ( isset( $row['rows_updated'] ) ) $rows_updated = (int) $row['rows_updated'];
            if ( isset( $row['session_id'] ) ) $sid_out = (int) $row['session_id'];
        }
        $result->free();
    }
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) { $extra->free(); }
    }
    $stmt->close();
    $conn->close();

    return new WP_REST_Response( [
        'success'      => true,
        'session_id'   => (int) $sid_out,
        'rows_updated' => (int) $rows_updated,
    ], 200 );
}

