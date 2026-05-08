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
    
    echo "[$db] Updating missing schemas...\n";
    
    // Add service_durations
    $sql = "CREATE TABLE IF NOT EXISTS `service_durations` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `service_id` int(11) NOT NULL,
      `car_size_id` int(11) NOT NULL,
      `duration_minutes` int(11) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_svc_size` (`service_id`,`car_size_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    if ($conn->query($sql)) echo "  OK service_durations\n";

    // Fix car_washes table
    $cr = $conn->query("SHOW COLUMNS FROM car_washes");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        $toAdd = [
            'planned_start'  => "ALTER TABLE car_washes ADD COLUMN planned_start DATETIME NULL",
            'planned_end'    => "ALTER TABLE car_washes ADD COLUMN planned_end DATETIME NULL",
            'started_at'     => "ALTER TABLE car_washes ADD COLUMN started_at DATETIME NULL",
            'completed_at'   => "ALTER TABLE car_washes ADD COLUMN completed_at DATETIME NULL",
            'duration_minutes'=> "ALTER TABLE car_washes ADD COLUMN duration_minutes INT(11) DEFAULT NULL",
            'ended_by'       => "ALTER TABLE car_washes ADD COLUMN ended_by VARCHAR(100) DEFAULT NULL",
        ];
        foreach ($toAdd as $col => $sql) {
            if (!in_array($col, $cols)) {
                if ($conn->query($sql)) echo "  ADDED car_washes.$col\n";
                else echo "  FAILED car_washes.$col: " . $conn->error . "\n";
            }
        }
    }
    
    // Fix wash_tasks
    $cr = $conn->query("SHOW COLUMNS FROM wash_tasks");
    if ($cr) {
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        if (!in_array('assigned_by', $cols)) {
            if ($conn->query("ALTER TABLE wash_tasks ADD COLUMN assigned_by VARCHAR(100) DEFAULT NULL")) echo "  ADDED wash_tasks.assigned_by\n";
        }
    }

    echo "\n";
}
echo "All done.\n";
