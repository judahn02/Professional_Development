<?php
// includes/rest-presenters.php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    // New canonical route
    register_rest_route('profdev/v1', '/presenters', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'pd_get_presenters_json',
        'permission_callback' => 'pd_presenters_permission',
    ]);
});

// function pd_presenters_permission( WP_REST_Request $request ) {
//     // Admin-only; change to is_user_logged_in() or __return_true for other policies
//     return current_user_can('manage_options');
// }

function pd_presenters_permission( WP_REST_Request $request ) {
    $nonce = $request->get_header('X-WP-Nonce');
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return new WP_Error('rest_forbidden', 'Bad or missing nonce.', ['status' => 403]);
    }
    return current_user_can('manage_options'); // or your chosen capability
}

function pd_get_presenters_json( WP_REST_Request $request ) {
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));

    $conn = @new mysqli($host, $user, $pass, $name);
    if ($conn->connect_error) {
        return new WP_Error('db_connect_error', 'Database connection failed: ' . $conn->connect_error, ['status' => 500]);
    }
    $conn->set_charset('utf8mb4');

    $rows = [];
    if ($res = $conn->query('CALL presentor_table_view()')) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'name'         => $row['name'],
                'email'        => $row['email'],
                'phone_number' => $row['phone_number'],
                'session_name' => $row['session_name'],
                'id'           => $row['id'],
            ];
        }
        $res->free();
        while ($conn->more_results() && $conn->next_result()) {}
    } else {
        $err = $conn->error;
        $conn->close();
        return new WP_Error('db_query_error', 'Procedure call failed: ' . $err, ['status' => 500]);
    }
    $conn->close();
    return rest_ensure_response($rows);
}

// POST /wp-json/profdev/v1/presenters -> add presenter
add_action('rest_api_init', function () {
    register_rest_route('profdev/v1', '/presenters', [
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'callback'            => 'pd_add_presenter_json',
        'permission_callback' => 'pd_presenters_permission',
        'args'                => [
            'name'  => ['type' => 'string', 'required' => false],
            'firstname' => ['type' => 'string', 'required' => true],
            'lastname'  => ['type' => 'string', 'required' => false],
            'email' => ['type' => 'string', 'required' => false],
            'phone' => ['type' => 'string', 'required' => false],
        ],
    ]);
});


function pd_add_presenter_json( WP_REST_Request $req ) {
    // Honeypot (if sent)
    $hp = (string) $req->get_param('pd_hp');
    if ( ! empty($hp) ) {
        return new WP_Error('bad_request', 'Spam detected.', ['status' => 400]);
    }

    // Sanitize
    $first = sanitize_text_field( wp_unslash( (string) $req->get_param('firstname') ) );
    $last  = sanitize_text_field( wp_unslash( (string) $req->get_param('lastname') ) );
    $name  = trim( $first . ' ' . $last );
    $email = sanitize_email( wp_unslash( (string) $req->get_param('email') ) );
    $email = ($email === '') ? null : $email; // normalize empty to NULL
    $phone = wp_unslash( (string) $req->get_param('phone') );
    $phone = preg_replace('/[^0-9()+.\-\s]/', '', $phone);

    // Enforce limits like your DB schema
    if ( $name === '' || mb_strlen($name) > 60 ) {
        return new WP_Error('bad_request', 'Invalid name.', ['status' => 400]);
    }
    // if ( empty($email) || ! is_email($email) || mb_strlen($email) > 254 ) {
    //     return new WP_Error('bad_request', 'Invalid email.', ['status' => 400]);
    // }
    if ( $email !== null ) {
        if ( ! is_email( $email ) || mb_strlen( $email ) > 254 ) {
            return new WP_Error('bad_request', 'Invalid email.', ['status' => 400]);
        }
    }
    if ( mb_strlen($phone) > 20 ) {
        $phone = mb_substr($phone, 0, 20);
    }

    // DB connect...
    // (your existing connect code here)
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $dbname = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));

    $conn = @new mysqli($host, $user, $pass, $dbname);
    if ($conn->connect_error) {
        return new WP_Error('db_connect_error', 'Database connection failed: ' . $conn->connect_error, ['status' => 500]);
    }
    $conn->set_charset('utf8mb4');
    // Prepared call (already parameterized)
    $stmt = $conn->prepare('CALL add_presentor(?, ?, ?, @ok)');
    if (!$stmt) {
        $err = $conn->error;
        $conn->close();
        // Return generic error to avoid leaking DB internals
        return new WP_Error('db_prepare_error', 'Database error.', ['status' => 500]);
    }
    $stmt->bind_param('sss', $name, $email, $phone);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        $conn->close();
        return new WP_Error('db_exec_error', 'Database error.', ['status' => 500]);
    }

    while ($conn->more_results() && $conn->next_result()) {}

    $res = $conn->query('SELECT @ok AS success, LAST_INSERT_ID() AS id');
    $row = $res ? $res->fetch_assoc() : null;
    $success = $row ? (int)$row['success'] : 0;
    $newId   = $row ? (int)$row['id'] : 0;

    if ($success !== 1) {
        // Optional: detect duplicate email for a better status code
        $dup = $conn->prepare('SELECT 1 FROM `presentor` WHERE LOWER(`email`) = ? LIMIT 1');
        if ($dup) {
            $e = strtolower($email);
            $dup->bind_param('s', $e);
            $dup->execute();
            $dup->store_result();
            $isDup = $dup->num_rows > 0;
            $dup->close();
            $conn->close();
            if ($isDup) {
                return new WP_Error('conflict', 'Presenter with this email already exists.', ['status' => 409]);
            }
        }
        return new WP_Error('bad_request', 'Insert failed.', ['status' => 400]);
    }

    $conn->close();
    return new WP_REST_Response([
        'success' => true,
        'id'      => $newId,
        'name'    => $name,
        'email'   => $email,
        'phone'   => $phone,
    ], 201);
}
