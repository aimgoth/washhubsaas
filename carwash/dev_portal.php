<?php
// WashHub CEO Console — GothTech Consult
// Central SaaS tenant management portal

$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1), " \t\n\r\x0B\"'");
        if ($key !== '') { $_ENV[$key] = $val; putenv("$key=$val"); }
    }
}

require_once __DIR__ . '/config/dev.php';

session_start();
if (empty($_SESSION['dev_logged_in'])) { header('Location: dev_login.php'); exit; }
unset($_SESSION['DB_NAME_OVERRIDE']); // CEO console must always use master DB.
$dev_username = trim((string)($_SESSION['dev_username'] ?? ''));
if ($dev_username === '') { $dev_username = DEV_USERNAME ?? 'ceo'; }

// Allow database.php to fail gracefully — we show a friendly error below
define('ALLOW_DB_FAIL', true);
define('DISABLE_DB_NAME_OVERRIDE', true);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/notifications.php';
date_default_timezone_set('Africa/Accra');

// If MySQL is down, show a simple maintenance page and stop
if (!$conn) {
    ?><!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>WashHub CEO Console — Database Offline</title>
      <link rel="icon" type="image/png" href="../frontend/new logo.png?v=2">
      <style>
        body{font-family:'Segoe UI',sans-serif;background:#C8EAF9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
        .box{background:#fff;border-radius:16px;padding:40px 44px;text-align:center;box-shadow:0 8px 32px rgba(27,63,160,.12);max-width:480px;width:90%;}
        .box img{height:60px;margin-bottom:20px;}
        .box h2{color:#1B3FA0;font-size:1.4rem;margin-bottom:10px;}
        .box p{color:#64748b;font-size:.95rem;line-height:1.6;}
        .badge{display:inline-block;background:#fef2f2;color:#991b1b;border:1.5px solid #fca5a5;border-radius:8px;padding:10px 18px;margin-top:20px;font-weight:700;font-size:.88rem;}
        .hint{margin-top:16px;font-size:.82rem;color:#94a3b8;}
      </style>
    </head>
    <body>
      <div class="box">
        <img src="../frontend/new logo.png" alt="WashHub">
        <h2>Database Offline</h2>
        <p>MySQL is not running. Please open the <strong>XAMPP Control Panel</strong> and click <strong>Start</strong> next to MySQL.</p>
        <div class="badge">⚠️ MySQL is stopped</div>
        <div class="hint">After starting MySQL, <a href="dev_portal.php" style="color:#1B3FA0;">click here to reload</a>.</div>
      </div>
    </body>
    </html>
    <?php exit;
}


// ── Ensure tenants table exists ─────────────────────────────────────────────
$messages = $messages ?? [];
$errors   = $errors ?? [];

$tenantsTableSql = "CREATE TABLE IF NOT EXISTS tenants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_name     VARCHAR(150) NOT NULL,
    bay_name        VARCHAR(100) NOT NULL,
    db_name         VARCHAR(100) NOT NULL UNIQUE,
    db_user         VARCHAR(100),
    superadmin_username VARCHAR(100),
    contact_name    VARCHAR(150),
    contact_phone   VARCHAR(30),
    contact_email   VARCHAR(150),
    monthly_fee     DECIMAL(10,2) DEFAULT 0.00,
    status          ENUM('trial','active','blocked','suspended') DEFAULT 'trial',
    subscription_start DATE,
    subscription_end   DATE,
    last_renewed_at    DATETIME,
    last_reminder_sent DATETIME NULL,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$tenants_table_ready = false;
try {
    $conn->query($tenantsTableSql);
    // Add last_reminder_sent if upgrading existing table
    $conn->query("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS last_reminder_sent DATETIME NULL AFTER last_renewed_at");
    $conn->query("SELECT 1 FROM tenants LIMIT 1");
    $tenants_table_ready = true;
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $isCorrupt = stripos($msg, "doesn't exist in engine") !== false
        || stripos($msg, 'tablespace for table') !== false;

    if ($isCorrupt) {
        try {
            $conn->query("DROP TABLE IF EXISTS tenants");
            $conn->query($tenantsTableSql);
            $conn->query("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS last_reminder_sent DATETIME NULL AFTER last_renewed_at");
            $conn->query("SELECT 1 FROM tenants LIMIT 1");
            $tenants_table_ready = true;
            $messages[] = "⚠️ Tenant registry table was repaired automatically.";
        } catch (Throwable $repairError) {
            $errors[] = "Tenant registry table is corrupted and could not be repaired automatically. Please restart MySQL and repair table `tenants` in phpMyAdmin. Error: " . $repairError->getMessage();
        }
    } else {
        $errors[] = "Tenant registry table error: " . $msg;
    }
}

// ── Renewal requests table (tenant submissions visible to CEO) ─────────────
$conn->query("CREATE TABLE IF NOT EXISTS renewal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    tenant_db_name VARCHAR(100) NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    bay_name VARCHAR(120) NULL,
    owner_name VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(40) NOT NULL,
    payment_network ENUM('mtn','telecel','other') DEFAULT 'other',
    payment_reference VARCHAR(160) NOT NULL,
    payment_date DATE NOT NULL,
    submitted_by_user VARCHAR(100) NULL,
    notes TEXT NULL,
    status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    confirmation_message VARCHAR(255) NULL,
    confirmed_by VARCHAR(100) NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rr_tenant_db (tenant_db_name),
    INDEX idx_rr_status (status),
    INDEX idx_rr_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── CEO profile table ───────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS ceo_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    avatar_path VARCHAR(255) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Contact submissions table ─────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS contact_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    business VARCHAR(150) NULL,
    region VARCHAR(50) NOT NULL,
    town VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new','contacted','closed') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add region and town columns if table already exists without them
$columns = $conn->query("SHOW COLUMNS FROM contact_submissions LIKE 'region'");
if ($columns && $columns->num_rows == 0) {
    $conn->query("ALTER TABLE contact_submissions ADD COLUMN region VARCHAR(50) NOT NULL AFTER business");
}
$columns = $conn->query("SHOW COLUMNS FROM contact_submissions LIKE 'town'");
if ($columns && $columns->num_rows == 0) {
    $conn->query("ALTER TABLE contact_submissions ADD COLUMN town VARCHAR(100) NOT NULL AFTER region");
}

// ── CEO notifications table ──────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS ceo_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    link_url VARCHAR(500) NULL,
    contact_id INT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_read (is_read),
    INDEX idx_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add contact_id column if it doesn't exist
$columns = $conn->query("SHOW COLUMNS FROM ceo_notifications LIKE 'contact_id'");
if ($columns && $columns->num_rows == 0) {
    $conn->query("ALTER TABLE ceo_notifications ADD COLUMN contact_id INT NULL AFTER link_url");
}

// Update existing notifications to extract contact_id from link_url
$existing_notifs = $conn->query("SELECT id, link_url FROM ceo_notifications WHERE contact_id IS NULL AND link_url LIKE '%id=%'");
if ($existing_notifs) {
    while ($row = $existing_notifs->fetch_assoc()) {
        if (preg_match('/id=(\d+)/', $row['link_url'], $matches)) {
            $contact_id = (int)$matches[1];
            $conn->query("UPDATE ceo_notifications SET contact_id = $contact_id WHERE id = " . (int)$row['id']);
        }
    }
}
$ceo_profile = null;
if ($pst = $conn->prepare("SELECT username, full_name, avatar_path FROM ceo_profiles WHERE username = ? LIMIT 1")) {
    $pst->bind_param('s', $dev_username);
    $pst->execute();
    $ceo_profile = $pst->get_result()->fetch_assoc();
    $pst->close();
}
$ceo_display_name = trim((string)($ceo_profile['full_name'] ?? ''));
if ($ceo_display_name === '') { $ceo_display_name = 'CEO'; }
$ceo_avatar = trim((string)($ceo_profile['avatar_path'] ?? ''));
if ($ceo_avatar === '') { $ceo_avatar = '../frontend/new logo.png'; }

// ── Fetch unread notifications count ─────────────────────────────────────────
$unread_count = 0;
$notif_res = $conn->query("SELECT COUNT(*) as cnt FROM ceo_notifications WHERE is_read = 0");
if ($notif_res) {
    $unread_count = (int)($notif_res->fetch_assoc()['cnt'] ?? 0);
}

// ── Fetch all notifications ───────────────────────────────────────────────────
$notifications = [];
$show_notifications = (($_GET['show_notifications'] ?? '') === '1') || (($_GET['view'] ?? '') === 'notifications');
$notif_list = $conn->query("SELECT * FROM ceo_notifications ORDER BY is_read ASC, created_at DESC LIMIT 50");
if ($notif_list) {
    while ($n = $notif_list->fetch_assoc()) {
        $notifications[] = $n;
    }
}

// ── SMS Helper — Africa's Talking ────────────────────────────────────────────
function sendSMS(string $phone, string $message): array {
    $username = getenv('AT_USERNAME') ?: 'sandbox';
    $apiKey   = getenv('AT_API_KEY')  ?: '';
    $sender   = getenv('AT_SENDER')   ?: '';
    $env      = getenv('AT_ENV')      ?: 'sandbox';

    if (!$apiKey) return ['ok' => false, 'error' => 'AT_API_KEY not set in .env'];

    // Normalise phone: strip spaces/dashes, ensure +233 format
    $phone = preg_replace('/[\s\-]/', '', $phone);
    if (preg_match('/^0(\d{9})$/', $phone, $m)) $phone = '+233' . $m[1]; // 0XX → +233XX
    if (!preg_match('/^\+/', $phone)) $phone = '+' . $phone;

    $url  = $env === 'sandbox'
        ? 'https://api.sandbox.africastalking.com/version1/messaging'
        : 'https://api.africastalking.com/version1/messaging';

    $body = http_build_query(array_filter([
        'username' => $username,
        'to'       => $phone,
        'message'  => $message,
        'from'     => $sender ?: null,
    ]));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'apiKey: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'error' => 'cURL: ' . $err];
    $data = json_decode($resp, true);
    $status = $data['SMSMessageData']['Recipients'][0]['status'] ?? ($data['SMSMessageData']['Message'] ?? 'Unknown');
    $ok = (stripos($status, 'Success') !== false || stripos($status, 'sent') !== false);
    return ['ok' => $ok, 'status' => $status, 'raw' => $resp];
}

function buildReminderMsg(array $t): string {
    $daysLeft = ceil((strtotime($t['subscription_end']) - time()) / 86400);
    $expiry   = date('d M Y', strtotime($t['subscription_end']));
    $fee      = 'GH₵' . number_format($t['monthly_fee'], 2);
    return "WashHub Reminder: Hello {$t['contact_name']}, your subscription for {$t['bay_name']} expires on $expiry ($daysLeft days). Please renew ($fee/month) to avoid service interruption. Call GothTech Consult to renew. Thank you.";
}

$messages = $messages ?? [];
$errors   = $errors ?? [];

// ── Actions ─────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$tid    = (int)($_POST['tid'] ?? $_GET['tid'] ?? 0);
if (!$tenants_table_ready) {
    $action = '';
}

// Mark notification as read
if ($action === 'mark_read' && !empty($_POST['nid'])) {
    $nid = (int)$_POST['nid'];
    $conn->query("UPDATE ceo_notifications SET is_read = 1 WHERE id = $nid");
    $messages[] = "Notification marked as read.";
}

// Mark all notifications as read
if ($action === 'mark_all_read') {
    $conn->query("UPDATE ceo_notifications SET is_read = 1 WHERE is_read = 0");
    $messages[] = "All notifications marked as read.";
}

// Block tenant
if ($action === 'block' && $tid) {
    if ($stmt = $conn->prepare("UPDATE tenants SET status='blocked' WHERE id=?")) {
        $stmt->bind_param('i', $tid); $stmt->execute();
        $messages[] = "Bay has been blocked. They cannot log in until unblocked.";
    }
}
// Unblock tenant
if ($action === 'unblock' && $tid) {
    if ($stmt = $conn->prepare("UPDATE tenants SET status='active' WHERE id=?")) {
        $stmt->bind_param('i', $tid); $stmt->execute();
        $messages[] = "Bay has been restored to active.";
    }
}
// Mark renewed — extend 30 days from today or from current end
if ($action === 'renew' && $tid) {
    $row_r = $conn->query("SELECT subscription_end FROM tenants WHERE id=$tid")->fetch_assoc();
    $base = (!empty($row_r['subscription_end']) && strtotime($row_r['subscription_end']) > time())
        ? $row_r['subscription_end'] : date('Y-m-d');
    $new_end = date('Y-m-d', strtotime($base . ' +30 days'));
    if ($stmt = $conn->prepare("UPDATE tenants SET status='active', subscription_end=?, last_renewed_at=NOW() WHERE id=?")) {
        $stmt->bind_param('si', $new_end, $tid); $stmt->execute();
        $messages[] = "Renewed! New expiry: $new_end";
    }
}

// Confirm a submitted renewal request and extend tenant subscription.
if ($action === 'confirm_renewal' && !empty($_POST['rid'])) {
    $rid = (int)$_POST['rid'];
    $ceoUser = trim((string)($_SESSION['dev_username'] ?? DEV_USERNAME));
    $confirmationText = "Renewal payment confirmed. Your subscription has been updated successfully.";

    $request = null;
    if ($reqStmt = $conn->prepare("SELECT id, tenant_id, tenant_db_name, status FROM renewal_requests WHERE id = ? LIMIT 1")) {
        $reqStmt->bind_param('i', $rid);
        $reqStmt->execute();
        $request = $reqStmt->get_result()->fetch_assoc();
        $reqStmt->close();
    }

    if (!$request) {
        $errors[] = 'Renewal request not found.';
    } elseif (($request['status'] ?? '') === 'confirmed') {
        $messages[] = 'Renewal request already confirmed.';
    } else {
        $tenantId = (int)($request['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            if ($tStmt = $conn->prepare("SELECT id FROM tenants WHERE db_name = ? LIMIT 1")) {
                $dbn = (string)$request['tenant_db_name'];
                $tStmt->bind_param('s', $dbn);
                $tStmt->execute();
                $tenantId = (int)($tStmt->get_result()->fetch_assoc()['id'] ?? 0);
                $tStmt->close();
            }
        }

        if ($tenantId <= 0) {
            $errors[] = 'Cannot map renewal request to a tenant record.';
        } else {
            $row_r = $conn->query("SELECT subscription_end FROM tenants WHERE id={$tenantId}")->fetch_assoc();
            $base = (!empty($row_r['subscription_end']) && strtotime((string)$row_r['subscription_end']) > time())
                ? $row_r['subscription_end'] : date('Y-m-d');
            $new_end = date('Y-m-d', strtotime($base . ' +30 days'));

            $okRenew = false;
            if ($stmt = $conn->prepare("UPDATE tenants SET status='active', subscription_end=?, last_renewed_at=NOW() WHERE id=?")) {
                $stmt->bind_param('si', $new_end, $tenantId);
                $okRenew = $stmt->execute();
                $stmt->close();
            }

            if ($okRenew) {
                if ($upd = $conn->prepare("UPDATE renewal_requests SET tenant_id=?, status='confirmed', confirmation_message=?, confirmed_by=?, confirmed_at=NOW() WHERE id=?")) {
                    $upd->bind_param('issi', $tenantId, $confirmationText, $ceoUser, $rid);
                    $upd->execute();
                    $upd->close();
                }
                $messages[] = "Renewal confirmed and subscription extended to {$new_end}.";
            } else {
                $errors[] = 'Failed to renew tenant subscription.';
            }
        }
    }
}

// ── Send reminder SMS to a single tenant ────────────────────────────────────
if ($action === 'send_reminder' && $tid) {
    $t = $conn->query("SELECT * FROM tenants WHERE id = $tid LIMIT 1")->fetch_assoc();
    if (!$t) {
        $errors[] = 'Tenant not found.';
    } elseif (empty($t['contact_phone'])) {
        $errors[] = "No contact phone for {$t['client_name']}. Please update their record.";
    } else {
        $msg    = buildReminderMsg($t);
        $result = sendSMS($t['contact_phone'], $msg);
        if ($result['ok']) {
            $conn->query("UPDATE tenants SET last_reminder_sent = NOW() WHERE id = $tid");
            $messages[] = "✅ Reminder sent to {$t['contact_name']} ({$t['contact_phone']}) for {$t['bay_name']}.";
        } else {
            $errors[] = "❌ SMS failed for {$t['client_name']}: " . ($result['error'] ?? $result['status'] ?? 'Unknown error');
        }
    }
}

// ── Auto-send reminders to ALL tenants expiring within 3 days ───────────────
if ($action === 'send_all_reminders') {
    $res_exp = $conn->query(
        "SELECT * FROM tenants
         WHERE status IN ('active','trial')
           AND subscription_end IS NOT NULL
           AND subscription_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
           AND (last_reminder_sent IS NULL OR last_reminder_sent < DATE_SUB(NOW(), INTERVAL 24 HOUR))"
    );
    $sent = 0; $failed = 0;
    if ($res_exp && $res_exp->num_rows > 0) {
        while ($t = $res_exp->fetch_assoc()) {
            if (empty($t['contact_phone'])) { $failed++; continue; }
            $msg    = buildReminderMsg($t);
            $result = sendSMS($t['contact_phone'], $msg);
            if ($result['ok']) {
                $conn->query("UPDATE tenants SET last_reminder_sent = NOW() WHERE id = {$t['id']}");
                $sent++;
            } else {
                $errors[] = "SMS failed for {$t['client_name']}: " . ($result['error'] ?? $result['status']);
                $failed++;
            }
        }
        $messages[] = "📲 Auto-reminder complete: $sent sent" . ($failed ? ", $failed failed (no phone / error)" : '') . ".";
    } else {
        $messages[] = "ℹ️ No clients are expiring within 3 days that need a reminder right now.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'provision') {
    @set_time_limit(90);

    $client_name = trim($_POST['client_name'] ?? '');
    $bay_name    = trim($_POST['bay_name']    ?? '');
    $db_name     = trim($_POST['db_name']     ?? '');
    $db_user     = trim($_POST['db_user']     ?? '');
    $db_pass     = $_POST['db_pass']   ?? '';
    $sa_name     = trim($_POST['sa_name']     ?? '');
    $sa_username = trim($_POST['sa_username'] ?? '');
    $sa_password = $_POST['sa_password'] ?? '';
    $contact_name  = trim($_POST['contact_name']  ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $monthly_fee   = (float)($_POST['monthly_fee'] ?? 0);
    $sub_start     = $_POST['sub_start'] ?? date('Y-m-d');
    $sub_end       = $_POST['sub_end']   ?? date('Y-m-d', strtotime('+30 days'));
    $db_name_s = preg_replace('/[^a-zA-Z0-9_]/', '', $db_name);
    $db_user_s = preg_replace('/[^a-zA-Z0-9_]/', '', $db_user);

        if (!$client_name || !$bay_name || !$db_name_s || !$db_user_s || !$db_pass || !$sa_username || !$sa_password) {
        $errors[] = 'All fields marked * are required.';
    } else {
        // Disable exceptions for this block — we handle errors manually
        mysqli_report(MYSQLI_REPORT_OFF);

        // ── Phase 1: DB creation + schema installation ──────────────────────
            $srvInit = mysqli_init();
            if ($srvInit) {
                mysqli_options($srvInit, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
                if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                    mysqli_options($srvInit, MYSQLI_OPT_READ_TIMEOUT, 15);
                }
            }
            $srv = ($srvInit && @mysqli_real_connect($srvInit, '127.0.0.1', DB_USERNAME, DB_PASSWORD, '', (int)DB_PORT)) ? $srvInit : null;
        if (!$srv || $srv->connect_error) {
            $errors[] = 'Cannot connect to MySQL: ' . ($srv ? $srv->connect_error : 'unknown error');
        } else {
            $srv->set_charset('utf8mb4');

            // 1. Create database
            if (!$srv->query("CREATE DATABASE IF NOT EXISTS `$db_name_s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                $errors[] = 'Failed to create DB: ' . $srv->error;
            } else {
                $messages[] = "✅ Database `$db_name_s` created.";
                $srv->select_db($db_name_s);

                // 2. Install schema — all on $srv while DB is selected
                $tables = [
                    "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY,full_name VARCHAR(100) NOT NULL,username VARCHAR(50) UNIQUE NOT NULL,password VARCHAR(255) NOT NULL,role ENUM('superadmin','admin','washer','cashier') NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                    "CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                    "CREATE TABLE IF NOT EXISTS services (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,description TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                    "CREATE TABLE IF NOT EXISTS car_sizes (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(50) NOT NULL UNIQUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                    "CREATE TABLE IF NOT EXISTS workers (id INT AUTO_INCREMENT PRIMARY KEY,full_name VARCHAR(100) NOT NULL,phone VARCHAR(20),next_of_kin_name VARCHAR(255) DEFAULT NULL,next_of_kin_phone VARCHAR(50) DEFAULT NULL,photo_path VARCHAR(500) DEFAULT NULL,status ENUM('active','inactive') DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                    "CREATE TABLE IF NOT EXISTS car_washes (id INT AUTO_INCREMENT PRIMARY KEY,service_id INT NULL,category_id INT NULL,car_size_id INT NULL,amount DECIMAL(10,2) NOT NULL,number_plate VARCHAR(20) NOT NULL,worker_id INT NULL,admin_id INT NULL,payment_confirmed TINYINT(1) NOT NULL DEFAULT 0,payment_confirmed_at DATETIME NULL,status ENUM('pending','confirmed','completed') DEFAULT 'pending',workload_level ENUM('low', 'normal', 'heavy') DEFAULT 'normal',is_foul TINYINT(1) DEFAULT 0,foul_overrun_minutes INT DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                    "CREATE TABLE IF NOT EXISTS daily_reports (id INT AUTO_INCREMENT PRIMARY KEY,report_date DATE NOT NULL UNIQUE,created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,submitted_at DATETIME NULL,total_cars_washed INT NOT NULL DEFAULT 0,total_motors_washed INT NOT NULL DEFAULT 0,total_carpets_washed INT NOT NULL DEFAULT 0,gross_amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,revenue_two_thirds_total DECIMAL(12,2) NOT NULL DEFAULT 0.00)",
                    "CREATE TABLE IF NOT EXISTS wash_tasks (id INT AUTO_INCREMENT PRIMARY KEY,service_id INT,category_id INT,car_size_id INT,number_plate VARCHAR(20),worker_id INT,workload_level ENUM('normal','busy') DEFAULT 'normal',amount DECIMAL(10,2),status ENUM('pending','started','awaiting_end','manual_closed') DEFAULT 'pending',planned_start DATETIME,planned_end DATETIME,started_at DATETIME,created_by INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
                    "CREATE TABLE IF NOT EXISTS customers (id INT(11) NOT NULL AUTO_INCREMENT,full_name VARCHAR(255) NOT NULL,service_type VARCHAR(255) NOT NULL,washer_name VARCHAR(255) NOT NULL,contact_number VARCHAR(50) NOT NULL,expected_date DATE NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),KEY idx_expected_date (expected_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                    "CREATE TABLE IF NOT EXISTS day_closures (id INT(11) NOT NULL AUTO_INCREMENT,report_date DATE NOT NULL,closed_by INT(11) DEFAULT NULL,closed_at DATETIME DEFAULT NULL,PRIMARY KEY (id),UNIQUE KEY report_date (report_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                    "CREATE TABLE IF NOT EXISTS prices (id INT(11) NOT NULL AUTO_INCREMENT,service_id INT(11) NOT NULL,car_size_id INT(11) NOT NULL,amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,PRIMARY KEY (id),KEY idx_service_id (service_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                    "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(50) NOT NULL,setting_value TEXT,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                    "CREATE TABLE IF NOT EXISTS tenants (id INT AUTO_INCREMENT PRIMARY KEY,client_name VARCHAR(150) NOT NULL,bay_name VARCHAR(100) NOT NULL,db_name VARCHAR(100) NOT NULL UNIQUE,db_user VARCHAR(100),superadmin_username VARCHAR(100),contact_name VARCHAR(150),contact_phone VARCHAR(30),contact_email VARCHAR(150),monthly_fee DECIMAL(10,2) DEFAULT 0.00,status ENUM('trial','active','blocked','suspended') DEFAULT 'trial',subscription_start DATE,subscription_end DATE,last_renewed_at DATETIME,last_reminder_sent DATETIME NULL,notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                ];
                foreach ($tables as $sql) { $srv->query($sql); }

                // Seed lookups
                foreach (['Engine Body','Engine Only','Interior Cleaning','Normal Washing'] as $s) { $e=$srv->real_escape_string($s); $srv->query("INSERT IGNORE INTO services(name) VALUES('$e')"); }
                foreach (['Cars','Motors','Carpets'] as $c) { $e=$srv->real_escape_string($c); $srv->query("INSERT IGNORE INTO categories(name) VALUES('$e')"); }
                foreach (['Small','Medium','Large','Extra Large'] as $z) { $e=$srv->real_escape_string($z); $srv->query("INSERT IGNORE INTO car_sizes(name) VALUES('$e')"); }
                $messages[] = '✅ Schema and seed data installed.';

                // 3. Create super admin user in new DB
                $hash   = password_hash($sa_password, PASSWORD_DEFAULT);
                $saFull = $sa_name ?: $sa_username;
                if ($st = $srv->prepare("INSERT INTO users(full_name,username,password,role) VALUES(?,?,?,'superadmin') ON DUPLICATE KEY UPDATE password=VALUES(password)")) {
                    $st->bind_param('sss', $saFull, $sa_username, $hash);
                    $st->execute() ? $messages[] = "✅ Super Admin '$sa_username' created." : $errors[] = 'Super Admin error: ' . $srv->error;
                    $st->close();
                }
            }
            // Close schema connection before opening user/grant connection
            $srv->close();
            unset($srv);
        }

        // ── Phase 2: Fresh connection for CREATE USER + GRANT ───────────────
        // GRANT with IDENTIFIED BY is deprecated in MySQL 8+ / MariaDB 10.5+
        // Always use: CREATE USER first, then GRANT separately
        if (!$errors) {
            $srv2Init = mysqli_init();
            if ($srv2Init) {
                mysqli_options($srv2Init, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
                if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                    mysqli_options($srv2Init, MYSQLI_OPT_READ_TIMEOUT, 15);
                }
            }
            $srv2 = ($srv2Init && @mysqli_real_connect($srv2Init, '127.0.0.1', DB_USERNAME, DB_PASSWORD, '', (int)DB_PORT)) ? $srv2Init : null;
            if (!$srv2 || $srv2->connect_error) {
                $errors[] = 'Cannot open GRANT connection: ' . ($srv2 ? $srv2->connect_error : 'unknown');
            } else {
                $srv2->set_charset('utf8mb4');
                $pwdEsc = $srv2->real_escape_string($db_pass);
                foreach (['localhost', '127.0.0.1', '%'] as $h) {
                    // Drop first if force or just ensure user exists
                    $srv2->query("CREATE USER IF NOT EXISTS '$db_user_s'@'$h' IDENTIFIED BY '$pwdEsc'");
                    // Set/update password separately (compatible with MySQL 8 + MariaDB)
                    $srv2->query("ALTER USER '$db_user_s'@'$h' IDENTIFIED BY '$pwdEsc'");
                    // Grant privileges — NO "IDENTIFIED BY" in GRANT (that syntax is gone in MySQL 8+)
                    $srv2->query("GRANT ALL PRIVILEGES ON `$db_name_s`.* TO '$db_user_s'@'$h'");
                }
                $srv2->query('FLUSH PRIVILEGES');
                $messages[] = "✅ Database user `$db_user_s` provisioned with full access.";
                $srv2->close();
                unset($srv2);
            }
        }

        // Re-enable exceptions for the rest of the app
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // 5. Register tenant in CEO console
        if (!$errors) {
            if ($st = $conn->prepare("INSERT INTO tenants(client_name,bay_name,db_name,db_user,superadmin_username,contact_name,contact_phone,contact_email,monthly_fee,status,subscription_start,subscription_end,last_renewed_at) VALUES(?,?,?,?,?,?,?,?,?,'active',?,?,NOW()) ON DUPLICATE KEY UPDATE client_name=VALUES(client_name),status='active',last_renewed_at=NOW()")) {
                $st->bind_param('ssssssssdss', $client_name,$bay_name,$db_name_s,$db_user_s,$sa_username,$contact_name,$contact_phone,$contact_email,$monthly_fee,$sub_start,$sub_end);
                $st->execute() ? $messages[] = "✅ Tenant registered in CEO console." : $errors[] = 'Tenant record error: '.$conn->error;
                $st->close();
                
                // Send SMS and Email notifications
                if (!empty($contact_phone) || !empty($contact_email)) {
                    $login_url = "http://{$_SERVER['HTTP_HOST']}/carwash/login.php";
                    $notif_messages = sendProvisionNotification(
                        $client_name,
                        $bay_name,
                        $contact_name ?: $sa_name,
                        $contact_phone,
                        $contact_email,
                        $sa_username,
                        $sa_password,
                        $login_url,
                        $sub_end
                    );
                    $messages = array_merge($messages, $notif_messages);
                }
            }
        }
    }
}


// ── Load tenant list ─────────────────────────────────────────────────────────
$tenants = [];
if ($tenants_table_ready) {
    $res = $conn->query("SELECT * FROM tenants ORDER BY FIELD(status,'blocked','suspended','trial','active'), client_name ASC");
    if ($res) { while ($t = $res->fetch_assoc()) $tenants[] = $t; }
}
$renewal_requests = [];
if ($reqRes = $conn->query("SELECT rr.*, t.client_name AS tenant_client_name, t.bay_name AS tenant_bay_name
                            FROM renewal_requests rr
                            LEFT JOIN tenants t ON rr.tenant_id = t.id
                            ORDER BY rr.created_at DESC
                            LIMIT 200")) {
    while ($rr = $reqRes->fetch_assoc()) { $renewal_requests[] = $rr; }
}
$show_renewals = (($_GET['show_renewals'] ?? '') === '1') || (($_POST['action'] ?? '') === 'confirm_renewal');

// ── Stats ────────────────────────────────────────────────────────────────────
$total_bays   = count($tenants);
$active_bays  = count(array_filter($tenants, fn($t) => $t['status'] === 'active'));
$blocked_bays = count(array_filter($tenants, fn($t) => in_array($t['status'], ['blocked','suspended'])));
$expiring_soon= count(array_filter($tenants, fn($t) => !empty($t['subscription_end']) && strtotime($t['subscription_end']) <= strtotime('+7 days') && strtotime($t['subscription_end']) > time() && $t['status'] === 'active'));
$monthly_rev  = array_sum(array_column(array_filter($tenants, fn($t) => $t['status']==='active'), 'monthly_fee'));
$expiring_in_3_days = array_values(array_filter($tenants, function ($t) {
    if (($t['status'] ?? '') !== 'active' || empty($t['subscription_end'])) {
        return false;
    }
    $endTs = strtotime((string)$t['subscription_end']);
    return $endTs >= strtotime('today') && $endTs <= strtotime('+3 days 23:59:59');
}));
usort($expiring_in_3_days, function ($a, $b) {
    return strtotime((string)$a['subscription_end']) <=> strtotime((string)$b['subscription_end']);
});
$show_provision = (($_POST['action'] ?? '') === 'provision') || (($_GET['show_provision'] ?? '') === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WashHub CEO Console — GothTech Consult</title>
  <!-- Favicons & App Icons -->
  <link rel="icon" type="image/png" sizes="32x32" href="../frontend/new logo.png?v=2">
  <link rel="icon" type="image/png" sizes="192x192" href="../frontend/new logo.png?v=2">
  <link rel="apple-touch-icon" href="../frontend/new logo.png?v=2">
  <link rel="icon" type="image/png" sizes="16x16" href="../frontend/new logo.png?v=2">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    :root{--brand:#00AEEF;--navy:#1B3FA0;--dark:#0f172a;--bg:#C8EAF9;--card:#fff;--border:#d0e6f5;--text:#1e293b;--muted:#64748b;--green:#10b981;--red:#ef4444;--amber:#f59e0b;}
    body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

    /* Navbar */
    .navbar{background:linear-gradient(135deg,var(--dark) 0%,var(--navy) 100%);padding:0 28px;box-shadow:0 4px 20px rgba(0,0,0,.25);position:sticky;top:0;z-index:100;}
    .navbar-inner{max-width:1320px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;height:66px;gap:16px;}
    .brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
    .brand img{height:40px;width:auto;}
    .brand-text .name{color:#fff;font-size:1.2rem;font-weight:800;}
    .brand-text .sub{color:rgba(255,255,255,.6);font-size:.7rem;letter-spacing:.8px;text-transform:uppercase;}
    .nav-right{display:flex;align-items:center;gap:10px;}
    .badge-ceo{background:linear-gradient(135deg,var(--brand),var(--navy));color:#fff;font-size:.7rem;font-weight:700;padding:4px 12px;border-radius:20px;letter-spacing:.6px;}
    .nbtn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:.83rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:filter .15s;}
    .nbtn:hover{filter:brightness(1.12);}
    .nbtn.ghost{background:rgba(255,255,255,.13);color:#fff;}
    .nbtn.danger{background:var(--red);color:#fff;}
    .ceo-chip{display:inline-flex;align-items:center;gap:9px;text-decoration:none;background:rgba(255,255,255,.14);padding:5px 10px;border-radius:999px;color:#fff;}
    .ceo-chip:hover{background:rgba(255,255,255,.22);}
    .ceo-chip img{width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.55);background:#fff;}
    .ceo-chip .name{font-size:.82rem;font-weight:700;max-width:190px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

    /* Page */
    .page{max-width:1320px;margin:0 auto;padding:28px 24px 60px;}

    /* Welcome */
    .welcome{background:linear-gradient(135deg,var(--navy) 0%,var(--brand) 100%);border-radius:16px;padding:26px 32px;color:#fff;margin-bottom:26px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;}
    .welcome h1{font-size:1.6rem;font-weight:800;margin-bottom:3px;}
    .welcome p{opacity:.85;font-size:.9rem;}
    .welcome-sub{font-size:.78rem;opacity:.65;margin-top:5px;}
    .wa{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;font-weight:700;font-size:.85rem;text-decoration:none;transition:filter .15s,transform .1s;}
    .wa:hover{filter:brightness(1.08);transform:translateY(-1px);}
    .wa.white{background:#fff;color:var(--navy);}
    .wa.outline{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.35);}

    /* Alerts */
    .alert{padding:11px 15px;border-radius:9px;margin-bottom:10px;display:flex;align-items:center;gap:9px;font-size:.88rem;font-weight:500;}
    .alert-ok{background:#ecfdf5;border-left:4px solid var(--green);color:#064e3b;}
    .alert-err{background:#fef2f2;border-left:4px solid var(--red);color:#7f1d1d;}
    .alert-warn{background:#fff7ed;border-left:4px solid var(--amber);color:#92400e;}
    .expiring-list{margin-top:8px;display:flex;flex-direction:column;gap:8px;}
    .expiring-item{display:flex;justify-content:space-between;align-items:center;gap:8px;background:rgba(255,255,255,.55);border:1px solid #fed7aa;border-radius:8px;padding:8px 10px;font-size:.83rem;}
    .expiring-left{display:flex;flex-direction:column;gap:2px;}
    .expiring-days{font-size:.75rem;color:#9a3412;font-weight:700;}
    .btn-mini{padding:5px 9px;border:none;border-radius:6px;font-size:.73rem;font-weight:700;cursor:pointer;background:#f59e0b;color:#fff;}
    .btn-mini:hover{filter:brightness(1.06);}

    /* Stats */
    .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:26px;}
    @media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr);}}
    .stat{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
    .stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:12px;}
    .si-blue{background:#dbeafe;color:#1d4ed8;} .si-green{background:#d1fae5;color:#065f46;}
    .si-red{background:#fee2e2;color:#991b1b;}   .si-amber{background:#fef3c7;color:#92400e;}
    .si-purple{background:#ede9fe;color:#5b21b6;}
    .stat-val{font-size:1.6rem;font-weight:800;line-height:1;}
    .stat-lbl{font-size:.75rem;color:var(--muted);margin-top:4px;font-weight:500;}

    /* Section */
    .sec-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
    .sec-hdr h2{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--dark);}
    .sec-hdr h2 i{color:var(--brand);}

    /* Cards */
    .card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,.06);margin-bottom:24px;}
    .card-hdr{padding:15px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:#f8fbfe;}
    .card-hdr h3{font-size:.93rem;font-weight:700;margin:0;}
    .card-body{padding:22px;}

    /* Grid */
    .grid2{display:grid;grid-template-columns:1.1fr 0.9fr;gap:20px;}
    @media(max-width:900px){.grid2{grid-template-columns:1fr;}}

    /* Provision form */
    .fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media(max-width:560px){.fgrid{grid-template-columns:1fr;}}
    .fg{display:flex;flex-direction:column;gap:5px;}
    .fg.full{grid-column:1/-1;}
    .fg label{font-size:.73rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;}
    .fg input,.fg select{padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.88rem;font-family:inherit;color:var(--text);background:#fff;transition:border-color .2s;}
    .fg input:focus,.fg select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(0,174,239,.12);}
    .fg .hint{font-size:.72rem;color:var(--muted);margin-top:2px;}
    .divider{grid-column:1/-1;border:none;border-top:1.5px dashed var(--border);margin:6px 0;}
    .section-label{grid-column:1/-1;font-size:.78rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px;}
    .section-label::after{content:'';flex:1;height:1px;background:var(--border);}
    .form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:10px;}
    .btn-prim{padding:10px 22px;background:linear-gradient(135deg,var(--navy),var(--brand));color:#fff;border:none;border-radius:9px;font-weight:700;font-size:.88rem;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:filter .15s,transform .1s;box-shadow:0 3px 12px rgba(0,174,239,.25);}
    .btn-prim:hover{filter:brightness(1.08);transform:translateY(-1px);}
    .btn-ghost{padding:8px 14px;border:1.5px solid var(--border);background:#fff;color:var(--muted);border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;}
    .provision-wrap{display:none;}
    .provision-wrap.show{display:grid;}
    .renewals-wrap{display:none;}
    .renewals-wrap.show{display:block;}
    .notifications-wrap{display:none;}
    .notifications-wrap.show{display:block;}

    /* Notification items */
    .notif-item{padding:16px;border-bottom:1px solid var(--border);transition:background .15s;}
    .notif-item:last-child{border-bottom:none;}
    .notif-item.unread{background:#f0f9ff;}
    .notif-item:hover{background:#f8fbff;}
    .notif-item.unread:hover{background:#e0f2fe;}

    /* Modal */
    .modal-wrap{position:fixed;top:0;left:0;width:100%;height:100%;z-index:2000;display:none;align-items:center;justify-content:center;}
    .modal-wrap.show{display:flex;}
    .modal-overlay{position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1;}
    .modal-box{position:relative;background:#fff;border-radius:16px;max-width:600px;width:90%;max-height:85vh;overflow:hidden;z-index:2;box-shadow:0 25px 50px rgba(0,0,0,0.3);}
    .modal-hdr{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--border);background:#f8fbfe;}
    .modal-hdr h3{font-size:1.1rem;font-weight:700;margin:0;color:var(--dark);display:flex;align-items:center;gap:8px;}
    .modal-close{background:none;border:none;font-size:1.8rem;color:var(--muted);cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:background .15s;}
    .modal-close:hover{background:rgba(0,0,0,0.05);color:var(--dark);}
    .modal-body{padding:24px;overflow-y:auto;max-height:calc(85vh - 80px);}
    .detail-row{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f0f6fb;}
    .detail-row:last-child{border-bottom:none;}
    .detail-label{font-weight:600;color:var(--muted);font-size:.85rem;}
    .detail-value{font-weight:600;color:var(--text);font-size:.9rem;text-align:right;}
    .detail-value.large{font-size:1rem;}

    /* Table */
    .tbl-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;font-size:.84rem;}
    th{padding:10px 14px;background:#f0f7fc;color:var(--muted);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid var(--border);text-align:left;white-space:nowrap;}
    td{padding:11px 14px;border-bottom:1px solid #f0f6fb;vertical-align:middle;}
    tr:last-child td{border-bottom:none;}
    tr:hover td{background:#f7fbff;}

    /* Status badge */
    .badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;}
    .b-active{background:#d1fae5;color:#065f46;}
    .b-trial{background:#dbeafe;color:#1e40af;}
    .b-blocked{background:#fee2e2;color:#991b1b;}
    .b-suspended{background:#fef3c7;color:#92400e;}
    .b-expiring{background:#fef3c7;color:#92400e;}

    /* Action buttons */
    .act-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:7px;font-size:.76rem;font-weight:700;border:none;cursor:pointer;text-decoration:none;transition:filter .15s;}
    .act-btn:hover{filter:brightness(1.1);}
    .ab-block{background:#fee2e2;color:#991b1b;}
    .ab-unblock{background:#d1fae5;color:#065f46;}
    .ab-renew{background:linear-gradient(135deg,var(--navy),var(--brand));color:#fff;}
    .ab-view{background:#f1f5f9;color:var(--muted);}
    .acts{display:flex;gap:6px;flex-wrap:wrap;}
    .rr-pending{background:#fef3c7;color:#92400e;}
    .rr-confirmed{background:#d1fae5;color:#065f46;}
    .rr-rejected{background:#fee2e2;color:#991b1b;}

    /* Expiry indicator */
    .exp-ok{color:var(--green);}
    .exp-warn{color:var(--amber);}
    .exp-bad{color:var(--red);font-weight:700;}

    /* Empty state */
    .empty{text-align:center;padding:40px 20px;color:var(--muted);}
    .empty i{font-size:2.5rem;opacity:.3;margin-bottom:12px;display:block;}

    /* Credentials box */
    .creds-box{background:linear-gradient(135deg,var(--dark),var(--navy));border-radius:12px;padding:16px 18px;color:#fff;}
    .creds-box h4{font-size:.88rem;font-weight:700;margin-bottom:12px;opacity:.8;}
    .cred-row{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:rgba(255,255,255,.08);border-radius:7px;margin-bottom:7px;}
    .cred-row .lbl{font-size:.72rem;opacity:.7;}
    .cred-row .val{font-weight:700;font-family:monospace;font-size:.88rem;}
    .copy-btn{background:rgba(255,255,255,.2);border:none;border-radius:5px;color:#fff;padding:3px 9px;font-size:.72rem;cursor:pointer;}
    .copy-btn:hover{background:rgba(255,255,255,.35);}

    footer{text-align:center;padding:16px;color:var(--muted);font-size:.78rem;border-top:1px solid var(--border);}
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="dev_portal.php" class="brand">
      <img src="../frontend/new logo.png" alt="WashHub">
      <div class="brand-text">
        <div class="name">WashHub</div>
        <div class="sub">CEO Console</div>
      </div>
    </a>
    <div class="nav-right">
      <span class="badge-ceo">🔑 CEO Access</span>
      <a href="dev_profile.php" class="ceo-chip" title="Edit Profile">
        <img src="<?php echo htmlspecialchars($ceo_avatar); ?>" alt="CEO">
        <span class="name"><?php echo htmlspecialchars($ceo_display_name); ?></span>
      </a>
      <button type="button" class="nbtn" onclick="toggleNotifications(true)" style="position:relative;background:rgba(255,255,255,.15);color:#fff;border:none;padding:8px 16px;border-radius:8px;font-weight:600;font-size:.83rem;cursor:pointer;transition:filter .15s;">
        <i class="fas fa-bell"></i> Notifications
        <?php if ($unread_count > 0): ?>
        <span style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;padding:2px 6px;border-radius:99px;min-width:18px;text-align:center;"><?php echo $unread_count; ?></span>
        <?php endif; ?>
      </button>
      <a href="dev_login.php?logout=1" class="nbtn danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>
</nav>

<div class="page">

  <!-- Welcome -->
  <div class="welcome">
    <div>
      <h1>WashHub CEO Console 🚗</h1>
      <p>Provision, manage and control all client washing bays from one place.</p>
      <div class="welcome-sub"><?php echo date('l, F j, Y  ·  g:i A'); ?> — GothTech Consult HQ</div>
    </div>
    <div style="display:flex;align-items:center;gap:60px;flex-wrap:wrap;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" class="wa white" style="border:none;cursor:pointer;" onclick="toggleProvision(true)"><i class="fas fa-plus-circle"></i> Provision New Bay</button>
        <a href="#clients" class="wa outline"><i class="fas fa-list"></i> View All Clients</a>
        <button type="button" class="wa outline" style="border:none;cursor:pointer;" onclick="toggleRenewals(true)"><i class="fas fa-money-check-dollar"></i> Renewal Submissions</button>
        <form method="POST" style="margin:0;" data-confirm="Send renewal reminders to all bays expiring within 3 days?">
          <input type="hidden" name="action" value="send_all_reminders">
          <button type="submit" class="wa" style="background:rgba(245,158,11,.9);color:#fff;border:none;cursor:pointer;font-family:inherit;"><i class="fas fa-bell"></i> Send All Reminders</button>
        </form>
      </div>
      <div style="display:flex;align-items:center;gap:15px;">
        <img src="<?php echo htmlspecialchars($ceo_avatar); ?>" alt="CEO" style="width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid rgba(255,255,255,.5);">
        <div style="text-align:right;">
          <div style="font-weight:800;font-size:1.3rem;color:#fff;"><?php echo htmlspecialchars($ceo_display_name); ?></div>
          <div style="font-size:.85rem;opacity:.9;font-weight:600;">CEO</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Alerts -->
  <?php foreach ($messages as $m): ?>
    <div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($m); ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $e): ?>
    <div class="alert alert-err"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($e); ?></div>
  <?php endforeach; ?>

  <!-- Notifications Panel -->
  <div id="notifications-panel" class="notifications-wrap <?php echo $show_notifications ? 'show' : ''; ?>">
    <div class="sec-hdr">
      <h2><i class="fas fa-bell"></i> Notifications</h2>
      <div style="display:flex;gap:8px;align-items:center;">
        <span style="font-size:.8rem;color:var(--muted);"><?php echo count($notifications); ?> notification(s)</span>
        <?php if ($unread_count > 0): ?>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="mark_all_read">
          <button type="submit" class="btn-ghost" style="padding:6px 12px;font-size:.75rem;">Mark All Read</button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn-ghost" onclick="toggleNotifications(false)"><i class="fas fa-times"></i> Close</button>
      </div>
    </div>
    <div class="card">
      <div class="card-body" style="padding:0;">
        <?php if (empty($notifications)): ?>
          <div class="empty">
            <i class="fas fa-bell-slash"></i>
            <div style="font-weight:600;margin-bottom:6px;">No notifications yet</div>
            <div style="font-size:.85rem;">New contact submissions and alerts will appear here.</div>
          </div>
        <?php else: ?>
          <div style="max-height:500px;overflow-y:auto;">
            <?php foreach ($notifications as $n): ?>
              <div class="notif-item <?php echo ($n['is_read'] ?? 0) == 0 ? 'unread' : ''; ?>">
                <div style="display:flex;gap:12px;align-items:flex-start;">
                  <div style="width:40px;height:40px;border-radius:50%;background:<?php echo ($n['is_read'] ?? 0) == 0 ? 'var(--brand)' : 'var(--muted)'; ?>;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?php echo ($n['type'] ?? '') === 'contact' ? 'fa-envelope' : 'fa-bell'; ?>"></i>
                  </div>
                  <div style="flex:1;">
                    <div style="font-weight:700;font-size:.9rem;color:var(--dark);"><?php echo htmlspecialchars($n['title'] ?? ''); ?></div>
                    <?php if (!empty($n['message'])): ?>
                    <div style="font-size:.85rem;color:var(--muted);margin-top:4px;"><?php echo htmlspecialchars($n['message']); ?></div>
                    <?php endif; ?>
                    <div style="font-size:.75rem;color:var(--muted);margin-top:6px;">
                      <i class="fas fa-clock"></i> <?php echo date('d M Y, g:ia', strtotime((string)$n['created_at'])); ?>
                    </div>
                  </div>
                  <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
                    <?php if (($n['is_read'] ?? 0) == 0): ?>
                    <form method="POST" style="margin:0;">
                      <input type="hidden" name="action" value="mark_read">
                      <input type="hidden" name="nid" value="<?php echo (int)$n['id']; ?>">
                      <button type="submit" class="btn-mini" style="background:var(--brand);">Mark Read</button>
                    </form>
                    <?php endif; ?>
                    <?php if (!empty($n['link_url'])): ?>
                    <button type="button" class="btn-mini" style="background:var(--ink);" onclick="viewContactSubmission(<?php echo (int)$n['id']; ?>)">View</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Contact Details Modal -->
  <div id="contact-modal" class="modal-wrap" style="display:none;">
    <div class="modal-overlay" onclick="closeContactModal()"></div>
    <div class="modal-box">
      <div class="modal-hdr">
        <h3><i class="fas fa-user"></i> Contact Submission Details</h3>
        <button type="button" class="modal-close" onclick="closeContactModal()">&times;</button>
      </div>
      <div class="modal-body" id="contact-modal-content">
        <div style="text-align:center;padding:30px;">
          <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--brand);"></i>
          <div style="margin-top:15px;color:var(--muted);">Loading...</div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($expiring_in_3_days)): ?>
    <div class="alert alert-warn" style="display:block;">
      <div style="display:flex;align-items:center;gap:9px;font-weight:700;"><i class="fas fa-bell"></i> <?php echo count($expiring_in_3_days); ?> client(s) expire within 3 days</div>
      <div class="expiring-list">
        <?php foreach ($expiring_in_3_days as $t): $daysLeft = max(0, (int)ceil((strtotime((string)$t['subscription_end']) - time()) / 86400)); ?>
          <div class="expiring-item">
            <div class="expiring-left">
              <strong><?php echo htmlspecialchars($t['client_name']); ?> — <?php echo htmlspecialchars($t['bay_name']); ?></strong>
              <span class="expiring-days">Expires <?php echo date('d M Y', strtotime((string)$t['subscription_end'])); ?> (<?php echo $daysLeft; ?> day<?php echo $daysLeft === 1 ? '' : 's'; ?> left)</span>
            </div>
            <form method="POST" style="margin:0;" data-confirm="Send reminder to <?php echo htmlspecialchars(addslashes($t['contact_name'] ?: $t['client_name'])); ?> now?">
              <input type="hidden" name="action" value="send_reminder">
              <input type="hidden" name="tid" value="<?php echo (int)$t['id']; ?>">
              <button type="submit" class="btn-mini"><i class="fas fa-paper-plane"></i> Send</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats">
    <div class="stat">
      <div class="stat-icon si-blue"><i class="fas fa-store"></i></div>
      <div class="stat-val"><?php echo $total_bays; ?></div>
      <div class="stat-lbl">Total Client Bays</div>
    </div>
    <div class="stat">
      <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
      <div class="stat-val"><?php echo $active_bays; ?></div>
      <div class="stat-lbl">Active Bays</div>
    </div>
    <div class="stat">
      <div class="stat-icon si-red"><i class="fas fa-ban"></i></div>
      <div class="stat-val"><?php echo $blocked_bays; ?></div>
      <div class="stat-lbl">Blocked / Suspended</div>
    </div>
    <div class="stat">
      <div class="stat-icon si-amber"><i class="fas fa-clock"></i></div>
      <div class="stat-val"><?php echo $expiring_soon; ?></div>
      <div class="stat-lbl">Expiring in 7 Days</div>
    </div>
    <div class="stat">
      <div class="stat-icon si-purple"><i class="fas fa-coins"></i></div>
      <div class="stat-val">GH₵<?php echo number_format($monthly_rev, 0); ?></div>
      <div class="stat-lbl">Monthly Revenue</div>
    </div>
  </div>

  <!-- Client Table -->
  <div id="clients">
    <div class="sec-hdr">
      <h2><i class="fas fa-store"></i> Client Washing Bays</h2>
      <span style="font-size:.8rem;color:var(--muted);"><?php echo $total_bays; ?> bays registered</span>
    </div>
    <div class="card">
      <div class="tbl-wrap">
        <?php if (empty($tenants)): ?>
          <div class="empty">
            <i class="fas fa-store-slash"></i>
            <div style="font-weight:600;margin-bottom:6px;">No client bays yet</div>
            <div style="font-size:.85rem;">Provision the first bay below.</div>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Client / Bay</th>
              <th>Contact</th>
              <th>Database & Login</th>
              <th>SuperAdmin</th>
              <th>Status</th>
              <th>Subscription End</th>
              <th>Fee/mo</th>
              <th>Last Reminder</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tenants as $i => $t):
              $end_ts  = !empty($t['subscription_end']) ? strtotime($t['subscription_end']) : null;
              $days_left = $end_ts ? ceil(($end_ts - time()) / 86400) : null;
              $exp_cls = $days_left === null ? '' : ($days_left < 0 ? 'exp-bad' : ($days_left <= 7 ? 'exp-warn' : 'exp-ok'));
              $status_class = ['active'=>'b-active','trial'=>'b-trial','blocked'=>'b-blocked','suspended'=>'b-suspended'][$t['status']] ?? 'b-trial';
            ?>
            <tr>
              <td><?php echo $i+1; ?></td>
              <td>
                <div style="font-weight:700;"><?php echo htmlspecialchars($t['client_name']); ?></div>
                <div style="font-size:.77rem;color:var(--muted);"><?php echo htmlspecialchars($t['bay_name']); ?></div>
              </td>
              <td>
                <div><?php echo htmlspecialchars($t['contact_name'] ?: '—'); ?></div>
                <div style="font-size:.77rem;color:var(--muted);"><?php echo htmlspecialchars($t['contact_phone'] ?: ''); ?></div>
              </td>
              <td>
                <a href="login.php?bay=<?php echo urlencode($t['db_name']); ?>" target="_blank" style="text-decoration:none;">
                  <code style="font-size:.78rem;background:#00AEEF;color:#fff;padding:4px 8px;border-radius:4px;display:inline-flex;align-items:center;gap:4px;">
                    <i class="fas fa-external-link-alt" style="font-size:10px;"></i> <?php echo htmlspecialchars($t['db_name']); ?>
                  </code>
                </a>
              </td>
              <td><?php echo htmlspecialchars($t['superadmin_username'] ?: '—'); ?></td>
              <td>
                <span class="badge <?php echo $status_class; ?>">
                  <?php echo ucfirst($t['status']); ?>
                  <?php if ($days_left !== null && $days_left <= 7 && $t['status']==='active'): ?>
                    &nbsp;<i class="fas fa-triangle-exclamation"></i>
                  <?php endif; ?>
                </span>
              </td>
              <td>
                <?php if ($end_ts): ?>
                  <span class="<?php echo $exp_cls; ?>">
                    <?php echo date('d M Y', $end_ts); ?>
                  </span>
                  <div style="font-size:.73rem;color:var(--muted);">
                    <?php echo $days_left >= 0 ? "$days_left days left" : abs($days_left).' days overdue'; ?>
                  </div>
                <?php else: echo '<span style="color:var(--muted);">—</span>'; endif; ?>
              </td>
              <td>GH₵<?php echo number_format($t['monthly_fee'], 2); ?></td>
              <td>
                <?php if (!empty($t['last_reminder_sent'])): ?>
                  <div style="font-size:.77rem;"><?php echo date('d M, g:ia', strtotime($t['last_reminder_sent'])); ?></div>
                  <div style="font-size:.72rem;color:var(--muted);">via SMS</div>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.78rem;">Never sent</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="acts">
                  <!-- Renew -->
                  <form method="POST" style="display:inline;" data-confirm-title="Renew Subscription" data-confirm="Mark as renewed for 30 days?" data-confirm-type="success">
                    <input type="hidden" name="action" value="renew">
                    <input type="hidden" name="tid" value="<?php echo $t['id']; ?>">
                    <button class="act-btn ab-renew" type="submit"><i class="fas fa-rotate-right"></i> Renew</button>
                  </form>
                  <!-- Block / Unblock -->
                  <?php if (in_array($t['status'], ['blocked','suspended'])): ?>
                    <form method="POST" style="display:inline;" data-confirm-title="Restore Access" data-confirm="Restore access for this bay?" data-confirm-type="success">
                      <input type="hidden" name="action" value="unblock">
                      <input type="hidden" name="tid" value="<?php echo $t['id']; ?>">
                      <button class="act-btn ab-unblock" type="submit"><i class="fas fa-unlock"></i> Unblock</button>
                    </form>
                  <?php else: ?>
                    <form method="POST" style="display:inline;" data-confirm-title="Block Tenant" data-confirm="Block this bay? They will not be able to log in." data-confirm-type="danger">
                      <input type="hidden" name="action" value="block">
                      <input type="hidden" name="tid" value="<?php echo $t['id']; ?>">
                      <button class="act-btn ab-block" type="submit"><i class="fas fa-ban"></i> Block</button>
                    </form>
                    <!-- SMS Reminder -->
                    <form method="POST" style="display:inline;" data-confirm-title="Send Reminder" data-confirm="Send an SMS renewal reminder to <?php echo htmlspecialchars(addslashes($t['contact_name'])); ?>?" data-confirm-type="success">
                      <input type="hidden" name="action" value="send_reminder">
                      <input type="hidden" name="tid" value="<?php echo $t['id']; ?>">
                      <button class="act-btn" style="background:#fef3c7;color:#92400e;" type="submit"><i class="fas fa-bell"></i> Remind</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Renewal Requests -->
  <div id="renewal-requests" class="renewals-wrap <?php echo $show_renewals ? 'show' : ''; ?>">
    <div class="sec-hdr">
      <h2><i class="fas fa-money-check-dollar"></i> Renewal Submissions</h2>
      <div style="display:flex;gap:8px;align-items:center;">
        <span style="font-size:.8rem;color:var(--muted);"><?php echo count($renewal_requests); ?> submission(s)</span>
        <button type="button" class="btn-ghost" onclick="toggleRenewals(false)"><i class="fas fa-times"></i> Close</button>
      </div>
    </div>
    <div class="card">
      <div class="tbl-wrap">
        <?php if (empty($renewal_requests)): ?>
          <div class="empty">
            <i class="fas fa-file-circle-xmark"></i>
            <div style="font-weight:600;margin-bottom:6px;">No renewal submissions yet</div>
            <div style="font-size:.85rem;">Client renewal forms will appear here for CEO confirmation.</div>
          </div>
        <?php else: ?>
          <?php $latest_rr = $renewal_requests[0]; ?>
          <div style="padding:14px 16px;background:#f8fbff;border-bottom:1px solid var(--border);">
            <strong>Most Recent:</strong>
            <?php echo htmlspecialchars(($latest_rr['tenant_client_name'] ?: $latest_rr['client_name']) . ' — ' . ($latest_rr['tenant_bay_name'] ?: ($latest_rr['bay_name'] ?? ''))); ?>
            <span style="color:var(--muted);font-size:.8rem;">
              (<?php echo date('d M Y, g:ia', strtotime((string)$latest_rr['created_at'])); ?>)
            </span>
          </div>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Client / Bay</th>
                <th>Owner</th>
                <th>Payment</th>
                <th>Contact</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($renewal_requests as $i => $rr):
                $rrStatus = strtolower((string)($rr['status'] ?? 'pending'));
                $rrClass = $rrStatus === 'confirmed' ? 'rr-confirmed' : ($rrStatus === 'rejected' ? 'rr-rejected' : 'rr-pending');
                $clientLabel = $rr['tenant_client_name'] ?: $rr['client_name'];
                $bayLabel = $rr['tenant_bay_name'] ?: ($rr['bay_name'] ?? '—');
              ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td>
                    <div style="font-weight:700;"><?php echo htmlspecialchars($clientLabel); ?></div>
                    <div style="font-size:.77rem;color:var(--muted);"><?php echo htmlspecialchars($bayLabel); ?> · <code><?php echo htmlspecialchars($rr['tenant_db_name']); ?></code></div>
                  </td>
                  <td><?php echo htmlspecialchars($rr['owner_name']); ?></td>
                  <td>
                    <div style="font-weight:700;"><?php echo strtoupper((string)$rr['payment_network']); ?></div>
                    <div style="font-size:.77rem;color:var(--muted);"><?php echo htmlspecialchars($rr['payment_reference']); ?> · <?php echo date('d M Y', strtotime((string)$rr['payment_date'])); ?></div>
                  </td>
                  <td><?php echo htmlspecialchars($rr['contact_phone']); ?></td>
                  <td><span class="badge <?php echo $rrClass; ?>"><?php echo ucfirst($rrStatus); ?></span></td>
                  <td>
                    <div style="font-size:.77rem;"><?php echo date('d M, g:ia', strtotime((string)$rr['created_at'])); ?></div>
                    <?php if (!empty($rr['confirmation_message'])): ?>
                      <div style="font-size:.73rem;color:var(--muted);margin-top:4px;"><?php echo htmlspecialchars($rr['confirmation_message']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($rrStatus === 'pending'): ?>
                      <form method="POST" style="margin:0;" data-confirm="Confirm this renewal and extend subscription for 30 days?">
                        <input type="hidden" name="action" value="confirm_renewal">
                        <input type="hidden" name="rid" value="<?php echo (int)$rr['id']; ?>">
                        <button class="act-btn ab-renew" type="submit"><i class="fas fa-check"></i> Confirm</button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:.75rem;color:var(--muted);">By <?php echo htmlspecialchars($rr['confirmed_by'] ?: 'CEO'); ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Provision + Credentials -->
  <div class="grid2 provision-wrap <?php echo $show_provision ? 'show' : ''; ?>" id="provision">

    <!-- Provision Form -->
    <div class="card">
      <div class="card-hdr">
        <i class="fas fa-rocket" style="color:var(--brand);"></i>
        <h3>Provision New Client Bay</h3>
        <button type="button" class="btn-ghost" style="margin-left:auto;" onclick="toggleProvision(false)"><i class="fas fa-times"></i> Close</button>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="provision">
          <div class="fgrid">

            <div class="section-label"><i class="fas fa-building"></i> Client Info</div>

            <div class="fg">
              <label>Client / Business Name *</label>
              <input type="text" name="client_name" placeholder="e.g. Accra Premium Wash" required>
            </div>
            <div class="fg">
              <label>Bay / Branch Name *</label>
              <input type="text" name="bay_name" placeholder="e.g. Accra CBD Branch">
            </div>
            <div class="fg">
              <label>Contact Person</label>
              <input type="text" name="contact_name" placeholder="Full name">
            </div>
            <div class="fg">
              <label>Contact Phone</label>
              <input type="text" name="contact_phone" placeholder="+233 xx xxx xxxx">
            </div>
            <div class="fg full">
              <label>Contact Email</label>
              <input type="text" name="contact_email" placeholder="email@example.com">
            </div>
            <div class="fg">
              <label>Monthly Fee (GH₵)</label>
              <input type="text" name="monthly_fee" placeholder="0.00" value="500">
            </div>
            <div class="fg">
              <label>Subscription Start</label>
              <input type="text" name="sub_start" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="fg full">
              <label>Subscription End</label>
              <input type="text" name="sub_end" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>

            <div class="section-label"><i class="fas fa-database"></i> Database Setup</div>

            <div class="fg">
              <label>Database Name *</label>
              <input type="text" name="db_name" placeholder="washhub_accra01" required>
              <div class="hint">Letters, numbers, underscore only.</div>
            </div>
            <div class="fg">
              <label>DB Username *</label>
              <input type="text" name="db_user" placeholder="washhub_user01" required>
            </div>
            <div class="fg full">
              <label>DB Password *</label>
              <input type="password" name="db_pass" placeholder="Strong database password" required>
            </div>

            <div class="section-label"><i class="fas fa-user-shield"></i> Super Admin (Bay Owner)</div>

            <div class="fg">
              <label>Full Name</label>
              <input type="text" name="sa_name" placeholder="e.g. Kwame Mensah">
            </div>
            <div class="fg">
              <label>Username *</label>
              <input type="text" name="sa_username" placeholder="e.g. kwame.accra" required>
            </div>
            <div class="fg full">
              <label>Password *</label>
              <input type="password" name="sa_password" placeholder="Initial login password" required>
              <div class="hint">Give this to the bay owner. They should change it after first login.</div>
            </div>

          </div>
          <div class="form-actions">
            <button type="submit" class="btn-prim"><i class="fas fa-rocket"></i> Provision Bay & Create Super Admin</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Info + Access -->
    <div>
      <div class="card" style="margin-bottom:18px;">
        <div class="card-hdr"><i class="fas fa-list-check" style="color:var(--green);"></i><h3>Onboarding Flow</h3></div>
        <div class="card-body">
          <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach([
              ['1','Provision the bay','Fill the form on the left — DB, schema, and Super Admin are set up automatically.','fas fa-rocket','#dbeafe','#1d4ed8'],
              ['2','Share login details','Give the bay owner their username, password and the login URL.','fas fa-share-nodes','#d1fae5','#065f46'],
              ['3','Owner logs in','They sign in as Super Admin and create an Admin account for daily operations.','fas fa-user-shield','#ede9fe','#5b21b6'],
              ['4','Monthly renewal','Click Renew in the client table when they pay. Block if they don\'t.','fas fa-rotate-right','#fef3c7','#92400e'],
            ] as [$n,$title,$desc,$icon,$bg,$clr]): ?>
            <div style="display:flex;gap:12px;align-items:flex-start;">
              <div style="width:32px;height:32px;border-radius:50%;background:<?php echo $bg;?>;color:<?php echo $clr;?>;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;font-weight:800;"><?php echo $n;?></div>
              <div>
                <div style="font-weight:700;font-size:.88rem;"><?php echo $title;?></div>
                <div style="font-size:.78rem;color:var(--muted);margin-top:2px;"><?php echo $desc;?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-hdr"><i class="fas fa-key" style="color:var(--amber);"></i><h3>CEO Console Access</h3></div>
        <div class="card-body">
          <div class="creds-box">
            <h4>🔐 Login Credentials (from .env)</h4>
            <div class="cred-row">
              <div><div class="lbl">URL</div><div class="val" style="font-size:.75rem;">carwash/dev_login.php</div></div>
              <button class="copy-btn" onclick="navigator.clipboard.writeText(location.origin+'/<?php echo trim(str_replace('/dev_portal.php','',ltrim($_SERVER['PHP_SELF'],'/')));?>/dev_login.php');this.textContent='Copied!'">Copy</button>
            </div>
            <div class="cred-row">
              <div><div class="lbl">Username</div><div class="val"><?php echo htmlspecialchars(DEV_USERNAME); ?></div></div>
            </div>
            <div class="cred-row">
              <div><div class="lbl">Client Login URL</div><div class="val" style="font-size:.75rem;">carwash/login.php</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /grid2 -->

</div><!-- /page -->

<footer>&copy; <?php echo date('Y'); ?> GothTech Consult &mdash; WashHub CEO Console &mdash; All rights reserved.</footer>

<script>
  function toggleProvision(show) {
    const section = document.getElementById('provision');
    if (!section) return;
    if (show) {
      section.classList.add('show');
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    section.classList.remove('show');
  }
  function toggleRenewals(show) {
    const section = document.getElementById('renewal-requests');
    if (!section) return;
    if (show) {
      section.classList.add('show');
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    section.classList.remove('show');
  }
  function toggleNotifications(show) {
    const section = document.getElementById('notifications-panel');
    if (!section) return;
    if (show) {
      section.classList.add('show');
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      section.classList.remove('show');
    }
  }

  function viewContactSubmission(notifId) {
    const modal = document.getElementById('contact-modal');
    const content = document.getElementById('contact-modal-content');

    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--brand);"></i><div style="margin-top:15px;color:var(--muted);">Loading...</div></div>';

    // Get contact_id directly from notification
    const notif = <?php echo json_encode($notifications); ?>;
    // Fix type mismatch - convert both to number for comparison
    const notification = notif.find(n => parseInt(n.id) === parseInt(notifId));
    if (!notification) {
      content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--red);"><i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i><div style="margin-top:15px;">Notification not found</div></div>';
      return;
    }

    const contactId = notification.contact_id;
    if (!contactId) {
      content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--red);"><i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i><div style="margin-top:15px;">No contact ID associated with this notification</div></div>';
      return;
    }

    // Fetch contact details via AJAX
    fetch('get_contact_details.php?id=' + contactId)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const contact = data.contact;
          content.innerHTML = `
            <div class="detail-row">
              <span class="detail-label">Name</span>
              <span class="detail-value large">${contact.name || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Email</span>
              <span class="detail-value">${contact.email || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Phone</span>
              <span class="detail-value">${contact.phone || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Business</span>
              <span class="detail-value">${contact.business || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Region</span>
              <span class="detail-value">${contact.region || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Town/City</span>
              <span class="detail-value">${contact.town || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Status</span>
              <span class="detail-value" style="text-transform:capitalize;">${contact.status || '-'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Submitted</span>
              <span class="detail-value">${contact.created_at || '-'}</span>
            </div>
            <div style="margin-top:20px;">
              <div class="detail-label" style="margin-bottom:8px;">Message</div>
              <div style="background:#f8fbfe;padding:16px;border-radius:8px;border:1px solid var(--border);font-size:.9rem;line-height:1.6;color:var(--text);">${contact.message || '-'}</div>
            </div>
            <div style="margin-top:24px;display:flex;gap:10px;justify-content:flex-end;">
              <a href="https://wa.me/233${contact.phone.replace(/^0/, '')}?text=Hi%20${encodeURIComponent(contact.name)},%20I%20received%20your%20inquiry%20about%20WashHub." target="_blank" class="btn-mini" style="background:#25D366;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                <i class="fab fa-whatsapp"></i> Reply on WhatsApp
              </a>
            </div>
          `;
        } else {
          content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--red);"><i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i><div style="margin-top:15px;">' + (data.message || 'Failed to load contact details') + '</div></div>';
        }
      })
      .catch(err => {
        content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--red);"><i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i><div style="margin-top:15px;">Network error occurred</div></div>';
      });
  }

  function closeContactModal() {
    const modal = document.getElementById('contact-modal');
    modal.style.display = 'none';
  }
</script>

</body>
</html>
