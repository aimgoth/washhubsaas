<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_type = $_POST['service_type'];
    $car_size = $_POST['car_size'];
    $number_plate = strtoupper($_POST['number_plate']);
    
    // Calculate amount based on service type and car size
    $prices = [
        'Basic' => ['Small' => 10, 'Medium' => 15, 'Large' => 20, 'SUV' => 25],
        'Premium' => ['Small' => 20, 'Medium' => 30, 'Large' => 40, 'SUV' => 50],
        'Interior' => ['Small' => 30, 'Medium' => 45, 'Large' => 60, 'SUV' => 75]
    ];
    
    $amount = $prices[$service_type][$car_size];
    
    // Insert new car wash record
    $sql = "INSERT INTO car_washes (service_type, car_size, amount, number_plate, worker_id, admin_id) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $worker_id = ($_SESSION['role'] === 'washer') ? $_SESSION['user_id'] : null;
    $admin_id = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'superadmin') ? $_SESSION['user_id'] : null;
    $stmt->bind_param("ssdsii", $service_type, $car_size, $amount, $number_plate, $worker_id, $admin_id);
    
    if ($stmt->execute()) {
        $success = "Car wash record added successfully!";
    } else {
        $error = "Error adding car wash record: " . $conn->error;
    }
}

// Get all car washes
$sql = "SELECT cw.*, u1.full_name as worker_name, u2.full_name as admin_name 
        FROM car_washes cw
        LEFT JOIN users u1 ON cw.worker_id = u1.id
        LEFT JOIN users u2 ON cw.admin_id = u2.id
        ORDER BY cw.created_at DESC";
$result = $conn->query($sql);
$washes = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $washes[] = $row;
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container">
    <h1>Car Wash Records</h1>
    
    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'superadmin' || $_SESSION['role'] === 'washer'): ?>
    <div class="card" style="margin-bottom: 30px;">
        <h2>Add New Car Wash</h2>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="washes.php">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label for="service_type" style="display: block; margin-bottom: 5px; font-weight: 600;">Service Type</label>
                    <select id="service_type" name="service_type" required 
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Select Service</option>
                        <option value="Basic">Basic Wash</option>
                        <option value="Premium">Premium Wash</option>
                        <option value="Interior">Interior Cleaning</option>
                    </select>
                </div>
                
                <div>
                    <label for="car_size" style="display: block; margin-bottom: 5px; font-weight: 600;">Size</label>
                    <select id="car_size" name="car_size" required 
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Select Size</option>
                        <option value="Small">Small</option>
                        <option value="Medium">Medium</option>
                        <option value="Large">Large</option>
                        <option value="SUV">SUV</option>
                    </select>
                </div>
                
                <div>
                    <label for="number_plate" style="display: block; margin-bottom: 5px; font-weight: 600;">License Plate</label>
                    <input type="text" id="number_plate" name="number_plate" required
                           style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="align-self: flex-end;">
                    <button type="submit" class="btn" style="width: 100%;">Add Record</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h2>Recent Washes</h2>
        
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                <thead>
                    <tr style="background-color: var(--secondary-color); color: white;">
                        <th style="padding: 12px; text-align: left;">Date</th>
                        <th style="padding: 12px; text-align: left;">Service</th>
                        <th style="padding: 12px; text-align: left;">Size</th>
                        <th style="padding: 12px; text-align: left;">License Plate</th>
                        <th style="padding: 12px; text-align: left;">Worker</th>
                        <th style="padding: 12px; text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($washes) > 0): ?>
                        <?php foreach ($washes as $wash): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?php echo date('M j, Y h:i A', strtotime($wash['created_at'])); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($wash['service_type']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($wash['car_size']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($wash['number_plate']); ?></td>
                                <td style="padding: 12px;"><?php echo $wash['worker_name'] ?? 'N/A'; ?></td>
                                <td style="padding: 12px; text-align: right;">$<?php echo number_format($wash['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="padding: 20px; text-align: center;">No car wash records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
