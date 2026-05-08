<?php
// Guard: only allow when DEV_PORTAL_ENABLED=1 AND superadmin is logged in
require_once __DIR__ . '/config/session.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Load .env for DEV_PORTAL_ENABLED
function load_env_once($path) {
    if (!is_file($path)) return;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $val);
    }
}
load_env_once(__DIR__ . '/.env');

if (getenv('DEV_PORTAL_ENABLED') !== '1') {
    http_response_code(403);
    echo 'Schema sync disabled. Set DEV_PORTAL_ENABLED=1 temporarily.';
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo 'Only superadmin can run schema sync.';
    exit;
}

require_once __DIR__ . '/config/database.php';

$results = [];
$errors = [];

function exec_query($conn, $sql) {
    global $results, $errors;
    if ($conn->query($sql)) {
        $results[] = 'OK: ' . $sql;
    } else {
        $errors[] = 'ERR: ' . $sql . ' -> ' . $conn->error;
    }
}

// Ensure core tables and columns exist (idempotent)
// car_washes modern schema
exec_query($conn, "CREATE TABLE IF NOT EXISTS car_washes (
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
  INDEX (created_at), INDEX (service_id), INDEX (category_id), INDEX (car_size_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS service_id INT NULL AFTER id");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS category_id INT NULL AFTER service_id");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS car_size_id INT NULL AFTER category_id");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS payment_confirmed TINYINT(1) NOT NULL DEFAULT 0");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS payment_confirmed_at DATETIME NULL");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS status ENUM('pending','confirmed','completed') DEFAULT 'pending'");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP");
@exec_query($conn, "ALTER TABLE car_washes ADD INDEX IF NOT EXISTS idx_cw_created_at (created_at)");
@exec_query($conn, "ALTER TABLE car_washes ADD INDEX IF NOT EXISTS idx_cw_service_id (service_id)");
@exec_query($conn, "ALTER TABLE car_washes ADD INDEX IF NOT EXISTS idx_cw_category_id (category_id)");
@exec_query($conn, "ALTER TABLE car_washes ADD INDEX IF NOT EXISTS idx_cw_car_size_id (car_size_id)");

// daily_reports with totals
exec_query($conn, "CREATE TABLE IF NOT EXISTS daily_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_date DATE NOT NULL UNIQUE,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  total_cars_washed INT NOT NULL DEFAULT 0,
  total_motors_washed INT NOT NULL DEFAULT 0,
  total_carpets_washed INT NOT NULL DEFAULT 0,
  gross_amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  revenue_two_thirds_total DECIMAL(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@exec_query($conn, "ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS total_cars_washed INT NOT NULL DEFAULT 0");
@exec_query($conn, "ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS total_motors_washed INT NOT NULL DEFAULT 0");
@exec_query($conn, "ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS total_carpets_washed INT NOT NULL DEFAULT 0");
@exec_query($conn, "ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS gross_amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00");
@exec_query($conn, "ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS revenue_two_thirds_total DECIMAL(12,2) NOT NULL DEFAULT 0.00");

// repair_expenses
exec_query($conn, "CREATE TABLE IF NOT EXISTS repair_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  description TEXT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// lookup tables
exec_query($conn, "CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
exec_query($conn, "CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
exec_query($conn, "CREATE TABLE IF NOT EXISTS car_sizes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
exec_query($conn, "CREATE TABLE IF NOT EXISTS workers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20),
  next_of_kin_name VARCHAR(255) DEFAULT NULL,
  next_of_kin_phone VARCHAR(50) DEFAULT NULL,
  photo_path VARCHAR(500) DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@exec_query($conn, "ALTER TABLE workers ADD COLUMN IF NOT EXISTS next_of_kin_name VARCHAR(255) DEFAULT NULL");
@exec_query($conn, "ALTER TABLE workers ADD COLUMN IF NOT EXISTS next_of_kin_phone VARCHAR(50) DEFAULT NULL");
@exec_query($conn, "ALTER TABLE workers ADD COLUMN IF NOT EXISTS photo_path VARCHAR(500) DEFAULT NULL");

@exec_query($conn, "ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS workload_level ENUM('low', 'normal', 'heavy') DEFAULT 'normal'");
@exec_query($conn, "ALTER TABLE daily_reports ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL");

// seed defaults (idempotent)
$conn->query("INSERT IGNORE INTO services(name) VALUES ('Engine Body'),('Engine Only'),('Interior Cleaning'),('Normal Washing')");
$conn->query("INSERT IGNORE INTO categories(name) VALUES ('Cars'),('Motors'),('Carpets')");
$conn->query("INSERT IGNORE INTO car_sizes(name) VALUES ('Small'),('Medium'),('Large'),('Extra Large')");

header('Content-Type: application/json');
echo json_encode([
  'ok' => empty($errors),
  'results' => $results,
  'errors' => $errors,
]);
