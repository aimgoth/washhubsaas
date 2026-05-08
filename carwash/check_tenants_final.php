<?php
require_once 'config/database.php';
$res = $conn->query("SELECT client_name, bay_name, db_name FROM tenants");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo "Client: " . $row['client_name'] . " | Bay: " . $row['bay_name'] . " | DB: " . $row['db_name'] . "\n";
    }
}
?>
