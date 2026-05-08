<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/session.php';
session_start();
require_once __DIR__ . '/includes/fuel_functions.php';

// Only admin and superadmin can access
if (!in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admins only.';
    header('Location: index.php');
    exit;
}

$page_title = 'Finish Fuel Usage';
$error = '';
$success = '';

// Get active purchase
$currentStatus = getCurrentFuelStatus();

// If no active purchase, redirect
if (!$currentStatus['has_active']) {
    $_SESSION['error'] = 'No active fuel purchase found.';
    header('Location: fuel_purchase.php');
    exit;
}

$purchaseId = $currentStatus['purchase']['id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_finish'])) {
    try {
        $report = finishFuelUsage($purchaseId, $_SESSION['user_id']);
        
        // Notify super admin
        notifySuperAdminFuelFinished($purchaseId, $report);
        
        // Store report in session for display
        $_SESSION['fuel_report'] = $report;
        
        // Redirect to report page
        header('Location: fuel_report.php');
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?= $page_title ?></h3>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <div class="alert alert-warning">
                        <h5>Finish Current Fuel Purchase</h5>
                        <p>You are about to mark the current fuel purchase as finished. This action cannot be undone.</p>
                        
                        <h6 class="mt-3">Purchase Details:</h6>
                        <ul>
                            <li>Amount: GHS <?= number_format($currentStatus['purchase']['amount_cedis'], 2) ?></li>
                            <li>Liters: <?= number_format($currentStatus['purchase']['liters'], 2) ?> L</li>
                            <li>Started: <?= date('M j, Y H:i', strtotime($currentStatus['purchase']['start_date'])) ?></li>
                        </ul>
                        
                        <?php if ($currentStatus['wash_stats']): ?>
                            <h6 class="mt-3">Wash Statistics:</h6>
                            <ul>
                                <li>Total Washes: <?= $currentStatus['wash_stats']['total_washes'] ?? 0 ?></li>
                                <li>Cars Washed: <?= $currentStatus['wash_stats']['total_cars'] ?? 0 ?></li>
                                <li>Motors Washed: <?= $currentStatus['wash_stats']['total_motors'] ?? 0 ?></li>
                                <li>Carpets Washed: <?= $currentStatus['wash_stats']['total_carpets'] ?? 0 ?></li>
                            </ul>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No washes recorded for this fuel purchase.
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" class="mt-4">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirm" required>
                                <label class="form-check-label" for="confirm">
                                    I confirm that I want to finish this fuel purchase
                                </label>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="fuel_purchase.php" class="btn btn-secondary me-md-2">
                                    <i class="fas fa-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" name="confirm_finish" class="btn btn-warning">
                                    <i class="fas fa-flag-checkered"></i> Confirm Finish
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function () {
    'use strict'
    
    var form = document.querySelector('form')
    var confirmCheckbox = document.getElementById('confirm')
    
    form.addEventListener('submit', function (event) {
        if (!confirmCheckbox.checked) {
            event.preventDefault()
            event.stopPropagation()
            confirmCheckbox.classList.add('is-invalid')
        } else {
            confirmCheckbox.classList.remove('is-invalid')
        }
    }, false)
})()
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
