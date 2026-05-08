<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

// Check for active fuel purchase
$hasActiveFuel = false;
$stmt = $conn->prepare("SELECT id FROM fuel_purchases WHERE status = 'active' LIMIT 1");
if ($stmt->execute() && $stmt->fetch()) {
    $hasActiveFuel = true;
}

echo json_encode([
    'success' => true,
    'hasActiveFuel' => $hasActiveFuel
]);
