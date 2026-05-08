<?php
session_start();

// Check if user is logged in and is admin/superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Discover available columns for `workers` to support schema differences
$workerColumns = [];
try {
    if ($colsRes = $conn->query("SHOW COLUMNS FROM workers")) {
        while ($c = $colsRes->fetch_assoc()) { $workerColumns[strtolower($c['Field'])] = true; }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }
$hasPhone = isset($workerColumns['phone']);
$hasEmail = isset($workerColumns['email']);
$hasStatus = isset($workerColumns['status']);
$hasCreatedAt = isset($workerColumns['created_at']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_worker'])) {
        $full_name         = trim($_POST['full_name'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $next_of_kin_name  = trim($_POST['next_of_kin_name'] ?? '');
        $next_of_kin_phone = trim($_POST['next_of_kin_phone'] ?? '');
        $status            = $_POST['status'] ?? 'active';

        if (empty($full_name)) {
            $error = 'Full name is required';
        } else {
            // Handle photo upload
            $photo_path = '';
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/uploads/workers/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                    $fname = uniqid('worker_') . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $fname)) {
                        $photo_path = 'uploads/workers/' . $fname;
                    }
                }
            }

            // Build INSERT respecting available columns
            $fields = ['full_name'];
            $types  = 's';
            $values = [$full_name];
            if ($hasPhone) { $fields[] = 'phone'; $types .= 's'; $values[] = $phone; }
            if (isset($workerColumns['next_of_kin_name']))  { $fields[] = 'next_of_kin_name';  $types .= 's'; $values[] = $next_of_kin_name; }
            if (isset($workerColumns['next_of_kin_phone'])) { $fields[] = 'next_of_kin_phone'; $types .= 's'; $values[] = $next_of_kin_phone; }
            if (isset($workerColumns['photo_path']))        { $fields[] = 'photo_path';        $types .= 's'; $values[] = $photo_path; }
            if ($hasStatus)    { $fields[] = 'status';     $types .= 's'; $values[] = $status; }
            if ($hasCreatedAt) { $fields[] = 'created_at'; $types .= 's'; $values[] = date('Y-m-d H:i:s'); }

            $placeholders = implode(',', array_fill(0, count($fields), '?'));
            $sql  = "INSERT INTO workers (" . implode(',', $fields) . ") VALUES (" . $placeholders . ")";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);

            if ($stmt->execute()) {
                $_SESSION['success'] = 'Worker added successfully!';
                header('Location: workers.php');
                exit;
            } else {
                $error = 'Error adding worker: ' . $conn->error;
            }
        }
    }
}

// Superadmin daily visibility and closure state
$today = date('Y-m-d');
$dayStart = $today . ' 00:00:00';
$dayEnd = $today . ' 23:59:59';

// Define work window (optional). Default to on-hours between 06:00 and 20:00 local server time
$hourNow = (int)date('G');
$inWorkWindow = ($hourNow >= 6 && $hourNow < 20);

// Day closure state (superadmin clears daily data after closure)
$dayClosed = false;
try {
    // Ensure day_closures exists
    $conn->query("CREATE TABLE IF NOT EXISTS day_closures (id INT AUTO_INCREMENT PRIMARY KEY, report_date DATE NOT NULL UNIQUE, closed_by INT NULL, closed_at DATETIME NULL, INDEX(report_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $conn->prepare("SELECT 1 FROM day_closures WHERE report_date = CURDATE() AND closed_at IS NOT NULL LIMIT 1");
    $stmt->execute();
    $res = $stmt->get_result();
    $dayClosed = ($res && $res->num_rows > 0);
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Optional: Handle superadmin confirm end-of-day
if (isset($_GET['close_day']) && $_SESSION['role'] === 'superadmin') {
    try {
        $stmt = $conn->prepare("INSERT INTO day_closures (report_date, closed_by, closed_at) VALUES (CURDATE(), ?, NOW()) ON DUPLICATE KEY UPDATE closed_by=VALUES(closed_by), closed_at=VALUES(closed_at)");
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        header('Location: workers.php');
        exit();
    } catch (mysqli_sql_exception $e) { /* ignore */ }
}

// Determine whether to show daily performance
$showDaily = ($_SESSION['role'] === 'superadmin') ? !$dayClosed : true;

// Build leaderboards
$topDailyWorkers = [];
try {
    $sqlTopDaily = "SELECT COALESCE(w.full_name,'Unknown') AS worker_name, COUNT(*) AS washes, COALESCE(SUM(cw.amount),0) AS total_amount
                    FROM car_washes cw
                    LEFT JOIN workers w ON cw.worker_id = w.id
                    WHERE cw.created_at >= ? AND cw.created_at <= ?
                    GROUP BY cw.worker_id, w.full_name
                    ORDER BY washes DESC, total_amount DESC
                    LIMIT 10";
    $st = $conn->prepare($sqlTopDaily);
    $st->bind_param('ss', $dayStart, $dayEnd);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) { $topDailyWorkers[] = $row; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

$topMonthlyWorkers = [];
try {
    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd = date('Y-m-t 23:59:59');
    $sqlTopMonth = "SELECT COALESCE(w.full_name,'Unknown') AS worker_name, COUNT(*) AS washes, COALESCE(SUM(cw.amount),0) AS total_amount
                    FROM car_washes cw
                    LEFT JOIN workers w ON cw.worker_id = w.id
                    WHERE cw.created_at >= ? AND cw.created_at <= ?
                    GROUP BY cw.worker_id, w.full_name
                    ORDER BY washes DESC, total_amount DESC
                    LIMIT 10";
    $stm = $conn->prepare($sqlTopMonth);
    $stm->bind_param('ss', $monthStart, $monthEnd);
    $stm->execute();
    $rm = $stm->get_result();
    while ($row = $rm->fetch_assoc()) { $topMonthlyWorkers[] = $row; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Also pull session flash messages
if (!empty($_SESSION['success'])) { $success = $_SESSION['success']; unset($_SESSION['success']); }
if (!empty($_SESSION['error']))   { $error   = $_SESSION['error'];   unset($_SESSION['error']); }

// Get all workers
$sql = "SELECT * FROM workers ORDER BY full_name";
$result = $conn->query($sql);
$workers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$page_title = 'Manage Workers';
include 'includes/header.php';
?>

<div class="container">
    

    <div class="card card-elevated">
        <div class="card-header">
            <h2 style="display:flex;align-items:center;gap:10px;margin:0;">
                <i class="fas fa-users-cog" style="color:#4a6cf7;"></i>
                Manage Workers
            </h2>
        </div>
        
        <div class="card-body">
            <div class="form-section">
                <div class="section-head">
                    <div>
                        <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-user-plus" style="color:#4a6cf7;"></i>
                            Add New Worker
                        </h3>
                        <p class="muted">Create a worker profile. Phone and email are optional and can be edited later.</p>
                    </div>
                </div>
                
            <?php if (isset($success)): ?>
                <div class="wk-alert ok" style="display:flex;align-items:center;gap:10px;padding:13px 18px;border-radius:10px;font-size:.9rem;font-weight:600;margin-bottom:18px;background:#e0f2fe;border-left:4px solid #00AEEF;color:#0369a1;">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="wk-alert err" style="display:flex;align-items:center;gap:10px;padding:13px 18px;border-radius:10px;font-size:.9rem;font-weight:600;margin-bottom:18px;background:#fef2f2;border-left:4px solid #ef4444;color:#991b1b;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="addWorkerForm">

                    <!-- Photo Upload -->
                    <div style="display:flex;flex-direction:column;align-items:center;gap:10px;margin-bottom:24px;">
                        <div id="photoCircle" onclick="document.getElementById('wPhotoInput').click()"
                             style="width:96px;height:96px;border-radius:50%;border:3px dashed #cbd5e1;background:#f8fafc;
                                    display:flex;align-items:center;justify-content:center;overflow:hidden;
                                    cursor:pointer;transition:border-color .2s;" title="Click to upload photo"
                             onmouseover="this.style.borderColor='#00AEEF'" onmouseout="this.style.borderColor='#cbd5e1'">
                            <div id="wCamIcon" style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#94a3b8;">
                                <i class="fas fa-camera" style="font-size:1.6rem;"></i>
                                <span style="font-size:.68rem;font-weight:700;">Upload Photo</span>
                            </div>
                            <img src="#" id="wPhotoPreview" style="width:100%;height:100%;object-fit:cover;display:none;" alt="Preview">
                        </div>
                        <div style="font-size:.75rem;color:#64748b;text-align:center;">
                            JPG, PNG or GIF &bull;
                            <span style="color:#00AEEF;font-weight:700;cursor:pointer;"
                                  onclick="document.getElementById('wPhotoInput').click()">Choose file</span>
                        </div>
                        <input type="file" id="wPhotoInput" name="photo" accept="image/*" style="display:none;">
                    </div>

                    <!-- Personal Details -->
                    <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;margin:0 0 12px;padding-bottom:7px;border-bottom:1px solid #f1f5f9;">
                        <i class="fas fa-user" style="margin-right:5px;"></i>Personal Details
                    </p>
                    <div class="form-grid" style="margin-bottom:20px;">
                        <div class="form-group">
                            <label for="full_name">Full Name <span class="required">*</span></label>
                            <input type="text" id="full_name" name="full_name" placeholder="e.g. Kwame Mensah" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" placeholder="e.g. 0244000000">
                        </div>
                    </div>

                    <!-- Next of Kin -->
                    <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;margin:0 0 12px;padding-bottom:7px;border-bottom:1px solid #f1f5f9;">
                        <i class="fas fa-user-friends" style="margin-right:5px;"></i>Next of Kin
                    </p>
                    <div class="form-grid" style="margin-bottom:20px;">
                        <div class="form-group">
                            <label for="next_of_kin_name">Next of Kin Name</label>
                            <input type="text" id="next_of_kin_name" name="next_of_kin_name" placeholder="e.g. Ama Mensah">
                        </div>
                        <div class="form-group">
                            <label for="next_of_kin_phone">Next of Kin Phone</label>
                            <input type="tel" id="next_of_kin_phone" name="next_of_kin_phone" placeholder="e.g. 0244000001">
                        </div>
                    </div>

                    <!-- Status -->
                    <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;margin:0 0 12px;padding-bottom:7px;border-bottom:1px solid #f1f5f9;">
                        <i class="fas fa-toggle-on" style="margin-right:5px;"></i>Status
                    </p>
                    <input type="hidden" name="status" value="active">

                    <div class="form-actions">
                        <button type="submit" name="add_worker" class="btn btn-primary" style="padding:11px 28px;font-size:.92rem;">
                            <i class="fas fa-plus-circle"></i> Add Worker
                        </button>
                    </div>
                </form>

                <script>
                document.getElementById('wPhotoInput').addEventListener('change', function() {
                    const file = this.files[0];
                    if (!file) return;
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('wPhotoPreview').src = e.target.result;
                        document.getElementById('wPhotoPreview').style.display = 'block';
                        document.getElementById('wCamIcon').style.display = 'none';
                    };
                    reader.readAsDataURL(file);
                });

                </script>
            </div>
            
            <div class="table-section">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Workers</h2>
                    <div>
                        <a href="employee_performance.php" class="btn btn-info me-2">
                            <i class="fas fa-chart-line"></i> View Performance
                        </a>
                        <a href="workers.php?action=add" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Worker
                        </a>
                    </div>
                </div>
                    <i class="fas fa-address-book" style="color:#4a6cf7;"></i>
                    Workers List
                </h3>
                
                <?php if (!empty($workers)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <?php if ($hasPhone): ?><th>Phone</th><?php endif; ?>
                                    <?php if ($hasEmail): ?><th>Email</th><?php endif; ?>
                                    <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
                                    <?php if ($hasCreatedAt): ?><th>Date Added</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workers as $worker): ?>
                                    <tr>
                                        <td><?php echo $worker['id']; ?></td>
                                        <td><?php echo htmlspecialchars($worker['full_name']); ?></td>
                                        <?php if ($hasPhone): ?><td><?php echo htmlspecialchars($worker['phone'] ?? ''); ?></td><?php endif; ?>
                                        <?php if ($hasEmail): ?><td><?php echo htmlspecialchars($worker['email'] ?? ''); ?></td><?php endif; ?>
                                        <?php if ($hasStatus): ?>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars($worker['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($worker['status'])); ?>
                                            </span>
                                        </td>
                                        <?php endif; ?>
                                        <?php if ($hasCreatedAt): ?><td><?php echo $worker['created_at'] ? date('M j, Y', strtotime($worker['created_at'])) : ''; ?></td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No workers found. Add your first worker above.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    

    <!-- Worker Performance -->
    <div class="card card-elevated" style="margin-top: 16px;">
        <h2 style="display:flex;align-items:center;gap:10px;margin-top:0;">
            <i class="fas fa-chart-line" style="color:#4a6cf7;"></i>
            Worker Performance
        </h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
            <!-- Daily -->
            <div>
                <h3 style="margin: 0 0 10px; display:flex;align-items:center;gap:8px;">
                    <i class="far fa-calendar-day" style="color:#111;"></i>
                    Today's Top Workers
                </h3>
                <div style="overflow-x:auto;">
                <table class="table compact">
                    <thead>
                        <tr>
                            <th>Worker</th>
                            <th>Washes</th>
                            <th>Amount (GHS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($showDaily && !empty($topDailyWorkers)): ?>
                            <?php foreach ($topDailyWorkers as $w): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($w['worker_name']); ?></td>
                                    <td class="text-strong">&nbsp;<?php echo (int)$w['washes']; ?></td>
                                    <td>GHS <?php echo number_format((float)$w['total_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="muted">No data for today.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Monthly -->
            <div>
                <h3 style="margin: 0 0 10px; display:flex;align-items:center;gap:8px;">
                    <i class="far fa-calendar-alt" style="color:#111;"></i>
                    Month-to-Date Top Workers
                </h3>
                <div style="overflow-x:auto;">
                <table class="table compact">
                    <thead>
                        <tr>
                            <th>Worker</th>
                            <th>Washes</th>
                            <th>Amount (GHS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($topMonthlyWorkers)): ?>
                            <?php foreach ($topMonthlyWorkers as $w): ?>
                                <tr>
                                    <td>&nbsp;<?php echo htmlspecialchars($w['worker_name']); ?></td>
                                    <td class="text-strong">&nbsp;<?php echo (int)$w['washes']; ?></td>
                                    <td>GHS <?php echo number_format((float)$w['total_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="muted">No data for this month.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Layout */
    .container { max-width: 1100px; }
    .card { background:#fff; border-radius:12px; border:1px solid #eaeaea; padding:18px; }
    .card-elevated { box-shadow: 0 6px 18px rgba(22,34,51,0.06); }
    .card-header { padding:0 0 12px; border-bottom:1px solid #f0f0f0; margin-bottom:16px; }
    .card-body { padding: 0; }

    /* Alerts */
    .alert { padding:10px 12px; border-radius:8px; margin:10px 0; font-weight:600; }
    .alert-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .alert-error { background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; }

    /* Form */
    .form-section { margin-bottom: 30px; padding: 16px; background: #f9fafb; border:1px solid #eef2f7; border-radius: 10px; }
    .section-head { display:flex; align-items:flex-start; justify-content:space-between; gap: 10px; margin-bottom: 12px; }
    .muted { color:#6b7280; font-size: 0.92em; }
    .form-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:14px; }
    .form-group { display:flex; flex-direction:column; gap:6px; }
    .form-group label { font-weight:600; color:#111827; }
    .required { color:#ef4444; }
    .form-styled input[type="text"],
    .form-styled input[type="tel"],
    .form-styled input[type="email"] { padding:12px 12px; border:1px solid #e5e7eb; border-radius:8px; outline:none; transition: all .15s ease; background:#fff; }
    .form-styled input:focus { border-color:#4a6cf7; box-shadow: 0 0 0 4px rgba(74,108,247,0.15); }
    .form-actions { grid-column: 1 / -1; display:flex; gap:10px; justify-content:flex-start; margin-top:4px; }
    .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; color:#111827; text-decoration:none; font-weight:600; cursor:pointer; transition:.15s ease; }
    .btn:hover { background:#f9fafb; }
    .btn-primary { background:#4a6cf7; color:#fff; border-color:#4a6cf7; }
    .btn-primary:hover { background:#3f5fe0; }

    /* Tables */
    .table-responsive { width:100%; overflow:auto; border:1px solid #eef2f7; border-radius:10px; }
    table.table { width:100%; border-collapse: collapse; }
    table.table thead th { background:#f8fafc; color:#111827; font-weight:700; padding:12px; border-bottom:1px solid #e5e7eb; position:sticky; top:0; }
    table.table tbody td { padding:12px; border-bottom:1px solid #f1f5f9; }
    .table-striped tbody tr:nth-child(odd) { background:#fcfcfd; }
    .table-hover tbody tr:hover { background:#f8fafc; }
    .compact thead th, .compact tbody td { padding:10px; }
    .text-strong { font-weight:700; }

    /* Status badges */
    .status-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border-radius:999px; font-size:12px; font-weight:700; border:1px solid transparent; }
    .status-badge.active { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
    .status-badge.inactive { background:#fff1f2; color:#9f1239; border-color:#fecdd3; }

    @media (max-width: 640px) {
        .card { padding:14px; }
        .card-header { margin-bottom:12px; }
    }
</style>

<?php include 'includes/footer.php'; ?>
