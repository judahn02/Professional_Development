<?php
/*
Plugin Name: Professional Development
Plugin URI: 
Description: Integration Plugin for Professional Development tracking and logging.
Version: 0.1
Author: Parallel Solvit LLC
Author URI: https://parallelsolvit.com/
License: 
*/

// defined('ABSPATH') || exit ;

// require_once 'admin/index.php' ;
// require_once 'public/index.php' ;


// ------ Activation, Deactivation, and Deletion Hooks: meta table set up.
defined('ABSPATH') || exit;

// Activation: Create custom table
register_activation_hook(__FILE__, 'profDef_create_table');
function profDef_create_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'profDef';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        kv_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        kv_key VARCHAR(191) NOT NULL,
        kv_value TEXT NOT NULL,
        PRIMARY KEY (kv_id),
        UNIQUE KEY kv_key (kv_key)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Deactivation: nothing
register_deactivation_hook(__FILE__, function () {
    // No action on deactivation
});

// Deletion: Drop the table
register_uninstall_hook(__FILE__, 'profDef_delete_table');
function profDef_delete_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'profDef';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}
