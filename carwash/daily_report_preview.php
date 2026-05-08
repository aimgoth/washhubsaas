<?php
// Preview today's daily report before submission
ob_start();

// Enable error logging
if (!function_exists('log_error')) {
    function log_error($message) {
        $log_file = __DIR__ . '/../logs/daily_report_errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message\n";
        error_log($log_message, 3, $log_file);
    }
}

require_once 'config/session.php';
if (session_status() === PHP_SESSION_NONE) {
    }
require_once __DIR__ . '/includes/csrf.php';

// Only admin can access preview (as per requirement)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Get Wash Bay Name
$bayName = "WashHub"; 
try {
    // 1. Try local tenant settings first (if user customized it)
    $res_bay = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bay_name' LIMIT 1");
    if ($res_bay && $row_bay = $res_bay->fetch_assoc()) {
        $bayName = $row_bay['setting_value'];
    } else {
        // 2. Fetch from Master DB based on current DB name
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
                if ($cName === $bName) {
                    $bayName = $cName;
                } else {
                    $bayName = "$cName — $bName";
                }
            }
            $mConn->close();
        }
    }
} catch (Exception $e) { /* use fallback */ }

$reportDate = date('Y-m-d');
$dayStart = $reportDate . ' 00:00:00';
$dayEnd = $reportDate . ' 23:59:59';

// Detect legacy columns to build SQL safely across schemas
$hasServiceType = false;
try {
    $colRes = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'service_type'");
    if ($colRes && $colRes->num_rows > 0) { $hasServiceType = true; }
} catch (mysqli_sql_exception $e) { $hasServiceType = false; }
// Expression for service name matching
$svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";

// Detect category_id safely
$hasCategoryId = false;
try {
    $colRes = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'category_id'");
    if ($colRes && $colRes->num_rows > 0) { $hasCategoryId = true; }
} catch (mysqli_sql_exception $e) { $hasCategoryId = false; }

// Determine if already submitted
$dayReported = false;
try {
    // Check if we're coming back from a successful submission
    if (isset($_GET['success']) && $_GET['success'] == '1') {
        $dayReported = true;
        // Also update the session to remember this
        $_SESSION['last_report_date'] = date('Y-m-d');
    } 
    // Check if we've already reported today
    elseif (isset($_SESSION['last_report_date']) && $_SESSION['last_report_date'] === date('Y-m-d')) {
        $dayReported = true;
    }
    // Check the database
    else {
        $stmtRep = $conn->prepare("SELECT report_date FROM daily_reports WHERE report_date = CURDATE() LIMIT 1");
        $stmtRep->execute();
        $resRep = $stmtRep->get_result();
        if ($resRep && $row = $resRep->fetch_assoc()) {
            $dayReported = true;
            // Store in session for future checks
            $_SESSION['last_report_date'] = $row['report_date'];
        }
    }
} catch (mysqli_sql_exception $e) { 
    // Log the error but don't block the user
    log_error('Error checking report status: ' . $e->getMessage());
}

// Determine if there are any unpaid worker payments (today) for this admin
$unpaid_count = 0;
$unpaid_workers = [];
try {
    $stmtUnp = $conn->prepare("SELECT COUNT(*) AS cnt
                               FROM car_washes
                               WHERE DATE(created_at) = CURDATE()
                                 AND (payment_confirmed IS NULL OR payment_confirmed = 0)
                                 AND admin_id = ?");
    $stmtUnp->bind_param('i', $_SESSION['user_id']);
    $stmtUnp->execute();
    $resUnp = $stmtUnp->get_result();
    if ($resUnp && ($ru = $resUnp->fetch_assoc())) { $unpaid_count = (int)$ru['cnt']; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Fetch per-worker breakdown if there are unpaid records
if ($unpaid_count > 0) {
    try {
        $stmtList = $conn->prepare("SELECT w.id, COALESCE(w.full_name,'Unknown') AS worker_name,
                                           COUNT(*) AS items, COALESCE(SUM(cw.amount),0) AS total_amount
                                    FROM car_washes cw
                                    LEFT JOIN workers w ON cw.worker_id = w.id
                                    WHERE DATE(cw.created_at) = CURDATE()
                                      AND (cw.payment_confirmed IS NULL OR cw.payment_confirmed = 0)
                                      AND cw.admin_id = ?
                                    GROUP BY w.id, w.full_name
                                    ORDER BY worker_name ASC");
        $stmtList->bind_param('i', $_SESSION['user_id']);
        $stmtList->execute();
        $resList = $stmtList->get_result();
        while ($row = $resList->fetch_assoc()) { $unpaid_workers[] = $row; }
    } catch (mysqli_sql_exception $e) { /* ignore */ }
}

// Flash message support
$flash_unpaid_error = $_SESSION['flash_unpaid_error'] ?? '';
if (isset($_SESSION['flash_unpaid_error'])) { unset($_SESSION['flash_unpaid_error']); }

// All totals (count + gross)
$sqlAll = "SELECT COUNT(*) as total_all, COALESCE(SUM(amount),0) as gross_all
           FROM car_washes WHERE created_at >= ? AND created_at <= ?";
$stmtAll = $conn->prepare($sqlAll);
$stmtAll->bind_param('ss', $dayStart, $dayEnd);
$stmtAll->execute();
$resAll = $stmtAll->get_result();
$total_all = 0; $gross_all = 0.0;
if ($row = $resAll->fetch_assoc()) {
    $total_all = (int)$row['total_all'];
    $gross_all = (float)$row['gross_all'];
}

// Dynamically fetch all columns from car_washes to build safe queries
$carWashCols = [];
try {
    $cwRes = $conn->query("SHOW COLUMNS FROM car_washes");
    if ($cwRes) {
        while ($c = $cwRes->fetch_assoc()) {
            $carWashCols[strtolower($c['Field'])] = true;
        }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }

$hasCategoryId = isset($carWashCols['category_id']);
$hasCarSizeId = isset($carWashCols['car_size_id']);
$hasIsFoul = isset($carWashCols['is_foul']);
$hasFoulOverrun = isset($carWashCols['foul_overrun_minutes']);
$hasWorkloadLevel = isset($carWashCols['workload_level']);
$hasDelayReasonId = isset($carWashCols['delay_reason_id']);
$hasDelayNotes = isset($carWashCols['delay_notes']);

// Get counts directly from car_washes table safely
try {
    if ($hasCategoryId) {
        $sqlCategories = "SELECT 
            cat.name as category_name,
            COUNT(*) as count,
            SUM(cw.amount) as total_amount
        FROM car_washes cw
        LEFT JOIN categories cat ON cw.category_id = cat.id
        WHERE cw.created_at >= ? AND cw.created_at <= ?
        GROUP BY cat.name";
    } else {
        $sqlCategories = "SELECT 
            'Uncategorized' as category_name,
            COUNT(*) as count,
            SUM(cw.amount) as total_amount
        FROM car_washes cw
        WHERE cw.created_at >= ? AND cw.created_at <= ?
        GROUP BY DATE(cw.created_at)"; // Avoid GROUP BY constant string
    }

    $stmtCategories = $conn->prepare($sqlCategories);
    if ($stmtCategories) {
        $stmtCategories->bind_param('ss', $dayStart, $dayEnd);
        $stmtCategories->execute();
        $resultCategories = $stmtCategories->get_result();
    }
} catch (mysqli_sql_exception $e) {
    log_error("Categories query failed: " . $e->getMessage());
}

// Get all services with their details safely
try {
    $selCarSize = $hasCarSizeId ? "cs.name as car_size_name," : "'Standard' as car_size_name,";
    $selCategory = $hasCategoryId ? "cat.name as category," : "'Uncategorized' as category,";
    
    $joinCarSize = $hasCarSizeId ? "LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id" : "";
    $joinCategory = $hasCategoryId ? "LEFT JOIN categories cat ON cw.category_id = cat.id" : "";

    $sqlServices = "SELECT 
        cw.id,
        cw.amount,
        $selCategory
        s.name as service_name,
        $selCarSize
        cw.created_at
    FROM car_washes cw
    LEFT JOIN services s ON cw.service_id = s.id
    $joinCarSize
    $joinCategory
    WHERE cw.created_at >= ? AND cw.created_at <= ?
    ORDER BY cw.created_at";

    $stmtServices = $conn->prepare($sqlServices);
    if ($stmtServices) {
        $stmtServices->bind_param('ss', $dayStart, $dayEnd);
        $stmtServices->execute();
        $resultServices = $stmtServices->get_result();
    }
} catch (mysqli_sql_exception $e) {
    log_error("Services query failed: " . $e->getMessage());
}

// Initialize counters
$motors_count = 0; $motors_gross = 0.0;
$carpets_count = 0; $carpets_gross = 0.0;
$cars_count = 0; $cars_gross = 0.0;

if (isset($resultServices) && $resultServices) {
    while ($service = $resultServices->fetch_assoc()) {
        $serviceName = strtolower($service['service_name'] ?? '');
        $carSize = strtolower($service['car_size_name'] ?? '');
        $category = strtolower($service['category'] ?? '');
        $amount = (float)($service['amount'] ?? 0);
        
        // Categorize based on service name
        if (stripos($serviceName, 'blow') !== false) {
            $cars_count++;
            $cars_gross += $amount;
        } 
        elseif (stripos($serviceName, 'basic') !== false) {
            $carpets_count++;
            $carpets_gross += $amount;
        }
        else {
            $cars_count++;
            $cars_gross += $amount;
        }
    }
}

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

// Fetch all individual washes for the day for detailed breakdown
$detailed_washes = [];
try {
    $selIsFoul = $hasIsFoul ? "cw.is_foul," : "0 as is_foul,";
    $selFoulOverrun = $hasFoulOverrun ? "cw.foul_overrun_minutes," : "0 as foul_overrun_minutes,";
    $selWorkloadLevel = $hasWorkloadLevel ? "cw.workload_level," : "'normal' as workload_level,";
    $selDelayReasonId = $hasDelayReasonId ? "cw.delay_reason_id," : "NULL as delay_reason_id,";
    $selDelayNotes = $hasDelayNotes ? "cw.delay_notes," : "'' as delay_notes,";
    
    $joinDelayReason = $hasDelayReasonId ? "LEFT JOIN delay_reasons dr ON cw.delay_reason_id = dr.id" : "";
    $selDelayReason = $hasDelayReasonId ? "dr.reason as delay_reason" : "'' as delay_reason";

    $sql_detailed = "SELECT 
                        cw.number_plate,
                        cw.amount,
                        $selIsFoul
                        $selFoulOverrun
                        $selWorkloadLevel
                        $selDelayReasonId
                        $selDelayNotes
                        w.full_name as worker_name,
                        s.name as service_name,
                        $selDelayReason
                     FROM car_washes cw
                     LEFT JOIN workers w ON cw.worker_id = w.id
                     LEFT JOIN services s ON cw.service_id = s.id
                     $joinDelayReason
                     WHERE cw.created_at >= ? AND cw.created_at <= ? 
                     ORDER BY cw.created_at ASC";

    $stmt_detailed = $conn->prepare($sql_detailed);
    if ($stmt_detailed) {
        $stmt_detailed->bind_param('ss', $dayStart, $dayEnd);
        $stmt_detailed->execute();
        $res_detailed = $stmt_detailed->get_result();
        while ($row = $res_detailed->fetch_assoc()) {
            $detailed_washes[] = $row;
        }
    }
} catch (mysqli_sql_exception $e) {
    log_error("Detailed washes query failed: " . $e->getMessage());
}

// Generate CSRF token
$token = csrf_token();
log_error('Generated CSRF token: ' . $token);

?>
<?php
$page_title = 'Daily Report Preview';
include 'includes/header.php';
?>
<style>
    /* Premium SaaS Report UI */
    .rp-page { max-width: 1200px; margin: 40px auto; padding: 0 20px 80px; }
    
    .rp-title { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; margin-bottom: 32px; }
    .rp-title-left { display: flex; align-items: center; gap: 16px; }
    .rp-title-icon { width: 64px; height: 64px; border-radius: 16px; background: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1.5px solid #e2e8f0; overflow: hidden; }
    .rp-title-icon img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
    .rp-title h1 { font-size: 1.8rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .rp-title p { font-size: 0.95rem; color: #64748b; margin: 4px 0 0; }
    
    .rp-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; border-radius: 10px; font-weight: 700; font-size: 0.95rem; background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff; text-decoration: none; border: none; cursor: pointer; transition: all .2s; box-shadow: 0 3px 12px rgba(0,174,239,0.3); }
    .rp-btn:hover { filter: brightness(1.1); transform: translateY(-1px); color: #fff; }
    .rp-btn:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(1); transform: none; }
    .rp-btn-outline { background: #fff; color: #1B3FA0; border: 1.5px solid #cbd5e1; box-shadow: none; }
    .rp-btn-outline:hover { background: #f8fafc; border-color: #94a3b8; color: #1B3FA0; }

    .rp-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.06); margin-bottom: 24px; overflow: hidden; }
    .rp-card-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff; display: flex; align-items: center; gap: 12px; }
    .rp-card-header h2 { font-size: 1.15rem; font-weight: 800; color: #1e293b; margin: 0; }

    .rp-alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 14px; border: 1px solid transparent; }
    .rp-alert-icon { font-size: 1.5rem; margin-top: 2px; }
    .rp-alert-title { font-weight: 800; font-size: 1rem; margin-bottom: 4px; }
    .rp-alert-desc { font-size: 0.9rem; line-height: 1.5; }
    
    .rp-alert.success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .rp-alert.warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
    .rp-alert.error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }

    .rp-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; padding: 24px; background: #f8faff; border-bottom: 1px solid #e2e8f0; }
    .rp-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
    .rp-stat-label { font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .rp-stat-val { font-size: 1.8rem; font-weight: 800; color: #1B3FA0; }
    .rp-stat-val.green { color: #059669; }

    .rp-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .rp-table th { padding: 16px 24px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; text-align: left; color: #475569; background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .rp-table td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
    .rp-table tbody tr:hover td { background: #f8faff; }
    .rp-table tbody tr:last-child td { border-bottom: none; }
    
    .rp-plate { font-family: monospace; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-weight: 700; color: #334155; border: 1px solid #cbd5e1; }
    .rp-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
    .rp-badge.foul { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .rp-badge.ok { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }

    .rp-footer { display: flex; justify-content: space-between; align-items: center; padding: 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; flex-wrap: wrap; gap: 20px; }
    .rp-meta { font-size: 0.9rem; color: #64748b; font-weight: 600; }
</style>

<div class="rp-page">
    <div class="rp-title">
        <div class="rp-title-left">
            <div class="rp-title-icon"><img src="../frontend/new logo.png" alt="WashHub Logo"></div>
            <div>
                <h1 style="text-transform: uppercase; letter-spacing: 1px; font-size: 1.6rem;"><?php echo htmlspecialchars($bayName); ?></h1>
                <p style="font-weight: 700; color: #1B3FA0;">Daily Operations Report Preview — <?php echo date('F j, Y', strtotime($reportDate)); ?></p>
            </div>
        </div>
        <a href="dashboard.php" class="rp-btn rp-btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="rp-alert success">
            <i class="fas fa-check-circle rp-alert-icon"></i>
            <div>
                <div class="rp-alert-title">Success</div>
                <div class="rp-alert-desc"><?php echo htmlspecialchars($_SESSION['flash_success']); ?></div>
            </div>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="rp-alert error">
            <i class="fas fa-exclamation-triangle rp-alert-icon"></i>
            <div>
                <div class="rp-alert-title">Error</div>
                <div class="rp-alert-desc"><?php echo htmlspecialchars($_SESSION['flash_error']); ?></div>
            </div>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($flash_unpaid_error)): ?>
        <div class="rp-alert error">
            <i class="fas fa-ban rp-alert-icon"></i>
            <div>
                <div class="rp-alert-title">Cannot Submit Report</div>
                <div class="rp-alert-desc"><?php echo htmlspecialchars($flash_unpaid_error); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($dayReported): ?>
        <div class="rp-alert success">
            <i class="fas fa-lock rp-alert-icon"></i>
            <div>
                <div class="rp-alert-title">Report Already Submitted</div>
                <div class="rp-alert-desc">The daily report for today has already been finalized and locked. You can review the totals below.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($unpaid_count > 0): ?>
        <div class="rp-alert warning" style="flex-direction: column; gap: 16px;">
            <div style="display: flex; gap: 14px;">
                <i class="fas fa-exclamation-triangle rp-alert-icon"></i>
                <div>
                    <div class="rp-alert-title">Action Required: Unpaid Workers</div>
                    <div class="rp-alert-desc">There are <strong><?php echo number_format($unpaid_count); ?></strong> unconfirmed wash records today. You must confirm these payments before submitting the daily report.</div>
                </div>
            </div>
            
            <?php if (!empty($unpaid_workers)): ?>
            <div style="width: 100%; background: #fff; border-radius: 8px; border: 1px solid #fde68a; overflow: hidden;">
                <table class="rp-table">
                    <thead>
                        <tr>
                            <th>Worker</th>
                            <th style="text-align: right;">Pending Items</th>
                            <th style="text-align: right;">Total Amount</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unpaid_workers as $uw): ?>
                        <tr>
                            <td style="font-weight: 700; color: #92400e;"><?php echo htmlspecialchars($uw['worker_name']); ?></td>
                            <td style="text-align: right;"><?php echo number_format((int)$uw['items']); ?></td>
                            <td style="text-align: right; font-weight: 700;">GHS <?php echo number_format((float)$uw['total_amount'], 2); ?></td>
                            <td style="text-align: right;">
                                <a href="worker_payments.php?worker_id=<?php echo (int)$uw['id']; ?>&return=daily_report_preview.php" class="rp-btn rp-btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">Review Payment</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="rp-card">
        <div class="rp-card-header">
            <i class="fas fa-coins" style="color:#059669; font-size:1.2rem;"></i>
            <h2>Revenue Breakdown</h2>
        </div>
        
        <div class="rp-stats-grid">
            <div class="rp-stat">
                <span class="rp-stat-label">Total Services</span>
                <span class="rp-stat-val"><?php echo number_format($total_all); ?></span>
            </div>
            <div class="rp-stat">
                <span class="rp-stat-label">Gross Revenue</span>
                <span class="rp-stat-val">GHS <?php echo number_format($gross_all, 2); ?></span>
            </div>
            <div class="rp-stat" style="border-top: 4px solid #059669; background: #f0fdf4;">
                <span class="rp-stat-label">Closing Amount (<?php echo round($company_pct, 1); ?>%)</span>
                <span class="rp-stat-val" style="color: #059669;">GHS <?php echo number_format($revenue_two_thirds_total, 2); ?></span>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="rp-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th style="text-align: center;">Count</th>
                        <th style="text-align: right;">Avg Unit Price</th>
                        <th style="text-align: right;">Gross Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($cars_count > 0): ?>
                    <tr>
                        <td style="font-weight: 700;">Car Washes</td>
                        <td style="text-align: center;"><?php echo number_format($cars_count); ?></td>
                        <td style="text-align: right; color: #64748b;">GHS <?php echo $cars_count > 0 ? number_format($cars_gross / $cars_count, 2) : '0.00'; ?></td>
                        <td style="text-align: right; font-weight: 700;">GHS <?php echo number_format($cars_gross, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($motors_count > 0): ?>
                    <tr>
                        <td style="font-weight: 700;">Motor Washes</td>
                        <td style="text-align: center;"><?php echo number_format($motors_count); ?></td>
                        <td style="text-align: right; color: #64748b;">GHS <?php echo $motors_count > 0 ? number_format($motors_gross / $motors_count, 2) : '0.00'; ?></td>
                        <td style="text-align: right; font-weight: 700;">GHS <?php echo number_format($motors_gross, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($carpets_count > 0): ?>
                    <tr>
                        <td style="font-weight: 700;">Carpet Cleaning</td>
                        <td style="text-align: center;"><?php echo number_format($carpets_count); ?></td>
                        <td style="text-align: right; color: #64748b;">GHS <?php echo $carpets_count > 0 ? number_format($carpets_gross / $carpets_count, 2) : '0.00'; ?></td>
                        <td style="text-align: right; font-weight: 700;">GHS <?php echo number_format($carpets_gross, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr style="background: #f8fafc; border-top: 2px solid #e2e8f0;">
                        <td style="font-weight: 800; font-size: 1.05rem;">GRAND TOTAL</td>
                        <td style="text-align: center; font-weight: 800; font-size: 1.05rem;"><?php echo number_format($total_all); ?></td>
                        <td style="text-align: right;">-</td>
                        <td style="text-align: right; font-weight: 800; font-size: 1.15rem; color: #1B3FA0;">GHS <?php echo number_format($gross_all, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rp-card">
        <div class="rp-card-header">
            <i class="fas fa-clipboard-list" style="color:#00AEEF; font-size:1.2rem;"></i>
            <h2>Detailed Operational Log</h2>
        </div>
        <?php if (!empty($detailed_washes)): ?>
            <div style="overflow-x: auto;">
                <table class="rp-table" style="font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Vehicle</th>
                            <th>Washer</th>
                            <th>Workload</th>
                            <th style="text-align:right;">Gross (GHS)</th>
                            <th style="text-align:right;">Closing (<?php echo round($company_pct, 1); ?>%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailed_washes as $wash): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($wash['service_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($wash['number_plate'] && $wash['number_plate'] !== 'N/A'): ?>
                                        <span class="rp-plate"><?php echo htmlspecialchars($wash['number_plate']); ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-style:italic;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($wash['worker_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php 
                                        $workload = htmlspecialchars($wash['workload_level'] ?? 'normal');
                                        if ($workload === 'busy') echo '<span style="color:#d97706; font-weight:700;"><i class="fas fa-fire"></i> Rush</span>';
                                        else echo '<span style="color:#64748b;">Normal</span>';
                                    ?>
                                </td>
                                <td style="text-align:right; font-weight:700; color:#1e293b;">
                                    <?php echo number_format((float)($wash['amount'] ?? 0), 2); ?>
                                </td>
                                <td style="text-align:right; font-weight:700; color:#059669;">
                                    <?php echo number_format(((float)($wash['amount'] ?? 0)) * ($company_pct / 100), 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 60px 20px; text-align: center; color: #94a3b8;">
                <i class="fas fa-clipboard-list" style="font-size: 3rem; margin-bottom: 16px; opacity: 0.5; display: block;"></i>
                <div style="font-size: 1.1rem; font-weight: 700; color: #475569; margin-bottom: 4px;">No Logs Found</div>
                <p>No wash records have been finalized today.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="rp-card rp-footer">
        <div>
            <div class="rp-meta">Prepared By: <span style="color:#1e293b;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span></div>
            <div class="rp-meta">Timestamp: <span style="color:#1e293b;"><?php echo date('Y-m-d H:i'); ?></span></div>
        </div>
        
        <form id="dailyReportForm" method="POST" action="submit_daily_report.php" style="margin: 0;">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="submit_daily_report" value="1" />
            
            <button type="submit" class="rp-btn" <?php echo ($dayReported || $unpaid_count > 0) ? 'disabled' : ''; ?> id="submitReportBtn">
                <i class="fas fa-paper-plane"></i> Finalize &amp; Submit Report
            </button>
            <div id="errorMessage" style="color: #e74c3c; margin-top: 10px; font-weight: 600; display: none;"></div>
            <div id="successMessage" style="color: #27ae60; margin-top: 10px; font-weight: 600; display: none;"></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dailyReportForm');
    const btn = document.getElementById('submitReportBtn');
    const errorMessage = document.getElementById('errorMessage');
    const successMessage = document.getElementById('successMessage');
    
    if (!form) return;
    
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        
        WashHubConfirm({
            title: 'Finalize Daily Report?',
            message: 'Are you absolutely sure you want to finalize and submit the daily report for today? This action cannot be undone.',
            type: 'success',
            onConfirm: () => {
                btn.disabled = true;
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                
                const formData = new FormData(form);
                const url = new URL(form.action);
                url.searchParams.append('_t', new Date().getTime());
                
                fetch(url.toString(), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json, text/plain, */*'
                    }
                })
                .then(async response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }
                    const contentType = response.headers.get('content-type');
                    const responseText = await response.text();
                    
                    if (!response.ok) throw new Error(responseText || `Server error`);
        
                    if (contentType && contentType.includes('application/json')) {
                        try {
                            const json = JSON.parse(responseText);
                            if (json.redirect_url) window.location.href = json.redirect_url;
                            else if (json.message) {
                                showSuccess(json.message);
                                setTimeout(() => window.location.reload(), 2000);
                            }
                        } catch (e) {
                            showSuccess('Report submitted successfully!');
                            setTimeout(() => window.location.reload(), 2000);
                        }
                    } else {
                        if (responseText.includes('window.location.href')) {
                            const match = responseText.match(/window\.location\.(?:href|replace)\s*=\s*['"]([^'"]+)['"]/i);
                            if (match && match[1]) window.location.href = match[1];
                            else window.location.reload();
                        } else {
                            window.location.href = 'daily_report_preview.php?success=1';
                        }
                    }
                })
                .catch(error => {
                    showError('Network error. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
            }
        });
        
        return false;
    });
    
    function showError(msg) { errorMessage.textContent = msg; errorMessage.style.display = 'block'; successMessage.style.display = 'none'; }
    function showSuccess(msg) { successMessage.textContent = msg; successMessage.style.display = 'block'; errorMessage.style.display = 'none'; }
});
</script>
<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>