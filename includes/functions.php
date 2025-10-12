<?php
// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Key derivation: uses PS_ENCRYPTION_KEY if defined, otherwise derives a per-site key
// from WordPress salts and the site URL so no wp-config editing is required.

/**
 * Plugin utility functions for storing and retrieving a third-party service password.
 *
 * HOW TO USE:
 * 
 * - require_once plugin_dir_path(__FILE__) . 'include/functions.php';
 * 
 * - To save the password securely:
 *     ProfessionalDevelopment_DB_save_password('your_password_here');
 * 
 * - To retrieve the stored password:
 *     $password = ProfessionalDevelopment_DB_get_password();
 * 
 * - To delete the stored password:
 *     ProfessionalDevelopment_DB_delete_password();
 *
 * NOTE: Replace 'ProfessionalDevelopment_DB_password' with your own unique key if you're using this in another plugin.
 */

/**
 * Save the password to the WordPress options table.
 *
 * @param string $password The password to store.
 * @return bool True if successful, false otherwise.
 */
function ProfessionalDevelopment_DB_save_password($password) {
    /*
    Password Requirements:
    - At least 8 characters
    - May include uppercase, lowercase, numbers, and allowed symbols:
      ! @ # $ % ^ & * ( ) _ - + = ~ ?
    */

    if (!is_string($password)) {
        return false;
    }

    if (strlen($password) < 8 || strlen($password) > 255) {
        return false;
    }

    $encrypted_password = ProfessionalDevelopment_encrypt($password);
    return update_option('ProfessionalDevelopment_DB_password', $encrypted_password);
}

/**
 * Retrieve the stored service password.
 *
 * @return string|false The stored password or false if not found.
 */
function ProfessionalDevelopment_DB_get_password() {
    $encrypted = get_option('ProfessionalDevelopment_DB_password', false);
    return $encrypted ? ProfessionalDevelopment_decrypt($encrypted) : false;
}

/**
 * Delete the stored service password.
 *
 * @return bool True if deleted, false otherwise.
 */
function ProfessionalDevelopment_DB_delete_password() {
    return delete_option('ProfessionalDevelopment_DB_password');
}




function ProfessionalDevelopment_derive_key() {
    // Prefer explicit key if provided by the site owner
    if (defined('PS_ENCRYPTION_KEY') && PS_ENCRYPTION_KEY) {
        $material = (string) PS_ENCRYPTION_KEY;
    } else {
        // Derive per-site key material from salts and site URL
        if (!function_exists('wp_salt')) {
            require_once ABSPATH . 'wp-includes/pluggable.php';
        }
        $salt = function_exists('wp_salt') ? wp_salt('auth') : (defined('AUTH_KEY') ? AUTH_KEY : '');
        $site = function_exists('site_url') ? site_url() : '';
        $material = $salt . '|' . $site . '|Professional_Development';
    }
    // Produce a 32-byte key for AES-256
    return hash('sha256', $material, true);
}

function ProfessionalDevelopment_encrypt($data) {
    $key = ProfessionalDevelopment_derive_key();

    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function ProfessionalDevelopment_decrypt($encrypted) {
    $data = base64_decode($encrypted);
    if ($data === false || strlen($data) <= 16) return false;

    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);

    // Try current derived key first
    $key = ProfessionalDevelopment_derive_key();
    $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv);
    if ($plain !== false && $plain !== null) return $plain;

    // Backward-compat: attempt legacy dev key used in older versions
    $legacy_key = 'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm';
    return openssl_decrypt($ciphertext, 'AES-256-CBC', $legacy_key, 0, $iv);
}

function pd_sessions_permission( WP_REST_Request $request ) {
    $nonce = $request->get_header('X-WP-Nonce');
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return new WP_Error('rest_forbidden', __( 'Bad or missing nonce.', 'professional-development' ), ['status' => 403]);
    }
    if ( ! current_user_can('manage_options') ) {
        return new WP_Error('rest_forbidden', __( 'Insufficient permissions.', 'professional-development' ), ['status' => 403]);
    }
    return true;
}