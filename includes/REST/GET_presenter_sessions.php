<?php
/**
 * ProfDef REST: GET_presenter_sessions
 * Endpoint: GET /wp-json/profdef/v2/presenter/sessions?presenter_id=123
 * Calls stored procedure Test_Database.GET_presenter_sessions(IN p_idPresentor INT)
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
            'permission_callback' => 'pd_presenters_permission',
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

    // Decrypt DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
    if ( ! $host || ! $name || ! $user ) {
        return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
    }

    $conn = @new mysqli( $host, $user, $pass, $name );
    if ( $conn->connect_error ) {
        return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
    }
    $conn->set_charset( 'utf8mb4' );

    $stmt = $conn->prepare( 'CALL Test_Database.GET_presenter_sessions(?)' );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
    }
    $stmt->bind_param( 'i', $pid );
    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
    }

    $rows = [];
    if ( $result = $stmt->get_result() ) {
        while ( $row = $result->fetch_assoc() ) {
            $rows[] = [
                'session_id'           => isset( $row['session_id'] ) ? (int) $row['session_id'] : 0,
                'session_title'        => isset( $row['session_title'] ) ? (string) $row['session_title'] : '',
                'session_date'         => isset( $row['session_date'] ) ? (string) $row['session_date'] : '',
                'session_parent_event' => isset( $row['session_parent_event'] ) ? (string) $row['session_parent_event'] : null,
            ];
        }
        $result->free();
    }
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) { $extra->free(); }
    }
    $stmt->close();
    $conn->close();

    return new WP_REST_Response( $rows, 200 );
}

