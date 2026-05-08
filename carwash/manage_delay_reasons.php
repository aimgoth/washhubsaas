<?php
require_once 'config/session.php';
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'superadmin') { http_response_code(403); echo 'Access denied. Super Admin only.'; exit; }

$errors = [];
$messages = [];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($action === 'add' && $reason !== '') {
        $stmt = $conn->prepare('INSERT INTO delay_reasons (reason) VALUES (?)');
        $stmt->bind_param('s', $reason);
        if ($stmt->execute()) { $messages[] = 'Delay reason added.'; } else { $errors[] = 'Insert failed: ' . $conn->error; }
    } elseif ($action === 'update' && $id > 0 && $reason !== '') {
        $stmt = $conn->prepare('UPDATE delay_reasons SET reason = ? WHERE id = ?');
        $stmt->bind_param('si', $reason, $id);
        if ($stmt->execute()) { $messages[] = 'Delay reason updated.'; } else { $errors[] = 'Update failed: ' . $conn->error; }
    } elseif ($action === 'toggle_status') {
        $stmt = $conn->prepare('UPDATE delay_reasons SET is_active = !is_active WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) { $messages[] = 'Status updated.'; } else { $errors[] = 'Status update failed: ' . $conn->error; }
    }
}

$reasons = $conn->query('SELECT id, reason, is_active FROM delay_reasons ORDER BY reason')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Delay Reasons</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;margin:0;padding:20px}
    .container{max-width:800px;margin:0 auto}
    .card{background:#fff;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,.05);padding:20px;margin-bottom:16px}
    .header{display:flex;justify-content:space-between;align-items:center}
    .btn{background:#2a5298;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;}
    .btn.toggle{background:#f59e0b;}
    .btn.toggle.inactive{background:#9ca3af;}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
    .msg{padding:10px;border-radius:8px;margin:6px 0}
    .ok{background:#ecfdf5;border-left:4px solid #10b981}
    .err{background:#fef2f2;border-left:4px solid #ef4444}
    input{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px}
    form.inline{display:inline}
  </style>
</head>
<body>
  <div class="container">
    <div class="card header">
      <h2><i class="fas fa-clock"></i> Manage Delay Reasons</h2>
    </div>

    <?php foreach ($messages as $m): ?><div class="card msg ok"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $e): ?><div class="card msg err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="card">
      <h3>Add New Reason</h3>
      <form method="post">
        <input type="hidden" name="action" value="add">
        <input type="text" name="reason" placeholder="e.g., Excessive dirt on vehicle" required>
        <div style="margin-top:10px"><button class="btn" type="submit"><i class="fas fa-plus"></i> Add Reason</button></div>
      </form>
    </div>

    <div class="card">
      <h3>Existing Reasons</h3>
      <table>
        <thead><tr><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($reasons as $r): ?>
          <tr>
            <td>
              <form method="post" class="inline">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="text" name="reason" value="<?= htmlspecialchars($r['reason']) ?>" required>
                <button type="submit" class="btn" style="margin-top:5px;"><i class="fas fa-save"></i> Update</button>
              </form>
            </td>
            <td><span style="color:<?= $r['is_active'] ? '#10b981' : '#ef4444' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td>
              <form method="post" class="inline">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn toggle <?= $r['is_active'] ? '' : 'inactive' ?>"><i class="fas fa-power-off"></i> Toggle</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
  </div>
</body>
</html>
