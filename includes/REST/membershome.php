<?php
# TODO: fix, permission callback

if (!defined('ABSPATH')) exit; 


add_action('rest_api_init', function() {
    $ns = 'profdef/v2';

    register_rest_route(
        $ns,
        '/membershome',
        [ 
            'methods'  => \WP_REST_Server::READABLE, // GET
            'permission_callback' => '__return_true',
            // 'permission_callback' => 'pd_sessions_permission',
            'callback' => 'pd_members_home_callback',
            'args' => [
                'members_id' => [
                    'description' => 'Members ID (optional). If omitted, return all members.',
                    'type'        => 'integer',
                    'required'    => false,
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                    'validate_callback' => function($param) { return is_numeric($param);},
                ],
            ],
        ],
    );
}) ;


function pd_members_home_callback( WP_REST_Request $request) {
    $id_param = $request->get_param('members_id');
    $id       = ($id_param !== null) ? (int) $id_param : null;

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    try {
        if ($id !== null && $id > 0) {
            $sql = sprintf('CALL beta_2.get_member_totals(%d);', $id);
        } else {
            $sql = 'CALL beta_2.get_member_totals(NULL);';
        }

        if (!function_exists('aslta_signed_query')) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
            $plugin_root   = dirname(dirname(__DIR__)); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if (is_readable($skeleton_path)) {
                require_once $skeleton_path;
            }
        }

        if (!function_exists('aslta_signed_query')) {
            return new \WP_Error(
                'aslta_helper_missing',
                'Signed query helper is not available.',
                ['status' => 500]
            );
        }

        $result = aslta_signed_query($sql);
    } catch (\Throwable $e) {
        return new \WP_Error(
            'aslta_remote_error',
            'Failed to query member totals via remote API.',
            [
                'status' => 500,
                'debug'  => (WP_DEBUG ? $e->getMessage() : null),
            ]
        );
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        return new \WP_Error(
            'aslta_remote_http_error',
            'Remote member totals endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => (WP_DEBUG ? ['http_code' => $result['status'], 'body' => $result['body']] : null),
            ]
        );
    }

    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new \WP_Error(
            'aslta_json_error',
            'Failed to decode member totals JSON response.',
            [
                'status' => 500,
                'debug'  => (WP_DEBUG ? json_last_error_msg() : null),
            ]
        );
    }

    // Normalise to a list of row arrays and return as-is (JS consumer handles field names).
    if (isset($decoded['rows']) && is_array($decoded['rows'])) {
        $rows = $decoded['rows'];
    } elseif (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)) {
        $rows = $decoded;
    } else {
        $rows = [$decoded];
    }

    return new \WP_REST_Response($rows, 200);

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * $id = $request->get_param('members_id');
     * $id = ($id !== null) ? (int)$id : null;
     *
     * $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
     * $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
     * $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
     * $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));
     *
     * if (!$host || !$name || !$user) {
     *     return new \WP_Error('profdef_db_creds_missing', 'Database credentials are not configured.', ['status' => 500]);
     * }
     *
     * $conn = @new mysqli($host, $user, $pass, $name) ;
     *
     * if ($conn->connect_error) {
     *     return new WP_Error('mysql_not_connect', 'Database connection failed', ['status' => 500]);
     * }
     * $conn->set_charset('utf8mb4');
     *
     * // Execute the stored procedure
     * if ($id !== null && $id > 0) {
     *     // Use a prepared call for the ID case
     *     $stmt = $conn->prepare(\"CALL get_member_totals(?)\");
     *     if (!$stmt) {
     *         $err = $conn->error ?: 'Failed to prepare statement';
     *         $conn->close();
     *         return new WP_Error('mysql_prepare_failed', $err, ['status' => 500]);
     *     }
     *     $stmt->bind_param('i', $id);
     *     if (!$stmt->execute()) {
     *         $err = $stmt->error ?: 'Failed to execute statement';
     *         $stmt->close();
     *         $conn->close();
     *         return new WP_Error('mysql_exec_failed', $err, ['status' => 500]);
     *     }
     *     $result = $stmt->get_result();
     * } else {
     *     // Call with NULL (can’t bind easily, so run as a simple query)
     *     $sql = \"CALL get_member_totals(NULL)\";
     *     $result = $conn->query($sql);
     *     if ($result === false) {
     *         $err = $conn->error ?: 'Failed to run query';
     *         $conn->close();
     *         return new WP_Error('mysql_query_failed', $err, ['status' => 500]);
     *     }
     * }
     *
     * // Collect the first result set
     * $rows = [];
     * if ($result instanceof mysqli_result) {
     *     while ($row = $result->fetch_assoc()) {
     *         $rows[] = $row;
     *     }
     *     $result->free();
     * }
     *
     * // Consume any additional result sets produced by the procedure
     * while ($conn->more_results() && $conn->next_result()) {
     *     if ($extra = $conn->use_result()) { $extra->free(); }
     * }
     *
     * // Clean up
     * if (isset($stmt) && $stmt instanceof mysqli_stmt) { $stmt->close(); }
     * $conn->close();
     *
     * // Return JSON response
     * return new \WP_REST_Response($rows, 200);
     */
}
