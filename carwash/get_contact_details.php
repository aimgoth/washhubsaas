<?php
// Fetch Contact Submission Details
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

// Rate limiting: max 30 requests per minute per IP
if (!checkRateLimit('get_contact_details', 30, 60)) {
    logSecurityEvent('RATE_LIMIT_EXCEEDED', ['action' => 'get_contact_details']);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid contact ID']);
    exit;
}

try {
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $stmt = $conn->prepare("SELECT id, name, email, phone, business, region, town, message, status, created_at FROM contact_submissions WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $contact = $result->fetch_assoc();
    $stmt->close();

    if ($contact) {
        // Format the date
        $contact['created_at'] = date('d M Y, g:ia', strtotime((string)$contact['created_at']));
        echo json_encode(['success' => true, 'contact' => $contact]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Contact submission not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
