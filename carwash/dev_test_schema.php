<?php
require_once 'config/database.php';
$res = $conn->query('SHOW COLUMNS FROM car_washes');
while($row = $res->fetch_assoc()){
    echo $row['Field'] . "\n";
}
