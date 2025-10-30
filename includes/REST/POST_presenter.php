<?php
/**
 * ProfDef REST: POST_presenter
 * Endpoint: POST /wp-json/profdef/v2/presenter
 * Purpose: Create a presenter via stored procedure Test_Database.POST_presenter
 *
 * Accepts JSON (or form) params:
 * - name (string, required; if absent, combines firstname + lastname)
 * - firstname (string, optional)
 * - lastname (string, optional)
 * - email (string, optional; NULL allowed)
 * - phone (string, optional; NULL allowed)
 *
 * Returns: { success: true, id, name, email, phone_number }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route(
        'profdef/v2',
        '/presenter',
        [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'permission_callback' => 'pd_presenters_permission', // reuse presenters nonce + capability
            'callback'            => 'pd_post_presenter_create',
            'args'                => [
                'name' => [ 'type' => 'string', 'required' => false ],
                'firstname' => [ 'type' => 'string', 'required' => false ],
                'lastname'  => [ 'type' => 'string', 'required' => false ],
                'email' => [ 'type' => 'string', 'required' => false ],
                'phone' => [ 'type' => 'string', 'required' => false ],
            ],
        ]
    );
} );

function pd_post_presenter_get( WP_REST_Request $req, array $json, $keys, $default = null ) {
    foreach ( (array) $keys as $k ) {
        if ( array_key_exists( $k, $json ) ) return $json[ $k ];
        $v = $req->get_param( $k );
        if ( null !== $v ) return $v;
    }
    return $default;
}

function pd_post_presenter_create( WP_REST_Request $req ) {
    $json = (array) $req->get_json_params();

    // Collect + sanitize
    $name_in  = pd_post_presenter_get( $req, $json, [ 'name' ], '' );
    $first_in = pd_post_presenter_get( $req, $json, [ 'firstname', 'first_name' ], '' );
    $last_in  = pd_post_presenter_get( $req, $json, [ 'lastname', 'last_name' ], '' );
    $email_in = pd_post_presenter_get( $req, $json, [ 'email' ], '' );
    $phone_in = pd_post_presenter_get( $req, $json, [ 'phone' ], '' );

    $first = sanitize_text_field( wp_unslash( (string) $first_in ) );
    $last  = sanitize_text_field( wp_unslash( (string) $last_in ) );
    $name  = sanitize_text_field( wp_unslash( (string) $name_in ) );
    if ( $name === '' ) {
        $name = trim( $first . ' ' . $last );
    }
    if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
        if ( mb_strlen( $name ) > 60 ) $name = mb_substr( $name, 0, 60 );
    } else {
        if ( strlen( $name ) > 60 ) $name = substr( $name, 0, 60 );
    }

    $email = sanitize_email( wp_unslash( (string) $email_in ) );
    if ( $email === '' ) $email = null; // normalize empty to NULL
    if ( $email !== null ) {
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_request', 'Invalid email.', [ 'status' => 400 ] );
        }
        if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
            if ( mb_strlen( $email ) > 254 ) $email = mb_substr( $email, 0, 254 );
        } else {
            if ( strlen( $email ) > 254 ) $email = substr( $email, 0, 254 );
        }
    }

    $phone = wp_unslash( (string) $phone_in );
    $phone = preg_replace( '/[^0-9()+.\-\s]/', '', $phone );
    if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
        if ( mb_strlen( $phone ) > 20 ) $phone = mb_substr( $phone, 0, 20 );
    } else {
        if ( strlen( $phone ) > 20 ) $phone = substr( $phone, 0, 20 );
    }
    if ( $phone === '' ) $phone = null; // normalize empty to NULL

    if ( $name === '' ) {
        return new WP_Error( 'bad_request', 'Presenter name is required.', [ 'status' => 400 ] );
    }

    // 1) Decrypt external DB creds
    $host = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_host', '' ) );
    $dbname = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_name', '' ) );
    $user = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_user', '' ) );
    $pass = ProfessionalDevelopment_decrypt( get_option( 'ProfessionalDevelopment_db_pass', '' ) );
    if ( ! $host || ! $dbname || ! $user ) {
        return new WP_Error( 'profdef_db_creds_missing', 'Database credentials are not configured.', [ 'status' => 500 ] );
    }

    // 2) Connect and call stored procedure
    $conn = @new mysqli( $host, $user, $pass, $dbname );
    if ( $conn->connect_error ) {
        return new WP_Error( 'mysql_not_connect', 'Database connection failed.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->connect_error : null ) ] );
    }
    $conn->set_charset( 'utf8mb4' );

    $stmt = $conn->prepare( 'CALL Test_Database.POST_presenter(?, ?, ?, @new_id)' );
    if ( ! $stmt ) {
        $conn->close();
        return new WP_Error( 'profdef_prepare_failed', 'Failed to prepare stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $conn->error : null ) ] );
    }
    // bind NULLs allowed
    $stmt->bind_param( 'sss', $name, $email, $phone );
    if ( ! $stmt->execute() ) {
        $err = $stmt->error ?: $conn->error;
        $stmt->close();
        $conn->close();
        if ( strpos( (string) $err, 'POST_presenter: name is required' ) !== false ) {
            return new WP_Error( 'bad_request', 'Presenter name is required.', [ 'status' => 400 ] );
        }
        return new WP_Error( 'profdef_execute_failed', 'Failed to execute stored procedure.', [ 'status' => 500, 'debug' => ( WP_DEBUG ? $err : null ) ] );
    }

    // First result set should contain the selected payload
    $new_id = 0; $out = [ 'name' => $name, 'email' => $email, 'phone_number' => $phone ];
    if ( $result = $stmt->get_result() ) {
        if ( $row = $result->fetch_assoc() ) {
            $new_id = isset( $row['idPresentor'] ) ? (int) $row['idPresentor'] : 0;
            $out['name'] = isset( $row['name'] ) ? (string) $row['name'] : $out['name'];
            $out['email'] = isset( $row['email'] ) ? ( $row['email'] === '' ? null : (string) $row['email'] ) : $out['email'];
            $out['phone_number'] = isset( $row['phone_number'] ) ? ( $row['phone_number'] === '' ? null : (string) $row['phone_number'] ) : $out['phone_number'];
        }
        $result->free();
    }
    // Drain any extra results
    while ( $stmt->more_results() && $stmt->next_result() ) {
        if ( $extra = $stmt->get_result() ) { $extra->free(); }
    }
    $stmt->close();

    if ( $new_id <= 0 ) {
        // Fallback to OUT var
        if ( $res = $conn->query( 'SELECT @new_id AS id' ) ) {
            if ( $r = $res->fetch_assoc() ) {
                $new_id = isset( $r['id'] ) ? (int) $r['id'] : 0;
            }
            $res->free();
        }
    }

    $conn->close();

    if ( $new_id <= 0 ) {
        return new WP_Error( 'profdef_no_id', 'Did not receive a new presenter id.', [ 'status' => 500 ] );
    }

    return new WP_REST_Response( [
        'success'      => true,
        'id'           => (int) $new_id,
        'name'         => (string) $out['name'],
        'email'        => $out['email'],      // may be null
        'phone_number' => $out['phone_number'] // may be null
    ], 201 );
}
