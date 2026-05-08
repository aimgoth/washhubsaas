<?php
require_once 'config/database.php';
echo "<h2>All Tenants in Master Database</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
echo "<tr><th>ID</th><th>Client Name</th><th>Bay Name</th><th>Database Name</th><th>DB User</th><th>Status</th></tr>";

$res = $conn->query("SELECT * FROM tenants");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['client_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['bay_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['db_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['db_user']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "</tr>";
    }
}
echo "</table>";
?>
