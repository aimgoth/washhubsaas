<?php
require_once 'config/session.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($month < 1 || $month > 12) { $month = (int)date('n'); }
if ($year < 2020 || $year > (int)date('Y') + 1) { $year = (int)date('Y'); }

// Get list of years with data
$years = [];
$query = "SELECT DISTINCT YEAR(created_at) as year FROM car_washes ORDER BY year DESC";
$result = $conn->query($query);
if ($result) {
    }
}

// Get Dynamic Worker Commission
$worker_pct = 33.33; // Default 1/3
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        $worker_pct = (float)$row_set['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
$company_pct = 100 - $worker_pct;
if (empty($years)) {
    $years = [(int)date('Y')];
}

// Initialize monthly data arrays
$monthlyData = [];
$monthlyTotal = [
    'total_amount' => 0,
    'total_vehicles' => 0,
    'cars' => 0,
    'motors' => 0,
    'carpets' => 0,
    'services' => []
];

// Main query
$query = "SELECT 
    s.id as service_id,
    s.name as service_name,
    COUNT(cw.id) as total_vehicles,
    CASE 
        WHEN cw.category_id = 2 THEN 'motors'
        WHEN cw.category_id = 3 THEN 'carpets'
        ELSE 'cars'
    END as vehicle_category,
    SUM(cw.amount) as total_amount
FROM car_washes cw
LEFT JOIN services s ON cw.service_id = s.id
WHERE MONTH(cw.created_at) = ? 
AND YEAR(cw.created_at) = ?
AND cw.status IS NOT NULL
GROUP BY s.id, s.name, vehicle_category
ORDER BY vehicle_category, total_vehicles DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $month, $year);
$stmt->execute();
$res = $stmt->get_result();

$categoryTotals = [
    'cars' => ['count' => 0, 'amount' => 0],
    'motors' => ['count' => 0, 'amount' => 0],
    'carpets' => ['count' => 0, 'amount' => 0]
];

while ($row = $res->fetch_assoc()) {
    $category = $row['vehicle_category'];
    $count = (int)($row['total_vehicles'] ?? 0);
    $amount = (float)($row['total_amount'] ?? 0);
    
    if (isset($categoryTotals[$category])) {
        $categoryTotals[$category]['count'] += $count;
        $categoryTotals[$category]['amount'] += $amount;
    }
    
    $monthlyData[] = $row;
    $monthlyTotal['total_vehicles'] += $count;
    $monthlyTotal['total_amount'] += $amount;
}
$stmt->close();

$monthlyTotal['cars'] = $categoryTotals['cars']['count'];
$monthlyTotal['motors'] = $categoryTotals['motors']['count'];
$monthlyTotal['carpets'] = $categoryTotals['carpets']['count'];

$monthName = date('F', mktime(0, 0, 0, $month, 1));
$page_title = 'Monthly Report - ' . $monthName . ' ' . $year;
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
    .mr-btn-view {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 20px; background: #fff; color: #1B3FA0; border: 1.5px solid #1B3FA0;
        border-radius: 10px; font-weight: 700; font-size: 0.92rem; text-decoration: none;
        transition: all .2s; cursor: pointer;
    }
    .mr-btn-view:hover { background: #1B3FA0; color: #fff; }
    
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
    
    .mr-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .mr-form select {
        padding: 8px 14px; border-radius: 8px; border: 1.5px solid #e2e8f0;
        font-weight: 600; color: #1e293b; background: #fff; outline: none; font-size: 0.9rem;
    }
    .mr-form select:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.12); }

    /* Summary Grid */
    .mr-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; padding: 24px; }
    .mr-stat {
        background: #fff; border-radius: 12px; padding: 18px;
        border: 1px solid #e2e8f0; border-top: 4px solid #1B3FA0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04); text-align: center;
    }
    .mr-stat.blue { border-top-color: #00AEEF; }
    .mr-stat.green { border-top-color: #10b981; }
    .mr-stat.purple { border-top-color: #8b5cf6; }
    .mr-stat.orange { border-top-color: #f59e0b; }
    .mr-stat.red { border-top-color: #ef4444; }
    .mr-stat-val { font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2; margin-bottom: 4px; }
    .mr-stat-lbl { font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Table */
    .mr-table-wrap { overflow-x: auto; }
    .mr-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .mr-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .mr-table th { padding: 14px 20px; font-size: 0.73rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; text-align: left; color: #fff; white-space: nowrap; }
    .mr-table td { padding: 14px 20px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
    .mr-table tbody tr:last-child td { border-bottom: none; }
    .mr-table tbody tr:hover td { background: #f0f7ff; }
    
    .mr-tfoot { background: #f8fafc; font-weight: 800; }
    .mr-tfoot td { border-top: 2px solid #e2e8f0; color: #0f172a; }
    
    .mr-amt { font-weight: 700; color: #059669; }
</style>

<div class="mr-page">
    
    <!-- Title -->
    <div class="mr-title">
        <div class="mr-title-left">
            <div class="mr-title-icon"><i class="fas fa-chart-line"></i></div>
            <div>
                <h1>Monthly Report</h1>
                <p>Performance summary for <?php echo $monthName . ' ' . $year; ?></p>
            </div>
        </div>
        <div class="mr-actions">
            <a href="view_monthly_data.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="mr-btn-view">
                <i class="fas fa-list"></i> View All Data
            </a>
            <button type="button" class="mr-btn-export" onclick="exportReport(<?php echo $month; ?>, <?php echo $year; ?>)">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
        </div>
    </div>

    <!-- Date Selection -->
    <div class="mr-card">
        <div class="mr-card-header">
            <h2><i class="fas fa-calendar-alt"></i> Select Period</h2>
            <form method="get" class="mr-form">
                <select name="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="mr-summary-grid">
            <div class="mr-stat blue">
                <div class="mr-stat-val"><?php echo number_format($monthlyTotal['total_vehicles']); ?></div>
                <div class="mr-stat-lbl">Total Washes</div>
            </div>
            <div class="mr-stat green">
                <div class="mr-stat-val">GHS <?php echo number_format($monthlyTotal['total_amount'], 2); ?></div>
                <div class="mr-stat-lbl">Total Gross</div>
            </div>
            <div class="mr-stat blue">
                <div class="mr-stat-val">GHS <?php echo number_format($monthlyTotal['total_amount'] * ($company_pct / 100), 2); ?></div>
                <div class="mr-stat-lbl">Company (<?php echo round($company_pct, 1); ?>%)</div>
            </div>
            <div class="mr-stat purple" style="border-top-color: #8b5cf6;">
                <div class="mr-stat-val">GHS <?php echo number_format($monthlyTotal['total_amount'] * ($worker_pct / 100), 2); ?></div>
                <div class="mr-stat-lbl">Worker (<?php echo round($worker_pct, 1); ?>%)</div>
            </div>
            <div class="mr-stat purple">
                <div class="mr-stat-val"><?php echo number_format($monthlyTotal['cars']); ?></div>
                <div class="mr-stat-lbl">Cars</div>
            </div>
            <div class="mr-stat orange" style="border-top-color: #f59e0b;">
                <div class="mr-stat-val"><?php echo number_format($monthlyTotal['motors']); ?></div>
                <div class="mr-stat-lbl">Motors</div>
            </div>
            <div class="mr-stat red" style="border-top-color: #ef4444;">
                <div class="mr-stat-val"><?php echo number_format($monthlyTotal['carpets']); ?></div>
                <div class="mr-stat-lbl">Carpets</div>
            </div>
        </div>
    </div>

    <!-- Details Table -->
    <div class="mr-card">
        <div class="mr-card-header">
            <h2><i class="fas fa-table"></i> Service Breakdown</h2>
        </div>
        <div class="mr-table-wrap">
            <table class="mr-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Category</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Amount (GHS)</th>
                        <th style="text-align:right;">Avg (GHS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $groupedData = [];
                    foreach ($monthlyData as $row) {
                        $key = $row['service_name'] . '|' . $row['vehicle_category'];
                        if (!isset($groupedData[$key])) {
                            $groupedData[$key] = [
                                'service_name' => $row['service_name'],
                                'category' => ucfirst($row['vehicle_category']),
                                'total_vehicles' => 0,
                                'total_amount' => 0
                            ];
                        }
                        $groupedData[$key]['total_vehicles'] += $row['total_vehicles'];
                        $groupedData[$key]['total_amount'] += $row['total_amount'];
                    }
                    
                    if (empty($groupedData)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 30px; color:#94a3b8;">
                                No data available for this month.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($groupedData as $item): ?>
                            <tr>
                                <td style="font-weight:700;"><?php echo htmlspecialchars($item['service_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['category']); ?></td>
                                <td style="text-align:right;"><?php echo $item['total_vehicles']; ?></td>
                                <td style="text-align:right;" class="mr-amt"><?php echo number_format($item['total_amount'], 2); ?></td>
                                <td style="text-align:right;">
                                    <?php echo $item['total_vehicles'] > 0 ? number_format($item['total_amount'] / $item['total_vehicles'], 2) : '0.00'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($groupedData)): ?>
                <tfoot class="mr-tfoot">
                    <tr>
                        <td colspan="2">GRAND TOTAL</td>
                        <td style="text-align:right;"><?php echo $monthlyTotal['total_vehicles']; ?></td>
                        <td style="text-align:right; color:#059669;">GHS <?php echo number_format($monthlyTotal['total_amount'], 2); ?></td>
                        <td style="text-align:right;">GHS <?php echo $monthlyTotal['total_vehicles'] > 0 ? number_format($monthlyTotal['total_amount'] / $monthlyTotal['total_vehicles'], 2) : '0.00'; ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    
    <!-- Chart -->
    <div class="mr-card">
        <div class="mr-card-header">
            <h2><i class="fas fa-chart-bar"></i> Service Distribution</h2>
        </div>
        <div style="padding: 24px;">
            <canvas id="serviceChart" height="100"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Export function
function exportReport(month, year) {
    try {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_monthly_report.php';
        
        const m = document.createElement('input'); m.type = 'hidden'; m.name = 'month'; m.value = month;
        const y = document.createElement('input'); y.type = 'hidden'; y.name = 'year'; y.value = year;
        
        form.appendChild(m);
        form.appendChild(y);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    } catch (error) {
        window.location.href = `export_monthly_report.php?month=${month}&year=${year}`;
    }
}

// Chart initialization
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('serviceChart');
    if (!ctx) return;
    
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: [<?php echo implode(',', array_map(function($item) { return "'" . addslashes($item['service_name']) . "'"; }, $monthlyData)); ?>],
            datasets: [{
                label: 'Number of Vehicles',
                data: [<?php echo implode(',', array_column($monthlyData, 'total_vehicles')); ?>],
                backgroundColor: 'rgba(0, 174, 239, 0.6)',
                borderColor: 'rgba(27, 63, 160, 1)',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
