<?php
require_once 'config/session.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Only admin and superadmin can access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admins only.';
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';
require_once 'includes/fuel_functions.php';

$page_title = 'Record Fuel Purchase';
$error = '';
$success = '';

// Check for active purchase
$currentStatus = getCurrentFuelStatus();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_purchase'])) {
    try {
        $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $liters = filter_input(INPUT_POST, 'liters', FILTER_VALIDATE_FLOAT);
        
        if (!$amount || $amount <= 0) {
            throw new Exception("Please enter a valid amount");
        }
        
        if (!$liters || $liters <= 0) {
            throw new Exception("Please enter valid liters");
        }
        
        $purchaseId = recordFuelPurchase($amount, $liters, $_SESSION['user_id']);
        
        $_SESSION['success'] = "Fuel purchase recorded successfully! You can now start recording washes.";
        header('Location: dashboard.php');
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

include 'includes/header.php';
?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-gas-pump"></i> <?= $page_title ?></h2>
        <div class="card-body" style="padding: 25px;">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($currentStatus['has_active']): ?>
                        <div class="alert alert-warning" style="background: #FFF8E1; border-left: 4px solid #FFC107; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 4px;">
                            <div style="display: flex; align-items: center; margin-bottom: 1rem;">
                                <i class="fas fa-gas-pump" style="font-size: 1.5rem; color: #FFA000; margin-right: 0.75rem;"></i>
                                <h5 style="margin: 0; color: #5D4037; font-size: 1.1rem; font-weight: 600;">
                                    Active Fuel Purchase
                                </h5>
                            </div>
                            
                            <p style="color: #5D4037; margin-bottom: 1.25rem;">There's an active fuel purchase in progress:</p>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                                <div style="background: white; padding: 1rem; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                    <div style="font-size: 0.85rem; color: #757575; margin-bottom: 0.5rem;">Amount</div>
                                    <div style="font-size: 1.25rem; font-weight: 600; color: #2E7D32;">
                                        GHS <?= number_format($currentStatus['purchase']['amount_cedis'], 2) ?>
                                    </div>
                                </div>
                                
                                <div style="background: white; padding: 1rem; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                    <div style="font-size: 0.85rem; color: #757575; margin-bottom: 0.5rem;">Liters</div>
                                    <div style="font-size: 1.25rem; font-weight: 600; color: #1565C0;">
                                        <?= number_format($currentStatus['purchase']['liters'], 2) ?> L
                                    </div>
                                </div>
                                
                                <div style="background: white; padding: 1rem; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                    <div style="font-size: 0.85rem; color: #757575; margin-bottom: 0.5rem;">Started</div>
                                    <div style="font-size: 1.1rem; font-weight: 500; color: #4A148C;">
                                        <i class="far fa-calendar-alt" style="margin-right: 0.5rem;"></i>
                                        <?= date('M j, Y H:i', strtotime($currentStatus['purchase']['start_date'])) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($currentStatus['wash_stats']): ?>
                            <div style="background: #F5F5F5; padding: 1rem; border-radius: 6px; margin: 1.5rem 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="color: #424242; font-weight: 500;">Washes completed:</span>
                                        <span style="background: #1E88E5; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.9rem; font-weight: 600;">
                                            <?= $currentStatus['wash_stats']['total_washes'] ?>
                                        </span>
                                    </div>
                                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                        <?php
                                        if (!empty($currentStatus['wash_stats']['by_category'])) {
                                            $category_icons = [
                                                'car' => 'fas fa-car',
                                                'motor' => 'fas fa-motorcycle',
                                                'carpet' => 'fas fa-layer-group',
                                                'interior' => 'fas fa-couch'
                                            ];
                                            $category_colors = ['#43A047', '#0288D1', '#FF8F00', '#7B1FA2', '#C2185B'];
                                            $i = 0;

                                            foreach ($currentStatus['wash_stats']['by_category'] as $category_stat) {
                                                $name = strtolower($category_stat['category_name']);
                                                $icon = $category_icons[$name] ?? 'fas fa-soap';
                                                $color = $category_colors[$i % count($category_colors)];
                                                $plural = ($category_stat['count'] > 1) ? 's' : '';
                                                $i++;
                                        ?>
                                        <span style="background: <?= $color ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                            <i class="<?= $icon ?>"></i>
                                            <?= htmlspecialchars($category_stat['count']) ?> <?= htmlspecialchars(ucfirst($name)) . $plural ?>
                                        </span>
                                        <?php
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #EEEEEE;">
                                <a href="finish_fuel.php" style="background: #FFA000; color: white; text-decoration: none; padding: 0.6rem 1.25rem; border-radius: 4px; font-weight: 500; display: inline-flex; align-items: center; gap: 0.5rem; transition: background 0.2s;">
                                    <i class="fas fa-flag-checkered"></i>
                                    Finish Current Fuel
                                </a>
                                <?php if ($_SESSION['role'] === 'superadmin'): ?>
                                <a href="fuel_history.php" style="background: #0288D1; color: white; text-decoration: none; padding: 0.6rem 1.25rem; border-radius: 4px; font-weight: 500; display: inline-flex; align-items: center; gap: 0.5rem; transition: background 0.2s;">
                                    <i class="fas fa-history"></i>
                                    View History
                                </a>
                                <?php endif; ?>
                                <a href="dashboard.php" style="color: #616161; text-decoration: none; padding: 0.6rem 1.25rem; border: 1px solid #E0E0E0; border-radius: 4px; font-weight: 500; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s;">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Dashboard
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="post" class="form-grid">
                            <div class="form-group">
                                <label for="amount">Amount (GHS) <span class="required">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">GHS</span>
                                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                                </div>
                                <div class="error-message" id="amount-error"></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="liters">Liters <span class="required">*</span></label>
                                <div class="input-group">
                                    <input type="number" id="liters" name="liters" step="0.1" min="0.1" required>
                                    <span class="input-group-text">liters</span>
                                </div>
                                <div class="error-message" id="liters-error"></div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="record_purchase" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Record Fuel Purchase
                                </button>
                                <a href="dashboard.php" class="btn btn-outline">
                                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.form-grid');
    if (!form) return;

    // Real-time validation
    const inputs = form.querySelectorAll('input[required]');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            validateField(this);
        });
    });

    // Form submission
    form.addEventListener('submit', function(e) {
        let isValid = true;
        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
        }
    });

    function validateField(input) {
        const errorElement = document.getElementById(`${input.id}-error`);
        if (!errorElement) return true;

        if (!input.checkValidity()) {
            errorElement.textContent = input.validationMessage || 'This field is required';
            input.classList.add('error');
            return false;
        } else {
            errorElement.textContent = '';
            input.classList.remove('error');
            return true;
        }
    }
});
</script>

<?php include 'includes/footer.php'; ?>
