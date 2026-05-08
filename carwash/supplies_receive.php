<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/supplies_common.php';

// Only admins and superadmins can operate
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin','superadmin'])) { http_response_code(403); echo 'Forbidden'; exit; }

$omoId = supplies_bootstrap($conn);
$userId = (int)$_SESSION['user_id'];

// Embed mode: when true, do not include header/footer or outer container
$isEmbed = (isset($_GET['embed']) && (int)$_GET['embed'] === 1);

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty = max(0, (int)($_POST['qty_received'] ?? 0));
    $now = date('Y-m-d H:i:s');

    if ($qty <= 0) {
        $err = 'Please enter a valid quantity received.';
    } else {
        // prevent overlapping open periods
        $open = get_current_open_period($conn, $userId, $omoId);
        if ($open) {
            $err = 'You already have an open OMO period started at ' . htmlspecialchars($open['start_at']) . '. Close it by making a request before starting a new one.';
        } else {
            if ($stmt = $conn->prepare("INSERT INTO supplies_periods (admin_user_id, item_id, start_at, qty_received, created_at) VALUES (?,?,?,?,NOW())")) {
                $stmt->bind_param('iisi', $userId, $omoId, $now, $qty);
                if ($stmt->execute()) {
                    $msg = 'OMO received confirmed. Tracking started.';
                } else {
                    $err = 'Database error starting period: ' . $conn->error;
                }
                $stmt->close();
            } else {
                $err = 'Failed to prepare statement.';
            }
        }
    }
}

if (!$isEmbed) { include 'includes/header.php'; }
?>
<?php if (!$isEmbed): ?>
<div class="container">
  <h1>Receive OMO</h1>
<?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-error"><?php echo $err; ?></div>
  <?php endif; ?>
  <?php if ($msg): ?>
    <div class="alert alert-success"><?php echo $msg; ?></div>
  <?php endif; ?>

  <div class="card" style="max-width:480px;">
    <form method="post">
      <div style="margin-bottom:12px;">
        <label for="qty_received" style="display:block; font-weight:600; margin-bottom:6px;">Quantity Received (bags)</label>
        <input type="number" id="qty_received" name="qty_received" min="1" step="1" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" />
      </div>
      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn">Confirm Receive & Start Tracking</button>
        <a class="btn btn-secondary" href="dashboard.php">Cancel</a>
      </div>
    </form>
  </div>

  <?php $open = get_current_open_period($conn, $userId, $omoId); if ($open): ?>
    <div class="card" style="margin-top:16px;">
      <h3>Current OMO Period</h3>
      <div>Started: <?php echo htmlspecialchars($open['start_at']); ?></div>
      <div>Qty Received: <?php echo (int)$open['qty_received']; ?> bags</div>
      <div style="color:#777;">End this period by requesting OMO.</div>
    </div>
  <?php endif; ?>
<?php if (!$isEmbed): ?>
</div>
<?php include 'includes/footer.php'; ?>
<?php endif; ?>
