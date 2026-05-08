<?php
$conn = new mysqli('localhost', 'root', '', 'puma');
$res = $conn->query("DESCRIBE daily_reports");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
