<?php
// Make sure WordPress is loaded if this runs as a standalone file
if ( ! defined('ABSPATH') ) {
    require_once( dirname(__FILE__) . '/wp-load.php' );
}

header('Content-Type: application/json');

// Only allow admins to access
if ( ! current_user_can('manage_options') ) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

$args = array(
    'role__in' => array('subscriber', 'contributor', 'author', 'editor', 'administrator'), 
    'orderby'  => 'last_name',
    'order'    => 'ASC',
    'fields'   => array('ID', 'user_email', 'first_name', 'last_name')
);

$user_query = new WP_User_Query($args);

$members = array();

if (!empty($user_query->get_results())) {
    foreach ($user_query->get_results() as $user) {
        $members[] = array(
            'id'               => $user->ID,
            'firstname'        => get_user_meta($user->ID, 'first_name', true),
            'lastname'         => get_user_meta($user->ID, 'last_name', true),
            'email'            => $user->user_email,
            // 'certificationType'=> get_user_meta($user->ID, 'certificationType', true), // custom field
            // 'totalHours'       => get_user_meta($user->ID, 'totalHours', true)         // custom field
        );
    }
}

echo json_encode($members);
exit;
