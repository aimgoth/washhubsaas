<?php
// Repair workers table in all tenant databases
$conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error . "\n");

$dbs = [];
$res = $conn->query("SHOW DATABASES");
while ($row = $res->fetch_row()) $dbs[] = $row[0];
$skip = ['information_schema','performance_schema','mysql','sys','phpmyadmin'];

foreach ($dbs as $db) {
    if (in_array($db, $skip)) continue;
    $conn->select_db($db);
    $r = @$conn->query("SHOW TABLES LIKE 'workers'");
    if (!$r || $r->num_rows === 0) continue;

    echo "[$db] Found workers table\n";

    // Try to get columns - if table is corrupt, skip
    $cr = @$conn->query("SHOW COLUMNS FROM workers");
    if (!$cr) {
        echo "  SKIPPED (table in engine error): " . $conn->error . "\n\n";
        $conn->query("REPAIR TABLE workers");
        continue;
    }

    $cols = [];
    while ($c = $cr->fetch_assoc()) {
        echo "  col: " . $c['Field'] . "\n";
        $cols[] = $c['Field'];
    }

    $toAdd = [
        'next_of_kin_name'  => "ALTER TABLE workers ADD COLUMN next_of_kin_name VARCHAR(255) DEFAULT NULL",
        'next_of_kin_phone' => "ALTER TABLE workers ADD COLUMN next_of_kin_phone VARCHAR(50) DEFAULT NULL",
        'photo_path'        => "ALTER TABLE workers ADD COLUMN photo_path VARCHAR(500) DEFAULT NULL",
    ];
    foreach ($toAdd as $col => $sql) {
        if (!in_array($col, $cols)) {
            if (@$conn->query($sql)) echo "  ADDED: $col\n";
            else echo "  FAILED $col: " . $conn->error . "\n";
        } else {
            echo "  OK: $col\n";
        }
    }
    echo "\n";
}
echo "All done.\n";
