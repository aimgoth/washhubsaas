<?php
// Public page: How the app works
$appName = getenv('APP_NAME') ?: 'WashHub';
$whatsapp = getenv('SUPPORT_WHATSAPP') ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>How <?php echo htmlspecialchars($appName); ?> Works</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    :root {
      --brand: #00AEEF;
      --brand-dark: #0088BC;
      --ink: #1B3FA0;            /* Rich deep blue from logo */
      --text: #243E63;           /* Strong deep blue for text */
      --muted: #4B6E96;          /* Muted deep blue */
      --bg: #C8EAF9;             /* Visibly deeper light blue */
      --card: #ffffff;
      --border: #9ADAF7;         /* Deeper light blue borders */
      --shadow: 0 10px 30px rgba(27, 63, 160, 0.08); 
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    
    body { 
        font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        background: var(--bg); 
        color: var(--text); 
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
    }

    /* Page Entrance Animations */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    @keyframes floatLogo {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-3px); }
      100% { transform: translateY(0px); }
    }

    /* Header */
    header {
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border-bottom: 2px solid var(--border);
      height:-50px;
    }
    header .container {
      max-width: 100%;
      padding: 0 4%;
      display: flex; align-items: center; justify-content: space-between; height: 130px;
    }
    .brand-logo { 
        height: 150px !important;
        margin-left: -15px;
        width: auto; 
        object-fit: contain; 
        animation: floatLogo 4s ease-in-out infinite;
    }
    .brand-logo:hover { animation: none; transform: scale(1.03) translateX(-8px); }

    .nav-actions { display: flex; align-items: center; gap: 16px; margin-left: auto; }
    
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 14px 32px; border-radius: 99px;
      font-weight: 700; font-size: 1rem; text-decoration: none;
      background: linear-gradient(135deg, var(--brand) 0%, var(--ink) 100%);
      color: #fff !important; border: none;
      box-shadow: 0 6px 20px rgba(0, 174, 239, 0.4);
      transition: all 0.3s ease;
    }
    .btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(27, 63, 160, 0.5);
    }
    .btn.outline {
      background: #fff; color: var(--ink) !important; border: 2px solid var(--brand); box-shadow: none;
    }
    .btn.outline:hover { background: var(--brand); color: #fff !important; }

    /* Hero */
    .hero { 
        text-align: center; 
        padding: 80px 20px 100px;
        background: linear-gradient(180deg, var(--bg) 0%, #ffffff 100%);
    }
    .hero h1 { margin: 0 0 16px; font-size: 3rem; color: var(--ink); animation: fadeUp 0.8s ease-out forwards; opacity: 0; }
    .hero p { font-size: 1.25rem; color: var(--text); max-width: 600px; margin: 0 auto; animation: fadeUp 0.8s ease-out 0.2s forwards; opacity: 0; }
    
    .main-container { max-width: 1100px; margin: -50px auto 80px; padding: 0 20px; z-index: 2; position: relative; }
    
    .card { 
        background: var(--card); border-radius: 20px; 
        box-shadow: 0 20px 40px rgba(27, 63, 160, 0.1); 
        padding: 40px; border: 1px solid var(--border);
        animation: fadeUp 1s ease-out 0.4s forwards; opacity: 0;
    }
    .card h2 { margin: 0 0 20px; color: var(--ink); font-size: 1.8rem; display: flex; align-items: center; gap: 12px; }
    .card h2 i { color: var(--brand); }
    
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 40px; }
    
    /* Steps */
    .step { 
        background: #F4FAFD; border: 1px solid var(--border); 
        border-radius: 16px; padding: 24px; transition: 0.3s; 
    }
    .step:hover { transform: translateY(-5px); box-shadow: var(--shadow); border-color: var(--brand); }
    .step h3 { margin: 0 0 10px; font-size: 1.2rem; color: var(--ink); display: flex; align-items: center; gap: 10px; }
    .step i { color: var(--brand); }
    
    .list { margin: 10px 0 0 20px; color: var(--text); }
    .list li { margin-bottom: 8px; }
    
    .note { 
        margin-top: 30px; background: #e0f2fe; border-left: 5px solid var(--brand); 
        padding: 20px; border-radius: 12px; color: var(--ink); font-weight: 600; 
    }
    
    .cta-row { margin-top: 30px; display: flex; gap: 16px; flex-wrap: wrap; }

    @media (max-width: 768px) {
        .hero h1 { font-size: 2.2rem; }
        .card { padding: 20px; }
        .brand-logo { height: 70px !important; }
        header .container { height: 90px; }
        .nav-actions { display: none; }
    }
  </style>
</head>
<body>

  <!-- Consistent Global Header -->
  <header>
    <div class="container">
      <div class="brand">
        <a href="index.php">
          <img src="../frontend/new logo.png" alt="<?php echo htmlspecialchars($appName); ?>" class="brand-logo" />
        </a>
      </div>
      <div class="nav-actions">
        <a href="login.php" class="btn">Login to Dashboard</a>
      </div>
    </div>
  </header>

  <div class="hero">
    <h1>How <?php echo htmlspecialchars($appName); ?> Works</h1>
    <p>The smartest workflow for modern car wash operations and management.</p>
  </div>
  
  <div class="main-container">
    <div class="card">
      <h2><i class="fas fa-route"></i> Daily Workflow</h2>
      <div class="grid">
        <div class="step">
          <h3><i class="fas fa-user-shield"></i> 1. Secure Access</h3>
          <p class="muted">Super Admin or Admins securely log in to manage their specific bays.</p>
        </div>
        <div class="step">
          <h3><i class="fas fa-plus-circle"></i> 2. Record Washes</h3>
          <p class="muted">Log vehicles instantly (car, carpet, motor/bike), track the worker, size, and auto-generate timestamps.</p>
        </div>
        <div class="step">
          <h3><i class="fas fa-chart-line"></i> 3. Live Dashboard</h3>
          <p class="muted">Monitor today's active metrics, month-to-date revenue splits, and live statuses on a beautiful UI.</p>
        </div>
        <div class="step">
          <h3><i class="fas fa-file-invoice"></i> 4. Daily Reports</h3>
          <p class="muted">Easily trigger the end-of-day reports. System flawlessly resets dashboard to track tomorrow's activity.</p>
        </div>
        <div class="step">
          <h3><i class="fas fa-check-circle"></i> 5. Payment Settling</h3>
          <p class="muted">Mark vehicles as paid/confirmed. Cleanly hides them from open daily task lists to avoid clutter.</p>
        </div>
      </div>

      <h2><i class="fas fa-database"></i> Roles & Privileges</h2>
      <ul class="list" style="margin-bottom: 40px;">
        <li><strong>Super Admin:</strong> Full overarching visibility, developer settings, and absolute platform control.</li>
        <li><strong>Admin:</strong> Strict access to view and manage their specific assigned bay environment.</li>
        <li><strong>Workers:</strong> System tracks workers implicitly per-wash for detailed performance statistics.</li>
      </ul>

      <h2><i class="fas fa-laptop-code"></i> Setup Requirements</h2>
      <ul class="list" style="margin-bottom: 20px;">
        <li>Seamlessly runs on any Modern Browser. Native desktop apps are available for Windows via Electron containerization.</li>
        <li>Your software runs natively from the core XAMPP architecture to guarantee lightning-fast local or hybrid speed.</li>
      </ul>

      <div class="note">
        <i class="fas fa-info-circle"></i> For custom installations, dedicated deployments, or platform setup, please contact GothTech Consult directly on WhatsApp.
      </div>

      <div class="cta-row">
        <a class="btn" href="login.php"><i class="fas fa-door-open"></i> Go to Platform Login</a>
        <a class="btn outline" target="_blank" href="https://wa.me/<?php echo htmlspecialchars($whatsapp); ?>?text=Hello%20GothTech%20Consult%2C%20I%20want%20to%20setup%20WashHub.">
          <i class="fab fa-whatsapp"></i> Contact GothTech Consult
        </a>
      </div>
    </div>
  </div>
  
</body>
</html>
