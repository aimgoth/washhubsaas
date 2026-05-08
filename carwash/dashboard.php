<?php
require_once 'config/session.php';
require_once 'config/database.php';
require_once 'includes/csrf.php';

// Hard guard against fallback to master DB after tenant login.
// Reconnect immediately to tenant DB in this same request.
try {
    $dbNowRes = $conn ? $conn->query("SELECT DATABASE() AS dbn") : null;
    $dbNow = $dbNowRes ? (string)($dbNowRes->fetch_assoc()['dbn'] ?? '') : '';
    $masterDb = getenv('DB_NAME') ?: 'carwash_db';
    $targetTenantDb = (string)($_SESSION['ACTIVE_TENANT_DB'] ?? ($_SESSION['DB_NAME_OVERRIDE'] ?? ''));

    if ($dbNow === $masterDb && $targetTenantDb !== '' && $targetTenantDb !== $masterDb) {
        $tenantConn = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $targetTenantDb, (int)DB_PORT);
        if ($tenantConn && !$tenantConn->connect_error) {
            $tenantConn->query("SET time_zone = '+00:00'");
            $conn = $tenantConn;
            $_SESSION['DB_NAME_OVERRIDE'] = $targetTenantDb;
        }
    }
} catch (Throwable $e) {
    // Continue; later query failures are handled by existing logic/error output.
}

// Handle close_day before any output to allow safe redirects
if (isset($_GET['close_day']) && $_GET['close_day'] == '1' && ($_SESSION['role'] ?? '') === 'superadmin') {
    // Ensure closure table exists and upsert today's closure
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS day_closures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_date DATE NOT NULL UNIQUE,
            closed_by INT NULL,
            closed_at DATETIME NULL,
            INDEX(report_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at) 
                              VALUES (CURDATE(), ?, NOW()) 
                              ON DUPLICATE KEY UPDATE 
                              closed_by=VALUES(closed_by), 
                              closed_at=VALUES(closed_at)");
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        
        // Redirect to avoid form resubmission
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    } catch (Exception $e) {
        error_log("Error in close_day handler: " . $e->getMessage());
        // Continue with page load but show error
        $closeDayError = "Failed to confirm end of day. Please try again.";
    }
}

// Check if user is logged in and is admin/superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Daily Report submission is now handled by submit_daily_report.php (PRG)

// Removed: do not overwrite $_SESSION['role'] from DB on each request.
// Trust the role set at login to avoid unexpected view switching after refresh.

// Flash message handling
$flash_report_submitted = !empty($_SESSION['flash_report_submitted']);
if ($flash_report_submitted) { unset($_SESSION['flash_report_submitted']); }

// Has today's daily report been submitted? Capture submitted_at timestamp if available
$dayReported = false;
$reportSubmittedAtTs = null;
// Has superadmin confirmed/closed today? Capture closed_at timestamp
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

// Read closure time if any (superadmin action)
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

// Get statistics
$stats = [
    'total_washes' => 0,
    'total_employees' => 0,
    'total_amount' => 0,
    'monthly_revenue' => 0,
    'carpet_daily' => 0,
    'carpet_monthly' => 0,
    'recent_washes' => []
];

// Get today's date
$today = date('Y-m-d');
// Work hours window: 05:00 - 19:00 local time
$nowTs = time();
$startTs = strtotime($today . ' 05:00:00');
$endTs = strtotime($today . ' 19:00:00');
$inWorkWindow = ($nowTs >= $startTs && $nowTs < $endTs);

// Legacy close-day flag (kept for backward compatibility in UI logic)
$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
$closeFlagPath = $tmpDir . '/close_' . $today . '.flag';
// Preserve existing flag state but prefer DB closed_at
$fileClosed = file_exists($closeFlagPath);
if (!$dayClosed && $fileClosed) { $dayClosed = true; }

// Get washes count and amounts for TODAY.
// If a daily report exists, for admins start counting from submitted_at so only NEW records appear.
{
    $todayWhere = "WHERE DATE(created_at) = CURDATE()";
    $types = '';
    $params = [];
    // Determine threshold time: if closed, use closed_at for ALL; else if admin post-submission, use submitted_at
    $thresholdTs = null;
    if ($dayClosed && $closureAtTs) {
        $thresholdTs = $closureAtTs;
    } elseif ($dayReported && $_SESSION['role'] === 'admin' && $reportSubmittedAtTs) {
        $thresholdTs = $reportSubmittedAtTs;
    }
    if ($thresholdTs) {
        $todayWhere .= " AND created_at > ?";
        $params[] = date('Y-m-d H:i:s', $thresholdTs);
        $types .= 's';
    }
    $sql = "SELECT 
                COUNT(*) as total_washes,
                COALESCE(SUM(amount), 0) as gross_amount
            FROM car_washes 
            $todayWhere";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        // Get Dynamic Worker Commission
        $worker_pct = 33.33; // Default 1/3
        try {
            $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
            if ($res_set && $row_set = $res_set->fetch_assoc()) {
                $worker_pct = (float)$row_set['setting_value'];
            }
        } catch (Exception $e) { /* ignore */ }
        $company_pct = 100 - $worker_pct;

        $stats['total_washes'] = (int)$row['total_washes'];
        // Total Amount should reflect daily revenue (Company Share)
        $stats['total_amount'] = ($row['gross_amount'] ?? 0) * ($company_pct / 100);
    }

    // Define month range for further monthly metrics
    $monthStart = date('Y-m-01 00:00:00');
    $nowStr = date('Y-m-d H:i:s', $nowTs);

    // Check if category_id and legacy columns exist
    $hasCategoryId = false;
    $hasServiceType = false;
    $hasCarSize = false;
    try {
        $colRes = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'category_id'");
        if ($colRes && $colRes->num_rows > 0) { $hasCategoryId = true; }
    } catch (mysqli_sql_exception $e) { $hasCategoryId = false; }
    try {
        $colRes2 = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'service_type'");
        if ($colRes2 && $colRes2->num_rows > 0) { $hasServiceType = true; }
    } catch (mysqli_sql_exception $e) { $hasServiceType = false; }
    try {
        $colRes3 = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'car_size'");
        if ($colRes3 && $colRes3->num_rows > 0) { $hasCarSize = true; }
    } catch (mysqli_sql_exception $e) { $hasCarSize = false; }

    // Daily breakdown (today only): cars, carpets, motors
    if ($hasCategoryId) {
        $svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";
        $sqlDaily = "SELECT
            SUM(CASE WHEN LOWER(COALESCE(c.name,'')) = 'carpets' OR LOWER(".$svcNameExpr.") LIKE '%carpet%' THEN 1 ELSE 0 END) AS carpets_today,
            SUM(CASE WHEN LOWER(COALESCE(c.name,'')) IN ('motors','motor','bikes','bike')
                      OR LOWER(".$svcNameExpr.") LIKE '%motor%'
                      OR LOWER(".$svcNameExpr.") LIKE '%bike%'
                THEN 1 ELSE 0 END) AS motors_today,
            SUM(CASE WHEN (LOWER(COALESCE(c.name,'')) = 'carpets' OR LOWER(".$svcNameExpr.") LIKE '%carpet%')
                      OR (LOWER(COALESCE(c.name,'')) IN ('motors','motor','bikes','bike')
                          OR LOWER(".$svcNameExpr.") LIKE '%motor%'
                          OR LOWER(".$svcNameExpr.") LIKE '%bike%')
                 THEN 0 ELSE 1 END) AS cars_today
          FROM car_washes cw
          LEFT JOIN services s ON cw.service_id = s.id
          LEFT JOIN categories c ON cw.category_id = c.id
          WHERE DATE(cw.created_at) = CURDATE()";
        if ($thresholdTs) {
            $sqlDaily .= " AND cw.created_at > ?";
            $stmt = $conn->prepare($sqlDaily);
            $th = date('Y-m-d H:i:s', $thresholdTs);
            $stmt->bind_param('s', $th);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sqlDaily);
        }
    } else {
        $svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";
        $sqlDaily = "SELECT
            SUM(CASE WHEN LOWER(".$svcNameExpr.") LIKE '%carpet%' THEN 1 ELSE 0 END) AS carpets_today,
            SUM(CASE WHEN LOWER(".$svcNameExpr.") LIKE '%motor%' OR LOWER(".$svcNameExpr.") LIKE '%bike%' THEN 1 ELSE 0 END) AS motors_today,
            SUM(CASE WHEN LOWER(".$svcNameExpr.") LIKE '%carpet%'
                      OR LOWER(".$svcNameExpr.") LIKE '%motor%'
                      OR LOWER(".$svcNameExpr.") LIKE '%bike%'
                 THEN 0 ELSE 1 END) AS cars_today
          FROM car_washes cw
          LEFT JOIN services s ON cw.service_id = s.id
          WHERE DATE(cw.created_at) = CURDATE()";
        if ($thresholdTs) {
            $sqlDaily .= " AND cw.created_at > ?";
            $stmt = $conn->prepare($sqlDaily);
            $th = date('Y-m-d H:i:s', $thresholdTs);
            $stmt->bind_param('s', $th);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sqlDaily);
        }
    }
    if ($res && ($r = $res->fetch_assoc())) {
        $stats['carpets_today'] = (int)($r['carpets_today'] ?? 0);
        $stats['motors_today'] = (int)($r['motors_today'] ?? 0);
        $stats['cars_today'] = (int)($r['cars_today'] ?? 0);
    }

    // Monthly breakdown (month-to-date)
    if ($hasCategoryId) {
        $svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";
        $sqlMonthly = "SELECT
            SUM(CASE WHEN LOWER(COALESCE(c.name,'')) = 'carpets' OR LOWER(".$svcNameExpr.") LIKE '%carpet%' THEN 1 ELSE 0 END) AS carpets_month,
            SUM(CASE WHEN LOWER(COALESCE(c.name,'')) IN ('motors','motor','bikes','bike')
                      OR LOWER(".$svcNameExpr.") LIKE '%motor%'
                      OR LOWER(".$svcNameExpr.") LIKE '%bike%'
                THEN 1 ELSE 0 END) AS motors_month,
            SUM(CASE WHEN (LOWER(COALESCE(c.name,'')) = 'carpets' OR LOWER(".$svcNameExpr.") LIKE '%carpet%')
                      OR (LOWER(COALESCE(c.name,'')) IN ('motors','motor','bikes','bike')
                          OR LOWER(".$svcNameExpr.") LIKE '%motor%'
                          OR LOWER(".$svcNameExpr.") LIKE '%bike%')
                 THEN 0 ELSE 1 END) AS cars_month
          FROM car_washes cw
          LEFT JOIN services s ON cw.service_id = s.id
          LEFT JOIN categories c ON cw.category_id = c.id
          WHERE cw.created_at >= ? AND cw.created_at <= ?";
        $stmt = $conn->prepare($sqlMonthly);
        $stmt->bind_param('ss', $monthStart, $nowStr);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";
        $sqlMonthly = "SELECT
            SUM(CASE WHEN LOWER(".$svcNameExpr.") LIKE '%carpet%' THEN 1 ELSE 0 END) AS carpets_month,
            SUM(CASE WHEN LOWER(".$svcNameExpr.") LIKE '%motor%' OR LOWER(".$svcNameExpr.") LIKE '%bike%' THEN 1 ELSE 0 END) AS motors_month,
            SUM(CASE WHEN LOWER(".$svcNameExpr.") LIKE '%carpet%'
                      OR LOWER(".$svcNameExpr.") LIKE '%motor%'
                      OR LOWER(".$svcNameExpr.") LIKE '%bike%'
                 THEN 0 ELSE 1 END) AS cars_month
          FROM car_washes cw
          LEFT JOIN services s ON cw.service_id = s.id
          WHERE cw.created_at >= ? AND cw.created_at <= ?";
        $stmt = $conn->prepare($sqlMonthly);
        $stmt->bind_param('ss', $monthStart, $nowStr);
        $stmt->execute();
        $res = $stmt->get_result();
    }
    if ($res && ($r = $res->fetch_assoc())) {
        $stats['carpets_month'] = (int)($r['carpets_month'] ?? 0);
        $stats['motors_month'] = (int)($r['motors_month'] ?? 0);
        $stats['cars_month'] = (int)($r['cars_month'] ?? 0);
    }
}

// Monthly revenue (month-to-date) always visible (2/3 of gross)
if (!isset($monthStart)) { $monthStart = date('Y-m-01 00:00:00'); }
$monthEndStr = date('Y-m-d 23:59:59');
$sqlMonthAlways = "SELECT COALESCE(SUM(amount), 0) as gross_month FROM car_washes WHERE created_at >= ? AND created_at <= ?";
$stmtMonthAlways = $conn->prepare($sqlMonthAlways);
$stmtMonthAlways->bind_param('ss', $monthStart, $monthEndStr);
$stmtMonthAlways->execute();
$resMonthAlways = $stmtMonthAlways->get_result();
if ($mm = $resMonthAlways->fetch_assoc()) {
    $stats['monthly_revenue'] = ($mm['gross_month'] ?? 0) * ($company_pct / 100);
}

// Build month-to-date per-day chart data (counts and 2/3 revenue)
$sqlDaily = "SELECT DATE(created_at) as d, COUNT(*) as c, COALESCE(SUM(amount),0) as gross
             FROM car_washes
             WHERE created_at >= ? AND created_at <= ?
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at)";
$stmtDaily = $conn->prepare($sqlDaily);
$stmtDaily->bind_param('ss', $monthStart, $monthEndStr);
$stmtDaily->execute();
$resDaily = $stmtDaily->get_result();
$monthlyLabels = [];
$monthlyCounts = [];
$monthlyRevenue = [];
while ($row = $resDaily->fetch_assoc()) {
    $monthlyLabels[] = date('M j', strtotime($row['d']));
    $monthlyCounts[] = (int)$row['c'];
    $monthlyRevenue[] = ((float)$row['gross']) * ($company_pct / 100);
}

// Get total employees (only for superadmin)
if ($_SESSION['role'] === 'superadmin') {
    $sql = "SELECT COUNT(*) as total_employees FROM users WHERE role = 'admin'";
    $result = $conn->query($sql);
    if ($row = $result->fetch_assoc()) {
        $stats['total_employees'] = $row['total_employees'];
    }
}

    // Get recent washes for today with toggle to view all, fallback to last 2 overall if none today
    $stats['recent_washes'] = [];
    $view_all = isset($_GET['view_all']) && $_GET['view_all'] == '1';
    $limitClause = $view_all ? '' : 'LIMIT 2';

    $serviceNameExprSel = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "COALESCE(s.name, '')";
    $carSizeNameExprSel = $hasCarSize ? "COALESCE(cs.name, cw.car_size)" : "COALESCE(cs.name, '')";
    $baseSelect = "SELECT 
                   cw.id,
                   cw.number_plate,
                   cw.amount,
                   cw.created_at,
                   COALESCE(cw.workload_level, 'normal') as workload_level,
                   COALESCE(w.full_name, 'N/A') as worker_name,
                   u.full_name as admin_name,
                   " . $serviceNameExprSel . " as service_name,
                   " . $carSizeNameExprSel . " as car_size_name,
                   COALESCE(c.name, '') as category_name,
                   'completed' as status
            FROM car_washes cw
            LEFT JOIN workers w ON cw.worker_id = w.id
            LEFT JOIN users u ON cw.admin_id = u.id
            LEFT JOIN services s ON cw.service_id = s.id
            LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
            LEFT JOIN categories c ON cw.category_id = c.id";

    // Recent Washes: today only; if closed use closed_at for ALL; else for admin after submission start from submitted_at
    $recentWhere = "WHERE DATE(cw.created_at) = DATE(NOW())";
    $recentTypes = '';
    $recentParams = [];
    if ($thresholdTs) {
        $recentWhere .= " AND cw.created_at > ?";
        $recentParams[] = date('Y-m-d H:i:s', $thresholdTs);
        $recentTypes .= 's';
    }
    
    // Debug: Log the query and parameters
    error_log("Recent Washes Query: " . $baseSelect . "\n" . $recentWhere . "\nORDER BY cw.created_at DESC\n" . $limitClause);
    error_log("Query Params: " . print_r($recentParams, true));
    $sqlToday = $baseSelect . "\n$recentWhere\nORDER BY cw.created_at DESC\n$limitClause";
    try {
        $stmt = $conn->prepare($sqlToday);
        if (!empty($recentParams)) {
            $stmt->bind_param($recentTypes, ...$recentParams);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats['recent_washes'][] = $row;
            }
        }
    } catch (mysqli_sql_exception $e) {
        error_log("Dashboard query error (recent washes today-only): " . $e->getMessage());
    }

    // Removed fallback to older data: keep Recent Washes limited to today's/post-submission records

    // Removed 'view all overall' fallback to avoid showing old records

$page_title = ($_SESSION['full_name'] ?? 'Dashboard');
include 'includes/header.php';
?>

<div class="container" style="max-width: 1200px; margin: 0 auto; font-size: 1.4em; font-weight: 600; color: #000000;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #000000;">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Dashboard'); ?>
        </h1>
        <div style="display: flex; gap: 10px;">
            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                <a href="manage_settings.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 1.2rem; font-weight: 600; border-color: #00AEEF; color: #00AEEF;">
                    <i class="fas fa-percentage"></i> Commission Settings
                </a>
                <a href="users.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 1.2rem; font-weight: 600;">
                    <i class="fas fa-users"></i> Manage Users
                </a>
            <?php endif; ?>
        </div>
    </div>
    <!-- Removed End Tasks Button -->

    <?php if ($flash_report_submitted): ?>
    <div id="report-success" class="card" style="border-left:4px solid #27ae60; background:#ecf9f1;">
        <strong><i class="fas fa-check-circle"></i> Daily report submitted.</strong> Admin dashboard metrics are cleared until new records are added.
    </div>
    <script>
      setTimeout(function(){
        var el = document.getElementById('report-success');
        if (el) el.style.display = 'none';
      }, 4000);
    </script>
    <?php endif; ?>
    <?php if (!$inWorkWindow && !$dayClosed && $_SESSION['role'] === 'superadmin'): ?>
        <div class="card" style="border-left: 4px solid #e67e22; background-color: #fff7ec;">
            <div style="display:flex; justify-content: space-between; align-items:center; gap: 10px;">
                <div>
                    <strong>After-hours:</strong> Today's counters are still visible until you confirm end-of-day.
                </div>
                <a href="dashboard.php?close_day=1" class="btn" style="background-color:#e67e22;">Confirm End of Day</a>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; margin-bottom: 30px;">
        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <?php endif; ?>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 3rem; color: var(--primary-color); margin-bottom: 12px;">
                <i class="fas fa-car"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['total_washes']); ?></h3>
            <p style="color: var(--dark-gray);">Today's Washes</p>
        </div>
        
        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #27ae60; margin-bottom: 10px;">
                <i class="fas fa-cash-register"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;">GHS <?php echo number_format($stats['total_amount'], 2); ?></h3>
            <p style="color: var(--dark-gray);">Daily Revenue (<?php echo round($company_pct, 1); ?>%)</p>
        </div>
        
        <div class="card" style="text-align: center; padding: 20px; color: #000000; background-color: #eef3ff;">
            <div style="font-size: 2.5rem; color: #2c5cc5; margin-bottom: 10px;">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;">GHS <?php echo number_format($stats['monthly_revenue'], 2); ?></h3>
            <p style="color: var(--dark-gray);">Monthly Revenue (<?php echo round($company_pct, 1); ?>%)</p>
        </div>

        <!-- Today breakdown: Cars / Carpets / Motors -->
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #34495e; margin-bottom: 10px;">
                <i class="fas fa-car"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['cars_today'] ?? 0); ?></h3>
            <p style="color: var(--dark-gray);">Cars Washed Today</p>
        </div>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #8e44ad; margin-bottom: 10px;">
                <i class="fas fa-broom"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['carpets_today'] ?? 0); ?></h3>
            <p style="color: var(--dark-gray);">Carpet Washes Today</p>
        </div>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #e67e22; margin-bottom: 10px;">
                <i class="fas fa-motorcycle"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['motors_today'] ?? 0); ?></h3>
            <p style="color: var(--dark-gray);">Motor/Bike Washes Today</p>
        </div>

        <!-- Month-to-date breakdown: Cars / Carpets / Motors -->
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #2c3e50; margin-bottom: 10px;">
                <i class="fas fa-car"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['cars_month'] ?? 0); ?></h3>
            <p style="color: var(--dark-gray);">Cars Washed This Month</p>
        </div>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #2980b9; margin-bottom: 10px;">
                <i class="fas fa-broom"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['carpets_month'] ?? 0); ?></h3>
            <p style="color: var(--dark-gray);">Carpet Washes This Month</p>
        </div>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #d35400; margin-bottom: 10px;">
                <i class="fas fa-motorcycle"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo number_format($stats['motors_month'] ?? 0); ?></h3>
            <p style="color: var(--dark-gray);">Motor/Bike Washes This Month</p>
        </div>

        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #9B59B6; margin-bottom: 10px;">
                <i class="fas fa-users"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo $stats['total_employees']; ?></h3>
            <p style="color: var(--dark-gray);">Admin Users</p>
        </div>
        <?php endif; ?>
        
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #3498DB; margin-bottom: 10px;">
                <i class="fas fa-calendar-day"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;"><?php echo date('M j, Y'); ?></h3>
            <p style="color: var(--dark-gray);">Today's Date</p>
        </div>

        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #6B7280; margin-bottom: 10px;">
                <i class="fas fa-archive"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;">Daily Reports Archive</h3>
            <p style="color: var(--dark-gray);">Search and print past reports</p>
            <a href="daily_reports_archive.php" class="btn" style="margin-top: 10px;">
                <i class="fas fa-external-link-alt"></i> Open Archive
            </a>
        </div>
        <?php endif; ?>

        <?php if ($_SESSION['role'] === 'admin'): ?>
        <!-- Admin: Quick access to close worker accounts -->
        <div class="card" style="text-align: center; padding: 20px; color: #000000;">
            <div style="font-size: 2.5rem; color: #e67e22; margin-bottom: 10px;">
                <i class="fas fa-wallet"></i>
            </div>
            <h3 style="margin: 12px 0; color: var(--secondary-color); font-size: 1.4em;">Close Worker Accounts</h3>
            <p style="color: var(--dark-gray);">Confirm daily payments</p>
            <a href="worker_payments.php" class="btn" style="margin-top: 10px;">
                <i class="fas fa-user-check"></i> Open Payments
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Recent Washes Section -->
    <style>
        .rw-card { margin-bottom: 40px; }
        .rw-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .rw-title { font-size: 1.6rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 12px; }
        .rw-title i { color: var(--primary-color); font-size: 1.3rem; }
        
        .rw-table-container { border-radius: 16px; border: 1px solid #f1f5f9; background: #fff; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        .rw-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .rw-table th { background: #f8fafc; padding: 16px 24px; text-align: left; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #64748b; border-bottom: 1.5px solid #f1f5f9; }
        .rw-table td { padding: 18px 24px; border-bottom: 1px solid #f8fafc; vertical-align: middle; transition: background 0.2s; }
        .rw-table tr:hover td { background: #fcfdfe; }
        .rw-table tr:last-child td { border-bottom: none; }
        
        /* Vehicle Badge */
        .rw-plate { display: inline-flex; flex-direction: column; }
        .rw-plate-tag { background: #f1f5f9; color: #1e293b; font-family: 'JetBrains Mono', monospace; font-weight: 800; padding: 4px 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.9rem; letter-spacing: 0.5px; }
        .rw-size-tag { font-size: 0.75rem; color: #94a3b8; font-weight: 700; margin-top: 4px; padding-left: 2px; }
        
        /* Service Info */
        .rw-service-name { font-weight: 700; color: #1e293b; font-size: 0.95rem; display: block; }
        .rw-category-name { font-size: 0.8rem; color: #64748b; font-weight: 600; margin-top: 2px; }
        
        /* Status Badge */
        .rw-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 99px; font-size: 0.8rem; font-weight: 700; text-transform: capitalize; }
        .rw-status-completed { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
        .rw-status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        
        /* Amount */
        .rw-amount { font-weight: 800; color: #1e293b; text-align: right; font-size: 1.05rem; }
        
        /* Admin/Date Info */
        .rw-info-primary { font-weight: 700; color: #334155; font-size: 0.9rem; display: block; }
        .rw-info-secondary { font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-top: 2px; }
    </style>

    <div class="card rw-card">
        <div class="rw-header">
            <h2 class="rw-title"><i class="fas fa-history"></i> Recent Washes</h2>
            <div style="display: flex; gap: 10px;">
                <?php if (isset($view_all) && $view_all): ?>
                    <a href="dashboard.php" class="btn-outline" style="padding: 8px 16px; border-radius: 10px; font-size: 0.85rem;"><i class="fas fa-compress"></i> Show Less</a>
                <?php else: ?>
                    <a href="dashboard.php?view_all=1" class="btn-outline" style="padding: 8px 16px; border-radius: 10px; font-size: 0.85rem;"><i class="fas fa-expand-alt"></i> View All Today</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (count($stats['recent_washes']) > 0): ?>
            <div class="rw-table-container">
                <table class="rw-table">
                    <thead>
                        <tr>
                            <th>Time & Date</th>
                            <th>Vehicle Details</th>
                            <th>Service & Category</th>
                            <th>Washer</th>
                            <th>Workload</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Admin & Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_washes'] as $wash): ?>
                            <tr>
                                <td>
                                    <span class="rw-info-primary"><?php echo date('g:i A', strtotime($wash['created_at'])); ?></span>
                                    <span class="rw-info-secondary"><?php echo date('M j, Y', strtotime($wash['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div class="rw-plate">
                                        <span class="rw-plate-tag"><?php echo htmlspecialchars($wash['number_plate'] ?? 'N/A'); ?></span>
                                        <span class="rw-size-tag"><?php echo htmlspecialchars($wash['car_size_name'] ?? 'Regular'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="rw-service-name"><?php echo htmlspecialchars($wash['service_name'] ?? 'N/A'); ?></span>
                                    <span class="rw-category-name">
                                        <?php
                                            $cat = trim($wash['category_name'] ?? '');
                                            if ($cat === '') {
                                                $svc = strtolower($wash['service_name'] ?? '');
                                                $cat = (strpos($svc, 'carpet') !== false) ? 'Carpets' : ((strpos($svc, 'motor') !== false || strpos($svc, 'bike') !== false) ? 'Motors' : 'Cars');
                                            }
                                            echo htmlspecialchars($cat);
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="rw-info-primary" style="color: var(--secondary-color);"><i class="fas fa-user-circle" style="opacity: 0.5; margin-right: 4px;"></i> <?php echo htmlspecialchars($wash['worker_name'] ?? 'N/A'); ?></span>
                                </td>
                                <td>
                                    <?php 
                                        $wl = strtolower($wash['workload_level'] ?? 'normal');
                                        if ($wl === 'busy') {
                                            echo '<span class="rw-status-badge" style="background: #fffbeb; color: #d97706; border: 1px solid #fde68a; gap: 4px;">
                                                    <i class="fas fa-fire" style="font-size: 0.7rem;"></i> Rush
                                                  </span>';
                                        } else {
                                            echo '<span class="rw-status-badge" style="background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0;">
                                                    Normal
                                                  </span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="rw-amount">GHS <?php echo number_format($wash['amount'] ?? 0, 2); ?></div>
                                </td>
                                <td>
                                    <span class="rw-info-primary"><?php echo htmlspecialchars($wash['admin_name'] ?? 'System'); ?></span>
                                    <span class="rw-info-secondary">
                                        <?php echo strtoupper(substr($appName, 0, 4)) . '-' . str_pad((string)($wash['id'] ?? 0), 6, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="text-align: center; margin-top: 24px;">
                <a href="washes.php" class="btn" style="padding: 12px 30px; font-size: 0.95rem;"><i class="fas fa-list-ul"></i> View All Archive</a>
            </div>
        <?php else: ?>
            <div style="padding: 50px 20px; text-align: center; color: #94a3b8; background: #f8fafc; border-radius: 16px; border: 2px dashed #e2e8f0;">
                <i class="fas fa-car-side" style="font-size: 3rem; opacity: 0.2; margin-bottom: 20px; display: block;"></i>
                <p style="font-weight: 700; font-size: 1.1rem; color: #64748b;">No recent washes recorded yet today.</p>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="tasks_today.php" class="btn" style="margin-top: 20px;">Assign First Wash</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Monthly Chart (Superadmin) -->
    <?php if ($_SESSION['role'] === 'superadmin'): ?>
    <div class="card" style="margin-bottom: 30px; font-size: 1.4em; color: #000000;">
        <h2 style="font-size: 2.0rem; font-weight: 700;">Monthly Overview</h2>
        <canvas id="monthlyChart" height="120"></canvas>
    </div>
    <?php endif; ?>

    
    <!-- Quick Actions -->
    <div style="margin-top: 40px; margin-bottom: 30px;">
        <h2 style="font-size: 2.2rem; font-weight: 800; color: #1e293b; margin-bottom: 25px; text-align: center;">Quick Actions</h2>
        
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; justify-items: center;">
                
                <a href="tasks_today.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: var(--primary-color); margin-bottom: 10px;">
                        <i class="fas fa-car"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Tasks</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Assign, start and end</p>
                </a>
                
                <a href="employees.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: all 0.3s ease; border: 1px solid #e0e0e0; border-radius: 8px; background: linear-gradient(145deg, #ffffff, #f5f7fa); box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);">
                    <div style="font-size: 2rem; margin-bottom: 10px; background: #3498db; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                        <i class="fas fa-user-tie" style="color: white;"></i>
                    </div>
                    <h3 style="margin: 0 0 5px 0; font-size: 1.2rem; color: #2c3e50; font-weight: 600;">Manage Washers</h3>
                    <p style="color: #7f8c8d; font-size: 1.0rem; margin: 0; line-height: 1.4;">Add, edit, and manage</p>
                </a>
                
                <a href="daily_report_preview.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #27ae60; margin-bottom: 10px;">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Submit Daily Report</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Review today and submit</p>
                </a>
                
                <a href="repair_expenses.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #e67e22; margin-bottom: 10px;">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Fault & Repairs</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Log repair expenses</p>
                </a>
                
                <a href="monthly_washes.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #2c5cc5; margin-bottom: 10px;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Monthly Wash Log</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Search this month's records</p>
                </a>

            </div>
        <?php endif; ?>


        <?php if ($_SESSION['role'] === 'superadmin'): ?>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; justify-items: center;">
                <a href="monthly_report.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #27AE60; margin-bottom: 10px;">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Monthly Report</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Financial summaries</p>
                </a>
                
                <a href="repair_expenses_overview.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #e67e22; margin-bottom: 10px;">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Fault & Repairs</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Overview and totals</p>
                </a>

                <a href="manage_services.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #0ea5e9; margin-bottom: 10px;">
                        <i class="fas fa-list-ul"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Manage Services</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Add service types</p>
                </a>
                
                <a href="manage_prices.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #10b981; margin-bottom: 10px;">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Pricing Matrix</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Set prices for services</p>
                </a>
                
                <a href="manage_categories.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #10b981; margin-bottom: 10px;">
                        <i class="fas fa-tags"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Manage Categories</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Add wash categories</p>
                </a>
                
                <a href="manage_car_sizes.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #f59e0b; margin-bottom: 10px;">
                        <i class="fas fa-car-side"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Manage Sizes</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Add size options</p>
                </a>

                <a href="users.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #E74C3C; margin-bottom: 10px;">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Manage Users</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Superadmin & Admins</p>
                </a>
                
                <a href="manage_workers.php" class="card" style="width:100%; text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                    <div style="font-size: 2rem; color: #8E44AD; margin-bottom: 10px;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.1rem;">Workers Management</h3>
                    <p style="color: #000000; font-size: 1.2rem; font-weight: 600; margin: 5px 0 0;">Manage on-site staff</p>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        padding: 20px;
        margin-bottom: 20px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        text-align: left;
        padding: 12px;
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    td {
        padding: 12px;
        border-bottom: 1px solid #eee;
    }
    
    tr:last-child td {
        border-bottom: none;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background-color: var(--primary-color);
        color: white;
        text-decoration: none;
        border-radius: 4px;
        font-weight: 500;
        transition: background-color 0.2s;
    }
    
    .btn:hover {
        background-color: #16A085;
        color: white;
    }
    
    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--primary-color);
        color: var(--primary-color);
    }
    
    .btn-outline:hover {
        background-color: var(--primary-color);
        color: white;
    }
</style>

<?php if ($_SESSION['role'] === 'superadmin'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const monthlyLabels = <?php echo json_encode($monthlyLabels); ?>;
const monthlyCounts = <?php echo json_encode($monthlyCounts); ?>;
const monthlyRevenue = <?php echo json_encode($monthlyRevenue); ?>;

if (document.getElementById('monthlyChart')) {
  const ctx = document.getElementById('monthlyChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: monthlyLabels,
      datasets: [
        {
          label: 'Cars Washed',
          data: monthlyCounts,
          backgroundColor: 'rgba(52, 152, 219, 0.6)',
          borderColor: 'rgba(41, 128, 185, 1)',
          borderWidth: 1,
          yAxisID: 'y'
        },
        {
          label: 'Revenue (2/3) - GHS',
          data: monthlyRevenue,
          type: 'line',
          fill: false,
          borderColor: 'rgba(39, 174, 96, 1)',
          backgroundColor: 'rgba(39, 174, 96, 0.2)',
          tension: 0.3,
          yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      stacked: false,
      scales: {
        y: {
          type: 'linear',
          position: 'left',
          title: { display: true, text: 'Cars' }
        },
        y1: {
          type: 'linear',
          position: 'right',
          grid: { drawOnChartArea: false },
          title: { display: true, text: 'GHS' }
        }
      }
    }
  });
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
