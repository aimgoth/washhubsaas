<?php
// Run this once to add missing columns to workers table
session_start();
require_once __DIR__ . '/../config/database.php';

echo "<pre>\n";
echo "Checking workers table...\n\n";

// Show current columns
$res = $conn->query("SHOW COLUMNS FROM workers");
$cols = [];
echo "Current columns:\n";
while ($row = $res->fetch_assoc()) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
    $cols[] = $row['Field'];
}
echo "\n";

$toAdd = [
    'next_of_kin_name'  => "ALTER TABLE workers ADD COLUMN next_of_kin_name VARCHAR(255) DEFAULT NULL AFTER phone",
    'next_of_kin_phone' => "ALTER TABLE workers ADD COLUMN next_of_kin_phone VARCHAR(50) DEFAULT NULL AFTER next_of_kin_name",
    'photo_path'        => "ALTER TABLE workers ADD COLUMN photo_path VARCHAR(500) DEFAULT NULL",
];

foreach ($toAdd as $col => $sql) {
    if (!in_array($col, $cols)) {
        if ($conn->query($sql)) {
            echo "✅ Added column: $col\n";
        } else {
            echo "❌ Failed to add $col: " . $conn->error . "\n";
        }
    } else {
        echo "✔  Already exists: $col\n";
    }
}

echo "\nFinal columns:\n";
$res2 = $conn->query("SHOW COLUMNS FROM workers");
while ($row = $res2->fetch_assoc()) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}
echo "\nDone.\n</pre>";
?>
