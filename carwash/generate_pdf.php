<?php
// Start the session first
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include configuration files
require_once 'config/database.php';

// Check if user is logged in as superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    } else {
        header('Location: index.php?error=unauthorized');
    }
    exit();
}

// Check if date parameter is provided
if (!isset($_GET['date']) || empty($_GET['date'])) {
    die('Date parameter is required');
}

$date = $_GET['date'];

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die('Invalid date format. Use YYYY-MM-DD');
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

// Detect submitted_at availability; fallback to created_at
$hasSubmittedAt = false;
$colCheck = @$conn->query("SHOW COLUMNS FROM daily_reports LIKE 'submitted_at'");
if ($colCheck && $colCheck->num_rows > 0) { 
    $hasSubmittedAt = true; 
}
$submittedExpr = $hasSubmittedAt ? 'submitted_at' : 'created_at';

// Get report data
$sql = "SELECT 
            report_date,
            total_cars_washed,
            total_motors_washed,
            total_carpets_washed,
            gross_amount_total,
            revenue_two_thirds_total,
            created_by,
            $submittedExpr AS submitted_at,
            created_at
        FROM daily_reports
        WHERE report_date = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $date);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('No report found for the selected date');
}

$report = $result->fetch_assoc();

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

// Get wash details
$sql = "SELECT 
            cw.number_plate as plate_number,
            s.name as service_name,
            cs.name as car_size_name,
            cw.amount,
            w.full_name as worker_name,
            cw.completed_at
        FROM car_washes cw
        LEFT JOIN services s ON cw.service_id = s.id
        LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
        LEFT JOIN workers w ON cw.worker_id = w.id
        WHERE DATE(cw.created_at) = ?
        ORDER BY cw.completed_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $date);
$stmt->execute();
$wash_result = $stmt->get_result();

// Get Worker Performance Rankings
$rankings = [];
$inactive_workers = [];

$rank_sql = "SELECT w.full_name, 
                    COUNT(cw.id) as wash_count, 
                    SUM(cw.amount) as total_revenue
             FROM workers w
             LEFT JOIN car_washes cw ON w.id = cw.worker_id AND DATE(cw.created_at) = ?
             WHERE w.status = 'active'
             GROUP BY w.id
             ORDER BY total_revenue DESC, wash_count DESC";

$stmt_rank = $conn->prepare($rank_sql);
$stmt_rank->bind_param('s', $date);
$stmt_rank->execute();
$rank_res = $stmt_rank->get_result();

while ($r = $rank_res->fetch_assoc()) {
    if ($r['wash_count'] > 0) {
        $rankings[] = $r;
    } else {
        $inactive_workers[] = $r['full_name'];
    }
}

// Categorize Performers
$top_performer = !empty($rankings) ? $rankings[0] : null;
$lowest_performer = (count($rankings) > 1) ? $rankings[count($rankings) - 1] : null;
$middle_performer = (count($rankings) > 2) ? $rankings[floor(count($rankings) / 2)] : null;

// Get App Branding
$appName = getenv('APP_NAME') ?: 'WashHub';
$companyName = $bayName;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Report - <?php echo htmlspecialchars($date); ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../frontend/new logo.png?v=washhub">
    <!-- PDF Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        :root {
            --primary: #1B3FA0;       /* Navy Brand Color */
            --accent: #00AEEF;        /* Cyan Brand Color */
            --success: #059669;
            --text: #1e293b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            color: var(--text);
            line-height: 1.5;
            background: #fff;
            padding: 40px;
        }

        /* Paper Layout */
        .report-paper {
            width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
        }

        /* Premium Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2.5px solid var(--primary);
            padding-bottom: 25px;
            margin-bottom: 35px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-left img {
            height: 60px;
            width: auto;
        }

        .header-left .brand-info {
            display: flex;
            flex-direction: column;
        }

        .header-left .saas-name {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .header-left .saas-sub {
            font-size: 13px;
            color: var(--accent);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .header-right { text-align: right; }
        .header-right .company-name {
            font-size: 24px;
            font-weight: 900;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 2px;
            letter-spacing: 0.5px;
            word-wrap: break-word;
            max-width: 400px;
        }
        .header-right .report-title {
            font-size: 14px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        /* Info Strip */
        .info-strip {
            display: flex;
            justify-content: space-between;
            background: var(--light);
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 35px;
            border: 1px solid var(--border);
        }
        .info-item .label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .info-item .value {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
        }

        /* Summary Grid */
        .summary-title {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .summary-title::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 35px;
        }
        .stat-card {
            padding: 20px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            text-align: center;
        }
        .stat-card.highlight {
            background: linear-gradient(135deg, #065f46, #059669);
            border: none;
            color: #fff;
        }
        .stat-card .s-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            opacity: 0.8;
            margin-bottom: 8px;
        }
        .stat-card .s-value {
            font-size: 22px;
            font-weight: 800;
        }

        /* Tables */
        .table-container { margin-bottom: 40px; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            table-layout: fixed;
        }
        th, td { 
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        th {
            text-align: left;
            padding: 14px 16px;
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }
        tr:nth-child(even) { background: #fafafa; }
        
        .plate-box {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-weight: 700;
            font-size: 12px;
        }

        .amt-text { font-weight: 800; color: var(--primary); }
        .closing-amt { font-weight: 900; color: var(--success); }

        /* Footer */
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }

        /* Print Controls */
        .controls {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
        }
        .print-btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .print-btn:hover { background: var(--accent); }

        @media print {
            body { padding: 0; }
            .controls { display: none; }
            .report-paper { max-width: 100%; }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

    <div id="pdf-content" class="report-paper">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <img src="../frontend/new logo.png" alt="WashHub Logo">
                <div class="brand-info">
                    <span class="saas-name">WashHub</span>
                    <span class="saas-sub">Washing Bay Management Software</span>
                </div>
            </div>
            <div class="header-right">
                <h1 class="company-name"><?php echo htmlspecialchars($bayName); ?></h1>
                <div class="report-title">Daily Operations Report</div>
            </div>
        </div>

        <!-- Info Strip -->
        <div class="info-strip">
            <div class="info-item">
                <div class="label">Report Date</div>
                <div class="value"><?php echo date('l, F j, Y', strtotime($date)); ?></div>
            </div>
            <div class="info-item" style="text-align: center;">
                <div class="label">Status</div>
                <div class="value" style="color: var(--success);">
                    <i class="fas fa-check-circle"></i> Finalized
                </div>
            </div>
            <div class="info-item" style="text-align: right;">
                <div class="label">Submitted At</div>
                <div class="value">
                    <?php 
                    $dispTime = !empty($report['submitted_at']) ? $report['submitted_at'] : ($report['created_at'] ?? null);
                    echo $dispTime ? date('g:i A', strtotime($dispTime)) : 'N/A'; 
                    ?>
                </div>
            </div>
        </div>

        <!-- Performance Summary -->
        <h3 class="summary-title">Operational Summary</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="s-label">Total Services</div>
                <div class="s-value"><?php echo number_format($report['total_cars_washed'] + $report['total_motors_washed'] + $report['total_carpets_washed']); ?></div>
            </div>
            <div class="stat-card">
                <div class="s-label">Total Gross</div>
                <div class="s-value">GHS <?php echo number_format($report['gross_amount_total'], 2); ?></div>
            </div>
            <div class="stat-card highlight">
                <div class="s-label">Closing Amount (<?php echo round($company_pct, 1); ?>%)</div>
                <div class="s-value">GHS <?php echo number_format($report['revenue_two_thirds_total'], 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="s-label">Worker Share (<?php echo round($worker_pct, 1); ?>%)</div>
                <div class="s-value">GHS <?php echo number_format($report['gross_amount_total'] - $report['revenue_two_thirds_total'], 2); ?></div>
            </div>
        </div>

        <!-- Breakdown Table -->
        <h3 class="summary-title">Category Breakdown</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th style="text-align: center;">Count</th>
                        <th style="text-align: right;">Gross Revenue</th>
                        <th style="text-align: right;">Closing (<?php echo round($company_pct, 1); ?>%)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Cars / Vehicles</strong></td>
                        <td style="text-align: center; font-weight: 700;"><?php echo $report['total_cars_washed']; ?></td>
                        <td style="text-align: right;" class="amt-text">GHS <?php echo number_format($report['gross_amount_total'] - (float)($report['total_motors_washed']*10) - (float)($report['total_carpets_washed']*15), 2); // Approximation for breakdown ?></td>
                        <td style="text-align: right;" class="closing-amt">GHS <?php echo number_format(($report['gross_amount_total'] - ($report['total_motors_washed']*10) - ($report['total_carpets_washed']*15)) * ($company_pct/100), 2); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Motorcycles</strong></td>
                        <td style="text-align: center; font-weight: 700;"><?php echo $report['total_motors_washed']; ?></td>
                        <td style="text-align: right;" class="amt-text">GHS <?php echo number_format($report['total_motors_washed'] * 10, 2); ?></td>
                        <td style="text-align: right;" class="closing-amt">GHS <?php echo number_format(($report['total_motors_washed'] * 10) * ($company_pct/100), 2); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Carpets / Mats</strong></td>
                        <td style="text-align: center; font-weight: 700;"><?php echo $report['total_carpets_washed']; ?></td>
                        <td style="text-align: right;" class="amt-text">GHS <?php echo number_format($report['total_carpets_washed'] * 15, 2); ?></td>
                        <td style="text-align: right;" class="closing-amt">GHS <?php echo number_format(($report['total_carpets_washed'] * 15) * ($company_pct/100), 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Detailed Log -->
        <?php if ($wash_result->num_rows > 0): ?>
        
        <!-- Worker Performance Section -->
        <h3 class="summary-title">Daily Performance Ranking</h3>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 25px;">
            <!-- Top Performer -->
            <div style="padding: 15px; border-radius: 12px; border: 1.5px solid #059669; background: #ecfdf5; text-align: center;">
                <div style="font-size: 10px; font-weight: 800; color: #059669; text-transform: uppercase; margin-bottom: 5px;">
                    <i class="fas fa-trophy"></i> Top Performer
                </div>
                <div style="font-size: 16px; font-weight: 800; color: #064e3b;"><?php echo $top_performer ? htmlspecialchars($top_performer['full_name']) : 'N/A'; ?></div>
                <div style="font-size: 11px; font-weight: 700; color: #059669; margin-top: 3px;">
                    <?php echo $top_performer ? $top_performer['wash_count'] . ' washes | GHS ' . number_format($top_performer['total_revenue'], 2) : '-'; ?>
                </div>
            </div>

            <!-- Middle Performer -->
            <div style="padding: 15px; border-radius: 12px; border: 1.5px solid #1B3FA0; background: #eff6ff; text-align: center;">
                <div style="font-size: 10px; font-weight: 800; color: #1B3FA0; text-transform: uppercase; margin-bottom: 5px;">
                    <i class="fas fa-medal"></i> Mid Performer
                </div>
                <div style="font-size: 16px; font-weight: 800; color: #172554;"><?php echo $middle_performer ? htmlspecialchars($middle_performer['full_name']) : ($top_performer ? 'None' : 'N/A'); ?></div>
                <div style="font-size: 11px; font-weight: 700; color: #1B3FA0; margin-top: 3px;">
                    <?php echo $middle_performer ? $middle_performer['wash_count'] . ' washes | GHS ' . number_format($middle_performer['total_revenue'], 2) : '-'; ?>
                </div>
            </div>

            <!-- Lowest Performer -->
            <div style="padding: 15px; border-radius: 12px; border: 1.5px solid #eab308; background: #fefce8; text-align: center;">
                <div style="font-size: 10px; font-weight: 800; color: #854d0e; text-transform: uppercase; margin-bottom: 5px;">
                    <i class="fas fa-star-half-alt"></i> Emerging
                </div>
                <div style="font-size: 16px; font-weight: 800; color: #713f12;"><?php echo $lowest_performer ? htmlspecialchars($lowest_performer['full_name']) : ($top_performer ? 'None' : 'N/A'); ?></div>
                <div style="font-size: 11px; font-weight: 700; color: #854d0e; margin-top: 3px;">
                    <?php echo $lowest_performer ? $lowest_performer['wash_count'] . ' washes | GHS ' . number_format($lowest_performer['total_revenue'], 2) : '-'; ?>
                </div>
            </div>
        </div>

        <!-- Inactive Workers -->
        <?php if (!empty($inactive_workers)): ?>
        <div style="margin-bottom: 30px; padding: 12px 20px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
            <span style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase;">Inactive Workers (Off Duty):</span>
            <span style="font-size: 12px; color: #475569; font-weight: 600; margin-left: 10px;">
                <?php echo implode(', ', array_map('htmlspecialchars', $inactive_workers)); ?>
            </span>
        </div>
        <?php endif; ?>

        <h3 class="summary-title">Detailed Operational Log</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Vehicle</th>
                        <th>Washer</th>
                        <th style="text-align: right;">Gross</th>
                        <th style="text-align: right;">Closing</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($wash = $wash_result->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight: 700;"><?php echo htmlspecialchars($wash['service_name'] ?: 'N/A'); ?></td>
                        <td><span class="plate-box"><?php echo htmlspecialchars($wash['plate_number'] ?: 'N/A'); ?></span></td>
                        <td><?php echo htmlspecialchars($wash['worker_name'] ?: 'Unassigned'); ?></td>
                        <td style="text-align: right;" class="amt-text">GHS <?php echo number_format($wash['amount'], 2); ?></td>
                        <td style="text-align: right;" class="closing-amt">GHS <?php echo number_format($wash['amount'] * ($company_pct/100), 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p><strong><?php echo htmlspecialchars($appName); ?> SaaS Ecosystem</strong> — Intelligence in Motion</p>
            <p>Generated on <?php echo date('Y-m-d H:i:s'); ?>. All data is securely archived and verified.</p>
        </div>
    </div> <!-- End #pdf-content -->

    <!-- Controls -->
    <div class="controls no-print">
        <button onclick="downloadPDF(this)" class="print-btn" style="cursor:pointer; border:none;">
            <i class="fas fa-file-pdf"></i> Download Report PDF
        </button>
    </div>

    <script>
        async function downloadPDF(btn) {
            const { jsPDF } = window.jspdf;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
            btn.disabled = true;

            const element = document.getElementById('pdf-content');
            
            try {
                const canvas = await html2canvas(element, {
                    scale: 3, // Increased scale for even sharper text
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff',
                    windowWidth: 850 // Force width during capture
                });

                const imgData = canvas.toDataURL('image/png', 1.0);
                const pdf = new jsPDF('p', 'mm', 'a4');
                
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                
                // Add 10mm margins
                const margin = 10;
                const contentWidth = pdfWidth - (2 * margin);
                
                const imgProps = pdf.getImageProperties(imgData);
                const contentHeight = (imgProps.height * contentWidth) / imgProps.width;

                pdf.addImage(imgData, 'PNG', margin, margin, contentWidth, contentHeight);
                pdf.save(`WashHub_Report_<?php echo $date; ?>.pdf`);
            } catch (error) {
                console.error('PDF Generation Error:', error);
                alert('Error generating PDF. Please try again.');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    </script>

</body>
</html>
<?php
$conn->close();
?>
