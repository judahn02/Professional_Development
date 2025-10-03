<?php
// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up plugin options on uninstall.
// Note: This runs only on uninstall, not on deactivate/reactivate.

// Single-site options
delete_option('ProfessionalDevelopment_db_host');
delete_option('ProfessionalDevelopment_db_name');
delete_option('ProfessionalDevelopment_db_user');
delete_option('ProfessionalDevelopment_db_pass');
delete_option('ProfessionalDevelopment_DB_password');

// Multisite support: also delete site options if they were stored network-wide
if (function_exists('is_multisite') && is_multisite()) {
    delete_site_option('ProfessionalDevelopment_db_host');
    delete_site_option('ProfessionalDevelopment_db_name');
    delete_site_option('ProfessionalDevelopment_db_user');
    delete_site_option('ProfessionalDevelopment_db_pass');
    delete_site_option('ProfessionalDevelopment_DB_password');
}
