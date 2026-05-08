<?php
require_once 'config/database.php';
$res = $conn->query("SELECT * FROM users LIMIT 5");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Name: " . $row['full_name'] . " | Role: " . $row['role'] . "\n";
    }
}
?>
