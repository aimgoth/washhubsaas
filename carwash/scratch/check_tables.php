<?php
require_once 'config/database.php';
$res = $conn->query("SHOW TABLES");
echo "TABLES:\n";
while($row = $res->fetch_array()) {
    echo $row[0] . "\n";
}
