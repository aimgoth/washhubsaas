<?php
// Lightweight HTTP client for calling the Node backend from PHP
// Requires API_BASE_URL (defined in config/api_config.php)

if (!defined('API_BASE_URL')) {
    define('API_BASE_URL', '');
}

function api_build_url(string $path): string {
    $base = rtrim(API_BASE_URL, '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function api_request(string $method, string $path, array $options = []) {
    if (API_BASE_URL === '') {
        throw new RuntimeException('API_BASE_URL is not configured.');
    }

    $url = api_build_url($path);
    $headers = [ 'Content-Type: application/json' ];

    // Attach token automatically if present
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['NODE_API_TOKEN'])) {
        $headers[] = 'x-auth-token: ' . $_SESSION['NODE_API_TOKEN'];
    }

    // Allow explicit headers override/merge
    if (!empty($options['headers']) && is_array($options['headers'])) {
        $headers = array_merge($headers, $options['headers']);
    }

    $payload = null;
    if (!empty($options['json'])) {
        $payload = json_encode($options['json']);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $methodU = strtoupper($method);
    if ($methodU === 'POST' || $methodU === 'PUT' || $methodU === 'PATCH' || $methodU === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodU);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo) {
        throw new RuntimeException('API request failed with cURL error #' . $errNo);
    }

    // Try to decode JSON
    $data = json_decode($resp, true);

    if ($httpCode >= 400) {
        $msg = 'API HTTP ' . $httpCode;
        if (is_array($data) && isset($data['msg'])) { $msg .= ' - ' . $data['msg']; }
        throw new RuntimeException($msg);
    }

    return $data !== null ? $data : $resp;
}

function api_get(string $path, array $query = []) {
    if (!empty($query)) {
        $qs = http_build_query($query);
        $path .= (strpos($path, '?') !== false ? '&' : '?') . $qs;
    }
    return api_request('GET', $path);
}

function api_post(string $path, array $json = []) {
    return api_request('POST', $path, ['json' => $json]);
}
