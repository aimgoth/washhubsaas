<?php
$c = new mysqli('127.0.0.1', 'root', '', 'aimgoth');
$res = $c->query("SELECT * FROM system_settings");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['setting_key'] . "|" . $row['setting_value'] . "\n";
    }
}
?>
