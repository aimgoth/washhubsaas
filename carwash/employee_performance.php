<?php
session_start();

// Check if user is logged in and has admin rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

// Get month and year from URL or use current month
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month and year
$month = max(1, min(12, $month));
$year = max(2020, min(2100, $year));

// Get month name
$monthName = date('F', mktime(0, 0, 0, $month, 1));

// Get all workers
$workers = [];
$sql = "SELECT id, full_name FROM workers ORDER BY full_name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $workers[] = $row;
    }
}

// Get performance data for all workers
$performanceData = [];
$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate)) . ' 23:59:59';

// First, get all performance data
foreach ($workers as $worker) {
    $sql = "SELECT 
                COUNT(*) as total_washes,
                COALESCE(SUM(amount), 0) as total_revenue,
                COALESCE(AVG(amount), 0) as avg_revenue_per_wash,
                COUNT(DISTINCT DATE(created_at)) as days_worked,
                SUM(CASE WHEN payment_confirmed = 1 THEN 1 ELSE 0 END) as confirmed_washes
            FROM car_washes 
            WHERE worker_id = ? 
            AND created_at >= ? 
            AND created_at <= ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", 
        $worker['id'], 
        $startDate, 
        $endDate
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $performanceData[$worker['id']] = array_merge($worker, $row);
        // Calculate additional metrics
        $performanceData[$worker['id']]['washes_per_day'] = ($row['days_worked'] ?? 0) > 0 
            ? round(($row['total_washes'] ?? 0) / $row['days_worked'], 1) 
            : 0;

        // Service breakdown for this worker within the selected period
        $sql2 = "SELECT s.name AS service_name, COUNT(*) AS cnt\n"
              . "FROM car_washes cw\n"
              . "LEFT JOIN services s ON cw.service_id = s.id\n"
              . "WHERE cw.worker_id = ?\n"
              . "  AND cw.created_at >= ?\n"
              . "  AND cw.created_at <= ?\n"
              . "GROUP BY cw.service_id\n"
              . "ORDER BY cnt DESC";
        if ($stmt2 = $conn->prepare($sql2)) {
            $stmt2->bind_param("iss", $worker['id'], $startDate, $endDate);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $serviceCounts = [];
            if ($res2) {
                while ($r2 = $res2->fetch_assoc()) {
                    $label = $r2['service_name'] ?? 'Unknown';
                    $serviceCounts[] = [
                        'name' => $label,
                        'count' => (int)($r2['cnt'] ?? 0)
                    ];
                }
            }
            $performanceData[$worker['id']]['service_counts'] = $serviceCounts;
        }
    }
}

// Sort workers by total_washes in descending order for ranking
usort($performanceData, function($a, $b) {
    return $b['total_washes'] <=> $a['total_washes'];
});

// Add ranking
$rank = 1;
$prevWashes = null;
$prevRank = 1;

foreach ($performanceData as $key => $worker) {
    if ($worker['total_washes'] === 0) {
        $performanceData[$key]['rank'] = '-';
        continue;
    }
    
    if ($prevWashes !== null && $worker['total_washes'] < $prevWashes) {
        $rank = $prevRank + 1;
    }
    
    $performanceData[$key]['rank'] = $rank;
    $prevWashes = $worker['total_washes'];
    $prevRank = $rank;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Performance - Car Wash Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .performance-card {
            border-left: 4px solid #4e73df;
            margin-bottom: 20px;
            border-radius: 4px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        .performance-header {
            border-bottom: 1px solid #e3e6f0;
            padding: 1rem;
            background-color: #f8f9fc;
            font-weight: 600;
        }
        .performance-body {
            padding: 1rem;
        }
        .metric {
            margin-bottom: 1rem;
        }
        .metric-label {
            font-size: 0.8rem;
            color: #5a5c69;
            text-transform: uppercase;
            font-weight: 700;
        }
        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #4e73df;
        }
        .date-selector {
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fc;
            border-radius: 4px;
        }
        .service-breakdown {
            font-size: 1.2rem;
            font-weight: 700;
        }
        /* Print styles */
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .navbar, .date-selector, .btn, .btn-group, .actions, a[href^="employees.php"], a[href^="washes.php"], .alert { display: none !important; }
            .container { max-width: 100% !important; }
            .row { gap: 0 !important; }
            .card { box-shadow: none !important; }
            .performance-card { page-break-inside: avoid; break-inside: avoid; margin-bottom: 8px; }
            h2 { margin-bottom: 6px; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Employee Performance - <?php echo $monthName . ' ' . $year; ?></h2>
            <div class="btn-group">
                <a href="employees.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Employees
                </a>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>

        <!-- Month/Year Selector -->
        <div class="date-selector">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <select name="month" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">View Report</button>
                </div>
            </form>
        </div>

        <!-- Performance Cards -->
        <div class="row">
            <?php foreach ($performanceData as $employee): 
                if (($employee['total_washes'] ?? 0) <= 0) continue; // Skip employees with no washes
            ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card performance-card h-100">
                        <div class="performance-header d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge bg-primary me-2">#<?php echo $employee['rank']; ?></span>
                                <h5 class="mb-0 d-inline"><?php echo htmlspecialchars($employee['full_name']); ?></h5>
                                <small class="d-block text-muted">
                                    <?php 
                                        $daysWorked = $employee['days_worked'] ?? 0;
                                        echo $daysWorked . ' ' . ($daysWorked == 1 ? 'day' : 'days') . ' worked';
                                    ?>
                                </small>
                            </div>
                            <?php if ($employee['rank'] == 1 && $employee['total_washes'] > 0): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-trophy"></i> Top Performer
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 metric">
                                    <div class="metric-label">Total Washes</div>
                                    <div class="metric-value"><?php echo $employee['total_washes'] ?? 0; ?></div>
                                </div>
                                <div class="col-6 metric">
                                    <div class="metric-label">Avg. Washes/Day</div>
                                    <div class="metric-value"><?php echo $employee['washes_per_day'] ?? 0; ?></div>
                                </div>
                                <div class="col-6 metric">
                                    <div class="metric-label">Total Revenue</div>
                                    <div class="metric-value">GHS <?php echo number_format($employee['total_revenue'] ?? 0, 2); ?></div>
                                </div>
                                <div class="col-6 metric">
                                    <div class="metric-label">Avg. Revenue/Wash</div>
                                    <div class="metric-value">GHS <?php echo number_format($employee['avg_revenue_per_wash'] ?? 0, 2); ?></div>
                                </div>
                                <div class="col-12 metric">
                                    <div class="metric-label">Payment Confirmed</div>
                                    <div class="metric-value"><?php echo $employee['confirmed_washes'] ?? 0; ?> / <?php echo $employee['total_washes'] ?? 0; ?></div>
                                </div>

                                <div class="col-12 metric">
                                    <div class="metric-label">By Service</div>
                                    <ul class="list-unstyled mb-0 service-breakdown">
                                        <?php if (!empty($employee['service_counts'])): ?>
                                            <?php foreach ($employee['service_counts'] as $sc): ?>
                                                <li>
                                                    <?php echo htmlspecialchars($sc['name']); ?>:
                                                    <strong><?php echo (int)$sc['count']; ?></strong>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <li>None</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($performanceData)): ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        No performance data available for the selected period.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
