<?php
session_start();

// Check if user is logged in and has admin rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';
require_once __DIR__ . '/includes/csrf.php';

// Add pair_name column if it doesn't exist
$conn->query("ALTER TABLE workers ADD COLUMN IF NOT EXISTS pair_name VARCHAR(100) NULL");

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pair_name'])) {
    $worker1_id = (int)($_POST['worker1_id'] ?? 0);
    $worker2_id = (int)($_POST['worker2_id'] ?? 0);
    $pair_name = trim($_POST['pair_name']);
    
    if (empty($pair_name)) {
        $error = 'Pair name is required';
    } elseif ($worker1_id === 0 || $worker2_id === 0) {
        $error = 'Please select both workers';
    } elseif ($worker1_id === $worker2_id) {
        $error = 'A worker cannot be paired with themselves';
    } else {
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Check if workers are already in pairs
            $stmt = $conn->prepare("SELECT id FROM workers WHERE id IN (?, ?) AND (pair_name IS NOT NULL AND pair_name != '')");
            $stmt->bind_param("ii", $worker1_id, $worker2_id);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'One or both workers are already in a pair';
                $conn->rollback();
            } else {
                // Update both workers with the pair name
                $stmt = $conn->prepare("UPDATE workers SET pair_name = ? WHERE id IN (?, ?)");
                $stmt->bind_param("sii", $pair_name, $worker1_id, $worker2_id);
                
                if ($stmt->execute()) {
                    $conn->commit();
                    $success = 'Pair created successfully';
                    // Refresh the page to show updated list
                    header("Location: manage_pairs.php?success=" . urlencode($success));
                    exit();
                } else {
                    $conn->rollback();
                    $error = 'Error creating pair: ' . $conn->error;
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle pair deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['pair_name'])) {
    $pair_name = urldecode($_GET['pair_name']);
    $stmt = $conn->prepare("UPDATE workers SET pair_name = NULL WHERE pair_name = ?");
    
    if ($stmt->execute([$pair_name])) {
        $success = 'Pair deleted successfully';
        // Refresh the page to show updated list
        header("Location: manage_pairs.php?success=" . urlencode($success));
        exit();
    } else {
        $error = 'Error deleting pair: ' . $conn->error;
    }
}

// Get success message from URL if present
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

// Get all unpaired workers
$workers = [];
$result = $conn->query("SELECT id, full_name FROM workers WHERE pair_name IS NULL OR pair_name = '' ORDER BY full_name");
if ($result) {
    $workers = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all existing pairs
$pairs = [];
$result = $conn->query("
    SELECT pair_name, GROUP_CONCAT(full_name SEPARATOR ' & ') as members 
    FROM workers 
    WHERE pair_name IS NOT NULL AND pair_name != '' 
    GROUP BY pair_name
    HAVING COUNT(*) = 2
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pairs[] = [
            'name' => $row['pair_name'],
            'members' => $row['members']
        ];
    }
}

?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Manage Worker Pairs</h5>
                    <div>
                        <a href="manage_workers.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Workers
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Create New Pair Form -->
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="mb-0">Create New Pair</h6>
                                </div>
                                <div class="card-body">
                                    <form method="post" action="" class="mx-auto" style="max-width: 600px; font-size: 1.1rem;">
                                        <?= csrf_field() ?>
                                        
                                        <div class="mb-4">
                                            <label for="pair_name" class="form-label fw-bold">Pair Name</label>
                                            <input type="text" class="form-control form-control-lg" id="pair_name" name="pair_name" required 
                                                   placeholder="e.g., Team A" value="<?= htmlspecialchars($_POST['pair_name'] ?? '') ?>">
                                            <div class="form-text">Enter a name for this pair</div>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label for="worker1_id" class="form-label fw-bold">Worker 1</label>
                                            <select class="form-select form-select-lg" id="worker1_id" name="worker1_id" required>
                                                <option value="">Select Worker 1</option>
                                                <?php foreach ($workers as $worker): ?>
                                                    <option value="<?= $worker['id'] ?>" 
                                                        <?= (isset($_POST['worker1_id']) && $_POST['worker1_id'] == $worker['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($worker['full_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label for="worker2_id" class="form-label fw-bold">Worker 2</label>
                                            <select class="form-select form-select-lg" id="worker2_id" name="worker2_id" required>
                                                <option value="">Select Worker 2</option>
                                                <?php foreach ($workers as $worker): ?>
                                                    <option value="<?= $worker['id'] ?>"
                                                        <?= (isset($_POST['worker2_id']) && $_POST['worker2_id'] == $worker['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($worker['full_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="fas fa-plus-circle me-2"></i> Create Pair
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Existing Pairs -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">Existing Pairs</h6>
                                    <span class="badge bg-primary"><?= count($pairs) ?> pairs</span>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($pairs)): ?>
                                        <div class="p-4 text-center text-muted">
                                            <i class="fas fa-user-friends fa-3x mb-3"></i>
                                            <p class="mb-0">No pairs created yet</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Pair Name</th>
                                                        <th>Members</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pairs as $pair): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="d-flex align-items-center">
                                                                    <div class="avatar-group me-2">
                                                                        <span class="avatar avatar-sm bg-primary text-white rounded-circle">
                                                                            <?= strtoupper(substr($pair['name'], 0, 1)) ?>
                                                                        </span>
                                                                    </div>
                                                                    <span class="fw-bold"><?= htmlspecialchars($pair['name']) ?></span>
                                                                </div>
                                                            </td>
                                                            <td><?= htmlspecialchars($pair['members']) ?></td>
                                                            <td class="text-end">
                                                                <a href="?action=delete&pair_name=<?= urlencode($pair['name']) ?>" 
                                                                   class="btn btn-sm btn-outline-danger" 
                                                                   title="Delete pair"
                                                                   data-confirm="Are you sure you want to delete this pair? This will not delete the workers.">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const worker1 = document.getElementById('worker1_id');
    const worker2 = document.getElementById('worker2_id');
    
    function updateOptions() {
        const selectedWorker1 = worker1.value;
        const selectedWorker2 = worker2.value;
        
        // Enable all options first
        Array.from(worker1.options).forEach(option => option.disabled = false);
        Array.from(worker2.options).forEach(option => option.disabled = false);
        
        // Disable selected options in the other select
        if (selectedWorker1) {
            Array.from(worker2.options).forEach(option => {
                if (option.value === selectedWorker1) option.disabled = true;
            });
        }
        if (selectedWorker2) {
            Array.from(worker1.options).forEach(option => {
                if (option.value === selectedWorker2) option.disabled = true;
            });
        }
    }
    
    worker1.addEventListener('change', updateOptions);
    worker2.addEventListener('change', updateOptions);
    
    // Initialize on page load
    updateOptions();

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>

<style>
.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    margin-bottom: 1.5rem;
}

.card-header {
    background-color: #f8f9fc;
    border-bottom: 1px solid #e3e6f0;
}

.avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    font-size: 0.875rem;
}

.avatar-group {
    display: inline-flex;
}

.table th {
    font-weight: 600;
    background-color: #f8f9fa;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>
