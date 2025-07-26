<?php
// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// !! BEFORE RELEASE: Change 'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm' to a secure key, ideally stored in wp-config.php

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




function ProfessionalDevelopment_encrypt($data) {
    $key = defined('PS_ENCRYPTION_KEY') ? PS_ENCRYPTION_KEY : 'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm'; // fallback for dev

    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function ProfessionalDevelopment_decrypt($encrypted) {
    $key = defined('PS_ENCRYPTION_KEY') ? PS_ENCRYPTION_KEY : 'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm'; // fallback for dev

    $data = base64_decode($encrypted);
    if ($data === false || strlen($data) <= 16) return false;

    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv);
}
