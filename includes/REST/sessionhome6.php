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

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome6',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            // 'permission_callback' => 'pd_sessions_permission',
            'permission_callback' => '__return_true',
            'callback'            => 'pd_sessionhome6_add_lookup_value',
            'args'                => [
                'target' => [
                    'description' => "Target table: 'ceu_consideration' | 'event_type' | 'type_of_session'",
                    'type'        => 'string',
                    'required'    => true,
                ],
                'value'  => [
                    'description' => 'Value to insert into the selected lookup table',
                    'type'        => 'string',
                    'required'    => true,
                ],
            ],
        ]
    );
} );

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
    $stmt = $conn->prepare( 'CALL Test_Database.sp_add_lookup_value(?, ?)' );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
    }
    $stmt->bind_param( 'ss', $target, $value );

    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
    }

    // 4) Read returned id from first result set (SELECT LAST_INSERT_ID() AS id)
    $new_id = 0;
    if ( $res = $stmt->get_result() ) {
        if ( $row = $res->fetch_assoc() ) {
            $new_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
        }
        $res->free();
    }
    // Drain additional result sets if any
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) {
            $extra->free();
        }
    }
    $stmt->close();
    $conn->close();

    return new WP_REST_Response( [
        'success' => true,
        'id'      => $new_id,
        'target'  => $target,
        'value'   => $value,
    ], 201 );
}

