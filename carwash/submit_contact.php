<?php
// Contact Form Submission Handler
header('Content-Type: application/json');

// Load .env file
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

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Rate limiting: max 5 submissions per minute per IP
if (!checkRateLimit('contact_form', 5, 60)) {
    logSecurityEvent('RATE_LIMIT_EXCEEDED', ['action' => 'contact_form']);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}

// CSRF validation
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'contact_form']);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$business = trim($_POST['business'] ?? '');
$region = trim($_POST['region'] ?? '');
$town = trim($_POST['town'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($name) || empty($email) || empty($phone) || empty($region) || empty($town) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

// Validate lengths
if (!validateLength($name, 2, 100) || !validateLength($town, 2, 100) || !validateLength($message, 10, 2000)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input length']);
    exit;
}

// Detect suspicious input
$suspicious = false;
foreach ([$name, $business, $town, $message] as $field) {
    if (detectSuspiciousInput($field)) {
        $suspicious = true;
        break;
    }
}
if ($suspicious) {
    logSecurityEvent('SUSPICIOUS_INPUT', ['action' => 'contact_form']);
    echo json_encode(['success' => false, 'message' => 'Invalid input detected']);
    exit;
}

// Validate email
$email = sanitizeEmail($email);
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Validate phone
$phone = sanitizePhone($phone);
if (!$phone) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number']);
    exit;
}

try {
    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Insert into contact_submissions table
    $stmt = $conn->prepare("INSERT INTO contact_submissions (name, email, phone, business, region, town, message, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', NOW())");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }
    $stmt->bind_param('sssssss', $name, $email, $phone, $business, $region, $town, $message);

    if ($stmt->execute()) {
        $contact_id = $conn->insert_id;
        // Create notification for CEO
        $notif_stmt = $conn->prepare("INSERT INTO ceo_notifications (type, title, message, link_url, contact_id, is_read, created_at) VALUES ('contact', 'New Contact Submission', ?, ?, ?, 0, NOW())");
        if ($notif_stmt) {
            $notif_title = "$name from $town, $region Region" . ($business ? " ($business)" : '') . " has contacted you";
            $notif_link = "dev_portal.php?view=contact&id=" . $contact_id;
            $notif_stmt->bind_param('ssi', $notif_title, $notif_link, $contact_id);
            $notif_stmt->execute();
            $notif_stmt->close();
        }

        echo json_encode(['success' => true, 'message' => 'Thank you! Your message has been sent. We will contact you shortly.']);
    } else {
        throw new Exception('Failed to execute statement: ' . $stmt->error);
    }
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
