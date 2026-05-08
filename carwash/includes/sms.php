<?php
// Africa's Talking SMS helper
// Requires .env values:
// AT_ENV=sandbox|production
// AT_USERNAME=your_username
// AT_API_KEY=your_api_key
// AT_SENDER=SenderID_or_shortCode (optional)
// SUPPLIER_OMO_PHONE=+233XXXXXXXXX (optional, used to prefill forms)

if (!function_exists('send_sms_africastalking')) {
    function send_sms_africastalking(string $to, string $message, ?string $from = null): array {
        $env = getenv('AT_ENV') ?: 'production';
        $host = strtolower($env) === 'sandbox'
            ? 'https://api.sandbox.africastalking.com'
            : 'https://api.africastalking.com';
        $url = $host . '/version1/messaging';

        $username = getenv('AT_USERNAME') ?: '';
        $apiKey   = getenv('AT_API_KEY') ?: '';
        $sender   = $from ?: (getenv('AT_SENDER') ?: '');

        if ($username === '' || $apiKey === '') {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Missing AT_USERNAME or AT_API_KEY in environment.'
            ];
        }
        if ($to === '' || $message === '') {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Missing destination number or message.'
            ];
        }

        $payload = http_build_query([
            'username' => $username,
            'to'       => $to,
            'message'  => $message,
            // Only include from if provided (some setups require pre-registration)
        ]);
        if ($sender !== '') {
            $payload .= '&from=' . urlencode($sender);
        }
        // Default to bulk mode on
        $payload .= '&bulkSMSMode=1';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        // Reasonable timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $respBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($respBody === false) {
            return [
                'success' => false,
                'status' => $httpCode,
                'error' => 'cURL error: ' . $curlErr
            ];
        }

        $json = json_decode($respBody, true);
        // Basic success check according to Africa's Talking structure
        // Example: {"SMSMessageData":{"Recipients":[{"status":"Success","statusCode":101,...}]}}
        $ok = false;
        $details = [];
        if (isset($json['SMSMessageData']['Recipients']) && is_array($json['SMSMessageData']['Recipients'])) {
            foreach ($json['SMSMessageData']['Recipients'] as $r) {
                $details[] = $r;
                if (isset($r['status']) && strtolower($r['status']) === 'success') {
                    $ok = true;
                }
            }
        }

        return [
            'success' => $ok,
            'status' => $httpCode,
            'response' => $json ?: $respBody,
            'details' => $details
        ];
    }
}
