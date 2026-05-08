<?php
require_once 'config/database.php';
$res = $conn->query("SHOW COLUMNS FROM tenants");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . "\n";
    }
}
?>
