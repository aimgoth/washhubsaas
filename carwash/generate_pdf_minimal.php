<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

echo "Step 1: Session OK<br>";

require_once 'config/database.php';
echo "Step 2: Database OK<br>";

if (!isset($_GET['date'])) {
    die("No date parameter");
}

$date = $_GET['date'];
echo "Step 3: Date = $date<br>";

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die("Invalid date format");
}

echo "Step 4: Date format OK<br>";

// Try the query
$sql = "SELECT report_date, total_cars_washed, total_motors_washed, total_carpets_washed, gross_amount_total, revenue_two_thirds_total, created_by, created_at FROM daily_reports WHERE report_date = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $date);
$stmt->execute();
$result = $stmt->get_result();

echo "Step 5: Query executed, rows = " . $result->num_rows . "<br>";

if ($result->num_rows === 0) {
    die("No report found");
}

$report = $result->fetch_assoc();
echo "Step 6: Report data loaded<br>";
echo "Step 7: Done - PDF generation would start here<br>";
?>
