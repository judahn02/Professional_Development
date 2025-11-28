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

/**
 * Minimal SQL string escaper for values embedded into a CALL statement.
 * Uses simple backslash + single-quote escaping, assuming MySQL-compatible syntax.
 *
 * @param string|null $value
 * @return string SQL literal or NULL
 */
function pd_sessionhome_sql_quote( ?string $value ): string {
    if ( $value === null ) {
        return 'NULL';
    }
    $escaped = str_replace( ['\\', '\''], ['\\\\', '\\\''], $value );
    return '\'' . $escaped . '\'';
}

function pd_sessions_home_callback( WP_REST_Request $request ) {
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

    // New implementation: use the signed API connection defined in admin/skeleton2.php.
    // Build CALL statement with safely quoted values, matching procedure:
    // get_sessions3_f(IN id, IN sort_col, IN sort_dir, IN limit, IN offset, IN search)
    $sid_literal   = ($sid > 0) ? (string) (int) $sid : 'NULL';
    $sort_literal  = pd_sessionhome_sql_quote( $sort_col );
    $dir_literal   = pd_sessionhome_sql_quote( $dir );
    $search_literal = pd_sessionhome_sql_quote( $q );

    $sql = sprintf(
        'CALL beta_2.get_sessions3_f(%s, %s, %s, %d, %d, %s);',
        $sid_literal,
        $sort_literal,
        $dir_literal,
        $limit,
        $offset,
        $search_literal
    );

    try {
        if ( ! function_exists( 'aslta_signed_query' ) ) {
            // Fallback: try to load the helper if, for some reason, the main plugin file has not.
            $plugin_root   = dirname( dirname( __DIR__ ) ); // .../Professional_Development
            $skeleton_path = $plugin_root . '/admin/skeleton2.php';
            if ( is_readable( $skeleton_path ) ) {
                require_once $skeleton_path;
            }
        }

        if ( ! function_exists( 'aslta_signed_query' ) ) {
            return new \WP_Error(
                'aslta_helper_missing',
                'Signed query helper is not available.',
                [ 'status' => 500 ]
            );
        }

        $result = aslta_signed_query( $sql );
    } catch ( \Throwable $e ) {
        return new \WP_Error(
            'aslta_remote_error',
            'Failed to query sessions via remote API.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? $e->getMessage() : null ),
            ]
        );
    }

    if ( $result['status'] < 200 || $result['status'] >= 300 ) {
        return new \WP_Error(
            'aslta_remote_http_error',
            'Remote sessions endpoint returned an HTTP error.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? [ 'http_code' => $result['status'], 'body' => $result['body'] ] : null ),
            ]
        );
    }

    $decoded = json_decode( $result['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new \WP_Error(
            'aslta_json_error',
            'Failed to decode sessions JSON response.',
            [
                'status' => 500,
                'debug'  => ( WP_DEBUG ? json_last_error_msg() : null ),
            ]
        );
    }

    // Normalise to a list of row arrays.
    if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
        $rows = $decoded['rows'];
    } elseif ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
        $rows = $decoded;
    } else {
        $rows = [ $decoded ];
    }

    // Return JSON (array of rows to preserve existing client expectations)
    return new \WP_REST_Response( $rows, 200 );

    /*
     * Previous implementation (direct MySQL connection and stored procedure call)
     * kept for reference. This has been replaced by the signed remote API
     * connection wired through admin/skeleton2.php (aslta_signed_query()).
     *
     * // 1) Decrypt DB credentials from options
     * $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
     * $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
     * $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
     * $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));
     *
     * if (!$host || !$name || !$user) {
     *     return new \WP_Error('profdef_db_creds_missing', 'Database credentials are not configured.', ['status' => 500]);
     * }
     *
     * // 2) Connect
     * $conn = @new mysqli($host, $user, $pass, $name);
     * if ($conn->connect_error) {
     *     return new \WP_Error('mysql_not_connect', 'Database connection failed.', [
     *         'status' => 500,
     *         'debug'  => WP_DEBUG ? $conn->connect_error : null,
     *     ]);
     * }
     * $conn->set_charset('utf8mb4');
     *
     * // Param helpers
     * $sid_param = $request->get_param('session_id');
     * $sid = ($sid_param !== null && $sid_param !== '') ? (int) $sid_param : 0;
     *
     * $page = (int) $request->get_param('page');
     * if ($page <= 0) { $page = 1; }
     * $per_page = (int) $request->get_param('per_page');
     * if ($per_page <= 0) { $per_page = 25; }
     * if ($per_page > 100) { $per_page = 100; }
     *
     * // Sort mapping: accept friendly keys or exact column labels required by the proc
     * $sort_in = (string) $request->get_param('sort');
     * $sort_in = trim($sort_in);
     * $sort_map = [
     *     'date' => 'Date', 'Date' => 'Date',
     *     'title' => 'Title', 'Title' => 'Title',
     *     'length' => 'Length', 'Length' => 'Length',
     *     'stype' => 'Session Type', 'session type' => 'Session Type', 'Session Type' => 'Session Type',
     *     'ceuweight' => 'CEU Weight', 'ceuWeight' => 'CEU Weight', 'CEU Weight' => 'CEU Weight',
     *     'ceuconst' => 'CEU Const', 'ceuConst' => 'CEU Const', 'CEU Const' => 'CEU Const',
     *     'ceucapable' => 'CEU Capable', 'ceuCapable' => 'CEU Capable', 'CEU Capable' => 'CEU Capable',
     *     'event' => 'Event Type', 'eventtype' => 'Event Type', 'eventType' => 'Event Type', 'Event Type' => 'Event Type',
     *     'parentevent' => 'Parent Event', 'parentEvent' => 'Parent Event', 'Parent Event' => 'Parent Event',
     *     'presenters' => 'presenters',
     *     'attendees' => 'attendees_ct', 'attendeesct' => 'attendees_ct', 'attendees_ct' => 'attendees_ct', 'attendeesCt' => 'attendees_ct',
     * ];
     * $norm_key = strtolower(str_replace(['_', ' ', '-'], '', $sort_in));
     * $sort_col = isset($sort_map[$norm_key]) ? $sort_map[$norm_key] : 'Date';
     *
     * $dir_in = (string) $request->get_param('dir');
     * $dir = strtoupper($dir_in === '' ? '' : $dir_in);
     * if ($dir !== 'ASC' && $dir !== 'DESC') { $dir = 'DESC'; }
     *
     * $limit = $per_page;
     * $offset = ($page - 1) * $per_page;
     * if ($offset < 0) { $offset = 0; }
     *
     * // Sanitize search q: allow letters, digits, spaces, hyphens, ASCII ' and Unicode ’; collapse whitespace
     * $q_raw = (string) $request->get_param('q');
     * $q_tmp = preg_replace(\"/[^\\p{L}\\p{Nd}\\s\\-'’]/u\", '', $q_raw);
     * $q_tmp = preg_replace('/\\s+/u', ' ', $q_tmp);
     * $q = trim($q_tmp);
     * if ($q === '') { $q = null; }
     *
     * // 3) Prepare + execute stored procedure get_sessions3_f(id, sort_col, sort_dir, limit, offset, search)
     * $stmt = $conn->prepare('CALL Test_Database.get_sessions3_f(?, ?, ?, ?, ?, ?)');
     * if (!$stmt) {
     *     $conn->close();
     *     return new \\WP_Error('profdef_prepare_failed', 'Failed to prepare stored procedure.', [
     *         'status' => 500,
     *         'debug'  => WP_DEBUG ? $conn->error : null,
     *     ]);
     * }
     * $sid_param_i = ($sid > 0) ? $sid : null; // allow NULL for no filter
     * // If no session filter, pass NULL; otherwise pass id. Always pass limit/offset (defaulted)
     * $stmt->bind_param('issiis', $sid_param_i, $sort_col, $dir, $limit, $offset, $q);
     *
     * if (!$stmt->execute()) {
     *     $err = $stmt->error ?: $conn->error;
     *     $stmt->close();
     *     $conn->close();
     *     return new \\WP_Error('profdef_exec_failed', 'Failed to execute stored procedure.', [
     *         'status' => 500,
     *         'debug'  => WP_DEBUG ? $err : null,
     *     ]);
     * }
     *
     * // 4) Collect results (handles multiple result sets)
     * $rows = [];
     * do {
     *     if ($result = $stmt->get_result()) {
     *         while ($row = $result->fetch_assoc()) {
     *             $rows[] = $row;
     *         }
     *         $result->free();
     *     }
     * } while ($stmt->more_results() && $stmt->next_result());
     *
     * $stmt->close();
     * $conn->close();
     *
     * // 5) Return JSON (array of rows to preserve existing client expectations)
     * return new \\WP_REST_Response($rows, 200);
     */
}
