<?php
require_once 'config/database.php';
$res = $conn->query("SHOW TABLES");
if ($res) {
    while($row = $res->fetch_row()) {
        echo $row[0] . "\n";
    }
}
?>
