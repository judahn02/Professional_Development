<?php
if (!defined('ABSPATH')) exit;

/**
 * REST: GET /wp-json/profdef/v2/memberspage?members_id=123
 */
add_action('rest_api_init', function () {
    register_rest_route('profdef/v2', '/memberspage', [
        'methods'  => \WP_REST_Server::READABLE,
        'permission_callback' => '__return_true', // flip to pd_members_permission() later
        'callback' => 'pd_members_page_callback',
        'args' => [
            'members_id' => [
                'description' => 'Members ID; returns sessions that member attended.',
                'type'        => 'integer',
                'required'    => true,
                'sanitize_callback' => function($param){ return is_numeric($param) ? (int)$param : null; },
                'validate_callback' => function($param){ return filter_var($param, FILTER_VALIDATE_INT) !== false; },
            ],
        ],
    ]);
});

function pd_members_page_callback(\WP_REST_Request $request) {
    $id = filter_var($request->get_param('members_id'), FILTER_VALIDATE_INT);
    if ($id === false) {
        return new \WP_Error('invalid_members_id', 'Parameter "members_id" must be an integer.', ['status' => 400]);
    }

    // Validate member exists in WP users (adjust if your "members" are elsewhere)
    if (!get_user_by('ID', $id)) {
        return new \WP_Error('member_not_found', 'No WordPress user found with that ID.', ['status' => 404]);
    }

    // DB creds (decrypt from your options)
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));

    $conn = @new \mysqli($host, $user, $pass, $name);
    if ($conn->connect_errno) {
        return new \WP_Error('db_connect_error', 'Database connection failed.', [
            'status' => 500,
            'details' => $conn->connect_error,
        ]);
    }
    $conn->set_charset('utf8mb4');

    // Stored procedure from earlier
    $sql = "CALL get_sessions_by_member(?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $err = $conn->error ?: 'Unknown prepare error';
        $conn->close();
        return new \WP_Error('db_prepare_error', 'Failed to prepare statement.', ['status' => 500, 'details' => $err]);
    }

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: 'Unknown execute error';
        $stmt->close();
        $conn->close();
        return new \WP_Error('db_execute_error', 'Failed to execute statement.', ['status' => 500, 'details' => $err]);
    }

    $rows = [];

    // ---- Path A: mysqlnd available (get_result)
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result === false) {
            // Fall through to non-mysqlnd path as a safety
        } else {
            while ($row = $result->fetch_assoc()) {
                // Optional: convert CEU Capable to boolean
                if (isset($row['CEU Capable'])) {
                    $row['CEU Capable'] = ($row['CEU Capable'] === 'True');
                }
                $rows[] = $row;
            }
            $result->free();
        }
    }

    // ---- Path B: no mysqlnd — use bind_result
    if (empty($rows)) {
        // Bind in the exact order returned by the proc:
        $session_id = $date = $title = $session_type = $length = $ceu_capable = $ceu_weight = $parent_event = $event_type = $members_id = null;

        // When get_result() isn't available, we must call store_result() before bind_result()
        // for some drivers, but mysqli generally allows bind_result() directly after execute.
        $stmt->store_result();

        $ok = $stmt->bind_result(
            $session_id,   // A.id
            $date,         // DATE_FORMAT(A.date, '%Y-%m-%dT%H:%i:%s')
            $title,        // A.title
            $session_type, // B.name
            $length,       // A.length
            $ceu_capable,  // 'True'/'False'
            $ceu_weight,   // A.ceu_weight
            $parent_event, // A.specific_event
            $event_type,   // C.name
            $members_id    // D.members_id
        );

        if (!$ok) {
            $stmt->free_result();
            $stmt->close();
            $conn->close();
            return new \WP_Error('db_bind_error', 'Failed to bind result columns (non-mysqlnd path).', ['status' => 500]);
        }

        while ($stmt->fetch()) {
            $rows[] = [
                'Session Id'   => $session_id,
                'Date'         => $date,
                'Title'        => $title,
                'Session Type' => $session_type,
                'Length'       => is_null($length) ? null : (int)$length,
                'CEU Capable'  => ($ceu_capable === 'True'), // turn into boolean
                'CEU Weight'   => is_null($ceu_weight) ? null : (float)$ceu_weight,
                'Parent Event' => $parent_event,
                'Event Type'   => $event_type,
                'Members_id'   => is_null($members_id) ? null : (int)$members_id,
            ];
        }

        $stmt->free_result();
    }

    // Drain any extra result sets from the procedure (saves you from “Commands out of sync” later)
    while ($conn->more_results() && $conn->next_result()) {
        if ($extra = $conn->store_result()) { $extra->free(); }
    }

    $stmt->close();
    $conn->close();

    return new \WP_REST_Response([
        'members_id' => $id,
        'count'      => count($rows),
        'sessions'   => $rows,
    ], 200);
}
