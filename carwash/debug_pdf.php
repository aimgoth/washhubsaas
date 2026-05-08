<?php
// Debug PDF export issue
require_once 'config/database.php';

$working_db = 'aimgoth';
$broken_db = 'lolobidb';
$date = '2026-04-26';

echo "<h2>PDF Export Debug for Date: $date</h2>";

// Check working bay
echo "<h3>Working Bay ($working_db)</h3>";
$conn->select_db($working_db);

// Check daily_reports
$result = $conn->query("SELECT * FROM daily_reports WHERE report_date = '$date'");
echo "Daily Reports for $date: " . $result->num_rows . " rows<br>";
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "<pre>" . print_r($row, true) . "</pre>";
}

// Check car_washes
$result = $conn->query("SELECT COUNT(*) as count FROM car_washes WHERE DATE(created_at) = '$date'");
$wash_count = $result->fetch_assoc()['count'];
echo "Car washes on $date: $wash_count<br>";

// Check system_settings
$result = $conn->query("SELECT * FROM system_settings");
echo "System Settings: " . $result->num_rows . " rows<br>";
while ($row = $result->fetch_assoc()) {
    echo "- " . $row['setting_key'] . ": " . $row['setting_value'] . "<br>";
}

echo "<hr>";

// Check broken bay
echo "<h3>Broken Bay ($broken_db)</h3>";
$conn->select_db($broken_db);

// Check daily_reports
$result = $conn->query("SELECT * FROM daily_reports WHERE report_date = '$date'");
echo "Daily Reports for $date: " . $result->num_rows . " rows<br>";
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "<pre>" . print_r($row, true) . "</pre>";
}

// Check car_washes
$result = $conn->query("SELECT COUNT(*) as count FROM car_washes WHERE DATE(created_at) = '$date'");
$wash_count = $result->fetch_assoc()['count'];
echo "Car washes on $date: $wash_count<br>";

// Check system_settings
$result = $conn->query("SELECT * FROM system_settings");
echo "System Settings: " . $result->num_rows . " rows<br>";
while ($row = $result->fetch_assoc()) {
    echo "- " . $row['setting_key'] . ": " . $row['setting_value'] . "<br>";
}

echo "<hr>";

// Test actual PDF query on broken bay
echo "<h3>Test PDF Query on Broken Bay</h3>";
try {
    $sql = "SELECT report_date, total_cars_washed, total_motors_washed, total_carpets_washed, gross_amount_total, revenue_two_thirds_total, created_by, created_at FROM daily_reports WHERE report_date = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    echo "Query executed successfully. Rows: " . $result->num_rows . "<br>";
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . "<br>";
}

// Test system_settings query
echo "<h3>Test System Settings Query on Broken Bay</h3>";
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        echo "Worker percentage: " . $row_set['setting_value'] . "<br>";
    } else {
        echo "No worker_percentage setting found<br>";
    }
} catch (Exception $e) {
    echo "System settings query failed: " . $e->getMessage() . "<br>";
}
?>
