<?php
session_start();

// Check if user is logged in and has admin rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name         = trim($_POST['full_name'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $next_of_kin_name  = trim($_POST['next_of_kin_name'] ?? '');
    $next_of_kin_phone = trim($_POST['next_of_kin_phone'] ?? '');
    $status            = $_POST['status'] ?? 'active';

    if (empty($full_name)) {
        $error = 'Full name is required.';
    } else {
        // Handle photo upload
        $photo_path = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/workers/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = uniqid('worker_') . '.' . $file_extension;
                $target_file  = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                    $photo_path = $target_file;
                } else {
                    $error = 'Error uploading file. Please try again.';
                }
            } else {
                $error = 'Invalid file type. Only JPG, JPEG, PNG & GIF files are allowed.';
            }
        }

        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO workers (full_name, phone, next_of_kin_name, next_of_kin_phone, status, photo_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $full_name, $phone, $next_of_kin_name, $next_of_kin_phone, $status, $photo_path);

            if ($stmt->execute()) {
                $_SESSION['success'] = 'Worker added successfully!';
                header("Location: manage_workers.php");
                exit;
            } else {
                $error = 'Error adding worker: ' . $conn->error;
            }
        }
    }
}

$page_title = 'Add New Worker';
include 'includes/header.php';
?>

<style>
    .aw-page {
        max-width: 780px;
        margin: 36px auto;
        padding: 0 20px 60px;
    }

    /* ── Page Title ── */
    .aw-title {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 28px;
        justify-content: space-between;
        flex-wrap: wrap;
    }
    .aw-title-left { display: flex; align-items: center; gap: 14px; }
    .aw-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .aw-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .aw-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .aw-btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px;
        border: 2px solid #e2e8f0;
        background: #fff;
        color: #475569;
        border-radius: 10px;
        font-size: 0.9rem; font-weight: 700;
        text-decoration: none;
        transition: all .2s;
    }
    .aw-btn-back:hover { border-color: #1B3FA0; color: #1B3FA0; background: #f0f4ff; }

    /* ── Alert ── */
    .aw-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 0.9rem; font-weight: 600; margin-bottom: 20px;
    }
    .aw-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    /* ── Card ── */
    .aw-card {
        background: #fff;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .aw-card-header {
        padding: 18px 28px;
        border-bottom: 1px solid #f1f5f9;
        background: linear-gradient(135deg, #f0f7ff, #e8f4fd);
        display: flex; align-items: center; gap: 10px;
    }
    .aw-card-header h2 {
        font-size: 1rem; font-weight: 800; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .aw-card-header h2 i { color: #00AEEF; }
    .aw-card-body { padding: 28px; }

    /* ── Photo upload ── */
    .aw-photo-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
        margin-bottom: 28px;
    }
    .aw-photo-circle {
        width: 110px; height: 110px; border-radius: 50%;
        border: 3px dashed #cbd5e1;
        background: #f8fafc;
        display: flex; align-items: center; justify-content: center;
        overflow: hidden;
        transition: border-color .2s;
        cursor: pointer;
        position: relative;
    }
    .aw-photo-circle:hover { border-color: #00AEEF; }
    .aw-photo-circle img {
        width: 100%; height: 100%; object-fit: cover;
        display: none;
    }
    .aw-photo-circle .aw-cam-icon {
        display: flex; flex-direction: column; align-items: center;
        gap: 6px; color: #94a3b8;
    }
    .aw-photo-circle .aw-cam-icon i { font-size: 2rem; }
    .aw-photo-circle .aw-cam-icon span { font-size: 0.72rem; font-weight: 600; }
    .aw-photo-label {
        font-size: 0.8rem; color: #64748b;
        text-align: center; line-height: 1.4;
    }

    /* ── Form Grid ── */
    .aw-section-title {
        font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.8px; color: #94a3b8;
        margin: 0 0 14px;
        padding-bottom: 8px;
        border-bottom: 1px solid #f1f5f9;
    }
    .aw-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
    }
    @media (max-width: 600px) {
        .aw-form-grid { grid-template-columns: 1fr; }
    }
    .aw-form-group { display: flex; flex-direction: column; gap: 6px; }
    .aw-form-group label {
        font-size: 0.82rem; font-weight: 700; color: #374151;
    }
    .aw-form-group label .req { color: #ef4444; margin-left: 2px; }
    .aw-form-group input,
    .aw-form-group select {
        padding: 11px 14px;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.92rem;
        color: #1e293b;
        background: #fff;
        transition: border-color .2s, box-shadow .2s;
        outline: none;
        width: 100%;
    }
    .aw-form-group input:focus,
    .aw-form-group select:focus {
        border-color: #00AEEF;
        box-shadow: 0 0 0 3px rgba(0,174,239,0.12);
    }



    /* ── Footer Actions ── */
    .aw-form-footer {
        display: flex; justify-content: flex-end; align-items: center;
        gap: 12px; padding: 20px 28px;
        border-top: 1px solid #f1f5f9;
        background: #f8fafc;
    }
    .aw-btn-cancel {
        padding: 11px 24px; border-radius: 10px;
        border: 1.5px solid #e2e8f0; background: #fff;
        color: #64748b; font-size: 0.9rem; font-weight: 700;
        text-decoration: none; transition: all .2s;
    }
    .aw-btn-cancel:hover { background: #f1f5f9; color: #1e293b; border-color: #cbd5e1; }
    .aw-btn-submit {
        padding: 11px 28px; border-radius: 10px; border: none;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; font-size: 0.92rem; font-weight: 800;
        cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
        transition: filter .2s, transform .15s;
    }
    .aw-btn-submit:hover { filter: brightness(1.08); transform: translateY(-1px); }

    /* hidden file input trick */
    #photoFileInput { display: none; }
</style>

<div class="aw-page">

    <!-- Page Title -->
    <div class="aw-title">
        <div class="aw-title-left">
            <div class="aw-title-icon"><i class="fas fa-user-plus"></i></div>
            <div>
                <h1>Add New Worker</h1>
                <p>Fill in the details below to register a new worker.</p>
            </div>
        </div>
        <a href="manage_workers.php" class="aw-btn-back">
            <i class="fas fa-arrow-left"></i> Back to Workers
        </a>
    </div>

    <?php if ($error): ?>
        <div class="aw-alert err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="aw-card">
        <div class="aw-card-header">
            <h2><i class="fas fa-id-card"></i> Worker Information</h2>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="aw-card-body">

                <!-- Photo Upload -->
                <div class="aw-photo-wrap">
                    <div class="aw-photo-circle" id="photoCircle" onclick="document.getElementById('photoFileInput').click()">
                        <div class="aw-cam-icon" id="camIcon">
                            <i class="fas fa-camera"></i>
                            <span>Upload Photo</span>
                        </div>
                        <img src="#" id="photoPreview" alt="Preview">
                    </div>
                    <div class="aw-photo-label">JPG, PNG or GIF · Max 5MB<br><span style="color:#00AEEF;font-weight:700;cursor:pointer;" onclick="document.getElementById('photoFileInput').click()">Click to choose a photo</span></div>
                    <input type="file" id="photoFileInput" name="photo" accept="image/*">
                </div>

                <!-- Worker Details -->
                <p class="aw-section-title"><i class="fas fa-user" style="margin-right:6px;"></i>Personal Details</p>
                <div class="aw-form-grid" style="margin-bottom:24px;">
                    <div class="aw-form-group">
                        <label for="full_name">Full Name <span class="req">*</span></label>
                        <input type="text" id="full_name" name="full_name"
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                               placeholder="e.g. Kwame Mensah" required>
                    </div>
                    <div class="aw-form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                               placeholder="e.g. 0244000000">
                    </div>
                </div>

                <!-- Next of Kin -->
                <p class="aw-section-title"><i class="fas fa-user-friends" style="margin-right:6px;"></i>Next of Kin</p>
                <div class="aw-form-grid" style="margin-bottom:24px;">
                    <div class="aw-form-group">
                        <label for="next_of_kin_name">Next of Kin Name</label>
                        <input type="text" id="next_of_kin_name" name="next_of_kin_name"
                               value="<?php echo htmlspecialchars($_POST['next_of_kin_name'] ?? ''); ?>"
                               placeholder="e.g. Ama Mensah">
                    </div>
                    <div class="aw-form-group">
                        <label for="next_of_kin_phone">Next of Kin Phone</label>
                        <input type="tel" id="next_of_kin_phone" name="next_of_kin_phone"
                               value="<?php echo htmlspecialchars($_POST['next_of_kin_phone'] ?? ''); ?>"
                               placeholder="e.g. 0244000001">
                    </div>
                </div>

                <input type="hidden" name="status" value="active">

            </div><!-- /.aw-card-body -->

            <div class="aw-form-footer">
                <a href="manage_workers.php" class="aw-btn-cancel">Cancel</a>
                <button type="submit" class="aw-btn-submit">
                    <i class="fas fa-plus-circle"></i> Add Worker
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Photo preview
document.getElementById('photoFileInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        const img = document.getElementById('photoPreview');
        const icon = document.getElementById('camIcon');
        img.src = e.target.result;
        img.style.display = 'block';
        icon.style.display = 'none';
    };
    reader.readAsDataURL(file);
});


</script>

<?php include 'includes/footer.php'; ?>
