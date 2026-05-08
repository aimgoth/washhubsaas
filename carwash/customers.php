<?php
session_start();

// Restrict to admin or superadmin (visible mainly to admin via nav)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';

// Create table if not exists (customers)
$conn->query("CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  service_type VARCHAR(255) NOT NULL,
  washer_name VARCHAR(255) NOT NULL,
  contact_number VARCHAR(50) NOT NULL,
  expected_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(expected_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$errors = [];
$success = '';

// Add new customer row
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $full_name = trim($_POST['full_name'] ?? '');
    $service_type = trim($_POST['service_type'] ?? '');
    $washer_name = trim($_POST['washer_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $expected_date = trim($_POST['expected_date'] ?? '');

    if ($full_name === '') { $errors[] = 'Full name is required.'; }
    if ($service_type === '') { $errors[] = 'Service type is required.'; }
    if ($washer_name === '') { $errors[] = 'Washer name is required.'; }
    if ($contact_number === '') { $errors[] = 'Contact number is required.'; }
    if ($expected_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_date)) { $errors[] = 'Valid date is required (YYYY-MM-DD).'; }

    if (!$errors) {
        $stmt = $conn->prepare("INSERT INTO customers (full_name, service_type, washer_name, contact_number, expected_date) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss', $full_name, $service_type, $washer_name, $contact_number, $expected_date);
        if ($stmt->execute()) {
            header('Location: customers.php?added=1');
            exit;
        } else {
            $errors[] = 'Failed to add customer: ' . $conn->error;
        }
    }
}

// Confirm (delete) customer row once serviced/picked up
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            header('Location: customers.php?confirmed=1');
            exit;
        } else {
            $errors[] = 'Failed to confirm customer: ' . $conn->error;
        }
    } else {
        $errors[] = 'Invalid customer ID.';
    }
}

// Fetch customers (pending) with optional search on customer or washer name
$list = [];
$search = trim($_GET['s'] ?? '');
$view = $_GET['view'] ?? 'list';
$show_form = ($view === 'add');
if ($search !== '') {
    $like = '%' . $search . '%';
    $sql = 'SELECT id, full_name, service_type, washer_name, contact_number, expected_date, created_at
            FROM customers
            WHERE full_name LIKE ? OR washer_name LIKE ?
            ORDER BY expected_date ASC, id DESC';
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $list[] = $row; }
    }
} else {
    if ($res = $conn->query('SELECT id, full_name, service_type, washer_name, contact_number, expected_date, created_at FROM customers ORDER BY expected_date ASC, id DESC')) {
        while ($row = $res->fetch_assoc()) { $list[] = $row; }
    }
}

$workers = [];
// Load active workers for dropdown
if ($stmtW = $conn->prepare("SELECT full_name FROM workers WHERE status = 'active' ORDER BY full_name ASC")) {
    $stmtW->execute();
    $resW = $stmtW->get_result();
    while ($r = $resW->fetch_assoc()) { $workers[] = $r['full_name']; }
}

$page_title = 'Customers';
include 'includes/header.php';
?>

<style>
    .cus-page { max-width: 1200px; margin: 36px auto; padding: 0 20px 60px; }

    .cus-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .cus-title-left { display: flex; align-items: center; gap: 14px; }
    .cus-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .cus-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .cus-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .cus-alert { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 12px; font-size: 0.92rem; font-weight: 600; margin-bottom: 20px; }
    .cus-alert.success { background: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; }
    .cus-alert.error { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    .cus-btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px; border-radius: 10px; font-weight: 700; font-size: 0.92rem;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff;
        text-decoration: none; border: none; cursor: pointer; transition: all .2s;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .cus-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
    
    .cus-btn-outline {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 9px 18px; border-radius: 10px; font-weight: 700; font-size: 0.92rem;
        background: #fff; color: #1e293b; border: 1.5px solid #cbd5e1; text-decoration: none; cursor: pointer; transition: all .2s;
    }
    .cus-btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }

    .cus-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 16px rgba(0,0,0,0.07); overflow: hidden; margin-bottom: 24px; }
    
    /* Form Styles */
    .cus-form-card { max-width: 500px; margin: 0 auto; padding: 30px; }
    .cus-form-group { margin-bottom: 20px; }
    .cus-form-group label { display: block; font-size: 0.88rem; font-weight: 700; color: #475569; margin-bottom: 8px; }
    .cus-form-input { 
        width: 100%; padding: 12px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px;
        font-size: 0.95rem; color: #1e293b; background: #fff; transition: all .2s; box-sizing: border-box;
    }
    .cus-form-input:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.1); outline: none; }

    /* Table Styles */
    .cus-table-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; background: #f8faff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .cus-search { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .cus-search-input { padding: 9px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 0.9rem; min-width: 250px; outline: none; }
    .cus-search-input:focus { border-color: #00AEEF; }
    
    .cus-table-wrap { overflow-x: auto; }
    .cus-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .cus-table thead tr { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .cus-table th { padding: 16px 20px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; text-align: left; color: #475569; white-space: nowrap; }
    .cus-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
    .cus-table tbody tr:hover td { background: #f8faff; }
    .cus-table tbody tr:last-child td { border-bottom: none; }

    .cus-name { font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px; }
    .cus-avatar { 
        width: 36px; height: 36px; border-radius: 50%; 
        background: linear-gradient(135deg, #e2e8f0, #cbd5e1); 
        display: flex; align-items: center; justify-content: center; 
        font-weight: 800; color: #475569; font-size: 0.9rem; flex-shrink: 0;
    }
    .cus-contact { color: #64748b; font-family: monospace; font-size: 0.85rem; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; display: inline-block; }
    
    .cus-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; background: #e0e7ff; color: #4338ca; }
    
    .cus-btn-success {
        padding: 6px 14px; border-radius: 8px; font-weight: 700; font-size: 0.8rem;
        background: #10b981; color: #fff; border: none; cursor: pointer; transition: all .2s;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .cus-btn-success:hover { background: #059669; transform: translateY(-1px); }
</style>

<div class="cus-page">
    
    <div class="cus-title">
        <div class="cus-title-left">
            <div class="cus-title-icon"><i class="fas fa-user-friends"></i></div>
            <div>
                <h1>Customers Log</h1>
                <p>Manage pending drop-offs, carpets, and pickups.</p>
            </div>
        </div>
        <?php if ($show_form): ?>
            <a href="customers.php" class="cus-btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
        <?php else: ?>
            <a href="customers.php?view=add" class="cus-btn-primary"><i class="fas fa-user-plus"></i> Add Customer</a>
        <?php endif; ?>
    </div>
    
    <?php if (isset($_GET['added'])): ?>
        <div class="cus-alert success"><i class="fas fa-check-circle"></i> <div>Customer added successfully!</div></div>
    <?php endif; ?>
    <?php if (isset($_GET['confirmed'])): ?>
        <div class="cus-alert success"><i class="fas fa-check-circle"></i> <div>Customer confirmed and removed from pending log.</div></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="cus-alert error"><i class="fas fa-exclamation-triangle"></i> <div><?php echo htmlspecialchars($e); ?></div></div>
    <?php endforeach; ?>

    <?php if ($show_form): ?>
        <div class="cus-card cus-form-card">
            <h2 style="margin: 0 0 20px; font-size: 1.25rem; color: #1e293b; font-weight: 800; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;"><i class="fas fa-address-card" style="color:#00AEEF; margin-right:8px;"></i> Customer Details</h2>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="add" />
                
                <div class="cus-form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required class="cus-form-input" placeholder="e.g. Jane Doe" />
                </div>
                
                <div class="cus-form-group">
                    <label>Service Type</label>
                    <input type="text" name="service_type" required class="cus-form-input" placeholder="e.g. Carpet Wash (3 large)" />
                </div>
                
                <div class="cus-form-group">
                    <label>Assigned Washer</label>
                    <select name="washer_name" required class="cus-form-input">
                        <option value="" disabled selected>Select assigned worker...</option>
                        <?php foreach ($workers as $wname): ?>
                            <option value="<?php echo htmlspecialchars($wname); ?>"><?php echo htmlspecialchars($wname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="cus-form-group">
                    <label>Contact Number</label>
                    <input type="tel" name="contact_number" required class="cus-form-input" placeholder="e.g. 0501234567" />
                </div>
                
                <div class="cus-form-group">
                    <label>Expected Completion Date</label>
                    <input type="date" name="expected_date" required class="cus-form-input" />
                </div>
                
                <div style="margin-top: 30px;">
                    <button type="submit" class="cus-btn-primary" style="width: 100%; justify-content: center; padding: 14px;"><i class="fas fa-plus"></i> Save Customer</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="cus-card">
            <div class="cus-table-header">
                <h2 style="margin:0; font-size: 1.05rem; font-weight: 700; color: #1e293b;"><i class="fas fa-clock" style="color:#00AEEF; margin-right:8px;"></i> Pending Pickups / Deliveries</h2>
                <form method="get" class="cus-search">
                    <input type="text" name="s" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name or washer..." class="cus-search-input" />
                    <button type="submit" class="cus-btn-primary" style="padding: 9px 18px;"><i class="fas fa-search"></i> Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="customers.php" class="cus-btn-outline">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <div class="cus-table-wrap">
                <table class="cus-table">
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Contact</th>
                            <th>Service</th>
                            <th>Worker</th>
                            <th>Target Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($list)): ?>
                            <tr>
                                <td colspan="6" style="padding: 60px 20px; text-align: center; color: #94a3b8;">
                                    <i class="fas fa-check-double" style="font-size:3rem; margin-bottom:16px; opacity:0.5; display:block;"></i>
                                    <div style="font-size: 1.1rem; font-weight: 600; color:#475569;">All Caught Up!</div>
                                    <p style="margin-top: 5px;">There are no pending customer pickups at this time.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($list as $row): ?>
                                <tr>
                                    <td>
                                        <div class="cus-name">
                                            <div class="cus-avatar"><?php echo strtoupper(substr($row['full_name'], 0, 1)); ?></div>
                                            <div>
                                                <?php echo htmlspecialchars($row['full_name']); ?>
                                                <div style="font-size: 0.75rem; color: #94a3b8; font-weight: normal; margin-top: 2px;">Added <?php echo date('M j, g:i A', strtotime($row['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="cus-contact"><i class="fas fa-phone-alt" style="font-size: 0.7rem; margin-right: 4px; color: #94a3b8;"></i> <?php echo htmlspecialchars($row['contact_number']); ?></span></td>
                                    <td><span class="cus-badge"><?php echo htmlspecialchars($row['service_type']); ?></span></td>
                                    <td style="font-weight: 600; color: #475569;"><?php echo htmlspecialchars($row['washer_name']); ?></td>
                                    <td><i class="far fa-calendar-alt" style="color: #64748b; margin-right: 5px;"></i> <?php echo date('M j, Y', strtotime($row['expected_date'])); ?></td>
                                    <td style="text-align: right;">
                                        <form method="post" data-confirm-title="Confirm Delivery" data-confirm="Confirm service delivered/picked up? This will remove the entry from the pending log." data-confirm-type="success" style="margin: 0;">
                                            <input type="hidden" name="action" value="confirm" />
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>" />
                                            <button type="submit" class="cus-btn-success" title="Mark as Delivered">
                                                <i class="fas fa-check"></i> Complete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
