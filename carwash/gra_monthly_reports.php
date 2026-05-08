<?php
require_once 'config/session.php';
require_once 'config/database.php';

$tablesAvailable = false;
$tableCheck = $conn->query("SHOW TABLES LIKE 'gra_monthly_reports'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $tablesAvailable = true;
}

function getTargetAmount(float $totalRevenue): float {
    if ($totalRevenue < 25000) {
        return 7000;
    }
    if ($totalRevenue < 30000) {
        return 9000;
    }
    if ($totalRevenue < 35000) {
        return 10000;
    }
    return 12000;
}

function generateLiveSample(mysqli $conn, string $start, string $end): array {
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0) as total FROM car_washes WHERE status = ? AND created_at >= ? AND created_at <= ?'
    );
    $status = 'completed';
    $stmt->bind_param('sss', $status, $start, $end);
    $stmt->execute();
    $totalRevenue = (float)(($stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $stmt->close();

    $targetAmount = getTargetAmount($totalRevenue);
    $sql = "SELECT cw.id,
                cw.amount,
                cw.created_at,
                cw.number_plate,
                COALESCE(w.full_name, 'N/A') AS washer_name,
                COALESCE(c.name, 'Unknown') AS category,
                COALESCE(cs.name, 'Regular') AS size,
                COALESCE(s.name, 'General Service') AS service,
                cw.created_at as wash_date
          FROM car_washes cw
          LEFT JOIN workers w ON cw.worker_id = w.id
          LEFT JOIN categories c ON cw.category_id = c.id
          LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
          LEFT JOIN services s ON cw.service_id = s.id
          WHERE cw.status = ?
            AND cw.created_at BETWEEN ? AND ?
          ORDER BY RAND()
          LIMIT 2500";
    $candidateStmt = $conn->prepare($sql);
    $candidateStmt->bind_param('sss', $status, $start, $end);
    $candidateStmt->execute();
    $candidates = $candidateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $candidateStmt->close();

    $selected = [];
    $accumulated = 0.0;
    foreach ($candidates as $candidate) {
        if ($accumulated >= $targetAmount) {
            break;
        }
        $amount = (float)$candidate['amount'];
        if ($amount <= 0) {
            continue;
        }
        $selected[] = $candidate;
        $accumulated += $amount;
    }
    if (empty($selected) && !empty($candidates)) {
        $selected[] = $candidates[0];
        $accumulated = (float)$candidates[0]['amount'];
    }

    foreach ($selected as &$row) {
        if (empty($row['wash_date'])) {
            $row['wash_date'] = $row['created_at'] ?? date('Y-m-d H:i:s');
        }
    }

    return [
        [
            'total_revenue' => $totalRevenue,
            'target_amount' => $targetAmount,
            'extracted_total' => $accumulated,
            'status' => 'live',
            'note' => 'Live extraction (schema missing)'
        ],
        $selected
    ];
}

if (session_status() === PHP_SESSION_NONE) {
    }

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    header('Location: login.php');
    exit();
}

$reports = [];
$liveSample = null;
$tableMessage = null;
if ($tablesAvailable) {
    $query = "SELECT g.*, COUNT(i.id) as item_count
          FROM gra_monthly_reports g
          LEFT JOIN gra_monthly_report_items i ON i.gra_report_id = g.id
          GROUP BY g.id
          ORDER BY g.report_year DESC, g.report_month DESC";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
    }
} else {
    $tableMessage = 'gra_monthly_reports table is missing; showing live extraction directly from car_washes.';
    list($liveSampleReport, $liveSampleRows) = generateLiveSample($conn, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59'));
    $liveSample = ['report' => $liveSampleReport, 'rows' => $liveSampleRows];
}

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$page_title = 'GRA Monthly Samples';
include 'includes/header.php';
?>
<style>
    .mr-page { max-width: 1100px; margin: 36px auto; padding: 0 20px 60px; }

    .mr-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .mr-title-left { display: flex; align-items: center; gap: 14px; }
    .mr-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .mr-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .mr-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }
    
    .mr-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .mr-btn-export {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 20px; background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-weight: 700; font-size: 0.92rem; text-decoration: none;
        transition: filter .2s, transform .15s; cursor: pointer;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .mr-btn-export:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }

    .mr-card {
        background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;
        box-shadow: 0 2px 16px rgba(0,0,0,0.07); margin-bottom: 24px; overflow: hidden;
    }
    .mr-card-header {
        padding: 16px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff;
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    }
    .mr-card-header h2 { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 9px; }
    .mr-card-header h2 i { color: #00AEEF; }

    /* Table */
    .mr-table-wrap { overflow-x: auto; }
    .mr-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .mr-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .mr-table th { padding: 14px 20px; font-size: 0.73rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; text-align: left; color: #fff; white-space: nowrap; }
    .mr-table td { padding: 14px 20px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
    .mr-table tbody tr:last-child td { border-bottom: none; }
    .mr-table tbody tr:hover td { background: #f0f7ff; }
    
    .mr-amt { font-weight: 700; color: #059669; }
    
    .mr-btn-view {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 6px 14px; background: #f1f5f9; color: #1B3FA0; border: 1.5px solid #e2e8f0;
        border-radius: 8px; font-weight: 700; font-size: 0.8rem; text-decoration: none;
        transition: all .2s;
    }
    .mr-btn-view:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }
</style>

<div class="mr-page">
    <div class="mr-title">
        <div class="mr-title-left">
            <div class="mr-title-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <h1>GRA Sample Records</h1>
                <p>All extracted subsets prepared for the Ghana Revenue Authority.</p>
            </div>
        </div>
        <div class="mr-actions">
            <a href="gra_monthly_report.php" class="mr-btn-export">
                <i class="fas fa-plus"></i> Generate / Refresh Sample
            </a>
        </div>
    </div>

    <div class="mr-card">
        <div class="mr-card-header">
            <h2><i class="fas fa-history"></i> Generated Samples</h2>
        </div>
        <div class="mr-table-wrap">
            <table class="mr-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Year</th>
                        <th style="text-align:right;">Revenue</th>
                        <th style="text-align:right;">Target Extract</th>
                        <th style="text-align:right;">Extracted</th>
                        <th style="text-align:right;">Records</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding: 40px; color:#94a3b8;">
                                No GRA samples have been generated yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td style="font-weight:700;"><?php echo htmlspecialchars($months[$report['report_month']] ?? 'Unknown'); ?></td>
                                <td><?php echo (int)$report['report_year']; ?></td>
                                <td style="text-align:right;">GHS <?php echo number_format($report['total_revenue'], 2); ?></td>
                                <td style="text-align:right;">GHS <?php echo number_format($report['target_amount'], 2); ?></td>
                                <td style="text-align:right;" class="mr-amt">GHS <?php echo number_format($report['extracted_total'], 2); ?></td>
                                <td style="text-align:right; font-weight:700;"><?php echo (int)$report['item_count']; ?></td>
                                <td>
                                    <span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:700;">
                                        <?php echo htmlspecialchars(ucfirst($report['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="gra_monthly_report_detail.php?id=<?php echo $report['id']; ?>" class="mr-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
