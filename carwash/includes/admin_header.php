<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load common init for helpers like get_user_role(), db, and auth guards
$__init = __DIR__ . '/init.php';
if (file_exists($__init)) {
    require_once $__init;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Safe fallbacks if helpers are not loaded for any reason
if (!function_exists('get_user_role')) {
    function get_user_role() { return $_SESSION['role'] ?? null; }
}
if (!function_exists('is_admin')) {
    function is_admin() { $r = get_user_role(); return $r === 'admin' || $r === 'superadmin'; }
}
if (!function_exists('is_superadmin')) {
    function is_superadmin() { return get_user_role() === 'superadmin'; }
}

// Disable caching globally for admin pages to ensure fresh data on each refresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Set default page title if not provided
if (!isset($page_title)) {
    $page_title = 'WashHub - Admin';
} else {
    $page_title .= ' - WashHub';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../frontend/new logo.png?v=washhub">
    <link rel="shortcut icon" type="image/png" href="../frontend/new logo.png?v=washhub">
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#4e73df">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="WashHub">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="../frontend/new logo.png?v=washhub">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="js/admin.js"></script>
    
    <!-- PWA Script -->
    <script src="js/pwa.js"></script>

    <!-- Role-based auto-refresh: superadmin = 2 minutes, admin = 1 second -->
    <?php $role = $_SESSION['role'] ?? ''; ?>
    <?php if ($role === 'superadmin'): ?>
    <script>
      // Super Admin: refresh every 2 minutes
      setInterval(function() { location.reload(true); }, 120000);
    </script>
    <?php endif; ?>
    
    <!-- Custom styles -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4e73df;
            --primary-dark: #2e59d9;
            --primary-glow: rgba(78,115,223,0.35);
            --secondary: #858796;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fc;
            --dark: #5a5c69;
            --sidebar-bg: linear-gradient(180deg, #0f2756 0%, #1a3a8c 40%, #1e4db7 100%);
            --sidebar-width: 250px;
        }
        
        html, body { height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            font-weight: 500;
            background-color: #f0f4ff;
            color: #2d3748;
        }

        /* Heading sizes */
        .h1, h1 { font-size: 2rem; font-weight: 800 !important; }
        .h2, h2 { font-size: 1.7rem; font-weight: 700 !important; }
        .h3, h3 { font-size: 1.5rem; font-weight: 700 !important; }
        .h4, h4 { font-size: 1.3rem; font-weight: 700 !important; }
        .h5, h5 { font-size: 1.15rem; font-weight: 600 !important; }
        .h6, h6 { font-size: 1rem; font-weight: 600 !important; }

        /* Form & button sizes */
        .form-control, .btn { font-size: 0.92rem !important; font-weight: 600 !important; }
        .dropdown-menu, .dropdown-item { font-size: 0.9rem !important; }
        .table { font-size: 0.9rem !important; }
        .card-header { font-size: 1rem !important; font-weight: 700 !important; }
        .modal-title { font-size: 1.1rem !important; font-weight: 700 !important; }

        /* ── Layout ── */
        #wrapper { min-height: 100vh; display: flex; }
        #content-wrapper { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 100%; margin-left: var(--sidebar-width); transition: margin-left .3s ease; }

        /* ══════════════════════════════════════
           SIDEBAR — premium dark blue gradient
        ══════════════════════════════════════ */
        .sidebar {
            position: fixed;
            top: 0; bottom: 0; left: 0;
            width: var(--sidebar-width);
            z-index: 200;
            background: var(--sidebar-bg);
            box-shadow: 4px 0 24px rgba(15,39,86,0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: width .3s ease;
        }

        /* Brand area */
        .sidebar-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px 18px;
            text-decoration: none;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        .sidebar-brand img { height: 56px; width: auto; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3)); transition: transform .3s; }
        .sidebar-brand:hover img { transform: scale(1.06); }
        .sidebar-brand-text .brand-name { color: #fff; font-size: 1.15rem; font-weight: 800; letter-spacing: .3px; text-align: center; margin-top: 8px; }
        .sidebar-brand-text .brand-sub { color: rgba(255,255,255,0.55); font-size: 0.68rem; text-align: center; letter-spacing: 1.2px; text-transform: uppercase; }

        /* Scroll area */
        .sidebar-sticky {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 10px 0 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.15) transparent;
        }
        .sidebar-sticky::-webkit-scrollbar { width: 4px; }
        .sidebar-sticky::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        /* Section heading */
        .sidebar-heading {
            color: rgba(255,255,255,0.4);
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            padding: 18px 20px 6px;
        }

        /* Divider */
        .sidebar-divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin: 6px 16px;
        }

        /* Nav items */
        .sidebar .nav-item { list-style: none; }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.72);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 0;
            transition: background .2s, color .2s, padding-left .2s;
            position: relative;
        }
        .sidebar .nav-link::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: transparent;
            border-radius: 0 3px 3px 0;
            transition: background .2s;
        }
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 0.95rem;
            color: rgba(255,255,255,0.45);
            transition: color .2s, transform .2s;
            flex-shrink: 0;
        }
        .sidebar .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
            padding-left: 24px;
        }
        .sidebar .nav-link:hover i { color: rgba(255,255,255,0.9); transform: scale(1.1); }
        .sidebar .nav-link:hover::before { background: rgba(255,255,255,0.4); }
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }
        .sidebar .nav-link.active::before { background: #fff; }
        .sidebar .nav-link.active i { color: #fff; }

        /* Sidebar toggler */
        #sidebarToggle {
            display: block;
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50%;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
            margin: 12px auto;
            transition: background .2s;
            font-size: 0.8rem;
        }
        #sidebarToggle:hover { background: rgba(255,255,255,0.22); color: #fff; }

        /* ══════════════════════════════════════
           TOPBAR
        ══════════════════════════════════════ */
        .navbar, .topbar {
            height: 66px;
            background: #fff;
            box-shadow: 0 2px 16px rgba(15,39,86,0.09);
            padding: 0 1.5rem;
            border-bottom: 1px solid rgba(78,115,223,0.08);
        }
        .topbar { display: flex; align-items: center; }
        
        /* Topbar toggle button */
        #sidebarToggleTop {
            width: 40px; height: 40px;
            border: none;
            background: transparent;
            border-radius: 10px;
            color: var(--secondary);
            font-size: 1.1rem;
            cursor: pointer;
            transition: background .2s, color .2s;
            display: flex; align-items: center; justify-content: center;
        }
        #sidebarToggleTop:hover { background: #eef0f8; color: var(--primary); }

        /* Topbar page title */
        .topbar-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e2d5f;
            margin-left: 8px;
        }

        /* Dropdown lists (notifications) */
        .topbar .dropdown-list {
            padding: 0;
            border: none;
            border-radius: 12px;
            overflow: hidden;
            width: 22rem !important;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .topbar .dropdown-list .dropdown-header {
            background: linear-gradient(135deg, var(--primary), #224abe);
            padding: 12px 18px;
            color: #fff;
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.5px;
        }
        .topbar .dropdown-list .dropdown-item {
            white-space: normal;
            padding: 12px 18px;
            border-bottom: 1px solid #f0f2f8;
            transition: background .15s;
        }
        .topbar .dropdown-list .dropdown-item:hover { background: #f5f7ff; }
        .topbar .dropdown-list .dropdown-item .icon-circle {
            height: 2.4rem; width: 2.4rem;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .topbar .dropdown-list .dropdown-item:active { background: #eef0f8; color: #3a3b45; }
        
        .card {
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 1.5rem;
        }
        
        .card .card-header {
            font-weight: 700;
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
        }
        
        .bg-gradient-primary {
            background-color: #4e73df;
            background-image: linear-gradient(180deg, #4e73df 10%, #224abe 100%);
            background-size: cover;
        }
        
        .border-left-primary {
            border-left: 0.25rem solid #4e73df !important;
        }
        
        .border-left-success {
            border-left: 0.25rem solid #1cc88a !important;
        }
        
        .border-left-info {
            border-left: 0.25rem solid #36b9cc !important;
        }
        
        .border-left-warning {
            border-left: 0.25rem solid #f6c23e !important;
        }
        
        .text-primary {
            color: #4e73df !important;
        }
        
        .text-success {
            color: #1cc88a !important;
        }
        
        .text-info {
            color: #36b9cc !important;
        }
        
        .text-warning {
            color: #f6c23e !important;
        }
        
        .text-danger {
            color: #e74a3b !important;
        }
        
        .bg-primary {
            background-color: #4e73df !important;
        }
        
        .bg-success {
            background-color: #1cc88a !important;
        }
        
        .bg-info {
            background-color: #36b9cc !important;
        }
        
        .bg-warning {
            background-color: #f6c23e !important;
        }
        
        .bg-danger {
            background-color: #e74a3b !important;
        }
        
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2653d4;
        }
        
        .btn-success {
            background-color: #1cc88a;
            border-color: #1cc88a;
        }
        
        .btn-success:hover {
            background-color: #17a673;
            border-color: #169b6b;
        }
        
        .btn-info {
            background-color: #36b9cc;
            border-color: #36b9cc;
        }
        
        .btn-info:hover {
            background-color: #2c9faf;
            border-color: #2a96a5;
        }
        
        .btn-warning {
            background-color: #f6c23e;
            border-color: #f6c23e;
            color: #1f2d3d;
        }
        
        .btn-warning:hover {
            background-color: #f4b619;
            border-color: #f4b30d;
            color: #1f2d3d;
        }
        
        .btn-danger {
            background-color: #e74a3b;
            border-color: #e74a3b;
        }
        
        .btn-danger:hover {
            background-color: #e02d1b;
            border-color: #d52a1a;
        }
        
        /* ── User chip in topbar ── */
        .topbar-divider {
            width: 1px;
            height: 2rem;
            background: #e3e6f0;
            margin: 0 0.75rem;
        }
        .user-chip {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 5px 10px;
            border-radius: 50px;
            text-decoration: none;
            background: transparent;
            transition: background .2s;
            cursor: pointer;
            border: none;
        }
        .user-chip:hover { background: #f0f4ff; }
        .user-avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            color: #fff;
            flex-shrink: 0;
            border: 2px solid rgba(78,115,223,0.3);
        }
        .user-name { font-size: 0.85rem; font-weight: 700; color: #2d3748; }
        .user-role-badge {
            display: inline-block;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 20px;
        }
        .role-superadmin { background: linear-gradient(135deg,#f6c23e,#e0a800); color: #1a1a00; }
        .role-admin { background: linear-gradient(135deg,#4e73df,#224abe); color: #fff; }

        /* Bell notification badge */
        .badge-counter {
            position: absolute;
            top: -4px; right: -6px;
            background: var(--danger);
            color: #fff;
            font-size: 0.62rem;
            font-weight: 700;
            padding: 2px 5px;
            border-radius: 20px;
            min-width: 16px;
            text-align: center;
            border: 2px solid #fff;
        }
        .notif-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: 10px;
            background: transparent;
            border: none;
            color: var(--secondary);
            font-size: 1.1rem;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background .2s, color .2s;
        }
        .notif-btn:hover { background: #eef0f8; color: var(--primary); }

        /* Dropdown - User */
        .dropdown-menu-user {
            border-radius: 12px;
            border: 1px solid #e8ecf8;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            min-width: 200px;
            overflow: hidden;
            padding: 6px;
        }
        .dropdown-menu-user .dropdown-item {
            border-radius: 8px;
            padding: 9px 14px;
            font-size: 0.88rem;
            font-weight: 600;
            color: #2d3748;
            display: flex; align-items: center; gap: 10px;
            transition: background .15s;
        }
        .dropdown-menu-user .dropdown-item i { color: var(--secondary); width: 16px; text-align: center; }
        .dropdown-menu-user .dropdown-item:hover { background: #f0f4ff; color: var(--primary); }
        .dropdown-menu-user .dropdown-item:hover i { color: var(--primary); }
        .dropdown-menu-user .dropdown-divider { margin: 4px 0; border-color: #f0f2f8; }
        .dropdown-menu-user .dropdown-item.text-danger { color: #e74a3b; }
        .dropdown-menu-user .dropdown-item.text-danger:hover { background: #fff0f0; }
        .dropdown-menu-user .dropdown-item.text-danger i { color: #e74a3b; }

        /* Responsive */
        @media (max-width: 768px) {
            #content-wrapper { margin-left: 0; }
            .sidebar { transform: translateX(-100%); transition: transform .3s ease; }
            .sidebar.mobile-open { transform: translateX(0); }
            .user-name { display: none; }
        }
    </style>
    <script>
        // Close any open menu accordions when window is resized below 768px
        $(window).resize(function() {
            if ($(window).width() < 768) {
                $('.sidebar .collapse').collapse('hide');
            }
        });
        
        // Prevent the content wrapper from scrolling when the fixed side navigation hovered over
        $('body.fixed-nav .sidebar').on('mousewheel DOMMouseScroll wheel', function(e) {
            if ($(window).width() > 768) {
                var e0 = e.originalEvent,
                    delta = e0.wheelDelta || -e0.detail;
                this.scrollTop += (delta < 0 ? 1 : -1) * 30;
                e.preventDefault();
            }
        });
        
        // Scroll to top button appear
        $(document).on('scroll', function() {
            var scrollDistance = $(this).scrollTop();
            if (scrollDistance > 100) {
                $('.scroll-to-top').fadeIn();
            } else {
                $('.scroll-to-top').fadeOut();
            }
        });
        
        // Smooth scrolling using jQuery easing
        $(document).on('click', 'a.scroll-to-top', function(e) {
            var $anchor = $(this);
            $('html, body').stop().animate({
                scrollTop: ($($anchor.attr('href')).offset().top)
            }, 1000, 'easeInOutExpo');
            e.preventDefault();
        });
    </script>
</head>

<?php
$tenantBlocked = !empty($_SESSION['TENANT_BLOCKED']);
$tenantBlockMsg = $_SESSION['TENANT_BLOCK_MSG'] ?? 'Your subscription is currently inactive.';
?>
<body id="page-top" class="<?php echo $tenantBlocked ? 'tenant-blocked' : ''; ?>">
    <?php if ($tenantBlocked): ?>
        <style>
            body.tenant-blocked #wrapper {
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
                z-index: 20000;
            }
            .tenant-block-card {
                width: min(34vw, 680px);
                min-width: 360px;
                max-width: 92vw;
                background: #fff;
                border: 2px solid #fca5a5;
                border-left: 8px solid #ef4444;
                border-radius: 14px;
                padding: 24px 24px 18px;
                text-align: center;
                box-shadow: 0 16px 40px rgba(2, 6, 23, 0.25);
            }
            .tenant-block-title { color: #991b1b; font-size: 1.3rem; font-weight: 800; margin-bottom: 10px; }
            .tenant-block-text { color: #7f1d1d; font-size: 0.95rem; line-height: 1.5; margin-bottom: 14px; }
            .tenant-block-help { font-size: 0.82rem; color: #9f1239; }
        </style>
        <div class="tenant-block-overlay">
            <div class="tenant-block-card">
                <div class="tenant-block-title">Subscription Renewal Required</div>
                <div class="tenant-block-text"><?php echo htmlspecialchars($tenantBlockMsg); ?></div>
                <div class="tenant-block-help">Renew your subscription to regain portal access. Call 0509729601 or 0549195399.</div>
            </div>
        </div>
    <?php endif; ?>
    <!-- Page Wrapper -->
    <div id="wrapper">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebarNav">
            <!-- Brand -->
            <a class="sidebar-brand" href="dashboard.php">
                <img src="../frontend/new logo.png" alt="WashHub">
                <div class="sidebar-brand-text">
                    <div class="brand-name">WashHub</div>
                    <div class="brand-sub">Management System</div>
                </div>
            </a>

            <!-- Scrollable nav -->
            <div class="sidebar-sticky">
                <ul style="list-style:none;padding:0;margin:0;">

                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                        </a>
                    </li>

                    <hr class="sidebar-divider">
                    <div class="sidebar-heading">Operations</div>

                    <!-- Car Washes -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'washes.php' ? 'active' : ''; ?>" href="washes.php">
                            <i class="fas fa-car-side"></i><span>Car Washes</span>
                        </a>
                    </li>

                    <!-- Reports -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['reports.php','reports_admin.php','reports_super.php','monthly_report.php','daily_reports_archive.php']) ? 'active' : ''; ?>" href="reports.php">
                            <i class="fas fa-chart-bar"></i><span>Reports</span>
                        </a>
                    </li>

                    <?php if (get_user_role() === 'admin' || get_user_role() === 'superadmin'): ?>
                    <!-- Workers -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['workers.php','manage_workers.php','worker_payments.php']) ? 'active' : ''; ?>" href="workers.php">
                            <i class="fas fa-users"></i><span>Workers</span>
                        </a>
                    </li>

                    <!-- Customers -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'customers.php' ? 'active' : ''; ?>" href="customers.php">
                            <i class="fas fa-user-friends"></i><span>Customers</span>
                        </a>
                    </li>

                    <!-- Fuel -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['fuel_report.php','fuel_purchase.php','fuel_history.php']) ? 'active' : ''; ?>" href="fuel_report.php">
                            <i class="fas fa-gas-pump"></i><span>Fuel</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (get_user_role() === 'superadmin'): ?>
                    <hr class="sidebar-divider">
                    <div class="sidebar-heading">Administration</div>

                    <!-- Services -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_services.php' ? 'active' : ''; ?>" href="manage_services.php">
                            <i class="fas fa-cog"></i><span>Services</span>
                        </a>
                    </li>
                    
                    <!-- Pricing -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_prices.php' ? 'active' : ''; ?>" href="manage_prices.php">
                            <i class="fas fa-money-bill-wave"></i><span>Pricing</span>
                        </a>
                    </li>

                    <!-- Employees -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['employees.php','add_employee.php']) ? 'active' : ''; ?>" href="employees.php">
                            <i class="fas fa-id-badge"></i><span>Employees</span>
                        </a>
                    </li>

                    <!-- Supplies -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['supplies_request.php','supplies_requests.php','supplies_receive.php']) ? 'active' : ''; ?>" href="supplies_requests.php">
                            <i class="fas fa-boxes"></i><span>Supplies</span>
                        </a>
                    </li>

                    <!-- Users -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : ''; ?>" href="users.php">
                            <i class="fas fa-user-shield"></i><span>Users</span>
                        </a>
                    </li>

                    <!-- Complaints -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'manage_complaints.php' ? 'active' : ''; ?>" href="manage_complaints.php">
                            <i class="fas fa-comment-alt"></i><span>Complaints</span>
                        </a>
                    </li>
                    <?php endif; ?>

                </ul>
            </div>

            <!-- Collapse toggle -->
            <div style="text-align:center;padding:8px 0 16px;border-top:1px solid rgba(255,255,255,0.08);">
                <button id="sidebarToggle" title="Collapse sidebar"><i class="fas fa-chevron-left"></i></button>
            </div>
        </nav>
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar topbar mb-4 static-top" style="display:flex;align-items:center;justify-content:space-between;">
                    <!-- Left: hamburger + page title -->
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button id="sidebarToggleTop" onclick="toggleSidebar()" title="Toggle Sidebar">
                            <i class="fas fa-bars"></i>
                        </button>
                        <span class="topbar-title"><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></span>
                    </div>

                    <!-- Right: bell + divider + user chip -->
                    <div style="display:flex;align-items:center;gap:4px;">

                        <!-- Bell -->
                        <div class="nav-item dropdown no-arrow" style="list-style:none;">
                            <button class="notif-btn" id="alertsDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                                <i class="fas fa-bell"></i>
                                <span class="badge-counter">!</span>
                            </button>
                            <div class="dropdown-list dropdown-menu dropdown-menu-right" aria-labelledby="alertsDropdown">
                                <div class="dropdown-header"><i class="fas fa-bell me-2"></i> Notifications</div>
                                <a class="dropdown-item d-flex align-items-center gap-3" href="reports.php">
                                    <div class="icon-circle bg-primary"><i class="fas fa-chart-bar text-white"></i></div>
                                    <div><div class="fw-bold" style="font-size:.85rem;">Check latest reports</div><div class="text-muted" style="font-size:.75rem;">View daily &amp; monthly summaries</div></div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center gap-3" href="washes.php">
                                    <div class="icon-circle bg-success"><i class="fas fa-car-side text-white"></i></div>
                                    <div><div class="fw-bold" style="font-size:.85rem;">Active car washes</div><div class="text-muted" style="font-size:.75rem;">Track today's wash jobs</div></div>
                                </a>
                                <a class="dropdown-item text-center" style="font-size:.8rem;color:#858796;padding:10px;" href="dashboard.php">Go to Dashboard &rarr;</a>
                            </div>
                        </div>

                        <div class="topbar-divider"></div>

                        <!-- User Chip -->
                        <?php
                        $initials = '';
                        if (isset($_SESSION['full_name'])) {
                            $names = explode(' ', $_SESSION['full_name']);
                            $initials = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                        } else {
                            $initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 2));
                        }
                        $role = $_SESSION['role'] ?? 'admin';
                        $avatarBg = $role === 'superadmin' ? 'linear-gradient(135deg,#f6c23e,#e0a800)' : 'linear-gradient(135deg,#4e73df,#224abe)';
                        $avatarColor = $role === 'superadmin' ? '#1a1a00' : '#fff';
                        ?>
                        <div class="nav-item dropdown no-arrow" style="list-style:none;">
                            <button class="user-chip" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="user-avatar" style="background:<?php echo $avatarBg; ?>;color:<?php echo $avatarColor; ?>;"><?php echo $initials; ?></div>
                                <div style="display:flex;flex-direction:column;align-items:flex-start;gap:1px;">
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?></span>
                                    <span class="user-role-badge <?php echo $role === 'superadmin' ? 'role-superadmin' : 'role-admin'; ?>"><?php echo $role === 'superadmin' ? '★ Super Admin' : 'Admin'; ?></span>
                                </div>
                            </button>
                            <!-- Dropdown - User -->
                            <div class="dropdown-menu dropdown-menu-right dropdown-menu-user" aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                                <a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </nav>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">
