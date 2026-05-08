<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Session Check:<br>";
echo "User ID: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET') . "<br>";
echo "Role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET') . "<br>";
echo "Is Superadmin: " . ((isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') ? 'YES' : 'NO') . "<br>";
?>
