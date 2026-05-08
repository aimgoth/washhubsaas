# WashHub Deployment Guide

## Pre-Deployment Checklist

### 1. Security Configuration

**Current Setup (Localhost Development):**
- `.htaccess` - Simplified for localhost compatibility
- Error display disabled
- Session security enabled (HTTP-only, strict mode)

**Production Setup:**
- Use `.htaccess.production` instead of `.htaccess`
- Full security headers enabled (HSTS, CSP, etc.)
- HTTPS enforced
- Request size limits active

### 2. Deployment Steps

#### Step 1: Update .htaccess
```bash
# On production server:
mv .htaccess .htaccess.local
mv .htaccess.production .htaccess
```

#### Step 2: Set Environment Variables
Ensure your production `.env` file has:
```
DB_SERVER=your-production-db-host
DB_USERNAME=your-db-user
DB_PASSWORD=your-strong-password
DB_NAME=your-db-name
JWT_SECRET=your-super-secret-jwt-key-at-least-32-chars
SUPPORT_WHATSAPP=233509729601
```

#### Step 3: Install Backend Dependencies
```bash
cd backend
npm install
npm start
```

#### Step 4: Ensure HTTPS is Configured
- Install SSL certificate on your server
- Update CORS origins in `backend/server.js` to your production domain
- The `.htaccess.production` will force HTTPS redirects

#### Step 5: Set File Permissions
```bash
# Make logs directory writable
chmod 755 logs/

# Protect .env file
chmod 600 .env
```

#### Step 6: Test Security Features
- Test rate limiting (try submitting form multiple times)
- Test CSRF protection (try submitting without token)
- Test input validation (try XSS payloads)
- Check security headers in browser DevTools

## Security Features Summary

### ✅ Active Security Layers

1. **SQL Injection Protection**
   - All queries use prepared statements
   - No direct SQL concatenation

2. **CSRF Protection**
   - CSRF tokens on all forms
   - Tokens validated on submission
   - 1-hour token expiry

3. **XSS Protection**
   - Input sanitization
   - Suspicious pattern detection
   - Content-Security-Policy header (production)

4. **Rate Limiting**
   - Contact form: 5/minute
   - API endpoints: 100/15min
   - Prevents DoS attacks

5. **Session Security**
   - HTTP-only cookies
   - Secure flag (HTTPS only)
   - Strict mode (prevents fixation)
   - SameSite protection
   - 1-hour timeout

6. **File Access Protection**
   - .env blocked
   - .git blocked
   - Logs blocked
   - Database files blocked

7. **Security Headers**
   - X-Content-Type-Options
   - X-Frame-Options
   - X-XSS-Protection
   - Strict-Transport-Security (production)
   - Content-Security-Policy (production)
   - Referrer-Policy (production)
   - Permissions-Policy (production)

8. **Request Size Limits**
   - 10MB max request body
   - 100 max fields
   - Prevents DoS via large payloads

9. **Input Validation**
   - Email validation
   - Phone validation
   - Length validation
   - Suspicious input detection

10. **Security Logging**
    - All violations logged to `logs/security.log`
    - Includes IP, timestamp, user-agent
    - Helps track attack patterns

## Post-Deployment

### Monitor Security Logs
```bash
tail -f logs/security.log
```

### Regular Security Audits
- Check for new vulnerabilities in dependencies
- Review security logs weekly
- Update dependencies regularly
- Test penetration testing periodically

### Backup Strategy
- Daily database backups
- Code repository backups
- Keep .env file backed up securely
- Document all credentials securely

## Troubleshooting

### If 500 Error After Deployment
1. Check PHP error log: `logs/php_errors.log`
2. Check Apache error log
3. Verify .htaccess syntax
4. Ensure PHP modules are installed

### If CSRF Errors
1. Clear browser cache
2. Ensure session is working
3. Check that `get_csrf_token.php` is accessible

### If Rate Limiting Too Aggressive
- Adjust limits in `config/security.php`
- Check `logs/security.log` for patterns

## Contact Support
For deployment issues: 0509729601
