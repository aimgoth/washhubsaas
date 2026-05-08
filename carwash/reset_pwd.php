<?php
$conn = new mysqli('localhost', 'root', '', 'carwash_db');
$hash = password_hash('admin123', PASSWORD_DEFAULT);
$conn->query("UPDATE users SET password = '$hash' WHERE username = 'admin'");
$conn->query("UPDATE users SET password = '$hash' WHERE username = 'superadmin'");
echo "Passwords successfully updated!";
?>
