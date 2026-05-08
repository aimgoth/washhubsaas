<?php
// Auto close (confirm) today's daily report at or after 22:00
// Usage:
// - CLI (preferred): php auto_close_day.php
// - HTTP (fallback): /carwash/auto_close_day.php?secret=DEV_SECRET
//   Requires DEV_SECRET from config/dev.php and is intended for protected environments only.

ob_start();
@ini_set('display_errors', 0);

// Use Africa/Lagos so 23:00 aligns with local time
date_default_timezone_set('Africa/Lagos');

$now = new DateTime('now');

require_once __DIR__ . '/config/database.php';

// Allow CLI always; for HTTP require secret gate
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/config/dev.php';
    $provided = $_GET['secret'] ?? '';
    if (!hash_equals(DEV_SECRET, (string)$provided)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

// Support a TEMPORARY force flag for testing.
// CLI: php auto_close_day.php --force
// HTTP: auto_close_day.php?secret=...&force=1
$isForced = false;
if ($isCli) {
    $isForced = in_array('--force', $argv ?? [], true);
} else {
    $isForced = isset($_GET['force']) && $_GET['force'] == '1';
}

// TEMP TEST: Only act at/after 23:17 server local time (Africa/Lagos)
// NOTE: revert minute threshold back to 00 after testing.
$hour = (int)$now->format('G');
$minute = (int)$now->format('i');
if (!$isForced && ($hour < 23 || ($hour === 23 && $minute < 17))) {
    if ($isCli) {
        echo "Skipping: current time {$hour}:" . str_pad((string)$minute, 2, '0', STR_PAD_LEFT) . " < 23:17\n";
    } else {
        echo 'Skipped (before 23:17).';
    }
    exit;
}

if ($isForced) {
    if ($isCli) {
        echo "Force mode enabled: bypassing time check\n";
    } else {
        echo "Force mode enabled: bypassing time check.\n";
    }
}

$reportDate = $now->format('Y-m-d');
$dayStart = $reportDate . ' 00:00:00';
$dayEnd = $reportDate . ' 23:59:59';

// Ensure closures table exists and check status
try {
    $conn->query("CREATE TABLE IF NOT EXISTS day_closures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL UNIQUE,
        closed_by INT NULL,
        closed_at DATETIME NULL,
        INDEX (report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $conn->prepare("SELECT 1 FROM day_closures WHERE report_date = CURDATE() AND closed_at IS NOT NULL LIMIT 1");
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        echo $isCli ? "Already closed for {$reportDate}\n" : 'Already closed.';
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error preparing closure check';
    exit;
}

// Detect legacy service_type column
$hasServiceType = false;
try {
    $colRes = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'service_type'");
    if ($colRes && $colRes->num_rows > 0) { $hasServiceType = true; }
} catch (Throwable $e) { $hasServiceType = false; }
$svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";

// Compute totals for the day (same logic as manual submission)
$total_all = 0; $gross_all = 0.0;
try {
    $sqlAll = "SELECT COUNT(*) as total_all, COALESCE(SUM(amount),0) as gross_all FROM car_washes WHERE created_at >= ? AND created_at <= ?";
    $stmtAll = $conn->prepare($sqlAll);
    $stmtAll->bind_param('ss', $dayStart, $dayEnd);
    $stmtAll->execute();
    $resAll = $stmtAll->get_result();
    if ($row = $resAll->fetch_assoc()) { $total_all = (int)$row['total_all']; $gross_all = (float)$row['gross_all']; }
} catch (Throwable $e) {}

$motors_count = 0; $motors_gross = 0.0;
try {
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
    if ($m = $resMot->fetch_assoc()) { $motors_count = (int)$m['motors_count']; $motors_gross = (float)$m['motors_gross']; }
} catch (Throwable $e) {}

$carpets_count = 0; $carpets_gross = 0.0;
try {
    $sqlCarpets = "SELECT COUNT(*) as carpets_count, COALESCE(SUM(cw.amount),0) as carpets_gross
                   FROM car_washes cw
                   LEFT JOIN services s ON cw.service_id = s.id
                   WHERE cw.created_at >= ? AND cw.created_at <= ?
                     AND LOWER(" . $svcNameExpr . ") LIKE '%carpet%'";
    $stmtCarp = $conn->prepare($sqlCarpets);
    $stmtCarp->bind_param('ss', $dayStart, $dayEnd);
    $stmtCarp->execute();
    $resCarp = $stmtCarp->get_result();
    if ($c = $resCarp->fetch_assoc()) { $carpets_count = (int)$c['carpets_count']; $carpets_gross = (float)$c['carpets_gross']; }
} catch (Throwable $e) {}

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

// Ensure submitted_at column exists
@mysqli_report(MYSQLI_REPORT_OFF);
@$conn->query("ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL");

// Insert/Upsert the daily_reports row and then mark closure
$conn->begin_transaction();
try {
    $sqlInsert = "INSERT INTO daily_reports (
                      report_date,
                      total_cars_washed, total_motors_washed, total_carpets_washed,
                      gross_amount_total, revenue_two_thirds_total,
                      created_by
                  ) VALUES (?, ?, ?, ?, ?, ?, NULL)
                  ON DUPLICATE KEY UPDATE
                      total_cars_washed = VALUES(total_cars_washed),
                      total_motors_washed = VALUES(total_motors_washed),
                      total_carpets_washed = VALUES(total_carpets_washed),
                      gross_amount_total = VALUES(gross_amount_total),
                      revenue_two_thirds_total = VALUES(revenue_two_thirds_total)";
    $stmtIns = $conn->prepare($sqlInsert);
    $stmtIns->bind_param('siiidd', $reportDate, $cars_count, $motors_count, $carpets_count, $gross_all, $revenue_two_thirds_total);
    $stmtIns->execute();

    // Upsert closure row
    $conn->query("INSERT INTO day_closures (report_date, closed_by, closed_at)
                  VALUES (CURDATE(), NULL, NOW())
                  ON DUPLICATE KEY UPDATE closed_at = IFNULL(closed_at, NOW())");

    $conn->commit();
    echo $isCli ? "Auto-closed day {$reportDate}\n" : 'Auto-closed.';
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo $isCli ? ("Failed: " . $e->getMessage() . "\n") : 'Failed.';
}
