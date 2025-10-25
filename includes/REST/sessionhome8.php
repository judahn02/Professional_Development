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
                'length_minutes' => [ 'type' => 'integer', 'required' => true ],
                'session_title' => [ 'type' => 'string', 'required' => true ],
                'specific_event' => [ 'type' => 'string', 'required' => false ],
                'type_of_session_id' => [ 'type' => 'integer', 'required' => true ],
                'event_type_id' => [ 'type' => 'integer', 'required' => true ],
                'ceu_id' => [ 'type' => 'integer', 'required' => false ],
                'presenters_csv' => [ 'type' => 'string', 'required' => false ],
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

    // 3) Prepare + execute stored procedure
    $stmt = $conn->prepare( 'CALL Test_Database.sp_create_session(?, ?, ?, ?, ?, ?, ?, ?)' );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
    }

    // Bind params (s i s s i i i s)
    $stmt->bind_param(
        'sissiiis',
        $session_date,
        $length_minutes,
        $session_title,
        $specific_event,
        $type_of_session_id,
        $event_type_id,
        $ceu_id,
        $presenters_csv
    );

    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
    }

    // 4) Read new id from first result set (stored procedure must return it)
    $new_id = 0;
    if ( $result = $stmt->get_result() ) {
        if ( $row = $result->fetch_assoc() ) {
            if ( isset( $row['id'] ) )         $new_id = (int) $row['id'];
            elseif ( isset( $row['session_id'] ) ) $new_id = (int) $row['session_id'];
            elseif ( isset( $row['sessionId'] ) )  $new_id = (int) $row['sessionId'];
        }
        $result->free();
    }
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) { $extra->free(); }
    }
    $stmt->close();
    $conn->close();

    if ( $new_id <= 0 ) {
        return new WP_Error(
            'profdef_no_id',
            'Stored procedure did not return a new session id. Ensure sp_create_session SELECTs the created session_id.',
            [ 'status' => 500 ]
        );
    }

    return new WP_REST_Response( [ 'success' => true, 'id' => $new_id ], 201 );
}
