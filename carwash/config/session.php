<?php
// Set default timezone to match the server's timezone
date_default_timezone_set('UTC');

// Session security settings - must be called before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 for HTTPS
ini_set('session.gc_maxlifetime', 3600); // 1 hour timeout

// Start session before any DB bootstrap reads tenant override from $_SESSION.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to get consistent date format
function getFormattedDate($dateString = null) {
    return date('M j, Y', $dateString ? strtotime($dateString) : time());
}
?>
