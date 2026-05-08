<?php
// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
require_once __DIR__ . '/../config/session_prefixed.php';
require_once __DIR__ . '/../config/database_prefixed.php';

// Check if user is trying to access a protected page without logging in
$public_pages = ['login.php', 'register.php', 'forgot-password.php'];
$current_page = basename($_SERVER['PHP_SELF']);

if (!in_array($current_page, $public_pages) && !is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Set default timezone
date_default_timezone_set('Africa/Lagos');

// Function to get the prefixed table name
function get_table($table) {
    global $CLIENT_PREFIX;
    return $CLIENT_PREFIX . $table;
}

// Function to get the current user's ID
function get_user_id() {
    return $_SESSION['user_id'] ?? null;
}

// Function to get the current user's role
function get_user_role() {
    return $_SESSION['role'] ?? null;
}

// Function to check if current user is admin
function is_admin() {
    return get_user_role() === 'admin' || get_user_role() === 'superadmin';
}

// Function to check if current user is superadmin
function is_superadmin() {
    return get_user_role() === 'superadmin';
}

// Function to escape output to prevent XSS
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}
?>
