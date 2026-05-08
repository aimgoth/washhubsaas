<?php
$conn = new mysqli('localhost', 'root', '', 'carwash_db');
$res = $conn->query("SELECT client_name, bay_name FROM tenants WHERE db_name = 'puma' LIMIT 1");
$row = $res->fetch_assoc();
print_r($row);
