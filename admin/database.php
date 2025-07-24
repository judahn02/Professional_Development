<?php
/*
This is a php file for just on wordpress database management.

*/

defined('ABSPATH') || exit;

// OPTIONAL: Admin menu to view contents
// add_action('admin_menu', function () {
//     add_menu_page('KV Store', 'KV Store', 'manage_options', 'kvstore', 'kvstore_admin_page');
// });

// function kvstore_admin_page() {
//     global $wpdb;
//     $table_name = $wpdb->prefix . 'kvstore';

//     // Handle form submission
//     if (isset($_POST['kv_key'], $_POST['kv_value'])) {
//         $key = sanitize_text_field($_POST['kv_key']);
//         $value = sanitize_textarea_field($_POST['kv_value']);
//         $wpdb->replace($table_name, [ 'kv_key' => $key, 'kv_value' => $value ]);
//         echo '<div class="updated"><p>Saved.</p></div>';
//     }

//     // Handle deletion
//     if (isset($_GET['delete'])) {
//         $key = sanitize_text_field($_GET['delete']);
//         $wpdb->delete($table_name, ['kv_key' => $key]);
//         echo '<div class="updated"><p>Deleted.</p></div>';
//     }

//     // Show form and table
//     $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY kv_key ASC");

//     echo '<div class="wrap"><h1>Key Value Store</h1>';
//     echo '<form method="post">';
//     echo '<input type="text" name="kv_key" placeholder="Key" required> ';
//     echo '<input type="text" name="kv_value" placeholder="Value" required> ';
//     submit_button('Save Key/Value');
//     echo '</form>';

//     echo '<h2>Current Values</h2><table class="widefat"><thead><tr><th>Key</th><th>Value</th><th>Action</th></tr></thead><tbody>';
//     foreach ($results as $row) {
//         echo "<tr><td>{$row->kv_key}</td><td>{$row->kv_value}</td><td><a href='?page=kvstore&delete={$row->kv_key}'>Delete</a></td></tr>";
//     }
//     echo '</tbody></table></div>';
// }
