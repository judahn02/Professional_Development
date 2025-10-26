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
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ]
    );
} );

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

    // Decrypt creds
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

    // Load existing rows for session
    $existing = [];
    $sel = $conn->prepare( 'SELECT members_id, COALESCE(`Certification Status`, "") AS status FROM attending WHERE session_id = ?' );
    if ( ! $sel ) { $conn->close(); return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare select.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] ); }
    $sel->bind_param( 'i', $session_id );
    if ( ! $sel->execute() ) { $err = $sel->error ?: $conn->error; $sel->close(); $conn->close(); return new WP_Error( 'profdef_execute_failed', 'Failed to execute select.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] ); }
    if ( $res = $sel->get_result() ) {
        while ( $row = $res->fetch_assoc() ) {
            $existing[ (int) $row['members_id'] ] = (string) $row['status'];
        }
        $res->free();
    }
    $sel->close();

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

    // Transaction apply
    $conn->begin_transaction();
    try {
        if ( ! empty( $to_insert ) ) {
            $ins = $conn->prepare( 'INSERT INTO attending (session_id, members_id, `Certification Status`) VALUES (?,?,?)' );
            if ( ! $ins ) { throw new Exception( 'prepare insert failed: ' . $conn->error ); }
            foreach ( $to_insert as $mid => $st ) {
                $s = $st; $sid = $session_id; $m = (int) $mid;
                $ins->bind_param( 'iis', $sid, $m, $s );
                if ( ! $ins->execute() ) { throw new Exception( 'insert failed: ' . $ins->error ); }
            }
            $ins->close();
        }
        if ( ! empty( $to_update ) ) {
            $upd = $conn->prepare( 'UPDATE attending SET `Certification Status` = ? WHERE session_id = ? AND members_id = ?' );
            if ( ! $upd ) { throw new Exception( 'prepare update failed: ' . $conn->error ); }
            foreach ( $to_update as $mid => $st ) {
                $s = $st; $sid = $session_id; $m = (int) $mid;
                $upd->bind_param( 'sii', $s, $sid, $m );
                if ( ! $upd->execute() ) { throw new Exception( 'update failed: ' . $upd->error ); }
            }
            $upd->close();
        }
        if ( ! empty( $to_delete ) ) {
            $del = $conn->prepare( 'DELETE FROM attending WHERE session_id = ? AND members_id = ?' );
            if ( ! $del ) { throw new Exception( 'prepare delete failed: ' . $conn->error ); }
            foreach ( $to_delete as $mid ) {
                $sid = $session_id; $m = (int) $mid;
                $del->bind_param( 'ii', $sid, $m );
                if ( ! $del->execute() ) { throw new Exception( 'delete failed: ' . $del->error ); }
            }
            $del->close();
        }
        $conn->commit();
    } catch ( Exception $ex ) {
        $conn->rollback();
        $conn->close();
        return new WP_Error( 'profdef_tx_failed', 'Attendees update failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $ex->getMessage() : null ) ] );
    }

    // Return summary + resulting list
    $total = count( $incoming );
    $out   = [];
    foreach ( $incoming as $mid => $st ) {
        $out[] = [ 'member_id' => (int) $mid, 'status' => (string) $st ];
    }
    $conn->close();

    return new WP_REST_Response( [
        'session_id' => $session_id,
        'added'      => count( $to_insert ),
        'updated'    => count( $to_update ),
        'deleted'    => count( $to_delete ),
        'total'      => $total,
        'attendees'  => $out,
    ], 200 );
}

