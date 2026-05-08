<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

ob_start(); // Start output buffering

require_once 'config/session.php';
require_once 'config/database.php';
require_once 'includes/csrf.php';

// Setup error logging
$log_file = __DIR__ . '/logs/submission_errors.log';
@mkdir(dirname($log_file), 0755, true); // Ensure logs directory exists
ini_set('log_errors', 1);
ini_set('error_log', $log_file);

function log_error($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Check if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        sendJsonResponse(['error' => 'Invalid request method.'], 405);
    }
    $_SESSION['flash_error'] = 'Invalid request method.';
    header('Location: daily_report_preview.php');
    exit();
}

// Verify CSRF token
$token_valid = isset($_SESSION['csrf_token']) && !empty($_POST['_token']) && 
               hash_equals($_SESSION['csrf_token'], $_POST['_token']);
               
if (!isset($_POST['submit_daily_report']) || !$token_valid) {
    $errorMsg = 'Invalid CSRF token. Please refresh the page and try again.';
    if ($isAjax) {
        sendJsonResponse(['error' => $errorMsg], 403);
    }
    $_SESSION['flash_error'] = $errorMsg;
    header('Location: daily_report_preview.php');
    exit();
}

// Role check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    $errorMsg = 'You do not have permission to perform this action.';
    if ($isAjax) {
        sendJsonResponse(['error' => $errorMsg], 403);
    }
    $_SESSION['flash_error'] = $errorMsg;
    header('Location: daily_report_preview.php');
    exit();
}

$reportDate = $_POST['report_date'] ?? date('Y-m-d');
$dayStart = $reportDate . ' 00:00:00';
$dayEnd = $reportDate . ' 23:59:59';

// Database connection is already established in config/database.php as $conn

// Calculations
$sqlAll = "SELECT COUNT(*) as total_all, COALESCE(SUM(amount),0) as gross_all FROM car_washes WHERE created_at >= ? AND created_at <= ?";
$stmtAll = $conn->prepare($sqlAll);
$stmtAll->bind_param('ss', $dayStart, $dayEnd);
$stmtAll->execute();
$resAll = $stmtAll->get_result();
$row = $resAll->fetch_assoc();
$total_all = (int)$row['total_all'];
$gross_all = (float)$row['gross_all'];

// Get counts and totals for motors (category_id = 2)
$sqlMotors = "SELECT COUNT(*) as motors_count, COALESCE(SUM(amount),0) as motors_gross 
              FROM car_washes 
              WHERE created_at >= ? AND created_at <= ? AND category_id = 2";
$stmtMot = $conn->prepare($sqlMotors);
$stmtMot->bind_param('ss', $dayStart, $dayEnd);
$stmtMot->execute();
$resMot = $stmtMot->get_result();
$m = $resMot->fetch_assoc();
$motors_count = (int)$m['motors_count'];
$motors_gross = (float)$m['motors_gross'];

// Get counts and totals for carpets (category_id = 3)
$sqlCarpets = "SELECT COUNT(*) as carpets_count, COALESCE(SUM(amount),0) as carpets_gross 
               FROM car_washes 
               WHERE created_at >= ? AND created_at <= ? AND category_id = 3";
$stmtCarp = $conn->prepare($sqlCarpets);
$stmtCarp->bind_param('ss', $dayStart, $dayEnd);
$stmtCarp->execute();
$resCarp = $stmtCarp->get_result();
$c = $resCarp->fetch_assoc();
$carpets_count = (int)$c['carpets_count'];
$carpets_gross = (float)$c['carpets_gross'];

$cars_count = $total_all - $motors_count - $carpets_count;
$cars_gross = $gross_all - $motors_gross - $carpets_gross;

// Get Dynamic Worker Commission for archiving
$worker_pct = 33.33; // Default
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        $worker_pct = (float)$row_set['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
$company_pct = 100 - $worker_pct;

$revenue_two_thirds_total = $gross_all * ($company_pct / 100);

// Database transaction
$conn->begin_transaction();
try {
    $sqlInsert = "INSERT INTO daily_reports (report_date, total_cars_washed, total_motors_washed, total_carpets_washed, gross_amount_total, revenue_two_thirds_total, created_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE total_cars_washed = VALUES(total_cars_washed), total_motors_washed = VALUES(total_motors_washed), total_carpets_washed = VALUES(total_carpets_washed), gross_amount_total = VALUES(gross_amount_total), revenue_two_thirds_total = VALUES(revenue_two_thirds_total), created_by = VALUES(created_by), submitted_at = COALESCE(submitted_at, NOW())";
    $stmtIns = $conn->prepare($sqlInsert);
    $stmtIns->bind_param('siiiddi', $reportDate, $cars_count, $motors_count, $carpets_count, $gross_all, $revenue_two_thirds_total, $_SESSION['user_id']);
    
    if (!$stmtIns->execute()) {
        throw new Exception('Failed to execute report submission: ' . $stmtIns->error);
    }
    
    $conn->commit();
    $successMsg = 'Daily report submitted successfully!';
    $_SESSION['flash_success'] = $successMsg;
    
    if ($isAjax) {
        sendJsonResponse([
            'success' => true,
            'message' => $successMsg,
            'redirect_url' => 'daily_report_preview.php?success=1'
        ]);
    }

} catch (Throwable $e) {
    $conn->rollback();
    $errorMsg = 'An error occurred while submitting the report. Please try again.';
    log_error('Error in report submission: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
    
    if ($isAjax) {
        sendJsonResponse([
            'error' => $errorMsg,
            'debug' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() : $e->getMessage()
        ], 500);
    }
    
    $_SESSION['flash_error'] = $errorMsg;
}

ob_end_clean();
// Only redirect if not an AJAX request
if (!$isAjax) {
    header('Location: daily_report_preview.php' . (isset($_GET['debug']) ? '?debug=1' : ''));
    exit();
}

?>