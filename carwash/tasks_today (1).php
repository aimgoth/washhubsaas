<?php
require_once 'config/session.php';
session_start();

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
            $assigned_by = $_SESSION['full_name'] ?? 'Admin';
            
            // Get service duration for planned end time
            $duration_minutes = 0;
            $stmt_dur = $conn->prepare('SELECT duration_minutes FROM service_durations WHERE service_id = ? AND car_size_id = ?');
            $stmt_dur->bind_param('ii', $service_id, $car_size_id);
            $stmt_dur->execute();
            $res_dur = $stmt_dur->get_result();
            if ($row_dur = $res_dur->fetch_assoc()) {
                $duration_minutes = (int)$row_dur['duration_minutes'];
            }
            
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
        if ($action === 'start_task') {
            $task_id = (int)($_POST['task_id'] ?? 0);
            if ($task_id <= 0) throw new Exception('Invalid task ID');

            // Fetch task details to get service and car size
            $stmt_task = $conn->prepare("SELECT service_id, car_size_id FROM wash_tasks WHERE id = ?");
            $stmt_task->bind_param('i', $task_id);
            $stmt_task->execute();
            $task_res = $stmt_task->get_result();
            if (!($task = $task_res->fetch_assoc())) {
                throw new Exception('Task not found.');
            }

            // Fetch service duration
            $duration_minutes = 0;
            $stmt_dur = $conn->prepare('SELECT duration_minutes FROM service_durations WHERE service_id = ? AND car_size_id = ?');
            $stmt_dur->bind_param('ii', $task['service_id'], $task['car_size_id']);
            $stmt_dur->execute();
            $res_dur = $stmt_dur->get_result();
            if ($row_dur = $res_dur->fetch_assoc()) {
                $duration_minutes = (int)$row_dur['duration_minutes'];
            }

            // Calculate planned end time
            $startTime = new DateTime();
            $plannedEndTime = null;
            if ($duration_minutes > 0) {
                $plannedEndTime = (clone $startTime)->add(new DateInterval('PT' . $duration_minutes . 'M'));
            }

            // Update task with start time, planned end, and status
            $stmt = $conn->prepare("UPDATE wash_tasks SET started_at = ?, planned_end = ?, status = 'started' WHERE id = ? AND status = 'pending'");
            $formattedStartTime = $startTime->format('Y-m-d H:i:s');
            $formattedPlannedEndTime = $plannedEndTime ? $plannedEndTime->format('Y-m-d H:i:s') : null;
            $stmt->bind_param('ssi', 
                $formattedStartTime, 
                $formattedPlannedEndTime, 
                $task_id
            );
            $stmt->execute();
            $_SESSION['flash_success'] = 'Task started.';
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
            if (empty($task['started_at'])) throw new Exception('Task has not been started');

            $now = date('Y-m-d H:i:s');
            // Get the planned duration from the service_durations table instead of calculating
            $duration_minutes = 0;
            $stmt_dur = $conn->prepare('SELECT duration_minutes FROM service_durations WHERE service_id = ? AND car_size_id = ?');
            $stmt_dur->bind_param('ii', $task['service_id'], $task['car_size_id']);
            $stmt_dur->execute();
            $res_dur = $stmt_dur->get_result();
            if ($row_dur = $res_dur->fetch_assoc()) {
                $duration_minutes = (int)$row_dur['duration_minutes'];
            }
            
            // Calculate foul minutes based on planned end time vs actual end time
            $planned_end = $task['planned_end'];
            $foul_overrun_minutes = 0;
            if ($planned_end) {
                $planned_end_ts = strtotime($planned_end);
                $now_ts = strtotime($now);
                $foul_overrun_minutes = max(0, ceil(($now_ts - $planned_end_ts) / 60));
            }
            $is_foul = $foul_overrun_minutes > 0 ? 1 : 0;

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
                $update_task = $conn->prepare("UPDATE wash_tasks SET status = 'manual_closed' WHERE id = ? AND status = 'started'");
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

            // Timing fields
            $fields[]='planned_start'; $types.='s'; $vals[]=$task['planned_start'];
            $fields[]='planned_end';   $types.='s'; $vals[]=$task['planned_end'];
            $fields[]='started_at';    $types.='s'; $vals[]=$task['started_at'];
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

                // Also insert into generator_washes table
                $gen_fields = [
                    'fuel_purchase_id' => 1, // Default or get from session
                    'wash_type' => 'car',
                    'service_id' => (int)$task['service_id'],
                    'category_id' => (int)($task['category_id'] ?? 0),
                    'car_size_id' => (int)$task['car_size_id'],
                    'amount' => $amount,
                    'number_plate' => $task['number_plate'],
                    'worker_id' => (int)$task['worker_id'],
                    'created_by' => (int)$_SESSION['user_id']
                ];

                // Build the generator_washes insert query
                $gen_columns = [];
                $gen_values = [];
                $gen_types = '';
                $gen_params = [];

                foreach ($gen_fields as $field => $value) {
                    $gen_columns[] = $field;
                    $gen_values[] = '?';
                    $gen_types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
                    $gen_params[] = $value;
                }

                $gen_sql = "INSERT INTO generator_washes (" . implode(',', $gen_columns) . ", created_at) 
                           VALUES (" . implode(',', $gen_values) . ", NOW())";
                $gen_stmt = $conn->prepare($gen_sql);
                $gen_stmt->bind_param($gen_types, ...$gen_params);
                if (!$gen_stmt->execute()) {
                    throw new Exception('Failed to insert generator_washes record: ' . $conn->error);
                }
                
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
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'Tasks Today';
include 'includes/header.php';
?>

<div class="container">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
      <h2 style="margin:0;">Assign & Track Tasks</h2>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="end_tasks.php" class="btn btn-outline"><i class="fas fa-flag-checkered"></i> End Tasks</a>
        <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
      <?php endif; ?>

      <form method="POST" class="task-form form-grid">
        <input type="hidden" name="action" value="create_task">
        <div class="form-group">
          <label>Service <span class="required">*</span></label>
          <select name="service_id" required>
            <option value="">Select Service</option>
            <?php foreach ($services as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($cwHasCategory): ?>
        <div class="form-group">
          <label>Category <span class="required">*</span></label>
          <select name="category_id" required>
            <option value="">Select Category</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label>Size <span class="required">*</span></label>
          <select name="car_size_id" required>
            <option value="">Select Size</option>
            <?php foreach ($car_sizes as $cs): ?>
              <option value="<?php echo (int)$cs['id']; ?>"><?php echo htmlspecialchars($cs['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
            <label>Amount <span class="required">*</span></label>
            <input type="number" name="amount" id="amount" step="0.01" min="0" placeholder="0.00" required readonly>
            <small id="amount_help" class="text-muted">Amount will be calculated automatically</small>
        </div>
        <script src="js/task-pricing.js"></script>
        <div class="form-group" id="plate_group">
          <label>License Plate <span class="required">*</span></label>
          <input type="text" name="number_plate" placeholder="e.g., KAA-123-A or KAA 123A" pattern="[A-Z0-9 -]{3,12}" title="Enter a valid license plate (e.g., KAA-123-A or KAA 123A)">
          <small>Hidden if Category is Carpets.</small>
        </div>
        <div class="form-group">
          <label>Workload Level <span class="required">*</span></label>
          <select name="workload_level" required>
            <option value="normal" selected>Normal</option>
            <option value="busy">Busy</option>
          </select>
        </div>

        <div class="form-group">
          <label>Washer <span class="required">*</span></label>
          <select name="worker_id" required>
            <option value="">Select Washer</option>
            <?php foreach ($workers as $w): ?>
              <option value="<?php echo (int)$w['id']; ?>"><?php echo htmlspecialchars($w['full_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <!-- Link to Generator Wash Page -->
        <div class="form-group">
            <label>Power Outage?</label>
            <a href="add_generator_wash.php" class="btn btn-warning" style="text-decoration: none;">
                <i class="fas fa-bolt"></i> Switch to Generator Wash Form
            </a>
            <small class="text-muted">Use this if you are running on generator power.</small>
        </div>
        <div class="form-actions full-width">
          <button type="submit" class="btn" id="submitBtn">
            <i class="fas fa-plus"></i> Create Task
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php
  // Set timezone for display
  $timezone = new DateTimeZone('Africa/Accra');
  
  // Function to safely convert UTC to local time
  function utcToLocal($utcTime, $timezone) {
      if (empty($utcTime) || $utcTime === '0000-00-00 00:00:00') {
          return 'N/A';
      }
      try {
          $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
          $dt->setTimezone($timezone);
          return $dt->format('g:i A');
      } catch (Exception $e) {
          error_log("Error converting time: " . $e->getMessage());
          return 'N/A';
      }
  }
  
  // Load tasks by status with service and size names
  $tasks = ['pending'=>[], 'started'=>[], 'awaiting_end'=>[], 'manual_closed'=>[]];
  if (empty($error)) {
      // Set timezone for the database session
      $conn->query("SET time_zone = '+00:00'");  // Ensure we're working with UTC
      
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
        WHERE wt.status IN ('pending','started','awaiting_end') 
        ORDER BY wt.planned_start ASC";
      
      if ($rs = $conn->query($query)) {
          $rows = $rs->fetch_all(MYSQLI_ASSOC);
          foreach ($rows as $r) { 
              // Ensure service and size names are set, fallback to IDs if not
              $r['service_name'] = $r['service_name'] ?? ('Service #' . $r['service_id']);
              $r['car_size_name'] = $r['car_size_name'] ?? ('Size #' . $r['car_size_id']);
              $tasks[$r['status']][] = $r; 
          }
      }
  }
  ?>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;">Pending Tasks</h3></div>
    <div class="card-body">
      <?php if (empty($tasks['pending'])): ?>
        <div style="color:#666;">No pending tasks.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Washer</th><th>Service</th><th>Plate</th><th>Start/End</th><th>Amount</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($tasks['pending'] as $t): ?>
            <tr>
              <td><?php echo htmlspecialchars($t['worker_name'] ?? ('#'.$t['worker_id'])); ?></td>
              <td><?php echo htmlspecialchars($t['service_name']); ?> (<?php echo htmlspecialchars($t['car_size_name']); ?>)</td>
              <td><?php echo htmlspecialchars($t['number_plate']); ?></td>
              <td>
                <?php 
                $startTime = utcToLocal($t['planned_start'], $timezone);
                $endTime = utcToLocal($t['planned_end'], $timezone);
                echo htmlspecialchars($startTime . ' → ' . $endTime);
                ?>
              </td>
              <td>
                <?php 
                  $amt = isset($t['amount']) ? (float)$t['amount'] : 0.0;
                  if ($amt <= 0) { $amt = fetch_price($conn, (int)$t['service_id'], (int)$t['car_size_id']); }
                  echo number_format((float)$amt, 2);
                ?>
              </td>
              <td>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="start_task">
                  <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                  <button class="btn"><i class="fas fa-play"></i> Start Task</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;">In Progress</h3></div>
    <div class="card-body">
      <?php if (empty($tasks['started'])): ?>
        <div style="color:#666;">No in-progress tasks.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Washer</th><th>Service</th><th>Plate</th><th>Start</th><th>End</th><th>Amount</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($tasks['started'] as $t): ?>
            <tr>
              <td><?php echo htmlspecialchars($t['worker_name'] ?? ('#'.$t['worker_id'])); ?></td>
              <td><?php echo htmlspecialchars($t['service_name']); ?> (<?php echo htmlspecialchars($t['car_size_name']); ?>)</td>
              <td><?php echo htmlspecialchars($t['number_plate']); ?></td>
              <td><?php echo utcToLocal($t['started_at'], $timezone); ?></td>
              <td><?php echo utcToLocal($t['planned_end'], $timezone); ?></td>
              <td>
                <?php 
                  $amt = isset($t['amount']) ? (float)$t['amount'] : 0.0;
                  if ($amt <= 0) { $amt = fetch_price($conn, (int)$t['service_id'], (int)$t['car_size_id']); }
                  echo number_format((float)$amt, 2);
                ?>
              </td>
              <td>
                <a href="complete_task.php?id=<?php echo (int)$t['id']; ?>" class="btn btn-outline"><i class="fas fa-flag-checkered"></i> End Task</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.table { width:100%; border-collapse: collapse; }
.table th, .table td { padding:10px; border-bottom:1px solid #eee; text-align:left; }

/* Form layout: stacked by default, responsive columns on larger screens */
.form-grid { display:grid; grid-template-columns: 1fr; gap:14px; }
@media (min-width: 640px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .form-grid { grid-template-columns: repeat(3, 1fr); } }
.form-group.full-width, .form-actions { margin-top: 20px; text-align: right; }


/* Form styling */
.task-form { background:#f7f9fc; padding:18px; border-radius:10px; border:1px solid #e5e9f2; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
.task-form label { display:block; margin-bottom:6px; font-weight:600; color:#2c3e50; }
.task-form input[type="text"],
.task-form input[type="number"],
.task-form input[type="datetime-local"],
.task-form select { width:100%; padding:10px 12px; border:1px solid #ccd6e0; border-radius:6px; background:#fff; color:#2c3e50; font-size:14px; }
.task-form input::placeholder { color:#9aa6b2; }
.task-form small { display:block; margin-top:6px; color:#6b7280; }

/* Buttons and alerts */
.btn { display:inline-flex; align-items:center; gap:6px; padding:10px 14px; border-radius:6px; border:1px solid var(--primary-color, #2c3e50); background:var(--primary-color, #2c3e50); color:#fff; text-decoration:none; cursor:pointer; font-size:14px; }
.btn:hover { filter: brightness(1.05); }
.btn-outline { background:#fff; color:var(--primary-color, #2c3e50); border-color:var(--primary-color, #2c3e50); }
.alert { padding:12px 15px; border-radius:4px; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }

/* Make tables horizontally scrollable on small screens */
.card-body { overflow-x:auto; }

/* Responsive typography and control sizes */
@media (max-width: 640px) {
  body { font-size:16px; }
  .task-form label { font-size:15px; }
  .task-form input[type="text"],
  .task-form input[type="number"],
  .task-form input[type="datetime-local"],
  .task-form select { font-size:15px; padding:12px 14px; }
  .btn { font-size:15px; padding:12px 16px; }
}
@media (min-width: 641px) and (max-width: 1024px) {
  body { font-size:15px; }
  .task-form label { font-size:14.5px; }
  .task-form input[type="text"],
  .task-form input[type="number"],
  .task-form input[type="datetime-local"],
  .task-form select { font-size:14.5px; }
  .btn { font-size:14.5px; }
}
</style>

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
