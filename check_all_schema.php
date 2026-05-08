<?php
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
if ($conn->connect_error) die("Conn failed");

$master_db = 'aimgoth'; // let's assume aimgoth is the main
$tenant_db = 'lolobidb';

function get_schema($conn, $db) {
    $conn->select_db($db);
    $tables = [];
    $res = $conn->query("SHOW TABLES");
    if (!$res) return [];
    while ($row = $res->fetch_row()) {
        $table = $row[0];
        $cRes = $conn->query("SHOW COLUMNS FROM `$table`");
        $cols = [];
        while ($cRow = $cRes->fetch_assoc()) {
            $cols[$cRow['Field']] = $cRow['Type'];
        }
        $tables[$table] = $cols;
    }
    return $tables;
}

$master_schema = get_schema($conn, $master_db);
$tenant_schema = get_schema($conn, $tenant_db);

echo "--- Missing Tables in $tenant_db ---\n";
foreach (array_keys($master_schema) as $table) {
    if (!isset($tenant_schema[$table])) {
        echo "- $table\n";
    }
}

echo "\n--- Missing Columns in $tenant_db ---\n";
foreach ($master_schema as $table => $cols) {
    if (isset($tenant_schema[$table])) {
        foreach ($cols as $col => $type) {
            if (!isset($tenant_schema[$table][$col])) {
                echo "- $table.$col ($type)\n";
            }
        }
    }
}

echo "\nDone\n";
