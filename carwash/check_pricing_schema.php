<?php
require_once 'config/database.php';
$conn->select_db('aimgoth');

function printTable($conn, $table) {
    echo "TABLE: $table\n";
    try {
        $res = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                echo " - {$row['Field']} ({$row['Type']})\n";
            }
        } else {
            echo " - Not found\n";
        }
    } catch (Exception $e) {
        echo " - Not found or error\n";
    }
    echo "\n";
}

printTable($conn, 'prices');
printTable($conn, 'service_prices');
printTable($conn, 'car_washes');
