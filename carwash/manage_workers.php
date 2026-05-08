<?php
session_start();

// Check if user is logged in and has admin rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';
require_once __DIR__ . '/includes/csrf.php';

// ---- DAY CLOSE HANDLER ----
if (isset($_GET['close_day']) && $_GET['close_day'] == '1' && ($_SESSION['role'] ?? '') === 'superadmin') {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS day_closures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_date DATE NOT NULL UNIQUE,
            closed_by INT NULL,
            closed_at DATETIME NULL,
            INDEX(report_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        if ($stmtAuto = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at) VALUES (CURDATE(), ?, NOW())
            ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), closed_at = NOW()")) {
            $stmtAuto->bind_param('i', $_SESSION['user_id']);
            $stmtAuto->execute();
        }
    } catch (Throwable $e) { /* ignore */ }

    $_SESSION['flash_day_closed'] = 1;
    header('Location: manage_workers.php', true, 303);
    exit;
}

// Handle worker actions (add/edit/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
            case 'edit':
                $id = $_POST['id'] ?? null;
                $full_name = trim($_POST['full_name']);
                $phone = trim($_POST['phone'] ?? '');
                $next_of_kin_name = trim($_POST['next_of_kin_name'] ?? '');
                $next_of_kin_phone = trim($_POST['next_of_kin_phone'] ?? '');
                $status = $_POST['status'] ?? 'active';
                
                if (empty($full_name)) {
                    $_SESSION['error'] = 'Full name is required';
                } else {
                    // Handle photo upload if a new file was uploaded
                    $photo_path = null;
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/workers/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }

                        $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                        $new_filename = uniqid() . '.' . $file_extension;
                        $target_file = $upload_dir . $new_filename;

                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                            $photo_path = $target_file;
                        }
                    }

                    if ($_POST['action'] === 'add') {
                        $sql = "INSERT INTO workers (full_name, phone, next_of_kin_name, next_of_kin_phone, photo_path, status) VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssssss", $full_name, $phone, $next_of_kin_name, $next_of_kin_phone, $photo_path, $status);
                    } else {
                        if ($photo_path) {
                            $sql = "UPDATE workers SET full_name=?, phone=?, next_of_kin_name=?, next_of_kin_phone=?, photo_path=?, status=? WHERE id=?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("ssssssi", $full_name, $phone, $next_of_kin_name, $next_of_kin_phone, $photo_path, $status, $id);
                        } else {
                            $sql = "UPDATE workers SET full_name=?, phone=?, next_of_kin_name=?, next_of_kin_phone=?, status=? WHERE id=?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("sssssi", $full_name, $phone, $next_of_kin_name, $next_of_kin_phone, $status, $id);
                        }
                    }
                    
                    if ($stmt->execute()) {
                        $_SESSION['success'] = 'Worker ' . ($_POST['action'] === 'add' ? 'added' : 'updated') . ' successfully!';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $_SESSION['error'] = 'Error: ' . $conn->error;
                    }
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                $sql = "DELETE FROM workers WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = 'Worker deleted successfully!';
                } else {
                    $_SESSION['error'] = 'Error deleting worker: ' . $conn->error;
                }
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
                break;
        }
    }
}

// ---- DELETE WORKER ----
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM workers WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Worker deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting worker: " . $conn->error;
    }
    header("Location: manage_workers.php");
    exit();
}

// ---- TOGGLE STATUS ----
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("UPDATE workers SET status = IF(status='active','inactive','active') WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_workers.php");
    exit();
}

// ---- FETCH WORKERS ----
$workers = [];
$result = $conn->query("SELECT * FROM workers ORDER BY full_name");
if ($result) {
    $workers = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

$edit_worker = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM workers WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_worker = $result->fetch_assoc();
}

$page_title = 'Workers Management';
include 'includes/header.php';
?>

<style>
    .wk-page { max-width: 1100px; margin: 36px auto; padding: 0 20px 60px; }

    .wk-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .wk-title-left { display: flex; align-items: center; gap: 14px; }
    .wk-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .wk-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .wk-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .wk-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .wk-btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-size: 0.92rem; font-weight: 700; cursor: pointer; text-decoration: none;
        transition: filter .2s, transform .15s; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .wk-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }

    .wk-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 0.9rem; font-weight: 600; margin-bottom: 14px;
    }
    .wk-alert.ok  { background: #e0f2fe; border-left: 4px solid #00AEEF; color: #0369a1; }
    .wk-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    .wk-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 16px rgba(0,0,0,0.07);
        margin-bottom: 24px; overflow: hidden;
    }
    .wk-card-header {
        padding: 16px 24px; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        background: #f8faff;
    }
    .wk-card-header h2 {
        font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 9px;
    }
    .wk-card-header h2 i { color: #00AEEF; }
    .wk-badge {
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; font-size: 0.72rem; font-weight: 700;
        padding: 4px 12px; border-radius: 20px; letter-spacing: 0.4px;
    }

    /* Table */
    .wk-table-wrap { overflow-x: auto; }
    .wk-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .wk-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .wk-table th {
        padding: 14px 20px; font-size: 0.73rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        text-align: left; white-space: nowrap; color: #fff;
    }
    .wk-table td {
        padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; vertical-align: middle;
    }
    .wk-table tbody tr:last-child td { border-bottom: none; }
    .wk-table tbody tr:hover td { background: #f0f7ff; transition: background .15s; }

    .wk-row-num {
        display: inline-flex; width: 28px; height: 28px; border-radius: 50%;
        background: #e0f2fe; color: #0369a1;
        font-size: 0.78rem; font-weight: 800;
        align-items: center; justify-content: center;
    }

    .wk-photo {
        width: 44px; height: 44px; border-radius: 50%; object-fit: cover;
        border: 2px solid #e2e8f0; background: #fff;
    }
    .wk-photo-ph {
        width: 44px; height: 44px; border-radius: 50%; background: #f1f5f9;
        display: flex; align-items: center; justify-content: center;
        color: #94a3b8; border: 2px solid #e2e8f0; font-size: 1.1rem;
    }

    .wk-contact-stack { display: flex; flex-direction: column; gap: 2px; }
    .wk-contact-stack a { color: #1B3FA0; text-decoration: none; font-weight: 600; }
    .wk-contact-stack a:hover { text-decoration: underline; }
    
    .wk-nok-stack { display: flex; flex-direction: column; gap: 2px; }
    .wk-nok-name { font-weight: 600; color: #334155; }
    .wk-nok-phone { font-size: 0.82rem; color: #64748b; }

    /* Actions */
    .wk-status-btn {
        padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;
        border: none; cursor: pointer; transition: opacity .2s;
        display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.5px;
        text-decoration: none;
    }
    .wk-status-btn.active { background: #d1fae5; color: #065f46; box-shadow: inset 0 0 0 1px #a7f3d0; }
    .wk-status-btn.inactive { background: #fee2e2; color: #991b1b; box-shadow: inset 0 0 0 1px #fecaca; }
    .wk-status-btn:hover { opacity: 0.8; }
    
    .wk-actions-cell { display: flex; gap: 6px; align-items: center; justify-content: center; }
    .wk-btn-edit {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 8px;
        background: #f1f5f9; color: #1B3FA0; border: 1px solid #e2e8f0;
        transition: all .2s; text-decoration: none;
    }
    .wk-btn-edit:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }

    .wk-btn-del {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 8px;
        background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
        cursor: pointer; transition: all .2s;
    }
    .wk-btn-del:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .wk-empty { text-align: center; padding: 40px 20px; color: #94a3b8; }
    .wk-empty i { font-size: 2.8rem; opacity: 0.25; margin-bottom: 14px; display: block; }
    .wk-empty strong { display: block; font-size: 1rem; color: #64748b; margin-bottom: 6px; }

    /* Photo Grid */
    .wk-photo-grid-wrap { display: flex; flex-wrap: wrap; gap: 24px; padding: 24px; justify-content: center; }
    .wk-pg-item { display: flex; flex-direction: column; align-items: center; gap: 10px; width: 150px; }
    .wk-pg-img {
        width: 140px; height: 140px; border-radius: 16px; object-fit: cover;
        border: 3px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transition: transform .2s;
    }
    .wk-pg-img:hover { transform: scale(1.05); }
    .wk-pg-ph {
        width: 140px; height: 140px; border-radius: 16px; background: #f8fafc;
        display: flex; align-items: center; justify-content: center; color: #cbd5e1;
        font-size: 3rem; border: 3px dashed #e2e8f0; transition: transform .2s;
    }
    .wk-pg-ph:hover { transform: scale(1.02); }
    .wk-pg-name { font-weight: 700; color: #1e293b; text-align: center; font-size: 0.95rem; line-height: 1.3; }

    /* Lightbox Modal */
    .wk-lightbox {
        display: none; position: fixed; inset: 0;
        background: rgba(15,23,42,0.85); backdrop-filter: blur(8px);
        z-index: 9999; align-items: center; justify-content: center;
        padding: 20px;
    }
    .wk-lightbox.active { display: flex; animation: fadeIn .2s ease-out; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .wk-lightbox img {
        max-width: 90%; max-height: 90vh; border-radius: 12px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.5);
    }
    .wk-lightbox-close {
        position: absolute; top: 24px; right: 24px;
        width: 44px; height: 44px; border-radius: 50%;
        background: rgba(255,255,255,0.1); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem; cursor: pointer; border: none;
        transition: all .2s;
    }
    .wk-lightbox-close:hover { background: rgba(255,255,255,0.25); transform: scale(1.1); }

    /* inline add-worker form extras */
    .wk-aw-section {
        font-size: 0.72rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.8px; color: #94a3b8;
        margin: 0 0 12px; padding-bottom: 7px;
        border-bottom: 1px solid #f1f5f9;
    }
    .wk-aw-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 600px) { .wk-aw-grid { grid-template-columns: 1fr; } }
    .wk-aw-group { display: flex; flex-direction: column; gap: 5px; }
    .wk-aw-group label { font-size: 0.82rem; font-weight: 700; color: #374151; }
    .wk-aw-group label .req { color: #ef4444; margin-left: 2px; }
    .wk-aw-group input {
        padding: 10px 13px; border: 1.5px solid #e2e8f0;
        border-radius: 9px; font-size: 0.9rem; color: #1e293b;
        outline: none; width: 100%; transition: border-color .2s, box-shadow .2s;
        background: #fff;
    }
    .wk-aw-group input:focus {
        border-color: #00AEEF;
        box-shadow: 0 0 0 3px rgba(0,174,239,0.12);
    }
    .wk-aw-photo-wrap {
        display: flex; flex-direction: column; align-items: center; gap: 10px;
        margin-bottom: 24px;
    }
    .wk-aw-photo-circle {
        width: 96px; height: 96px; border-radius: 50%;
        border: 3px dashed #cbd5e1; background: #f8fafc;
        display: flex; align-items: center; justify-content: center;
        overflow: hidden; cursor: pointer; transition: border-color .2s;
    }
    .wk-aw-photo-circle:hover { border-color: #00AEEF; }
    .wk-aw-photo-circle img { width: 100%; height: 100%; object-fit: cover; display: none; }
    .wk-aw-cam { display: flex; flex-direction: column; align-items: center; gap: 4px; color: #94a3b8; }
    .wk-aw-cam i { font-size: 1.6rem; }
    .wk-aw-cam span { font-size: 0.68rem; font-weight: 700; }
    .wk-aw-status-row { display: flex; gap: 10px; margin-bottom: 6px; }
    .wk-aw-pill {
        flex: 1; border: 2px solid #e2e8f0;
        border-radius: 9px; padding: 9px 12px;
        display: flex; align-items: center; gap: 8px;
        cursor: pointer; transition: all .2s; background: #fff;
    }
    .wk-aw-pill input { display: none; }
    .wk-aw-dot {
        width: 16px; height: 16px; border-radius: 50%;
        border: 2px solid #cbd5e1; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
    }
    .wk-aw-dot::after {
        content: ''; width: 7px; height: 7px;
        border-radius: 50%; background: transparent; transition: background .2s;
    }
    .wk-aw-pill.sel-active   { border-color: #10b981; background: #f0fdf4; }
    .wk-aw-pill.sel-inactive { border-color: #f59e0b; background: #fffbeb; }
    .wk-aw-pill.sel-active .wk-aw-dot   { border-color: #10b981; }
    .wk-aw-pill.sel-inactive .wk-aw-dot { border-color: #f59e0b; }
    .wk-aw-pill.sel-active .wk-aw-dot::after   { background: #10b981; }
    .wk-aw-pill.sel-inactive .wk-aw-dot::after { background: #f59e0b; }
    .wk-aw-pill-lbl { font-size: 0.85rem; font-weight: 700; color: #374151; }
    #wkAwPhotoInput { display: none; }
</style>

<div class="wk-page">

    <!-- Page Title -->
    <div class="wk-title">
        <div class="wk-title-left">
            <div class="wk-title-icon"><i class="fas fa-users-cog"></i></div>
            <div>
                <h1>Manage Workers</h1>
                <p>Add, edit, and view all workers in your system.</p>
            </div>
        </div>
        <div class="wk-actions">
            <a href="#addWorkerCard" class="wk-btn-primary">
                <i class="fas fa-user-plus"></i> Add New Worker
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="wk-alert ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="wk-alert err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <!-- â”€â”€ Add New Worker Card â”€â”€ -->
    <div class="wk-card" id="addWorkerCard" style="margin-bottom:24px;">
        <div class="wk-card-header">
            <h2><i class="fas fa-user-plus"></i> Add New Worker</h2>
            <span style="font-size:0.78rem;color:#64748b;">Create a worker profile. Photo and contact details are optional.</span>
        </div>
        <div style="padding:24px;">
            <form method="POST" enctype="multipart/form-data" id="addWorkerForm">
                <input type="hidden" name="action" value="add">

                <!-- Photo Upload -->
                <div class="wk-aw-photo-wrap">
                    <div class="wk-aw-photo-circle" onclick="document.getElementById('wkAwPhotoInput').click()" title="Click to upload photo">
                        <div class="wk-aw-cam" id="wkAwCamIcon">
                            <i class="fas fa-camera"></i>
                            <span>Upload Photo</span>
                        </div>
                        <img src="#" id="wkAwPhotoPreview" alt="Preview">
                    </div>
                    <div style="font-size:.75rem;color:#64748b;text-align:center;">
                        JPG, PNG or GIF &bull;
                        <span style="color:#00AEEF;font-weight:700;cursor:pointer;" onclick="document.getElementById('wkAwPhotoInput').click()">Choose file</span>
                    </div>
                    <input type="file" id="wkAwPhotoInput" name="photo" accept="image/*">
                </div>

                <!-- Personal Details -->
                <p class="wk-aw-section"><i class="fas fa-user" style="margin-right:5px;"></i>Personal Details</p>
                <div class="wk-aw-grid">
                    <div class="wk-aw-group">
                        <label for="awFullName">Full Name <span class="req">*</span></label>
                        <input type="text" id="awFullName" name="full_name" placeholder="e.g. Kwame Mensah" required>
                    </div>
                    <div class="wk-aw-group">
                        <label for="awPhone">Phone Number</label>
                        <input type="tel" id="awPhone" name="phone" placeholder="e.g. 0244000000">
                    </div>
                </div>

                <!-- Next of Kin -->
                <p class="wk-aw-section"><i class="fas fa-user-friends" style="margin-right:5px;"></i>Next of Kin</p>
                <div class="wk-aw-grid">
                    <div class="wk-aw-group">
                        <label for="awNokName">Next of Kin Name</label>
                        <input type="text" id="awNokName" name="next_of_kin_name" placeholder="e.g. Ama Mensah">
                    </div>
                    <div class="wk-aw-group">
                        <label for="awNokPhone">Next of Kin Phone</label>
                        <input type="tel" id="awNokPhone" name="next_of_kin_phone" placeholder="e.g. 0244000001">
                    </div>
                </div>

                <input type="hidden" name="status" value="active">
                <!-- Submit -->
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="wk-btn-primary" style="border:none;cursor:pointer;">
                        <i class="fas fa-plus-circle"></i> Add Worker
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="wk-card">
        <div class="wk-card-header">
            <h2><i class="fas fa-list-ul"></i> Workers List</h2>
            <span class="wk-badge"><?php echo count($workers); ?> Worker<?php echo count($workers) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (empty($workers)): ?>
            <div class="wk-empty">
                <i class="fas fa-users-slash"></i>
                <strong>No workers found</strong>
                Use the "Add New Worker" button to get started.
            </div>
        <?php else: ?>
        <div class="wk-table-wrap">
            <table class="wk-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><i class="fas fa-image" style="margin-right:6px;opacity:.8;"></i>Photo</th>
                        <th><i class="fas fa-user" style="margin-right:6px;opacity:.8;"></i>Name</th>
                        <th><i class="fas fa-address-book" style="margin-right:6px;opacity:.8;"></i>Contact</th>
                        <th><i class="fas fa-user-friends" style="margin-right:6px;opacity:.8;"></i>Next of Kin</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workers as $i => $w): ?>
                    <tr>
                        <td>
                            <span class="wk-row-num" title="ID: <?php echo $w['id']; ?>"><?php echo $i + 1; ?></span>
                        </td>
                        <td>
                            <?php if ($w['photo_path']): ?>
                                <img src="<?php echo htmlspecialchars($w['photo_path']); ?>" class="wk-photo" alt="Photo">
                            <?php else: ?>
                                <div class="wk-photo-ph"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;">
                            <?php echo htmlspecialchars($w['full_name']); ?>
                        </td>
                        <td>
                            <div class="wk-contact-stack">
                                <?php if (!empty($w['phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($w['phone']); ?>"><?php echo htmlspecialchars($w['phone']); ?></a>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">â€”</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($w['next_of_kin_name'])): ?>
                                <div class="wk-nok-stack">
                                    <span class="wk-nok-name"><?php echo htmlspecialchars($w['next_of_kin_name']); ?></span>
                                    <?php if (!empty($w['next_of_kin_phone'])): ?>
                                        <span class="wk-nok-phone"><?php echo htmlspecialchars($w['next_of_kin_phone']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#cbd5e1; font-style:italic;">Not specified</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <a href="?toggle_status=1&id=<?php echo $w['id']; ?>" class="wk-status-btn <?php echo $w['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                <?php if ($w['status'] === 'active'): ?>
                                    <i class="fas fa-check-circle"></i> Active
                                <?php else: ?>
                                    <i class="fas fa-times-circle"></i> Inactive
                                <?php endif; ?>
                            </a>
                        </td>
                        <td style="text-align:center;">
                            <div class="wk-actions-cell">
                                <a href="edit_worker.php?id=<?php echo $w['id']; ?>" class="wk-btn-edit" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" style="margin:0;display:inline;" data-confirm="Delete worker &quot;<?php echo htmlspecialchars($w['full_name'], ENT_QUOTES); ?>&quot;? This cannot be undone.">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $w['id']; ?>">
                                    <button type="submit" class="wk-btn-del" title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Photos Grid Card -->
    <?php if (!empty($workers)): ?>
    <div class="wk-card">
        <div class="wk-card-header">
            <h2><i class="fas fa-images"></i> Workers Photo Directory</h2>
        </div>
        <div class="wk-photo-grid-wrap">
            <?php foreach ($workers as $w): ?>
                <div class="wk-pg-item">
                    <?php if ($w['photo_path']): ?>
                        <img src="<?php echo htmlspecialchars($w['photo_path']); ?>" class="wk-pg-img" alt="Photo" onclick="openLightbox(this.src)" style="cursor: pointer;" title="Click to view">
                    <?php else: ?>
                        <div class="wk-pg-ph"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="wk-pg-name"><?php echo htmlspecialchars($w['full_name']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lightbox Modal -->
    <div class="wk-lightbox" id="wkLightbox" onclick="closeLightbox()">
        <button class="wk-lightbox-close" onclick="closeLightbox()"><i class="fas fa-times"></i></button>
        <img src="" id="wkLightboxImg" alt="Worker Photo" onclick="event.stopPropagation()">
    </div>

</div>

<script>
// Photo preview for inline add form
document.getElementById('wkAwPhotoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('wkAwPhotoPreview');
        img.src = e.target.result;
        img.style.display = 'block';
        document.getElementById('wkAwCamIcon').style.display = 'none';
    };
    reader.readAsDataURL(file);
});

// Lightbox functions
function openLightbox(src) {
    const lightbox = document.getElementById('wkLightbox');
    const img = document.getElementById('wkLightboxImg');
    img.src = src;
    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    const lightbox = document.getElementById('wkLightbox');
    lightbox.classList.remove('active');
    document.body.style.overflow = '';
}

// Close lightbox on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>

<?php include 'includes/footer.php'; ?>
