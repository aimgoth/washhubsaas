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
        $conn->query("CREATE TABLE IF NOT EXISTS day_closures (\n          id INT AUTO_INCREMENT PRIMARY KEY,\n          report_date DATE NOT NULL UNIQUE,\n          closed_by INT NULL,\n          closed_at DATETIME NULL,\n          INDEX(report_date)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        if ($stmtAuto = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at) VALUES (CURDATE(), ?, NOW())\n                                       ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), closed_at = NOW()")) {
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

// Get all workers (from workers table)
$sql = "SELECT id, full_name, status, created_at 
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

<div class="container">
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
        $conn->query("CREATE TABLE IF NOT EXISTS day_closures (\n          id INT AUTO_INCREMENT PRIMARY KEY,\n          report_date DATE NOT NULL UNIQUE,\n          closed_by INT NULL,\n          closed_at DATETIME NULL,\n          INDEX(report_date)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
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

    <?php if (!$inWorkWindow && !$dayClosed && $_SESSION['role'] === 'superadmin'): ?>
        <div class="card" style="border-left: 4px solid #e67e22; background-color: #fff7ec;">
            <div style="display:flex; justify-content: space-between; align-items:center; gap: 10px;">
                <div>
                    <strong>After-hours:</strong> Today's worker performance is shown until you confirm end-of-day.
                </div>
                <a href="employees.php?close_day=1" class="btn" style="background-color:#e67e22;">Confirm End of Day</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_day_closed'])): unset($_SESSION['flash_day_closed']); ?>
        <div class="card" style="margin-bottom: 10px; border-left:4px solid #27ae60; background:#ecf9f1;">
            <div style="padding:12px 16px;">
                <strong><i class="fas fa-check-circle"></i> End of Day Confirmed.</strong> Today's report has been archived.
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-top: 10px;">
        <h2>Worker Performance</h2>
        <p style="margin:0 0 10px; color:#666; font-size:0.9em;">
            Status: <?php echo $inWorkWindow ? 'Within work hours (05:00–19:00)' : ($dayClosed ? 'Day closed' : 'After hours (showing until closed)'); ?>
        </p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
            <div>
                <h3 style="margin: 0 0 10px;">Today's Top Workers</h3>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee;">
                            <th style="padding:10px;">Worker</th>
                            <th style="padding:10px;">Washes</th>
                            <th style="padding:10px;">Amount (GHS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($showDaily && !empty($topDailyWorkers)): ?>
                            <?php foreach ($topDailyWorkers as $w): ?>
                                <tr style="border-bottom:1px solid #f2f2f2;">
                                    <td style="padding:10px;">&nbsp;<?php echo htmlspecialchars($w['worker_name']); ?></td>
                                    <td style="padding:10px; font-weight:600;">&nbsp;<?php echo (int)$w['washes']; ?></td>
                                    <td style="padding:10px;">GHS <?php echo number_format((float)$w['total_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="padding:10px; color:#666;">No data for today.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div>
                <h3 style="margin: 0 0 10px;">Month-to-Date Top Workers</h3>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee;">
                            <th style="padding:10px;">Worker</th>
                            <th style="padding:10px;">Washes</th>
                            <th style="padding:10px;">Amount (GHS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($topMonthlyWorkers)): ?>
                            <?php foreach ($topMonthlyWorkers as $w): ?>
                                <tr style="border-bottom:1px solid #f2f2f2;">
                                    <td style="padding:10px;">&nbsp;<?php echo htmlspecialchars($w['worker_name']); ?></td>
                                    <td style="padding:10px; font-weight:600;">&nbsp;<?php echo (int)$w['washes']; ?></td>
                                    <td style="padding:10px;">GHS <?php echo number_format((float)$w['total_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="padding:10px; color:#666;">No data for this month.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;">Manage Workers</h2>
            <div>
                <a href="employee_performance.php" class="btn btn-info" style="margin-right: 10px;">
                    <i class="fas fa-chart-line"></i> View Performance
                </a>
                <a href="workers.php" class="btn">
                    <i class="fas fa-user-plus"></i> Add New Worker
                </a>
            </div>
        </div>
        
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Workers Found</h3>
                    <p>No workers have been added yet. Get started by adding your first worker.</p>
                    <a href="workers.php" class="btn">
                        <i class="fas fa-user-plus"></i> Add Worker
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Date Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td><?php echo $employee['id']; ?></td>
                                    <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                    <td>N/A</td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="worker_id" value="<?php echo $employee['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $employee['status']; ?>">
                                            <button type="submit" name="toggle_status" class="status-badge <?php echo $employee['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo ucfirst($employee['status']); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($employee['created_at'])); ?></td>
                                    <td class="actions">
                                        <a href="workers.php?edit=<?php echo $employee['id']; ?>" class="btn-icon" title="Edit Worker">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $employee['id']; ?>)" class="btn-icon btn-delete" title="Delete Worker">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Status badges */
.status-badge {
    padding: 5px 10px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
}

.status-inactive {
    background-color: #f8d7da;
    color: #721c24;
}

.status-badge:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

/* Action buttons */
.actions {
    display: flex;
    gap: 8px;
    justify-content: center;
}

.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 4px;
    background-color: #f8f9fa;
    color: #333;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-icon:hover {
    background-color: #e9ecef;
    color: #000;
    transform: translateY(-1px);
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 3rem;
    color: #6c757d;
    opacity: 0.5;
    margin-bottom: 15px;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.empty-state p {
    color: #6c757d;
    margin-bottom: 20px;
}

/* Responsive table */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #333;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
}

tr:last-child td {
    border-bottom: none;
}

@media (max-width: 768px) {
    .actions {
        flex-direction: column;
        gap: 5px;
    }
    
    .btn-icon {
        width: 100%;
    }
}
</style>

<script>
function confirmDelete(workerId) {
    if (confirm('Are you sure you want to delete this worker? This action cannot be undone.')) {
        window.location.href = 'delete_worker.php?id=' + workerId;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
