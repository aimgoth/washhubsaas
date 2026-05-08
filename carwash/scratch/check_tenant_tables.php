<?php
require_once 'config/database.php';
$res = $conn->query("SELECT db_name FROM tenants LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $tenant_db = $row['db_name'];
    echo "TENANT_DB: " . $tenant_db . "\n";
    $conn->select_db($tenant_db);
    $res2 = $conn->query("SHOW TABLES");
    echo "TABLES IN $tenant_db:\n";
    while($row2 = $res2->fetch_array()) {
        echo $row2[0] . "\n";
    }
} else {
    echo "No tenants found.\n";
}
