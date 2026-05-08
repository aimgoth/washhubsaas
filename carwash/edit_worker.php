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
$worker = null;

// Get worker data if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM workers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $worker = $result->fetch_assoc();
    
    if (!$worker) {
        $_SESSION['error'] = 'Worker not found';
        header("Location: manage_workers.php");
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $next_of_kin_name = trim($_POST['next_of_kin_name']);
    $next_of_kin_phone = trim($_POST['next_of_kin_phone']);
    $status = $_POST['status'];
    
    // Basic validation
    if (empty($full_name)) {
        $error = 'Full name is required';
    } else {
        // Handle photo upload if a new file was uploaded
        $photo_path = $worker['photo_path'] ?? '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/workers/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid('worker_') . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                // Delete old photo if it exists
                if (!empty($worker['photo_path']) && file_exists($worker['photo_path'])) {
                    unlink($worker['photo_path']);
                }
                $photo_path = $target_file;
            }
        }
        
        // Update worker in database
        $stmt = $conn->prepare("UPDATE workers SET full_name = ?, phone = ?, next_of_kin_name = ?, next_of_kin_phone = ?, status = ?, photo_path = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $full_name, $phone, $next_of_kin_name, $next_of_kin_phone, $status, $photo_path, $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Worker updated successfully';
            header("Location: manage_workers.php");
            exit;
        } else {
            $error = 'Error updating worker: ' . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Worker - Car Wash Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 30px;
            margin-top: 30px;
        }
        .photo-preview {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-user-edit me-2"></i> Edit Worker</h2>
                    <a href="manage_workers.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Workers
                    </a>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="form-container">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?php echo $worker['id']; ?>">
                        
                        <div class="row mb-4">
                            <div class="col-md-4 text-center">
                                <?php if (!empty($worker['photo_path'])): ?>
                                    <img src="<?php echo $worker['photo_path']; ?>" class="photo-preview" id="photoPreview">
                                <?php else: ?>
                                    <div class="bg-light d-flex align-items-center justify-content-center" style="width: 150px; height: 150px; border-radius: 5px; margin: 0 auto 15px;">
                                        <i class="fas fa-user fa-4x text-muted"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label for="photo" class="form-label">Change Photo</label>
                                    <input class="form-control" type="file" id="photo" name="photo" accept="image/*" onchange="previewPhoto(event)">
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($worker['full_name']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($worker['phone'] ?? ''); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="next_of_kin_name" class="form-label">Next of Kin Name</label>
                                    <input type="text" class="form-control" id="next_of_kin_name" name="next_of_kin_name" value="<?php echo htmlspecialchars($worker['next_of_kin_name'] ?? ''); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="next_of_kin_phone" class="form-label">Next of Kin Phone</label>
                                    <input type="tel" class="form-control" id="next_of_kin_phone" name="next_of_kin_phone" value="<?php echo htmlspecialchars($worker['next_of_kin_phone'] ?? ''); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="status" id="statusActive" value="active" <?php echo ($worker['status'] ?? 'active') === 'active' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="statusActive">Active</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="status" id="statusInactive" value="inactive" <?php echo ($worker['status'] ?? '') === 'inactive' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="statusInactive">Inactive</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="manage_workers.php" class="btn btn-secondary me-md-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Update Worker
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function previewPhoto(event) {
        const input = event.target;
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                let preview = document.getElementById('photoPreview');
                if (!preview) {
                    const previewDiv = document.createElement('div');
                    previewDiv.className = 'mb-3';
                    previewDiv.innerHTML = '<img id="photoPreview" class="photo-preview">';
                    input.parentNode.insertBefore(previewDiv, input);
                    preview = document.getElementById('photoPreview');
                }
                preview.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
