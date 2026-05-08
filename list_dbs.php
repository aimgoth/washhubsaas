<?php
$conn = new mysqli('localhost', 'root', '', 'carwash_db');
$res = $conn->query("SELECT db_name FROM tenants");
while ($row = $res->fetch_assoc()) {
    echo $row['db_name'] . "\n";
}
