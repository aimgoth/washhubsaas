<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

$error = '';
$success = '';
$employee = null;
$earnings_data = [];
$total_earnings = 0;

// Get employee ID from URL
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$employee_id) {
    header("location: employees.php");
    exit;
}

// Get employee details with all necessary fields
$sql = "SELECT id, full_name, username, role, is_active, hourly_rate, phone_number, address, created_at 
        FROM users 
        WHERE id = ? AND role = 'washer' AND added_by = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $employee_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("location: employees.php");
    exit;
}

$employee = $result->fetch_assoc();

// Handle earnings calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_earnings'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // Get employee earnings for the period
    $sql = "SELECT 
                DATE(cw.created_at) as wash_date,
                COUNT(cw.id) as wash_count,
                SUM(
                    CASE 
                        WHEN cw.car_size = 'Small' THEN 10
                        WHEN cw.car_size = 'Medium' THEN 15
                        WHEN cw.car_size = 'Large' THEN 20
                        WHEN cw.car_size = 'SUV' THEN 25
                        ELSE 0
                    END
                ) as daily_earnings
            FROM car_washes cw
            WHERE cw.worker_id = ? 
            AND cw.created_at BETWEEN ? AND ? + INTERVAL 1 DAY
            GROUP BY DATE(cw.created_at)
            ORDER BY wash_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $employee_id, $start_date, $end_date);
    $stmt->execute();
    $earnings_result = $stmt->get_result();
    
    while ($row = $earnings_result->fetch_assoc()) {
        $earnings_data[] = $row;
        $total_earnings += $row['daily_earnings'];
    }
    
    if (empty($earnings_data)) {
        $error = 'No records found for the selected date range.';
    } else {
        $success = "Earnings calculated from " . date('M j, Y', strtotime($start_date)) . " to " . date('M j, Y', strtotime($end_date));
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Employee Details</h2>
            <a href="employees.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Employees
            </a>
        </div>
        
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <?php if ($total_earnings > 0): ?>
                        <div style="margin-top: 10px; font-size: 1.2em; font-weight: bold;">
                            Total Earnings: ₱<?php echo number_format($total_earnings, 2); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="employee-profile">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="profile-info">
                        <h3><?php echo htmlspecialchars($employee['full_name']); ?></h3>
                        <p class="text-muted">@<?php echo htmlspecialchars($employee['username']); ?></p>
                        <span class="status-badge <?php echo $employee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="profile-details">
                    <div class="detail-item">
                        <span class="detail-label">Hourly Rate:</span>
                        <span class="detail-value">₱<?php echo number_format($employee['hourly_rate'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value"><?php echo $employee['phone_number'] ? htmlspecialchars($employee['phone_number']) : 'N/A'; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Address:</span>
                        <span class="detail-value"><?php echo $employee['address'] ? nl2br(htmlspecialchars($employee['address'])) : 'N/A'; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Member Since:</span>
                        <span class="detail-value"><?php echo date('M j, Y', strtotime($employee['created_at'])); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="section-divider"></div>
            
            <div class="earnings-calculator">
                <h3>Calculate Earnings</h3>
                <form method="POST" class="form-inline">
                    <div class="form-group">
                        <label for="start_date">From:</label>
                        <input type="date" id="start_date" name="start_date" required 
                               value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">To:</label>
                        <input type="date" id="end_date" name="end_date" required
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <button type="submit" name="calculate_earnings" class="btn">
                        <i class="fas fa-calculator"></i> Calculate
                    </button>
                </form>
                
                <?php if (!empty($earnings_data)): ?>
                    <div class="earnings-summary">
                        <h4>Earnings Summary</h4>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Washes</th>
                                        <th>Earnings</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($earnings_data as $day): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($day['wash_date'])); ?></td>
                                            <td><?php echo $day['wash_count']; ?></td>
                                            <td>₱<?php echo number_format($day['daily_earnings'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td><strong>Total</strong></td>
                                        <td><strong><?php echo array_sum(array_column($earnings_data, 'wash_count')); ?></strong></td>
                                        <td><strong>₱<?php echo number_format($total_earnings, 2); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="text-right" style="margin-top: 20px;">
                            <button class="btn" onclick="window.print()">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.employee-profile {
    margin-bottom: 30px;
}

.profile-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.profile-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background-color: #f0f2f5;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 20px;
}

.profile-avatar i {
    font-size: 48px;
    color: #6c757d;
}

.profile-info h3 {
    margin: 0 0 5px 0;
    color: #333;
}

.profile-details {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
}

.detail-item {
    margin-bottom: 10px;
}

.detail-label {
    font-weight: 600;
    color: #6c757d;
    display: inline-block;
    min-width: 100px;
}

.detail-value {
    color: #333;
}

.section-divider {
    height: 1px;
    background-color: #e9ecef;
    margin: 25px 0;
}

.earnings-calculator {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
}

.form-inline {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.earnings-summary {
    margin-top: 20px;
}

.total-row {
    font-weight: bold;
    background-color: #f8f9fa;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
}

.status-inactive {
    background-color: #f8d7da;
    color: #721c24;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .container {
        width: 100%;
        padding: 0;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    .section-divider {
        margin: 15px 0;
    }
}

@media (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-avatar {
        margin: 0 0 15px 0;
    }
    
    .form-inline {
        flex-direction: column;
        align-items: stretch;
    }
    
    .form-group {
        margin-bottom: 10px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
