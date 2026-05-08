<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Test: PHP is working<br>";

session_start();
echo "Test: Session started<br>";

try {
    require_once 'config/database.php';
    echo "Test: Database loaded<br>";
    echo "Test: Current DB: " . ($conn ? $conn->query("SELECT DATABASE()")->fetch_row()[0] : 'No connection') . "<br>";
} catch (Exception $e) {
    echo "Test: DB Error: " . $e->getMessage() . "<br>";
}

echo "Test: Done<br>";
?>
