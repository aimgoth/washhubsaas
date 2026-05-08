<?php
$c = new mysqli('127.0.0.1', 'root', '', 'aimgoth');
if ($c->connect_error) {
    die("Connection failed: " . $c->connect_error);
}
$res = $c->query("SHOW TABLES");
if ($res) {
    while($row = $res->fetch_row()) {
        echo $row[0] . "\n";
    }
}
?>
