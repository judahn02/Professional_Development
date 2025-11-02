<?php
/**
 * ProfDef REST: GET member administrative service
 * Endpoint: GET /wp-json/profdef/v2/member/administrative_service?members_id=123
 * Calls: CALL Test_Database.GET_presenter_administrative_service(p_members_id)
 * Returns: [{ start_service, end_service, type, ceu_weight }, ...]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/member/administrative_service',
        [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'callback'            => 'pd_get_member_admin_service',
            'args'                => [
                'members_id' => [
                    'description' => 'WordPress user ID (members_id) to fetch administrative service for.',
                    'type'        => 'integer',
                    'required'    => true,
                ],
            ],
        ]
    );
} );

function pd_get_member_admin_service( WP_REST_Request $request ) {
    $mid = (int) $request->get_param( 'members_id' );
    if ( $mid <= 0 ) {
        return new WP_Error( 'bad_param', 'members_id must be a positive integer.', [ 'status' => 400 ] );
    }

    // Decrypt external DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
    if ( ! $host || ! $name || ! $user ) {
        return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
    }

    // Connect
    $conn = @new mysqli( $host, $user, $pass, $name );
    if ( $conn->connect_error ) {
        return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
    }
    $conn->set_charset( 'utf8mb4' );

    // Prepare + call proc
    $stmt = $conn->prepare( 'CALL Test_Database.GET_presenter_administrative_service(?)' );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
    }
    $stmt->bind_param( 'i', $mid );
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
                'start_service' => isset( $row['start_service'] ) ? (string) $row['start_service'] : null,
                'end_service'   => array_key_exists( 'end_service', $row ) ? ( $row['end_service'] === null ? null : (string) $row['end_service'] ) : null,
                'type'          => isset( $row['type'] ) ? (string) $row['type'] : '',
                'ceu_weight'    => isset( $row['ceu_weight'] ) ? (string) $row['ceu_weight'] : '',
            ];
        }
        $result->free();
    }

    // Drain additional result sets if any
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) { $extra->free(); }
    }
    $stmt->close();
    $conn->close();

    return new WP_REST_Response( $rows, 200 );
}

