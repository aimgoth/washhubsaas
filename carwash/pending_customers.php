<?php
session_start();

// Restrict to admin or superadmin (visible mainly to admin via nav)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS pending_customers (
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

// Add new pending customer
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
        $stmt = $conn->prepare("INSERT INTO pending_customers (full_name, service_type, washer_name, contact_number, expected_date) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss', $full_name, $service_type, $washer_name, $contact_number, $expected_date);
        if ($stmt->execute()) {
            $success = 'Customer added successfully';
            header('Location: pending_customers.php?added=1');
            exit;
        } else {
            $errors[] = 'Failed to add customer: ' . $conn->error;
        }
    }
}

// Confirm (delete) pending customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM pending_customers WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            header('Location: pending_customers.php?confirmed=1');
            exit;
        } else {
            $errors[] = 'Failed to confirm customer: ' . $conn->error;
        }
    } else {
        $errors[] = 'Invalid customer ID.';
    }
}

// Fetch all pending customers (newest first, show earliest expected first at top can be by date asc then id desc)
$list = [];
if ($res = $conn->query('SELECT id, full_name, service_type, washer_name, contact_number, expected_date, created_at FROM pending_customers ORDER BY expected_date ASC, id DESC')) {
    while ($row = $res->fetch_assoc()) { $list[] = $row; }
}

$page_title = 'Pending Customers';
include 'includes/header.php';
?>

<div class="container">
  <?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">Customer added successfully.</div>
  <?php endif; ?>
  <?php if (isset($_GET['confirmed'])): ?>
    <div class="alert alert-success">Customer confirmed and removed.</div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
  <?php endforeach; ?>

  <div class="card" style="margin-top: 12px;">
    <h2>Add Pending Customer</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="add" />
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
        <div>
          <label>Full Name</label>
          <input type="text" name="full_name" required style="width:100%; padding:8px;" />
        </div>
        <div>
          <label>Service Type</label>
          <input type="text" name="service_type" required style="width:100%; padding:8px;" placeholder="e.g., Carpet Wash" />
        </div>
        <div>
          <label>Washer Name</label>
          <input type="text" name="washer_name" required style="width:100%; padding:8px;" />
        </div>
        <div>
          <label>Contact Number</label>
          <input type="text" name="contact_number" required style="width:100%; padding:8px;" />
        </div>
        <div>
          <label>Expected Date</label>
          <input type="date" name="expected_date" required style="width:100%; padding:8px;" />
        </div>
      </div>
      <div style="margin-top:12px;">
        <button type="submit" class="btn">Add Customer</button>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top: 16px;">
    <h2>Pending Customers</h2>
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Full Name</th>
            <th>Service</th>
            <th>Washer</th>
            <th>Contact</th>
            <th>Expected Date</th>
            <th>Added</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($list)): ?>
            <tr><td colspan="8" style="padding:10px; color:#666;">No pending customers.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $row): ?>
              <tr>
                <td><?php echo (int)$row['id']; ?></td>
                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                <td><?php echo htmlspecialchars($row['service_type']); ?></td>
                <td><?php echo htmlspecialchars($row['washer_name']); ?></td>
                <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                <td><?php echo date('M j, Y', strtotime($row['expected_date'])); ?></td>
                <td><?php echo date('M j, Y H:i', strtotime($row['created_at'])); ?></td>
                <td>
                  <form method="post" data-confirm-title="Confirm Pickup" data-confirm="Confirm service delivered and remove this entry?" data-confirm-type="success">
                    <input type="hidden" name="action" value="confirm" />
                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>" />
                    <button type="submit" class="btn btn-success">Confirm</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
