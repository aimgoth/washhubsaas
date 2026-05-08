<?php
require_once 'config/session.php';

// Only admins manage tasks
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

$error = '';
$success = '';

// Get task ID
$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($task_id <= 0) {
    header('Location: tasks_today.php');
    exit();
}

// Fetch task
$stmt = $conn->prepare("SELECT * FROM wash_tasks WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    header('Location: tasks_today.php');
    exit();
}

// Detect category schema
$cwHasCategory = false;
try {
    $res = $conn->query("SHOW COLUMNS FROM wash_tasks LIKE 'category_id'");
    if ($res && $res->num_rows > 0) $cwHasCategory = true;
} catch (Throwable $e) {}

// Fetch dropdown data
$services = [];
if ($rs = $conn->query("SELECT id, name FROM services ORDER BY name")) { $services = $rs->fetch_all(MYSQLI_ASSOC); }
$categories = [];
if ($rs = $conn->query("SELECT id, name FROM categories ORDER BY name")) { $categories = $rs->fetch_all(MYSQLI_ASSOC); }
$car_sizes = [];
if ($rs = $conn->query("SELECT id, name FROM car_sizes ORDER BY name")) { $car_sizes = $rs->fetch_all(MYSQLI_ASSOC); }
$workers = [];
if ($rs = $conn->query("SELECT id, full_name FROM workers WHERE status = 'active' ORDER BY full_name")) { $workers = $rs->fetch_all(MYSQLI_ASSOC); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_id = (int)($_POST['service_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $car_size_id = (int)($_POST['car_size_id'] ?? 0);
    $number_plate = strtoupper(trim($_POST['number_plate'] ?? ''));
    $worker_id = (int)($_POST['worker_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $workload_level = trim($_POST['workload_level'] ?? 'normal');
    
    if ($service_id > 0 && $car_size_id > 0 && $worker_id > 0) {
        $plateValue = $number_plate === '' ? 'N/A' : $number_plate;
        
        try {
            if ($cwHasCategory) {
                $upd = $conn->prepare("UPDATE wash_tasks SET service_id=?, category_id=?, car_size_id=?, number_plate=?, worker_id=?, amount=?, workload_level=? WHERE id=?");
                $upd->bind_param('iiisidsi', $service_id, $category_id, $car_size_id, $plateValue, $worker_id, $amount, $workload_level, $task_id);
            } else {
                $upd = $conn->prepare("UPDATE wash_tasks SET service_id=?, car_size_id=?, number_plate=?, worker_id=?, amount=?, workload_level=? WHERE id=?");
                $upd->bind_param('iisidsi', $service_id, $car_size_id, $plateValue, $worker_id, $amount, $workload_level, $task_id);
            }
            $upd->execute();
            $_SESSION['flash_success'] = "Task updated successfully.";
            header("Location: tasks_today.php");
            exit();
        } catch (Exception $e) {
            $error = "Failed to update task: " . $e->getMessage();
        }
    } else {
        $error = "Please fill all required fields.";
    }
}

$page_title = 'Edit Task';
include 'includes/header.php';
?>

<style>
    .et-page { max-width: 800px; margin: 36px auto; padding: 0 20px 60px; }
    .et-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 16px rgba(0,0,0,0.07); overflow: hidden; }
    .et-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff; display: flex; align-items: center; justify-content: space-between; }
    .et-header h2 { font-size: 1.25rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
    
    .et-form-body { padding: 24px; }
    .ts-form-group { margin-bottom: 16px; }
    .ts-label { display:block; font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    .ts-input { width: 100%; padding: 12px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 0.95rem; font-weight: 600; color: #1e293b; outline: none; transition: all .2s; }
    .ts-input:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.1); }
    .et-footer { padding: 16px 24px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
    .btn { padding: 10px 20px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
    .btn-primary { background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff; }
    .btn-outline { background: #fff; color: #64748b; border: 1px solid #cbd5e1; }
</style>

<div class="et-page">
    <?php if ($error): ?>
        <div style="padding:15px; background:#fef2f2; color:#991b1b; border-radius:8px; border-left:4px solid #ef4444; margin-bottom:20px; font-weight:bold;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="et-card">
        <div class="et-header">
            <h2><i class="fas fa-pencil-alt" style="color:#00AEEF;"></i> Edit Wash Task</h2>
            <a href="tasks_today.php" class="btn btn-outline"><i class="fas fa-times"></i> Cancel</a>
        </div>
        <form method="POST">
            <div class="et-form-body">
                <div class="ts-form-group">
                    <label class="ts-label">Service *</label>
                    <select name="service_id" class="ts-input" required>
                        <option value="">Select Service...</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo $task['service_id']==$s['id']?'selected':''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($cwHasCategory): ?>
                <div class="ts-form-group">
                    <label class="ts-label">Category *</label>
                    <select name="category_id" class="ts-input" required>
                        <option value="">Select Category...</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($task['category_id']) && $task['category_id']==$c['id'])?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="ts-form-group">
                    <label class="ts-label">Vehicle Size *</label>
                    <select name="car_size_id" class="ts-input" required>
                        <option value="">Select Size...</option>
                        <?php foreach ($car_sizes as $cs): ?>
                            <option value="<?php echo (int)$cs['id']; ?>" <?php echo $task['car_size_id']==$cs['id']?'selected':''; ?>><?php echo htmlspecialchars($cs['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">License Plate *</label>
                    <input type="text" name="number_plate" class="ts-input" value="<?php echo htmlspecialchars($task['number_plate'] !== 'N/A' ? $task['number_plate'] : ''); ?>">
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Amount (GHS) *</label>
                    <input type="number" step="0.01" name="amount" class="ts-input" value="<?php echo htmlspecialchars($task['amount'] ?? 0); ?>" required>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Assigned Washer *</label>
                    <select name="worker_id" class="ts-input" required>
                        <option value="">Select Washer...</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo (int)$w['id']; ?>" <?php echo $task['worker_id']==$w['id']?'selected':''; ?>><?php echo htmlspecialchars($w['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ts-form-group">
                    <label class="ts-label">Workload Level</label>
                    <div style="display:flex; gap:16px; margin-top:8px;">
                        <label style="cursor:pointer; font-weight:600;"><input type="radio" name="workload_level" value="normal" <?php echo ($task['workload_level']??'normal')==='normal'?'checked':''; ?>> Normal Pace</label>
                        <label style="cursor:pointer; font-weight:600; color:#ef4444;"><input type="radio" name="workload_level" value="busy" <?php echo ($task['workload_level']??'')==='busy'?'checked':''; ?>> Busy / Rush Hour</label>
                    </div>
                </div>
            </div>
            <div class="et-footer">
                <div></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
