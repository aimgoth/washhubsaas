<?php
// Ensure session and send no-cache headers before any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $appName = getenv('APP_NAME') ?: 'WashHub';
    $appUrl  = getenv('APP_URL')  ?: '';
    ?>
    <title><?php echo htmlspecialchars($appName); ?></title>
    <link rel="icon" type="image/png" href="../frontend/new logo.png?v=washhub_v2">
    <link rel="shortcut icon" type="image/x-icon" href="../frontend/new logo.png?v=washhub_v2">
    <link rel="apple-touch-icon" href="../frontend/new logo.png?v=washhub_v2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- SEO -->
    <meta name="robots" content="index, follow">
    <meta name="description" content="<?php echo htmlspecialchars($appName); ?> — car wash management system. Track washes, workers, and daily reports.">
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($appName); ?>">
    <meta property="og:description" content="Car wash management made simple. Track washes, workers, and daily reports.">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($appName); ?>">

    <style>
        :root {
            --primary-color: #00AEEF;       /* Cyan from WashHub logo "Hub" text */
            --primary-dark:  #0090C8;       /* Darker cyan for hover */
            --primary-light: #E0F7FF;       /* Soft cyan tint for backgrounds */
            --secondary-color: #1B3FA0;     /* Navy from WashHub logo "Wash" text */
            --accent-color:  #1EC8E8;       /* Bright turquoise accent from water drop */
            --light-gray: #EEF5FB;
            --dark-gray: #4A6080;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        html, body {
            height: 100%;
        }

        body {
            /* Match index.php background */
            background-color: #C8EAF9;
            color: #333;
            line-height: 1.6;
            /* Sticky footer layout */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header Styles */
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: static;
            /* Fixed height so the logo can overflow without stretching the navbar */
            height: 76px;
            overflow: visible;
        }
        /* Header-specific container spacing to push logo left and nav right */
        header .container {
            max-width: 1320px;
            padding: 0 12px;
            height: 100%;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
            flex-wrap: nowrap;
            gap: 16px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--secondary-color);
            font-size: 1.4rem;
            font-weight: 800; /* Slightly bolder for the logo */
            line-height: 1.1;
            white-space: nowrap;
            letter-spacing: 0.5px; /* Slightly more spacing for better readability */
        }
        
        .logo i {
            color: var(--primary-color);
            margin-right: 10px;
        }
        
        nav {
            flex: 1 1 auto;
            display: flex;
            justify-content: flex-end; /* push all nav content to the right */
            min-width: 0; /* allow child to shrink */
        }

        nav ul {
            display: flex;
            list-style: none;
            align-items: center;
            flex-wrap: nowrap;
            gap: 14px; /* consistent spacing between items */
            white-space: nowrap;
            min-width: 0;
        }

        .nav-left {
            flex: 0 0 auto; /* don't fill space; sit right */
            justify-content: flex-end;
        }
        
        /* Mobile styles */
        .nav-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        body.nav-open .nav-overlay {
            display: block;
            opacity: 1;
        }
        
        @media (max-width: 992px) {
            .hamburger {
                display: flex;
                align-items: center;
                justify-content: center;
                background: none;
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                width: 44px;
                height: 44px;
                font-size: 1.5rem;
                cursor: pointer;
                margin-left: 10px;
                padding: 0;
                color: #333;
                transition: all 0.2s ease;
                position: relative;
                z-index: 1001;
            }
            
            .hamburger:hover {
                background: #f5f5f5;
            }
            
            nav {
                position: fixed;
                top: 0;
                right: -320px;
                width: 300px;
                height: 100%;
                background: white;
                box-shadow: -2px 0 15px rgba(0,0,0,0.1);
                transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                z-index: 1000;
                padding: 80px 20px 30px;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                display: flex;
                flex-direction: column;
            }
            
            body.nav-open nav {
                right: 0;
            }
            
            .nav-left, .nav-right {
                flex-direction: column;
                width: 100%;
                gap: 8px;
            }
            
            .nav-right {
                margin: 20px 0 0;
                padding: 20px 0 0;
                border-top: 1px solid #f0f0f0;
            }
            
            nav ul {
                width: 100%;
                padding: 0;
                margin: 0;
                flex-direction: column;
            }
            
            nav ul li {
                margin: 4px 0;
                width: 100%;
                list-style: none;
            }
            
            nav ul li a {
                display: flex;
                align-items: center;
                padding: 12px 16px;
                border-radius: 8px;
                color: #333;
                text-decoration: none;
                transition: all 0.2s ease;
                background: #fff;
            }
            
            nav ul li a:hover,
            nav ul li a:focus {
                background: #f8f9fa;
                color: var(--primary-color);
            }
            
            nav ul li a i {
                margin-right: 10px;
                width: 20px;
                text-align: center;
            }
        }

        .nav-right {
            flex: 0 0 auto;
            margin-left: 18px; /* gap between link group and user cluster */
        }
        nav ul li {
            margin-left: 0; /* use gap for spacing */
        }
        
        nav ul li a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 700;
            padding: 9px 14px;
            border-radius: 9999px;
            transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            font-size: 0.97rem;
            letter-spacing: 0.2px;
        }
        
        nav ul li a i {
            font-size: 0.9rem;
            opacity: 0.85;
        }
        
        nav ul li a:hover {
            background-color: rgba(0, 174, 239, 0.1);
            color: var(--secondary-color);
            box-shadow: 0 2px 8px rgba(0,174,239,0.08);
        }

        nav ul li a.active {
            background: linear-gradient(135deg, var(--primary-color), #0090C8);
            color: #fff;
            border-radius: 9999px;
            box-shadow: 0 3px 10px rgba(0,174,239,0.32);
        }
        nav ul li a.active i { opacity: 1; }
        
        /* Hamburger button */
        .hamburger {
            display: none; /* Hide by default */
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            z-index: 1001;
            position: relative;
            align-items: center;
            justify-content: center;
            height: 40px;
            width: 42px;
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.08);
            background: #fff;
            cursor: pointer;
            margin-left: 8px;
        }
        
        .hamburger i { 
            color: #2C3E50; 
            font-size: 18px; 
            line-height: 1; 
        }
        
        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            font-weight: 700 !important; /* Make button text bolder */
            padding: 10px 16px; /* Slightly increased padding for better touch targets */
            border-radius: 9999px; /* fully rounded */
            text-decoration: none;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            line-height: 1.2;
        }
        
        .btn:hover {
            background-color: #16A085;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            border-radius: 9999px; /* fully rounded */
            padding: 8px 14px; /* match .btn sizing */
            line-height: 1.2;
            font-size: 0.95rem;
        }
        
        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }
        /* Small button variant for compact header actions */
        .btn-sm {
            padding: 8px 14px;  /* same as .btn */
            font-size: 0.95rem;  /* same as .btn */
            border-radius: 9999px;
            line-height: 1.2;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #D4EDDA;
            color: #155724;
            border: 1px solid #C3E6CB;
        }
        
        .alert-error {
            background-color: #F8D7DA;
            color: #721C24;
            border: 1px solid #F5C6CB;
        }
        
        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 6px;
        }

        /* Truncate long user names inside the profile link */
        .user-name {
            display: inline-block;
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }
        
        .role-superadmin {
            background: linear-gradient(135deg, #f6c23e, #e0a800);
            color: #1a1400;
        }
        
        .role-admin {
            background: linear-gradient(135deg, #00AEEF, #0090C8);
            color: white;
        }
        
        /* Mobile / Tablet navigation behavior: inline dropdown */
        @media (max-width: 992px) {
            .hamburger {
                display: flex; /* Show hamburger on mobile */
            }
            
            header {
                position: relative;
                padding: 10px;
                gap: 10px;
            }
            
            .brand {
                margin-bottom: 10px;
            }
            
            nav {
                width: 300px;
                position: fixed;
                top: 0;
                right: -320px;
                background: white;
                z-index: 1000;
                height: 100%;
                overflow-y: auto;
                transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: -2px 0 10px rgba(0,0,0,0.1);
                padding: 80px 20px 30px;
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
            }

            body.nav-open nav {
                right: 0;
            }

            nav ul {
                flex-direction: column;
                padding: 0;
                gap: 8px;
                margin-bottom: 0;
            }

            nav ul.nav-right {
                margin-top: 20px;
                border-top: 1px solid #e0e0e0;
                padding-top: 20px;
            }
            
            nav ul li {
                width: 100%;
            }
            
            nav ul li a {
                display: block;
                padding: 15px;
                border-radius: 8px;
                width: 100%;
                text-align: left;
            }
            
            .nav-right { 
                margin-left: 0; 
                width: 100%;
                padding: 10px 0;
            }
            
            /* Ensure hamburger is visible and properly positioned */
            .hamburger {
                display: flex !important;
                position: relative !important;
                top: auto !important;
                right: auto !important;
                z-index: 1001 !important;
                background: #fff !important;
                border: 1px solid #e0e0e0 !important;
                box-shadow: none !important;
            }
            
            /* Add overlay for mobile nav */
            .nav-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            
            body.nav-open .nav-overlay {
                display: block;
                opacity: 1;
            }
        }
        @media (min-width: 993px) {
            /* Hide hamburger on larger screens */
            .hamburger { display: none; }
            
            /* Hide overlay on desktop */
            .nav-overlay { display: none !important; }
        }
        /* Ensure main grows to push footer down */
        main.container {
            flex: 1 0 auto;
            width: 100%;
        }

        /* --- Premium UI Modal Styles --- */
        #wh-modal-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            z-index: 10000; display: none; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s ease;
        }
        #wh-modal-overlay.active { display: flex; opacity: 1; }
        
        .wh-modal-card {
            background: #fff; width: min(450px, 90vw); border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transform: scale(0.9); transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden; border: 1px solid rgba(255,255,255,0.2);
        }
        #wh-modal-overlay.active .wh-modal-card { transform: scale(1); }
        
        .wh-modal-header {
            padding: 24px 24px 0; text-align: center;
        }
        .wh-modal-icon {
            width: 64px; height: 64px; background: #e0f2fe; color: #00AEEF;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; margin: 0 auto 16px;
        }
        .wh-modal-title { font-size: 1.25rem; font-weight: 800; color: #1e293b; margin-bottom: 8px; }
        .wh-modal-body { padding: 0 24px 24px; color: #64748b; font-size: 0.95rem; line-height: 1.6; text-align: center; }
        
        .wh-modal-footer {
            padding: 16px 24px 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
            background: #f8fafc;
        }
        .wh-modal-btn {
            padding: 12px; border-radius: 12px; font-weight: 700; font-size: 0.95rem;
            cursor: pointer; transition: all 0.2s; border: none; text-align: center;
        }
        .wh-btn-cancel { background: #fff; color: #64748b; border: 1px solid #e2e8f0; }
        .wh-btn-cancel:hover { background: #f1f5f9; color: #1e293b; }
        .wh-btn-confirm { background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff; box-shadow: 0 4px 12px rgba(0, 174, 239, 0.3); }
        .wh-btn-confirm:hover { filter: brightness(1.1); transform: translateY(-1px); }
        
        /* Variants */
        .wh-modal-card.success .wh-modal-icon { background: var(--primary-light); color: var(--primary-color); }
        .wh-modal-card.success .wh-btn-confirm { background: var(--primary-color); box-shadow: 0 4px 12px rgba(0, 174, 239, 0.2); }
        .wh-modal-card.success .wh-btn-confirm:hover { background: var(--primary-dark); }
    </style>

</head>
<body>
    <?php
    // Start the session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    // Show Add Task except on the Add Task page itself
    $hideAddTaskBtn = ($currentPage === 'tasks_today.php');
    $tenantBlocked = !empty($_SESSION['TENANT_BLOCKED']);
    $tenantBlockMsg = $_SESSION['TENANT_BLOCK_MSG'] ?? 'Your subscription is currently inactive.';
    ?>
    <?php if ($tenantBlocked): ?>
        <style>
            body.tenant-blocked header,
            body.tenant-blocked main {
                pointer-events: none;
                user-select: none;
                filter: blur(1px);
            }
            .tenant-block-overlay {
                position: fixed;
                inset: 0;
                background: rgba(3, 8, 20, 0.55);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            }
            .tenant-block-card {
                width: min(34vw, 680px);
                min-width: 360px;
                max-width: 92vw;
                background: #ffffff;
                border: 2px solid #fca5a5;
                border-left: 8px solid #ef4444;
                border-radius: 14px;
                padding: 24px 24px 18px;
                text-align: center;
                box-shadow: 0 16px 40px rgba(2, 6, 23, 0.25);
            }
            .tenant-block-title {
                color: #991b1b;
                font-size: 1.3rem;
                font-weight: 800;
                margin-bottom: 10px;
            }
            .tenant-block-text {
                color: #7f1d1d;
                font-size: 0.95rem;
                line-height: 1.5;
                margin-bottom: 14px;
            }
            .tenant-block-help {
                font-size: 0.82rem;
                color: #9f1239;
            }
        </style>
        <script>document.addEventListener('DOMContentLoaded', function(){ document.body.classList.add('tenant-blocked'); });</script>
        <div class="tenant-block-overlay">
            <div class="tenant-block-card">
                <div class="tenant-block-title">Subscription Renewal Required</div>
                <div class="tenant-block-text"><?php echo htmlspecialchars($tenantBlockMsg); ?></div>
                <div class="tenant-block-help">Renew your subscription to regain portal access. Call 0509729601 or 0549195399.</div>
            </div>
        </div>
    <?php endif; ?>
    <header>
        <div class="container">
            <div class="header-container">
                <a href="index.php" class="logo" style="display: flex; align-items: center;">
                    <img src="../frontend/new logo.png" alt="<?php echo htmlspecialchars(getenv('APP_NAME') ?: 'WashHub'); ?>" style="height:115px;width:auto;max-width:280px;object-fit:contain;margin-right:12px;display:block;filter:drop-shadow(0 2px 6px rgba(0,0,0,0.12));position:relative;z-index:1;" />
                </a>
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['role'] === 'admin') && !$hideAddTaskBtn): ?>
                    <a href="tasks_today.php" class="btn btn-sm" style="margin-left:20px; white-space:nowrap;">
                        <i class="fas fa-plus"></i>
                        <span>Add Task</span>
                    </a>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['superadmin', 'admin'])): ?>
                    <button class="hamburger" id="hamburgerBtn" aria-label="Open menu" aria-controls="primaryNav" aria-expanded="false">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <nav id="primaryNav" role="navigation" aria-label="Primary navigation">
                        <ul class="nav-left">
                            <!-- Dashboard link removed as per user request -->
                            <li><a href="washes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'washes.php' || basename($_SERVER['PHP_SELF']) == 'add_wash.php' ? 'active' : ''; ?>">
                                <i class="fas fa-car-side"></i>
                                Washes
                            </a></li>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <li><a href="customers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>">
                                <i class="fas fa-address-book"></i>
                                Customers
                            </a></li>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                            <li><a href="employees.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Employees</a></li>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                            <?php 
                                $reportsTarget = 'reports_super.php';
                                $isReportsActive = in_array(basename($_SERVER['PHP_SELF']), ['reports.php','reports_super.php']);
                            ?>
                            <li><a href="<?php echo $reportsTarget; ?>" class="<?php echo $isReportsActive ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Reports</a></li>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                            <li><a href="renewal.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'renewal.php' ? 'active' : ''; ?>"><i class="fas fa-money-check-dollar"></i> Renewal</a></li>
                            <?php endif; ?>

                            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                                <li>
                                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                                        <i class="fas fa-users"></i>
                                        <span>Users</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <ul class="nav-right">
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <li>
                                <a href="daily_report_preview.php" class="btn <?php echo basename($_SERVER['PHP_SELF']) == 'daily_report_preview.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-file-export"></i>
                                    <span>Submit Report</span>
                                </a>
                            </li>
                            <?php endif; ?>
                            <li>
                                <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                                    <i class="fas fa-user"></i>
                                    <?php
                                      $displayName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? '');
                                      $parts = preg_split('/\s+/', trim((string)$displayName));
                                      $firstName = $parts && count($parts) ? $parts[0] : $displayName;
                                    ?>
                                    <span class="user-name"><?php echo htmlspecialchars($firstName); ?></span>
                                    <span class="role-badge role-<?php echo $_SESSION['role']; ?>">
                                        <?php echo ucfirst($_SESSION['role']); ?>
                                    </span>
                                </a>
                            </li>
                            <li>
                                <a href="logout.php">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <span>Logout</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <div class="nav-overlay"></div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const hamburger = document.getElementById('hamburgerBtn');
        const nav = document.getElementById('primaryNav');
        const overlay = document.querySelector('.nav-overlay');
        const body = document.body;
        
        if (!hamburger || !nav) return;
        
        function toggleMenu() {
            const isOpen = body.classList.toggle('nav-open');
            hamburger.setAttribute('aria-expanded', isOpen);
            hamburger.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
            
            // Change icon based on menu state
            const icon = hamburger.querySelector('i');
            if (icon) {
                icon.className = isOpen ? 'fas fa-times' : 'fas fa-bars';
            }
        }
        
        function closeMenu() {
            body.classList.remove('nav-open');
            hamburger.setAttribute('aria-expanded', 'false');
            hamburger.setAttribute('aria-label', 'Open menu');
            
            // Reset icon
            const icon = hamburger.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-bars';
            }
        }
        
        // Toggle menu when clicking hamburger
        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMenu();
        });
        
        // Close when clicking overlay
        overlay.addEventListener('click', closeMenu);
        
        // Close when clicking outside the menu
        document.addEventListener('click', function(e) {
            if (body.classList.contains('nav-open') && 
                !nav.contains(e.target) && 
                e.target !== hamburger) {
                closeMenu();
            }
        });
        
        // Close when pressing Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('nav-open')) {
                closeMenu();
            }
        });
        
        // Close menu when resizing to desktop
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth > 992 && body.classList.contains('nav-open')) {
                    closeMenu();
                }
            }, 250);
        });
        
        // Add smooth transitions for menu items
        const menuItems = nav.querySelectorAll('a');
        menuItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    closeMenu();
                }
            });
        });
      });

      // --- WashHub Global Confirm UI ---
      let whConfirmCallback = null;
      function WashHubConfirm(options) {
          const overlay = document.getElementById('wh-modal-overlay');
          const card = overlay.querySelector('.wh-modal-card');
          const titleEl = overlay.querySelector('.wh-modal-title');
          const bodyEl = overlay.querySelector('.wh-modal-body');
          const confirmBtn = overlay.querySelector('.wh-btn-confirm');
          const icon = overlay.querySelector('.wh-modal-icon i');
          
          titleEl.innerText = options.title || 'Are you sure?';
          bodyEl.innerText = options.message || '';
          confirmBtn.innerText = options.confirmText || 'Confirm';
          
          // Style variant
          card.className = 'wh-modal-card ' + (options.type || 'danger');
          if (options.type === 'success') {
              icon.className = 'fas fa-check-circle';
          } else {
              icon.className = 'fas fa-exclamation-triangle';
          }
          
          whConfirmCallback = options.onConfirm;
          overlay.classList.add('active');
      }

      function closeWHModal() {
          document.getElementById('wh-modal-overlay').classList.remove('active');
          whConfirmCallback = null;
      }

      function handleWHConfirm() {
          if (whConfirmCallback) whConfirmCallback();
          closeWHModal();
      }

      // --- Global Auto-Interceptor for data-confirm ---
      document.addEventListener('submit', function(e) {
          const form = e.target;
          if (form.dataset.confirm && !form.dataset.confirmed) {
              e.preventDefault();
              WashHubConfirm({
                  title: form.dataset.confirmTitle || 'Are you sure?',
                  message: form.dataset.confirm,
                  type: form.dataset.confirmType || 'danger',
                  confirmText: form.dataset.confirmBtn || 'Confirm',
                  onConfirm: () => {
                      form.dataset.confirmed = 'true';
                      form.submit();
                  }
              });
          }
      });

      document.addEventListener('click', function(e) {
          const link = e.target.closest('a');
          if (link && link.dataset.confirm && !link.dataset.confirmed) {
              e.preventDefault();
              WashHubConfirm({
                  title: link.dataset.confirmTitle || 'Are you sure?',
                  message: link.dataset.confirm,
                  type: link.dataset.confirmType || 'danger',
                  confirmText: link.dataset.confirmBtn || 'Confirm',
                  onConfirm: () => {
                      link.dataset.confirmed = 'true';
                      link.click();
                  }
              });
          }
      });
    </script>
    
    <!-- Global Modal Structure -->
    <div id="wh-modal-overlay">
        <div class="wh-modal-card">
            <div class="wh-modal-header">
                <div class="wh-modal-icon"><i></i></div>
                <div class="wh-modal-title">Confirm Action</div>
            </div>
            <div class="wh-modal-body">Are you sure you want to proceed?</div>
            <div class="wh-modal-footer">
                <button type="button" class="wh-modal-btn wh-btn-cancel" onclick="closeWHModal()">Cancel</button>
                <button type="button" class="wh-modal-btn wh-btn-confirm" onclick="handleWHConfirm()">Confirm</button>
            </div>
        </div>
    </div>

    
    <main class="container">