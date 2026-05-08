<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/config/database.php';

$page_title = 'Suppliers Directory';

// Embed mode: when true, avoid including admin header/footer and outer layout
$isEmbed = (isset($_GET['embed']) && (int)$_GET['embed'] === 1);

$err = '';
$msg = '';

function sanitize_phone($p) { return preg_replace('/[^+0-9]/', '', trim($p ?? '')); }

// Handle actions: add, update, delete, toggle active, set default
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
  if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $phone = sanitize_phone($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $preferred = in_array($_POST['preferred_method'] ?? 'call', ['call','sms']) ? $_POST['preferred_method'] : 'call';
    $itemCode = 'OMO';
    if ($name === '') throw new Exception('Name is required');
    $stmt = $conn->prepare("INSERT INTO suppliers_contacts (name, phone, preferred_method, notes, active, item_code, is_default, created_at) VALUES (?,?,?,?,1,? ,0, NOW())");
    $stmt->bind_param('sssss', $name, $phone, $preferred, $notes, $itemCode);
    $stmt->execute();
    $stmt->close();
    $msg = 'Supplier added';
  } elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $phone = sanitize_phone($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $preferred = in_array($_POST['preferred_method'] ?? 'call', ['call','sms']) ? $_POST['preferred_method'] : 'call';
    if ($id <= 0) throw new Exception('Invalid supplier');
    if ($name === '') throw new Exception('Name is required');
    $stmt = $conn->prepare("UPDATE suppliers_contacts SET name=?, phone=?, preferred_method=?, notes=?, updated_at=NOW() WHERE id=?");
    $stmt->bind_param('ssssi', $name, $phone, $preferred, $notes, $id);
    $stmt->execute();
    $stmt->close();
    $msg = 'Supplier updated';
  } elseif ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Invalid supplier');
    $conn->query("DELETE FROM suppliers_contacts WHERE id=" . $id);
    $msg = 'Supplier deleted';
  } elseif ($action === 'toggle_active') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Invalid supplier');
    $conn->query("UPDATE suppliers_contacts SET active = CASE WHEN active=1 THEN 0 ELSE 1 END, updated_at=NOW() WHERE id=".$id);
    $msg = 'Supplier status updated';
  } elseif ($action === 'set_default') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Invalid supplier');
    // Clear other defaults for OMO, set this one
    $conn->query("UPDATE suppliers_contacts SET is_default=0 WHERE item_code='OMO'");
    $conn->query("UPDATE suppliers_contacts SET is_default=1, updated_at=NOW() WHERE id=".$id);
    $msg = 'Default supplier set';
  }
} catch (Exception $e) { $err = $e->getMessage(); }

// If editing
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
  $res = $conn->query("SELECT * FROM suppliers_contacts WHERE id=".$editId);
  if ($res && $res->num_rows) { $editRow = $res->fetch_assoc(); }
}

// Load list
$list = [];
$res = $conn->query("SELECT * FROM suppliers_contacts WHERE item_code='OMO' ORDER BY is_default DESC, active DESC, name ASC");
if ($res) { while ($r = $res->fetch_assoc()) { $list[] = $r; } }

if (!$isEmbed) { include 'includes/admin_header.php'; }
?>
<?php if (!$isEmbed): ?>
<div class="container">
  <h1>Suppliers Directory (OMO)</h1>
<?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:16px;">
    <h3><?php echo $editRow ? 'Edit Supplier' : 'Add Supplier'; ?></h3>
    <form method="post">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>" /><?php endif; ?>
      <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'add'; ?>" />
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
        <div>
          <label>Name</label>
          <input type="text" name="name" required value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" />
        </div>
        <div>
          <label>Phone</label>
          <input type="tel" name="phone" value="<?php echo htmlspecialchars($editRow['phone'] ?? ''); ?>" placeholder="+233XXXXXXXXX" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" />
        </div>
        <div>
          <label>Preferred Method</label>
          <select name="preferred_method" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
            <?php $pm = $editRow['preferred_method'] ?? 'call'; ?>
            <option value="call" <?php echo ($pm==='call'?'selected':''); ?>>Call</option>
            <option value="sms" <?php echo ($pm==='sms'?'selected':''); ?>>SMS</option>
          </select>
        </div>
        <div>
          <label>Notes</label>
          <input type="text" name="notes" value="<?php echo htmlspecialchars($editRow['notes'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" />
        </div>
      </div>
      <div style="margin-top:10px; display:flex; gap:10px;">
        <button class="btn" type="submit"><?php echo $editRow ? 'Update' : 'Add'; ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="suppliers_contacts.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Suppliers</h3>
    <div style="overflow-x:auto;">
      <table style="width:100%; border-collapse: collapse;">
        <thead>
          <tr style="background:#f0f4ff;">
            <th style="text-align:left; padding:8px;">Name</th>
            <th style="text-align:left; padding:8px;">Phone</th>
            <th style="text-align:left; padding:8px;">Preferred</th>
            <th style="text-align:left; padding:8px;">Default</th>
            <th style="text-align:left; padding:8px;">Active</th>
            <th style="text-align:left; padding:8px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($list)): ?>
            <tr><td colspan="6" style="padding:10px;">No suppliers yet.</td></tr>
          <?php else: foreach ($list as $r): ?>
            <tr style="border-bottom:1px solid #eee;">
              <td style="padding:8px;">
                <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                <?php if (!empty($r['notes'])): ?><div style="color:#777; font-size:12px;"><?php echo htmlspecialchars($r['notes']); ?></div><?php endif; ?>
              </td>
              <td style="padding:8px;"><?php echo htmlspecialchars($r['phone'] ?? ''); ?></td>
              <td style="padding:8px; text-transform:uppercase; font-size:12px;">
                <?php echo htmlspecialchars($r['preferred_method'] ?? 'call'); ?>
              </td>
              <td style="padding:8px;">
                <?php if ((int)$r['is_default'] === 1): ?>
                  <span class="badge bg-success">Default</span>
                <?php else: ?>
                  <a class="btn btn-outline" href="suppliers_contacts.php?action=set_default&id=<?php echo (int)$r['id']; ?>">Set Default</a>
                <?php endif; ?>
              </td>
              <td style="padding:8px;">
                <?php if ((int)$r['active'] === 1): ?><span class="badge bg-info">Active</span><?php else: ?><span class="badge bg-secondary">Inactive</span><?php endif; ?>
              </td>
              <td style="padding:8px; display:flex; gap:6px;">
                <a class="btn btn-outline" href="suppliers_contacts.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                <a class="btn btn-outline" href="suppliers_contacts.php?action=toggle_active&id=<?php echo (int)$r['id']; ?>"><?php echo ((int)$r['active']===1?'Deactivate':'Activate'); ?></a>
                <a class="btn btn-outline" href="suppliers_contacts.php?action=delete&id=<?php echo (int)$r['id']; ?>" data-confirm="Delete this supplier?">Delete</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php if (!$isEmbed): ?>
</div>
<?php include 'includes/admin_footer.php'; ?>
<?php endif; ?>
