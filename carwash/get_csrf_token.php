<?php
// CSRF Token Generator
header('Content-Type: application/json');

session_start();

require_once __DIR__ . '/config/security.php';

// Generate and return CSRF token
$token = generateCSRFToken();

echo json_encode(['success' => true, 'token' => $token]);
?>
