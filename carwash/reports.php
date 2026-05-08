<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin','admin'])) {
    header('Location: login.php');
    exit;
}

// Backward compatible router to role-specific pages
if ($_SESSION['role'] === 'superadmin') {
    header('Location: reports_super.php');
    exit;
}

header('Location: reports_admin.php');
exit;
