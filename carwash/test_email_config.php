<?php
// Test email configuration
require_once 'config/database.php';

$env_path = __DIR__ . '/.env';
echo "<h2>Email Configuration Check</h2>";

if (file_exists($env_path)) {
    echo "✅ .env file exists<br>";
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $smtp_found = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'SMTP') === 0) {
            echo "$line<br>";
            $smtp_found = true;
        }
    }
    if (!$smtp_found) {
        echo "<p style='color:red'>❌ No SMTP credentials found in .env</p>";
    }
} else {
    echo "<p style='color:red'>❌ .env file does not exist</p>";
}

// Check if PHP mail() is enabled
echo "<h3>PHP mail() Function</h3>";
if (function_exists('mail')) {
    echo "✅ mail() function exists<br>";
} else {
    echo "❌ mail() function does not exist<br>";
}

// Check sendmail_path
$sendmail_path = ini_get('sendmail_path');
echo "sendmail_path: " . ($sendmail_path ?: 'Not set') . "<br>";

// Check SMTP settings
echo "<h3>SMTP Settings in php.ini</h3>";
echo "SMTP: " . ini_get('SMTP') . "<br>";
echo "smtp_port: " . ini_get('smtp_port') . "<br>";

echo "<hr>";
echo "<h3>Required .env Variables:</h3>";
echo "<pre>
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-app-password
SMTP_FROM=noreply@washhub.com
SMTP_FROM_NAME=WashHub
</pre>";
echo "<p><strong>Note:</strong> For Gmail, you need to use an App Password, not your regular password. Enable 2-factor authentication and generate an App Password.</p>";
?>
