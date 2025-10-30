<?php
/**
 * ProfDef REST: GET_presenters_table
 * Endpoint: GET /wp-json/profdef/v2/presenters_table
 * Returns rows from Test_Database.GET_presenters_table view.
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
            'permission_callback' => 'pd_presenters_permission', // reuse existing nonce+cap check
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
    // 1) Decrypt DB creds
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

    // 3) Optional search
    $q_raw = (string) $request->get_param( 'q' );
    // Allow letters/digits/spaces/basic punctuation for email and phone; collapse whitespace
    $q_sanitized = preg_replace( "/[^\p{L}\p{N}\s@._+'’()\-]+/u", '', $q_raw );
    $q_sanitized = trim( preg_replace( '/\s+/u', ' ', (string) $q_sanitized ) );

    $rows = [];
    if ( $q_sanitized !== '' ) {
        $like = '%' . strtolower( $q_sanitized ) . '%';
        $sql  = 'SELECT idPresentor AS id, name, email, phone_number, session_count
                 FROM Test_Database.GET_presenters_table
                 WHERE (LOWER(name) LIKE ? OR LOWER(email) LIKE ? OR LOWER(phone_number) LIKE ?)
                 ORDER BY name ASC';
        $stmt = $conn->prepare( $sql );
        if ( ! $stmt ) {
            $conn->close();
            return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare query.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
        }
        $stmt->bind_param( 'sss', $like, $like, $like );
        if ( ! $stmt->execute() ) {
            $err = $stmt->error ?: $conn->error;
            $stmt->close();
            $conn->close();
            return new WP_Error( 'profdef_execute_failed', 'Failed to execute query.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
        }
        if ( $result = $stmt->get_result() ) {
            while ( $row = $result->fetch_assoc() ) {
                $row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;
                $row['session_count'] = isset( $row['session_count'] ) ? (int) $row['session_count'] : 0;
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
    } else {
        // No search → simple query
        $sql = 'SELECT idPresentor AS id, name, email, phone_number, session_count
                FROM Test_Database.GET_presenters_table
                ORDER BY name ASC';
        $res = $conn->query( $sql );
        if ( ! $res ) {
            $err = $conn->error;
            $conn->close();
            return new WP_Error( 'profdef_query_failed', 'Failed to query presenters table view.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
        }
        while ( $row = $res->fetch_assoc() ) {
            $row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;
            $row['session_count'] = isset( $row['session_count'] ) ? (int) $row['session_count'] : 0;
            $rows[] = $row;
        }
        $res->free();
    }

    $conn->close();
    return new WP_REST_Response( $rows, 200 );
}
