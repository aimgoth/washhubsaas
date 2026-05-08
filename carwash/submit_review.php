<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim(htmlspecialchars($_POST['customer_name'] ?? ''));
    $company_name = trim(htmlspecialchars($_POST['company_name'] ?? ''));
    $rating = (int)($_POST['rating'] ?? 5);
    $review_text = trim(htmlspecialchars($_POST['review_text'] ?? ''));

    if (empty($customer_name) || empty($review_text)) {
        echo json_encode(['success' => false, 'message' => 'Please provide both your name and a review.']);
        exit;
    }

    if ($rating < 1 || $rating > 5) {
        $rating = 5;
    }

    $stmt = $conn->prepare("INSERT INTO testimonials (customer_name, company_name, rating, review_text) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssis", $customer_name, $company_name, $rating, $review_text);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Thank you! Your review has been submitted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Preparation failed.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
