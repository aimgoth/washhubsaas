<?php
// Starts session, connects DB, authenticates, and redirects on success
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── SaaS Multi-Tenant Routing ──────────────────────────────────────────────
if (!empty($_GET['bay'])) {
    // If a bay link is passed (e.g. login.php?bay=washhub_client_xxx)
    // we instruct the database layer to route to this tenant.
    $_SESSION['DB_NAME_OVERRIDE'] = trim($_GET['bay']);
    $_SESSION['ACTIVE_TENANT_DB'] = trim($_GET['bay']);
    
    // Clear out any old session logic just in case
    unset($_SESSION['user_id']);
    unset($_SESSION['role']);
}
// If login page is opened directly (no bay in URL), always reset to workspace selector.
elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['DB_NAME_OVERRIDE']);
}

$error = '';
$login_type = '';
$current_bay = '';

define('ALLOW_DB_FAIL', true);
require_once __DIR__ . '/config/database.php'; // provides $conn

$active_db = '';
$master_db = '';
$is_master_db = false;
$current_bay = trim($_GET['bay'] ?? ($_POST['bay'] ?? ($_SESSION['DB_NAME_OVERRIDE'] ?? '')));

// Ensure tenant routing survives POST submits and non-sticky sessions.
if ($current_bay !== '') {
    $_SESSION['DB_NAME_OVERRIDE'] = $current_bay;
    $_SESSION['ACTIVE_TENANT_DB'] = $current_bay;
}

if (!$conn) {
    // Keep user on this page and show a friendly inline warning.
    $is_master_db = true;
    if ($current_bay !== '') {
        $error = 'Unable to connect to this facility. Please confirm the facility code and try again.';
    } else {
        $error = 'Please enter your facility code to continue.';
    }
}

// Resolve current DB context only (blocking is enforced inside the portal after login).
if ($conn && !$conn->connect_error) {
    $master_db = getenv('DB_NAME') ?: 'carwash_db';
    $active_db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $is_master_db = ($active_db === $master_db);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Keep bay routing during login submit.
    $posted_bay = trim($_POST['bay'] ?? '');
    if ($posted_bay !== '') {
        $_SESSION['DB_NAME_OVERRIDE'] = $posted_bay;
        $_SESSION['ACTIVE_TENANT_DB'] = $posted_bay;
        $current_bay = $posted_bay;
    }

    $login_type = isset($_POST['login_type']) ? trim($_POST['login_type']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($username === '' || $password === '' || ($login_type !== 'superadmin' && $login_type !== 'admin')) {
        $error = 'Please provide username, password and select a valid login type.';
    } else {
        if (!$conn) {
            $error = 'Facility not found or database unavailable. Please re-enter the correct facility code.';
        } elseif ($is_master_db) {
            $error = "Please connect your workspace first.";
        } else {
            if ($stmt = $conn->prepare('SELECT id, full_name, username, password, role FROM users WHERE username = ? LIMIT 1')) {
                $stmt->bind_param('s', $username);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $hash = $row['password'];
                        $is_legacy_plain = (strpos($hash, '$2y$') !== 0 && strpos($hash, '$argon2') !== 0);
                        $valid_password = password_verify($password, $hash)
                            || ($is_legacy_plain && hash_equals($hash, $password));

                        if ($valid_password) {
                            // Upgrade legacy plaintext passwords immediately after successful login.
                            if ($is_legacy_plain) {
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                if ($upd = $conn->prepare('UPDATE users SET password = ? WHERE id = ?')) {
                                    $uid = (int)$row['id'];
                                    $upd->bind_param('si', $newHash, $uid);
                                    $upd->execute();
                                    $upd->close();
                                }
                            }

                            // Role check based on selected tab
                            $role = $row['role'];
                            $ok = false;
                            if ($login_type === 'superadmin' && $role === 'superadmin') {
                                $ok = true;
                            } elseif ($login_type === 'admin' && $role === 'admin') {
                                $ok = true;
                            }

                            if ($ok) {
                                // Set session and redirect
                                $_SESSION['user_id'] = (int)$row['id'];
                                $_SESSION['username'] = $row['username'];
                                $_SESSION['full_name'] = $row['full_name'];
                                $_SESSION['role'] = $role;
                                $_SESSION['logged_in'] = true;
                                $_SESSION['login_time'] = time();
                                $_SESSION['ACTIVE_TENANT_DB'] = $active_db;

                                header('Location: dashboard.php');
                                exit;
                            } else {
                                $error = 'You do not have permission to log in with this role.';
                            }
                        } else {
                            $error = 'Invalid username or password.';
                        }
                    } else {
                        $error = 'Invalid username or password.';
                    }
                } else {
                    $error = 'Login failed. Please try again.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare login statement.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(getenv('APP_NAME') ?: 'WashHub'); ?> — Login</title>
    <link rel="icon" type="image/png" href="../frontend/new logo.png?v=washhub">
    <link rel="shortcut icon" type="image/png" href="../frontend/new logo.png?v=washhub">
    <link rel="apple-touch-icon" href="../frontend/new logo.png?v=washhub">
    <!-- Font Awesome -->
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
        
        .brand-logo-img { width: 280px; height: auto; margin-bottom: 24px; animation: floatLogo 4s ease-in-out infinite; }
        
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

        /* Tabs */
        .login-tabs { display: flex; gap: 8px; margin-bottom: 24px; background: rgba(0,0,0,0.2); padding: 4px; border-radius: 12px; }
        .tab-btn {
            flex: 1; padding: 10px; border: none; background: transparent; border-radius: 8px;
            color: #64748B; font-weight: 600; cursor: pointer; transition: 0.3s;
        }
        .tab-btn.active { background: rgba(255,255,255,0.05); color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        
        .login-form { display: none; }
        .login-form.active { display: block; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #94A3B8; }
        .form-input {
            width: 100%; padding: 14px 16px; border-radius: 12px;
            background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.08);
            color: #fff; font-size: 15px; transition: 0.3s;
        }
        .form-input:focus { outline: none; border-color: var(--brand); background: rgba(0,0,0,0.4); }
        
        .alert { background: rgba(239, 68, 68, 0.1); border-left: 4px solid #EF4444; color: #FCA5A5; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }

        .btn-submit {
            width: 100%; padding: 14px; border-radius: 12px;
            background: var(--brand); color: #fff; border: none;
            font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.3s;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 24px;
            box-shadow: 0 8px 20px rgba(0, 174, 239, 0.2);
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 174, 239, 0.4); }
        
        .action-buttons { margin-top: 24px; display: flex; flex-direction: column; gap: 12px; }
        .btn-action {
            width: 100%; padding: 12px; border-radius: 12px;
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);
            color: #E2E8F0; text-decoration: none; font-size: 13px; font-weight: 600;
            display: flex; align-items: center; justify-content: center; gap: 8px; transition: 0.3s;
        }
        .btn-action:hover { background: rgba(255,255,255,0.08); }
        .btn-action i { color: var(--brand); }

        .footer-text { text-align: center; margin-top: 24px; font-size: 12px; color: var(--text-muted); }
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
            <img src="../frontend/new logo.png" alt="<?php echo htmlspecialchars(getenv('APP_NAME') ?: 'WashHub'); ?>" class="brand-logo-img">
            <h1 class="brand-title"><?php echo htmlspecialchars(getenv('APP_NAME') ?: 'WashHub'); ?></h1>
            <div class="brand-subtitle">✦ MANAGEMENT SYSTEM ✦</div>
            
            <div class="quote-box">
                "Where Every Wash Gleams with Excellence"
            </div>
            
            <p class="brand-desc">
                Your all-in-one platform for managing car wash operations, analytics, worker assignments, and daily services — all in one beautiful dashboard.
            </p>
            
            <div class="badges">
                <div class="badge"><i class="fas fa-car"></i> Wash Bays</div>
                <div class="badge"><i class="fas fa-spray-can"></i> Detailing</div>
                <div class="badge"><i class="fas fa-chart-line"></i> Analytics</div>
                <div class="badge"><i class="fas fa-users"></i> Staff</div>
            </div>
        </div>
        
        <div class="brand-footer">
            v1.0.0 — GothTech Consult Ltd. &copy; <?php echo date('Y'); ?>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="login-panel">
        <div class="login-box">
            <?php if ($is_master_db): ?>
                <div class="login-header">
                    <h2>Workspace Setup 🏢</h2>
                    <p>Enter your assigned facility code to continue to your dashboard</p>
                </div>
                <?php if ($error): ?>
                    <div class="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form action="login.php" method="GET">
                    <div class="form-group">
                        <label class="form-label">Facility Code</label>
                        <input type="text" name="bay" class="form-input" placeholder="e.g. washhub_client_xyz" required>
                    </div>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-plug"></i> Connect to Facility
                    </button>
                </form>
            <?php else: ?>
                <div class="login-header">
                    <h2>Welcome Back 👋</h2>
                    <p>Sign in to your management account</p>
                </div>
                

                <div class="login-tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('superadmin')">Super Admin</button>
                    <button type="button" class="tab-btn" onclick="switchTab('admin')">Admin</button>
                </div>

                <!-- Super Admin Form -->
                <div id="superadmin-form" class="login-form active">
                    <?php if ($error && ($login_type === 'superadmin' || $login_type === '')): ?>
                        <div class="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="login_type" value="superadmin">
                        <input type="hidden" name="bay" value="<?php echo htmlspecialchars($current_bay); ?>">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-input" placeholder="Enter username" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-input" placeholder="•••••••••" required>
                        </div>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-lock"></i> Sign In to Dashboard
                        </button>
                    </form>
                </div>
                
                <!-- Admin Form -->
                <div id="admin-form" class="login-form">
                    <?php if ($error && $login_type === 'admin'): ?>
                        <div class="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="login_type" value="admin">
                        <input type="hidden" name="bay" value="<?php echo htmlspecialchars($current_bay); ?>">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-input" placeholder="Enter admin username" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-input" placeholder="•••••••••" required>
                        </div>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-lock"></i> Sign In to Dashboard
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <a href="https://github.com/gothtech/carwash-backend/releases/tag/v0.1.0" target="_blank" class="btn-action">
                    <i class="fas fa-download"></i> Download Desktop App (ZIP)
                </a>
                <a href="https://wa.me/<?php echo htmlspecialchars(getenv('SUPPORT_WHATSAPP') ?: ''); ?>?text=Support" target="_blank" class="btn-action">
                    <i class="fab fa-whatsapp"></i> Contact Support
                </a>
            </div>
            
            <div class="footer-text">
                Powered by <a href="dev_code.php">GothTech Consult</a>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(type) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`.tab-btn[onclick*="${type}"]`).classList.add('active');
            
            document.querySelectorAll('.login-form').forEach(form => form.classList.remove('active'));
            document.getElementById(type + '-form').classList.add('active');
        }
        
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('type') === 'admin') { switchTab('admin'); }
    </script>
</body>
</html>