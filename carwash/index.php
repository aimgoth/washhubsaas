<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Fetch top 4 testimonials
$testimonials = [];
try {
    $stmt = $conn->query("SELECT customer_name, company_name, rating, review_text FROM testimonials WHERE rating >= 4 ORDER BY created_at DESC LIMIT 4");
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            $testimonials[] = $row;
        }
    }
} catch (Exception $e) {
    // Suppress error if table doesn't exist yet
}

// If logged in, route to dashboard
if (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>WashHub — Car Wash Management Software</title>
  <meta name="description" content="WashHub helps car wash businesses log washes, manage workers, and get clear daily reports with ease." />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Favicons & App Icons -->
  <link rel="icon" type="image/png" sizes="32x32" href="../frontend/new logo.png?v=2">
  <link rel="icon" type="image/png" sizes="192x192" href="../frontend/new logo.png?v=2">
  <link rel="apple-touch-icon" href="../frontend/new logo.png?v=2">
  <link rel="manifest" href="manifest.json">
  <link rel="icon" type="image/png" sizes="16x16" href="../frontend/new logo.png?v=2">
  <meta name="theme-color" content="#00AEEF">
  <!-- Open Graph (helps some platforms show the logo) -->
  <meta property="og:title" content="WashHub — Car Wash Management Software">
  <meta property="og:description" content="Log washes, track workers, and get clear daily reports with ease.">
  <meta property="og:image" content="../frontend/new logo.png">
  <meta property="og:type" content="website">
  <style>
    :root {
      --brand: #00AEEF;
      --brand-dark: #0088BC;
      --ink: #1B3FA0;            /* Rich deep blue from logo */
      --text: #243E63;           /* Strong deep blue for text */
      --muted: #4B6E96;          /* Muted deep blue */
      --bg: #C8EAF9;             /* A visibly deeper light blue derived from the logo */
      --card: #ffffff;
      --ring: rgba(0,174,239,0.3);
      --border: #9ADAF7;         /* Deeper light blue borders */
      --shadow: 0 10px 30px rgba(27, 63, 160, 0.08); /* Deep blue shadow */
      --shadow-hover: 0 20px 40px rgba(0, 174, 239, 0.2); /* Light blue glow shadow */
    }
    
    * { box-sizing: border-box; margin: 0; padding: 0; }
    
    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      color: var(--text);
      background: var(--bg);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; text-decoration: none; }
    .container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }

    /* Clean Bright Header */
    header {
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border-bottom: 2px solid var(--border);
    }
    
    /* Override container exclusively for the header to push items to the very edge */
    header .nav.container {
      max-width: 100% !important;
      padding: 0 4% !important; /* Forces logo far left and buttons far right */
    }

    /* Refined sleek nav bar */
    .nav { display: flex; align-items: center; justify-content: space-between; height: 85px; width: 100%; transition: background 0.3s ease; }
    .brand { display: flex; align-items: center; }
    
    /* Smooth Floating Logo Animation */
    @keyframes floatLogo {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-3px); }
      100% { transform: translateY(0px); }
    }

    /* SLEEKA BRAND LOGO SIZE */
    .brand-logo { 
        height: 150px !important;     /* Reasonable sleek size */
        width: auto !important; 
        object-fit: contain; 
        background: transparent !important;
        border: none !important;
        border-radius: 0 !important;
        display: inline-block;
        transition: transform 0.3s ease;
        animation: floatLogo 4s ease-in-out infinite;
    }
    .brand:hover .brand-logo { transform: scale(1.03) !important; animation: none; }

    .nav-actions { display: flex; align-items: center; gap: 16px; margin-left: auto; }
    
    /* Buttons utilizing logo colors heavily */
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 14px 32px; border-radius: 99px; /* Pill shape */
      font-weight: 700; font-size: 1rem;
      background: linear-gradient(135deg, var(--brand) 0%, var(--ink) 100%); /* Light blue to Deep blue gradient */
      color: #fff !important;
      border: none;
      box-shadow: 0 6px 20px rgba(0, 174, 239, 0.4);
      transition: all 0.3s ease;
      cursor: pointer;
    }
    .btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(27, 63, 160, 0.5);
      color: #fff;
    }
    .btn.outline {
      background: var(--card); color: var(--ink) !important; border: 2px solid var(--brand); box-shadow: none;
    }
    .btn.outline:hover { background: var(--brand); color: #fff !important; transform: translateY(-3px); }

    /* Hero Section heavily driven by Light Blue/Deep Blue accents */
    .hero {
      position: relative;
      background: #C8EAF9;
      padding: 120px 0;
      overflow: hidden;
      border-bottom: 2px solid var(--brand); /* Thick light blue separator */
    }
    
    /* Sliding Background */
    .hero-slider {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      z-index: 0;
    }
    .hero-slider .slide {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      background-size: cover;
      background-position: center;
      opacity: 0;
      animation: crossfade 20s infinite;
    }
    .hero-slider .slide:nth-child(1) { background-image: url('../frontend/background1.jpg'); animation-delay: 0s; }
    .hero-slider .slide:nth-child(2) { background-image: url('../frontend/background2.jpg'); animation-delay: 5s; }
    .hero-slider .slide:nth-child(3) { background-image: url('../frontend/background3.jpg'); animation-delay: 10s; }
    .hero-slider .slide:nth-child(4) { background-image: url('../frontend/background4.jpg'); animation-delay: 15s; }
    
    @keyframes crossfade {
      0% { opacity: 0; }
      10% { opacity: 1; }
      25% { opacity: 1; }
      35% { opacity: 0; }
      100% { opacity: 0; }
    }
    
    /* Overlay to protect text while infusing deeper light blue */
    .hero-overlay {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      background: linear-gradient(90deg, rgba(200, 234, 249, 0.95) 0%, rgba(200, 234, 249, 0.6) 50%, rgba(200, 234, 249, 0.2) 100%);
      z-index: 1;
    }
    
    @media (max-width: 900px) {
      .hero-overlay {
        background: rgba(200, 234, 249, 0.85); /* Solid transparent deeper light-blue on mobile */
      }
    }
    
    .hero-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; position: relative; z-index: 2; }
    .hero h1 { font-family: 'Poppins', sans-serif; font-size: 3.8rem; line-height: 1.15; margin: 0 0 24px; font-weight: 800; letter-spacing: -1px; color: var(--ink); text-shadow: 0 0 30px rgba(255,255,255,1), 0 0 15px rgba(255,255,255,1); }
    
    /* Mixing light blue into the heading text */
    .hero h1 span { color: var(--brand); text-shadow: none; }
    
    .hero p { font-size: 1.25rem; color: var(--text); margin: 0 0 40px; font-weight: 600; max-width: 90%; text-shadow: 0 0 15px rgba(255,255,255,1), 0 0 5px rgba(255,255,255,1); }
    
    /* Elegant Page Entrance Animations */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .hero h1 { animation: fadeUp 0.8s ease-out forwards; opacity: 0; }
    .hero p { animation: fadeUp 0.8s ease-out 0.2s forwards; opacity: 0; }
    .hero .btn { animation: fadeUp 0.8s ease-out 0.4s forwards; opacity: 0; }
    
    /* Floating App Mockup Image */
    .hero-figure {
      background: #fff;
      border: 3px solid var(--brand); /* Bold light blue frame */
      border-radius: 20px;
      padding: 12px;
      box-shadow: 0 30px 60px rgba(27, 63, 160, 0.15); /* Deep blue shadow */
      transform: perspective(1000px) rotateY(-5deg);
      transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      animation: fadeUp 1s ease-out 0.5s forwards; 
      opacity: 0;
    }
    .hero-figure:hover { transform: perspective(1000px) rotateY(0deg) translateY(-10px); box-shadow: 0 40px 80px rgba(0, 174, 239, 0.2); }
    .mock { height: 460px; width: 100%; object-fit: contain; border-radius: 12px; border: 1px solid var(--border); background: #C8EAF9; }
    .caption { margin-top: 12px; font-size: 0.95rem; color: var(--ink); text-align: center; font-weight: 700;}

    /* Sections & Cards */
    section { padding: 90px 0; background: var(--bg); transition: background 0.3s ease; }
    section:nth-of-type(even) { background: #D9EFFB; } /* Soft alternate deeper background */ 
    [data-theme="dark"] section:nth-of-type(even) { background: #0B1736; } /* Dark theme alternate */ 

    .section-title { font-family: 'Poppins', sans-serif; color: var(--ink); font-size: 2.8rem; margin: 0 0 16px; text-align: center; font-weight: 800; letter-spacing: -0.5px;}
    .section-sub { color: var(--muted); text-align: center; max-width: 650px; margin: 0 auto 56px; font-size: 1.15rem; }
    
    .grid { display: grid; gap: 30px; }
    .grid.features { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
    .grid.steps { grid-template-columns: repeat(3, 1fr); }
    
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 36px;
      box-shadow: var(--shadow);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
      animation: fadeUp 1s ease-out forwards;
      opacity: 0;
    }
    
    /* Stagger card animations */
    .card:nth-child(1) { animation-delay: 0.2s; }
    .card:nth-child(2) { animation-delay: 0.4s; }
    .card:nth-child(3) { animation-delay: 0.6s; }
    
    /* Add cyan accent line top of card */
    .card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; height: 5px;
        background: linear-gradient(90deg, var(--brand) 0%, var(--ink) 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    .card:hover {
      transform: translateY(-6px);
      box-shadow: var(--shadow-hover);
      border-color: var(--brand);
    }
    .card:hover::before { opacity: 1; }
    
    .card h3 { font-family: 'Poppins', sans-serif; font-size: 1.4rem; margin: 0 0 12px; color: var(--ink); font-weight: 700; }
    .muted { color: var(--text); font-size: 1.05rem; }

    /* Trust Logos */
    .logos { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; justify-content: center; margin-bottom: 40px;}
    .logo-pill { background: #fff; border: 2px solid var(--border); border-radius: 99px; padding: 14px 28px; color: var(--ink); font-weight: 700; font-size: 1.05rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: 0.3s;}
    .logo-pill:hover { border-color: var(--brand); background: var(--brand); color: #fff; transform: translateY(-2px); }

    /* Specific elements */
    .badges { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px;}
    .badge { background: #EAF7FC; color: var(--ink); padding: 8px 16px; border-radius: 99px; font-weight: 700; font-size: 0.9rem; border: 1px solid var(--brand); }

    /* Footer deeply colored with navy */
    footer { background: var(--ink); color: #BCE1F6; padding: 60px 0 40px; border-top: 6px solid var(--brand);}
    footer a { color: #fff; transition: color 0.2s; font-weight: 600;}
    footer a:hover { color: var(--brand); }

    /* Responsive */
    @media (max-width: 1024px) {
      .hero h1 { font-size: 3rem; }
      .hero-grid { gap: 40px; }
      .grid.steps { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    }
    @media (max-width: 768px) {
      .hero { padding: 60px 0; }
      .hero-grid { grid-template-columns: 1fr; text-align: center; }
      .nav-actions { display: none; }
      .section-title { font-size: 2.2rem; }
      .card { padding: 24px; }
      .brand-logo { height: 50px !important; }
    }
    @media (max-width: 480px) {
      .hero h1 { font-size: 2.4rem; }
      .section-title { font-size: 1.8rem; }
    }
  </style>
</head>
<body>
  <!-- Header / Hero -->
  <header>
    <div class="container nav">
      <div class="brand">
        <a href="/carwash/">
          <img src="../frontend/new logo.png" alt="WashHub" class="brand-logo">
        </a>
      </div>
      <div class="nav-actions">
        <a href="#about" class="btn outline" style="margin-right:10px">About Us</a>
        <a href="how_it_works.php" class="btn outline" style="margin-right:10px">How it works</a>
        <a href="#" id="getDesktopApp" class="btn outline" style="margin-right:10px">Get Desktop App</a>
        <a href="login.php" class="btn">Login</a>
      </div>
    </div>
  </header>

  <section class="hero" id="top">
    <!-- Sliding Background Container -->
    <div class="hero-slider">
      <div class="slide"></div>
      <div class="slide"></div>
      <div class="slide"></div>
      <div class="slide"></div>
    </div>
    <!-- Overlay -->
    <div class="hero-overlay"></div>
    
    <div class="container hero-grid">
      <div>
        <h1>Run your <span>wash bay</span> with absolute clarity.</h1>
        <p>Log washes in seconds, track your staff's performance, and close each day with total financial confidence.</p>
        <div style="display:flex;gap:16px;flex-wrap:wrap;justify-content:flex-start;">
          <a class="btn" href="#" onclick="openContactModal(); return false;" style="background: linear-gradient(135deg, var(--brand) 0%, var(--ink) 100%); color:#fff !important; box-shadow: 0 6px 20px rgba(0, 174, 239, 0.4);">
             Contact Us
          </a>
          <a class="btn outline" href="https://wa.me/233509729601?text=Hi!%20I'm%20interested%20in%20WashHub%20for%20my%20car%20wash%20business." target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;">
             <i class="fab fa-whatsapp" style="font-size:1.1rem;"></i> Chat on WhatsApp
          </a>
        </div>
      </div>
      <div class="hero-figure">
        <img id="slideshow" src="assets/short1.png" alt="WashHub Interface" class="mock" style="object-fit: contain; width: 100%; height: 100%;" />
        <div id="slideshow-caption" class="caption">Real-time Performance Dashboard</div>
      </div>
    </div>
  </section>

  <!-- Key Features / Benefits -->
  <section>
    <div class="container">
      <h2 class="section-title">Everything you need to manage your car wash</h2>
      <p class="section-sub">Benefit-focused features that help owners and managers move faster every day.</p>
      <div class="grid features">
        <div class="card">
          <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(0, 174, 239, 0.1); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-bolt" style="font-size: 24px; color: var(--brand);"></i>
          </div>
          <h3 style="font-size: 1.35rem; margin-bottom: 12px; font-weight: 800; color: var(--ink);">Lightning Fast Check-in</h3>
          <p class="muted" style="line-height: 1.6;">Log any service immediately—car wash, detailing, or carpet cleaning—in seconds. The unified dashboard is deeply optimized for mobile point-of-sale speeds to keep your bays moving.</p>
        </div>
        <div class="card">
          <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(0, 174, 239, 0.1); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-users-cog" style="font-size: 24px; color: var(--brand);"></i>
          </div>
          <h3 style="font-size: 1.35rem; margin-bottom: 12px; font-weight: 800; color: var(--ink);">Worker Management</h3>
          <p class="muted" style="line-height: 1.6;">Track daily worker performance, assign tasks to specific employees, and evaluate monthly leaderboards. Beautiful analytics motivate your team with real numbers.</p>
        </div>
        <div class="card">
          <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(0, 174, 239, 0.1); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-file-invoice-dollar" style="font-size: 24px; color: var(--brand);"></i>
          </div>
          <h3 style="font-size: 1.35rem; margin-bottom: 12px; font-weight: 800; color: var(--ink);">End-of-Day Overviews</h3>
          <p class="muted" style="line-height: 1.6;">Say goodbye to messy spreadsheets. Generate clear daily, weekly, and monthly end-of-day revenue summaries and archives for completely clean, error-free accounting.</p>
        </div>
        <div class="card">
          <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(0, 174, 239, 0.1); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-shield-alt" style="font-size: 24px; color: var(--brand);"></i>
          </div>
          <h3 style="font-size: 1.35rem; margin-bottom: 12px; font-weight: 800; color: var(--ink);">Permissions & Roles</h3>
          <p class="muted" style="line-height: 1.6;">Control what your staff sees. Dedicated Super Admin, Admin, and Staff views ensure employees only have access to the exact tools they need, securing your business logic.</p>
        </div>
        <div class="card">
          <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(0, 174, 239, 0.1); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-chart-pie" style="font-size: 24px; color: var(--brand);"></i>
          </div>
          <h3 style="font-size: 1.35rem; margin-bottom: 12px; font-weight: 800; color: var(--ink);">Automated Analytics</h3>
          <p class="muted" style="line-height: 1.6;">Beautifully structured charts break down your revenue splits, most popular services, and peak customer times so you understand your margins at a glance.</p>
        </div>
        <div class="card">
          <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(0, 174, 239, 0.1); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-cloud" style="font-size: 24px; color: var(--brand);"></i>
          </div>
          <h3 style="font-size: 1.35rem; margin-bottom: 12px; font-weight: 800; color: var(--ink);">Secure & Portable</h3>
          <p class="muted" style="line-height: 1.6;">100% Cloud-based. Native support for desktop and mobile devices. Whether you're on the beach or in the office, your data is always instantly accessible and securely backed up.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- How It Works -->
  <section style="background:#f1f5f9">
    <div class="container">
      <h2 class="section-title">How it works</h2>
      <p class="section-sub">Simple 3‑step workflow designed for speed at the bay and clarity in the office.</p>
      <div class="grid steps">
        <div class="card" style="padding-top: 40px;">
          <div style="position: absolute; top: -10px; right: 10px; font-size: 120px; font-weight: 900; color: rgba(0, 174, 239, 0.05); z-index: 0; pointer-events: none;">1</div>
          <h3 style="position: relative; z-index: 1; font-size: 1.4rem; color: var(--ink); margin-bottom: 10px;">Setup & Sign In</h3>
          <p class="muted" style="position: relative; z-index: 1; line-height: 1.6;">Quickly create your administrative account. Define your core vehicle sizes, wash services, and custom prices.</p>
        </div>
        <div class="card" style="padding-top: 40px;">
          <div style="position: absolute; top: -10px; right: 10px; font-size: 120px; font-weight: 900; color: rgba(0, 174, 239, 0.05); z-index: 0; pointer-events: none;">2</div>
          <h3 style="position: relative; z-index: 1; font-size: 1.4rem; color: var(--ink); margin-bottom: 10px;">Log Daily Washes</h3>
          <p class="muted" style="position: relative; z-index: 1; line-height: 1.6;">Log incoming vehicles instantly via desktop or mobile. Assign workers and services with absolutely no friction.</p>
        </div>
        <div class="card" style="padding-top: 40px;">
          <div style="position: absolute; top: -10px; right: 10px; font-size: 120px; font-weight: 900; color: rgba(0, 174, 239, 0.05); z-index: 0; pointer-events: none;">3</div>
          <h3 style="position: relative; z-index: 1; font-size: 1.4rem; color: var(--ink); margin-bottom: 10px;">Review Results</h3>
          <p class="muted" style="position: relative; z-index: 1; line-height: 1.6;">Close the day. The system automatically calculates your gross totals, worker cuts, and populates insightful trend graphs.</p>
        </div>
      </div>
      <div style="text-align:center;margin-top:18px">
        <a class="btn" href="how_it_works.php">See detailed walkthrough</a>
      </div>
    </div>
  </section>

  <!-- Social Proof -->
  <section>
    <div class="container">
      <h2 class="section-title">Trusted by growing wash businesses</h2>
      <p class="section-sub">Here are a few brands that rely on WashHub.</p>
      <div class="logos" aria-label="Company logos">
        <span class="logo-pill">AutoShine</span>
        <span class="logo-pill">CleanRide</span>
        <span class="logo-pill">SparkleBay</span>
        <span class="logo-pill">WashWorks</span>
      </div>
      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(350px,1fr));margin-top:20px" id="testimonialsGrid">
        <?php if (!empty($testimonials)): ?>
            <?php foreach ($testimonials as $t): ?>
                <div class="card">
                  <div style="display:flex; gap: 4px; color: #F59E0B; margin-bottom: 16px; font-size: 14px;">
                    <?php for($i=0; $i<$t['rating']; $i++) echo '<i class="fas fa-star"></i>'; ?>
                  </div>
                  <h3 style="font-size: 1.4rem; margin-bottom: 12px; font-style: italic; color: var(--text);">"<?php echo htmlspecialchars(substr($t['review_text'], 0, 30)) . '..."'; ?>"</h3>
                  <p class="muted" style="line-height: 1.6; margin-bottom: 20px;">"<?php echo htmlspecialchars($t['review_text']); ?>"</p>
                  <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                        <?php echo strtoupper(substr($t['customer_name'], 0, 1)); ?>
                    </div>
                    <div>
                      <div style="font-weight: 700; color: var(--ink);"><?php echo htmlspecialchars($t['customer_name']); ?></div>
                      <div style="font-size: 12px; color: var(--muted);"><?php echo htmlspecialchars($t['company_name']); ?></div>
                    </div>
                  </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="card">
          <div style="display:flex; gap: 4px; color: #F59E0B; margin-bottom: 16px; font-size: 14px;">
            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
          </div>
          <h3 style="font-size: 1.4rem; margin-bottom: 12px; font-style: italic; color: var(--text);">"Daily control regained."</h3>
          <p class="muted" style="line-height: 1.6; margin-bottom: 20px;">"We finally know our accurate numbers every single day without waiting for the accountant. Staff absolutely love the mobile speed, and our revenue has organically increased just from having better oversight."</p>
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: bold;">JD</div>
            <div>
              <div style="font-weight: 700; color: var(--ink);">James D.</div>
              <div style="font-size: 12px; color: var(--muted);">Owner, SparkleBay Wash</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div style="display:flex; gap: 4px; color: #F59E0B; margin-bottom: 16px; font-size: 14px;">
            <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
          </div>
          <h3 style="font-size: 1.4rem; margin-bottom: 12px; font-style: italic; color: var(--text);">"Incredibly easy setup."</h3>
          <p class="muted" style="line-height: 1.6; margin-bottom: 20px;">"GothTech Consult literally had us set up and running live on the same afternoon. The automated end-of-day worker reports alone are worth their weight in gold. Extremely professional system."</p>
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: bold;">MK</div>
            <div>
              <div style="font-weight: 700; color: var(--ink);">Michael K.</div>
              <div style="font-size: 12px; color: var(--muted);">Branch Manager, FastWash</div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Submit a Review Form -->
      <div style="margin-top: 60px; background: #fff; border: 1px solid var(--border); padding: 40px; border-radius: 20px; box-shadow: var(--shadow); max-width: 700px; margin-inline: auto;">
        <h3 style="font-size: 1.8rem; margin-bottom: 10px; color: var(--ink); text-align: center; font-weight: 800;">Write a Review</h3>
        <p style="text-align: center; color: var(--muted); margin-bottom: 30px;">Are you using WashHub? Let others know what you think!</p>
        
        <form id="reviewForm" style="display: flex; flex-direction: column; gap: 20px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; color: var(--ink);">Your Name *</label>
                    <input type="text" name="customer_name" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 15px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; color: var(--ink);">Company Name (Optional)</label>
                    <input type="text" name="company_name" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 15px;">
                </div>
            </div>
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; color: var(--ink);">Rating *</label>
                <select name="rating" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 15px; background: #fff;">
                    <option value="5">⭐⭐⭐⭐⭐ (5/5)</option>
                    <option value="4">⭐⭐⭐⭐ (4/5)</option>
                    <option value="3">⭐⭐⭐ (3/5)</option>
                    <option value="2">⭐⭐ (2/5)</option>
                    <option value="1">⭐ (1/5)</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; color: var(--ink);">Your Review *</label>
                <textarea name="review_text" required rows="4" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 15px; resize: vertical;"></textarea>
            </div>
            <button type="submit" class="btn" style="width: 100%; padding: 16px; font-size: 16px; justify-content: center; text-align: center;">Submit Feedback</button>
            <div id="reviewMessage" style="display: none; padding: 12px; border-radius: 8px; text-align: center; font-weight: 600; margin-top: 10px;"></div>
        </form>
      </div>
    </div>
  </section>

  <!-- Contact for Deals -->
  <section style="background:#f1f5f9">
    <div class="container">
      <h2 class="section-title">Get Your Custom Deal</h2>
      <p class="section-sub">Contact us on WhatsApp for personalized pricing and package deals.</p>
      <div class="grid" style="grid-template-columns:1fr;max-width:600px;margin:0 auto">
        <div class="card" style="text-align:center; border: 2px solid var(--brand); box-shadow: 0 25px 50px rgba(0,174,239,.12); transform: scale(1.05); z-index: 10;">
          <div style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); background: var(--brand); color: #fff; padding: 4px 16px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; font-weight: 700; font-size: 12px; letter-spacing: 1px;">MOST POPULAR</div>
          <h3 style="font-size: 2rem; margin-top: 10px; color: var(--ink); font-weight: 800;">Enterprise SaaS Package</h3>
          <p style="font-size: 1.5rem; font-weight: 700; color: var(--brand); margin: 15px 0 25px;">Custom Pricing</p>
          <ul style="list-style: none; padding: 0; margin-bottom: 30px; text-align: left; max-width: 300px; margin-inline: auto;">
            <li style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; color: var(--text);"><i class="fas fa-check-circle" style="color: var(--brand);"></i> Fully bespoke branding</li>
            <li style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; color: var(--text);"><i class="fas fa-check-circle" style="color: var(--brand);"></i> Dedicated cloud deployment</li>
            <li style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; color: var(--text);"><i class="fas fa-check-circle" style="color: var(--brand);"></i> Unlimited worker profiles</li>
            <li style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; color: var(--text);"><i class="fas fa-check-circle" style="color: var(--brand);"></i> High-speed native desktop app</li>
            <li style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; color: var(--text);"><i class="fas fa-check-circle" style="color: var(--brand);"></i> 24/7 priority developer support</li>
          </ul>
          <div style="margin-top:20px">
            <a class="btn" href="https://wa.me/<?php echo htmlspecialchars(getenv('SUPPORT_WHATSAPP') ?: ''); ?>?text=Hi!%20I'm%20interested%20in%20WashHub%20for%20my%20car%20wash%20business." target="_blank" rel="noopener" style="background: linear-gradient(135deg, var(--brand), var(--ink)); border: none; font-size:1.15rem; padding:16px 32px; width: 100%;">
              <i class="fab fa-whatsapp" style="font-size: 1.2rem;"></i> Request a tailored quote
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>



  <!-- FAQ -->
  <section style="background:#f1f5f9">
    <div class="container">
      <h2 class="section-title">Frequently asked questions</h2>
      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
        <div>
          <details open>
            <summary>Do I need special hardware?</summary>
            <p class="muted">No. Any modern phone, tablet, or PC works great.</p>
          </details>
          <details>
            <summary>Can I import existing data?</summary>
            <p class="muted">Yes, we'll help you migrate CSV/Excel data.</p>
          </details>
        </div>
        <div>
          <details>
            <summary>How does pricing work?</summary>
            <p class="muted">We offer custom deals based on your specific needs. Contact us on WhatsApp for personalized pricing.</p>
          </details>
          <details>
            <summary>Do you offer support?</summary>
            <p class="muted">Yes. Email and WhatsApp support during business hours.</p>
          </details>
        </div>
      </div>
      <div style="text-align:center;margin-top:18px"></div>
    </div>
  </section>

  <!-- About Us Section -->
  <section id="about" style="background:#fff; padding: 60px 0;">
    <div class="container">
      <h2 class="section-title">About GothTech Consult</h2>
      <p class="section-sub" style="max-width: 700px; margin: 0 auto 30px;">
        The innovators behind the WashHub Platform. We specialize in building robust, modern, and beautiful software solutions tailored to propel businesses into the digital age.
      </p>
      
      <div style="max-width: 900px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: center;">
        <div style="background: var(--bg); border: 1px solid var(--border); padding: 40px; border-radius: 20px; text-align: center; box-shadow: var(--shadow);">
          <div style="width: 80px; height: 80px; background: var(--brand); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <i class="fas fa-building" style="font-size: 32px; color: #fff;"></i>
          </div>
          <h3 style="font-size: 1.5rem; color: var(--ink); margin-bottom: 12px; font-weight: 800;">Our Mission</h3>
          <p style="color: var(--text); line-height: 1.6; font-size: 15px;">
            To empower car wash and automotive detailing businesses with enterprise-grade management software that simplifies operations, tracks real-time revenue, and drastically improves customer service flow.
          </p>
        </div>
        
        <div style="background: var(--bg); border: 1px solid var(--border); padding: 40px; border-radius: 20px; text-align: center; box-shadow: var(--shadow);">
          <div style="width: 80px; height: 80px; background: var(--brand); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <i class="fas fa-satellite-dish" style="font-size: 32px; color: #fff;"></i>
          </div>
          <h3 style="font-size: 1.5rem; color: var(--ink); margin-bottom: 12px; font-weight: 800;">Why WashHub?</h3>
          <p style="color: var(--text); line-height: 1.6; font-size: 15px;">
            Designed meticulously with both Super Admins and Staff in mind, WashHub provides unparalleled data analytics, real-time worker management, and a beautiful UI that makes the daily grind feel effortless.
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- Secondary CTA -->
  <section>
    <div class="container" style="text-align:center">
      <h2 class="section-title">Ready to transform your car wash business?</h2>
      <p class="section-sub">Let's discuss a custom solution that fits your needs perfectly.</p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a class="btn" href="#" onclick="openContactModal(); return false;" style="background: linear-gradient(135deg, var(--brand) 0%, var(--ink) 100%);">
          💬 Contact Us
        </a>
        <a class="btn outline" href="https://wa.me/233509729601?text=Hi!%20I'm%20interested%20in%20WashHub%20for%20my%20car%20wash%20business." target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;">
          <i class="fab fa-whatsapp" style="font-size:1.1rem;"></i> Chat on WhatsApp
        </a>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer>
    <div class="container" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
      <p style="margin:0">&copy; <span id="y"></span> WashHub &mdash; Powered by <a href="#" style="color:#8dd9c9">GothTech Consult</a></p>
      <div style="display:flex;gap:14px">
        <a href="how_it_works.php">About</a>
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
        <a href="https://wa.me/<?php echo htmlspecialchars(getenv('SUPPORT_WHATSAPP') ?: ''); ?>" target="_blank" rel="noopener">Support</a>
      </div>
      <div style="flex-basis:100%;height:0"></div>

    </div>
  </footer>
  <!-- Contact Form Modal -->
  <div id="contactModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:20px;padding:40px;max-width:500px;width:100%;box-shadow:0 25px 50px rgba(0,0,0,0.25);position:relative;max-height:90vh;overflow-y:auto;">
      <button onclick="closeContactModal()" style="position:absolute;top:15px;right:15px;background:none;border:none;font-size:24px;color:var(--muted);cursor:pointer;transition:color 0.2s;">&times;</button>
      <h2 style="font-family:'Poppins',sans-serif;font-size:1.8rem;color:var(--ink);margin:0 0 10px;font-weight:800;text-align:center;">Contact Us</h2>
      <p style="text-align:center;color:var(--muted);margin-bottom:30px;font-size:1rem;">Interested in WashHub? Fill out the form below and we'll get back to you.</p>
      
      <form id="contactForm" style="display:grid;gap:16px;">
        <input type="hidden" name="csrf_token" id="csrf_token">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Name *</label>
            <input type="text" name="name" required placeholder="Your full name" style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;">
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Email *</label>
            <input type="email" name="email" required placeholder="you@company.com" style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Phone *</label>
            <input type="tel" name="phone" required placeholder="050 123 4567" style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;">
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Business (Optional)</label>
            <input type="text" name="business" placeholder="Your car wash name" style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Region *</label>
            <select name="region" required style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;background:#fff;">
              <option value="">Select region</option>
              <option value="Ahafo">Ahafo</option>
              <option value="Ashanti">Ashanti</option>
              <option value="Bono">Bono</option>
              <option value="Bono East">Bono East</option>
              <option value="Central">Central</option>
              <option value="Eastern">Eastern</option>
              <option value="Greater Accra">Greater Accra</option>
              <option value="North East">North East</option>
              <option value="Northern">Northern</option>
              <option value="Oti">Oti</option>
              <option value="Savannah">Savannah</option>
              <option value="Upper East">Upper East</option>
              <option value="Upper West">Upper West</option>
              <option value="Volta">Volta</option>
              <option value="Western">Western</option>
              <option value="Western North">Western North</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Town/City *</label>
            <input type="text" name="town" required placeholder="e.g. Kumasi, Accra, Tamale" style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;">
          </div>
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:6px;color:var(--text);">Message *</label>
          <textarea name="message" required rows="4" placeholder="Tell us about your car wash needs..." style="width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;transition:border 0.2s;resize:vertical;"></textarea>
        </div>
        <button type="submit" style="background:linear-gradient(135deg,var(--brand),var(--ink));color:#fff;border:none;padding:14px 28px;border-radius:8px;font-weight:600;font-size:1rem;cursor:pointer;transition:filter 0.2s;">
          Send Message
        </button>
        <div id="contactMessage" style="display:none;padding:12px;border-radius:8px;text-align:center;font-weight:600;margin-top:10px;"></div>
      </form>
    </div>
  </div>

  <script>
    // Handle Contact Modal
    function openContactModal() {
      document.getElementById('contactModal').style.display = 'flex';
      document.body.style.overflow = 'hidden';
      // Fetch CSRF token when modal opens
      fetch('get_csrf_token.php')
        .then(res => res.json())
        .then(data => {
          if (data.token) {
            document.getElementById('csrf_token').value = data.token;
          }
        });
    }

    function closeContactModal() {
      document.getElementById('contactModal').style.display = 'none';
      document.body.style.overflow = '';
    }

    document.getElementById('contactModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeContactModal();
      }
    });

    // Handle Contact Form Submission
    const contactForm = document.getElementById('contactForm');
    if(contactForm) {
        contactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const msg = document.getElementById('contactMessage');
            const submitBtn = this.querySelector('button[type="submit"]');
            const ogText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Sending...';
            msg.style.display = 'none';

            fetch('submit_contact.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(res => {
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then(data => {
                msg.style.display = 'block';
                if(data.success) {
                    msg.style.background = '#dcfce7';
                    msg.style.color = '#166534';
                    msg.textContent = data.message;
                    contactForm.reset();
                    closeContactModal();
                } else {
                    msg.style.background = '#fee2e2';
                    msg.style.color = '#991b1b';
                    msg.textContent = data.message;
                }
            })
            .catch(err => {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = 'Network error occurred. Please try again.';
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = ogText;
            });
        });
    }
  </script>

  <script>
    // Handle Review Submission
    const reviewForm = document.getElementById('reviewForm');
    if(reviewForm) {
        reviewForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const msg = document.getElementById('reviewMessage');
            const submitBtn = this.querySelector('button[type="submit"]');
            const ogText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Sending...';
            msg.style.display = 'none';

            fetch('submit_review.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(res => res.json())
            .then(data => {
                msg.style.display = 'block';
                if(data.success) {
                    msg.style.background = '#dcfce7';
                    msg.style.color = '#166534';
                    msg.textContent = data.message;
                    reviewForm.reset();
                    setTimeout(() => window.location.reload(), 2000); // Reload to show new review
                } else {
                    msg.style.background = '#fee2e2';
                    msg.style.color = '#991b1b';
                    msg.textContent = data.message;
                }
            })
            .catch(err => {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = 'Network error occurred.';
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = ogText;
            });
        });
    }

    // Set Current Year
    if (document.getElementById('y')) {
      document.getElementById('y').textContent = new Date().getFullYear();
    }
  </script>

  <script>
    const slideshowImages = [
      'assets/short1.png',
      'assets/short2.png',
      'assets/short3.png',
      'assets/short4.png',
      'assets/short5.png'
    ];
    const slideshowCaptions = [
      'Login panel',
      'Quick wash logging',
      'Dashboard overview',
      'Worker performance insights',
      'Monthly reports and trends'
    ];
    let currentImageIndex = 0;
    const slideshowElement = document.getElementById('slideshow');
    const captionElement = document.getElementById('slideshow-caption');

    if (slideshowElement) {
      if (captionElement) {
        captionElement.textContent = slideshowCaptions[0];
      }
      setInterval(() => {
        currentImageIndex = (currentImageIndex + 1) % slideshowImages.length;
        slideshowElement.style.opacity = 0;
        setTimeout(() => {
            slideshowElement.src = slideshowImages[currentImageIndex];
            slideshowElement.style.opacity = 1;
            if (captionElement) {
              captionElement.textContent = slideshowCaptions[currentImageIndex];
            }
        }, 500); // fade transition
      }, 5000); // 5 seconds
    }
  </script>
  <style>
      #slideshow {
          transition: opacity 0.5s ease-in-out;
      }
  </style>
</body>
</html>
