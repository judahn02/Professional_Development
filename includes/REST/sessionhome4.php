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

/**
 * Minimal SQL string escaper for values embedded into a CALL statement.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string $value
 * @return string SQL literal
 */
function pd_sessionhome4_sql_quote( string $value ): string {
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

// Sanitize callback: allow letters and spaces; collapse whitespace; trim; cap to 255.
function pd_sessionhome4_letters_only( $value ) {
    $term = (string) $value;
    $term = preg_replace( '/[^a-zA-Z\s]+/', '', $term );
    $term = preg_replace( '/\s+/', ' ', $term );
    $term = trim( $term );
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
            'permission_callback' => 'pd_presenters_permission',
            'callback'            => 'pd_sessionhome4_search_presenters',
            'args'                => [
                'term' => [
                    'description'      => 'Search term for presenter name (letters and spaces only)',
                    'type'             => 'string',
                    'required'         => true,
                    'sanitize_callback'=> 'pd_sessionhome4_letters_only',
                ],
                'only_non_attendees' => [
                    'description' => 'When truthy, restrict to presenters who are not attendees yet (attendee=0 AND presenter=1).',
                    'type'        => 'boolean',
                    'required'    => false,
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

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted term, matching sp_search_presentor(IN p_term).
    $term_lit = pd_sessionhome4_sql_quote( $term );
    $schema   = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
    $sql      = sprintf( 'CALL %s.sp_search_presentor(%s);', $schema, $term_lit );

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
            return new WP_Error(
                'aslta_helper_missing',
                'Signed query helper is not available.',
                [ 'status' => 500 ]
            );
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to search presenters via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote presenter search endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( $result['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'aslta_json_error',
            'Failed to decode presenter search JSON response.',
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

    $rows = [];
    foreach ( $rows_raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $name = isset( $row['name'] ) ? (string) $row['name'] : '';

        $rows[] = [
            'id'   => $id,
            'name' => $name,
        ];
    }

    // Optional filter: only presenters that are not currently attendees (attendee = 0 AND presenter = 1)
    $only_non_attendees = $request->get_param( 'only_non_attendees' );
    $only_non_attendees = ! empty( $only_non_attendees );

    if ( $only_non_attendees && ! empty( $rows ) ) {
        $ids = [];
        foreach ( $rows as $r ) {
            if ( isset( $r['id'] ) && (int) $r['id'] > 0 ) {
                $ids[] = (int) $r['id'];
            }
        }
        $ids = array_values( array_unique( $ids ) );

        if ( ! empty( $ids ) ) {
            $id_list = implode( ',', array_map( 'intval', $ids ) );
            $schema2 = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
            $sql2    = 'SELECT id, attendee, presenter FROM ' . $schema2 . '.person WHERE id IN (' . $id_list . ');';

            try {
                if ( ! function_exists( 'aslta_signed_query' ) ) {
                    $plugin_root   = dirname( dirname( __DIR__ ) );
                    $skeleton_path = $plugin_root . '/admin/skeleton2.php';
                    if ( is_readable( $skeleton_path ) ) {
                        require_once $skeleton_path;
                    }
                }

                if ( ! function_exists( 'aslta_signed_query' ) ) {
                    return new WP_Error(
                        'aslta_helper_missing',
                        'Signed query helper is not available.',
                        [ 'status' => 500 ]
                    );
                }

                $flags_result = aslta_signed_query( $sql2 );
            } catch ( \Throwable $e ) {
                return new WP_Error(
                    'aslta_remote_error',
                    'Failed to load presenter attendee flags via remote API.',
                    [
                        'status' => 500,
                        'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
                    ]
                );
            }

            if ( $flags_result['status'] < 200 || $flags_result['status'] >= 300 ) {
                return new WP_Error(
                    'aslta_remote_http_error',
                    'Remote presenter flags endpoint returned an HTTP error.',
                    [
                        'status' => 500,
                        'debug'  => ( WP_DEBUG ? [ 'http_code' => $flags_result['status'], 'body' => $flags_result['body'] ] : null ),
                    ]
                );
            }

            $decoded_flags = json_decode( $flags_result['body'], true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error(
                    'aslta_json_error',
                    'Failed to decode presenter flags JSON response.',
                    [
                        'status' => 500,
                        'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
                    ]
                );
            }

            if ( isset( $decoded_flags['rows'] ) && is_array( $decoded_flags['rows'] ) ) {
                $flags_raw = $decoded_flags['rows'];
            } elseif ( is_array( $decoded_flags ) && array_keys( $decoded_flags ) === range( 0, count( $decoded_flags ) - 1 ) ) {
                $flags_raw = $decoded_flags;
            } else {
                $flags_raw = [ $decoded_flags ];
            }

            $flag_map = [];
            foreach ( $flags_raw as $row2 ) {
                if ( ! is_array( $row2 ) ) {
                    continue;
                }
                $pid = isset( $row2['id'] ) ? (int) $row2['id'] : 0;
                if ( $pid <= 0 ) {
                    continue;
                }
                $att = isset( $row2['attendee'] ) ? (int) $row2['attendee'] : 0;
                $pre = isset( $row2['presenter'] ) ? (int) $row2['presenter'] : 0;
                $flag_map[ $pid ] = [
                    'attendee'  => $att,
                    'presenter' => $pre,
                ];
            }

            $rows = array_values(
                array_filter(
                    $rows,
                    static function ( $r ) use ( $flag_map ) {
                        $pid = isset( $r['id'] ) ? (int) $r['id'] : 0;
                        if ( $pid <= 0 || ! isset( $flag_map[ $pid ] ) ) {
                            return false;
                        }
                        $att = (int) $flag_map[ $pid ]['attendee'];
                        $pre = (int) $flag_map[ $pid ]['presenter'];
                        return ( 0 === $att && 1 === $pre );
                    }
                )
            );
        } else {
            $rows = [];
        }
    }

    return new WP_REST_Response( $rows, 200 );

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // 1) Decrypt external DB creds
     * $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
     * $name = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
     * $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
     * $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
     *
     * if ( ! $host || ! $name || ! $user ) {
     *     return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
     * }
     *
     * // 2) Connect to external DB
     * $conn = @new mysqli( $host, $user, $pass, $name );
     * if ( $conn->connect_error ) {
     *     return new WP_Error(
     *         'mysql_not_connect',
     *         'Database connection failed.',
     *         [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->connect_error : null ]
     *     );
     * }
     * $conn->set_charset( 'utf8mb4' );
     *
     * // 3) Prepare + execute stored procedure sp_search_presentor
     * // Qualify DB like other endpoints do with Test_Database.*
     * $stmt = $conn->prepare( 'CALL Test_Database.sp_search_presentor(?)' );
     * if ( ! $stmt ) {
     *     $conn->close();
     *     return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $conn->error : null ] );
     * }
     *
     * $stmt->bind_param( 's', $term );
     * if ( ! $stmt->execute() ) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => WP_DEBUG ? $err : null ] );
     * }
     *
     * // 4) Collect result rows
     * $rows = [];
     * if ( $result = $stmt->get_result() ) {
     *     while ( $row = $result->fetch_assoc() ) {
     *         // Normalize types
     *         if ( isset( $row['id'] ) ) {
     *             $row['id'] = (int) $row['id'];
     *         }
     *         $rows[] = [
     *             'id'   => $row['id'] ?? 0,
     *             'name' => isset( $row['name'] ) ? (string) $row['name'] : '',
     *         ];
     *     }
     *     $result->free();
     * }
     *
     * // 5) Drain any additional result sets if driver returns them
     * while ( $stmt->more_results() && $stmt->next_result() ) {
     *     if ( $extra = $stmt->get_result() ) {
     *         $extra->free();
     *     }
     * }
     *
     * $stmt->close();
     * $conn->close();
     *
     * return new WP_REST_Response( $rows, 200 );
     */
}
