<?php
// Security Helper Functions

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Token expires after 1 hour
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rate limiting using session
 */
function checkRateLimit($action = 'default', $maxAttempts = 10, $timeWindow = 60) {
    session_start();
    
    $key = 'rate_limit_' . $action;
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['attempts' => 0, 'time' => time()];
    }
    
    // Reset if time window has passed
    if (time() - $_SESSION[$key]['time'] > $timeWindow) {
        $_SESSION[$key] = ['attempts' => 0, 'time' => time()];
    }
    
    $_SESSION[$key]['attempts']++;
    
    if ($_SESSION[$key]['attempts'] > $maxAttempts) {
        return false;
    }
    
    return true;
}

/**
 * Sanitize output to prevent XSS
 */
function sanitizeOutput($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate and sanitize email
 */
function sanitizeEmail($email) {
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

/**
 * Sanitize phone number
 */
function sanitizePhone($phone) {
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    // Basic validation: must be at least 10 digits
    return (strlen($phone) >= 10) ? $phone : false;
}

/**
 * Check for suspicious patterns in input
 */
function detectSuspiciousInput($input) {
    $patterns = [
        '/<script/i',
        '/javascript:/i',
        '/on\w+=/i',
        '/eval\(/i',
        '/document\./i',
        '/window\./i',
        '/alert\(/i',
        '/<iframe/i',
        '/<object/i',
        '/<embed/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Validate input length
 */
function validateLength($input, $min = 1, $max = 1000) {
    $length = strlen($input);
    return $length >= $min && $length <= $max;
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ip = '';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Validate IP
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/**
 * Log security events
 */
function logSecurityEvent($event, $details = []) {
    $logFile = __DIR__ . '/../logs/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $logEntry = sprintf(
        "[%s] [%s] [%s] [%s] %s\n",
        $timestamp,
        $event,
        $ip,
        $userAgent,
        json_encode($details)
    );
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
?>
