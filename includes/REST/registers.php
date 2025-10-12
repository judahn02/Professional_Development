<?php
// includes/REST/registers.php
if ( ! defined('ABSPATH') ) { exit; }




// /ping
$ping = ['methods' => WP_REST_Server::READABLE, 'callback' => 'profdef_ping', 'permission_callback' => '__return_true'] ;
function profdef_ping( WP_REST_Request $req ) {
    $responce = ['ok' => true, 'timestamp' => current_time('mysql'), 'site' => get_bloginfo('name')] ;
    return new WP_REST_Response($responce, 200);
}

// /sessions
// /sessions (add args but still READ ONLY + public)
$sessions = [
  'methods'             => WP_REST_Server::READABLE,
  'permission_callback' => '__return_true',
  'args'                => [
    'search'   => ['type' => 'string',  'required' => false],
    'page'     => ['type' => 'integer', 'required' => false, 'default' => 1],
    'per_page' => ['type' => 'integer', 'required' => false, 'default' => 20],
    'order'    => ['type' => 'string',  'required' => false, 'enum' => ['asc','desc']],
    'orderby'  => ['type' => 'string',  'required' => false, 'enum' => ['title','date','name']],
  ],
  'callback'            => 'profdef_v2_sessions_list_min'
];
function profdef_v2_sessions_list_min( WP_REST_Request $req ) {
    // Temporary hardcoded data so we can wire up the UI before SQL.
    // Tweak/add items as you like.

    $rows = [
        [
            'id'                => 101,
            'title'             => 'Sample Session A',
            'date'              => '2025-10-15T14:00:00Z',   // ISO 8601 (JS Date-friendly)
            'length'            => '60',                    // string
            'Attached_Event'    => 'Fall Conference 2025',
            'Session_Type'      => 'Workshop',
            'CEU_Consideration' => 'Language/Teaching',
            'Event_Type'        => 'Conference',
            'CEU_Weight'        => 1.0                      // float
        ],
        [
            'id'                => 100,
            'title'             => 'Sample Session B',
            'date'              => '2025-11-01T19:30:00Z',
            'length'            => '90',
            'Attached_Event'    => 'Regional PD Series',
            'Session_Type'      => 'Lecture',
            'CEU_Consideration' => 'Ethics',
            'Event_Type'        => 'Webinar',
            'CEU_Weight'        => 0.5
        ],
        [
            'id'                => 99,
            'title'             => 'Sample Session C',
            'date'              => '2025-12-05T15:00:00Z',
            'length'            => '45',
            'Attached_Event'    => 'Winter Summit',
            'Session_Type'      => 'Panel',
            'CEU_Consideration' => 'Instructional Design',
            'Event_Type'        => 'Conference',
            'CEU_Weight'        => 1.25
        ],
    ];


    // ----- PLACEHOLDER PARAM HANDLING (not applied yet) -----
    $search   = (string) ($req->get_param('search') ?? '');
    $page     = max(1, (int) ($req->get_param('page') ?? 1));
    $per_page = max(1, min(100, (int) ($req->get_param('per_page') ?? 20)));

    // whitelist order/orderby to safe defaults if invalid/missing
    $order    = strtolower((string) ($req->get_param('order') ?? 'desc'));
    if (!in_array($order, ['asc','desc'], true)) { $order = 'desc'; }

    $orderby  = (string) ($req->get_param('orderby') ?? 'date');
    if (!in_array($orderby, ['title','date','name'], true)) { $orderby = 'date'; }

    // For now, DON’T apply search/order/orderby; only paginate the static array
    $offset = ($page - 1) * $per_page;
    $paged  = array_slice($rows, $offset, $per_page);

    // ----- RESPONSE (keep body as array; put param “comments” in headers) -----
    $res = new WP_REST_Response($paged, 200);

    // standard pagination headers
    $total = count($rows);
    $res->header('X-WP-Total', (string) $total);
    $res->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));

    // “Comments” about placeholders (visible with -i); safe to remove later
    $res->header('X-Params-Search', $search);
    $res->header('X-Params-Page', (string) $page);
    $res->header('X-Params-Per-Page', (string) $per_page);
    $res->header('X-Params-Order', $order);
    $res->header('X-Params-OrderBy', $orderby);
    $res->header('X-Note', 'placeholders only: search/order/orderby not applied yet');

    return $res;

}

// v2: sessions single (by id) — uses local dummy data
add_action('rest_api_init', function () {
    $ns = 'profdef/v2';

    // Local dummy data (keep in sync with the list route)
    $rows = [
        [
            'id' => 101, 'title' => 'Sample Session A',
            'date' => '2025-10-15T14:00:00Z', 'length' => '60',
            'Attached_Event' => 'Fall Conference 2025', 'Session_Type' => 'Workshop',
            'CEU_Consideration' => 'Language/Teaching', 'Event_Type' => 'Conference',
            'CEU_Weight' => 1.0
        ],
        [
            'id' => 100, 'title' => 'Sample Session B',
            'date' => '2025-11-01T19:30:00Z', 'length' => '90',
            'Attached_Event' => 'Regional PD Series', 'Session_Type' => 'Lecture',
            'CEU_Consideration' => 'Ethics', 'Event_Type' => 'Webinar',
            'CEU_Weight' => 0.5
        ],
        [
            'id' =>  99, 'title' => 'Sample Session C',
            'date' => '2025-12-05T15:00:00Z', 'length' => '45',
            'Attached_Event' => 'Winter Summit', 'Session_Type' => 'Panel',
            'CEU_Consideration' => 'Instructional Design', 'Event_Type' => 'Conference',
            'CEU_Weight' => 1.25
        ],
    ];

    register_rest_route($ns, '/sessions/(?P<id>\d+)', [
        'methods'             => \WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => ['type' => 'integer', 'required' => true],
        ],
        'callback'            => function (\WP_REST_Request $req) use ($rows) {
            $id = (int) $req->get_param('id');

            foreach ($rows as $row) {
                if ((int) $row['id'] === $id) {
                    return new \WP_REST_Response($row, 200);
                }
            }

            return new \WP_Error('profdef_not_found', 'Session not found', ['status' => 404]);
        },
    ]);
});


// Register routes
add_action('rest_api_init', function () use ($ping, $sessions) {
    $ns = 'profdef/v2';
    register_rest_route($ns, '/ping', $ping);
    register_rest_route($ns, '/sessions', $sessions);
});
