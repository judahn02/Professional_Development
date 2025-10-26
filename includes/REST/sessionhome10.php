<?php
/**
 * ASLTA Members Name Check Endpoint
 * Returns [["Name", members_id], ...] from the external members table, validating
 * against WordPress user names. If any non-null external name does not match the
 * WP-computed name, returns 422 with mismatch details.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome10',
        [
            'methods'             => 'GET',
            'callback'            => 'aslta_get_members_names_check',
            'permission_callback' => '__return_true',
            'args'                => [
                'search_p' => [
                    'description' => 'Partial name search. Matches members.name via LIKE.',
                    'type'        => 'string',
                    'required'    => true,
                ],
                'limit' => [
                    'description' => 'Maximum rows to return (default 200, max 1000)',
                    'type'        => 'integer',
                    'required'    => false,
                ],
            ],
        ]
    );
} );

function aslta_get_members_names_check( WP_REST_Request $request ) {
    // 1) Decrypt external DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
    if ( ! $host || ! $name || ! $user ) {
        return new \WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
    }

    // 2) Parse params
    $search_raw = (string) $request->get_param( 'search_p' );
    $search_raw = is_string( $search_raw ) ? $search_raw : '';
    $search_raw = trim( $search_raw );
    if ( $search_raw === '' ) {
        return new \WP_Error( 'bad_param', 'search_p is required.', [ 'status' => 400 ] );
    }

    // Scrub: allow letters, spaces, hyphens, and apostrophes (ASCII ' and Unicode ’)
    $search_clean = preg_replace( "/[^\p{L}\s\-'’]+/u", '', $search_raw );
    $search_clean = trim( (string) $search_clean );
    if ( $search_clean === '' ) {
        return new \WP_Error( 'bad_param', 'search_p contained no valid characters after sanitization.', [ 'status' => 400 ] );
    }

    $limit_in     = (int) $request->get_param( 'limit' );
    $limit        = ( $limit_in > 0 && $limit_in <= 1000 ) ? $limit_in : 200;

    // 3) Connect external DB
    $conn = @new mysqli( $host, $user, $pass, $name );
    if ( $conn->connect_error ) {
        return new \WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
    }
    $conn->set_charset( 'utf8mb4' );

    // 4) Build and run query
    // Use LIKE with backslash-escaped pattern; omit ESCAPE clause for broader MySQL/MariaDB compatibility
    $sql         = "SELECT A.name, A.members_id, A.email FROM members AS A WHERE (A.name LIKE ? OR A.name IS NULL OR TRIM(A.name) = '') LIMIT " . (int) $limit;
    $bind_types  = 's';
    $bind_values = [];

    // Escape LIKE wildcards in search and wrap with % ... %
    $escape_like = function( $s ) {
        $s = str_replace( '\\', '\\\\', $s ); // escape backslash first
        $s = str_replace( '%', '\\%', $s );
        $s = str_replace( '_', '\\_', $s );
        return $s;
    };
    $pattern = '%' . $escape_like( $search_clean ) . '%';
    $bind_values[] = $pattern;

    $stmt = $conn->prepare( $sql );
    if ( ! $stmt ) {
        $conn->close();
        return new \WP_Error( 'profdef_prepare_failed', 'Failed to prepare query.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
    }
    $stmt->bind_param( $bind_types, ...$bind_values );
    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        return new \WP_Error( 'profdef_execute_failed', 'Failed to execute query.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
    }

    $rows = [];
    if ( $result = $stmt->get_result() ) {
        while ( $row = $result->fetch_assoc() ) {
            $rows[] = [
                'name'       => ( isset( $row['name'] ) ? trim( (string) $row['name'] ) : '' ),
                'members_id' => (int) ( $row['members_id'] ?? 0 ),
                'email'      => ( isset( $row['email'] ) ? trim( (string) $row['email'] ) : '' ),
            ];
        }
        $result->free();
    }
    $stmt->close();
    $conn->close();

    // 5) Compute WP names and validate
    $wp_name_for = function ( $uid ) {
        if ( $uid <= 0 ) { return ''; }
        $wp_user = get_userdata( $uid );
        if ( ! ( $wp_user instanceof WP_User ) ) { return ''; }
        $first = trim( (string) get_user_meta( $uid, 'first_name', true ) );
        $last  = trim( (string) get_user_meta( $uid, 'last_name', true ) );
        $combo = trim( $first . ' ' . $last );
        if ( $combo !== '' ) { return $combo; }
        if ( ! empty( $wp_user->display_name ) ) { return trim( (string) $wp_user->display_name ); }
        if ( ! empty( $wp_user->user_nicename ) ) { return trim( (string) $wp_user->user_nicename ); }
        return '';
    };

    $norm = function ( $s ) {
        $t = strtolower( trim( (string) $s ) );
        $t = preg_replace( '/\s+/', ' ', $t );
        return $t;
    };

    $mismatches = [];
    $out        = [];
    foreach ( $rows as $r ) {
        $ext   = $r['name'];
        $uid   = (int) $r['members_id'];
        $email = (string) $r['email'];
        $wpnm  = $wp_name_for( $uid );
        if ( $ext !== '' && $norm( $ext ) !== $norm( $wpnm ) ) {
            $mismatches[] = [ 'members_id' => $uid, 'external_name' => $ext, 'wp_name' => $wpnm ];
        }
        // Use WordPress name to fill output (prefer WP over external when no mismatch)
        $out[] = [ $wpnm !== '' ? $wpnm : $ext, $uid, $email ];
    }

    // Final filter by search_p against filled (WP) names
    $qnorm = $norm( $search_clean );
    $filtered = [];
    foreach ( $out as $row ) {
        $nm = isset( $row[0] ) ? (string) $row[0] : '';
        if ( $qnorm === '' || strpos( $norm( $nm ), $qnorm ) !== false ) {
            $filtered[] = $row;
        }
    }

    // If no rows to return, send 201 with empty array
    if ( empty( $rows ) || empty( $filtered ) ) {
        return new WP_REST_Response( [], 201 );
    }

    // Otherwise, if mismatches were detected, return 422 so caller can surface a toast
    if ( ! empty( $mismatches ) ) {
        return new \WP_Error(
            'profdef_name_mismatch',
            'One or more names do not match WordPress.',
            [ 'status' => 422, 'details' => $mismatches ]
        );
    }

    return new WP_REST_Response( $filtered, 200 );
}
