<?php
require_once 'config/database.php';
$res = $conn->query("SELECT * FROM system_settings");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['setting_key'] . "|" . $row['setting_value'] . "\n";
    }
} else {
    echo "Error: Could not query system_settings table.\n";
}
?>
