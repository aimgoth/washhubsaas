<?php
// WashHub CEO Profile
session_start();
if (empty($_SESSION['dev_logged_in'])) { header('Location: dev_login.php'); exit; }
unset($_SESSION['DB_NAME_OVERRIDE']);

require_once __DIR__ . '/config/dev.php';
define('ALLOW_DB_FAIL', true);
define('DISABLE_DB_NAME_OVERRIDE', true);
require_once __DIR__ . '/config/database.php';

if (!$conn) { header('Location: dev_portal.php'); exit; }

$dev_username = trim((string)($_SESSION['dev_username'] ?? DEV_USERNAME ?? 'ceo'));
$error = '';
$success = '';

$conn->query("CREATE TABLE IF NOT EXISTS ceo_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    avatar_path VARCHAR(255) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    if ($full_name === '') {
        $error = 'Full name is required.';
    } else {
        $avatarPath = trim((string)($_POST['current_avatar'] ?? ''));
        if (!empty($_FILES['avatar']['name']) && (int)($_FILES['avatar']['error'] ?? 1) !== UPLOAD_ERR_NO_FILE) {
            if ((int)($_FILES['avatar']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $error = 'Image upload failed. Please try again.';
            } else {
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo((string)$_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $error = 'Only JPG, PNG, or WEBP images are allowed.';
                } else {
                    $dir = __DIR__ . '/uploads/ceo_profiles';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $dev_username);
                    $filename = $safeUser . '_' . time() . '.' . $ext;
                    $target = $dir . '/' . $filename;
                    if (@move_uploaded_file($_FILES['avatar']['tmp_name'], $target)) {
                        $avatarPath = 'uploads/ceo_profiles/' . $filename;
                    } else {
                        $error = 'Could not save uploaded image.';
                    }
                }
            }
        }

        if ($error === '') {
            if ($st = $conn->prepare("INSERT INTO ceo_profiles (username, full_name, avatar_path) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), avatar_path=VALUES(avatar_path), updated_at=NOW()")) {
                $st->bind_param('sss', $dev_username, $full_name, $avatarPath);
                if ($st->execute()) {
                    $success = 'Profile updated successfully.';
                } else {
                    $error = 'Failed to save profile.';
                }
                $st->close();
            } else {
                $error = 'Unable to prepare profile update.';
            }
        }
    }
}

$profile = ['full_name' => '', 'avatar_path' => ''];
if ($st = $conn->prepare("SELECT full_name, avatar_path FROM ceo_profiles WHERE username = ? LIMIT 1")) {
    $st->bind_param('s', $dev_username);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row) { $profile = $row; }
    $st->close();
}
$displayName = trim((string)($profile['full_name'] ?? ''));
if ($displayName === '') { $displayName = 'CEO'; }
$avatar = trim((string)($profile['avatar_path'] ?? ''));
if ($avatar === '') { $avatar = '../frontend/new logo.png'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CEO Profile — WashHub</title>
  <link rel="icon" type="image/png" href="../frontend/new logo.png?v=washhub">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root{--brand:#00AEEF;--navy:#1B3FA0;--dark:#0f172a;--bg:#C8EAF9;--card:#fff;--border:#d0e6f5;--text:#1e293b;--muted:#64748b;--green:#10b981;--red:#ef4444}
    *{box-sizing:border-box} body{margin:0;font-family:Inter,Segoe UI,sans-serif;background:var(--bg);color:var(--text)}
    .nav{background:linear-gradient(135deg,var(--dark),var(--navy));padding:12px 18px;display:flex;justify-content:space-between;align-items:center}
    .brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-weight:800}
    .brand img{height:36px}
    .actions{display:flex;gap:8px}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.86rem}
    .btn.white{background:#fff;color:var(--navy)} .btn.red{background:#ef4444;color:#fff}
    .wrap{max-width:900px;margin:28px auto;padding:0 16px}
    .card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
    .hdr{padding:16px 20px;border-bottom:1px solid #e6eef6;font-weight:800;color:var(--navy)}
    .body{padding:20px}
    .alert{padding:10px 12px;border-radius:8px;margin-bottom:12px}
    .ok{background:#ecfdf5;border-left:4px solid var(--green);color:#065f46}
    .err{background:#fef2f2;border-left:4px solid var(--red);color:#7f1d1d}
    .grid{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:start}
    .avatar-box{text-align:center}
    .avatar{width:160px;height:160px;border-radius:50%;object-fit:cover;border:4px solid #fff;box-shadow:0 4px 15px rgba(0,0,0,.14);background:#fff}
    .label{display:block;font-size:.76rem;font-weight:700;color:#64748b;margin:8px 0 5px;text-transform:uppercase}
    .input{width:100%;padding:10px 12px;border:1.5px solid #d4e6f5;border-radius:8px;font:inherit}
    .input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(0,174,239,.14)}
    .save{margin-top:14px;background:linear-gradient(135deg,var(--navy),var(--brand));color:#fff;border:none;border-radius:8px;padding:10px 16px;font-weight:800;cursor:pointer}
    .muted{font-size:.8rem;color:var(--muted)}
    @media(max-width:760px){.grid{grid-template-columns:1fr}.avatar{width:120px;height:120px}}
  </style>
</head>
<body>
  <div class="nav">
    <a class="brand" href="dev_portal.php"><img src="../frontend/new logo.png" alt="WashHub"><span>WashHub CEO Console</span></a>
    <div class="actions">
      <a class="btn white" href="dev_portal.php"><i class="fas fa-arrow-left"></i> Back to Portal</a>
      <a class="btn red" href="dev_login.php?logout=1"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>

  <div class="wrap">
    <div class="card">
      <div class="hdr"><i class="fas fa-user-gear"></i> CEO Profile</div>
      <div class="body">
        <?php if ($success): ?><div class="alert ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="current_avatar" value="<?php echo htmlspecialchars($avatar); ?>">
          <div class="grid">
            <div class="avatar-box">
              <img class="avatar" src="<?php echo htmlspecialchars($avatar); ?>" alt="CEO Avatar">
              <div class="muted" style="margin-top:8px;">Round profile image shown in navbar</div>
            </div>
            <div>
              <label class="label">Full Name *</label>
              <input class="input" type="text" name="full_name" required value="<?php echo htmlspecialchars($profile['full_name'] ?? ''); ?>" placeholder="e.g. Jimbaja Godfred">

              <label class="label">Profile Image (JPG/PNG/WEBP)</label>
              <input class="input" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              <div class="muted" style="margin-top:6px;">Upload a clear square image for best round display.</div>

              <button class="save" type="submit"><i class="fas fa-floppy-disk"></i> Save Profile</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
