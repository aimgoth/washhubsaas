<?php
session_start();

// Admin or Super Admin can view, but this page is for Admin submission UX
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';
require_once __DIR__ . '/includes/csrf.php';

$today = date('Y-m-d');

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

// Removed legacy tmp flag fallback; DB is source of truth

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
    $stmtSR = $conn->prepare("SELECT report_date, total_cars_washed, total_motors_washed, total_carpets_washed, gross_amount_total, submitted_at
                               FROM daily_reports WHERE report_date = CURDATE() LIMIT 1");
    $stmtSR->execute();
    $resSR = $stmtSR->get_result();
    if ($resSR && ($rpt = $resSR->fetch_assoc())) { $submitted = $rpt; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

include 'includes/header.php';
?>

<div class="container">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <h1 style="margin:0;">Admin Daily Report</h1>
        <?php if ($dayClosed): ?>
            <span class="badge" style="background:#27ae60; color:#fff; padding:6px 10px; border-radius:9999px; font-size:0.9rem;">Day Closed</span>
        <?php endif; ?>
    </div>

    <?php if ($dayClosed): ?>
        <div class="card" style="margin:16px 0; border-left:4px solid #27ae60; background:#f6fff8;">
            <div style="padding:12px 16px;">
                <strong>Day Closed:</strong> You cannot submit; Super Admin has confirmed today's report.
            </div>
        </div>
    <?php elseif ($submitted): ?>
        <?php /* Removed submitted notice banner to reduce noise after submission. */ ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div class="card" style="background: linear-gradient(135deg, #3498DB, #2980B9); color: white; text-align:center;">
                <div style="padding: 20px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                    <h3 style="margin: 0 0 8px;">Today's Amount</h3>
                    <div style="font-size: 2.4rem; font-weight: 700; line-height: 1.2; margin: 6px 0;">GHS <?php echo number_format((float)$submitted['gross_amount_total'], 2); ?></div>
                    <div style="opacity: 0.9; margin-top: 6px;"><i class="fas fa-calendar-day"></i> <?php echo date('M j, Y', strtotime($submitted['report_date'])); ?></div>
                </div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #1ABC9C, #16A085); color: white; text-align:center;">
                <div style="padding: 20px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                    <h3 style="margin: 0 0 8px;">Today's Cars</h3>
                    <div style="font-size: 2.6rem; font-weight: 700; line-height: 1.2; margin: 6px 0;"><?php echo number_format((int)$submitted['total_cars_washed']); ?></div>
                    <div style="opacity: 0.9; margin-top: 6px;"><i class="fas fa-car"></i> Submitted</div>
                </div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #f39c12, #e67e22); color: white; text-align:center;">
                <div style="padding: 20px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                    <h3 style="margin: 0 0 8px;">Today's Motors</h3>
                    <div style="font-size: 2.6rem; font-weight: 700; line-height: 1.2; margin: 6px 0;"><?php echo number_format((int)($submitted['total_motors_washed'] ?? 0)); ?></div>
                    <div style="opacity: 0.9; margin-top: 6px;"><i class="fas fa-motorcycle"></i> Submitted</div>
                </div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #9B59B6, #8E44AD); color: white; text-align:center;">
                <div style="padding: 20px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                    <h3 style="margin: 0 0 8px;">Today's Carpets</h3>
                    <div style="font-size: 2.6rem; font-weight: 700; line-height: 1.2; margin: 6px 0;"><?php echo number_format((int)$submitted['total_carpets_washed']); ?></div>
                    <div style="opacity: 0.9; margin-top: 6px;"><i class="fas fa-broom"></i> Submitted</div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="margin:16px 0; border-left:4px solid #f39c12; background:#fffaf2;">
            <div style="padding:12px 16px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
                <div>
                    <strong>No submitted daily report for today yet.</strong>
                    <div style="font-size:0.9rem; opacity:0.85;">Click submit to generate totals from today's washes.</div>
                </div>
                <form method="post" action="submit_daily_report.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="submit_daily_report" value="1" />
                    <button type="submit" class="btn">Submit Daily Report</button>
                </form>
            </div>
        </div>
        
        <!-- Today's Activities with Delay Reasons -->
        <div class="card" style="margin-bottom: 30px;">
            <h3>Today's Activities</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: var(--primary-color); color: white;">
                            <th style="padding: 12px; text-align: left;">Time</th>
                            <th style="padding: 12px; text-align: left;">Service</th>
                            <th style="padding: 12px; text-align: left;">Worker</th>
                            <th style="padding: 12px; text-align: left;">Plate</th>
                            <th style="padding: 12px; text-align: right;">Amount</th>
                            <th style="padding: 12px; text-align: left;">Status</th>
                            <th style="padding: 12px; text-align: left;">Delay Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get today's activities with delay reasons
                        $sql = "SELECT cw.*, s.name AS service_name, w.full_name AS worker_name, 
                                       dr.reason as delay_reason
                                FROM car_washes cw
                                LEFT JOIN services s ON cw.service_id = s.id
                                LEFT JOIN workers w ON cw.worker_id = w.id
                                LEFT JOIN delay_reasons dr ON cw.delay_reason_id = dr.id
                                WHERE DATE(cw.created_at) = CURDATE()
                                ORDER BY cw.created_at DESC";
                        $result = $conn->query($sql);
                        
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $time = !empty($row['completed_at']) ? date('h:i A', strtotime($row['completed_at'])) : 
                                       (!empty($row['started_at']) ? date('h:i A', strtotime($row['started_at'])) : '');
                                $status = !empty($row['completed_at']) ? 'Completed' : 'In Progress';
                                $delay_reason = !empty($row['delay_reason']) ? 
                                    '<span style="color: #e74c3c; font-weight: 500;">' . htmlspecialchars($row['delay_reason']) . 
                                    (!empty($row['delay_notes']) ? '<br><small style="color: #7f8c8d;">' . htmlspecialchars($row['delay_notes']) . '</small>' : '') . 
                                    '</span>' : 'None';
                                
                                echo "<tr style=\"border-bottom: 1px solid #eee;\">";
                                echo "<td style=\"padding: 12px;\">" . htmlspecialchars($time) . "</td>";
                                echo "<td style=\"padding: 12px;\">" . htmlspecialchars($row['service_name'] ?? 'N/A') . "</td>";
                                echo "<td style=\"padding: 12px;\">" . htmlspecialchars($row['worker_name'] ?? 'N/A') . "</td>";
                                echo "<td style=\"padding: 12px;\">" . htmlspecialchars($row['number_plate'] ?? '') . "</td>";
                                echo "<td style=\"padding: 12px; text-align: right;\">GHS " . number_format($row['amount'] ?? 0, 2) . "</td>";
                                echo "<td style=\"padding: 12px;\">" . $status . "</td>";
                                echo "<td style=\"padding: 12px;\">" . $delay_reason . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' style='padding: 20px; text-align: center; color: #7f8c8d;'>No activities found for today.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
