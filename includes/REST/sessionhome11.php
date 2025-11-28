<?php
/**
 * ASLTA Session Attendees Batch Update (sessionhome11)
 * PUT profdef/v2/sessionhome11
 * Body JSON: { session_id: number, attendees: [{ member_id:number, status:'Certified'|'Master'|'None'|'' }] }
 * Applies adds/updates/deletes to the external `attending` table using composite PK (session_id, members_id).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/sessionhome11',
        [
            'methods'             => [ 'PUT', 'POST' ], // allow POST for clients without PUT
            'callback'            => 'aslta_update_session_attendees_batch',
            'permission_callback' => '__return_true', // 'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ]
    );
} );

/**
 * Minimal SQL string escaper for values embedded into a statement.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string|null $value
 * @return string SQL literal or NULL
 */
function pd_sessionhome11_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

function aslta_update_session_attendees_batch( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) {
        return new WP_Error( 'bad_json', 'Expected JSON body.', [ 'status' => 400 ] );
    }

    $session_id = isset( $params['session_id'] ) ? (int) $params['session_id'] : 0;
    if ( $session_id <= 0 ) {
        return new WP_Error( 'bad_param', 'session_id must be a positive integer.', [ 'status' => 400 ] );
    }

    $attendees = isset( $params['attendees'] ) ? $params['attendees'] : [];
    if ( ! is_array( $attendees ) ) {
        return new WP_Error( 'bad_param', 'attendees must be an array.', [ 'status' => 400 ] );
    }

    // Normalize/validate statuses
    $allowed = [ 'Certified', 'Master', 'None', '' ];
    $normalize_status = function( $v ) use ( $allowed ) {
        $s = trim( (string) $v );
        if ( $s === 'Not Assigned' ) { $s = ''; }
        foreach ( $allowed as $label ) {
            if ( strcasecmp( $s, $label ) === 0 ) { return $label; }
        }
        // Unknown -> empty (Not Assigned)
        return '';
    };

    $incoming = []; // member_id => status
    foreach ( $attendees as $row ) {
        if ( is_array( $row ) ) {
            $mid = isset( $row['member_id'] ) ? (int) $row['member_id'] : ( isset( $row[0] ) ? (int) $row[0] : 0 );
            $st  = isset( $row['status'] ) ? $row['status'] : ( isset( $row[1] ) ? $row[1] : '' );
        } elseif ( is_object( $row ) ) {
            $mid = isset( $row->member_id ) ? (int) $row->member_id : 0;
            $st  = isset( $row->status ) ? $row->status : '';
        } else {
            return new WP_Error( 'bad_param', 'Invalid attendee item.', [ 'status' => 400 ] );
        }
        if ( $mid <= 0 ) {
            return new WP_Error( 'bad_param', 'attendees contains invalid member_id.', [ 'status' => 400 ] );
        }
        $incoming[ $mid ] = $normalize_status( $st );
    }

    // Basic validation: detect invalid statuses early to avoid SQL errors
    $invalid = [];
    foreach ( $incoming as $mid => $st ) {
        if ( ! in_array( $st, $allowed, true ) ) {
            $invalid[] = [ 'member_id' => (int) $mid, 'status' => (string) $st ];
        }
    }
    if ( ! empty( $invalid ) ) {
        return new WP_Error( 'bad_param', 'Invalid status value detected.', [ 'status' => 400, 'invalid' => $invalid ] );
    }

    // Ensure signed query helper is available
    if ( ! function_exists( 'aslta_signed_query' ) ) {
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

    // Load existing rows for session from beta_2.attending
    $existing = [];
    $sql_sel  = sprintf(
        'SELECT person_id, COALESCE(certification_status, "") AS status FROM beta_2.attending WHERE sessions_id = %d',
        $session_id
    );

    try {
        $sel_result = aslta_signed_query( $sql_sel );
    } catch ( \Throwable $e ) {
        return new WP_Error(
            'aslta_remote_error',
            'Failed to load existing attendees via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $sel_result['status'] < 200 || $sel_result['status'] >= 300 ) {
        return new WP_Error(
            'aslta_remote_http_error',
            'Remote attendees select returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $sel_result['status'], 'body' => $sel_result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( $sel_result['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'aslta_json_error',
            'Failed to decode attendees select JSON response.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
            ]
        );
    }

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

        $mid = 0;
        if ( isset( $row['person_id'] ) ) {
            $mid = (int) $row['person_id'];
        } elseif ( isset( $row['members_id'] ) ) {
            $mid = (int) $row['members_id'];
        } elseif ( isset( $row['member_id'] ) ) {
            $mid = (int) $row['member_id'];
        }

        if ( $mid <= 0 ) {
            continue;
        }

        $st = '';
        if ( isset( $row['status'] ) ) {
            $st = (string) $row['status'];
        } elseif ( isset( $row['certification_status'] ) ) {
            $st = (string) $row['certification_status'];
        }

        $existing[ $mid ] = $st;
    }

    // Compute diffs
    $to_insert = [];
    $to_update = [];
    $to_delete = [];
    foreach ( $incoming as $mid => $st ) {
        if ( ! array_key_exists( $mid, $existing ) ) {
            $to_insert[ $mid ] = $st;
        } elseif ( $existing[ $mid ] !== $st ) {
            $to_update[ $mid ] = $st;
        }
    }
    foreach ( $existing as $mid => $st ) {
        if ( ! array_key_exists( $mid, $incoming ) ) {
            $to_delete[] = $mid;
        }
    }

    // Apply changes via remote DML statements.
    // Note: this is no longer wrapped in a single DB transaction; each operation
    // is executed independently via the remote API. We still fail fast on the
    // first error and report that the update failed.

    // Inserts
    foreach ( $to_insert as $mid => $st ) {
        $status_lit = ( $st === '' ) ? 'NULL' : pd_sessionhome11_sql_quote( $st );
        $sql_ins    = sprintf(
            'INSERT INTO beta_2.attending (person_id, sessions_id, certification_status) VALUES (%d, %d, %s);',
            (int) $mid,
            $session_id,
            $status_lit
        );

        try {
            $ins_result = aslta_signed_query( $sql_ins );
        } catch ( \Throwable $e ) {
            return new WP_Error(
                'profdef_tx_failed',
                'Attendees update failed (insert).',
                [
                    'status' => 500,
                    'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
                ]
            );
        }

        if ( $ins_result['status'] < 200 || $ins_result['status'] >= 300 ) {
            return new WP_Error(
                'profdef_tx_failed',
                'Attendees update failed (insert).',
                [
                    'status' => 500,
                    'debug'  => ( WP_DEBUG ? [ 'http_code' => $ins_result['status'], 'body' => $ins_result['body'] ] : null ),
                ]
            );
        }
    }

    // Updates
    foreach ( $to_update as $mid => $st ) {
        $status_lit = ( $st === '' ) ? 'NULL' : pd_sessionhome11_sql_quote( $st );
        $sql_upd    = sprintf(
            'UPDATE beta_2.attending SET certification_status = %s WHERE sessions_id = %d AND person_id = %d;',
            $status_lit,
            $session_id,
            (int) $mid
        );

        try {
            $upd_result = aslta_signed_query( $sql_upd );
        } catch ( \Throwable $e ) {
            return new WP_Error(
                'profdef_tx_failed',
                'Attendees update failed (update).',
                [
                    'status' => 500,
                    'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
                ]
            );
        }

        if ( $upd_result['status'] < 200 || $upd_result['status'] >= 300 ) {
            return new WP_Error(
                'profdef_tx_failed',
                'Attendees update failed (update).',
                [
                    'status' => 500,
                    'debug'  => ( WP_DEBUG ? [ 'http_code' => $upd_result['status'], 'body' => $upd_result['body'] ] : null ),
                ]
            );
        }
    }

    // Deletes
    foreach ( $to_delete as $mid ) {
        $sql_del = sprintf(
            'DELETE FROM beta_2.attending WHERE sessions_id = %d AND person_id = %d;',
            $session_id,
            (int) $mid
        );

        try {
            $del_result = aslta_signed_query( $sql_del );
        } catch ( \Throwable $e ) {
            return new WP_Error(
                'profdef_tx_failed',
                'Attendees update failed (delete).',
                [
                    'status' => 500,
                    'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
                ]
            );
        }

        if ( $del_result['status'] < 200 || $del_result['status'] >= 300 ) {
            return new WP_Error(
                'profdef_tx_failed',
                'Attendees update failed (delete).',
                [
                    'status' => 500,
                    'debug'  => ( WP_DEBUG ? [ 'http_code' => $del_result['status'], 'body' => $del_result['body'] ] : null ),
                ]
            );
        }
    }

    // Return summary + resulting list
    $total = count( $incoming );
    $out   = [];
    foreach ( $incoming as $mid => $st ) {
        $out[] = [ 'member_id' => (int) $mid, 'status' => (string) $st ];
    }
    return new WP_REST_Response( [
        'session_id' => $session_id,
        'added'      => count( $to_insert ),
        'updated'    => count( $to_update ),
        'deleted'    => count( $to_delete ),
        'total'      => $total,
        'attendees'  => $out,
    ], 200 );
}
