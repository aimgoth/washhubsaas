<?php
require_once 'config/session.php';
require_once 'config/database.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check if date parameter is provided
if (!isset($_GET['date']) || empty($_GET['date'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Date parameter is required']);
    exit;
}

$date = $_GET['date'];

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

try {
    $sql = "SELECT 
                cw.id,
                cw.plate_number,
                cw.amount,
                cw.created_at as completed_at,
                cw.status,
                s.service_name,
                c.category_name,
                w.full_name as worker_name
            FROM car_washes cw
            LEFT JOIN services s ON cw.service_id = s.id
            LEFT JOIN vehicle_categories c ON cw.category_id = c.id
            LEFT JOIN workers w ON cw.worker_id = w.id
            WHERE DATE(cw.created_at) = ?
            ORDER BY cw.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = [
            'id' => $row['id'],
            'service_name' => $row['service_name'],
            'category_name' => $row['category_name'],
            'number_plate' => $row['plate_number'],
            'amount' => (float)$row['amount'],
            'worker_name' => $row['worker_name'],
            'completed_at' => $row['completed_at'],
            'status' => $row['status']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($records);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
?>
