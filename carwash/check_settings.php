<?php
require_once 'config/database.php';
$res = $conn->query("SELECT * FROM system_settings");
while($row = $res->fetch_assoc()) {
    echo $row['setting_key'] . " | " . $row['setting_value'] . "\n";
}
?>
