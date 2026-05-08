<?php
header('Content-Type: application/json');
require_once 'config/database.php';

$response = ['success' => false, 'amount' => 0];

if (isset($_GET['service_id']) && isset($_GET['car_size_id'])) {
    $service_id = intval($_GET['service_id']);
    $car_size_id = intval($_GET['car_size_id']);

    if ($service_id > 0 && $car_size_id > 0) {
        try {
            $stmt = $conn->prepare("SELECT amount FROM prices WHERE service_id = ? AND car_size_id = ?");
            $stmt->bind_param('ii', $service_id, $car_size_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($price = $result->fetch_assoc()) {
                $response['success'] = true;
                $response['amount'] = $price['amount'];
            }
        } catch (Exception $e) {
            // Log error if needed, but don't expose it to the client
            $response['error'] = 'Database query failed.';
        }
    }
}

echo json_encode($response);
?>
