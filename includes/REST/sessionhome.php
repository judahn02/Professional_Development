<?php
/**
 * Plugin Name: ProfDef REST: sessionhome
 * Description: Exposes /wp-json/profdef/v2/sessionhome, calling MySQL SP get_sessions2(IN p_id INT).
 * Version:     1.0.0
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    $ns = 'profdef/v2';

    register_rest_route(
        $ns, 
        '/sessionhome',
        [
            'methods'  => \WP_REST_Server::READABLE, // GET
            'permission_callback' => '__return_true',
            'callback' => 'pd_sessions_home_callback',
        ],
    ) ;
}) ;

function pd_sessions_home_callback( WP_REST_Request $request ) {
    // 1) Decrypt DB credentials from options
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));

    if (!$host || !$name || !$user) {
        return new \WP_Error('profdef_db_creds_missing', 'Database credentials are not configured.', ['status' => 500]);
    }

    // 2) Connect
    $conn = @new mysqli($host, $user, $pass, $name);
    if ($conn->connect_error) {
        return new \WP_Error('mysql_not_connect', 'Database connection failed.', [
            'status' => 500,
            'debug'  => WP_DEBUG ? $conn->connect_error : null,
        ]);
    }
    $conn->set_charset('utf8mb4');

    // 3) Prepare + execute stored procedure without member id
    $stmt = $conn->prepare('CALL get_sessions2(NULL)');
    if (!$stmt) {
        $conn->close();
        return new \WP_Error('profdef_prepare_failed', 'Failed to prepare stored procedure (NULL).', [
            'status' => 500,
            'debug'  => WP_DEBUG ? $conn->error : null,
        ]);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        return new \WP_Error('profdef_exec_failed', 'Failed to execute stored procedure.', [
            'status' => 500,
            'debug'  => WP_DEBUG ? $err : null,
        ]);
    }

    // 4) Collect results (handles multiple result sets)
    $rows = [];
    do {
        if ($result = $stmt->get_result()) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
    } while ($stmt->more_results() && $stmt->next_result());

    $stmt->close();
    $conn->close();

    // 5) Return JSON
    return new \WP_REST_Response($rows, 200);
}