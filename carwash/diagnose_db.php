<?php
// Database Schema Diagnostic Tool
// Run this file to compare working vs broken bay databases

require_once __DIR__ . '/config/database.php';

$working_db = 'aimgoth';
$broken_db = 'puma';

echo "<h2>Database Schema Diagnostic</h2>";
echo "<h3>Working Bay: $working_db</h3>";
echo "<h3>Broken Bay: $broken_db</h3>";
echo "<hr>";

// Function to get tables in a database
function getTables($conn, $db_name) {
    $conn->select_db($db_name);
    $result = $conn->query("SHOW TABLES");
    $tables = [];
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    return $tables;
}

// Function to get table structure
function getTableStructure($conn, $db_name, $table) {
    $conn->select_db($db_name);
    $result = $conn->query("DESCRIBE `$table`");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'] . ' ' . $row['Type'];
    }
    return $columns;
}

// Check if databases exist
echo "<h3>Database Existence Check</h3>";
$working_exists = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$working_db'")->num_rows > 0;
$broken_exists = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$broken_db'")->num_rows > 0;

echo "Working DB ($working_db): " . ($working_exists ? "✅ EXISTS" : "❌ DOES NOT EXIST") . "<br>";
echo "Broken DB ($broken_db): " . ($broken_exists ? "✅ EXISTS" : "❌ DOES NOT EXIST") . "<br>";
echo "<hr>";

if (!$working_exists || !$broken_exists) {
    echo "<p style='color:red'>One or both databases do not exist. Cannot proceed with comparison.</p>";
    exit;
}

// Get tables from both databases
$working_tables = getTables($conn, $working_db);
$broken_tables = getTables($conn, $broken_db);

echo "<h3>Table Comparison</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
echo "<tr><th>Table Name</th><th>Working Bay</th><th>Broken Bay</th><th>Status</th></tr>";

$all_tables = array_unique(array_merge($working_tables, $broken_tables));
sort($all_tables);

foreach ($all_tables as $table) {
    $in_working = in_array($table, $working_tables);
    $in_broken = in_array($table, $broken_tables);

    $status = $in_working && $in_broken ? "✅ OK" : ($in_working ? "❌ Missing in broken" : "❌ Missing in working");

    echo "<tr>";
    echo "<td>$table</td>";
    echo "<td>" . ($in_working ? "✅" : "❌") . "</td>";
    echo "<td>" . ($in_broken ? "✅" : "❌") . "</td>";
    echo "<td>$status</td>";
    echo "</tr>";
}
echo "</table>";
echo "<hr>";

// Check for missing tables in broken bay
$missing_in_broken = array_diff($working_tables, $broken_tables);
if (!empty($missing_in_broken)) {
    echo "<h3 style='color:red'>Tables Missing in Broken Bay ($broken_db)</h3>";
    echo "<ul>";
    foreach ($missing_in_broken as $table) {
        echo "<li style='color:red'>$table</li>";
    }
    echo "</ul>";
    echo "<hr>";
}

// Check for extra tables in broken bay
$extra_in_broken = array_diff($broken_tables, $working_tables);
if (!empty($extra_in_broken)) {
    echo "<h3 style='color:orange'>Extra Tables in Broken Bay ($broken_db)</h3>";
    echo "<ul>";
    foreach ($extra_in_broken as $table) {
        echo "<li style='color:orange'>$table</li>";
    }
    echo "</ul>";
    echo "<hr>";
}

// Expected tables for a fully provisioned bay
$expected_tables = [
    'users',
    'categories',
    'services',
    'car_sizes',
    'workers',
    'car_washes',
    'daily_reports',
    'wash_tasks',
    'tenants'
];

echo "<h3>Expected Tables Check</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
echo "<tr><th>Expected Table</th><th>Working Bay</th><th>Broken Bay</th></tr>";

foreach ($expected_tables as $table) {
    $in_working = in_array($table, $working_tables);
    $in_broken = in_array($table, $broken_tables);

    echo "<tr>";
    echo "<td>$table</td>";
    echo "<td>" . ($in_working ? "✅" : "❌") . "</td>";
    echo "<td>" . ($in_broken ? "✅" : "❌") . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<hr>";

// Check if users table has data
echo "<h3>Data Check - Users Table</h3>";
$conn->select_db($working_db);
$working_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$conn->select_db($broken_db);
$broken_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];

echo "Working Bay Users: $working_users<br>";
echo "Broken Bay Users: $broken_users<br>";
echo "<hr>";

// Check if services table has data
echo "<h3>Data Check - Services Table</h3>";
$conn->select_db($working_db);
$working_services = $conn->query("SELECT COUNT(*) as count FROM services")->fetch_assoc()['count'];
$conn->select_db($broken_db);
$broken_services = $conn->query("SELECT COUNT(*) as count FROM services")->fetch_assoc()['count'];

echo "Working Bay Services: $working_services<br>";
echo "Broken Bay Services: $broken_services<br>";
echo "<hr>";

echo "<p><strong>Diagnostic Complete</strong></p>";
echo "<p>If the broken bay is missing tables, re-provision it in the CEO Console.</p>";
?>
