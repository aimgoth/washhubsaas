# WashHub Notification System Setup

## Overview
The WashHub system now includes automatic SMS and email notifications for:
1. **New Client Provisioning** - When you provision a new washing bay client
2. **Subscription Renewal Reminders** - Automatic reminders before subscription expires

## How It Works

### 1. Provisioning Notifications
When you provision a new client bay in the CEO Console:
- ✅ SMS sent to client's phone with login details
- ✅ Email sent to client's email with login details and instructions
- ✅ Both notifications include:
  - Login URL
  - Username
  - Password
  - Subscription end date
  - Support contact information

### 2. Renewal Reminders
Automatic reminders are sent to clients whose subscriptions expire within 3 days:
- ✅ SMS reminder sent
- ✅ Email reminder sent
- ✅ Includes renewal instructions and WhatsApp link

## Setup Instructions

### Step 1: Get Hubtel API Credentials (for SMS) - Simple & Ghana-friendly
1. Go to https://hubtel.com
2. Sign up for a free account
3. Go to Developer Tools → API Keys
4. Copy your Client ID and API Key
5. Add to your `.env` file:
   ```
   HUBTEL_CLIENT_ID=your_client_id
   HUBTEL_API_KEY=your_api_key
   HUBTEL_SENDER_ID=WashHub
   ```

### Step 2: Configure SMTP (for Email)
**Option A: Using Gmail**
1. Enable 2-factor authentication on your Gmail
2. Generate an App Password:
   - Go to Google Account → Security → App Passwords
   - Generate a new app password
3. Add to your `.env` file:
   ```
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USERNAME=your_email@gmail.com
   SMTP_PASSWORD=your_app_password
   SMTP_FROM=noreply@washhub.com
   SMTP_FROM_NAME=WashHub
   ```

**Option B: Using a Transactional Email Service**
- SendGrid, Mailgun, or Amazon SES
- Use their SMTP credentials in the same format

### Step 3: Copy .env.example to .env
```bash
cp carwash/.env.example carwash/.env
```

### Step 4: Edit .env with your credentials
Open `carwash/.env` and fill in your actual credentials.

### Step 5: Test the System
1. Go to CEO Console
2. Provision a test client with your phone and email
3. Check if you receive both SMS and email
4. Check error logs if notifications fail:
   ```bash
   tail -f carwash/logs/php_errors.log
   ```

## Notification Templates

### Provision SMS Template
```
WashHub: Your car wash bay '{bay_name}' has been provisioned successfully! 
Login: {login_url} 
Username: {username} 
Password: {password} 
Subscription ends: {subscription_end}. 
Change password after login. Call 0509729601 for support.
```

### Provision Email Template
- Professional HTML email with:
  - Welcome header
  - Login credentials (highlighted)
  - Subscription details
  - Onboarding checklist
  - Support contact information
  - Login button

### Renewal Reminder Template
- Warning-themed email/SMS with:
  - Expiry date highlighted
  - Renewal instructions
  - WhatsApp link for quick renewal
  - Support contact

## Troubleshooting

### SMS Not Sending
1. Check HUBTEL_API_KEY and HUBTEL_CLIENT_ID in .env
2. Check phone number format (should be Ghana format: 0509729601)
3. Check Hubtel account balance
4. Check error logs: `carwash/logs/php_errors.log`

### Email Not Sending
1. Check SMTP credentials in .env
2. For Gmail: ensure you're using App Password, not regular password
3. Check email address is valid
4. Check if SMTP port is blocked by firewall

### Both Not Sending
1. Check .env file exists in carwash directory
2. Check file permissions on .env (should be 600)
3. Check PHP error logs
4. Ensure curl is enabled in PHP

## Cost Estimates

### Hubtel SMS
- Ghana: ~GHS 0.04-0.06 per SMS
- Free trial credits available for testing
- Pay-as-you-go, no monthly fees

### Email (Gmail)
- Free (up to daily sending limits)
- Transactional services: ~$0.001 per email

## Security Notes

- Never commit .env file to git
- Use strong passwords for SMTP
- Rotate Hubtel API keys periodically
- Monitor SMS/email usage for abuse

## Customization

To modify notification templates, edit:
- `carwash/config/notifications.php`
- Functions: `sendProvisionNotification()` and `sendRenewalReminder()`

## Support
For issues with notifications:
- Call: 0509729601
- WhatsApp: 0509729601
- Email: support@washhub.com
