<?php
// Simple API config loader for Node backend
// Reads API_BASE_URL from env or .env already loaded by database.php loader if present.

if (!function_exists('load_env_file')) {
    // Fallback mini loader in case this file is used before database.php
    function load_env_file($path) {
        if (!is_file($path)) return;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $val);
            }
        }
    }
}

$rootEnv = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
$thisEnv = __DIR__ . DIRECTORY_SEPARATOR . '.env';
load_env_file($rootEnv);
load_env_file($thisEnv);

if (!defined('API_BASE_URL')) {
    $apiBase = getenv('API_BASE_URL') ?: '';
    define('API_BASE_URL', rtrim($apiBase, '/'));
}
