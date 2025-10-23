<?php
/**
 * ProfDef REST: sessionhome4
 * Purpose: Search presenters by name via stored procedure sp_search_presentor(IN p_term VARCHAR(255)).
 * Endpoint: /wp-json/profdef/v2/sessionhome4?term=...
 *
 * Returns: [{ id, name }]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Sanitize callback: keep only letters (A–Z, a–z). Trim and cap to 255.
function pd_sessionhome4_letters_only( $value ) {
    $term = trim( (string) $value );
    // Letters only (ASCII). Remove everything else.
    $term = preg_replace( '/[^a-zA-Z]+/', '', $term );
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $term ) > 255 ) {
            $term = mb_substr( $term, 0, 255 );
        }
    } else {
        if ( strlen( $term ) > 255 ) {
            $term = substr( $term, 0, 255 );
        }
    }
    return $term;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome4',
        [
            'methods'             => WP_REST_Server::READABLE, // GET
            'permission_callback' => '__return_true',
            'callback'            => 'pd_sessionhome4_search_presenters',
            'args'                => [
                'term' => [
                    'description'      => 'Search term for presenter name (letters only)',
                    'type'             => 'string',
                    'required'         => true,
                    'sanitize_callback'=> 'pd_sessionhome4_letters_only',
                ],
            ],
        ]
    );
} );

/**
 * Handler: GET /profdef/v2/sessionhome4?term=...
 */
function pd_sessionhome4_search_presenters( WP_REST_Request $request ) {
    // 0) Validate input
    $term_raw = $request->get_param( 'term' );
    if ( $term_raw === null ) {
        return new WP_Error( 'bad_param', 'term is required.', [ 'status' => 400 ] );
    }
    // Value has been sanitized to letters-only by sanitize_callback above.
    $term = (string) $term_raw;
    if ( $term === '' ) {
        return new WP_Error( 'bad_param', 'term cannot be empty.', [ 'status' => 400 ] );
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

    // 3) Prepare + execute stored procedure sp_search_presentor
    // Qualify DB like other endpoints do with Test_Database.*
    $stmt = $conn->prepare( 'CALL Test_Database.sp_search_presentor(?)' );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
    }

    $stmt->bind_param( 's', $term );
    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
    }

    // 4) Collect result rows
    $rows = [];
    if ( $result = $stmt->get_result() ) {
        while ( $row = $result->fetch_assoc() ) {
            // Normalize types
            if ( isset( $row['id'] ) ) {
                $row['id'] = (int) $row['id'];
            }
            $rows[] = [
                'id'   => $row['id'] ?? 0,
                'name' => isset( $row['name'] ) ? (string) $row['name'] : '',
            ];
        }
        $result->free();
    }

    // 5) Drain any additional result sets if driver returns them
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) {
            $extra->free();
        }
    }

    $stmt->close();
    $conn->close();

    return new WP_REST_Response( $rows, 200 );
}
