<?php
/**
 * ProfDef REST: GET_session_table_count
 * Endpoint: GET /wp-json/profdef/v2/sessions/ct
 * Purpose: Return total count of sessions from beta_2.GET_session_table_count view (via signed API).
 *
 * Query params:
 * - per_page (int, optional): if provided, also returns total_pages computed server-side.
 * - q (string, optional): when provided, count matches /sessionhome filtering.
 *
 * Returns:
 * { "count": <int>, "per_page": <int|null>, "total_pages": <int|null> }
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessions/ct',
        [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_get_sessions_table_count',
            'args'                => [
                'per_page' => [ 'type' => 'integer', 'required' => false ],
                'q' => [ 'type' => 'string', 'required' => false ],
            ],
        ]
    );
} );

function pd_session_ct_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

function pd_session_ct_extract_rows( $decoded ): array {
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

function pd_get_sessions_table_count( WP_REST_Request $request ) {
    $per_page = $request->get_param( 'per_page' );
    $per_page = $per_page !== null ? (int) $per_page : null;
    if ( $per_page !== null ) {
        if ( $per_page <= 0 ) {
            $per_page = 0;
        } elseif ( $per_page > 500 ) {
            $per_page = 500;
        }
    }

    // Sanitize search q to match /sessionhome.
    $q_raw = (string) $request->get_param( 'q' );
    $q_tmp = preg_replace( "/[^\\p{L}\\p{Nd}\\s\\-'’]/u", '', $q_raw );
    $q_tmp = preg_replace( '/\\s+/u', ' ', (string) $q_tmp );
    $q     = trim( (string) $q_tmp );
    if ( $q === '' ) {
        $q = null;
    }

    // Cache counts briefly (especially important for search).
    $cache_key = 'pd_sessions_ct_v1_' . md5( (string) $q . '|' . (string) $per_page );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) && isset( $cached['count'] ) ) {
        return new WP_REST_Response(
            [
                'count'       => (int) $cached['count'],
                'per_page'    => $per_page !== null ? $per_page : null,
                'total_pages' => isset( $cached['total_pages'] ) ? $cached['total_pages'] : null,
            ],
            200
        );
    }

    try {
        $schema = defined( 'PD_DB_SCHEMA' ) ? PD_DB_SCHEMA : 'beta_2';

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

        if ( $q === null ) {
            $sql    = sprintf( 'SELECT * FROM %s.GET_session_table_count;', $schema );
            $result = aslta_signed_query( $sql );

            if ( $result['status'] < 200 || $result['status'] >= 300 ) {
                return new WP_Error(
                    'aslta_remote_http_error',
                    'Remote sessions count endpoint returned an HTTP error.',
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
                    'Failed to decode sessions count JSON response.',
                    [ 'status' => 500, 'debug' => ( WP_DEBUG ? json_last_error_msg() : null ) ]
                );
            }

            $rows = pd_session_ct_extract_rows( $decoded );
            $count = 0;
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                foreach ( $row as $v ) {
                    if ( is_numeric( $v ) ) {
                        $count = (int) $v;
                        break 2;
                    }
                }
            }
        } else {
            // Count filtered sessions by paging through the same stored proc used by /sessionhome.
            $page_size = 500;
            $offset    = 0;
            $count     = 0;
            $max_iters = 200; // 100k rows safety cap

            $sort_literal   = pd_session_ct_sql_quote( 'Date' );
            $dir_literal    = pd_session_ct_sql_quote( 'DESC' );
            $search_literal = pd_session_ct_sql_quote( $q );

            for ( $i = 0; $i < $max_iters; $i++ ) {
                $sql_page = sprintf(
                    'CALL %s.get_sessions3_f(NULL, %s, %s, %d, %d, %s);',
                    $schema,
                    $sort_literal,
                    $dir_literal,
                    $page_size,
                    $offset,
                    $search_literal
                );

                $page_result = aslta_signed_query( $sql_page );
                if ( $page_result['status'] < 200 || $page_result['status'] >= 300 ) {
                    return new WP_Error(
                        'aslta_remote_http_error',
                        'Remote sessions search count endpoint returned an HTTP error.',
                        [
                            'status' => 500,
                            'debug'  => ( WP_DEBUG ? [ 'http_code' => $page_result['status'], 'body' => $page_result['body'] ] : null ),
                        ]
                    );
                }

                $decoded_page = json_decode( (string) ( $page_result['body'] ?? '' ), true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new WP_Error(
                        'aslta_json_error',
                        'Failed to decode sessions search count JSON response.',
                        [ 'status' => 500, 'debug' => ( WP_DEBUG ? json_last_error_msg() : null ) ]
                    );
                }

                $rows_page = pd_session_ct_extract_rows( $decoded_page );
                $n = 0;
                foreach ( $rows_page as $r ) {
                    if ( is_array( $r ) ) {
                        $n++;
                    }
                }
                $count += $n;
                if ( $n < $page_size ) {
                    break;
                }
                $offset += $page_size;

                if ( $i === $max_iters - 1 ) {
                    return new WP_Error(
                        'profdef_count_too_large',
                        'Search result set too large to count safely.',
                        [ 'status' => 500 ]
                    );
                }
            }
        }
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to query sessions count via remote API.',
            [ 'status' => 500, 'debug' => ( WP_DEBUG ? $e->getMessage() : null ) ]
        );
    }

    $total_pages = null;
    if ( $per_page !== null && $per_page > 0 ) {
        $total_pages = max( 1, (int) ceil( $count / $per_page ) );
    }

    set_transient(
        $cache_key,
        [ 'count' => $count, 'total_pages' => $total_pages ],
        30
    );

    return new WP_REST_Response(
        [
            'count'       => $count,
            'per_page'    => $per_page !== null ? $per_page : null,
            'total_pages' => $total_pages,
        ],
        200
    );
}
