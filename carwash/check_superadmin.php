<?php
$c = new mysqli('127.0.0.1', 'root', '', 'aimgoth');
$res = $c->query("SELECT * FROM users WHERE role = 'superadmin' LIMIT 1");
if ($res) {
    while($row = $res->fetch_assoc()) {
        foreach($row as $k => $v) echo "$k: $v | ";
        echo "\n";
    }
}
?>
