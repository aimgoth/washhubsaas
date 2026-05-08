<?php
ob_start();
require_once 'config/session.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/csrf.php';

// Detect AJAX intent: stay on same page and show success without navigation
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

// Only superadmin can close day
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>'Forbidden']); exit; }
    header('Location: login.php');
    exit();
}
// Must be POST with valid CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>'Invalid method']); exit; }
    header('Location: reports_super.php');
    exit();
}
csrf_validate_or_die();

require_once 'config/database.php';

$reportDate = date('Y-m-d');
$dayStart = $reportDate . ' 00:00:00';
$dayEnd = $reportDate . ' 23:59:59';

// Detect legacy column for schema compatibility
$hasServiceType = false;
try {
    $colRes = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'service_type'");
    if ($colRes && $colRes->num_rows > 0) { $hasServiceType = true; }
} catch (mysqli_sql_exception $e) { $hasServiceType = false; }
$svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";

// Ensure archive/closure table exists (idempotent)
$conn->query("CREATE TABLE IF NOT EXISTS day_closures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_date DATE NOT NULL UNIQUE,
  closed_by INT NULL,
  closed_at DATETIME NULL,
  INDEX (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Make sure daily_reports has submitted_at
@mysqli_report(MYSQLI_REPORT_OFF);
@$conn->query("ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL");

// Idempotency: if already closed, exit early
$alreadyClosed = false;
try {
    $stmtChk = $conn->prepare("SELECT 1 FROM day_closures WHERE report_date = ? AND closed_at IS NOT NULL LIMIT 1");
    $stmtChk->bind_param('s', $reportDate);
    $stmtChk->execute();
    $rs = $stmtChk->get_result();
    if ($rs && $rs->num_rows > 0) { $alreadyClosed = true; }
} catch (mysqli_sql_exception $e) { /* ignore */ }
if ($alreadyClosed) {
    $_SESSION['flash_day_closed'] = 1;
    ob_end_clean();
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>'End of day already confirmed','already'=>1]); exit; }
    header('Location: reports_super.php', true, 303);
    exit();
}

// Compute today's aggregates (same as submit_daily_report.php)
$sqlAll = "SELECT COUNT(*) as total_all, COALESCE(SUM(amount),0) as gross_all
           FROM car_washes WHERE created_at >= ? AND created_at <= ?";
$stmtAll = $conn->prepare($sqlAll);
$stmtAll->bind_param('ss', $dayStart, $dayEnd);
$stmtAll->execute();
$resAll = $stmtAll->get_result();
$total_all = 0; $gross_all = 0.0;
if ($row = $resAll->fetch_assoc()) { $total_all = (int)$row['total_all']; $gross_all = (float)$row['gross_all']; }

$sqlMotors = "SELECT COUNT(*) as motors_count, COALESCE(SUM(cw.amount),0) as motors_gross
              FROM car_washes cw
              LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
              LEFT JOIN services s ON cw.service_id = s.id
              WHERE cw.created_at >= ? AND cw.created_at <= ?
                AND (LOWER(COALESCE(cs.name, '')) LIKE '%motor%'
                     OR LOWER(" . $svcNameExpr . ") LIKE '%motor%')";
$stmtMot = $conn->prepare($sqlMotors);
$stmtMot->bind_param('ss', $dayStart, $dayEnd);
$stmtMot->execute();
$resMot = $stmtMot->get_result();
$motors_count = 0; $motors_gross = 0.0;
if ($m = $resMot->fetch_assoc()) { $motors_count = (int)$m['motors_count']; $motors_gross = (float)$m['motors_gross']; }

$sqlCarpets = "SELECT COUNT(*) as carpets_count, COALESCE(SUM(cw.amount),0) as carpets_gross
               FROM car_washes cw
               LEFT JOIN services s ON cw.service_id = s.id
               WHERE cw.created_at >= ? AND cw.created_at <= ?
                 AND LOWER(" . $svcNameExpr . ") LIKE '%carpet%'";
$stmtCarp = $conn->prepare($sqlCarpets);
$stmtCarp->bind_param('ss', $dayStart, $dayEnd);
$stmtCarp->execute();
$resCarp = $stmtCarp->get_result();
$carpets_count = 0; $carpets_gross = 0.0;
if ($c = $resCarp->fetch_assoc()) { $carpets_count = (int)$c['carpets_count']; $carpets_gross = (float)$c['carpets_gross']; }

$cars_count = max($total_all - $motors_count - $carpets_count, 0);

// Get Dynamic Worker Commission
$worker_pct = 33.33; // Default
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        $worker_pct = (float)$row_set['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
$company_pct = 100 - $worker_pct;

$revenue_two_thirds_total = $gross_all * ($company_pct / 100);

// Upsert into daily_reports as the archive record
$sqlInsert = "INSERT INTO daily_reports (
                  report_date,
                  total_cars_washed, total_motors_washed, total_carpets_washed,
                  gross_amount_total, revenue_two_thirds_total,
                  created_by
              ) VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
                  total_cars_washed = VALUES(total_cars_washed),
                  total_motors_washed = VALUES(total_motors_washed),
                  total_carpets_washed = VALUES(total_carpets_washed),
                  gross_amount_total = VALUES(gross_amount_total),
                  revenue_two_thirds_total = VALUES(revenue_two_thirds_total),
                  created_by = VALUES(created_by)";
// Transactionally upsert report and mark closure
$conn->begin_transaction();
try {
    $stmtIns = $conn->prepare($sqlInsert);
    $stmtIns->bind_param('siiiddi', $reportDate, $cars_count, $motors_count, $carpets_count, $gross_all, $revenue_two_thirds_total, $_SESSION['user_id']);
    $stmtIns->execute();

    $stmtClose = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at) VALUES (?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), closed_at = NOW()");
    $stmtClose->bind_param('si', $reportDate, $_SESSION['user_id']);
    $stmtClose->execute();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    ob_end_clean();
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>'Close failed']); exit; }
    header('Location: reports_super.php', true, 303);
    exit();
}

$_SESSION['flash_day_closed'] = 1;

ob_end_clean();
if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'message'=>'End of day confirmed']); exit; }
header('Location: reports_super.php', true, 303);
exit();
