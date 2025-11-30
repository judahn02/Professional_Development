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

/**
 * Minimal SQL string escaper for values embedded into a query/statement.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string|null $value
 * @return string SQL literal or NULL
 */
function pd_sessionhome10_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
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
                'only_attendees_non_presenters' => [
                    'description' => 'When truthy, restrict to rows where attendee=1 AND presenter=0.',
                    'type'        => 'boolean',
                    'required'    => false,
                ],
            ],
        ]
    );
} );

function aslta_get_members_names_check( WP_REST_Request $request ) {
    // 1) Parse params
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
    $only_att_np  = $request->get_param( 'only_attendees_non_presenters' );
    $only_att_np  = ! empty( $only_att_np );

    // 2) Build SQL against {schema}.person using CONCAT_WS(first_name, last_name) as name.
    // Use LIKE with backslash-escaped pattern; omit ESCAPE clause for broader MySQL/MariaDB compatibility.
    $escape_like = function( $s ) {
        $s = str_replace( '\\', '\\\\', $s ); // escape backslash first
        $s = str_replace( '%', '\\%', $s );
        $s = str_replace( '_', '\\_', $s );
        return $s;
    };
    $pattern     = '%' . $escape_like( $search_clean ) . '%';
    $pattern_lit = pd_sessionhome10_sql_quote( $pattern );

    $rows = [];

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Note: the members table is now beta_2.person; we derive a full name from first_name/last_name.
    $where = '(CONCAT_WS(" ", A.first_name, A.last_name) LIKE ' . $pattern_lit
           . ' OR CONCAT_WS(" ", A.first_name, A.last_name) IS NULL '
           . ' OR TRIM(CONCAT_WS(" ", A.first_name, A.last_name)) = "")';

    if ( $only_att_np ) {
        $where .= ' AND A.attendee = 1 AND A.presenter = 0';
    }

    $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql = 'SELECT '
         . 'CONCAT_WS(" ", A.first_name, A.last_name) AS name, '
         . 'A.id AS members_id, '
         . 'A.email, '
         . 'A.wp_id AS wp_id '
         . 'FROM ' . $schema . '.person AS A '
         . 'WHERE ' . $where . ' '
         . 'LIMIT ' . (int) $limit;

    try {
        if ( ! function_exists( 'aslta_signed_query' ) ) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
            $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if ( is_readable( $skeleton_path ) ) {
                require_once $skeleton_path;
            }
        }

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            return new \WP_Error(
                'aslta_helper_missing',
                'Signed query helper is not available.',
                [ 'status' => 500 ]
            );
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new \WP_Error(
            'aslta_remote_error',
            'Failed to query members via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new \WP_Error(
            'aslta_remote_http_error',
            'Remote members endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( $result['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new \WP_Error(
            'aslta_json_error',
            'Failed to decode members JSON response.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
            ]
        );
    }

    // Normalise to a list of row arrays.
    if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
        $rows_raw = $decoded['rows'];
    } elseif ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
        $rows_raw = $decoded;
    } else {
        $rows_raw = [ $decoded ];
    }

    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $rows[] = [
            'name'       => isset( $row['name'] ) ? trim( (string) $row['name'] ) : '',
            'members_id' => isset( $row['members_id'] ) ? (int) $row['members_id'] : 0,
            'wp_id'      => isset( $row['wp_id'] ) ? (int) $row['wp_id'] : 0,
            'email'      => isset( $row['email'] ) ? trim( (string) $row['email'] ) : '',
        ];
    }

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
        $ext    = $r['name'];
        $person = (int) $r['members_id']; // beta_2.person.id (external person id)
        $wp_id  = isset( $r['wp_id'] ) ? (int) $r['wp_id'] : 0; // WordPress user ID, when linked
        $email  = (string) $r['email'];
        $wpnm   = $wp_name_for( $wp_id );

        // Only treat as mismatch when there is a linked WP user with a non-empty name that differs.
        if ( $wp_id > 0 && $ext !== '' && $wpnm !== '' && $norm( $ext ) !== $norm( $wpnm ) ) {
            $mismatches[] = [
                'members_id'    => $person,
                'wp_id'         => $wp_id,
                'external_name' => $ext,
                'wp_name'       => $wpnm,
            ];
        }
        // Use WordPress name to fill output (prefer WP over external when no mismatch)
        $out[] = [ $wpnm !== '' ? $wpnm : $ext, $person, $email ];
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
