<?php
// Load WordPress core
require_once(dirname(__FILE__) . '/../../../../wp-load.php');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Only POST requests are allowed.']);
    exit;
}

// Make sure the user is logged in and is an admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Access denied. Admins only.']);
    exit;
}

// Make sure the decrypt function exists
if (!function_exists('ProfessionalDevelopment_decrypt')) {
    echo json_encode(['success' => false, 'error' => 'Decryption function not available.']);
    exit;
}

// Get encrypted credentials from options
$host_encrypted = get_option('ProfessionalDevelopment_db_host', '');
$name_encrypted = get_option('ProfessionalDevelopment_db_name', '');
$user_encrypted = get_option('ProfessionalDevelopment_db_user', '');
$pass_encrypted = get_option('ProfessionalDevelopment_db_pass', '');

if (!$host_encrypted || !$name_encrypted || !$user_encrypted || !$pass_encrypted) {
    echo json_encode(['success' => false, 'error' => 'Missing database credentials.']);
    exit;
}

// Decrypt values
$host = ProfessionalDevelopment_decrypt($host_encrypted);
$name = ProfessionalDevelopment_decrypt($name_encrypted);
$user = ProfessionalDevelopment_decrypt($user_encrypted);
$pass = ProfessionalDevelopment_decrypt($pass_encrypted);

// Fail if any are still blank
if (!$host || !$name || !$user || !$pass) {
    echo json_encode(['success' => false, 'error' => 'Decryption failed.']);
    exit;
}

// Attempt DB connection
$mysqli = new mysqli($host, $user, $pass, $name);

// Set content type
header('Content-Type: application/json');

if ($mysqli->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $mysqli->connect_error]);
} else {
    echo json_encode(['success' => true]);
    $mysqli->close();
}
