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
    if ($id === false || $id <= 0) {
        return new \WP_Error('invalid_members_id', 'Parameter "members_id" must be a positive integer.', ['status' => 400]);
    }

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    try {
        $schema = defined('PD_DB_SCHEMA') ? PD_DB_SCHEMA : 'beta_2';
        $sql = sprintf('CALL %s.get_sessions_by_member(%d);', $schema, $id);

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
            'Failed to query member sessions via remote API.',
            [
                'status' => 500,
                'details' => (WP_DEBUG ? $e->getMessage() : null),
            ]
        );
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        return new \WP_Error(
            'aslta_remote_http_error',
            'Remote member sessions endpoint returned an HTTP error.',
            [
                'status' => 500,
                'details' => (WP_DEBUG ? ['http_code' => $result['status'], 'body' => $result['body']] : null),
            ]
        );
    }

    $decoded = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new \WP_Error(
            'aslta_json_error',
            'Failed to decode member sessions JSON response.',
            [
                'status' => 500,
                'details' => (WP_DEBUG ? json_last_error_msg() : null),
            ]
        );
    }

    // Normalise to a list of row arrays.
    if (isset($decoded['rows']) && is_array($decoded['rows'])) {
        $rows_raw = $decoded['rows'];
    } elseif (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)) {
        // Already a list
        $rows_raw = $decoded;
    } else {
        // Single object or unexpected shape; wrap in an array so downstream code still works.
        $rows_raw = [$decoded];
    }

    $rows = [];
    foreach ($rows_raw as $row) {
        if (!is_array($row)) {
            continue;
        }

        // Support both raw DB columns and legacy display labels.
        $session_id_raw = null;
        if (array_key_exists('session_id', $row)) {
            $session_id_raw = $row['session_id'];
        } elseif (array_key_exists('Session Id', $row)) {
            $session_id_raw = $row['Session Id'];
        } elseif (array_key_exists('id', $row)) {
            $session_id_raw = $row['id'];
        }

        $date_raw = '';
        if (array_key_exists('Date', $row)) {
            $date_raw = $row['Date'];
        } elseif (array_key_exists('date', $row)) {
            $date_raw = $row['date'];
        } elseif (array_key_exists('session_date', $row)) {
            $date_raw = $row['session_date'];
        }

        $title_raw = '';
        if (array_key_exists('Title', $row)) {
            $title_raw = $row['Title'];
        } elseif (array_key_exists('title', $row)) {
            $title_raw = $row['title'];
        } elseif (array_key_exists('session_title', $row)) {
            $title_raw = $row['session_title'];
        }

        $session_type_raw = '';
        if (array_key_exists('Session Type', $row)) {
            $session_type_raw = $row['Session Type'];
        } elseif (array_key_exists('session_type', $row)) {
            $session_type_raw = $row['session_type'];
        } elseif (array_key_exists('Session_Type', $row)) {
            $session_type_raw = $row['Session_Type'];
        }

        $length_raw = null;
        if (array_key_exists('Length', $row)) {
            $length_raw = $row['Length'];
        } elseif (array_key_exists('length', $row)) {
            $length_raw = $row['length'];
        } elseif (array_key_exists('length_minutes', $row)) {
            $length_raw = $row['length_minutes'];
        }

        $ceu_capable_raw = null;
        if (array_key_exists('CEU Capable', $row)) {
            $ceu_capable_raw = $row['CEU Capable'];
        } elseif (array_key_exists('ceu_capable', $row)) {
            $ceu_capable_raw = $row['ceu_capable'];
        }

        $ceu_weight_raw = null;
        if (array_key_exists('CEU Weight', $row)) {
            $ceu_weight_raw = $row['CEU Weight'];
        } elseif (array_key_exists('ceu_weight', $row)) {
            $ceu_weight_raw = $row['ceu_weight'];
        }

        $parent_event_raw = null;
        if (array_key_exists('Parent Event', $row)) {
            $parent_event_raw = $row['Parent Event'];
        } elseif (array_key_exists('parent_event', $row)) {
            $parent_event_raw = $row['parent_event'];
        } elseif (array_key_exists('specific_event', $row)) {
            $parent_event_raw = $row['specific_event'];
        }

        $event_type_raw = null;
        if (array_key_exists('Event Type', $row)) {
            $event_type_raw = $row['Event Type'];
        } elseif (array_key_exists('event_type', $row)) {
            $event_type_raw = $row['event_type'];
        }

        $members_id_raw = null;
        if (array_key_exists('Members_id', $row)) {
            $members_id_raw = $row['Members_id'];
        } elseif (array_key_exists('members_id', $row)) {
            $members_id_raw = $row['members_id'];
        } elseif (array_key_exists('member_id', $row)) {
            $members_id_raw = $row['member_id'];
        }

        // Normalise CEU capable to boolean if possible.
        if (is_string($ceu_capable_raw)) {
            $ceu_capable_raw = ($ceu_capable_raw === 'True' || $ceu_capable_raw === 'true' || $ceu_capable_raw === '1');
        }

        $rows[] = [
            'Session Id'   => $session_id_raw === null ? null : (int) $session_id_raw,
            'Date'         => $date_raw === null ? '' : (string) $date_raw,
            'Title'        => (string) $title_raw,
            'Session Type' => (string) $session_type_raw,
            'Length'       => $length_raw === null ? null : (int) $length_raw,
            'CEU Capable'  => (bool) $ceu_capable_raw,
            'CEU Weight'   => $ceu_weight_raw === null ? null : (float) $ceu_weight_raw,
            'Parent Event' => $parent_event_raw === null ? null : (string) $parent_event_raw,
            'Event Type'   => $event_type_raw === null ? null : (string) $event_type_raw,
            'Members_id'   => $members_id_raw === null ? null : (int) $members_id_raw,
        ];
    }

    return new \WP_REST_Response([
        'members_id' => $id,
        'count'      => count($rows),
        'sessions'   => $rows,
    ], 200);

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // DB creds (decrypt from your options)
     * $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
     * $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
     * $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
     * $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));
     *
     * $conn = @new \mysqli($host, $user, $pass, $name);
     * if ($conn->connect_errno) {
     *     return new \WP_Error('db_connect_error', 'Database connection failed.', [
     *         'status' => 500,
     *         'details' => $conn->connect_error,
     *     ]);
     * }
     * $conn->set_charset('utf8mb4');
     *
     * // Stored procedure from earlier
     * $sql = \"CALL get_sessions_by_member(?)\";
     * $stmt = $conn->prepare($sql);
     * if (!$stmt) {
     *     $err = $conn->error ?: 'Unknown prepare error';
     *     $conn->close();
     *     return new \WP_Error('db_prepare_error', 'Failed to prepare statement.', ['status' => 500, 'details' => $err]);
     * }
     *
     * $stmt->bind_param('i', $id);
     * if (!$stmt->execute()) {
     *     $err = $stmt->error ?: 'Unknown execute error';
     *     $stmt->close();
     *     $conn->close();
     *     return new \WP_Error('db_execute_error', 'Failed to execute statement.', ['status' => 500, 'details' => $err]);
     * }
     *
     * $rows = [];
     *
     * // ---- Path A: mysqlnd available (get_result)
     * if (method_exists($stmt, 'get_result')) {
     *     $result = $stmt->get_result();
     *     if ($result === false) {
     *         // Fall through to non-mysqlnd path as a safety
     *     } else {
     *         while ($row = $result->fetch_assoc()) {
     *             // Optional: convert CEU Capable to boolean
     *             if (isset($row['CEU Capable'])) {
     *                 $row['CEU Capable'] = ($row['CEU Capable'] === 'True');
     *             }
     *             $rows[] = $row;
     *         }
     *         $result->free();
     *     }
     * }
     *
     * // ---- Path B: no mysqlnd — use bind_result
     * if (empty($rows)) {
     *     // Bind in the exact order returned by the proc:
     *     $session_id = $date = $title = $session_type = $length = $ceu_capable = $ceu_weight = $parent_event = $event_type = $members_id = null;
     *
     *     // When get_result() isn't available, we must call store_result() before bind_result()
     *     // for some drivers, but mysqli generally allows bind_result() directly after execute.
     *     $stmt->store_result();
     *
     *     $ok = $stmt->bind_result(
     *         $session_id,   // A.id
     *         $date,         // DATE_FORMAT(A.date, '%Y-%m-%dT%H:%i:%s')
     *         $title,        // A.title
     *         $session_type, // B.name
     *         $length,       // A.length
     *         $ceu_capable,  // 'True'/'False'
     *         $ceu_weight,   // A.ceu_weight
     *         $parent_event, // A.specific_event
     *         $event_type,   // C.name
     *         $members_id    // D.members_id
     *     );
     *
     *     if (!$ok) {
     *         $stmt->free_result();
     *         $stmt->close();
     *         $conn->close();
     *         return new \WP_Error('db_bind_error', 'Failed to bind result columns (non-mysqlnd path).', ['status' => 500]);
     *     }
     *
     *     while ($stmt->fetch()) {
     *         $rows[] = [
     *             'Session Id'   => $session_id,
     *             'Date'         => $date,
     *             'Title'        => $title,
     *             'Session Type' => $session_type,
     *             'Length'       => is_null($length) ? null : (int)$length,
     *             'CEU Capable'  => ($ceu_capable === 'True'), // turn into boolean
     *             'CEU Weight'   => is_null($ceu_weight) ? null : (float)$ceu_weight,
     *             'Parent Event' => $parent_event,
     *             'Event Type'   => $event_type,
     *             'Members_id'   => is_null($members_id) ? null : (int)$members_id,
     *         ];
     *     }
     *
     *     $stmt->free_result();
     * }
     *
     * // Drain any extra result sets from the procedure (saves you from “Commands out of sync” later)
     * while ($conn->more_results() && $conn->next_result()) {
     *     if ($extra = $conn->store_result()) { $extra->free(); }
     * }
     *
     * $stmt->close();
     * $conn->close();
     *
     * return new \WP_REST_Response([
     *     'members_id' => $id,
     *     'count'      => count($rows),
     *     'sessions'   => $rows,
     * ], 200);
     */
}
