<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/export_errors.log');

// Start session at the very beginning

// Debug: Log session data
error_log('Session data: ' . print_r($_SESSION, true));

// Include session configuration after session_start()
require_once 'config/session.php';
require_once 'config/database.php';

// Debug: Log session after including session.php
error_log('Session after including session.php: ' . print_r($_SESSION, true));

// Only admins can export reports
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    error_log('Access denied - User not logged in or not admin');
    // If it's an AJAX request, return JSON error
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized access']);
    } else {
        // Redirect to login with return URL
        $return_url = urlencode($_SERVER['REQUEST_URI']);
        header('Location: login.php?return=' . $return_url);
    }
    exit();
}

// Get month and year from either POST or GET
$year = isset($_GET['year']) ? (int)$_GET['year'] : (isset($_POST['year']) ? (int)$_POST['year'] : date('Y'));
$month = isset($_GET['month']) ? (int)$_GET['month'] : (isset($_POST['month']) ? (int)$_POST['month'] : date('n'));

// Log the request
error_log("Export request - Month: $month, Year: $year");

// Set headers for HTML output
header('Content-Type: text/html; charset=utf-8');

require_once 'config/database.php';

// Get monthly summary
$monthlyData = [];
$monthlyTotal = [
    'total_amount' => 0,
    'total_vehicles' => 0,
    'cars' => 0,
    'motors' => 0,
    'carpets' => 0
];

$query = "
    SELECT 
        s.name as service_name,
        COUNT(cw.id) as total_vehicles,
        SUM(CASE 
            WHEN LOWER(cs.name) LIKE '%car%' OR LOWER(s.name) LIKE '%car%' THEN 1 
            WHEN LOWER(cs.name) LIKE '%motor%' OR LOWER(cs.name) LIKE '%bike%' OR LOWER(s.name) LIKE '%motor%' OR LOWER(s.name) LIKE '%bike%' THEN 0
            WHEN LOWER(cs.name) LIKE '%carpet%' OR LOWER(s.name) LIKE '%carpet%' THEN 0
            ELSE 1 
        END) as cars,
        SUM(CASE 
            WHEN LOWER(cs.name) LIKE '%motor%' OR LOWER(cs.name) LIKE '%bike%' OR LOWER(s.name) LIKE '%motor%' OR LOWER(s.name) LIKE '%bike%' THEN 1 
            ELSE 0 
        END) as motors,
        SUM(CASE 
            WHEN LOWER(cs.name) LIKE '%carpet%' OR LOWER(s.name) LIKE '%carpet%' THEN 1 
            ELSE 0 
        END) as carpets,
        SUM(cw.amount) as total_amount
    FROM car_washes cw
    LEFT JOIN services s ON cw.service_id = s.id
    LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
    WHERE MONTH(cw.created_at) = ? 
    AND YEAR(cw.created_at) = ?
    AND cw.status = 'completed'
    GROUP BY s.id, s.name
    ORDER BY total_vehicles DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $month, $year);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $monthlyData[] = $row;
    $monthlyTotal['total_amount'] += $row['total_amount'];
    $monthlyTotal['total_vehicles'] += $row['total_vehicles'];
    $monthlyTotal['cars'] += $row['cars'];
    $monthlyTotal['motors'] += $row['motors'];
    $monthlyTotal['carpets'] += $row['carpets'];
}

// Get month name
$monthName = date('F', mktime(0, 0, 0, $month, 1));

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=monthly_report_' . $monthName . '_' . $year . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for proper UTF-8 Excel handling
fputs($output, "\xEF\xBB\xBF");

// Add headers
fputcsv($output, ['Monthly Report - ' . $monthName . ' ' . $year]);
fputcsv($output, []); // Empty row

// Add summary

// HTML output
?>
<!DOCTYPE html>
<html>
    <meta charset="UTF-8">
    <title>Monthly Report - <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        h1, h2 {
            margin: 5px 0;
            color: #2c3e50;
        }
        .summary {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin: 20px 0;
            gap: 10px;
        }
        .summary-item {
            flex: 1;
            min-width: 150px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary-item .value {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #7f8c8d;
        }
        @media print {
            body { margin: 0; padding: 20px; }
            .no-print { display: none; }
            .summary { break-inside: avoid; }
            table { break-inside: auto; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Monthly Wash Report</h1>
        <h2><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></h2>
        <div class="no-print">
            <button onclick="window.print()" style="padding: 8px 16px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">
                Print / Save as PDF
            </button>
        </div>
    </div>

    <div class="summary">
        <div class="summary-item">
            <div class="value"><?php echo $monthlyTotal['total_vehicles']; ?></div>
            <div class="label">Total Washes</div>
        </div>
        <div class="summary-item">
            <div class="value">GHS <?php echo number_format($monthlyTotal['total_amount'], 2); ?></div>
            <div class="label">Total Revenue</div>
        </div>
        <div class="summary-item">
            <div class="value"><?php echo $monthlyTotal['cars']; ?></div>
            <div class="label">Cars Washed</div>
        </div>
        <div class="summary-item">
            <div class="value"><?php echo $monthlyTotal['motors']; ?></div>
            <div class="label">Motors Washed</div>
        </div>
        <div class="summary-item">
            <div class="value"><?php echo $monthlyTotal['carpets']; ?></div>
            <div class="label">Carpets Washed</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Service</th>
                <th class="text-center">Total</th>
                <th class="text-center">Cars</th>
                <th class="text-center">Motors</th>
                <th class="text-center">Carpets</th>
                <th class="text-right">Total Amount</th>
                <th class="text-right">Avg. per wash</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($monthlyData as $row): 
                $avgAmount = $row['total_vehicles'] > 0 ? $row['total_amount'] / $row['total_vehicles'] : 0;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                <td class="text-center"><?php echo $row['total_vehicles']; ?></td>
                <td class="text-center"><?php echo $row['cars']; ?></td>
                <td class="text-center"><?php echo $row['motors']; ?></td>
                <td class="text-center"><?php echo $row['carpets']; ?></td>
                <td class="text-right">GHS <?php echo number_format($row['total_amount'], 2); ?></td>
                <td class="text-right">GHS <?php echo number_format($avgAmount, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th>Total</th>
                <th class="text-center"><?php echo $monthlyTotal['total_vehicles']; ?></th>
                <th class="text-center"><?php echo $monthlyTotal['cars']; ?></th>
                <th class="text-center"><?php echo $monthlyTotal['motors']; ?></th>
                <th class="text-center"><?php echo $monthlyTotal['carpets']; ?></th>
                <th class="text-right">GHS <?php echo number_format($monthlyTotal['total_amount'], 2); ?></th>
                <th class="text-right">
                    <?php 
                    $totalAvg = $monthlyTotal['total_vehicles'] > 0 
                        ? $monthlyTotal['total_amount'] / $monthlyTotal['total_vehicles'] 
                        : 0;
                    echo 'GHS ' . number_format($totalAvg, 2); 
                    ?>
                </th>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>Report generated on <?php echo date('F j, Y \a\t g:i A'); ?> by <?php echo htmlspecialchars($_SESSION['username'] ?? 'System'); ?></p>
    </div>

    <script>
        // Auto-print when the page loads (optional)
        window.onload = function() {
            // Uncomment the next line to auto-print the report
            // window.print();
        };
    </script>
</body>
</html>
