<?php
// Notification Helper - SMS and Email

/**
 * Send SMS notification using Hubtel API (Ghana)
 */
if (!function_exists('sendSMS')) {
function sendSMS($phone, $message) {
    $env_path = __DIR__ . '/../.env';
    $apiKey = '';
    $senderId = 'WashHub';
    $clientId = '';

    // Load Hubtel credentials from .env
    if (file_exists($env_path)) {
        foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1), " \t\n\r\x0B\"'");
            if ($key === 'HUBTEL_API_KEY') $apiKey = $val;
            if ($key === 'HUBTEL_CLIENT_ID') $clientId = $val;
            if ($key === 'HUBTEL_SENDER_ID') $senderId = $val;
        }
    }

    if (empty($apiKey)) {
        error_log("SMS not sent: HUBTEL_API_KEY not configured");
        return false;
    }

    // Format phone number (ensure Ghana format)
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 10) {
        $phone = '233' . substr($phone, 1);
    } elseif (strlen($phone) === 12 && strpos($phone, '0') === 0) {
        $phone = '233' . substr($phone, 1);
    }

    // Hubtel API endpoint
    $url = 'https://api.hubtel.com/v1/messages';

    $data = [
        'From' => $senderId,
        'To' => $phone,
        'Content' => $message,
        'RegisteredDelivery' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($clientId . ':' . $apiKey)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 || $http_code === 201) {
        error_log("SMS sent successfully to $phone");
        return true;
    } else {
        error_log("SMS failed to $phone. HTTP $http_code: $response");
        return false;
    }
}
}

/**
 * Send email notification using PHPMailer
 */
if (!function_exists('sendEmail')) {
function sendEmail($to, $subject, $body) {
    $env_path = __DIR__ . '/../.env';
    $smtp_host = 'smtp.gmail.com';
    $smtp_port = 587;
    $smtp_username = '';
    $smtp_password = '';
    $smtp_from = 'noreply@washhub.com';
    $smtp_from_name = 'WashHub';
    
    // Load SMTP credentials from .env
    if (file_exists($env_path)) {
        foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1), " \t\n\r\x0B\"'");
            if ($key === 'SMTP_HOST') $smtp_host = $val;
            if ($key === 'SMTP_PORT') $smtp_port = $val;
            if ($key === 'SMTP_USERNAME') $smtp_username = $val;
            if ($key === 'SMTP_PASSWORD') $smtp_password = $val;
            if ($key === 'SMTP_FROM') $smtp_from = $val;
            if ($key === 'SMTP_FROM_NAME') $smtp_from_name = $val;
        }
    }
    
    if (empty($smtp_username) || empty($smtp_password)) {
        error_log("Email not sent: SMTP credentials not configured");
        return false;
    }
    
    // Use PHP's mail() as fallback if PHPMailer not available
    $headers = [
        'From' => "$smtp_from_name <$smtp_from>",
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'Reply-To' => $smtp_from
    ];
    
    $headers_str = '';
    foreach ($headers as $key => $value) {
        $headers_str .= "$key: $value\r\n";
    }
    
    $success = mail($to, $subject, $body, $headers_str);
    
    if ($success) {
        error_log("Email sent successfully to $to");
    } else {
        error_log("Email failed to $to");
    }
    
    return $success;
}
}

/**
 * Send provision notification (SMS + Email)
 */
if (!function_exists('sendProvisionNotification')) {
function sendProvisionNotification($client_name, $bay_name, $contact_name, $contact_phone, $contact_email, $sa_username, $sa_password, $login_url, $subscription_end) {
    $messages = [];
    
    // SMS Message
    $sms_message = "WashHub: Your car wash bay '$bay_name' has been provisioned successfully! Login: $login_url Username: $sa_username Password: $sa_password Subscription ends: $subscription_end. Change password after login. Call 0509729601 for support.";
    
    if (!empty($contact_phone)) {
        $sms_sent = sendSMS($contact_phone, $sms_message);
        $messages[] = $sms_sent ? "✅ SMS sent to $contact_phone" : "❌ SMS failed to $contact_phone";
    } else {
        $messages[] = "⚠️ No phone number provided, SMS not sent";
    }
    
    // Email Message (HTML)
    $email_subject = "Your WashHub Bay is Ready - $bay_name";
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: #00AEEF; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .credentials { background: white; padding: 20px; border-left: 4px solid #00AEEF; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🚗 Your WashHub Bay is Ready!</h1>
            </div>
            <div class='content'>
                <p>Dear $contact_name,</p>
                <p>Congratulations! Your car wash bay <strong>$bay_name</strong> has been successfully provisioned on the WashHub platform.</p>
                
                <div class='credentials'>
                    <h3>🔐 Your Login Details</h3>
                    <p><strong>Login URL:</strong> <a href='$login_url'>$login_url</a></p>
                    <p><strong>Username:</strong> $sa_username</p>
                    <p><strong>Password:</strong> $sa_password</p>
                    <p style='color: #d32f2f;'><strong>⚠️ Please change your password after your first login!</strong></p>
                </div>
                
                <p><strong>Subscription Details:</strong></p>
                <ul>
                    <li>Client: $client_name</li>
                    <li>Bay: $bay_name</li>
                    <li>Subscription Ends: $subscription_end</li>
                </ul>
                
                <p><strong>What's Next?</strong></p>
                <ol>
                    <li>Log in to your WashHub dashboard</li>
                    <li>Change your password immediately</li>
                    <li>Create an Admin account for daily operations</li>
                    <li>Add your workers and start logging washes</li>
                </ol>
                
                <center><a href='$login_url' class='button'>🚀 Login to WashHub</a></center>
                
                <p><strong>Need Help?</strong></p>
                <p>Call us: <strong>0509729601</strong><br>
                WhatsApp: <strong>0509729601</strong><br>
                Email: <strong>support@washhub.com</strong></p>
                
                <div class='footer'>
                    <p>© 2026 WashHub by GothTech Consult. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    if (!empty($contact_email) && filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $email_sent = sendEmail($contact_email, $email_subject, $email_body);
        $messages[] = $email_sent ? "✅ Email sent to $contact_email" : "❌ Email failed to $contact_email";
    } else {
        $messages[] = "⚠️ No valid email provided, email not sent";
    }

    return $messages;
}
}

/**
 * Send renewal reminder notification
 */
if (!function_exists('sendRenewalReminder')) {
function sendRenewalReminder($client_name, $bay_name, $contact_phone, $contact_email, $subscription_end) {
    $messages = [];
    
    // SMS Message
    $sms_message = "WashHub: Your subscription for '$bay_name' expires on $subscription_end. Please renew to avoid service interruption. Call 0509729601 or WhatsApp 0509729601 to renew.";
    
    if (!empty($contact_phone)) {
        $sms_sent = sendSMS($contact_phone, $sms_message);
        $messages[] = $sms_sent ? "✅ SMS reminder sent to $contact_phone" : "❌ SMS reminder failed to $contact_phone";
    }
    
    // Email Message
    $email_subject = "Subscription Renewal Reminder - $bay_name";
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .alert { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; }
            .button { display: inline-block; background: #00AEEF; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>⚠️ Subscription Renewal Reminder</h1>
            </div>
            <div class='content'>
                <p>Dear $contact_name,</p>
                
                <div class='alert'>
                    <h3>⏰ Your subscription expires soon!</h3>
                    <p><strong>Bay:</strong> $bay_name<br>
                    <strong>Expiry Date:</strong> $subscription_end</p>
                </div>
                
                <p>Please renew your subscription before the expiry date to avoid service interruption.</p>
                
                <p><strong>How to Renew:</strong></p>
                <ol>
                    <li>Call us at <strong>0509729601</strong></li>
                    <li>WhatsApp us at <strong>0509729601</strong></li>
                    <li>Visit our office with payment</li>
                </ol>
                
                <center><a href='https://wa.me/233509729601?text=Hi!%20I%20want%20to%20renew%20my%20WashHub%20subscription%20for%20$bay_name' class='button'>💬 Renew via WhatsApp</a></center>
                
                <p>If you've already renewed, please ignore this message.</p>
                
                <p><strong>Contact Support:</strong><br>
                Phone: 0509729601<br>
                WhatsApp: 0509729601</p>
                
                <p>© 2026 WashHub by GothTech Consult</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    if (!empty($contact_email) && filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $email_sent = sendEmail($contact_email, $email_subject, $email_body);
        $messages[] = $email_sent ? "✅ Email reminder sent to $contact_email" : "❌ Email reminder failed to $contact_email";
    }

    return $messages;
}
}
?>
