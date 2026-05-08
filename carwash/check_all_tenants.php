<?php
require_once 'config/database.php';
// We need to find the tenant associated with the current session/context
// But in CLI we don't have session. Let's look at all rows in tenants.
$res = $conn->query("SELECT * FROM tenants");
if ($res) {
    while($row = $res->fetch_assoc()) {
        foreach($row as $key => $val) {
            echo "$key: $val | ";
        }
        echo "\n---\n";
    }
}
?>
