<?php
session_start();

// Check if user is logged in and is admin/superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_worker']) || isset($_POST['update_worker'])) {
        $id = $_POST['id'] ?? null;
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $next_of_kin_name = trim($_POST['next_of_kin_name'] ?? '');
        $next_of_kin_phone = trim($_POST['next_of_kin_phone'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Handle file upload
        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/workers/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $filename = uniqid('worker_') . '.' . $file_extension;
                $target_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
                    $photo_path = $target_path;
                }
            }
        }
        
        if (isset($_POST['add_worker'])) {
            // Add new worker
            $sql = "INSERT INTO workers (full_name, phone, email, next_of_kin_name, next_of_kin_phone, photo_path, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssss", $full_name, $phone, $email, $next_of_kin_name, $next_of_kin_phone, $photo_path, $status);
        } else {
            // Update existing worker
            $sql = "UPDATE workers 
                    SET full_name = ?, phone = ?, email = ?, next_of_kin_name = ?, next_of_kin_phone = ?, status = ?" . 
                    ($photo_path ? ", photo_path = ?" : "") . " 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($photo_path) {
                $stmt->bind_param("sssssssi", $full_name, $phone, $email, $next_of_kin_name, $next_of_kin_phone, $status, $photo_path, $id);
            } else {
                $stmt->bind_param("ssssssi", $full_name, $phone, $email, $next_of_kin_name, $next_of_kin_phone, $status, $id);
            }
        }
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Worker " . (isset($_POST['add_worker']) ? "added" : "updated") . " successfully!";
            header("Location: manage_workers.php");
            exit();
        } else {
            $error = "Error: " . $conn->error;
        }
    }
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sql = "DELETE FROM workers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Worker deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting worker: " . $conn->error;
    }
    header("Location: manage_workers.php");
    exit();
}

// Toggle worker status
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sql = "UPDATE workers SET status = IF(status='active', 'inactive', 'active') WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_workers.php");
    exit();
}

// Get all workers
$workers = [];
$sql = "SELECT * FROM workers ORDER BY full_name";
$result = $conn->query($sql);
if ($result) {
    $workers = $result->fetch_all(MYSQLI_ASSOC);
}

// Get worker for editing
$edit_worker = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sql = "SELECT * FROM workers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_worker = $result->fetch_assoc();
}

$page_title = 'Manage Workers';
include 'includes/header.php';
?>

<div class="container py-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['success']; 
            unset($_SESSION['success']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['error']; 
            unset($_SESSION['error']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">Workers List</h4>
                    <a href="?action=add" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#workerModal">
                        <i class="fas fa-plus"></i> Add New Worker
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Photo</th>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Next of Kin</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($workers) > 0): ?>
                                    <?php foreach ($workers as $index => $worker): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php if (!empty($worker['photo_path'])): ?>
                                                    <img src="<?php echo $worker['photo_path']; ?>" alt="Worker Photo" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                                                <?php else: ?>
                                                    <div style="width: 50px; height: 50px; background-color: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 4px;">
                                                        <i class="fas fa-user" style="font-size: 24px; color: #666;"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($worker['full_name']); ?></strong><br>
                                                <small class="text-muted">ID: <?php echo $worker['id']; ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($worker['phone'])): ?>
                                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($worker['phone']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($worker['email'])): ?>
                                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($worker['email']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($worker['next_of_kin_name'])): ?>
                                                    <i class="fas fa-user-friends"></i> <?php echo htmlspecialchars($worker['next_of_kin_name']); ?><br>
                                                    <?php if (!empty($worker['next_of_kin_phone'])): ?>
                                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($worker['next_of_kin_phone']); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not specified</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="?toggle_status=1&id=<?php echo $worker['id']; ?>" class="btn btn-sm btn-<?php echo $worker['status'] === 'active' ? 'success' : 'secondary'; ?>" title="Toggle Status">
                                                    <?php echo ucfirst($worker['status']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="#" class="btn btn-sm btn-info" 
                                                       data-bs-toggle="modal" data-bs-target="#viewModal"
                                                       data-name="<?php echo htmlspecialchars($worker['full_name']); ?>"
                                                       data-phone="<?php echo htmlspecialchars($worker['phone']); ?>"
                                                       data-email="<?php echo htmlspecialchars($worker['email']); ?>"
                                                       data-kin="<?php echo htmlspecialchars($worker['next_of_kin_name']); ?>"
                                                       data-kin-phone="<?php echo htmlspecialchars($worker['next_of_kin_phone']); ?>"
                                                       data-photo="<?php echo htmlspecialchars($worker['photo_path'] ?? ''); ?>"
                                                       data-status="<?php echo $worker['status']; ?>"
                                                       title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="?action=edit&id=<?php echo $worker['id']; ?>" 
                                                       class="btn btn-sm btn-warning" 
                                                       title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?action=delete&id=<?php echo $worker['id']; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this worker?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">No workers found. Add your first worker!</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Worker Modal -->
<div class="modal fade" id="workerModal" tabindex="-1" aria-labelledby="workerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="workerModalLabel">
                    <?php echo isset($edit_worker) ? 'Edit Worker' : 'Add New Worker'; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <?php if (isset($edit_worker)): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_worker['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required
                               value="<?php echo isset($edit_worker) ? htmlspecialchars($edit_worker['full_name']) : ''; ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                   value="<?php echo isset($edit_worker) ? htmlspecialchars($edit_worker['phone'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo isset($edit_worker) ? htmlspecialchars($edit_worker['email'] ?? '') : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="next_of_kin_name" class="form-label">Next of Kin Name</label>
                            <input type="text" class="form-control" id="next_of_kin_name" name="next_of_kin_name"
                                   value="<?php echo isset($edit_worker) ? htmlspecialchars($edit_worker['next_of_kin_name'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="next_of_kin_phone" class="form-label">Next of Kin Phone</label>
                            <input type="tel" class="form-control" id="next_of_kin_phone" name="next_of_kin_phone"
                                   value="<?php echo isset($edit_worker) ? htmlspecialchars($edit_worker['next_of_kin_phone'] ?? '') : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="photo" class="form-label">
                            Worker's Photo
                            <?php if (isset($edit_worker) && !empty($edit_worker['photo_path'])): ?>
                                <br>
                                <small class="text-muted">Current: 
                                    <a href="<?php echo $edit_worker['photo_path']; ?>" target="_blank">View Photo</a>
                                </small>
                            <?php endif; ?>
                        </label>
                        <input type="file" class="form-control" id="photo" name="photo" accept="image/*">
                        <div class="form-text">Max size: 2MB. Allowed: JPG, PNG, GIF</div>
                    </div>
                    
                    <?php if (isset($edit_worker)): ?>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="status" id="statusActive" value="active" 
                                           <?php echo (!isset($edit_worker['status']) || $edit_worker['status'] === 'active') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="statusActive">Active</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="status" id="statusInactive" value="inactive"
                                           <?php echo (isset($edit_worker['status']) && $edit_worker['status'] === 'inactive') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="statusInactive">Inactive</label>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="<?php echo isset($edit_worker) ? 'update_worker' : 'add_worker'; ?>" class="btn btn-primary">
                        <?php echo isset($edit_worker) ? 'Update Worker' : 'Add Worker'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Worker Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewModalLabel">Worker Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <img id="viewPhoto" src="" alt="Worker Photo" class="img-thumbnail" style="max-width: 200px; display: none;">
                    <div id="noPhoto" class="text-center py-4">
                        <i class="fas fa-user-circle" style="font-size: 100px; color: #ddd;"></i>
                    </div>
                    <h4 class="mt-3" id="viewName"></h4>
                    <span class="badge bg-success" id="viewStatus"></span>
                </div>
                
                <div class="mb-3">
                    <h6>Contact Information</h6>
                    <p id="viewPhone" class="mb-1"></p>
                    <p id="viewEmail" class="mb-0"></p>
                </div>
                
                <div class="mb-3">
                    <h6>Next of Kin</h6>
                    <p id="viewKin" class="mb-1"></p>
                    <p id="viewKinPhone" class="mb-0"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// View Worker Modal
var viewModal = document.getElementById('viewModal');
viewModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    
    // Update the modal's content
    var photo = button.getAttribute('data-photo');
    var photoElement = document.getElementById('viewPhoto');
    var noPhotoElement = document.getElementById('noPhoto');
    
    if (photo) {
        photoElement.src = photo;
        photoElement.style.display = 'block';
        noPhotoElement.style.display = 'none';
    } else {
        photoElement.style.display = 'none';
        noPhotoElement.style.display = 'block';
    }
    
    document.getElementById('viewName').textContent = button.getAttribute('data-name');
    
    var status = button.getAttribute('data-status');
    var statusElement = document.getElementById('viewStatus');
    statusElement.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    statusElement.className = 'badge ' + (status === 'active' ? 'bg-success' : 'bg-secondary');
    
    document.getElementById('viewPhone').innerHTML = button.getAttribute('data-phone') ? 
        '<i class="fas fa-phone me-2"></i>' + button.getAttribute('data-phone') : 
        '<span class="text-muted">No phone number</span>';
        
    document.getElementById('viewEmail').innerHTML = button.getAttribute('data-email') ? 
        '<i class="fas fa-envelope me-2"></i>' + button.getAttribute('data-email') : 
        '<span class="text-muted">No email address</span>';
    
    var kinName = button.getAttribute('data-kin');
    var kinPhone = button.getAttribute('data-kin-phone');
    
    document.getElementById('viewKin').innerHTML = kinName ? 
        '<i class="fas fa-user-friends me-2"></i>' + kinName : 
        '<span class="text-muted">No next of kin specified</span>';
        
    document.getElementById('viewKinPhone').innerHTML = kinPhone ? 
        '<i class="fas fa-phone me-2"></i>' + kinPhone : '';
});

// Show edit modal if in edit mode
<?php if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var workerModal = new bootstrap.Modal(document.getElementById('workerModal'));
        workerModal.show();
    });
<?php endif; ?>
</script>
