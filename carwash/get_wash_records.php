<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Check if date parameter is provided
if (!isset($_GET['date']) || empty($_GET['date'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Date parameter is required']));
}

$date = $_GET['date'];

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']));
}

try {
    // Query to get wash records for the specified date
    $query = "
        SELECT 
            w.id,
            w.plate_number,
            w.vehicle_type,
            w.size,p
            w.amount,
            w.status, 
            w.created_at,
            w.planned_start_time,
            w.planned_end_time,
            u1.name as washer_name,
            u2.name as created_by,
            s.name as service_name,
            c.name as category_name
        FROM car_washes w
        LEFT JOIN users u1 ON w.washer_id = u1.id
        LEFT JOIN users u2 ON w.created_by = u2.id
        LEFT JOIN services s ON w.service_id = s.id
        LEFT JOIN categories c ON w.category_id = c.id
        WHERE DATE(w.created_at) = :date
        ORDER BY w.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':date', $date);
    $stmt->execute();
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $formattedRecords = array_map(function($record) {
        return [
            'id' => $record['id'],
            'plate_number' => $record['plate_number'],
            'vehicle_type' => $record['vehicle_type'],
            'size' => $record['size'],
            'amount' => $record['amount'],
            'status' => $record['status'],
            'service' => $record['service_name'],
            'category' => $record['category_name'],
            'washer_name' => $record['washer_name'],
            'created_by' => $record['created_by'],
            'created_at' => $record['created_at'],
            'planned_start_time' => $record['planned_start_time'],
            'planned_end_time' => $record['planned_end_time']
        ];
    }, $records);
    
    // Set JSON content type header
    header('Content-Type: application/json');
    echo json_encode($formattedRecords);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
