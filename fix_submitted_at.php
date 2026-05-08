<?php
require_once __DIR__ . '/carwash/config/database.php';

// Check puma specifically since that is the active tenant
$dbs = ['carwash_db', 'puma'];

foreach ($dbs as $dbName) {
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $dbName, (int)DB_PORT);
        if ($conn->connect_error) {
            echo "Connection failed for $dbName\n";
            continue;
        }

        $query = "UPDATE daily_reports SET submitted_at = NOW() WHERE submitted_at IS NULL";
        if ($conn->query($query)) {
            echo "Updated " . $conn->affected_rows . " rows in daily_reports for $dbName.\n";
        } else {
            echo "Error updating daily_reports for $dbName: " . $conn->error . "\n";
        }
        
        $conn->close();
    } catch (Exception $e) {
        echo "Exception for $dbName: " . $e->getMessage() . "\n";
    }
}
