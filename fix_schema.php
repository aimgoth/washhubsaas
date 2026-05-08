<?php
// fix_schema.php
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error . "\n");

$dbs = [];
$res = $conn->query("SHOW DATABASES");
while ($row = $res->fetch_row()) $dbs[] = $row[0];
$skip = ['information_schema','performance_schema','mysql','sys','phpmyadmin'];

foreach ($dbs as $db) {
    if (in_array($db, $skip)) continue;
    
    // Check if it's a tenant DB (has tenants table? no, only master has it. check if car_washes exists)
    $conn->select_db($db);
    $r = $conn->query("SHOW TABLES LIKE 'car_washes'");
    if (!$r || $r->num_rows === 0) continue;
    
    echo "[$db] Found tenant tables\n";
    
    // Fix workers table
    $cr = $conn->query("SHOW COLUMNS FROM workers");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        $toAdd = [
            'next_of_kin_name'  => "ALTER TABLE workers ADD COLUMN next_of_kin_name VARCHAR(255) DEFAULT NULL",
            'next_of_kin_phone' => "ALTER TABLE workers ADD COLUMN next_of_kin_phone VARCHAR(50) DEFAULT NULL",
            'photo_path'        => "ALTER TABLE workers ADD COLUMN photo_path VARCHAR(500) DEFAULT NULL",
        ];
        foreach ($toAdd as $col => $sql) {
            if (!in_array($col, $cols)) {
                if ($conn->query($sql)) echo "  ADDED workers.$col\n";
                else echo "  FAILED workers.$col: " . $conn->error . "\n";
            }
        }
    } else {
        echo "  SKIPPED workers (table corrupt)\n";
    }
    
    // Fix car_washes table
    $cr = $conn->query("SHOW COLUMNS FROM car_washes");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        $toAdd = [
            'workload_level'  => "ALTER TABLE car_washes ADD COLUMN workload_level ENUM('low', 'normal', 'heavy') DEFAULT 'normal'",
        ];
        foreach ($toAdd as $col => $sql) {
            if (!in_array($col, $cols)) {
                if ($conn->query($sql)) echo "  ADDED car_washes.$col\n";
                else echo "  FAILED car_washes.$col: " . $conn->error . "\n";
            }
        }
    } else {
        echo "  SKIPPED car_washes (table corrupt)\n";
    }
    
    // Fix daily_reports
    $cr = $conn->query("SHOW COLUMNS FROM daily_reports");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        if (!in_array('submitted_at', $cols)) {
            if ($conn->query("ALTER TABLE daily_reports ADD COLUMN submitted_at DATETIME NULL")) echo "  ADDED daily_reports.submitted_at\n";
            else echo "  FAILED daily_reports.submitted_at: " . $conn->error . "\n";
        }
    }
    
    echo "\n";
}
echo "All done.\n";
