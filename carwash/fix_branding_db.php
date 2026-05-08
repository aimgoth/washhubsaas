<?php
$c = new mysqli('127.0.0.1', 'root', '', 'carwash_db');
if ($c->connect_error) die("Connection failed");
$c->query("UPDATE tenants SET bay_name = 'GothTech Consult' WHERE id = 1");
echo "Updated tenant bay_name to GothTech Consult\n";

$c2 = new mysqli('127.0.0.1', 'root', '', 'aimgoth');
if (!$c2->connect_error) {
    $c2->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('bay_name', 'GothTech Consult') ON DUPLICATE KEY UPDATE setting_value = 'GothTech Consult'");
    echo "Updated local system_settings to GothTech Consult\n";
}
?>
