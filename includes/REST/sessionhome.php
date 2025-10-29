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
            'args' => [
                'session_id' => [
                    'description' => 'Optional session id to fetch a single row',
                    'type' => 'integer',
                    'required' => false,
                ],
                'page' => [
                    'description' => 'Page number (1-based)',
                    'type'        => 'integer',
                    'required'    => false,
                    'default'     => 1,
                ],
                'per_page' => [
                    'description' => 'Items per page (default 25)',
                    'type'        => 'integer',
                    'required'    => false,
                    'default'     => 25,
                ],
                'sort' => [
                    'description' => 'Sort key (e.g., date, title, length, stype, ceuWeight, ceuConst, ceuCapable, eventType, parentEvent, presenters, attendees_ct)',
                    'type'        => 'string',
                    'required'    => false,
                ],
                'dir' => [
                    'description' => 'Sort direction (asc|desc)',
                    'type'        => 'string',
                    'required'    => false,
                ],
                'q' => [
                    'description' => 'Case-insensitive substring to filter by date/title/presenters/session type/event type',
                    'type'        => 'string',
                    'required'    => false,
                ],
            ],
        ],
    );
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

    // Param helpers
    $sid_param = $request->get_param('session_id');
    $sid = ($sid_param !== null && $sid_param !== '') ? (int) $sid_param : 0;

    $page = (int) $request->get_param('page');
    if ($page <= 0) { $page = 1; }
    $per_page = (int) $request->get_param('per_page');
    if ($per_page <= 0) { $per_page = 25; }
    if ($per_page > 100) { $per_page = 100; }

    // Sort mapping: accept friendly keys or exact column labels required by the proc
    $sort_in = (string) $request->get_param('sort');
    $sort_in = trim($sort_in);
    $sort_map = [
        'date' => 'Date', 'Date' => 'Date',
        'title' => 'Title', 'Title' => 'Title',
        'length' => 'Length', 'Length' => 'Length',
        'stype' => 'Session Type', 'session type' => 'Session Type', 'Session Type' => 'Session Type',
        'ceuweight' => 'CEU Weight', 'ceuWeight' => 'CEU Weight', 'CEU Weight' => 'CEU Weight',
        'ceuconst' => 'CEU Const', 'ceuConst' => 'CEU Const', 'CEU Const' => 'CEU Const',
        'ceucapable' => 'CEU Capable', 'ceuCapable' => 'CEU Capable', 'CEU Capable' => 'CEU Capable',
        'event' => 'Event Type', 'eventtype' => 'Event Type', 'eventType' => 'Event Type', 'Event Type' => 'Event Type',
        'parentevent' => 'Parent Event', 'parentEvent' => 'Parent Event', 'Parent Event' => 'Parent Event',
        'presenters' => 'presenters',
        'attendees' => 'attendees_ct', 'attendeesct' => 'attendees_ct', 'attendees_ct' => 'attendees_ct', 'attendeesCt' => 'attendees_ct',
    ];
    $norm_key = strtolower(str_replace(['_', ' ', '-'], '', $sort_in));
    $sort_col = isset($sort_map[$norm_key]) ? $sort_map[$norm_key] : 'Date';

    $dir_in = (string) $request->get_param('dir');
    $dir = strtoupper($dir_in === '' ? '' : $dir_in);
    if ($dir !== 'ASC' && $dir !== 'DESC') { $dir = 'DESC'; }

    $limit = $per_page;
    $offset = ($page - 1) * $per_page;
    if ($offset < 0) { $offset = 0; }

    // Sanitize search q: allow letters, digits, spaces, hyphens, ASCII ' and Unicode ’; collapse whitespace
    $q_raw = (string) $request->get_param('q');
    $q_tmp = preg_replace("/[^\p{L}\p{Nd}\s\-'’]/u", '', $q_raw);
    $q_tmp = preg_replace('/\s+/u', ' ', $q_tmp);
    $q = trim($q_tmp);
    if ($q === '') { $q = null; }

    // 3) Prepare + execute stored procedure get_sessions3_f(id, sort_col, sort_dir, limit, offset, search)
    $stmt = $conn->prepare('CALL Test_Database.get_sessions3_f(?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        $conn->close();
        return new \WP_Error('profdef_prepare_failed', 'Failed to prepare stored procedure.', [
            'status' => 500,
            'debug'  => WP_DEBUG ? $conn->error : null,
        ]);
    }
    $sid_param_i = ($sid > 0) ? $sid : null; // allow NULL for no filter
    // If no session filter, pass NULL; otherwise pass id. Always pass limit/offset (defaulted)
    $stmt->bind_param('issiis', $sid_param_i, $sort_col, $dir, $limit, $offset, $q);

    if (!$stmt->execute()) {
        $err = $stmt->error ?: $conn->error;
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

    // 5) Return JSON (array of rows to preserve existing client expectations)
    return new \WP_REST_Response($rows, 200);
}
