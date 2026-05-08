<?php
session_start();

// ── Load .env FIRST so getenv() picks up the right values ──────────────────
$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1), " \t\n\r\x0B\"'");
        if ($key !== '') { $_ENV[$key] = $value; putenv("$key=$value"); }
    }
}

require_once __DIR__ . '/config/dev.php';

if (isset($_GET['logout'])) {
    $_SESSION['dev_logged_in'] = false;
    session_regenerate_id(true);
    header('Location: dev_login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $s = $_POST['secret']   ?? '';
    if ($u === DEV_USERNAME && hash_equals(DEV_SECRET, $s)) {
        $_SESSION['dev_logged_in'] = true;
        $_SESSION['dev_username'] = $u;
        session_regenerate_id(true);
        session_write_close(); // Prevent session hang on redirect
        header('Location: dev_portal.php');
        exit;
    } else {
        $error = 'Invalid credentials. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WashHub CEO Console — Login</title>
  <link rel="icon" type="image/png" href="../frontend/new logo.png?v=washhub">
  <link rel="shortcut icon" type="image/png" href="../frontend/new logo.png?v=washhub">
  <link rel="apple-touch-icon" href="../frontend/new logo.png?v=washhub">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --brand: #00AEEF;
      --brand-dark: #0088BC;
      --navy-dark: #0B1736;
      --navy-deep: #04091A;
      --navy-panel: #0C1529;
      --text-main: #FFFFFF;
      --text-muted: #94A3B8;
    }
    
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: 'Inter', sans-serif;
      background: var(--navy-deep);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      overflow: hidden;
    }

    /* --- LEFT PANEL (Brand) --- */
    .brand-panel {
      width: 50%;
      background: radial-gradient(circle at center, #11265C 0%, var(--navy-dark) 100%);
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 40px;
      overflow: hidden;
    }
    
    /* Floating Circles Background */
    .bg-circle {
      position: absolute;
      border-radius: 50%;
      background: rgba(0, 174, 239, 0.05);
      z-index: 1;
    }
    .circle-1 { width: 600px; height: 600px; top: -100px; right: -150px; }
    .circle-2 { width: 400px; height: 400px; bottom: -50px; left: -100px; }
    
    /* Massive subtle outline logo in background */
    .bg-logo {
      position: absolute; top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      width: 80%; opacity: 0.03; z-index: 1; pointer-events: none;
      filter: grayscale(100%);
    }

    .brand-content { position: relative; z-index: 10; text-align: center; max-width: 500px; }
    
    .brand-logo-img { width: 140px; height: auto; margin-bottom: 24px; animation: floatLogo 4s ease-in-out infinite; }
    
    @keyframes floatLogo {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-8px); }
      100% { transform: translateY(0px); }
    }

    .brand-title { font-size: 42px; font-weight: 800; letter-spacing: -1px; margin-bottom: 5px; }
    .brand-subtitle { font-size: 14px; font-weight: 600; letter-spacing: 3px; color: var(--brand); margin-bottom: 30px; text-transform: uppercase; }
    
    .quote-box {
      background: rgba(0, 174, 239, 0.1);
      border: 1px solid rgba(0, 174, 239, 0.3);
      padding: 16px 24px;
      border-radius: 12px;
      font-style: italic;
      font-weight: 500;
      color: #E2E8F0;
      margin-bottom: 30px;
    }
    
    .brand-desc { font-size: 15px; color: var(--text-muted); line-height: 1.6; margin-bottom: 40px; }
    
    .badges { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
    .badge {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      padding: 8px 16px;
      border-radius: 50px;
      font-size: 13px;
      font-weight: 600;
      color: #cbd5e1;
      display: flex; align-items: center; gap: 8px;
    }
    .badge i { color: var(--brand); }

    .brand-footer { position: absolute; bottom: 30px; font-size: 12px; color: rgba(255,255,255,0.3); z-index: 10; }

    /* --- RIGHT PANEL (Login) --- */
    .login-panel {
      width: 50%;
      background: var(--navy-deep);
      background-image: radial-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px);
      background-size: 20px 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px;
    }
    
    .login-box {
      background: var(--navy-panel);
      border: 1px solid rgba(255, 255, 255, 0.05);
      border-radius: 24px;
      padding: 40px;
      width: 100%;
      max-width: 460px;
      box-shadow: 0 25px 50px rgba(0,0,0,0.5);
      animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0; transform: translateY(20px);
    }
    
    @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }

    .login-header { margin-bottom: 30px; }
    .login-header h2 { font-size: 28px; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
    .login-header p { color: var(--text-muted); font-size: 14px; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #94A3B8; }
    .form-input {
      width: 100%; padding: 14px 16px; border-radius: 12px;
      background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.08);
      color: #fff; font-size: 15px; transition: 0.3s; font-family:inherit;
    }
    .form-input:focus { outline: none; border-color: var(--brand); background: rgba(0,0,0,0.4); }
    
    .alert { background: rgba(239, 68, 68, 0.1); border-left: 4px solid #EF4444; color: #FCA5A5; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }

    .btn-submit {
      width: 100%; padding: 14px; border-radius: 12px;
      background: var(--brand); color: #fff; border: none; font-family:inherit;
      font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.3s;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      margin-top: 24px;
      box-shadow: 0 8px 20px rgba(0, 174, 239, 0.2);
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 174, 239, 0.4); }
    
    .footer-text { text-align: center; margin-top: 34px; font-size: 12px; color: var(--text-muted); }
    .footer-text a { color: var(--brand); text-decoration: none; }

    @media (max-width: 900px) {
      body { flex-direction: column; overflow: auto; }
      .brand-panel, .login-panel { width: 100%; }
      .brand-panel { padding: 60px 20px; min-height: 50vh; }
      .login-panel { padding: 40px 20px; min-height: 50vh; }
    }
  </style>
</head>
<body>

  <!-- LEFT PANEL -->
  <div class="brand-panel">
      <div class="bg-circle circle-1"></div>
      <div class="bg-circle circle-2"></div>
      <img src="../frontend/new logo.png" alt="Logo BG" class="bg-logo">
      
      <div class="brand-content">
          <img src="../frontend/new logo.png" alt="WashHub" class="brand-logo-img">
          <h1 class="brand-title">WashHub</h1>
          <div class="brand-subtitle">✦ CEO CONSOLE ✦</div>
          
          <div class="quote-box">
              "Master Control For The Entire SaaS Platform"
          </div>
          
          <p class="brand-desc">
              Your centralized hub to manage tenant subscriptions, branch databases, system metrics, and automated SMS reminders.
          </p>
          
          <div class="badges">
              <div class="badge"><i class="fas fa-server"></i> Tenant DBs</div>
              <div class="badge"><i class="fas fa-sms"></i> SMS Reminders</div>
              <div class="badge"><i class="fas fa-globe"></i> Branches</div>
          </div>
      </div>
      
      <div class="brand-footer">
          v2.0.0 — GothTech Consult Ltd. &copy; <?php echo date('Y'); ?>
      </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="login-panel">
      <div class="login-box">
          <div class="login-header">
              <h2>Developer Access 🔐</h2>
              <p>Sign in to the WashHub CEO Console</p>
          </div>
          
          <?php if ($error): ?>
              <div class="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>
          
          <form method="post">
              <div class="form-group">
                  <label class="form-label">Username</label>
                  <input type="text" name="username" class="form-input" placeholder="Developer / CEO Username" required autocomplete="username">
              </div>
              <div class="form-group">
                  <label class="form-label">Secret Key</label>
                  <input type="password" name="secret" class="form-input" placeholder="•••••••••" required autocomplete="current-password">
              </div>
              <button type="submit" class="btn-submit">
                  <i class="fas fa-code"></i> Access Portal
              </button>
          </form>
          
          <div class="footer-text">
              Powered by <a href="https://gothtech.com" target="_blank">GothTech Consult</a>
          </div>
      </div>
  </div>
  
</body>
</html>
