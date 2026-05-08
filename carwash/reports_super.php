<?php
session_start();

// Super Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';
require_once __DIR__ . '/includes/csrf.php';

// Disable caching to ensure fresh data on each refresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$today = date('Y-m-d');
$nowTs = time();
$startTs = strtotime($today . ' 05:00:00');
$endTs = strtotime($today . ' 23:59:59');
$windowEndTs = min($nowTs, $endTs);
$afterHours = ($nowTs >= $endTs);
$startStr = date('Y-m-d H:i:s', $startTs);
$endStr = date('Y-m-d H:i:s', $windowEndTs);

// Day closed?
$dayClosed = false;
try {
    $stmtClose = $conn->prepare("CREATE TABLE IF NOT EXISTS day_closures (id INT AUTO_INCREMENT PRIMARY KEY, report_date DATE NOT NULL UNIQUE, closed_by INT NULL, closed_at DATETIME NULL, INDEX (report_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $stmtClose->execute();
    $stmtClose = $conn->prepare("SELECT closed_at FROM day_closures WHERE report_date = CURDATE() LIMIT 1");
    $stmtClose->execute();
    $resClose = $stmtClose->get_result();
    if ($resClose && ($rc = $resClose->fetch_assoc()) && !empty($rc['closed_at'])) {
        $dayClosed = true;
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Get Dynamic Worker Commission
$worker_pct = 33.33; // Default
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        $worker_pct = (float)$row_set['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
$company_pct = 100 - $worker_pct;

// Fetch submitted daily report
$submitted = null;
try {
    $stmtSR = $conn->prepare("CREATE TABLE IF NOT EXISTS daily_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL UNIQUE,
        total_cars_washed INT DEFAULT 0,
        total_motors_washed INT DEFAULT 0,
        total_carpets_washed INT DEFAULT 0,
        gross_amount_total DECIMAL(10,2) DEFAULT 0,
        revenue_two_thirds_total DECIMAL(10,2) DEFAULT 0,
        created_by INT NULL,
        submitted_at DATETIME NULL,
        INDEX(report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $stmtSR->execute();
    
    // Detect submitted_at availability; fallback to created_at
    $hasSubmittedAt = false;
    $colCheck = @$conn->query("SHOW COLUMNS FROM daily_reports LIKE 'submitted_at'");
    if ($colCheck && $colCheck->num_rows > 0) { 
        $hasSubmittedAt = true; 
    }
    $submittedExpr = $hasSubmittedAt ? 'submitted_at' : 'created_at';

    $stmtSR = $conn->prepare("SELECT report_date, total_cars_washed, total_motors_washed, total_carpets_washed, gross_amount_total, revenue_two_thirds_total, $submittedExpr AS submitted_at, created_by
                               FROM daily_reports WHERE report_date = CURDATE() LIMIT 1");
    $stmtSR->execute();
    $resSR = $stmtSR->get_result();
    if ($resSR && ($rpt = $resSR->fetch_assoc())) { $submitted = $rpt; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Auto-confirm after-hours on superadmin panel if a report exists and day not closed
if ($afterHours && !$dayClosed && !empty($submitted)) {
    try {
        $stmtAuto = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at)
                                     VALUES (CURDATE(), ?, NOW())
                                     ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), closed_at = NOW()");
        $stmtAuto->bind_param('i', $_SESSION['user_id']);
        $stmtAuto->execute();
        $dayClosed = true;
        $_SESSION['flash_day_closed'] = 1;
    } catch (mysqli_sql_exception $e) { /* ignore failure; allow manual confirm */ }
}

include 'includes/header.php';
?>

<style>
    .sup-page { max-width: 1200px; margin: 36px auto; padding: 0 20px 60px; }

    .sup-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .sup-title-left { display: flex; align-items: center; gap: 14px; }
    .sup-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .sup-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .sup-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .sup-alert { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 12px; font-size: 0.92rem; font-weight: 600; margin-bottom: 20px; }
    .sup-alert.success { background: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; }
    .sup-alert.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
    .sup-alert.info { background: #eff6ff; border-left: 4px solid #3b82f6; color: #1e40af; }

    .sup-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .sup-badge.closed { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

    .sup-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px; border-radius: 10px; font-weight: 700; font-size: 0.92rem;
        text-decoration: none; border: none; cursor: pointer; transition: all .2s;
    }
    .sup-btn-primary {
        background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff;
        box-shadow: 0 3px 12px rgba(245,158,11,0.3);
    }
    .sup-btn-primary:hover:not([disabled]) { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
    .sup-btn-primary[disabled] { opacity: 0.6; cursor: not-allowed; box-shadow: none; filter: grayscale(50%); }

    /* Cards */
    .sup-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 16px rgba(0,0,0,0.07); margin-bottom: 24px; overflow: hidden; }
    .sup-card-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .sup-card-header h2 { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 9px; }
    .sup-card-header h2 i { color: #00AEEF; }

    /* Summary Grid */
    .sup-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .sup-stat {
        background: #fff; border-radius: 16px; padding: 20px;
        border: 1px solid #e2e8f0; border-top: 5px solid #1B3FA0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.04); text-align: center;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .sup-stat.revenue { border-top-color: #10b981; background: linear-gradient(to bottom, #f0fdf4, #ffffff); }
    .sup-stat.cars    { border-top-color: #00AEEF; background: linear-gradient(to bottom, #f0f9ff, #ffffff); }
    .sup-stat.motors  { border-top-color: #f59e0b; background: linear-gradient(to bottom, #fffbeb, #ffffff); }
    .sup-stat.carpets { border-top-color: #8b5cf6; background: linear-gradient(to bottom, #f5f3ff, #ffffff); }
    
    .sup-stat h3 { font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 10px; }
    .sup-stat-val { font-size: 2.2rem; font-weight: 800; color: #1e293b; line-height: 1.1; margin-bottom: 8px; }
    .sup-stat-sub { font-size: 0.8rem; font-weight: 600; color: #94a3b8; display: flex; align-items: center; gap: 4px; }

    /* Table */
    .sup-table-wrap { overflow-x: auto; }
    .sup-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .sup-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .sup-table th { padding: 14px 20px; font-size: 0.73rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; text-align: left; color: #fff; white-space: nowrap; }
    .sup-table td { padding: 14px 20px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; line-height: 1.5; }
    .sup-table tbody tr:last-child td { border-bottom: none; }
    .sup-table tbody tr:hover td { background: #f0f7ff; }
    
    .sup-amt { font-weight: 700; color: #059669; }
    .sup-over { color: #dc2626; font-weight: 700; }
</style>

<script>
  // Auto-refresh every 2 minutes (120,000 ms)
  (function(){
    setTimeout(function(){ try { window.location.reload(); } catch(e) {} }, 120000);
  })();
</script>

<div class="sup-page">
    <div id="flashArea"></div>
    <?php if (!empty($_SESSION['flash_day_closed'])): unset($_SESSION['flash_day_closed']); ?>
        <div class="sup-alert success">
            <i class="fas fa-check-circle" style="font-size:1.2rem;"></i>
            <div>
                <strong>End of Day Confirmed.</strong> Today's report has been archived.
            </div>
        </div>
        <?php
        // === Per-service summary: list ALL services with today's counts ===
        $cwCols = [];
        try {
            if ($rsCols = $conn->query("SHOW COLUMNS FROM car_washes")) {
                while ($c = $rsCols->fetch_assoc()) { $cwCols[strtolower($c['Field'])] = true; }
            }
        } catch (mysqli_sql_exception $e) { /* ignore */ }
        $hasCompletedAt = isset($cwCols['completed_at']);
        $hasTimestamp   = isset($cwCols['timestamp']);
        $hasCreatedAt   = isset($cwCols['created_at']);

        $svcDateConds = [];
        if (!empty($hasCreatedAt) && $hasCreatedAt)   { $svcDateConds[] = 'DATE(cw.created_at) = CURDATE()'; }
        if (!empty($hasCompletedAt) && $hasCompletedAt) { $svcDateConds[] = 'DATE(cw.completed_at) = CURDATE()'; }
        if (!empty($hasTimestamp) && $hasTimestamp)   { $svcDateConds[] = 'DATE(cw.timestamp) = CURDATE()'; }
        $svcWhere = !empty($svcDateConds) ? ('(' . implode(' OR ', $svcDateConds) . ')') : '1=0';

        $sqlPerSvc = "SELECT s.id, s.name, COUNT(cw.id) AS washes, COALESCE(SUM(cw.amount),0) AS gross
                       FROM services s LEFT JOIN car_washes cw ON cw.service_id = s.id AND $svcWhere
                       GROUP BY s.id, s.name ORDER BY s.name";
        $perService = [];
        if ($rsPS = $conn->query($sqlPerSvc)) { $perService = $rsPS->fetch_all(MYSQLI_ASSOC); }
        ?>

        <div class="sup-card">
            <div class="sup-card-header">
                <h2><i class="fas fa-list-ul"></i> Per Service Summary (Today)</h2>
            </div>
            <div class="sup-table-wrap">
                <table class="sup-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th style="text-align:right;">Washes</th>
                            <th style="text-align:right;">Amount (GHS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($perService)): ?>
                            <tr><td colspan="3" style="text-align:center; color:#94a3b8; padding: 20px;">No services found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($perService as $ps): ?>
                                <tr>
                                    <td style="font-weight:700;"><?php echo htmlspecialchars($ps['name']); ?></td>
                                    <td style="text-align:right; font-weight:700;"><?php echo (int)$ps['washes']; ?></td>
                                    <td style="text-align:right;" class="sup-amt">GHS <?php echo number_format((float)$ps['gross'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="sup-title">
        <div class="sup-title-left">
            <div class="sup-title-icon"><i class="fas fa-chess-king"></i></div>
            <div>
                <div style="display:flex; align-items:center; gap: 10px;">
                    <h1>Super Admin Dashboard</h1>
                    <?php if ($dayClosed): ?>
                        <span class="sup-badge closed"><i class="fas fa-lock"></i> Day Closed</span>
                    <?php endif; ?>
                </div>
                <p>Live overview and daily performance verification.</p>
            </div>
        </div>
        
        <?php $canConfirm = (!$dayClosed && !empty($submitted)); ?>
        <div>
        <?php if ($canConfirm): ?>
            <form method="post" action="close_day.php" data-confirm-title="Close Work Day" data-confirm="Confirm today's report? This will archive totals and clear daily data until new records are added." data-confirm-type="success" data-confirm-btn="Confirm & Close" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="ajax" value="1" />
                <button type="submit" class="sup-btn sup-btn-primary"><i class="fas fa-check-double"></i> Confirm Report</button>
            </form>
        <?php else: ?>
            <button class="sup-btn sup-btn-primary" disabled title="<?php echo $dayClosed ? 'Already confirmed for today' : 'Admin has not submitted today\'s report yet'; ?>">
                <i class="fas fa-<?php echo $dayClosed ? 'lock' : 'clock'; ?>"></i> <?php echo $dayClosed ? 'Confirmed' : 'Confirm Report'; ?>
            </button>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($afterHours && !$dayClosed): ?>
        <div class="sup-alert warning">
            <i class="fas fa-moon" style="font-size:1.2rem;"></i>
            <div style="flex: 1;">
                <strong>After-hours Active:</strong> Today's worker performance is shown until you confirm end-of-day.
            </div>
            <?php if ($canConfirm): ?>
                <form method="post" action="close_day.php" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="ajax" value="1" />
                    <button type="submit" class="sup-btn sup-btn-primary" style="padding: 8px 16px;">Confirm End of Day</button>
                </form>
            <?php else: ?>
                <button type="button" class="sup-btn sup-btn-primary" style="padding: 8px 16px;" onclick="alert('Admin has not submitted today\'s report yet. Please ask the admin to submit the daily report first.');">
                    Confirm End of Day
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($dayClosed): ?>
        <div class="sup-alert success">
            <i class="fas fa-info-circle" style="font-size:1.2rem;"></i>
            <div>
                <strong>Day is currently closed.</strong> Daily operational data is cleared until new records are added tomorrow.
            </div>
        </div>
    <?php elseif ($submitted): ?>
        <?php
            // Resolve submitter's full name
            $submitter_name = 'Admin';
            try {
                if (!empty($submitted['created_by'])) {
                    $uid = (int)$submitted['created_by'];
                    if ($stmtU = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1")) {
                        $stmtU->bind_param('i', $uid);
                        $stmtU->execute();
                        $ru = $stmtU->get_result();
                        if ($ru && ($uu = $ru->fetch_assoc()) && !empty($uu['full_name'])) {
                            $submitter_name = $uu['full_name'];
                        }
                    }
                }
            } catch (Throwable $e) { /* keep default */ }
        ?>
        
        <div class="sup-grid">
            <div class="sup-stat revenue" style="border-top-color: #059669;">
                <h3>Closing Amount (<?php echo round($company_pct, 1); ?>%)</h3>
                <div class="sup-stat-val" style="color: #059669;">GHS <?php echo number_format((float)($submitted['revenue_two_thirds_total'] ?? 0), 2); ?></div>
                <div class="sup-stat-sub"><i class="fas fa-hand-holding-usd"></i> Closing to Manager</div>
            </div>
            <div class="sup-stat revenue">
                <h3>Total Gross</h3>
                <div class="sup-stat-val">GHS <?php echo number_format((float)$submitted['gross_amount_total'], 2); ?></div>
                <div class="sup-stat-sub"><i class="fas fa-calendar-day"></i> <?php echo date('M j, Y', strtotime($submitted['report_date'])); ?></div>
            </div>
            <div class="sup-stat cars">
                <h3>Today's Cars</h3>
                <div class="sup-stat-val"><?php echo number_format((int)$submitted['total_cars_washed']); ?></div>
                <div class="sup-stat-sub"><i class="fas fa-car"></i> By <?php echo htmlspecialchars($submitter_name); ?></div>
            </div>
            <div class="sup-stat motors">
                <h3>Today's Motors</h3>
                <div class="sup-stat-val"><?php echo number_format((int)($submitted['total_motors_washed'] ?? 0)); ?></div>
                <div class="sup-stat-sub"><i class="fas fa-motorcycle"></i> By <?php echo htmlspecialchars($submitter_name); ?></div>
            </div>
            <div class="sup-stat carpets">
                <h3>Today's Carpets</h3>
                <div class="sup-stat-val"><?php echo number_format((int)$submitted['total_carpets_washed']); ?></div>
                <div class="sup-stat-sub"><i class="fas fa-layer-group"></i> By <?php echo htmlspecialchars($submitter_name); ?></div>
            </div>
        </div>

        <div class="sup-card">
            <div class="sup-card-header">
                <h2><i class="fas fa-chart-pie"></i> Today's Breakdown</h2>
            </div>
            <div class="sup-table-wrap">
                <table class="sup-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th style="text-align:right;">Cars</th>
                            <th style="text-align:right;">Motors</th>
                            <th style="text-align:right;">Carpets</th>
                            <th style="text-align:right;">Gross (GHS)</th>
                            <th style="text-align:right;">Closing Amount (<?php echo round($company_pct, 1); ?>%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="font-weight:700;"><?php echo date('M j, Y', strtotime($submitted['report_date'])); ?></td>
                            <td style="text-align:right; font-weight:700;"><?php echo number_format((int)$submitted['total_cars_washed']); ?></td>
                            <td style="text-align:right; font-weight:700;"><?php echo number_format((int)($submitted['total_motors_washed'] ?? 0)); ?></td>
                            <td style="text-align:right; font-weight:700;"><?php echo number_format((int)$submitted['total_carpets_washed']); ?></td>
                            <td style="text-align:right;" class="sup-amt">GHS <?php echo number_format((float)$submitted['gross_amount_total'], 2); ?></td>
                            <td style="text-align:right; font-weight:700; color:#059669;">GHS <?php echo number_format((float)($submitted['revenue_two_thirds_total'] ?? 0), 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        // === Per-activity timing breakdown (today) ===
        $cwCols = [];
        try {
            if ($rsCols = $conn->query("SHOW COLUMNS FROM car_washes")) {
                while ($c = $rsCols->fetch_assoc()) { $cwCols[strtolower($c['Field'])] = true; }
            }
        } catch (mysqli_sql_exception $e) { /* ignore */ }
        $hasCompletedAt = isset($cwCols['completed_at']);
        $hasStartedAt   = isset($cwCols['started_at']);
        $hasPlannedEnd  = isset($cwCols['planned_end']);
        $hasPlannedStart= isset($cwCols['planned_start']);
        $hasTimestamp   = isset($cwCols['timestamp']);
        $hasCreatedAt   = isset($cwCols['created_at']);

        // Build WHERE to get today's activities
        $dateConds = [];
        if ($hasCreatedAt)   { $dateConds[] = 'DATE(cw.created_at) = CURDATE()'; }
        if ($hasCompletedAt) { $dateConds[] = 'DATE(cw.completed_at) = CURDATE()'; }
        if ($hasTimestamp)   { $dateConds[] = 'DATE(cw.timestamp) = CURDATE()'; }
        $dateWhere = !empty($dateConds) ? ('(' . implode(' OR ', $dateConds) . ')') : '1=0';

        $sqlAct = "SELECT cw.*, s.name AS service_name, w.full_name AS worker_name, dr.reason as delay_reason
                   FROM car_washes cw
                   LEFT JOIN services s ON cw.service_id = s.id
                   LEFT JOIN workers  w ON cw.worker_id = w.id
                   LEFT JOIN delay_reasons dr ON cw.delay_reason_id = dr.id
                   WHERE $dateWhere
                   ORDER BY cw.id DESC";
        $activities = [];
        if ($rsAct = $conn->query($sqlAct)) { $activities = $rsAct->fetch_all(MYSQLI_ASSOC); }

        // Normalize and merge rows
        $rows = [];
        foreach ($activities as $r) {
            $rows[] = [
                'service_id'    => $r['service_id'] ?? null,
                'service_name'  => $r['service_name'] ?? null,
                'worker_id'     => $r['worker_id'] ?? null,
                'worker_name'   => $r['worker_name'] ?? null,
                'number_plate'  => $r['number_plate'] ?? '',
                'started_at'    => $hasStartedAt ? ($r['started_at'] ?? null) : null,
                'planned_start' => $hasPlannedStart ? ($r['planned_start'] ?? null) : null,
                'planned_end'   => $hasPlannedEnd ? ($r['planned_end'] ?? null) : null,
                'completed_at'  => $hasCompletedAt ? ($r['completed_at'] ?? null) : ($hasTimestamp ? ($r['timestamp'] ?? null) : null),
                'delay_reason'  => $r['delay_reason'] ?? null,
                'delay_notes'   => $r['delay_notes'] ?? null,
            ];
        }

        // Sort by started_at desc then completed_at desc
        usort($rows, function($a,$b){
            $as = strtotime($a['started_at'] ?? '') ?: 0; $bs = strtotime($b['started_at'] ?? '') ?: 0;
            if ($as === $bs) {
                $ac = strtotime($a['completed_at'] ?? '') ?: 0; $bc = strtotime($b['completed_at'] ?? '') ?: 0;
                return $bc <=> $ac;
            }
            return $bs <=> $as;
        });

        $computeMins = function($from, $to) {
            $a = strtotime($from ?: ''); $b = strtotime($to ?: '');
            if ($a === false || $b === false) return null;
            $diff = max(0, $b - $a);
            return (int)ceil($diff / 60);
        };
        ?>

        <div class="sup-card">
            <div class="sup-card-header">
                <h2><i class="fas fa-clipboard-list"></i> Today's Activities by Service</h2>
            </div>
            <div class="sup-table-wrap">
                <table class="sup-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Worker</th>
                            <th>Plate</th>
                            <th>Start</th>
                            <th>End</th>
                            <th style="text-align:right;">Allowed</th>
                            <th style="text-align:right;">Exceeded</th>
                            <th>Delay Justification</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">No activity records for today.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $start     = $row['started_at']    ?? null;
                                $pstart    = $row['planned_start'] ?? null;
                                $pend      = $row['planned_end']   ?? null;
                                $completed = $row['completed_at']  ?? null;

                                $allowedMins = ($pstart && $pend) ? $computeMins($pstart, $pend) : null;
                                $nowStr = date('Y-m-d H:i:s');
                                if ($pend) {
                                    if ($completed) {
                                        $exceededMins = max(0, (int)$computeMins($pend, $completed));
                                    } else {
                                        $exceededMins = max(0, (int)$computeMins($pend, $nowStr));
                                    }
                                } else {
                                    $exceededMins = 0;
                                }
                                $exceededStr  = $exceededMins > 0 ? ("+".$exceededMins." min") : "—";
                                $allowedStr   = isset($allowedMins) ? ($allowedMins.' min') : '—';
                                $fmt = function($dt){ if(!$dt) return '—'; $t=strtotime($dt); if($t===false) return '—'; return date('H:i',$t); };
                            ?>
                            <tr>
                                <td style="font-weight:700;"><?php echo htmlspecialchars($row['service_name'] ?? ('#'.$row['service_id'])); ?></td>
                                <td><?php echo htmlspecialchars($row['worker_name'] ?? ('#'.$row['worker_id'])); ?></td>
                                <td><span style="background:#f1f5f9; border:1px solid #cbd5e1; padding:2px 6px; border-radius:4px; font-family:monospace;"><?php echo htmlspecialchars($row['number_plate'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($fmt($start)); ?></td>
                                <td><?php echo htmlspecialchars($fmt($completed)); ?></td>
                                <td style="text-align:right;"><?php echo htmlspecialchars($allowedStr); ?></td>
                                <td style="text-align:right;" class="<?php echo ($exceededMins>0?'sup-over':''); ?>">
                                    <?php echo htmlspecialchars($exceededStr); ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['delay_reason'])): ?>
                                        <div style="font-weight:700; color:#b45309;"><?php echo htmlspecialchars($row['delay_reason']); ?></div>
                                        <?php if (!empty($row['delay_notes'])): ?>
                                            <div style="font-size: 0.8rem; color: #64748b; margin-top:2px;"><?php echo htmlspecialchars($row['delay_notes']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="sup-alert info">
            <i class="fas fa-info-circle" style="font-size:1.2rem;"></i>
            <div>No submitted daily report for today yet. The Admin must submit the end-of-day report first.</div>
        </div>
    <?php endif; ?>
</div>

<script>
(function(){
  function showFlash(html) {
    const area = document.getElementById('flashArea');
    if (!area) return;
    area.innerHTML = html;
    setTimeout(() => {
      area.style.opacity = '0';
      area.style.transition = 'opacity 0.5s';
      setTimeout(() => { area.innerHTML = ''; area.style.opacity = '1'; }, 500);
    }, 5000);
  }

  function successCard(message) {
    return `
      <div class="sup-alert success">
        <i class="fas fa-check-circle" style="font-size:1.2rem;"></i>
        <div><strong>${message}</strong></div>
      </div>`;
  }

  function errorCard(message) {
    return `
      <div class="sup-alert warning">
        <i class="fas fa-exclamation-triangle" style="font-size:1.2rem;"></i>
        <div><strong>${message}</strong></div>
      </div>`;
  }

  function markClosedUI() {
    document.querySelectorAll('form[action="close_day.php"]').forEach(form => {
      form.style.display = 'none';
    });
    
    const h1cont = document.querySelector('.sup-title-left div div');
    if (h1cont && !document.querySelector('.sup-badge.closed')) {
        h1cont.innerHTML += '<span class="sup-badge closed"><i class="fas fa-lock"></i> Day Closed</span>';
    }
  }

  function handleAjaxResponse(response) {
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    const contentType = response.headers.get('content-type');
    if (contentType && contentType.includes('application/json')) {
      return response.json();
    }
    return response.text().then(text => {
      try {
        return JSON.parse(text);
      } catch (e) {
        return { ok: false, message: 'Invalid response from server' };
      }
    });
  }

  function wireAjaxConfirm() {
    const forms = document.querySelectorAll('form[action="close_day.php"]');
    
    forms.forEach(form => {
      if (form.dataset.ajaxWired === '1') return;
      form.dataset.ajaxWired = '1';
      
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
        
        try {
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
          }
          
          const formData = new FormData(form);
          formData.set('ajax', '1');
          
          const response = await fetch('close_day.php', {
            method: 'POST',
            body: formData,
            headers: { 
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json, text/plain, */*'
            },
            credentials: 'same-origin'
          });
          
          const data = await handleAjaxResponse(response);
          
          if (data && data.ok) {
            showFlash(successCard(data.message || 'End of day confirmed successfully.'));
            markClosedUI();
            setTimeout(() => window.location.reload(), 1500);
          } else {
            const errorMsg = data && data.message 
              ? data.message 
              : 'Failed to confirm end of day. Please try again.';
            showFlash(errorCard(errorMsg));
          }
        } catch (error) {
          console.error('Error:', error);
          showFlash(errorCard('Network error. Please check your connection and try again.'));
        } finally {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
          }
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireAjaxConfirm();
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
