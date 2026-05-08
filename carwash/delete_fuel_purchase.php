<?php
require_once 'includes/auth.php';
require_once 'includes/fuel_functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only POST method is allowed']);
    exit;
}

// Only superadmin can delete records
if ($_SESSION['role'] !== 'superadmin') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only superadmin can delete records']);
    exit;
}

// Get and validate the ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid fuel purchase ID']);
    exit;
}

try {
    global $conn;
    
    // Begin transaction
    $conn->begin_transaction();
    
    // Check if the record exists and is finished
    $stmt = $conn->prepare("
        SELECT id, status 
        FROM fuel_purchases 
        WHERE id = ? 
        FOR UPDATE
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Fuel purchase record not found');
    }
    
    $purchase = $result->fetch_assoc();
    
    if ($purchase['status'] !== 'finished') {
        throw new Exception('Only finished fuel purchases can be deleted');
    }
    
    // First, update any related generator_washes to set fuel_purchase_id to NULL
    $updateStmt = $conn->prepare("
        UPDATE generator_washes 
        SET fuel_purchase_id = NULL 
        WHERE fuel_purchase_id = ?
    ");
    $updateStmt->bind_param("i", $id);
    $updateStmt->execute();
    
    // Then delete the fuel purchase
    $deleteStmt = $conn->prepare("DELETE FROM fuel_purchases WHERE id = ?");
    $deleteStmt->bind_param("i", $id);
    $deleteStmt->execute();
    
    if ($deleteStmt->affected_rows === 0) {
        throw new Exception('Failed to delete the fuel purchase record');
    }
    
    // If we got here, commit the transaction
    $conn->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
    }
    
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
