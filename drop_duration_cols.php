<?php
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error . "\n");

$dbs = [];
$res = $conn->query("SHOW DATABASES");
while ($row = $res->fetch_row()) $dbs[] = $row[0];
$skip = ['information_schema','performance_schema','mysql','sys','phpmyadmin'];

foreach ($dbs as $db) {
    if (in_array($db, $skip)) continue;
    
    $conn->select_db($db);
    $r = $conn->query("SHOW TABLES LIKE 'car_washes'");
    if (!$r || $r->num_rows === 0) continue;
    
    echo "[$db] Removing duration columns...\n";
    
    // Drop service_durations
    if ($conn->query("DROP TABLE IF EXISTS service_durations")) {
        echo "  DROPPED table service_durations\n";
    }

    // Drop car_washes columns
    $cr = $conn->query("SHOW COLUMNS FROM car_washes");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        $toDrop = [
            'planned_start',
            'planned_end',
            'started_at',
            'completed_at',
            'duration_minutes',
            'ended_by'
        ];
        foreach ($toDrop as $col) {
            if (in_array($col, $cols)) {
                if ($conn->query("ALTER TABLE car_washes DROP COLUMN `$col`")) echo "  DROPPED car_washes.$col\n";
                else echo "  FAILED car_washes.$col: " . $conn->error . "\n";
            }
        }
    }
    
    // Drop wash_tasks columns
    $cr = $conn->query("SHOW COLUMNS FROM wash_tasks");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        if (in_array('assigned_by', $cols)) {
            if ($conn->query("ALTER TABLE wash_tasks DROP COLUMN assigned_by")) echo "  DROPPED wash_tasks.assigned_by\n";
        }
    }

    echo "\n";
}
echo "All done.\n";
