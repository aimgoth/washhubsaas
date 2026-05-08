<?php
// Enable error reporting but don't display in production
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Secure session settings
ini_set('session.cookie_httponly', 1); // Prevent JavaScript access to cookies
// Only set secure flag if using HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1); // Only send cookies over HTTPS
    ini_set('session.cookie_samesite', 'Strict'); // Prevent CSRF
} else {
    // For HTTP (localhost), use Lax instead of Strict
    ini_set('session.cookie_samesite', 'Lax');
}
ini_set('session.use_strict_mode', 1); // Prevent session fixation
ini_set('session.gc_maxlifetime', 3600); // 1 hour session timeout
ini_set('session.cookie_lifetime', 3600); // 1 hour cookie lifetime

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Directly load environment variables from .env file
$envPath = dirname(__DIR__) . '/.env';  // Look in /htdocs/carwash/.env
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            
            if (!empty($key)) {
                // Only load from .env if not already set in the environment (e.g. by Docker)
                if (getenv($key) === false) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }
}

// Determine final DB name (allow session override for multi-tenant login)
$envDbName = getenv('DB_NAME') ?: (getenv('DB_HOST') ? '' : 'carwash_db');
$finalDbName = $envDbName;
if (!(defined('DISABLE_DB_NAME_OVERRIDE') && DISABLE_DB_NAME_OVERRIDE)
    && function_exists('session_status')
    && session_status() === PHP_SESSION_ACTIVE) {
    if (!empty($_SESSION['DB_NAME_OVERRIDE'])) {
        $finalDbName = $_SESSION['DB_NAME_OVERRIDE'];
    } elseif (!empty($_SESSION['logged_in']) && !empty($_SESSION['ACTIVE_TENANT_DB'])) {
        // Persist tenant DB routing after login even if temporary override is cleared.
        $finalDbName = $_SESSION['ACTIVE_TENANT_DB'];
    }
}

// Read connection settings from environment only (no hardcoded fallbacks for production)
$envServer = getenv('DB_SERVER') ?: getenv('DB_HOST') ?: '127.0.0.1';
$envUser   = getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'root';
$envPass   = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
$envPort   = getenv('DB_PORT') ?: '3306';
$envDbName  = $finalDbName ?: (getenv('DB_NAME') ?: 'carwash_db');

define('DB_SERVER',   $envServer);
define('DB_USERNAME', $envUser);
define('DB_PASSWORD', $envPass);
define('DB_NAME',     $envDbName);
define('DB_PORT',     $envPort);

// Tenant access state (used by UI guards)
$GLOBALS['TENANT_BLOCKED'] = false;
$GLOBALS['TENANT_BLOCK_MSG'] = '';

// Set default timezone for PHP
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Accra');

// Prevent mysqli from throwing exceptions on connection failure —
// we check connect_error ourselves right after.
mysqli_report(MYSQLI_REPORT_OFF);

// Create connection using explicit timeouts to prevent page hangs.
$mysqli = mysqli_init();
if ($mysqli) {
    mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        mysqli_options($mysqli, MYSQLI_OPT_READ_TIMEOUT, 15);
    }
    $conn = @mysqli_real_connect($mysqli, DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, (int)DB_PORT)
        ? $mysqli
        : null;
} else {
    $conn = null;
}

// Re-enable strict reporting for everything AFTER the connection
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Check connection
if (!$conn || $conn->connect_error) {
    $connErr = $conn ? $conn->connect_error : 'Unknown error';
    $GLOBALS['DB_CONNECT_ERROR'] = $connErr;
    // Allow dev_portal.php and dev_login.php to handle the error gracefully
    if (defined('ALLOW_DB_FAIL') && ALLOW_DB_FAIL) {
        $conn = null; // caller checks for null
    } else {
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;background:#fef2f2;color:#7f1d1d;border-left:5px solid #ef4444;max-width:600px;margin:60px auto;border-radius:12px;"><h2>⚠️ Database Unavailable</h2><p>MySQL is not running. Please start MySQL in the XAMPP Control Panel and refresh.</p><small style="opacity:.7">' . htmlspecialchars($connErr) . '</small></div>');
    }
} else {
    // Set MySQL session timezone
    $conn->query("SET time_zone = '+00:00'");

    // Resolve tenant subscription status for logged-in tenant users.
    try {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $activeDbRes = $conn->query("SELECT DATABASE() AS dbn");
            $activeDb = $activeDbRes ? (string)($activeDbRes->fetch_assoc()['dbn'] ?? '') : '';
            $masterDb = getenv('DB_NAME') ?: 'carwash_db';

            if ($activeDb !== '' && $activeDb !== $masterDb) {
                $tenantSql = "SELECT client_name, bay_name, status, subscription_end FROM `" . $masterDb . "`.tenants WHERE db_name = ? LIMIT 1";
                if ($stTenant = $conn->prepare($tenantSql)) {
                    $stTenant->bind_param('s', $activeDb);
                    $stTenant->execute();
                    $tenant = $stTenant->get_result()->fetch_assoc();
                    $stTenant->close();

                    if ($tenant) {
                        // Store branding in session
                        $cName = (string)($tenant['client_name'] ?? '');
                        $bName = (string)($tenant['bay_name'] ?? '');
                        $_SESSION['TENANT_CLIENT'] = $cName;
                        $_SESSION['TENANT_BAY'] = $bName;
                        $_SESSION['TENANT_BRAND'] = ($cName === $bName || $bName === '') ? $cName : "$cName — $bName";

                        $status = strtolower((string)($tenant['status'] ?? ''));
                        $expired = !empty($tenant['subscription_end']) && strtotime((string)$tenant['subscription_end']) < time();
                        if (in_array($status, ['blocked', 'suspended'], true) || $expired) {
                            $GLOBALS['TENANT_BLOCKED'] = true;
                            $GLOBALS['TENANT_BLOCK_MSG'] = 'Your WashHub subscription is currently inactive. You must renew your subscription before you can access the portal. Call 0509729601 or 0549195399.';
                            if ($expired) {
                                $GLOBALS['TENANT_BLOCK_MSG'] = 'Your WashHub subscription has expired. Please renew your subscription to continue accessing the portal. Call 0509729601 or 0549195399.';
                            }
                            $_SESSION['TENANT_BLOCKED'] = true;
                            $_SESSION['TENANT_BLOCK_MSG'] = $GLOBALS['TENANT_BLOCK_MSG'];

                            // Prevent blocked tenants from performing state-changing actions.
                            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
                            $script = basename($_SERVER['PHP_SELF'] ?? '');
                            $allowScripts = ['login.php', 'logout.php'];
                            if ($method !== 'GET' && !in_array($script, $allowScripts, true)) {
                                http_response_code(403);
                                if (!headers_sent()) {
                                    header('Content-Type: application/json; charset=utf-8');
                                }
                                echo json_encode([
                                    'ok' => false,
                                    'message' => $GLOBALS['TENANT_BLOCK_MSG']
                                ]);
                                exit;
                            }
                        } else {
                            unset($_SESSION['TENANT_BLOCKED'], $_SESSION['TENANT_BLOCK_MSG']);
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Never block normal page load because of subscription status checks.
    }
}


// Backward-compat helper
function db_connect() {
    global $conn;
    return $conn;
}
?>
