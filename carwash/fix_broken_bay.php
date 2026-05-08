<?php
// Fix broken bay by adding missing tables
require_once 'config/database.php';

$broken_db = 'lolobidb';

echo "<h2>Fixing Broken Bay: $broken_db</h2>";

// Connect to the broken database
$conn->select_db($broken_db);

// Add missing tables
$missing_tables = [
    "CREATE TABLE IF NOT EXISTS customers (id INT(11) NOT NULL AUTO_INCREMENT,full_name VARCHAR(255) NOT NULL,service_type VARCHAR(255) NOT NULL,washer_name VARCHAR(255) NOT NULL,contact_number VARCHAR(50) NOT NULL,expected_date DATE NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),KEY idx_expected_date (expected_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS day_closures (id INT(11) NOT NULL AUTO_INCREMENT,report_date DATE NOT NULL,closed_by INT(11) DEFAULT NULL,closed_at DATETIME DEFAULT NULL,PRIMARY KEY (id),UNIQUE KEY report_date (report_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS prices (id INT(11) NOT NULL AUTO_INCREMENT,service_id INT(11) NOT NULL,car_size_id INT(11) NOT NULL,amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,PRIMARY KEY (id),KEY idx_service_id (service_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(50) NOT NULL,setting_value TEXT,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

echo "<h3>Adding Missing Tables</h3>";
foreach ($missing_tables as $sql) {
    if ($conn->query($sql)) {
        echo "✅ Table created successfully<br>";
    } else {
        echo "❌ Error creating table: " . $conn->error . "<br>";
    }
}

echo "<hr>";
echo "<h3>Verification</h3>";
$result = $conn->query("SHOW TABLES");
echo "<ul>";
while ($row = $result->fetch_array()) {
    echo "<li>" . htmlspecialchars($row[0]) . "</li>";
}
echo "</ul>";

echo "<hr>";
echo "<p><strong>Fix Complete!</strong> The broken bay should now work correctly.</p>";
echo "<p>Try logging in with facility code: <strong>adenta</strong></p>";
?>
