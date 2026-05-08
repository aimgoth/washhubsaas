<?php
require_once __DIR__ . '/config/session.php';
session_start();
require_once __DIR__ . '/includes/fuel_functions.php';

$report = null;

// Check if report ID is provided in URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $report = generateFuelReport($_GET['id']);
    if (!$report) {
        $_SESSION['error'] = 'No fuel report found for the specified ID.';
        header('Location: fuel_history.php');
        exit;
    }
}
// Fall back to session if no ID provided (for backward compatibility)
elseif (!empty($_SESSION['fuel_report'])) {
    $report = $_SESSION['fuel_report'];
    unset($_SESSION['fuel_report']);
}

// If still no report, redirect with error
if (!$report) {
    $_SESSION['error'] = 'No fuel report specified. Please select a report to view.';
    header('Location: fuel_history.php');
    exit;
}

$page_title = 'Fuel Usage Report';
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col-12">
            <!-- Report Header -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                    <div>
                        <h3 class="h4 mb-0"><i class="fas fa-gas-pump me-2"></i> <?= $page_title ?></h3>
                        <p class="mb-0 small">Generated on <?= date('M j, Y \a\t g:i A') ?></p>
                    </div>
                    <div class="btn-group">
                        <button onclick="window.print()" class="btn btn-light btn-sm">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <a href="fuel_history.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to History
                        </a>
                    </div>
                </div>
                <div class="card-body bg-light">
                    <div class="alert alert-success mb-0">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle fa-2x me-3"></i>
                            <div>
                                <h4 class="alert-heading mb-1">Fuel Usage Completed</h4>
                                <p class="mb-0">The fuel purchase has been successfully recorded and closed.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                    
                    <!-- Main Content -->
            <div class="row g-4">
                <!-- Fuel Details Card -->
                <div class="col-lg-6 d-flex">
                    <div class="card flex-fill border-0 shadow-sm d-flex flex-column">
                        <div class="card-header bg-white border-bottom">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-gas-pump text-primary me-2"></i>Fuel Details
                            </h5>
                        </div>
                                <div class="card-body d-flex flex-column">
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Amount</th>
                                            <td>GHS <?= number_format($report['fuel']['amount_cedis'], 2) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Liters</th>
                                            <td><?= number_format($report['fuel']['liters'], 2) ?> L</td>
                                        </tr>
                                        <tr>
                                            <th>Price per Liter</th>
                                            <td>GHS <?= number_format($report['fuel']['price_per_liter'], 2) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Period</th>
                                            <td>
                                                <?= date('M j, Y H:i', strtotime($report['fuel']['start_date'])) ?> to<br>
                                                <?= date('M j, Y H:i', strtotime($report['fuel']['end_date'])) ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Duration</th>
                                            <td>
                                                <?php
                                                $start = new DateTime($report['fuel']['start_date']);
                                                $end = new DateTime($report['fuel']['end_date']);
                                                $interval = $start->diff($end);
                                                
                                                $duration = [];
                                                if ($interval->d > 0) $duration[] = $interval->d . ' day' . ($interval->d > 1 ? 's' : '');
                                                if ($interval->h > 0) $duration[] = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '');
                                                if ($interval->i > 0) $duration[] = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '');
                                                
                                                echo implode(', ', $duration) ?: 'Less than a minute';
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                                        <!-- Wash Statistics Card -->
                <div class="col-lg-6 d-flex">
                    <div class="card flex-fill border-0 shadow-sm d-flex flex-column">
                        <div class="card-header bg-white border-bottom">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-bar text-primary me-2"></i>Wash Statistics
                            </h5>
                        </div>
                                <div class="card-body d-flex flex-column">
                                    <div class="row text-center mb-3">
                                        <div class="col-4">
                                            <div class="display-4 text-primary">
                                                <?= $report['washes']['total_washes'] ?: '0' ?>
                                            </div>
                                            <div class="text-muted small">Total Washes</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="display-4 text-success">
                                                <?= $report['washes']['total_cars'] ?: '0' ?>
                                            </div>
                                            <div class="text-muted small">Cars</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="display-4 text-info">
                                                <?= $report['washes']['total_motors'] ?: '0' ?>
                                            </div>
                                            <div class="text-muted small">Motors</div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($report['washes']['total_washes'] > 0): ?>
                                        <div class="mt-4">
                                            <h6>Cost Efficiency</h6>
                                            
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Cost per Wash</span>
                                                    <strong>GHS <?= number_format($report['cost_per_wash'], 2) ?></strong>
                                                </div>
                                                <?php
                                                // Simple efficiency metric (lower cost per wash is better)
                                                $maxEfficiency = 100;
                                                $efficiency = min(100, max(0, 100 - ($report['cost_per_wash'] * 5)));
                                                $efficiencyClass = $efficiency > 70 ? 'bg-success' : ($efficiency > 40 ? 'bg-warning' : 'bg-danger');
                                                ?>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated <?= $efficiencyClass ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= $efficiency ?>%" 
                                                         aria-valuenow="<?= $efficiency ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                        <?= round($efficiency) ?>%
                                                    </div>
                                                </div>
                                                <small class="text-muted">
                                                    <?php
                                                    if ($efficiency > 70) {
                                                        echo 'Excellent efficiency';
                                                    } elseif ($efficiency > 40) {
                                                        echo 'Average efficiency';
                                                    } else {
                                                        echo 'Low efficiency - Review fuel usage';
                                                    }
                                                    ?>
                                                </small>
                                            </div>
                                            
                                            <div class="mt-4">
                                                <h6>Wash Distribution</h6>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="chart-container" style="position: relative; height:200px;">
                                                            <canvas id="washChart"></canvas>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="small">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <span class="badge bg-success me-2" style="width: 15px; height: 15px;"></span>
                                                                <span>Cars: <?= $report['washes']['total_cars'] ?: '0' ?></span>
                                                            </div>
                                                            <div class="d-flex align-items-center">
                                                                <span class="badge bg-info me-2" style="width: 15px; height: 15px;"></span>
                                                                <span>Motors: <?= $report['washes']['total_motors'] ?: '0' ?></span>
                                                            </div>
                                                            <hr>
                                                            <div class="text-muted small">
                                                                <div>Total Revenue Potential:</div>
                                                                <div class="h5 text-dark">
                                                                    GHS <?= number_format(($report['washes']['total_cars'] * 20) + ($report['washes']['total_motors'] * 10), 2) ?>
                                                                </div>
                                                                <small>(Est. GHS 20 per car, GHS 10 per motor)</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i> No washes were recorded for this fuel purchase.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                                <!-- Summary Section -->
            <div class="row mt-4">
                <div class="col-12 d-flex">
                    <div class="card border-0 shadow-sm flex-fill d-flex flex-column">
                        <div class="card-header bg-white border-bottom">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-pie text-primary me-2"></i>Summary & Analysis
                            </h5>
                        </div>
                        <div class="card-body d-flex flex-column">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Fuel Cost Analysis</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Total Fuel Cost:</th>
                                            <td>GHS <?= number_format($report['fuel']['amount_cedis'], 2) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Total Washes:</th>
                                            <td><?= $report['washes']['total_washes'] ?: '0' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Cost per Wash:</th>
                                            <td>GHS <?= number_format($report['cost_per_wash'], 2) ?></td>
                                        </tr>
                                        <?php if ($report['washes']['total_washes'] > 0): ?>
                                            <tr>
                                                <th>Revenue per Liter:</th>
                                                <td>GHS <?= number_format((($report['washes']['total_cars'] * 20) + ($report['washes']['total_motors'] * 10)) / $report['fuel']['liters'], 2) ?></td>
                                            </tr>
                                            <tr>
                                                <th>Profit per Liter:</th>
                                                <td>
                                                    <?php
                                                    $revenue = ($report['washes']['total_cars'] * 20) + ($report['washes']['total_motors'] * 10);
                                                    $profitPerLiter = ($revenue / $report['fuel']['liters']) - $report['fuel']['price_per_liter'];
                                                    $profitClass = $profitPerLiter >= 0 ? 'text-success' : 'text-danger';
                                                    ?>
                                                    <span class="<?= $profitClass ?>">
                                                        GHS <?= number_format($profitPerLiter, 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Recommendations</h6>
                                    <ul class="list-group list-group-flush">
                                        <?php if ($report['washes']['total_washes'] === 0): ?>
                                            <li class="list-group-item">
                                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                                No washes were recorded for this fuel purchase. Ensure washes are being properly recorded.
                                            </li>
                                        <?php else: ?>
                                            <?php if ($report['cost_per_wash'] > 15): ?>
                                                <li class="list-group-item">
                                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                                    High cost per wash (GHS <?= number_format($report['cost_per_wash'], 2) ?>). Consider monitoring fuel usage more closely.
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php if (($report['washes']['total_motors'] / max(1, $report['washes']['total_washes'])) > 0.7): ?>
                                                <li class="list-group-item">
                                                    <i class="fas fa-info-circle text-info me-2"></i>
                                                    High percentage of motor washes. Consider adjusting pricing or promotions for cars.
                                                </li>
                                            <?php endif; ?>
                                            
                                            <li class="list-group-item">
                                                <i class="fas fa-lightbulb text-primary me-2"></i>
                                                Potential revenue per liter: GHS <?= number_format(($report['washes']['total_cars'] * 20 + $report['washes']['total_motors'] * 10) / $report['fuel']['liters'], 2) ?>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <li class="list-group-item">
                                            <i class="fas fa-calendar-alt text-primary me-2"></i>
                                            Report generated on <?= date('M j, Y \a\t g:i A') ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                                <div class="card-footer bg-light text-center py-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center text-muted small">
                        <?php if (isset($report['fuel']['id']) && $report['fuel']['id']): ?>
                            <div>Report ID: #<?= htmlspecialchars($report['fuel']['id']) ?></div>
                        <?php else: ?>
                            <div>Report ID: N/A (Auto-generated)</div>
                        <?php endif; ?>
                        <div>Generated on <?= date('M j, Y \a\t g:i A') ?></div>
                        <div>For any discrepancies, please contact support</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($report['washes']['total_washes'] > 0): ?>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('washChart').getContext('2d');
    var washChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Cars', 'Motors'],
            datasets: [{
                data: [
                    <?= $report['washes']['total_cars'] ?: 0 ?>, 
                    <?= $report['washes']['total_motors'] ?: 0 ?>
                ],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(23, 162, 184, 0.8)'
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(23, 162, 184, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<style>
@media print {
    .no-print, .no-print * {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    .card-header {
        background-color: transparent !important;
        border-bottom: 1px solid #dee2e6;
    }
    
    .alert {
        border: 1px solid #dee2e6;
    }
    
    @page {
        size: auto;
        margin: 0.5cm;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
