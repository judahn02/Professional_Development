<?php
/**
 * ProfDef REST: GET_attendees_table
 * Endpoint: GET /wp-json/profdef/v2/attendees_table
 *
 * Server-driven attendees table backing the admin "Attendees Table" UI.
 * - Pulls the underlying totals via beta_2.get_member_totals(NULL) (signed API)
 * - Applies search + sorting + pagination on the server
 * - Computes total pages on the server (uses GET_attendees_table_count view when unfiltered)
 *
 * Query params:
 * - page (int, default 1)
 * - per_page (int, default 20)
 * - q (string, optional search across name/email/phone/wp_id)
 * - sort (firstname|lastname|email|totalHours|totalCEUs) default lastname
 * - dir (asc|desc) default asc
 *
 * Returns:
 * { page, per_page, total_count, total_pages, rows: [...] }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/attendees_table',
        [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_get_attendees_table',
            'args'                => [
                'page' => [ 'type' => 'integer', 'required' => false ],
                'per_page' => [ 'type' => 'integer', 'required' => false ],
                'q' => [ 'type' => 'string', 'required' => false ],
                'sort' => [ 'type' => 'string', 'required' => false ],
                'dir' => [ 'type' => 'string', 'required' => false ],
            ],
        ]
    );
} );

function pd_attendees_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

function pd_attendees_extract_rows( $decoded ): array {
    if ( is_array( $decoded ) ) {
        if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
            return $decoded['rows'];
        }
        if ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
            return $decoded;
        }
        return [ $decoded ];
    }
    return [];
}

function pd_attendees_num( $v ): float {
    return is_numeric( $v ) ? (float) $v : 0.0;
}

function pd_attendees_total_hours( array $row ): float {
    // Stored proc commonly returns minutes; table displays hours.
    if ( array_key_exists( 'total_length', $row ) ) {
        return pd_attendees_num( $row['total_length'] ) / 60.0;
    }
    if ( array_key_exists( 'totalHours', $row ) ) {
        return pd_attendees_num( $row['totalHours'] ) / 60.0;
    }
    return 0.0;
}

function pd_attendees_total_ceus( array $row ): float {
    if ( array_key_exists( 'totalCEUs', $row ) ) {
        return pd_attendees_num( $row['totalCEUs'] );
    }
    if ( array_key_exists( 'total_ceu', $row ) ) {
        return pd_attendees_num( $row['total_ceu'] );
    }
    return 0.0;
}

function pd_attendees_get_wp_id( array $row ): int {
    // Convention in existing JS: wp_id is typically exposed as "id".
    foreach ( [ 'wp_id', 'id' ] as $k ) {
        if ( array_key_exists( $k, $row ) && is_numeric( $row[ $k ] ) ) {
            return (int) $row[ $k ];
        }
    }
    return 0;
}

function pd_attendees_row_text( array $row, string $key ): string {
    if ( ! array_key_exists( $key, $row ) || $row[ $key ] === null ) {
        return '';
    }
    return (string) $row[ $key ];
}

function pd_get_attendees_table( WP_REST_Request $request ) {
    $page     = max( 1, (int) $request->get_param( 'page' ) );
    $per_page = (int) $request->get_param( 'per_page' );
    if ( $per_page <= 0 ) {
        $per_page = 20;
    }
    $per_page = min( 200, $per_page );

    $q_raw = (string) $request->get_param( 'q' );
    $q_sanitized = preg_replace( "/[^\\p{L}\\p{N}\\s@._+'’()\\-]+/u", '', $q_raw );
    $q_sanitized = trim( preg_replace( '/\\s+/u', ' ', (string) $q_sanitized ) );
    $q_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q_sanitized ) : strtolower( $q_sanitized );

    $sort = (string) $request->get_param( 'sort' );
    $dir  = strtolower( (string) $request->get_param( 'dir' ) );
    if ( $dir !== 'desc' ) {
        $dir = 'asc';
    }

    $allowed_sorts = [ 'firstname', 'lastname', 'email', 'totalHours', 'totalCEUs' ];
    if ( ! in_array( $sort, $allowed_sorts, true ) ) {
        $sort = 'lastname';
    }

    // Cache raw rows briefly to avoid re-querying the remote API on every page click.
    $cache_key = 'pd_attendees_table_rows_v1';
    $rows_raw  = get_transient( $cache_key );
    $rows_ok   = is_array( $rows_raw );

    try {
        $schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';

        if ( ! $rows_ok ) {
            $sql = sprintf( 'CALL %s.get_member_totals(NULL);', $schema );

            if ( ! function_exists( 'aslta_signed_query' ) ) {
                $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
                $skeleton_path = $plugin_root . '/admin/skeleton2.php';
                if ( is_readable( $skeleton_path ) ) {
                    require_once $skeleton_path;
                }
            }

            if ( ! function_exists( 'aslta_signed_query' ) ) {
                return new WP_Error( 'aslta_helper_missing', 'Signed query helper is not available.', [ 'status' => 500 ] );
            }

            $result = aslta_signed_query( $sql );
            if ( $result['status'] < 200 || $result['status'] >= 300 ) {
                return new WP_Error(
                    'aslta_remote_http_error',
                    'Remote member totals endpoint returned an HTTP error.',
                    [
                        'status' => 500,
                        'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
                    ]
                );
            }

            $decoded = json_decode( (string) ( $result['body'] ?? '' ), true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error(
                    'aslta_json_error',
                    'Failed to decode member totals JSON response.',
                    [ 'status' => 500, 'debug' => ( WP_DEBUG ? json_last_error_msg() : null ) ]
                );
            }

            $rows_raw = pd_attendees_extract_rows( $decoded );
            set_transient( $cache_key, $rows_raw, 60 );
        }
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to query attendees table via remote API.',
            [ 'status' => 500, 'debug' => ( WP_DEBUG ? $e->getMessage() : null ) ]
        );
    }

    $rows = [];
    foreach ( (array) $rows_raw as $row ) {
        if ( is_array( $row ) ) {
            // If the proc includes attendee flag, ensure we only keep attendees.
            if ( array_key_exists( 'attendee', $row ) && is_numeric( $row['attendee'] ) ) {
                if ( (int) $row['attendee'] !== 1 ) {
                    continue;
                }
            }
            $rows[] = $row;
        }
    }

    // Search
    if ( $q_lower !== '' ) {
        $rows = array_values(
            array_filter(
                $rows,
                static function ( array $r ) use ( $q_lower ) {
                    $first = pd_attendees_row_text( $r, 'firstname' );
                    if ( $first === '' ) {
                        $first = pd_attendees_row_text( $r, 'first_name' );
                    }
                    $last = pd_attendees_row_text( $r, 'lastname' );
                    if ( $last === '' ) {
                        $last = pd_attendees_row_text( $r, 'last_name' );
                    }
                    $email = pd_attendees_row_text( $r, 'email' );
                    $phone = pd_attendees_row_text( $r, 'phone_number' );
                    if ( $phone === '' ) {
                        $phone = pd_attendees_row_text( $r, 'phone' );
                    }
                    $wp_id = (string) pd_attendees_get_wp_id( $r );

                    $hay = trim( $first . ' ' . $last );
                    $hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );
                    $email_l = function_exists( 'mb_strtolower' ) ? mb_strtolower( $email ) : strtolower( $email );
                    $phone_l = function_exists( 'mb_strtolower' ) ? mb_strtolower( $phone ) : strtolower( $phone );

                    return ( $hay !== '' && strpos( $hay, $q_lower ) !== false )
                        || ( $email_l !== '' && strpos( $email_l, $q_lower ) !== false )
                        || ( $phone_l !== '' && strpos( $phone_l, $q_lower ) !== false )
                        || ( $wp_id !== '0' && strpos( $wp_id, $q_lower ) !== false );
                }
            )
        );
    }

    // Total count/pages
    $total_count = 0;
    if ( $q_lower === '' ) {
        // Use the DB view-based count when unfiltered.
        try {
            $schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';
            $sql_ct = sprintf( 'SELECT * FROM %s.GET_attendees_table_count;', $schema );
            if ( ! function_exists( 'aslta_signed_query' ) ) {
                $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
                $skeleton_path = $plugin_root . '/admin/skeleton2.php';
                if ( is_readable( $skeleton_path ) ) {
                    require_once $skeleton_path;
                }
            }
            if ( function_exists( 'aslta_signed_query' ) ) {
                $ct_result = aslta_signed_query( $sql_ct );
                if ( $ct_result['status'] >= 200 && $ct_result['status'] < 300 ) {
                    $ct_decoded = json_decode( (string) ( $ct_result['body'] ?? '' ), true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        foreach ( pd_attendees_extract_rows( $ct_decoded ) as $row ) {
                            if ( is_array( $row ) ) {
                                foreach ( $row as $v ) {
                                    if ( is_numeric( $v ) ) {
                                        $total_count = (int) $v;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Fall back to counting rows array.
        }
    }
    if ( $total_count <= 0 ) {
        $total_count = count( $rows );
    }
    $total_pages = max( 1, (int) ceil( $total_count / $per_page ) );

    // Sort (server-side)
    usort(
        $rows,
        static function ( array $a, array $b ) use ( $sort, $dir ) {
            $mul = ( $dir === 'desc' ) ? -1 : 1;
            if ( $sort === 'totalHours' ) {
                $av = pd_attendees_total_hours( $a );
                $bv = pd_attendees_total_hours( $b );
                if ( $av < $bv ) return -1 * $mul;
                if ( $av > $bv ) return 1 * $mul;
                return 0;
            }
            if ( $sort === 'totalCEUs' ) {
                $av = pd_attendees_total_ceus( $a );
                $bv = pd_attendees_total_ceus( $b );
                if ( $av < $bv ) return -1 * $mul;
                if ( $av > $bv ) return 1 * $mul;
                return 0;
            }

            $map = [
                'firstname' => [ 'firstname', 'first_name' ],
                'lastname'  => [ 'lastname', 'last_name' ],
                'email'     => [ 'email' ],
            ];
            $keys = $map[ $sort ] ?? [ $sort ];

            $va = '';
            $vb = '';
            foreach ( $keys as $k ) {
                if ( $va === '' && array_key_exists( $k, $a ) && $a[ $k ] !== null ) {
                    $va = (string) $a[ $k ];
                }
                if ( $vb === '' && array_key_exists( $k, $b ) && $b[ $k ] !== null ) {
                    $vb = (string) $b[ $k ];
                }
            }
            $va = function_exists( 'mb_strtolower' ) ? mb_strtolower( $va ) : strtolower( $va );
            $vb = function_exists( 'mb_strtolower' ) ? mb_strtolower( $vb ) : strtolower( $vb );

            if ( $va === $vb ) return 0;
            return ( $va < $vb ? -1 : 1 ) * $mul;
        }
    );

    // Pagination
    $page = min( $page, $total_pages );
    $offset = ( $page - 1 ) * $per_page;
    $page_rows = array_slice( $rows, $offset, $per_page );

    return new WP_REST_Response(
        [
            'page'        => $page,
            'per_page'    => $per_page,
            'total_count' => $total_count,
            'total_pages' => $total_pages,
            'rows'        => array_values( $page_rows ),
        ],
        200
    );
}
