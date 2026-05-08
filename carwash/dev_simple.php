<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Start session
session_start();

// Check login
if (!isset($_SESSION['dev_logged_in']) || $_SESSION['dev_logged_in'] !== true) {
    header('Location: dev_login.php');
    exit;
}

// Database connection
$db = new mysqli('sql303.infinityfree.com', 'if0_39762246', 'sybZtLsejrYQDi', 'if0_39762246_appdb', 3306);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Handle form submission
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '1';
    
    if ($step === '1') {
        // Step 1: Create tables
        $sql = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(100) NOT NULL,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role ENUM('superadmin','admin','washer','cashier') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
            "CREATE TABLE IF NOT EXISTS car_washes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                service_id INT NULL,
                category_id INT NULL,
                car_size_id INT NULL,
                amount DECIMAL(10,2) NOT NULL,
                number_plate VARCHAR(20) NOT NULL,
                worker_id INT NULL,
                admin_id INT NULL,
                payment_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                payment_confirmed_at DATETIME NULL,
                status ENUM('pending','confirmed','completed') DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX (created_at), 
                INDEX (service_id), 
                INDEX (category_id), 
                INDEX (car_size_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];
        
        foreach ($sql as $query) {
            if (!$db->query($query)) {
                $errors[] = "Error creating table: " . $db->error;
                break;
            }
        }
        
        if (empty($errors)) {
            $messages[] = "Database tables created successfully!";
        }
    } 
    elseif ($step === '2') {
        // Step 2: Create superadmin
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($full_name) || empty($username) || empty($password)) {
            $errors[] = 'All fields are required';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, 'superadmin') ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), password=VALUES(password), role='superadmin'");
            
            if ($stmt) {
                $stmt->bind_param('sss', $full_name, $username, $hash);
                if ($stmt->execute()) {
                    $messages[] = 'Superadmin user created/updated successfully!';
                } else {
                    $errors[] = 'Failed to create superadmin: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $errors[] = 'Database error: ' . $db->error;
            }
        }
    }
}

// Close database connection if it exists
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WashHub - Developer Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f6f8fb; 
            margin: 0;
            color: #333;
            line-height: 1.6;
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 20px;
        }
        .navbar {
            background: #0f172a;
            color: white;
            padding: 12px 0;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar-inner {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand img {
            height: 32px;
            border-radius: 6px;
        }
        .brand-name {
            font-weight: 700;
            font-size: 1.2em;
        }
        .nav-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn i {
            font-size: 0.9em;
        }
        .btn.secondary {
            background: #475569;
            color: white;
        }
        .btn.danger {
            background: #dc2626;
            color: white;
        }
        .btn.secondary:hover {
            background: #3e4c5e;
        }
        .btn.danger:hover {
            background: #b91c1c;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 24px;
            margin-bottom: 24px;
        }
        h1 {
            color: #1e293b;
            margin: 0 0 24px 0;
            font-size: 1.8em;
        }
        h2 {
            color: #1e40af;
            margin: 0 0 16px 0;
            font-size: 1.3em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        h3 {
            color: #334155;
            margin: 24px 0 16px 0;
            font-size: 1.1em;
        }
        .msg {
            padding: 12px 16px;
            border-radius: 6px;
            margin: 16px 0;
            font-size: 0.95em;
        }
        .ok {
            background: #dcfce7;
            border-left: 4px solid #16a34a;
            color: #166534;
        }
        .err {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #991b1b;
        }
        .row {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin: 16px 0;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #4b5563;
            font-size: 0.9em;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.95em;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        button[type="submit"] {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95em;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        button[type="submit"]:hover {
            background: #1d4ed8;
        }
        button[type="submit"]:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <header class="navbar">
        <div class="navbar-inner">
            <div class="brand">
                <img src="../frontend/new logo.png" alt="WashHub Logo" />
                <span class="brand-name">WashHub</span>
            </div>
            <nav class="nav-actions">
                <a href="dev_portal.php" class="btn secondary"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="dev_login.php?logout=1" class="btn danger"><i class="fas fa-right-from-bracket"></i> Logout</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <h1><i class="fas fa-screwdriver-wrench"></i> Simple Provisioning</h1>

            <?php foreach ($messages as $m): ?>
                <div class="msg ok"><?php echo htmlspecialchars($m); ?></div>
            <?php endforeach; ?>
            
            <?php foreach ($errors as $e): ?>
                <div class="msg err"><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>

            <h3>Step 1: Create database and tables</h3>
            <form method="post">
                <input type="hidden" name="step" value="1" />
                <div class="row">
                    <div>
                        <label>DB Name</label>
                        <input type="text" name="db_name" value="washhub_client" required />
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit"><i class="fas fa-database"></i> Create DB & Tables</button>
                </div>
            </form>

            <h3 style="margin-top: 32px;">Step 2: Create superadmin</h3>
            <form method="post">
                <input type="hidden" name="step" value="2" />
                <div class="row">
                    <div>
                        <label>DB Name</label>
                        <input type="text" name="db_name" value="washhub_client" required />
                    </div>
                    <div>
                        <label>Full Name</label>
                        <input type="text" name="full_name" placeholder="Super Admin" required />
                    </div>
                    <div>
                        <label>Username</label>
                        <input type="text" name="username" placeholder="superadmin" required />
                    </div>
                    <div>
                        <label>Password</label>
                        <input type="password" name="password" placeholder="StrongPassword123!" required />
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit"><i class="fas fa-user-shield"></i> Create/Update Superadmin</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

