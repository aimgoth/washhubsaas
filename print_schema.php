<?php
$c = new mysqli('127.0.0.1', 'root', '', 'aimgoth', 3306);
$r = $c->query('SHOW CREATE TABLE service_durations');
if ($r) echo $r->fetch_row()[1];
