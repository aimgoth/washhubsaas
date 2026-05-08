<?php
require_once 'config/session.php';

// Check if user is logged in and has the right role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin', 'washer'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';
require_once 'includes/fuel_functions.php';

$error = '';
$success = '';

// Check for an active fuel purchase session
$fuelStatus = getCurrentFuelStatus();
if (!$fuelStatus['has_active']) {
    $_SESSION['flash_error'] = 'You must start a fuel purchase session before you can add a generator wash.';
    header('Location: fuel_purchase.php');
    exit();
}

// Populate success message from session flash or query param after redirect
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
} elseif (!empty($_GET['success'])) {
    $success = 'Generator wash record added successfully!';
}

// Detect generator_washes table columns
$genWashColumns = [];
if ($cols = $conn->query("SHOW COLUMNS FROM generator_washes")) {
    while ($c = $cols->fetch_assoc()) { $genWashColumns[strtolower($c['Field'])] = true; }
}
$genHasCategory = isset($genWashColumns['category_id']);

// Get dropdown data
$services = $conn->query("SELECT * FROM services ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$car_sizes = $conn->query("SELECT * FROM car_sizes ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$workers = $conn->query("SELECT * FROM workers WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service_id = intval($_POST['service_id'] ?? 0);
        $category_id = intval($_POST['category_id'] ?? 0);
        $car_size_id = intval($_POST['car_size_id'] ?? 0);
        $number_plate = strtoupper(trim($_POST['number_plate'] ?? ''));
        $worker_id = intval($_POST['worker_id'] ?? 0);
        $admin_id = intval($_SESSION['user_id']);
        $amount = floatval($_POST['amount'] ?? 0);

        $isCarpet = false;
        if ($genHasCategory && $category_id > 0) {
            $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $stmt->bind_param('i', $category_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $isCarpet = (strtolower(trim($row['name'] ?? '')) === 'carpets');
            }
        }

        $plateMissing = (!$isCarpet && empty($number_plate));
        $missingCategory = ($genHasCategory && empty($category_id));
        if (empty($service_id) || empty($car_size_id) || $missingCategory || $plateMissing || $worker_id <= 0) {
            throw new Exception('Please fill in all required fields.');
        }
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount.');
        }

        $amount = number_format($amount, 2, '.', '');
        $plateParam = ($isCarpet && empty($number_plate)) ? 'N/A' : $number_plate;
        
        // Data for generator_washes
        $fuelPurchaseId = $fuelStatus['purchase']['id'];
        // Get size name
        $size_stmt = $conn->prepare("SELECT name FROM car_sizes WHERE id = ?");
        $size_stmt->bind_param('i', $car_size_id);
        $size_stmt->execute();
        $size_result = $size_stmt->get_result()->fetch_assoc();
        $size_name = strtolower($size_result['name'] ?? '');
        
        // Get category name if category_id is set
        $category_name = '';
        if ($genHasCategory && $category_id > 0) {
            $cat_stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $cat_stmt->bind_param('i', $category_id);
            $cat_stmt->execute();
            $cat_result = $cat_stmt->get_result()->fetch_assoc();
            $category_name = strtolower($cat_result['name'] ?? '');
        }
        
        // Debug output (remove after testing)
        error_log("Size: $size_name, Category: $category_name, isCarpet: " . ($isCarpet ? 'true' : 'false'));
        
        // Determine wash type based on category and size
        if ($isCarpet || strpos($category_name, 'carpet') !== false) {
            $wash_type = 'carpet';
        } elseif (strpos($size_name, 'motor') !== false) {
            $wash_type = 'motor';
        } else {
            $wash_type = 'car';
        }

        $fields = ['fuel_purchase_id', 'wash_type', 'created_by', 'service_id', 'car_size_id', 'amount', 'number_plate', 'worker_id'];
        $types = 'isiiidsi';
        $values = [$fuelPurchaseId, $wash_type, $admin_id, $service_id, $car_size_id, $amount, $plateParam, $worker_id];

        if ($genHasCategory) {
            array_splice($fields, 4, 0, 'category_id');
            array_splice($values, 4, 0, $category_id);
            $types = 'isiiiidsi';
        }

        $conn->begin_transaction();

        try {
            // Insert into generator_washes
            $sql_gen = "INSERT INTO generator_washes (" . implode(',', $fields) . ") VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")";
            $stmt_gen = $conn->prepare($sql_gen);
            $stmt_gen->bind_param($types, ...$values);

            if (!$stmt_gen->execute()) {
                throw new Exception('Error adding generator wash record: ' . $stmt_gen->error);
            }

            // Also insert into car_washes table immediately
            $car_wash_fields = ['service_id', 'category_id', 'car_size_id', 'amount', 'number_plate', 'worker_id', 'admin_id', 'created_at'];
            $car_wash_sql = "INSERT INTO car_washes (service_id, category_id, car_size_id, amount, number_plate, worker_id, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt_car_wash = $conn->prepare($car_wash_sql);
            
            // Note: category_id might not be set, handle it
            $current_category_id = $genHasCategory ? $category_id : null;

            $stmt_car_wash->bind_param('iiidssi', 
                $service_id, 
                $current_category_id, 
                $car_size_id, 
                $amount, 
                $plateParam, 
                $worker_id, 
                $admin_id
            );

            if (!$stmt_car_wash->execute()) {
                throw new Exception('Error adding car wash record: ' . $stmt_car_wash->error);
            }

            $conn->commit();

        } catch (Exception $e) {
            $conn->rollback();
            throw $e; // Re-throw the exception to be caught by the outer handler
        }

        $_SESSION['flash_success'] = 'Generator wash record added successfully!';
        header('Location: add_generator_wash.php?success=1');
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'New Generator Wash';
include 'includes/header.php';
?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>New Generator Wash</h2>
            <a href="fuel_purchase.php" class="btn btn-outline">
                <i class="fas fa-tachometer-alt"></i> Back to Fuel Dashboard
            </a>
        </div>
        
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You are adding a wash powered by the generator. An active fuel purchase session is required.
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <label for="service_id">Service Type <span class="required">*</span></label>
                    <select id="service_id" name="service_id" required>
                        <option value="">Select Service</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($genHasCategory): ?>
                <div class="form-group">
                    <label for="category_id">Category <span class="required">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="car_size_id">Size <span class="required">*</span></label>
                    <select id="car_size_id" name="car_size_id" required>
                        <option value="">Select Size</option>
                        <?php foreach ($car_sizes as $size): ?>
                            <option value="<?php echo $size['id']; ?>"><?php echo htmlspecialchars($size['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" id="plate_group">
                    <label for="number_plate">License Plate <span class="required">*</span></label>
                    <input type="text" id="number_plate" name="number_plate" required
                           value="<?php echo htmlspecialchars($_POST['number_plate'] ?? ''); ?>"
                           placeholder="e.g., KAA 123A"
                           pattern="[A-Z0-9 ]{3,10}" 
                           title="Enter a valid license plate (3-10 alphanumeric characters)">
                </div>
                
                <div class="form-group">
                    <label for="worker_id">Washer <span class="required">*</span></label>
                    <select id="worker_id" name="worker_id" required>
                        <option value="">Select Washer</option>
                        <?php foreach ($workers as $worker): ?>
                            <option value="<?php echo $worker['id']; ?>"><?php echo htmlspecialchars($worker['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Can't find a worker? <a href="workers.php">Add them here</a></small>
                </div>

                <div class="form-group">
                    <label>Admin</label>
                    <input type="text" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Current Admin'); ?>" readonly>
                    <small>This record will be saved under the logged-in admin.</small>
                </div>

                <div class="form-group">
                    <label for="amount">Amount (GHS) <span class="required">*</span></label>
                    <input type="number" id="amount" name="amount" required 
                           value="<?php echo htmlspecialchars($_POST['amount'] ?? '0'); ?>"
                           min="0" step="0.01" class="amount-input">
                </div>

                <div class="form-group full-width">
                    <div class="amount-display">
                        <span>Calculated Amount:</span>
                        <span id="amount_display">GHS 0.00</span>
                    </div>
                </div>
                
                <div class="form-actions full-width">
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Save Generator Wash</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceSelect = document.getElementById('service_id');
    const carSizeSelect = document.getElementById('car_size_id');
    const categorySelect = document.getElementById('category_id');
    const plateGroup = document.getElementById('plate_group');
    const plateInput = document.getElementById('number_plate');
    const amountInput = document.getElementById('amount');
    const amountDisplay = document.getElementById('amount_display');
    
    function updatePrice() {
        const serviceId = serviceSelect.value;
        const carSizeId = carSizeSelect.value;
        if (!serviceId || !carSizeId) {
            amountDisplay.textContent = 'GHS 0.00';
            return;
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `get_price.php?service_id=${serviceId}&car_size_id=${carSizeId}`, true);
        xhr.onload = function() {
            if (this.status === 200) {
                try {
                    const response = JSON.parse(this.responseText);
                    if (response.success) {
                        const amount = parseFloat(response.amount);
                        amountDisplay.textContent = 'GHS ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        amountInput.value = amount.toFixed(2);
                    } else {
                        amountDisplay.textContent = 'Price not available';
                        amountInput.value = '0.00';
                    }
                } catch (e) {
                    console.error('Failed to parse price response:', e);
                    amountDisplay.textContent = 'Error fetching price';
                }
            }
        };
        xhr.send();
    }
    
    function togglePlate() {
        if (!categorySelect) {
            plateGroup.style.display = '';
            plateInput.required = true;
            return;
        }
        const selectedText = (categorySelect.options[categorySelect.selectedIndex]?.text || '').toLowerCase().trim();
        const isCarpet = (selectedText === 'carpets' || selectedText === 'carpet');
        if (isCarpet) {
            plateGroup.style.display = 'none';
            plateInput.required = false;
        } else {
            plateGroup.style.display = '';
            plateInput.required = true;
        }
    }
    
    amountInput.addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;
        amountDisplay.textContent = 'GHS ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    });
    
    serviceSelect.addEventListener('change', updatePrice);
    carSizeSelect.addEventListener('change', updatePrice);
    if (categorySelect) {
        categorySelect.addEventListener('change', function() {
            togglePlate();
            updatePrice();
        });
    }
    
    // Initial setup
    togglePlate();
    updatePrice();
});
</script>

<style>
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
.form-group { margin-bottom: 15px; }
.form-group.full-width { grid-column: 1 / -1; }
label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--secondary-color); }
input[type="text"], input[type="tel"], input[type="number"], select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; }
.required { color: #e74c3c; }
.amount-display { background-color: #f8f9fa; padding: 15px; border-radius: 4px; text-align: right; font-size: 1.2rem; font-weight: bold; color: var(--primary-color); }
.amount-display span:first-child { color: #6c757d; margin-right: 10px; }
.form-actions { margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px; }
.alert { padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.alert-info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
.alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
@media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
</style>

<?php include 'includes/footer.php'; ?>
