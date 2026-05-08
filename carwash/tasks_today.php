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

// Flash success
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Detect optional columns in car_washes (for schema compatibility)
$carWashColumns = [];
try {
    if ($cwCols = $conn->query("SHOW COLUMNS FROM car_washes")) {
        while ($c = $cwCols->fetch_assoc()) { $carWashColumns[strtolower($c['Field'])] = true; }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }
$cwHasCategory = isset($carWashColumns['category_id']);
$cwHasCreated  = isset($carWashColumns['created_at']);
$cwHasUpdated  = isset($carWashColumns['updated_at']);

// Ensure wash_tasks table exists (soft check)
try {
    $conn->query("SELECT 1 FROM wash_tasks LIMIT 1");
} catch (Throwable $e) {
    $error = 'wash_tasks table not found. Please run the provided SQL migration.';
}

// Ensure wash_tasks.amount exists for manual pricing
try {
    $__colAmt = $conn->query("SHOW COLUMNS FROM wash_tasks LIKE 'amount'");
    if ($__colAmt && $__colAmt->num_rows === 0) {
        @$conn->query("ALTER TABLE wash_tasks ADD COLUMN amount DECIMAL(10,2) NULL DEFAULT NULL");
    }
} catch (Throwable $e) { /* ignore */ }

// Ensure workload_level columns exist
try {
    if ($__col = $conn->query("SHOW COLUMNS FROM wash_tasks LIKE 'workload_level'")) {
        if ($__col->num_rows === 0) @$conn->query("ALTER TABLE wash_tasks ADD COLUMN workload_level VARCHAR(20) NOT NULL DEFAULT 'normal'");
    }
    if ($__col = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'workload_level'")) {
        if ($__col->num_rows === 0) @$conn->query("ALTER TABLE car_washes ADD COLUMN workload_level VARCHAR(20) NOT NULL DEFAULT 'normal'");
    }
} catch (Throwable $e) { /* ignore */ }

// Ensure assigned_by, planned_start, planned_end exist
try {
    if ($__col = $conn->query("SHOW COLUMNS FROM wash_tasks LIKE 'assigned_by'")) {
        if ($__col->num_rows === 0) @$conn->query("ALTER TABLE wash_tasks ADD COLUMN assigned_by VARCHAR(100) NULL DEFAULT 'Admin'");
    }
    if ($__col = $conn->query("SHOW COLUMNS FROM wash_tasks LIKE 'planned_start'")) {
        if ($__col->num_rows === 0) @$conn->query("ALTER TABLE wash_tasks ADD COLUMN planned_start DATETIME NULL DEFAULT NULL");
    }
    if ($__col = $conn->query("SHOW COLUMNS FROM wash_tasks LIKE 'planned_end'")) {
        if ($__col->num_rows === 0) @$conn->query("ALTER TABLE wash_tasks ADD COLUMN planned_end DATETIME NULL DEFAULT NULL");
    }
} catch (Throwable $e) { /* ignore */ }

// Ensure car_washes timing and duration columns exist
try {
    $cw_cols = [
        'planned_start' => 'DATETIME NULL DEFAULT NULL',
        'planned_end' => 'DATETIME NULL DEFAULT NULL',
        'started_at' => 'DATETIME NULL DEFAULT NULL',
        'completed_at' => 'DATETIME NULL DEFAULT NULL',
        'duration_minutes' => 'INT NOT NULL DEFAULT 0',
        'is_foul' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'foul_overrun_minutes' => 'INT NOT NULL DEFAULT 0',
        'ended_by' => "VARCHAR(100) NULL DEFAULT 'Admin'",
        'admin_id' => 'INT NULL DEFAULT NULL'
    ];
    foreach ($cw_cols as $col => $def) {
        if ($__col = $conn->query("SHOW COLUMNS FROM car_washes LIKE '$col'")) {
            if ($__col->num_rows === 0) @$conn->query("ALTER TABLE car_washes ADD COLUMN $col $def");
        }
    }
} catch (Throwable $e) { /* ignore */ }

// Load dropdown data
$services = [];
if ($rs = $conn->query("SELECT id, name FROM services ORDER BY name")) { $services = $rs->fetch_all(MYSQLI_ASSOC); }
$categories = [];
if ($rs = $conn->query("SELECT id, name FROM categories ORDER BY name")) { $categories = $rs->fetch_all(MYSQLI_ASSOC); }
$car_sizes = [];
if ($rs = $conn->query("SELECT id, name FROM car_sizes ORDER BY name")) { $car_sizes = $rs->fetch_all(MYSQLI_ASSOC); }
$workers = [];
if ($rs = $conn->query("SELECT id, full_name FROM workers WHERE status = 'active' ORDER BY full_name")) { $workers = $rs->fetch_all(MYSQLI_ASSOC); }

function fetch_price(mysqli $conn, int $service_id, int $car_size_id): float {
    $amount = 0.0;
    if ($stmt = $conn->prepare("SELECT amount FROM prices WHERE service_id = ? AND car_size_id = ? LIMIT 1")) {
        $stmt->bind_param('ii', $service_id, $car_size_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) { $amount = (float)$row['amount']; }
    }
    return $amount;
}

function ceil_minutes_diff(string $from, string $to): int {
    $start = strtotime($from);
    $end = strtotime($to);
    if ($start === false || $end === false) return 0;
    $diff = max(0, $end - $start);
    return (int)ceil($diff / 60);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_task') {
            $service_id = (int)($_POST['service_id'] ?? 0);
            $category_id = (int)($_POST['category_id'] ?? 0);
            $car_size_id = (int)($_POST['car_size_id'] ?? 0);
            $number_plate = strtoupper(trim($_POST['number_plate'] ?? ''));
            $worker_id = (int)($_POST['worker_id'] ?? 0);
            $input_amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
            $workload_level = trim($_POST['workload_level'] ?? 'normal');

            if ($service_id <= 0 || $car_size_id <= 0 || $worker_id <= 0) {
                throw new Exception('Please fill in all required fields');
            }
            if ($input_amount <= 0) {
                throw new Exception('Please enter a valid amount for this task');
            }

            // Determine if category implies no plate (Carpets)
            $isCarpet = false;
            if ($cwHasCategory && $category_id > 0) {
                if ($stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?")) {
                    $stmt->bind_param('i', $category_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $isCarpet = (strtolower(trim($row['name'] ?? '')) === 'carpets' || strtolower(trim($row['name'] ?? '')) === 'carpet');
                    }
                }
            }
            if (!$isCarpet && $number_plate === '') {
                throw new Exception('License plate is required for non-carpet services');
            }

            $plateValue = ($isCarpet && $number_plate === '') ? 'N/A' : $number_plate;

            // Duplicate Plate Verification
            if ($plateValue !== 'N/A' && $plateValue !== '') {
                $today = date('Y-m-d');
                
                // Check completed washes (car_washes)
                $stmt_chk = $conn->prepare("SELECT id FROM car_washes WHERE number_plate = ? AND DATE(created_at) = ? LIMIT 1");
                $stmt_chk->bind_param('ss', $plateValue, $today);
                $stmt_chk->execute();
                if ($stmt_chk->get_result()->num_rows > 0) {
                    throw new Exception("Duplicate Entry: The license plate '{$plateValue}' has already been recorded today.");
                }
                
                // Check queued washes (wash_tasks)
                try {
                    $stmt_chk2 = $conn->prepare("SELECT id FROM wash_tasks WHERE number_plate = ? AND DATE(created_at) = ? AND status != 'cancelled' LIMIT 1");
                    $stmt_chk2->bind_param('ss', $plateValue, $today);
                    $stmt_chk2->execute();
                    if ($stmt_chk2->get_result()->num_rows > 0) {
                        throw new Exception("Duplicate Entry: The license plate '{$plateValue}' is currently queued or has been washed today.");
                    }
                } catch (Throwable $e) {}
            }

            $assigned_by = $_SESSION['full_name'] ?? 'Admin';
            
            // Get service duration for planned end time
            $duration_minutes = 0;
            // Duration calculation removed as per user request
            
            // Calculate planned start and end times in local timezone
            $timezone = new DateTimeZone('Africa/Accra');
            $now = new DateTime('now', $timezone);
            $planned_start = $now->format('Y-m-d H:i:s');
            
            // Calculate end time by adding duration minutes
            $end_time = clone $now;
            $end_time->add(new DateInterval("PT{$duration_minutes}M"));
            $planned_end = $end_time->format('Y-m-d H:i:s');

            $sql = "INSERT INTO wash_tasks (service_id, " . ($cwHasCategory ? 'category_id,' : '') . " car_size_id, number_plate, worker_id, amount, status, assigned_by, workload_level, planned_start, planned_end) VALUES (" .
                   "?, " . ($cwHasCategory ? '?, ' : '') . "?, ?, ?, ?, 'pending', ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($cwHasCategory) {
                $stmt->bind_param('iiisidssss', $service_id, $category_id, $car_size_id, $plateValue, $worker_id, $input_amount, $assigned_by, $workload_level, $planned_start, $planned_end);
            } else {
                $stmt->bind_param('iisidssss', $service_id, $car_size_id, $plateValue, $worker_id, $input_amount, $assigned_by, $workload_level, $planned_start, $planned_end);
            }
            $stmt->execute();
            $_SESSION['flash_success'] = 'Task created.';
            header('Location: tasks_today.php');
            exit();
        }
        if ($action === 'end_task') {
            $task_id = (int)($_POST['task_id'] ?? 0);
            if ($task_id <= 0) throw new Exception('Invalid task');
            // Load task
            $stmt = $conn->prepare("SELECT * FROM wash_tasks WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $task_id);
            $stmt->execute();
            $taskRes = $stmt->get_result();
            if (!($task = $taskRes->fetch_assoc())) throw new Exception('Task not found');
            // No longer checking for 'started_at'

            $now = date('Y-m-d H:i:s');
            $duration_minutes = 0;
            $foul_overrun_minutes = 0;
            $is_foul = 0;

            // Prefer manually set amount on task; otherwise from prices
            $amount = isset($task['amount']) ? (float)$task['amount'] : 0.0;
            if ($amount <= 0) {
                $amount = fetch_price($conn, (int)$task['service_id'], (int)$task['car_size_id']);
            }
            $amount = (float)number_format($amount, 2, '.', '');

            // Start transaction
            $conn->begin_transaction();
            
            try {
                // First, check if task is already completed
                $check_task = $conn->prepare("SELECT status FROM wash_tasks WHERE id = ? FOR UPDATE");
                $check_task->bind_param('i', $task_id);
                $check_task->execute();
                $task_status = $check_task->get_result()->fetch_assoc()['status'] ?? '';
                
                if ($task_status === 'manual_closed' || $task_status === 'completed') {
                    throw new Exception('This task has already been completed.');
                }
                
                // Check for existing wash record with same details in the last 10 minutes
                $check_sql = "SELECT id FROM car_washes 
                             WHERE service_id = ? 
                             AND number_plate = ? 
                             AND worker_id = ? 
                             AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                             ORDER BY created_at DESC LIMIT 1";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('isi', $task['service_id'], $task['number_plate'], $task['worker_id']);
                $check_stmt->execute();
                $existing_wash = $check_stmt->get_result()->fetch_assoc();
                
                if ($existing_wash) {
                    // Just update the task status without creating a new wash record
                    $update_task = $conn->prepare("UPDATE wash_tasks SET status = 'manual_closed' WHERE id = ?");
                    $update_task->bind_param('i', $task_id);
                    $update_task->execute();
                    
                    $conn->commit();
                    $_SESSION['flash_warning'] = 'This wash has already been recorded. Task marked as completed.';
                    header('Location: tasks_today.php');
                    exit();
                }

                // Mark the task as completed
                $update_task = $conn->prepare("UPDATE wash_tasks SET status = 'manual_closed' WHERE id = ?");
                $update_task->bind_param('i', $task_id);
                if (!$update_task->execute() || $update_task->affected_rows === 0) {
                    throw new Exception('Task has already been completed or does not exist.');
                }

            // Build INSERT into car_washes with detected columns
            $fields = ['service_id']; $types = 'i'; $vals = [(int)$task['service_id']];
            if ($cwHasCategory) { $fields[] = 'category_id'; $types .= 'i'; $vals[] = (int)($task['category_id'] ?? 0); }
            $fields[]='car_size_id'; $types.='i'; $vals[]=(int)$task['car_size_id'];
            $fields[]='amount'; $types.='d'; $vals[]=$amount;
            $fields[]='number_plate'; $types.='s'; $vals[]=$task['number_plate'];
            $fields[]='worker_id'; $types.='i'; $vals[]=(int)$task['worker_id'];
            $fields[]='admin_id'; $types.='i'; $vals[]=(int)$_SESSION['user_id'];

            // Timing fields (setting all to now/null since we don't track duration)
            $fields[]='planned_start'; $types.='s'; $vals[]=$now;
            $fields[]='planned_end';   $types.='s'; $vals[]=$now;
            $fields[]='started_at';    $types.='s'; $vals[]=$now;
            $fields[]='completed_at';  $types.='s'; $vals[]=$now;
            $fields[]='duration_minutes'; $types.='i'; $vals[]=$duration_minutes;
            $fields[]='is_foul'; $types.='i'; $vals[]=$is_foul;
            $fields[]='foul_overrun_minutes'; $types.='i'; $vals[]=$foul_overrun_minutes;
            $fields[]='ended_by'; $types.='s'; $vals[]=$_SESSION['full_name'] ?? 'Admin';
            $fields[]='workload_level'; $types.='s'; $vals[]=$task['workload_level'] ?? 'normal';

                // Insert into car_washes table
                $columns = implode(',', $fields);
                $placeholders = implode(',', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO car_washes ($columns" . ($cwHasCreated ? ', created_at' : '') . ($cwHasUpdated ? ', updated_at' : '') . ") VALUES ($placeholders" . ($cwHasCreated ? ', NOW()' : '') . ($cwHasUpdated ? ', NOW()' : '') . ")";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$vals);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to insert car_washes record: ' . $conn->error);
                }
                $car_wash_id = $conn->insert_id;

                // No generator/fuel logging — standard wash only
                
                // Commit the transaction
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }

            // Close task
            $stmt = $conn->prepare("UPDATE wash_tasks SET status = 'manual_closed' WHERE id = ?");
            $stmt->bind_param('i', $task_id);
            $stmt->execute();

            $_SESSION['flash_success'] = 'Task ended and recorded.';
            header('Location: tasks_today.php');
            exit();
        }
        
        if ($action === 'cancel_task') {
            $task_id = (int)($_POST['task_id'] ?? 0);
            if ($task_id > 0) {
                // Instead of hard deleting, we mark it as cancelled for audit purposes
                $stmt = $conn->prepare("UPDATE wash_tasks SET status = 'cancelled' WHERE id = ?");
                $stmt->bind_param('i', $task_id);
                $stmt->execute();
                $_SESSION['flash_success'] = 'Task cancelled and removed from queue.';
            }
            header('Location: tasks_today.php');
            exit();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'Tasks Today';
include 'includes/header.php';
?>

<style>
    .ts-page { max-width: 1300px; margin: 36px auto; padding: 0 20px 60px; }
    
    .ts-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .ts-title-left { display: flex; align-items: center; gap: 14px; }
    .ts-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .ts-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .ts-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .ts-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 24px; border-radius: 10px; font-weight: 700; font-size: 0.95rem; background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff; text-decoration: none; border: none; cursor: pointer; transition: all .2s; box-shadow: 0 3px 12px rgba(0,174,239,0.3); }
    .ts-btn:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
    .ts-btn-outline { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 10px; font-weight: 700; font-size: 0.92rem; background: #fff; color: #1B3FA0; border: 1.5px solid #cbd5e1; box-shadow: none; text-decoration: none; transition: all .2s; }
    .ts-btn-outline:hover { background: #f8fafc; border-color: #94a3b8; color: #1B3FA0; }

    .ts-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 16px rgba(0,0,0,0.07); margin-bottom: 24px; overflow: hidden; }
    .ts-card-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .ts-card-header h2 { font-size: 1.1rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
    
    /* Form Styles */
    .ts-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; padding: 24px; }
    .ts-form-group { display: flex; flex-direction: column; gap: 6px; }
    .ts-form-group.full { grid-column: 1 / -1; }
    .ts-label { font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
    .ts-label .req { color: #ef4444; }
    .ts-input { padding: 12px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 0.95rem; font-weight: 600; color: #1e293b; outline: none; transition: all .2s; background: #fff; width: 100%; box-sizing: border-box; }
    .ts-input:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.1); }
    .ts-input:disabled, .ts-input[readonly] { background: #f8fafc; color: #64748b; }
    .ts-hint { font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-top: 2px; }

    /* Tables */
    .ts-table-wrap { overflow-x: auto; }
    .ts-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .ts-table thead tr { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .ts-table th { padding: 16px 20px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; text-align: left; color: #475569; white-space: nowrap; }
    .ts-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
    .ts-table tbody tr:hover td { background: #f8faff; }
    .ts-table tbody tr:last-child td { border-bottom: none; }
    
    .ts-plate { font-family: monospace; background: #f1f5f9; padding: 6px 10px; border-radius: 6px; font-weight: 800; color: #334155; border: 1px solid #cbd5e1; display: inline-block; font-size: 0.95rem; letter-spacing: 1px; }
    .ts-amount { font-weight: 800; color: #059669; }
    
    .ts-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .ts-badge.queued { background: #fef3c7; color: #d97706; }
    .ts-badge.progress { background: #e0e7ff; color: #4338ca; }
    
    .ts-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; text-decoration: none; border: none; cursor: pointer; transition: transform .1s; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .ts-action-btn.success { background: #10b981; color: #fff; }
    .ts-action-btn.success:hover { background: #059669; transform: translateY(-1px); }
    .ts-action-btn.outline { background: #fff; color: #1B3FA0; border: 1.5px solid #cbd5e1; }
    .ts-action-btn.outline:hover { border-color: #1B3FA0; }
    
    .ts-alert { padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 600; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; }
    .ts-alert.success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
    .ts-alert.error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
    
    .ts-empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .ts-empty i { font-size: 3rem; margin-bottom: 16px; opacity: 0.5; display: block; }
    .ts-empty-title { font-size: 1.1rem; font-weight: 700; color: #475569; margin-bottom: 4px; }
</style>

<div class="ts-page">
    <div class="ts-title">
        <div class="ts-title-left">
            <div class="ts-title-icon"><i class="fas fa-clipboard-list"></i></div>
            <div>
                <h1>Assign &amp; Track Tasks</h1>
                <p>Log a new car wash job and assign it to a worker.</p>
            </div>
        </div>
        <a href="dashboard.php" class="ts-btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php if ($error): ?>
        <div class="ts-alert error"><i class="fas fa-exclamation-triangle"></i> <div><?php echo htmlspecialchars($error); ?></div></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="ts-alert success"><i class="fas fa-check-circle"></i> <div><?php echo htmlspecialchars($success); ?></div></div>
    <?php endif; ?>

    <div class="ts-card">
        <div class="ts-card-header" style="background: linear-gradient(to right, #f8faff, #fff);">
            <h2><i class="fas fa-plus-circle" style="color:#00AEEF;"></i> New Wash Task</h2>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="create_task">
            
            <div class="ts-form-grid">
                <div class="ts-form-group">
                    <label class="ts-label">Service <span class="req">*</span></label>
                    <select name="service_id" class="ts-input" required>
                        <option value="">Select Service...</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($cwHasCategory): ?>
                <div class="ts-form-group">
                    <label class="ts-label">Category <span class="req">*</span></label>
                    <select name="category_id" class="ts-input" required>
                        <option value="">Select Category...</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="ts-form-group">
                    <label class="ts-label">Vehicle Size <span class="req">*</span></label>
                    <select name="car_size_id" class="ts-input" required>
                        <option value="">Select Size...</option>
                        <?php foreach ($car_sizes as $cs): ?>
                            <option value="<?php echo (int)$cs['id']; ?>"><?php echo htmlspecialchars($cs['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ts-form-group" id="plate_group">
                    <label class="ts-label">License Plate <span class="req">*</span></label>
                    <input type="text" name="number_plate" class="ts-input" placeholder="e.g. GR-1234-21" pattern="[A-Z0-9 -]{3,12}" title="Enter a valid license plate">
                    <div class="ts-hint">Not required for Carpets.</div>
                </div>
                
                <div class="ts-form-group">
                    <label class="ts-label">Amount (GHS) <span class="req">*</span></label>
                    <input type="number" name="amount" id="amount" class="ts-input" step="0.01" min="0" placeholder="0.00" required readonly>
                    <div class="ts-hint" id="amount_help">Calculated automatically based on service and size.</div>
                </div>
                
                <div class="ts-form-group">
                    <label class="ts-label">Assigned Washer <span class="req">*</span></label>
                    <select name="worker_id" class="ts-input" required>
                        <option value="">Select Washer...</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo (int)$w['id']; ?>"><?php echo htmlspecialchars($w['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ts-form-group full">
                    <label class="ts-label">Workload Context <span class="req">*</span></label>
                    <div style="display:flex; gap:16px;">
                        <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                            <input type="radio" name="workload_level" value="normal" checked style="width:18px; height:18px; accent-color:#00AEEF;"> Normal Pace
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                            <input type="radio" name="workload_level" value="busy" style="width:18px; height:18px; accent-color:#ef4444;"> Busy / Rush Hour
                        </label>
                    </div>
                </div>
            </div>
            
            <div style="padding: 0 24px 24px; text-align: right;">
                <button type="submit" class="ts-btn" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Dispatch Task
                </button>
            </div>
        </form>
    </div>

    <?php
    // Set timezone for display
    $timezone = new DateTimeZone('Africa/Accra');
    
    function utcToLocal($utcTime, $timezone) {
        if (empty($utcTime) || $utcTime === '0000-00-00 00:00:00') {
            return '—';
        }
        try {
            $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
            $dt->setTimezone($timezone);
            return $dt->format('g:i A');
        } catch (Exception $e) {
            return '—';
        }
    }
    
    // Load tasks by status with service and size names
    $tasks = ['pending'=>[], 'started'=>[], 'awaiting_end'=>[], 'manual_closed'=>[]];
    if (empty($error)) {
        // Set timezone for the database session
        $conn->query("SET time_zone = '+00:00'");
        
        $query = "SELECT 
            wt.*, 
            w.full_name AS worker_name,
            s.name AS service_name,
            cs.name AS car_size_name,
            CONVERT_TZ(wt.planned_start, @@session.time_zone, '+00:00') as planned_start_utc,
            CONVERT_TZ(wt.planned_end, @@session.time_zone, '+00:00') as planned_end_utc,
            CONVERT_TZ(wt.created_at, @@session.time_zone, '+00:00') as created_at_utc,
            CONVERT_TZ(wt.started_at, @@session.time_zone, '+00:00') as started_at_utc
          FROM wash_tasks wt 
          LEFT JOIN workers w ON wt.worker_id = w.id 
          LEFT JOIN services s ON wt.service_id = s.id
          LEFT JOIN car_sizes cs ON wt.car_size_id = cs.id
          WHERE wt.status = 'pending'
          ORDER BY wt.created_at ASC";
        
        if ($rs = $conn->query($query)) {
            $rows = $rs->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) { 
                $r['service_name'] = $r['service_name'] ?? ('Service #' . $r['service_id']);
                $r['car_size_name'] = $r['car_size_name'] ?? ('Size #' . $r['car_size_id']);
                $tasks[$r['status']][] = $r; 
            }
        }
    }
    ?>

    <div class="ts-card">
        <div class="ts-card-header">
            <h2><i class="fas fa-hourglass-half" style="color:#d97706;"></i> Pending &amp; Queued</h2>
        </div>
        <div class="ts-table-wrap">
            <?php if (empty($tasks['pending'])): ?>
                <div class="ts-empty">
                    <i class="fas fa-check-double"></i>
                    <div class="ts-empty-title">Queue Empty</div>
                    <p>No tasks waiting to start.</p>
                </div>
            <?php else: ?>
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Washer</th>
                            <th>Service</th>
                            <th>Vehicle</th>
                            <th>Queued At</th>
                            <th>Est. Amount</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks['pending'] as $t): ?>
                        <tr>
                            <td style="font-weight:700;"><?php echo htmlspecialchars($t['worker_name'] ?? ('#'.$t['worker_id'])); ?></td>
                            <td><?php echo htmlspecialchars($t['service_name']); ?> <span style="color:#64748b;">(<?php echo htmlspecialchars($t['car_size_name']); ?>)</span></td>
                            <td>
                                <?php if ($t['number_plate'] && $t['number_plate'] !== 'N/A'): ?>
                                    <span class="ts-plate"><?php echo htmlspecialchars($t['number_plate']); ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-style:italic;">Not applicable</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color:#475569; font-weight:600;"><i class="far fa-clock" style="margin-right:4px;"></i> <?php echo utcToLocal($t['created_at_utc'], $timezone); ?></span>
                            </td>
                            <td class="ts-amount">
                                GHS <?php 
                                    $amt = isset($t['amount']) ? (float)$t['amount'] : 0.0;
                                    if ($amt <= 0) { $amt = fetch_price($conn, (int)$t['service_id'], (int)$t['car_size_id']); }
                                    echo number_format((float)$amt, 2);
                                ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="edit_task.php?id=<?php echo (int)$t['id']; ?>" class="ts-action-btn outline" style="color: #f59e0b; border-color: #f59e0b; padding: 8px 12px; margin-right: 4px;" title="Edit Task"><i class="fas fa-pencil-alt"></i></a>
                                <form id="complete-form-<?php echo (int)$t['id']; ?>" method="POST" style="margin:0; display:inline-block;">
                                    <input type="hidden" name="action" value="end_task">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="button" class="ts-action-btn success" onclick="WashHubConfirm({ title: 'Finalize Wash', message: 'Complete this task and log it?', type: 'success', onConfirm: () => { document.getElementById('complete-form-<?php echo (int)$t['id']; ?>').submit(); } });"><i class="fas fa-check-double"></i> Complete &amp; Log</button>
                                </form>
                                <form id="cancel-form-<?php echo (int)$t['id']; ?>" method="POST" style="margin:0; display:inline-block; margin-left: 4px;">
                                    <input type="hidden" name="action" value="cancel_task">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="button" class="ts-action-btn outline" style="color: #ef4444; border-color: #ef4444; padding: 8px 12px;" title="Cancel Task" onclick="WashHubConfirm({ title: 'Cancel Task?', message: 'Are you sure you want to remove this task from the queue?', type: 'danger', onConfirm: () => { document.getElementById('cancel-form-<?php echo (int)$t['id']; ?>').submit(); } });"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Pricing and form handling
(function(){
  // Get form elements
  const cat = document.querySelector('select[name="category_id"]');
  const serviceSelect = document.querySelector('select[name="service_id"]');
  const carSizeSelect = document.querySelector('select[name="car_size_id"]');
  const amountInput = document.getElementById('amount');
  const amountHelp = document.getElementById('amount_help');
  const plateGroup = document.getElementById('plate_group');
  
  if (!serviceSelect || !carSizeSelect || !amountInput) return;
  
  // Pricing configuration
  const pricing = {
    // Blowing service prices
    'blowing': {
      'small': 15,
      'medium': 20,
      'large': 25
    },
    // Default service prices
    'default': {
      'small': 20,
      'medium': 25,
      'large': 30
    }
  };

  // Function to check if service requires manual amount
  function requiresManualAmount(serviceName) {
    const manualServices = ['carpet', 'interior'];
    serviceName = serviceName.toLowerCase();
    // Check each part of a hyphenated service name
    const serviceParts = serviceName.split(/[-\s]+/);
    return serviceParts.some(part => 
      manualServices.some(manual => part.includes(manual))
    );
  }

  // Function to get car size key
  function getSizeKey(sizeName) {
    sizeName = sizeName.toLowerCase();
    if (sizeName.includes('small')) return 'small';
    if (sizeName.includes('large')) return 'large';
    return 'medium'; // Default to medium
  }

  // Calculate price for a single service part
  function calculateServicePrice(servicePart, sizeKey, categoryName = '') {
    // Always return 10 GHS for Motor category regardless of service or size
    if (categoryName && categoryName.includes('motor')) {
      return 10;
    }
    
    servicePart = servicePart.toLowerCase().trim();
    const serviceType = servicePart.includes('blowing') ? 'blowing' : 'default';
    return pricing[serviceType][sizeKey] || 0;
  }

  // Calculate and update amount
  function updateAmount() {
    const serviceId = serviceSelect.value;
    const serviceName = serviceSelect.options[serviceSelect.selectedIndex]?.text || '';
    const carSizeName = carSizeSelect.options[carSizeSelect.selectedIndex]?.text || '';
    const categoryName = cat?.options[cat.selectedIndex]?.text.toLowerCase() || '';
    
    if (!serviceId || !carSizeName) return;

    // Check for Motor category first
    if (categoryName.includes('motor')) {
      amountInput.value = '10.00';
      amountInput.setAttribute('readonly', 'readonly');
      amountHelp.textContent = 'Fixed amount for Motor category: 10 GHS';
      return;
    }

    // Enable manual amount for 'Carpets' category or if service requires it
    if (categoryName.includes('carpet') || requiresManualAmount(serviceName)) {
      amountInput.removeAttribute('readonly');
      amountInput.value = '';
      amountInput.placeholder = 'Enter amount';
      amountHelp.textContent = 'Please enter the amount manually for this service';
      return;
    }

    // Get size key once
    const sizeKey = getSizeKey(carSizeName);
    
    // Split service name by hyphens or spaces and calculate total amount
    const serviceParts = serviceName.split(/[-\s]+/).filter(part => part.trim() !== '');
    
    if (serviceParts.length === 0) return;
    
    let totalAmount = 0;
    let serviceDetails = [];
    
    // Calculate price for each service part
    serviceParts.forEach(part => {
      const price = calculateServicePrice(part, sizeKey);
      totalAmount += price;
      if (price > 0) {
        serviceDetails.push(`${part.trim()} (${price} GHS)`);
      }
    });
    
    if (totalAmount > 0) {
      amountInput.value = totalAmount.toFixed(2);
      amountInput.setAttribute('readonly', 'readonly');
      amountHelp.textContent = `Calculated amount: ${serviceDetails.join(' + ')} = ${totalAmount} GHS`;
    } else {
      amountInput.removeAttribute('readonly');
      amountInput.value = '';
      amountInput.placeholder = 'Enter amount';
      amountHelp.textContent = 'Could not calculate amount. Please enter manually.';
    }
  }

  // Toggle license plate visibility based on category
  function togglePlateVisibility() {
    if (!cat || !plateGroup) return;
    const categoryName = cat.options[cat.selectedIndex]?.text.toLowerCase().trim();
    const isCarpet = (categoryName === 'carpets' || categoryName === 'carpet');
    plateGroup.style.display = isCarpet ? 'none' : 'block';
    const plateInput = plateGroup.querySelector('input[name="number_plate"]');
    if (plateInput) plateInput.required = !isCarpet;
  }

  // Event listeners
  serviceSelect?.addEventListener('change', updateAmount);
  carSizeSelect?.addEventListener('change', updateAmount);
  cat?.addEventListener('change', togglePlateVisibility);
  
  // Initial setup
  togglePlateVisibility();
  updateAmount();
})();
</script>

<?php include 'includes/footer.php'; ?>
