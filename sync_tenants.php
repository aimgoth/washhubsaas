<?php
$master = new mysqli('localhost', 'root', '', 'carwash_db');
$res = $master->query("SELECT db_name FROM tenants");
while ($row = $res->fetch_assoc()) {
    $db = $row['db_name'];
    echo "Processing $db...\n";
    $conn = new mysqli('localhost', 'root', '', $db);
    if ($conn->connect_error) {
        echo "  Failed to connect to $db: " . $conn->connect_error . "\n";
        continue;
    }
    
    // 1. Ensure submitted_at exists in daily_reports
    $check = $conn->query("SHOW COLUMNS FROM daily_reports LIKE 'submitted_at'");
    if ($check->num_rows === 0) {
        $conn->query("ALTER TABLE daily_reports ADD COLUMN submitted_at DATETIME NULL AFTER created_at");
        echo "  Added submitted_at to daily_reports\n";
    }
    
    // 2. Backfill submitted_at from created_at if empty
    $conn->query("UPDATE daily_reports SET submitted_at = created_at WHERE submitted_at IS NULL");
    echo "  Backfilled submitted_at for daily_reports\n";
    
    $conn->close();
}
echo "Done!\n";
