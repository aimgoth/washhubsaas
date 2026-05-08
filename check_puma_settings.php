<?php
$conn = new mysqli('localhost', 'root', '', 'puma');
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bay_name' LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    echo "bay_name: " . $row['setting_value'] . "\n";
} else {
    echo "bay_name not found in puma system_settings\n";
}
