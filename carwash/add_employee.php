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

// Form submission handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $hourly_rate = floatval($_POST['hourly_rate']);
    $phone_number = trim($_POST['phone_number']);
    $address = trim($_POST['address']);
    
    // Validation
    if (empty($full_name) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        // Check if username already exists
        $sql = "SELECT id FROM users WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $error = 'Username already exists';
        } else {
            // Insert new employee
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (full_name, username, password, role, added_by, hourly_rate, phone_number, address) 
                    VALUES (?, ?, ?, 'washer', ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssdis", $full_name, $username, $hashed_password, $_SESSION['user_id'], $hourly_rate, $phone_number, $address);
            
            if ($stmt->execute()) {
                $success = 'Employee added successfully';
                // Clear form
                $_POST = [];
            } else {
                $error = 'Error adding employee: ' . $conn->error;
            }
        }
        $stmt->close();
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Add New Employee</h2>
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
                </div>
            <?php endif; ?>
            
            <form method="POST" class="form-grid">
                <div class="form-group">
                    <label for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" required 
                           value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <input type="text" id="username" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" required>
                    <small class="form-hint">Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                
                <div class="form-group">
                    <label for="hourly_rate">Hourly Rate (₱)</label>
                    <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0"
                           value="<?php echo htmlspecialchars($_POST['hourly_rate'] ?? '0.00'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number"
                           value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                </div>
                
                <div class="form-group full-width">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions full-width">
                    <button type="submit" class="btn">
                        <i class="fas fa-user-plus"></i> Add Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
input[type="password"],
input[type="tel"],
input[type="number"],
textarea,
select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
}

textarea {
    min-height: 100px;
    resize: vertical;
}

.required {
    color: #e74c3c;
}

.form-hint {
    display: block;
    margin-top: 5px;
    font-size: 0.8rem;
    color: #7f8c8d;
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
