<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('profdev/v1', '/sessions', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'pd_sessions_list',
        'permission_callback' => 'pd_sessions_permission',
    ]);

    register_rest_route('profdev/v1', '/sessions/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'pd_sessions_get_item',
        'permission_callback' => 'pd_sessions_permission',
        'args'                => [
            'id' => [
                'type'     => 'integer',
                'required' => true,
            ],
        ],
    ]);

    register_rest_route('profdev/v1', '/sessions', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'pd_sessions_create',
        'permission_callback' => 'pd_sessions_permission',
        'args'                => pd_sessions_args_schema(),
    ]);

    register_rest_route('profdev/v1', '/sessions/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => 'pd_sessions_update',
        'permission_callback' => 'pd_sessions_permission',
        'args'                => array_merge(
            [
                'id' => [
                    'type'     => 'integer',
                    'required' => true,
                ],
            ],
            pd_sessions_args_schema()
        ),
    ]);
});

# This is moved to the functions.php file
# function pd_sessions_permission( WP_REST_Request $request )


function pd_sessions_args_schema() {
    $schema = [
        'date' => [
            'type'     => 'string',
            'required' => true,
        ],
        'title' => [
            'type'     => 'string',
            'required' => true,
        ],
        'length' => [
            'type'     => 'integer',
            'required' => true,
        ],
        'stype' => [
            'type'     => 'string',
            'required' => true,
        ],
        'ceuWeight' => [
            'type'     => 'string',
            'required' => false,
        ],
        'ceuConsiderations' => [
            'type'     => 'string',
            'required' => false,
        ],
        'qualifyForCeus' => [
            'type'     => 'string',
            'required' => true,
        ],
        'eventType' => [
            'type'     => 'string',
            'required' => true,
        ],
        'presenters' => [
            'type'     => 'string',
            'required' => true,
        ],
    ];
    return apply_filters('pd_sessions_args_schema', $schema);
}

function pd_sessions_db_options() {
    $defaults = [
        'host' => get_option('ProfessionalDevelopment_db_host', ''),
        'name' => get_option('ProfessionalDevelopment_db_name', ''),
        'user' => get_option('ProfessionalDevelopment_db_user', ''),
        'pass' => get_option('ProfessionalDevelopment_db_pass', ''),
    ];
    return apply_filters('pd_sessions_db_options', $defaults);
}

function pd_sessions_connect() {
    $options = pd_sessions_db_options();

    $host = isset($options['host']) ? ProfessionalDevelopment_decrypt($options['host']) : '';
    $name = isset($options['name']) ? ProfessionalDevelopment_decrypt($options['name']) : '';
    $user = isset($options['user']) ? ProfessionalDevelopment_decrypt($options['user']) : '';
    $pass = isset($options['pass']) ? ProfessionalDevelopment_decrypt($options['pass']) : '';

    if (! $host || ! $name || ! $user || $pass === false) {
        return new WP_Error('pd_sessions_credentials', __( 'Database credentials are missing or could not be decrypted.', 'professional-development' ), ['status' => 500]);
    }

    $mysqli = @new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_error) {
        return new WP_Error('db_connect_error', sprintf(__( 'Database connection failed: %s', 'professional-development' ), $mysqli->connect_error), ['status' => 500]);
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function pd_sessions_drain_results(mysqli $conn) {
    while ($conn->more_results() && $conn->next_result()) {
        if ($result = $conn->use_result()) {
            $result->free();
        }
    }
}

function pd_sessions_wp_error(mysqli $conn, $default_message, $code = 'db_query_error', $status = 500) {
    $errno  = $conn->errno;
    $detail = $conn->error;

    if ($errno === 1305) { // ER_SP_DOES_NOT_EXIST
        $hint = __( 'The expected stored procedure is missing. Create it in the database or override the filter (e.g. pd_sessions_fetch_proc).', 'professional-development' );
        return new WP_Error('db_missing_procedure', $hint, ['status' => 500]);
    }
    if ($detail) {
        $default_message .= ' ' . $detail;
    }
    return new WP_Error($code, $default_message, ['status' => $status]);
}

function pd_sessions_normalize_row(array $row) {
    $id = null;
    foreach (['id', 'session_id', 'ID'] as $key) {
        if (isset($row[$key])) {
            $id = (int) $row[$key];
            break;
        }
    }

    $raw_date = '';
    foreach (['date', 'session_date', 'sessionDate'] as $key) {
        if (!empty($row[$key])) {
            $raw_date = (string) $row[$key];
            break;
        }
    }
    $iso_date = pd_sessions_to_iso_date($raw_date);
    $display_date = $iso_date ? pd_sessions_format_date($iso_date) : $raw_date;

    $length = null;
    foreach (['length', 'length_minutes', 'duration'] as $key) {
        if (isset($row[$key])) {
            $length = (int) $row[$key];
            break;
        }
    }

    $stype = '';
    foreach (['stype', 'session_type', 'type'] as $key) {
        if (isset($row[$key])) {
            $stype = (string) $row[$key];
            break;
        }
    }

    $event_type = '';
    foreach (['eventType', 'event_type'] as $key) {
        if (isset($row[$key])) {
            $event_type = (string) $row[$key];
            break;
        }
    }

    $ceu_weight = '';
    foreach (['ceuWeight', 'ceu_weight'] as $key) {
        if (isset($row[$key])) {
            $ceu_weight = (string) $row[$key];
            break;
        }
    }

    $ceu_considerations = '';
    foreach (['ceuConsiderations', 'ceu_considerations'] as $key) {
        if (isset($row[$key])) {
            $ceu_considerations = (string) $row[$key];
            break;
        }
    }

    $qualify = '';
    foreach (['qualifyForCeus', 'qualify_for_ceus', 'qualify'] as $key) {
        if (isset($row[$key])) {
            $qualify = (string) $row[$key];
            break;
        }
    }

    $presenters = '';
    foreach (['presenters', 'presenter_names'] as $key) {
        if (isset($row[$key])) {
            $presenters = (string) $row[$key];
            break;
        }
    }

    $members = [];
    if (!empty($row['members']) && is_array($row['members'])) {
        $members = $row['members'];
    } elseif (!empty($row['members_json'])) {
        $decoded = json_decode($row['members_json'], true);
        if (is_array($decoded)) {
            $members = $decoded;
        }
    }

    $normalized = [
        'id'                => $id,
        'date'              => $display_date,
        'isoDate'           => $iso_date,
        'title'             => isset($row['title']) ? (string) $row['title'] : ((isset($row['session_title'])) ? (string) $row['session_title'] : ''),
        'length'            => $length,
        'stype'             => $stype,
        'eventType'         => $event_type,
        'ceuWeight'         => $ceu_weight,
        'ceuConsiderations' => $ceu_considerations,
        'qualifyForCeus'    => $qualify,
        'presenters'        => $presenters,
        'members'           => $members,
    ];

    return apply_filters('pd_sessions_normalize_row', $normalized, $row);
}

function pd_sessions_to_iso_date($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
        [$month, $day, $year] = array_map('intval', explode('/', $value));
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    $timestamp = strtotime($value);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    return '';
}

function pd_sessions_format_date($iso) {
    if (! $iso) {
        return '';
    }
    $timestamp = strtotime($iso);
    if (! $timestamp) {
        return $iso;
    }
    return date('n/j/Y', $timestamp);
}

function pd_sessions_list( WP_REST_Request $request ) {
    $conn = pd_sessions_connect();
    if (is_wp_error($conn)) {
        return $conn;
    }

    $sql = apply_filters('pd_sessions_fetch_proc', 'CALL sessions_table_view()');
    $result = $conn->query($sql);
    if (! $result) {
        $error = pd_sessions_wp_error($conn, __( 'Failed to fetch sessions.', 'professional-development' ));
        $conn->close();
        return $error;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = pd_sessions_normalize_row($row);
    }
    $result->free();
    pd_sessions_drain_results($conn);
    $conn->close();

    return rest_ensure_response($rows);
}

function pd_sessions_get_item( WP_REST_Request $request ) {
    $id = (int) $request['id'];

    $conn = pd_sessions_connect();
    if (is_wp_error($conn)) {
        return $conn;
    }

    $sql_pattern = apply_filters('pd_sessions_detail_proc', 'CALL session_profile_view(%d)');
    $sql = sprintf($sql_pattern, $id);
    $result = $conn->query($sql);
    if (! $result) {
        $error = pd_sessions_wp_error($conn, __( 'Failed to fetch the session.', 'professional-development' ));
        $conn->close();
        return $error;
    }

    $row = $result->fetch_assoc();
    $result->free();
    pd_sessions_drain_results($conn);
    $conn->close();

    if (! $row) {
        return new WP_Error('rest_not_found', __( 'Session not found.', 'professional-development' ), ['status' => 404]);
    }

    return rest_ensure_response(pd_sessions_normalize_row($row));
}

function pd_sessions_collect_payload( WP_REST_Request $request ) {
    $date_raw = trim((string) $request->get_param('date'));
    $iso_date = pd_sessions_to_iso_date($date_raw);
    if (! $iso_date) {
        return new WP_Error('rest_invalid_param', __( 'Invalid session date.', 'professional-development' ), ['status' => 400]);
    }

    $length = (int) $request->get_param('length');
    if ($length < 0) {
        $length = abs($length);
    }

    $qualify = strtoupper(substr(trim((string) $request->get_param('qualifyForCeus')), 0, 3));
    $qualify = ($qualify === 'YES') ? 'Yes' : 'No';

    $payload = [
        'date'              => $iso_date,
        'title'             => sanitize_text_field((string) $request->get_param('title')),
        'length'            => $length,
        'stype'             => sanitize_text_field((string) $request->get_param('stype')),
        'ceuWeight'         => sanitize_text_field((string) $request->get_param('ceuWeight')),
        'ceuConsiderations' => sanitize_textarea_field((string) $request->get_param('ceuConsiderations')),
        'qualifyForCeus'    => $qualify,
        'eventType'         => sanitize_text_field((string) $request->get_param('eventType')),
        'presenters'        => sanitize_text_field((string) $request->get_param('presenters')),
    ];

    if ($payload['title'] === '') {
        return new WP_Error('rest_invalid_param', __( 'Session title is required.', 'professional-development' ), ['status' => 400]);
    }
    if ($payload['stype'] === '') {
        return new WP_Error('rest_invalid_param', __( 'Session type is required.', 'professional-development' ), ['status' => 400]);
    }
    if ($payload['eventType'] === '') {
        return new WP_Error('rest_invalid_param', __( 'Event type is required.', 'professional-development' ), ['status' => 400]);
    }
    if ($payload['presenters'] === '') {
        return new WP_Error('rest_invalid_param', __( 'Presenter information is required.', 'professional-development' ), ['status' => 400]);
    }

    return $payload;
}

function pd_sessions_after_write(mysqli $conn) {
    $status = $conn->query('SELECT @ok AS success, LAST_INSERT_ID() AS id');
    if (! $status) {
        return new WP_Error('db_exec_error', __( 'Unable to determine stored procedure status.', 'professional-development' ), ['status' => 500]);
    }
    $row = $status->fetch_assoc();
    $status->free();
    pd_sessions_drain_results($conn);

    $success = isset($row['success']) ? (int) $row['success'] : 0;
    $new_id  = isset($row['id']) ? (int) $row['id'] : 0;

    if ($success !== 1) {
        return new WP_Error('db_exec_error', __( 'The database reported a failure when saving the session.', 'professional-development' ), ['status' => 500]);
    }

    return ['id' => $new_id];
}

function pd_sessions_fetch_single(mysqli $conn, $id) {
    $sql_pattern = apply_filters('pd_sessions_detail_proc', 'CALL session_profile_view(%d)');
    $sql = sprintf($sql_pattern, (int) $id);
    $result = $conn->query($sql);
    if (! $result) {
        return pd_sessions_wp_error($conn, __( 'Failed to fetch the saved session.', 'professional-development' ));
    }
    $row = $result->fetch_assoc();
    $result->free();
    pd_sessions_drain_results($conn);
    if (! $row) {
        return new WP_Error('rest_not_found', __( 'Session not found after saving.', 'professional-development' ), ['status' => 404]);
    }
    return $row;
}

function pd_sessions_create( WP_REST_Request $request ) {
    $payload = pd_sessions_collect_payload($request);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $conn = pd_sessions_connect();
    if (is_wp_error($conn)) {
        return $conn;
    }

    $sql = apply_filters('pd_sessions_insert_proc', 'CALL add_session(?, ?, ?, ?, ?, ?, ?, ?, ?, @ok)');
    $stmt = $conn->prepare($sql);
    if (! $stmt) {
        $error = pd_sessions_wp_error($conn, __( 'Unable to prepare the session insert statement.', 'professional-development' ));
        $conn->close();
        return $error;
    }

    $length = isset($payload['length']) ? (int) $payload['length'] : 0;
    $stmt->bind_param(
        'ssissssss',
        $payload['date'],
        $payload['title'],
        $length,
        $payload['stype'],
        $payload['ceuWeight'],
        $payload['ceuConsiderations'],
        $payload['qualifyForCeus'],
        $payload['eventType'],
        $payload['presenters']
    );

    if (! $stmt->execute()) {
        $stmt->close();
        $error = pd_sessions_wp_error($conn, __( 'Failed to insert the session.', 'professional-development' ));
        $conn->close();
        return $error;
    }
    $stmt->close();

    $status = pd_sessions_after_write($conn);
    if (is_wp_error($status)) {
        $conn->close();
        return $status;
    }

    $row = pd_sessions_fetch_single($conn, $status['id']);
    $conn->close();
    if (is_wp_error($row)) {
        return $row;
    }

    return new WP_REST_Response(pd_sessions_normalize_row($row), 201);
}

function pd_sessions_update( WP_REST_Request $request ) {
    $payload = pd_sessions_collect_payload($request);
    if (is_wp_error($payload)) {
        return $payload;
    }
    $payload['id'] = (int) $request['id'];
    if ($payload['id'] <= 0) {
        return new WP_Error('rest_invalid_param', __( 'Invalid session identifier.', 'professional-development' ), ['status' => 400]);
    }

    $conn = pd_sessions_connect();
    if (is_wp_error($conn)) {
        return $conn;
    }

    $sql = apply_filters('pd_sessions_update_proc', 'CALL update_session(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @ok)');
    $stmt = $conn->prepare($sql);
    if (! $stmt) {
        $error = pd_sessions_wp_error($conn, __( 'Unable to prepare the session update statement.', 'professional-development' ));
        $conn->close();
        return $error;
    }

    $length = isset($payload['length']) ? (int) $payload['length'] : 0;
    $stmt->bind_param(
        'ississssss',
        $payload['id'],
        $payload['date'],
        $payload['title'],
        $length,
        $payload['stype'],
        $payload['ceuWeight'],
        $payload['ceuConsiderations'],
        $payload['qualifyForCeus'],
        $payload['eventType'],
        $payload['presenters']
    );

    if (! $stmt->execute()) {
        $stmt->close();
        $error = pd_sessions_wp_error($conn, __( 'Failed to update the session.', 'professional-development' ));
        $conn->close();
        return $error;
    }
    $stmt->close();

    $status = pd_sessions_after_write($conn);
    if (is_wp_error($status)) {
        $conn->close();
        return $status;
    }

    $row = pd_sessions_fetch_single($conn, $payload['id']);
    $conn->close();
    if (is_wp_error($row)) {
        return $row;
    }

    return rest_ensure_response(pd_sessions_normalize_row($row));
}
