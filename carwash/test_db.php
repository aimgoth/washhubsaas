<?php
$start = microtime(true);
echo "Connecting...\n";
$conn = @new mysqli("127.0.0.1", "root", "", "carwash_db", 3306);
echo "Done in " . round((microtime(true) - $start)*1000) . " ms.\n";
if ($conn->connect_error) {
    echo "Error: " . $conn->connect_error . "\n";
} else {
    echo "Success!\n";
}
