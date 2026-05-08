<?php
session_start();

// Check if user is logged in and has admin rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';
require_once __DIR__ . '/includes/csrf.php';

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
        if ($stmtAuto = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at) VALUES (CURDATE(), ?, NOW())
                                       ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), closed_at = NOW()")) {
            $stmtAuto->bind_param('i', $_SESSION['user_id']);
            $stmtAuto->execute();
        }
    } catch (Throwable $e) { /* ignore */ }
    // Back-compat local flag (optional)
    $todayTmp = date('Y-m-d');
    $tmpDirEarly = __DIR__ . '/tmp';
    if (!is_dir($tmpDirEarly)) { @mkdir($tmpDirEarly, 0777, true); }
    @file_put_contents($tmpDirEarly . '/close_' . $todayTmp . '.flag', 'closed');
    $_SESSION['flash_day_closed'] = 1;
    header('Location: employees.php', true, 303);
    exit;
}

$error = '';
$success = '';
$employees = [];

// Get all workers (from workers table) with phone numbers
$sql = "SELECT id, full_name, phone, status, created_at 
        FROM workers 
        ORDER BY status DESC, full_name";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

// Handle worker status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    csrf_validate_or_die();
    $worker_id = intval($_POST['worker_id']);
    $current_status = $_POST['current_status'];
    $new_status = ($current_status === 'active') ? 'inactive' : 'active';
    
    $sql = "UPDATE workers SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $new_status, $worker_id);
    
    if ($stmt->execute()) {
        $success = 'Worker status updated successfully';
        header("Location: employees.php");
        exit;
    } else {
        $error = 'Error updating worker status: ' . $conn->error;
    }
}
?>
<?php include 'includes/header.php'; ?>

<?php
// Worker performance logic (daily + monthly) for Employees page
$today = date('Y-m-d');
$nowTs = time();
$startTs = strtotime($today . ' 05:00:00');
$endTs = strtotime($today . ' 19:00:00');
$inWorkWindow = ($nowTs >= $startTs && $nowTs < $endTs);

// End-of-day confirmation
// 1) Prefer DB-backed closure state (keeps consistent with reports_super.php)
$dayClosed = false;
try {
    $conn->query("CREATE TABLE IF NOT EXISTS day_closures (
      id INT AUTO_INCREMENT PRIMARY KEY,
      report_date DATE NOT NULL UNIQUE,
      closed_by INT NULL,
      closed_at DATETIME NULL,
      INDEX(report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    if ($stmtC = $conn->prepare("SELECT 1 FROM day_closures WHERE report_date = CURDATE() AND closed_at IS NOT NULL LIMIT 1")) {
        $stmtC->execute();
        $resC = $stmtC->get_result();
        if ($resC && $resC->num_rows > 0) { $dayClosed = true; }
    }
} catch (Throwable $e) { /* fallback to file flag below */ }

// 2) Backward-compatible file flag check (supports legacy close marker)
$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
$closeFlagPath = $tmpDir . '/close_' . $today . '.flag';
if (!$dayClosed && file_exists($closeFlagPath)) { $dayClosed = true; }
$showDaily = ($inWorkWindow || (!$inWorkWindow && !$dayClosed));
$windowEndTs = min($nowTs, $endTs);
$startStr = date('Y-m-d H:i:s', $startTs);
$endStr = date('Y-m-d H:i:s', $windowEndTs);

$monthStart = date('Y-m-01 00:00:00');
$nowStr = date('Y-m-d H:i:s', $nowTs);

$topDailyWorkers = [];
$topMonthlyWorkers = [];

if ($showDaily) {
    $sqlWD = "SELECT COALESCE(w.full_name, 'Unknown') AS worker_name,
                     COUNT(*) AS washes,
                     COALESCE(SUM(cw.amount),0) AS total_amount
              FROM car_washes cw
              LEFT JOIN workers w ON cw.worker_id = w.id
              WHERE cw.created_at >= ? AND cw.created_at <= ?
              GROUP BY worker_name
              ORDER BY washes DESC, total_amount DESC
              LIMIT 20";
    if ($stmtWD = $conn->prepare($sqlWD)) {
        $stmtWD->bind_param('ss', $startStr, $endStr);
        $stmtWD->execute();
        $resWD = $stmtWD->get_result();
        while ($row = $resWD->fetch_assoc()) { $topDailyWorkers[] = $row; }
    }
}

$sqlWM = "SELECT COALESCE(w.full_name, 'Unknown') AS worker_name,
                 COUNT(*) AS washes,
                 COALESCE(SUM(cw.amount),0) AS total_amount
          FROM car_washes cw
          LEFT JOIN workers w ON cw.worker_id = w.id
          WHERE cw.created_at >= ? AND cw.created_at <= ?
          GROUP BY worker_name
          ORDER BY washes DESC, total_amount DESC
          LIMIT 50";
if ($stmtWM = $conn->prepare($sqlWM)) {
    $stmtWM->bind_param('ss', $monthStart, $nowStr);
    $stmtWM->execute();
    $resWM = $stmtWM->get_result();
    while ($row = $resWM->fetch_assoc()) { $topMonthlyWorkers[] = $row; }
}
?>

<style>
    .wk-page { max-width: 1100px; margin: 36px auto; padding: 0 20px 60px; }

    .wk-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .wk-title-left { display: flex; align-items: center; gap: 14px; }
    .wk-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .wk-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .wk-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .wk-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .wk-btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-size: 0.92rem; font-weight: 700; cursor: pointer; text-decoration: none;
        transition: filter .2s, transform .15s; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .wk-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
    
    .wk-btn-secondary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px; border-radius: 10px;
        background: #f1f5f9; color: #475569; font-weight: 700;
        font-size: 0.92rem; text-decoration: none;
        border: 1.5px solid #e2e8f0;
        transition: background .2s, color .2s, border-color .2s;
    }
    .wk-btn-secondary:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }

    .wk-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 0.9rem; font-weight: 600; margin-bottom: 14px;
    }
    .wk-alert.ok  { background: #e0f2fe; border-left: 4px solid #00AEEF; color: #0369a1; }
    .wk-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }
    .wk-alert.warn { background: #fffbeb; border-left: 4px solid #f59e0b; color: #b45309; }

    .wk-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 16px rgba(0,0,0,0.07);
        margin-bottom: 24px; overflow: hidden;
    }
    .wk-card-header {
        padding: 16px 24px; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        background: #f8faff;
    }
    .wk-card-header h2 {
        font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 9px;
    }
    .wk-card-header h2 i { color: #00AEEF; }
    .wk-badge {
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; font-size: 0.72rem; font-weight: 700;
        padding: 4px 12px; border-radius: 20px; letter-spacing: 0.4px;
    }

    .wk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; padding: 24px; }
    @media(max-width: 768px) { .wk-grid { grid-template-columns: 1fr; } }
    
    .wk-perf-box {
        border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;
        background: #fff;
    }
    .wk-perf-box h3 { margin: 0 0 12px 0; font-size: 0.95rem; color: #334155; display: flex; align-items: center; gap: 6px; }
    .wk-perf-box h3 i { color: #00AEEF; }

    /* Performance tables */
    .perf-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .perf-table th { text-align: left; padding: 10px; color: #64748b; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
    .perf-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
    .perf-table tbody tr:last-child td { border-bottom: none; }
    .perf-table tbody tr:hover td { background: #f8fafc; }

    /* Main Workers Table */
    .wk-table-wrap { overflow-x: auto; }
    .wk-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .wk-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .wk-table th {
        padding: 14px 20px; font-size: 0.73rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        text-align: left; white-space: nowrap; color: #fff;
    }
    .wk-table td {
        padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; vertical-align: middle;
    }
    .wk-table tbody tr:last-child td { border-bottom: none; }
    .wk-table tbody tr:hover td { background: #f0f7ff; transition: background .15s; }

    .wk-row-num {
        display: inline-flex; width: 28px; height: 28px; border-radius: 50%;
        background: #e0f2fe; color: #0369a1;
        font-size: 0.78rem; font-weight: 800;
        align-items: center; justify-content: center;
    }
    .worker-name {
        display: inline-flex; align-items: center; gap: 8px;
        font-weight: 700; color: #1e293b; font-size: 0.95rem;
    }
    .worker-name i { color: #00AEEF; font-size: 1.1rem; }

    /* Status Toggles */
    .status-form { display: inline; margin: 0; }
    .wk-status-btn {
        padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;
        border: none; cursor: pointer; transition: opacity .2s;
        display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .wk-status-btn.active { background: #d1fae5; color: #065f46; box-shadow: inset 0 0 0 1px #a7f3d0; }
    .wk-status-btn.inactive { background: #fee2e2; color: #991b1b; box-shadow: inset 0 0 0 1px #fecaca; }
    .wk-status-btn:hover { opacity: 0.8; }
    
    /* Action Buttons */
    .wk-actions-cell { display: flex; gap: 8px; align-items: center; justify-content: center; }
    .wk-btn-edit {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 8px;
        background: #f1f5f9; color: #1B3FA0; border: 1px solid #e2e8f0;
        transition: all .2s; text-decoration: none;
    }
    .wk-btn-edit:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }

    .wk-btn-del {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 8px;
        background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
        cursor: pointer; transition: all .2s;
    }
    .wk-btn-del:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .wk-empty { text-align: center; padding: 40px 20px; color: #94a3b8; }
    .wk-empty i { font-size: 2.8rem; opacity: 0.25; margin-bottom: 14px; display: block; }
    .wk-empty strong { display: block; font-size: 1rem; color: #64748b; margin-bottom: 6px; }

</style>

<div class="wk-page">

    <!-- Page Title -->
    <div class="wk-title">
        <div class="wk-title-left">
            <div class="wk-title-icon"><i class="fas fa-users-cog"></i></div>
            <div>
                <h1>Manage Workers</h1>
                <p>Add, manage, and track the performance of your washing bay staff.</p>
            </div>
        </div>
        <div class="wk-actions">
            <a href="employee_performance.php" class="wk-btn-secondary">
                <i class="fas fa-chart-bar"></i> Full Performance
            </a>
            <a href="add_worker.php" class="wk-btn-primary">
                <i class="fas fa-user-plus"></i> Add Worker
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="wk-alert ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="wk-alert err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Day Closure Alerts -->
    <?php if (!$inWorkWindow && !$dayClosed && $_SESSION['role'] === 'superadmin'): ?>
        <div class="wk-alert warn">
            <i class="fas fa-clock"></i> 
            <div style="flex:1;"><strong>After-hours:</strong> Today's worker performance is shown until you confirm end-of-day.</div>
            <a href="employees.php?close_day=1" class="wk-btn-primary" style="padding: 6px 14px; font-size: 0.8rem; box-shadow:none;">Confirm End of Day</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_day_closed'])): unset($_SESSION['flash_day_closed']); ?>
        <div class="wk-alert ok">
            <i class="fas fa-check-circle"></i> <strong>End of Day Confirmed.</strong> Today's report has been archived.
        </div>
    <?php endif; ?>

    <!-- Performance Overview Card -->
    <div class="wk-card">
        <div class="wk-card-header">
            <h2><i class="fas fa-medal"></i> Performance Overview</h2>
            <span style="font-size: 0.85rem; color: #64748b;">
                <?php echo $inWorkWindow ? 'Work hours (05:00–19:00)' : ($dayClosed ? 'Day closed' : 'After hours'); ?>
            </span>
        </div>
        <div class="wk-grid">
            <!-- Today's Top Workers -->
            <div class="wk-perf-box">
                <h3><i class="fas fa-calendar-day"></i> Today's Top Workers</h3>
                <div style="overflow-x:auto;">
                    <table class="perf-table">
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Washes</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($showDaily && !empty($topDailyWorkers)): ?>
                                <?php foreach ($topDailyWorkers as $w): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($w['worker_name']); ?></td>
                                        <td><?php echo (int)$w['washes']; ?></td>
                                        <td style="color:#059669; font-weight:600;">GHS <?php echo number_format((float)$w['total_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="color:#94a3b8; font-style:italic;">No data for today.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MTD Top Workers -->
            <div class="wk-perf-box">
                <h3><i class="fas fa-calendar-alt"></i> Month-to-Date Leaders</h3>
                <div style="overflow-x:auto;">
                    <table class="perf-table">
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Washes</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($topMonthlyWorkers)): ?>
                                <?php foreach ($topMonthlyWorkers as $w): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($w['worker_name']); ?></td>
                                        <td><?php echo (int)$w['washes']; ?></td>
                                        <td style="color:#059669; font-weight:600;">GHS <?php echo number_format((float)$w['total_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="color:#94a3b8; font-style:italic;">No data for this month.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- All Workers Table Card -->
    <div class="wk-card">
        <div class="wk-card-header">
            <h2><i class="fas fa-users"></i> All Workers</h2>
            <span class="wk-badge"><?php echo count($employees); ?> Worker<?php echo count($employees) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (empty($employees)): ?>
            <div class="wk-empty">
                <i class="fas fa-user-slash"></i>
                <strong>No workers found</strong>
                Use the "Add Worker" button above to add your first staff member.
            </div>
        <?php else: ?>
            <div class="wk-table-wrap">
                <table class="wk-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><i class="fas fa-user" style="margin-right:6px;opacity:.8;"></i>Name</th>
                            <th><i class="fas fa-phone" style="margin-right:6px;opacity:.8;"></i>Phone</th>
                            <th><i class="fas fa-calendar" style="margin-right:6px;opacity:.8;"></i>Joined</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $i => $emp): ?>
                        <tr>
                            <td><span class="wk-row-num"><?php echo $i + 1; ?></span></td>
                            <td>
                                <div class="worker-name">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($emp['full_name']); ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($emp['phone'])): ?>
                                    <span style="color:#475569; font-weight:600;"><?php echo htmlspecialchars($emp['phone']); ?></span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="color:#334155; font-size:0.87rem; font-weight:600;"><?php echo date('M j, Y', strtotime($emp['created_at'])); ?></div>
                            </td>
                            <td style="text-align:center;">
                                <form method="POST" class="status-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="worker_id" value="<?php echo $emp['id']; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $emp['status']; ?>">
                                    <button type="submit" name="toggle_status" class="wk-status-btn <?php echo $emp['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                        <?php if ($emp['status'] === 'active'): ?>
                                            <i class="fas fa-check-circle"></i> Active
                                        <?php else: ?>
                                            <i class="fas fa-times-circle"></i> Inactive
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td style="text-align:center;">
                                <div class="wk-actions-cell">
                                    <a href="edit_worker.php?id=<?php echo $emp['id']; ?>" class="wk-btn-edit" title="Edit Worker">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button onclick="confirmDelete(<?php echo $emp['id']; ?>)" class="wk-btn-del" title="Delete Worker">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function confirmDelete(workerId) {
    if (confirm('Are you sure you want to delete this worker? This action cannot be undone.')) {
        window.location.href = 'delete_worker.php?id=' + workerId;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
