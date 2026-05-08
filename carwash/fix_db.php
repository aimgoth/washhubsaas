<?php
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
try {
    $conn = new mysqli('127.0.0.1', 'root', '', 'carwash_db', 3306);
    echo "Connected.\n";
    
    // Attempt to drop the table. Sometimes this works if the dictionary entry still exists.
    try {
        $conn->query("DROP TABLE IF EXISTS tenants");
        echo "Successfully ran DROP TABLE.\n";
    } catch (Exception $e) {
        echo "Warning dropping table: " . $e->getMessage() . "\n";
    }

    $conn->close();
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
