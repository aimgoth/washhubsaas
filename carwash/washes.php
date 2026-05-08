<?php
require_once 'config/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// If the legacy/new button uses ?action=new, redirect to the form
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    header('Location: tasks_today.php');
    exit();
}

require_once 'config/database.php';

// Ensure $washes is defined before any potential use
$washes = [];

// Get Dynamic Worker Commission
$worker_pct = 33.33; // Default 1/3
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        $worker_pct = (float)$row_set['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
$company_pct = 100 - $worker_pct;

// Get Wash Bay Name — Priority: Session Brand > Master DB > Local Setting
$bayName = $_SESSION['TENANT_BRAND'] ?? "WashHub"; 

if ($bayName === "WashHub" || $bayName === "WashHub Client") {
    try {
        // 1. Try local tenant settings first (if user customized it)
        $res_bay = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bay_name' LIMIT 1");
        if ($res_bay && $row_bay = $res_bay->fetch_assoc()) {
            $localBay = $row_bay['setting_value'];
            if ($localBay !== "WashHub Client") {
                $bayName = $localBay;
            }
        }
        
        // 2. If still default, double check Master DB (fallback for first load)
        if ($bayName === "WashHub" || $bayName === "WashHub Client") {
            $masterDb = getenv('DB_NAME') ?: 'carwash_db';
            $currentDb = $conn->query("SELECT DATABASE()")->fetch_row()[0];
            $mConn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $masterDb, (int)DB_PORT);
            if ($mConn && !$mConn->connect_error) {
                $stmtM = $mConn->prepare("SELECT client_name, bay_name FROM tenants WHERE db_name = ? LIMIT 1");
                $stmtM->bind_param('s', $currentDb);
                $stmtM->execute();
                $resM = $stmtM->get_result();
                if ($rowM = $resM->fetch_assoc()) {
                    $cName = $rowM['client_name'];
                    $bName = $rowM['bay_name'];
                    $bayName = ($cName === $bName || $bName === '') ? $cName : "$cName — $bName";
                    $_SESSION['TENANT_BRAND'] = $bayName; // Cache it
                }
                $mConn->close();
            }
        }
    } catch (Exception $e) { /* use fallback */ }
}
$appName = getenv('APP_NAME') ?: 'WashHub';

// Ensure schema compatibility: add payment_confirmed column if missing
$__colChk = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'payment_confirmed'");
if ($__colChk && $__colChk->num_rows === 0) {
    @$conn->query("ALTER TABLE car_washes ADD COLUMN payment_confirmed TINYINT(1) NOT NULL DEFAULT 0");
}
if ($__colChk) { $__colChk->free(); }

// Detect if category_id column exists so we can include Category in listing
$hasCategory = false;
try {
    $__catCol = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'category_id'");
    if ($__catCol) { $hasCategory = ($__catCol->num_rows > 0); $__catCol->free(); }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Detect which date columns exist to build robust daily filters
$cwCols = [];
try {
    if ($__cols = $conn->query("SHOW COLUMNS FROM car_washes")) {
        while ($c = $__cols->fetch_assoc()) { $cwCols[strtolower($c['Field'])] = true; }
        $__cols->free();
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Detect optional tables/columns used by richer wash timeline queries.
$hasDelayReasonId = isset($cwCols['delay_reason_id']);
$hasDelayNotes    = isset($cwCols['delay_notes']);
$hasDelayReasonsTable = false;
$hasServiceDurationsTable = false;
try {
    $tbl = $conn->query("SHOW TABLES LIKE 'delay_reasons'");
    if ($tbl) { $hasDelayReasonsTable = ($tbl->num_rows > 0); $tbl->free(); }
} catch (mysqli_sql_exception $e) { /* ignore */ }
try {
    $tbl = $conn->query("SHOW TABLES LIKE 'service_durations'");
    if ($tbl) { $hasServiceDurationsTable = ($tbl->num_rows > 0); $tbl->free(); }
} catch (mysqli_sql_exception $e) { /* ignore */ }
$hasCreatedAt = isset($cwCols['created_at']);
$hasStartedAt = isset($cwCols['started_at']);
$hasCompletedAt = isset($cwCols['completed_at']);
$hasTimestamp = isset($cwCols['timestamp']);
$hasDateCol = isset($cwCols['date']);
$startedAtExpr = $hasStartedAt ? "cw.started_at" : "NULL";
$completedAtExpr = $hasCompletedAt ? "cw.completed_at" : "NULL";
$startRefExpr = $hasStartedAt ? "COALESCE(cw.started_at, cw.created_at)" : "cw.created_at";

// Handle search
$search = $_GET['search'] ?? '';
$worker_filter = $_GET['worker'] ?? '';
$allRequested = isset($_GET['all']) && $_GET['all'] == '1';

// Work hours window control (05:00 - 19:00 local time)
$today = date('Y-m-d');
$nowTs = time();
$startTs = strtotime($today . ' 05:00:00');
$endTs = strtotime($today . ' 19:00:00');
$inWorkWindow = ($nowTs >= $startTs && $nowTs < $endTs);

// Determine report submission/closure thresholds for filtering
$dayReported = false;
$reportSubmittedAtTs = null;
$dayClosed = false;
$closureAtTs = null;
try {
    $stmtRep = $conn->prepare("SELECT submitted_at FROM daily_reports WHERE report_date = CURDATE() LIMIT 1");
    $stmtRep->execute();
    $resRep = $stmtRep->get_result();
    if ($resRep && ($row = $resRep->fetch_assoc())) {
        $dayReported = true;
        if (!empty($row['submitted_at'])) {
            $reportSubmittedAtTs = strtotime($row['submitted_at']);
        }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }

try {
    $stmtClose = $conn->prepare("SELECT closed_at FROM day_closures WHERE report_date = CURDATE() ORDER BY closed_at DESC LIMIT 1");
    $stmtClose->execute();
    $resClose = $stmtClose->get_result();
    if ($resClose && ($rc = $resClose->fetch_assoc())) {
        if (!empty($rc['closed_at'])) {
            $dayClosed = true;
            $closureAtTs = strtotime($rc['closed_at']);
        }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }

$thresholdTs = null;
if ($_SESSION['role'] === 'superadmin') {
    if ($dayClosed && $closureAtTs) { $thresholdTs = $closureAtTs; }
} elseif ($_SESSION['role'] === 'admin') {
    $liveRequested = isset($_GET['live']) && $_GET['live'] == '1';
    if ($liveRequested && $dayReported && $reportSubmittedAtTs) {
        $thresholdTs = $reportSubmittedAtTs;
    } else if (!$allRequested) {
        $thresholdTs = null;
    }
}

// Base query with joins
$sql = "SELECT cw.*, w.full_name as worker_name, u2.full_name as admin_name, 
        s.name as service_name, cs.name as car_size_name, cat.name as category_name,
        $startedAtExpr as started_at, $completedAtExpr as completed_at,
        (SELECT wt1.planned_start
         FROM wash_tasks wt1
         WHERE wt1.worker_id = cw.worker_id
           AND DATE(wt1.planned_start) = DATE(cw.created_at)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt1.planned_start, $startRefExpr))
         LIMIT 1) as planned_start,
        (SELECT wt2.planned_end
         FROM wash_tasks wt2
         WHERE wt2.worker_id = cw.worker_id
           AND DATE(wt2.planned_start) = DATE(cw.created_at)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt2.planned_start, $startRefExpr))
         LIMIT 1) as planned_end,
        " . ($hasServiceDurationsTable ? "sd.duration_minutes" : "NULL") . " as service_duration,
        TIMESTAMPDIFF(MINUTE,
            (SELECT wt3.planned_start
             FROM wash_tasks wt3
             WHERE wt3.worker_id = cw.worker_id
               AND DATE(wt3.planned_start) = DATE(cw.created_at)
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt3.planned_start, $startRefExpr))
             LIMIT 1),
            (SELECT wt4.planned_end
             FROM wash_tasks wt4
             WHERE wt4.worker_id = cw.worker_id
               AND DATE(wt4.planned_start) = DATE(cw.created_at)
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt4.planned_start, $startRefExpr))
             LIMIT 1)
        ) as planned_duration,
        CASE 
            WHEN $completedAtExpr IS NOT NULL AND (
                (SELECT wt5.planned_end FROM wash_tasks wt5
                 WHERE wt5.worker_id = cw.worker_id
                   AND DATE(wt5.planned_start) = DATE(cw.created_at)
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt5.planned_start, $startRefExpr))
                 LIMIT 1)
            ) IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE,
                (SELECT wt6.planned_end FROM wash_tasks wt6
                 WHERE wt6.worker_id = cw.worker_id
                   AND DATE(wt6.planned_start) = DATE(cw.created_at)
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt6.planned_start, $startRefExpr))
                 LIMIT 1),
                $completedAtExpr)
            WHEN $completedAtExpr IS NULL AND (
                (SELECT wt7.planned_end FROM wash_tasks wt7
                 WHERE wt7.worker_id = cw.worker_id
                   AND DATE(wt7.planned_start) = DATE(cw.created_at)
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt7.planned_start, $startRefExpr))
                 LIMIT 1)
            ) IS NOT NULL AND (
                (SELECT wt8.planned_end FROM wash_tasks wt8
                 WHERE wt8.worker_id = cw.worker_id
                   AND DATE(wt8.planned_start) = DATE(cw.created_at)
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt8.planned_start, $startRefExpr))
                 LIMIT 1) < NOW()
            )
            THEN TIMESTAMPDIFF(MINUTE,
                (SELECT wt9.planned_end FROM wash_tasks wt9
                 WHERE wt9.worker_id = cw.worker_id
                   AND DATE(wt9.planned_start) = DATE(cw.created_at)
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, wt9.planned_start, $startRefExpr))
                 LIMIT 1),
                NOW())
            ELSE NULL 
        END as exceeded_minutes,
        $completedAtExpr as actual_end_time,
        " . (($hasDelayReasonId && $hasDelayReasonsTable) ? "dr.reason" : "NULL") . " as delay_reason,
        " . ($hasDelayNotes ? "cw.delay_notes" : "NULL") . " as delay_notes
        FROM car_washes cw 
        " . (($hasDelayReasonId && $hasDelayReasonsTable) ? "LEFT JOIN delay_reasons dr ON cw.delay_reason_id = dr.id" : "") . "
        LEFT JOIN workers w ON cw.worker_id = w.id 
        LEFT JOIN users u2 ON cw.admin_id = u2.id 
        LEFT JOIN services s ON cw.service_id = s.id 
        LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id 
        LEFT JOIN categories cat ON cw.category_id = cat.id
        " . ($hasServiceDurationsTable ? "LEFT JOIN service_durations sd ON cw.service_id = sd.service_id AND cw.car_size_id = sd.car_size_id" : "") . "
        WHERE 1=1";

$showTodaysData = empty($search) && empty($worker_filter) && !$allRequested && !isset($_GET['date']);
if ($showTodaysData) {
    $sql .= " AND DATE(cw.created_at) = CURDATE()";
}

$params = [];
$types = "";
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_duplicates'])) {
    try {
        $cleanup = $conn->query("
            DELETE cw1 FROM car_washes cw1
            INNER JOIN car_washes cw2 
            WHERE cw1.id > cw2.id 
            AND cw1.number_plate = cw2.number_plate 
            AND cw1.service_id = cw2.service_id 
            AND cw1.worker_id = cw2.worker_id 
            AND cw1.amount = cw2.amount 
            AND DATE(cw1.created_at) = DATE(cw2.created_at)
        ");
        $deletedCount = $conn->affected_rows;
        $success = "Removed $deletedCount duplicate records. Page will refresh to show clean data.";
        echo "<script>setTimeout(function(){ window.location.href = 'washes.php'; }, 2000);</script>";
    } catch (Exception $e) {
        $error = "Error cleaning duplicates: " . $e->getMessage();
    }
}

if (!empty($search)) {
    $sql .= " AND (cw.number_plate LIKE ? OR w.full_name LIKE ? OR u2.full_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

if (!empty($worker_filter)) {
    $sql .= " AND w.id = ?";
    $params[] = $worker_filter;
    $types .= "i";
}

if ($_SESSION['role'] === 'admin') {
    $mineOnly = isset($_GET['mine']) && $_GET['mine'] == '1';
    if ($mineOnly) {
        $sql .= " AND cw.admin_id = ?";
        $params[] = $_SESSION['user_id'];
        $types .= "i";
    }
}
elseif ($_SESSION['role'] === 'washer') {
    $sql .= " AND cw.worker_id = ?";
    $params[] = $_SESSION['user_id'];
    $types .= "i";
}

if (!is_null($thresholdTs) && !$allRequested) {
    $pendingThresholdCond = [date('Y-m-d H:i:s', $thresholdTs)];
} else {
    $pendingThresholdCond = null;
}

$specificDate = isset($_GET['date']) ? trim($_GET['date']) : '';
if (!$allRequested && $specificDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $specificDate)) {
    $startAt = $specificDate . ' 00:00:00';
    $endAt   = $specificDate . ' 23:59:59';
    $dateConds = [];
    if ($hasCreatedAt)   { $dateConds[] = "(cw.created_at BETWEEN ? AND ?)"; }
    if ($hasCompletedAt) { $dateConds[] = "(cw.completed_at BETWEEN ? AND ?)"; }
    if ($hasTimestamp)   { $dateConds[] = "(cw.timestamp BETWEEN ? AND ?)"; }
    if ($hasDateCol)     { $dateConds[] = "(cw.date BETWEEN ? AND ?)"; }
    if (!empty($dateConds)) {
        $sql .= " AND (" . implode(' OR ', $dateConds) . ")";
        $bindPairs = 2 * count($dateConds);
        $types .= str_repeat('s', $bindPairs);
        for ($i=0; $i<count($dateConds); $i++) { $params[] = $startAt; $params[] = $endAt; }
    }
} else if (!$allRequested) {
    if ($_SESSION['role'] === 'admin' && $dayReported) {
        $sql .= " AND DATE(cw.created_at) != CURDATE()";
    } else {
        $sql .= " AND DATE(cw.created_at) = CURDATE()";
    }
}

$orderByDateExpr = 'cw.created_at';
if (!$hasCreatedAt && $hasCompletedAt) { $orderByDateExpr = 'cw.completed_at'; }
elseif (!$hasCreatedAt && !$hasCompletedAt && $hasTimestamp) { $orderByDateExpr = 'cw.timestamp'; }
elseif (!$hasCreatedAt && !$hasCompletedAt && !$hasTimestamp && $hasDateCol) { $orderByDateExpr = 'cw.date'; }
$sql .= " ORDER BY $orderByDateExpr DESC LIMIT 1000";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$washes = [];
$workerSummary = null;
$countCars = 0; $countCarpets = 0; $countMotors = 0;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $washes[] = $row;
        $catName = strtolower(trim($row['category_name'] ?? ''));
        if ($catName === '') {
            $sn = strtolower($row['service_name'] ?? $row['service_type'] ?? '');
            if (strpos($sn, 'carpet') !== false) { $catName = 'carpets'; }
            elseif (strpos($sn, 'motor') !== false || strpos($sn, 'bike') !== false) { $catName = 'motors'; }
            else { $catName = 'cars'; }
        }
        if ($catName === 'carpets' || $catName === 'carpet') { $countCarpets++; }
        elseif ($catName === 'motors' || $catName === 'motor' || $catName === 'bikes' || $catName === 'bike') { $countMotors++; }
        else { $countCars++; }
    }
    if (!empty($worker_filter)) {
        $totalAmount = 0.0;
        $cars = 0; $carpets = 0; $motors = 0;
        foreach ($washes as $row) {
            $catName = strtolower(trim($row['category_name'] ?? ''));
            if ($catName === '') {
                $sn = strtolower($row['service_name'] ?? $row['service_type'] ?? '');
                if (strpos($sn, 'carpet') !== false) { $catName = 'carpets'; }
                elseif (strpos($sn, 'motor') !== false || strpos($sn, 'bike') !== false) { $catName = 'motors'; }
                else { $catName = 'cars'; }
            }
            if ($catName === 'carpets' || $catName === 'carpet') { $carpets++; }
            elseif ($catName === 'motors' || $catName === 'motor' || $catName === 'bikes' || $catName === 'bike') { $motors++; }
            else { $cars++; }
            $totalAmount += (float)$row['amount'];
        }
        $workerSummary = [
            'cars' => $cars,
            'carpets' => $carpets,
            'motors' => $motors,
            'total' => $totalAmount,
            'company_share' => $totalAmount * ($company_pct / 100),
            'worker_share' => $totalAmount * ($worker_pct / 100),
        ];
    }
}

$page_title = 'Car Wash Records';
include 'includes/header.php';
?>

<style>
    .ws-page { max-width: 1400px; margin: 36px auto; padding: 0 20px 60px; }

    .ws-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .ws-title-left { display: flex; align-items: center; gap: 14px; }
    .ws-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .ws-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .ws-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .ws-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 9px 18px; border-radius: 10px; font-weight: 700; font-size: 0.92rem;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff;
        text-decoration: none; border: none; cursor: pointer; transition: all .2s;
    }
    .ws-btn:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
    .ws-btn-outline { background: #fff; color: #1B3FA0; border: 1.5px solid #1B3FA0; box-shadow: none; }
    .ws-btn-outline:hover { background: #1B3FA0; color: #fff; }

    .ws-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 16px rgba(0,0,0,0.07); margin-bottom: 24px; overflow: hidden; }
    .ws-card-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .ws-card-header h2 { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 9px; }
    .ws-card-header h2 i { color: #00AEEF; }

    .ws-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 0; }
    .ws-input { padding: 8px 14px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; font-weight: 600; color: #1e293b; outline: none; transition: border .2s; background: #fff; }
    .ws-input:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.1); }

    /* Summary Grid */
    .ws-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; padding: 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .ws-stat { background: #fff; border-radius: 12px; padding: 18px; border: 1px solid #e2e8f0; border-left: 4px solid #1B3FA0; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
    .ws-stat-lbl { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display:flex; align-items:center; gap:6px; }
    .ws-stat-val { font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2; }
    
    /* Table */
    .ws-table-wrap { overflow-x: auto; }
    .ws-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .ws-table thead tr { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .ws-table th { padding: 14px 16px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; text-align: left; color: #475569; white-space: nowrap; }
    .ws-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; white-space: nowrap; }
    .ws-table tbody tr:hover td { background: #f8faff; }
    .ws-table tbody tr:last-child td { border-bottom: none; }
    
    .ws-amt { font-weight: 800; color: #059669; }
    .ws-plate-tag { background: #f1f5f9; color: #1e293b; font-family: 'JetBrains Mono', monospace; font-weight: 800; padding: 4px 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; letter-spacing: 0.5px; display: inline-block; }
    
    /* Premium Status Pills */
    .ws-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 99px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .ws-status-ontime { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
    .ws-status-late { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; }
    .ws-status-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

    .ws-info-secondary { font-size: 0.72rem; color: #94a3b8; font-weight: 600; margin-top: 2px; display: block; }
    .ws-staff-info { display: flex; align-items: center; gap: 8px; }
    .ws-staff-icon { width: 24px; height: 24px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #64748b; }

    .ws-totals { display: flex; gap: 10px; flex-wrap: wrap; padding: 20px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; align-items: center; }
    .ws-total-badge { padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; gap: 6px; background: #fff; border: 1.5px solid #e2e8f0; color: #1e293b; }
</style>

<div class="ws-page">
    
    <div class="ws-title">
        <div class="ws-title-left">
            <div class="ws-title-icon"><i class="fas fa-clipboard-check"></i></div>
            <div>
                <h1>Car Wash Records</h1>
                <p>Complete historical log of all services rendered.</p>
            </div>
        </div>
        <?php if (!empty($specificDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $specificDate)): ?>
        <div style="display:flex; gap:10px;">
            <a class="ws-btn ws-btn-outline" href="daily_reports_archive.php"><i class="fas fa-arrow-left"></i> Archive</a>
            <a class="ws-btn ws-btn-outline" href="washes.php"><i class="fas fa-sync"></i> Today</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="ws-card">
        <div class="ws-card-header">
            <h2><i class="fas fa-filter"></i> Search & Filter</h2>
            <form method="GET" class="ws-form">
                <input type="date" name="date" class="ws-input" value="<?php echo isset($specificDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $specificDate) ? htmlspecialchars($specificDate) : ''; ?>">
                <input type="text" name="search" class="ws-input" placeholder="Search plate, worker..." value="<?php echo htmlspecialchars($search); ?>" style="width: 220px;">
                
                <select name="worker" class="ws-input">
                    <option value="">All Workers</option>
                    <?php
                    $worker_result = $conn->query("SELECT id, full_name FROM workers WHERE status = 'active' UNION SELECT id, full_name FROM users WHERE role = 'washer' ORDER BY full_name");
                    while ($worker = $worker_result->fetch_assoc()):
                    ?>
                        <option value="<?php echo $worker['id']; ?>" <?php echo $worker_filter == $worker['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($worker['full_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                
                <button type="submit" class="ws-btn" style="padding: 9px 14px;"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search) || !empty($worker_filter)): ?>
                    <a href="washes.php" class="ws-btn ws-btn-outline" style="padding: 9px 14px;"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if ($workerSummary): ?>
            <div class="ws-grid">
                <div class="ws-stat" style="border-left-color: #00AEEF;">
                    <div class="ws-stat-lbl"><i class="fas fa-car"></i> Total Cars</div>
                    <div class="ws-stat-val"><?php echo number_format($workerSummary['cars']); ?></div>
                </div>
                <div class="ws-stat" style="border-left-color: #8b5cf6;">
                    <div class="ws-stat-lbl"><i class="fas fa-broom"></i> Total Carpets</div>
                    <div class="ws-stat-val"><?php echo number_format($workerSummary['carpets']); ?></div>
                </div>
                <div class="ws-stat" style="border-left-color: #f59e0b;">
                    <div class="ws-stat-lbl"><i class="fas fa-motorcycle"></i> Total Motors</div>
                    <div class="ws-stat-val"><?php echo number_format($workerSummary['motors']); ?></div>
                </div>
                <div class="ws-stat" style="border-left-color: #10b981;">
                    <div class="ws-stat-lbl"><i class="fas fa-calculator"></i> Total Amount</div>
                    <div class="ws-stat-val" style="color: #059669;">GHS <?php echo number_format($workerSummary['total'], 2); ?></div>
                </div>
                <div class="ws-stat" style="border-left-color: #1B3FA0;">
                    <div class="ws-stat-lbl"><i class="fas fa-user"></i> Worker Share (<?php echo round($worker_pct, 1); ?>%)</div>
                    <div class="ws-stat-val">GHS <?php echo number_format($workerSummary['worker_share'], 2); ?></div>
                </div>
            </div>
            <div style="text-align:center; color:#94a3b8; font-size:0.8rem; padding: 10px; background: #fff;">* Once payment is confirmed, this worker's details disappear from this view.</div>
        <?php endif; ?>

        <?php if (!empty($washes)): ?>
            <div class="ws-table-wrap">
                <table class="ws-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Service Details</th>
                            <th>Size</th>
                            <th>Plate</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Washer</th>
                            <th>Admin & Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($washes as $wash): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; color: #1e293b;"><?php echo date('M j, Y', strtotime($wash['created_at'])); ?></div>
                                    <span class="ws-info-secondary"><?php echo date('g:i A', strtotime($wash['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 800; color: #1e293b; font-size: 0.9rem;"><?php echo htmlspecialchars($wash['service_name'] ?? $wash['service_type'] ?? 'N/A'); ?></div>
                                    <?php if ($hasCategory): ?>
                                        <span class="ws-info-secondary">
                                            <?php 
                                                $cat = $wash['category_name'] ?? '';
                                                if ($cat === '' || $cat === null) {
                                                    $sn = strtolower($wash['service_name'] ?? $wash['service_type'] ?? '');
                                                    $cat = (strpos($sn, 'carpet') !== false) ? 'Carpets' : ((strpos($sn, 'motor') !== false || strpos($sn, 'bike') !== false) ? 'Motors' : 'Cars');
                                                }
                                                echo htmlspecialchars($cat);
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: #64748b; font-size: 0.8rem; text-transform: uppercase;"><?php echo htmlspecialchars($wash['car_size_name'] ?? $wash['car_size'] ?? 'Regular'); ?></span>
                                </td>
                                <td><span class="ws-plate-tag"><?php echo htmlspecialchars($wash['number_plate'] ?? 'N/A'); ?></span></td>
                                <td style="text-align:right;" class="ws-amt">GHS <?php echo number_format($wash['amount'], 2); ?></td>
                                <td>
                                    <div class="ws-staff-info">
                                        <div class="ws-staff-icon"><i class="fas fa-user"></i></div>
                                        <span style="font-weight: 700; color: #334155;"><?php echo htmlspecialchars($wash['worker_name'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: #1e293b; display: block;"><?php echo htmlspecialchars($wash['admin_name'] ?? 'System'); ?></span>
                                    <span class="ws-info-secondary"><?php echo strtoupper(substr($appName, 0, 4)) . '-' . str_pad((string)($wash['id'] ?? 0), 6, '0', STR_PAD_LEFT); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                <?php
                    $amountCars = 0.0; $amountCarpets = 0.0; $amountMotors = 0.0; $amountGross = 0.0;
                    foreach ($washes as $row) {
                        $catName = strtolower(trim($row['category_name'] ?? ''));
                        if ($catName === '') {
                            $sn = strtolower($row['service_name'] ?? $row['service_type'] ?? '');
                            if (strpos($sn, 'carpet') !== false) { $catName = 'carpets'; }
                            elseif (strpos($sn, 'motor') !== false || strpos($sn, 'bike') !== false) { $catName = 'motors'; }
                            else { $catName = 'cars'; }
                        }
                        $amount = (float)($row['amount'] ?? 0);
                        if ($catName === 'carpets' || $catName === 'carpet') { $amountCarpets += $amount; }
                        elseif ($catName === 'motors' || $catName === 'motor' || $catName === 'bikes' || $catName === 'bike') { $amountMotors += $amount; }
                        else { $amountCars += $amount; }
                        $amountGross += $amount;
                    }
                ?>
                <div class="ws-totals">
                    <div style="font-weight:800; color:#475569; margin-right: 10px;">TOTALS:</div>
                    <div class="ws-total-badge" style="color:#00AEEF; border-color:#bae6fd;"><i class="fas fa-car"></i> Cars: <?php echo number_format($countCars); ?> (GHS <?php echo number_format($amountCars, 2); ?>)</div>
                    <div class="ws-total-badge" style="color:#8b5cf6; border-color:#ddd6fe;"><i class="fas fa-broom"></i> Carpets: <?php echo number_format($countCarpets); ?> (GHS <?php echo number_format($amountCarpets, 2); ?>)</div>
                    <div class="ws-total-badge" style="color:#f59e0b; border-color:#fef3c7;"><i class="fas fa-motorcycle"></i> Motors: <?php echo number_format($countMotors); ?> (GHS <?php echo number_format($amountMotors, 2); ?>)</div>
                    <div class="ws-total-badge" style="color:#10b981; border-color:#a7f3d0;"><i class="fas fa-coins"></i> Gross: GHS <?php echo number_format($amountGross, 2); ?></div>
                    <div class="ws-total-badge" style="color:#1B3FA0; border-color:#bfdbfe;"><i class="fas fa-building"></i> Revenue (<?php echo round($company_pct, 1); ?>%): GHS <?php echo number_format($amountGross * ($company_pct / 100), 2); ?></div>
                </div>
                
                <div style="padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; background: #fff;">
                    <button class="ws-btn ws-btn-outline" onclick="window.print();"><i class="fas fa-print"></i> Print</button>
                    <button class="ws-btn" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                </div>

                <!-- PDF Export Scripts -->
                <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
                <script>
                document.getElementById('exportPdfBtn').addEventListener('click', async function() {
                    const btn = this;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
                    btn.disabled = true;

                    const { jsPDF } = window.jspdf;
                    
                    // 1. Gather all data
                    const bayName = <?php echo json_encode($bayName); ?>;
                    const appName = <?php echo json_encode($appName); ?>;
                    const filterDate = "<?php echo date('F j, Y', strtotime($today)); ?>";
                    
                    const countCars = "<?php echo $countCars; ?>";
                    const amtCars = "<?php echo number_format($amountCars, 2); ?>";
                    const countCarpets = "<?php echo $countCarpets; ?>";
                    const amtCarpets = "<?php echo number_format($amountCarpets, 2); ?>";
                    const countMotors = "<?php echo $countMotors; ?>";
                    const amtMotors = "<?php echo number_format($amountMotors, 2); ?>";
                    const amtGross = "<?php echo number_format($amountGross, 2); ?>";
                    const revenue = "<?php echo number_format($amountGross * ($company_pct / 100), 2); ?>";
                    const companyPct = "<?php echo round($company_pct, 1); ?>";
                    const activeWorkers = <?php echo json_encode($active_workers_list); ?>;

                    // 1.1 Calculate Rankings from DOM
                    const stats = {};
                    Array.from(document.querySelectorAll('.ws-table tbody tr')).forEach(tr => {
                        const cells = tr.querySelectorAll('td');
                        if (cells.length < 6) return;
                        const name = cells[5].innerText.trim();
                        const amount = parseFloat(cells[4].innerText.replace('GHS', '').trim()) || 0;
                        if (!stats[name]) stats[name] = { name, count: 0, revenue: 0 };
                        stats[name].count++;
                        stats[name].revenue += amount;
                    });

                    const sorted = Object.values(stats).sort((a, b) => b.revenue - a.revenue || b.count - a.count);
                    const top = sorted[0] || null;
                    const mid = sorted.length > 2 ? sorted[Math.floor(sorted.length / 2)] : (sorted.length > 1 ? sorted[1] : null);
                    const low = sorted.length > 1 ? sorted[sorted.length - 1] : null;

                    const workedNames = new Set(Object.keys(stats));
                    const inactive = activeWorkers.filter(name => !workedNames.has(name));

                    // Create the capture container
                    const container = document.createElement('div');
                    container.style.position = 'absolute';
                    container.style.left = '-9999px';
                    container.style.width = '800px';
                    container.style.background = '#fff';
                    container.style.padding = '40px';
                    container.style.fontFamily = "'Inter', sans-serif";
                    document.body.appendChild(container);

                    // Build the premium HTML
                    container.innerHTML = `
                        <div style="border-bottom: 2.5px solid #1B3FA0; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <img src="../frontend/new logo.png" style="height: 50px;">
                                <div>
                                    <div style="font-size: 22px; font-weight: 800; color: #1B3FA0; line-height: 1.2;">${appName}</div>
                                    <div style="font-size: 11px; color: #00AEEF; font-weight: 700; text-transform: uppercase;">Washing Bay Management Software</div>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 20px; font-weight: 900; color: #000; text-transform: uppercase;">${bayName}</div>
                                <div style="font-size: 12px; color: #64748b; font-weight: 700; text-transform: uppercase;">Car Wash Records Export</div>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; background: #f8fafc; padding: 15px 25px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
                            <div>
                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Records Date</div>
                                <div style="font-size: 16px; font-weight: 700; color: #1B3FA0;">${filterDate}</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Status</div>
                                <div style="font-size: 16px; font-weight: 700; color: #059669;">Verified Log</div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Volume</div>
                                <div style="font-size: 16px; font-weight: 700; color: #1B3FA0;">${parseInt(countCars) + parseInt(countCarpets) + parseInt(countMotors)} Washes</div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
                            <div style="padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Cars</div>
                                <div style="font-size: 18px; font-weight: 800; color: #00AEEF;">${countCars}</div>
                            </div>
                            <div style="padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Gross Total</div>
                                <div style="font-size: 18px; font-weight: 800; color: #1B3FA0;">GHS ${amtGross}</div>
                            </div>
                            <div style="padding: 15px; background: #065f46; border-radius: 12px; text-align: center; color: #fff;">
                                <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; opacity: 0.8; margin-bottom: 5px;">Revenue (${companyPct}%)</div>
                                <div style="font-size: 18px; font-weight: 800;">GHS ${revenue}</div>
                            </div>
                            <div style="padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Other Items</div>
                                <div style="font-size: 18px; font-weight: 800; color: #8b5cf6;">${parseInt(countCarpets) + parseInt(countMotors)}</div>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px; font-size: 16px; font-weight: 800; color: #1B3FA0; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">Performance Ranking</div>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px;">
                            <div style="padding: 15px; border-radius: 12px; border: 1.5px solid #059669; background: #ecfdf5; text-align: center;">
                                <div style="font-size: 10px; font-weight: 800; color: #059669; text-transform: uppercase;">Top Performer</div>
                                <div style="font-size: 16px; font-weight: 800; color: #064e3b; margin: 4px 0;">${top ? top.name : 'N/A'}</div>
                                <div style="font-size: 11px; color: #059669;">${top ? top.count + ' washes | GHS ' + top.revenue.toFixed(2) : '-'}</div>
                            </div>
                            <div style="padding: 15px; border-radius: 12px; border: 1.5px solid #1B3FA0; background: #eff6ff; text-align: center;">
                                <div style="font-size: 10px; font-weight: 800; color: #1B3FA0; text-transform: uppercase;">Mid Performer</div>
                                <div style="font-size: 16px; font-weight: 800; color: #172554; margin: 4px 0;">${mid ? mid.name : 'N/A'}</div>
                                <div style="font-size: 11px; color: #1B3FA0;">${mid ? mid.count + ' washes | GHS ' + mid.revenue.toFixed(2) : '-'}</div>
                            </div>
                            <div style="padding: 15px; border-radius: 12px; border: 1.5px solid #eab308; background: #fefce8; text-align: center;">
                                <div style="font-size: 10px; font-weight: 800; color: #854d0e; text-transform: uppercase;">Emerging</div>
                                <div style="font-size: 16px; font-weight: 800; color: #713f12; margin: 4px 0;">${low && low !== mid ? low.name : 'None'}</div>
                                <div style="font-size: 11px; color: #854d0e;">${low && low !== mid ? low.count + ' washes | GHS ' + low.revenue.toFixed(2) : '-'}</div>
                            </div>
                        </div>

                        ${inactive.length > 0 ? `
                            <div style="margin-bottom: 25px; padding: 12px 20px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 12px;">
                                <strong style="color: #64748b; text-transform: uppercase;">Inactive Workers:</strong> 
                                <span style="color: #475569; margin-left: 10px;">${inactive.join(', ')}</span>
                            </div>
                        ` : ''}

                        <div style="margin-bottom: 20px; font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 10px;">Detailed Historical Log</div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #1B3FA0;">
                                    <th style="padding: 12px; text-align: left;">Date/Time</th>
                                    <th style="padding: 12px; text-align: left;">Service</th>
                                    <th style="padding: 12px; text-align: left;">Plate</th>
                                    <th style="padding: 12px; text-align: right;">Amount</th>
                                    <th style="padding: 12px; text-align: left;">Washer</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${Array.from(document.querySelectorAll('.ws-table tbody tr')).map(tr => {
                                    const cells = tr.querySelectorAll('td');
                                    return `
                                        <tr style="border-bottom: 1px solid #f1f5f9;">
                                            <td style="padding: 10px;">${cells[0].innerText}</td>
                                            <td style="padding: 10px; font-weight: 700;">${cells[1].innerText}</td>
                                            <td style="padding: 10px;">${cells[3].innerText}</td>
                                            <td style="padding: 10px; text-align: right; font-weight: 700; color: #1B3FA0;">${cells[4].innerText}</td>
                                            <td style="padding: 10px;">${cells[5].innerText}</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>

                        <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 10px;">
                            <p><strong>${appName} SaaS Ecosystem</strong> — Intelligence in Motion</p>
                            <p>Generated on ${new Date().toLocaleString()}. All data securely verified.</p>
                        </div>
                    `;

                    try {
                        const canvas = await html2canvas(container, {
                            scale: 3,
                            useCORS: true,
                            backgroundColor: '#ffffff'
                        });

                        const imgData = canvas.toDataURL('image/png', 1.0);
                        const pdf = new jsPDF('p', 'mm', 'a4');
                        const pdfWidth = pdf.internal.pageSize.getWidth();
                        const margin = 10;
                        const contentWidth = pdfWidth - (2 * margin);
                        const imgProps = pdf.getImageProperties(imgData);
                        const contentHeight = (imgProps.height * contentWidth) / imgProps.width;

                        pdf.addImage(imgData, 'PNG', margin, margin, contentWidth, contentHeight);
                        pdf.save(`CarWash_Records_${new Date().toISOString().split('T')[0]}.pdf`);
                    } catch (error) {
                        console.error('PDF Export Error:', error);
                        alert('Could not generate PDF. Please try again.');
                    } finally {
                        document.body.removeChild(container);
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                });
                </script>
            <?php else: ?>
                <div class="ws-totals">
                    <div style="font-weight:800; color:#475569; margin-right: 10px;">TOTALS:</div>
                    <div class="ws-total-badge" style="color:#00AEEF; border-color:#bae6fd;"><i class="fas fa-car"></i> Cars: <?php echo number_format($countCars); ?></div>
                    <div class="ws-total-badge" style="color:#8b5cf6; border-color:#ddd6fe;"><i class="fas fa-broom"></i> Carpets: <?php echo number_format($countCarpets); ?></div>
                    <div class="ws-total-badge" style="color:#f59e0b; border-color:#fef3c7;"><i class="fas fa-motorcycle"></i> Motors: <?php echo number_format($countMotors); ?></div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div style="padding: 60px 20px; text-align: center; color: #94a3b8;">
                <i class="fas fa-clipboard-list" style="font-size:3rem; margin-bottom:16px; opacity:0.5; display:block;"></i>
                <h3 style="margin:0 0 8px; color:#475569;">No Records Found</h3>
                <p style="margin:0 0 20px;">No car wash records match your current filters.</p>
                <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'washer'): ?>
                    <a href="tasks_today.php" class="ws-btn"><i class="fas fa-list-check"></i> View Open Tasks</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>