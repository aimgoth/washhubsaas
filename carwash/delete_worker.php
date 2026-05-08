<?php
session_start();

// Check if user is logged in and has admin rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("HTTP/1.1 403 Forbidden");
    exit('Access Denied');
}

require_once 'config/database.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid worker ID';
    header('Location: employees.php');
    exit;
}

$worker_id = intval($_GET['id']);

// Check if the worker has any associated records
$check_sql = "SELECT COUNT(*) as count FROM car_washes WHERE worker_id = ?";
$stmt = $conn->prepare($check_sql);
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row['count'] > 0) {
    // Worker has associated records, don't delete
    $_SESSION['error'] = 'Cannot delete worker with existing wash records. Please deactivate instead.';
    header('Location: employees.php');
    exit;
}

// Proceed with deletion
$sql = "DELETE FROM workers WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $worker_id);

if ($stmt->execute()) {
    $_SESSION['success'] = 'Worker deleted successfully';
} else {
    $_SESSION['error'] = 'Error deleting worker: ' . $conn->error;
}

$stmt->close();
$conn->close();

header('Location: employees.php');
exit;
