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

// Populate success message from session flash or query param after redirect
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
} elseif (!empty($_GET['success'])) {
    // Fallback generic success if session flash not present
    $success = 'Car wash record added successfully!';
}

// Detect car_washes table columns to support schema differences (e.g., category_id may be absent)
$carWashColumns = [];
try {
    if ($cwCols = $conn->query("SHOW COLUMNS FROM car_washes")) {
        while ($c = $cwCols->fetch_assoc()) { $carWashColumns[strtolower($c['Field'])] = true; }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }
$cwHasCategory = isset($carWashColumns['category_id']);

// Get all services
$services = [];
$result = $conn->query("SELECT * FROM services ORDER BY name");
if ($result) {
    $services = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all categories
$categories = [];
$result = $conn->query("SELECT * FROM categories ORDER BY name");
if ($result) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all car sizes
$car_sizes = [];
$result = $conn->query("SELECT * FROM car_sizes ORDER BY name");
if ($result) {
    $car_sizes = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all workers
$workers = [];
$result = $conn->query("SELECT * FROM workers WHERE status = 'active' ORDER BY full_name");
if ($result) {
    $workers = $result->fetch_all(MYSQLI_ASSOC);
}


// Get list of washers for dropdown
$sql = "SELECT id, full_name FROM users WHERE role = 'washer' ORDER BY full_name";
$result = $conn->query($sql);
$washers = [];

while ($row = $result->fetch_assoc()) {
    $washers[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and validate form data
        $service_id = intval($_POST['service_id'] ?? 0);
        $category_id = intval($_POST['category_id'] ?? 0);
        $car_size_id = intval($_POST['car_size_id'] ?? 0);
        $number_plate = strtoupper(trim($_POST['number_plate'] ?? ''));
        $worker_id = intval($_POST['worker_id'] ?? 0);
        $admin_id = intval($_SESSION['user_id']);
        $amount = floatval($_POST['amount'] ?? 0);

        // Determine if category is 'Carpets'
        $isCarpet = false;
        if ($cwHasCategory && $category_id > 0) {
            $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $stmt->bind_param('i', $category_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $isCarpet = (strtolower(trim($row['name'] ?? '')) === 'carpets');
            }
        }

        // Validation checks
        $plateMissing = (!$isCarpet && empty($number_plate));
        $missingCategory = ($cwHasCategory && empty($category_id));
        if (empty($service_id) || empty($car_size_id) || $missingCategory || $plateMissing || $worker_id <= 0) {
            throw new Exception('Please fill in all required fields.');
        }
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount.');
        }

        // Prepare data for insertion
        $amount = number_format($amount, 2, '.', '');
        $plateParam = ($isCarpet && empty($number_plate)) ? 'N/A' : $number_plate;

        // Duplicate Plate Verification
        if ($plateParam !== 'N/A' && $plateParam !== '') {
            $today = date('Y-m-d');
            
            // Check completed washes (car_washes)
            $stmt_chk = $conn->prepare("SELECT id FROM car_washes WHERE number_plate = ? AND DATE(created_at) = ? LIMIT 1");
            $stmt_chk->bind_param('ss', $plateParam, $today);
            $stmt_chk->execute();
            if ($stmt_chk->get_result()->num_rows > 0) {
                throw new Exception("Duplicate Entry: The license plate '{$plateParam}' has already been recorded today.");
            }
            
            // Check queued/active washes (wash_tasks)
            try {
                $stmt_chk2 = $conn->prepare("SELECT id FROM wash_tasks WHERE number_plate = ? AND DATE(created_at) = ? AND status != 'cancelled' LIMIT 1");
                $stmt_chk2->bind_param('ss', $plateParam, $today);
                $stmt_chk2->execute();
                if ($stmt_chk2->get_result()->num_rows > 0) {
                    throw new Exception("Duplicate Entry: The license plate '{$plateParam}' is currently queued or has been washed today.");
                }
            } catch (Throwable $e) {}
        }

        // Duration calculation removed as per user request
        $duration_minutes = 0;

        $startTime = new DateTime();
        $plannedEndTime = null;
        if ($duration_minutes > 0) {
            $plannedEndTime = (clone $startTime)->add(new DateInterval('PT' . $duration_minutes . 'M'));
        }

        $fields = ['service_id', 'car_size_id', 'amount', 'number_plate', 'worker_id', 'admin_id', 'status', 'started_at', 'planned_end'];
        $types  = 'iidssisss';
        $values = [
            $service_id, $car_size_id, $amount, $plateParam, $worker_id, $admin_id, 
            'in_progress', 
            $startTime->format('Y-m-d H:i:s'), 
            $plannedEndTime ? $plannedEndTime->format('Y-m-d H:i:s') : null
        ];

        if ($cwHasCategory) {
            array_splice($fields, 2, 0, 'category_id');
            array_splice($values, 2, 0, $category_id);
            $types = 'iisississs';
        }

        // Insert directly into car_washes table
        $sql = "INSERT INTO car_washes (" . implode(',', $fields) . ") VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            throw new Exception('Error adding car wash record: ' . $stmt->error);
        }

        // Success: redirect
        $_SESSION['flash_success'] = 'Car wash record added successfully!';
        header('Location: add_wash.php?success=1');
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'New Car Wash';
include 'includes/header.php';
?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>New Car Wash</h2>
            <div>
                <a href="add_generator_wash.php" class="btn btn-warning">
                    <i class="fas fa-bolt"></i> Add Generator Wash
                </a>
                <a href="washes.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
        
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <label for="service_id">Service Type <span class="required">*</span></label>
                    <select id="service_id" name="service_id" required>
                        <option value="">Select Service</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>">
                                <?php echo htmlspecialchars($service['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($cwHasCategory): ?>
                <div class="form-group">
                    <label for="category_id">Category <span class="required">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="car_size_id">Size <span class="required">*</span></label>
                    <select id="car_size_id" name="car_size_id" required>
                        <option value="">Select Size</option>
                        <?php foreach ($car_sizes as $size): ?>
                            <option value="<?php echo $size['id']; ?>">
                                <?php echo htmlspecialchars($size['name']); ?>
                            </option>
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
                            <option value="<?php echo $worker['id']; ?>">
                                <?php echo htmlspecialchars($worker['full_name']); ?>
                            </option>
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
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i> Save Record
                    </button>
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
        
        // Make an AJAX request to get the price
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `get_price.php?service_id=${serviceId}&car_size_id=${carSizeId}`, true);
        xhr.onload = function() {
            if (this.status === 200) {
                const response = JSON.parse(this.responseText);
                if (response.success) {
                    const amount = parseFloat(response.amount);
                    if (amount > 0) {
                        amountDisplay.textContent = 'GHS ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                        amountDisplay.style.color = 'var(--primary-color)';
                        amountInput.value = amount.toFixed(2);
                    } else {
                        amountDisplay.textContent = 'Custom/Negotiated Price';
                        amountDisplay.style.color = '#e67e22'; // Orange to draw attention
                        amountInput.value = ''; // Force them to type
                    }
                } else {
                    amountDisplay.textContent = 'Price not set';
                    amountInput.value = '';
                }
            }
        };
        xhr.send();
    }
    
    function togglePlate() {
        if (!categorySelect) {
            // No categories feature; always require plate
            plateGroup.style.display = '';
            plateInput.required = true;
            return;
        }
        const selectedText = (categorySelect.options[categorySelect.selectedIndex]?.text || '').toLowerCase().trim();
        const isCarpet = (selectedText === 'carpets' || selectedText === 'carpet');
        if (isCarpet) {
            plateGroup.style.display = 'none';
            plateInput.required = false;
            // keep value as-is; server will accept empty and set 'N/A' if needed
        } else {
            plateGroup.style.display = '';
            plateInput.required = true;
        }
    }
    
    // Allow manual amount override
    amountInput.addEventListener('change', function() {
        const amount = parseFloat(this.value) || 0;
        amountDisplay.textContent = 'GHS ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    });
    
    serviceSelect.addEventListener('change', updatePrice);
    carSizeSelect.addEventListener('change', updatePrice);
    if (categorySelect) {
        categorySelect.addEventListener('change', function() {
            togglePlate();
            // Also recompute price in case sizes/services mapping differs across categories
            updatePrice();
        });
    }
    
    // Initial update
    togglePlate();
    updatePrice();
});
</script>

<style>
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: var(--secondary-color);
}

input[type="text"],
input[type="tel"],
input[type="number"],
select,
textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
}

.required {
    color: #e74c3c;
}

.amount-display {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    text-align: right;
    font-size: 1.2rem;
    font-weight: bold;
    color: var(--primary-color);
}

.amount-display span:first-child {
    color: #6c757d;
    margin-right: 10px;
}

.form-actions {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.alert {
    padding: 12px 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
