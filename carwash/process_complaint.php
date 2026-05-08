<?php
require_once 'config/database.php';
session_start();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input
        $required = ['name', 'contact', 'complaint_type', 'details'];
        foreach ($required as $field) {
            if (empty(trim($_POST[$field] ?? ''))) {
                throw new Exception("Please fill in all required fields.");
            }
        }

        // Sanitize inputs
        $name = trim(htmlspecialchars($_POST['name']));
        $contact = trim(htmlspecialchars($_POST['contact']));
        $complaint_type = $_POST['complaint_type'];
        $worker_name = !empty($_POST['worker_name']) ? trim(htmlspecialchars($_POST['worker_name'])) : null;
        $details = trim(htmlspecialchars($_POST['details']));
        $status = 'pending'; // Default status

        // Validate complaint type
        $valid_types = ['worker', 'manager', 'facility', 'service', 'other'];
        if (!in_array($complaint_type, $valid_types)) {
            throw new Exception("Invalid complaint type.");
        }

        // Insert into database
        $stmt = $conn->prepare("INSERT INTO customer_complaints 
            (customer_name, customer_contact, complaint_type, worker_name, complaint_details, status) 
            VALUES (?, ?, ?, ?, ?, ?)");
            
        $stmt->bind_param("ssssss", 
            $name,
            $contact,
            $complaint_type,
            $worker_name,
            $details,
            $status
        );

        if ($stmt->execute()) {
            $response = [
                'success' => true,
                'message' => 'Thank you for your feedback. We appreciate you taking the time to let us know about your experience.'
            ];
        } else {
            throw new Exception("Failed to submit your complaint. Please try again later.");
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
} else {
    $response['message'] = 'Invalid request method.';
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
