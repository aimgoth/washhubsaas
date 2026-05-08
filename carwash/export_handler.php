<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/export_errors.log');

// Start session at the very beginning

// Include session configuration after session_start()
require_once 'config/session.php';
require_once 'config/database.php';

// Only admins can export reports
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401); // Unauthorized
    die('Unauthorized access');
}

// Get month and year from GET parameters
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

// Log the request
error_log("Export request - Month: $month, Year: $year");

// Include the export logic
require_once 'export_monthly_report.php';
?>
