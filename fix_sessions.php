<?php
$dir = __DIR__ . '/carwash';
$files = glob($dir . '/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    if (strpos($content, "require_once 'config/session.php';") !== false && strpos($content, "session_start();") !== false) {
        $content = str_replace("session_start();\n", "", $content);
        $content = str_replace("session_start();\r\n", "", $content);
        // Replace ones without newline too but safely
        $content = preg_replace('/^\s*session_start\(\);\s*$/m', '', $content);
        file_put_contents($file, $content);
        echo "Fixed $file\n";
    }
}
