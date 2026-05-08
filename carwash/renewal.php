<?php
require_once 'config/session.php';
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

$masterDb = preg_replace('/[^a-zA-Z0-9_]/', '', getenv('DB_NAME') ?: 'carwash_db');
if ($masterDb === '') {
    $masterDb = 'carwash_db';
}
$activeDb = '';
if ($resDb = $conn->query("SELECT DATABASE() AS dbn")) {
    $activeDb = (string)($resDb->fetch_assoc()['dbn'] ?? '');
}

$tenant = null;
if ($activeDb !== '' && $activeDb !== $masterDb) {
    $sqlTenant = "SELECT id, client_name, bay_name, contact_phone, superadmin_username
                  FROM `{$masterDb}`.tenants
                  WHERE db_name = ?
                  LIMIT 1";
    if ($st = $conn->prepare($sqlTenant)) {
        $st->bind_param('s', $activeDb);
        $st->execute();
        $tenant = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

$createRenewalSql = "CREATE TABLE IF NOT EXISTS `{$masterDb}`.renewal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    tenant_db_name VARCHAR(100) NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    bay_name VARCHAR(120) NULL,
    owner_name VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(40) NOT NULL,
    payment_network ENUM('mtn','telecel','other') DEFAULT 'other',
    payment_reference VARCHAR(160) NOT NULL,
    payment_date DATE NOT NULL,
    submitted_by_user VARCHAR(100) NULL,
    notes TEXT NULL,
    status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    confirmation_message VARCHAR(255) NULL,
    confirmed_by VARCHAR(100) NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rr_tenant_db (tenant_db_name),
    INDEX idx_rr_status (status),
    INDEX idx_rr_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($createRenewalSql);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_renewal_request') {
    $client_name = trim($_POST['client_name'] ?? '');
    $bay_name = trim($_POST['bay_name'] ?? '');
    $owner_name = trim($_POST['owner_name'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $payment_network = trim($_POST['payment_network'] ?? 'other');
    $payment_reference = trim($_POST['payment_reference'] ?? '');
    $payment_date = trim($_POST['payment_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $tenant_id = (int)($tenant['id'] ?? 0);
    $submitted_by = trim((string)($_SESSION['username'] ?? ''));

    if (!in_array($payment_network, ['mtn', 'telecel', 'other'], true)) {
        $payment_network = 'other';
    }

    if ($client_name === '' || $owner_name === '' || $contact_phone === '' || $payment_reference === '' || $payment_date === '') {
        $error = 'Please fill all required fields before submitting.';
    } elseif (!$activeDb || $activeDb === $masterDb) {
        $error = 'Tenant workspace was not detected. Please re-open your bay login link and try again.';
    } else {
        $insertSql = "INSERT INTO `{$masterDb}`.renewal_requests
            (tenant_id, tenant_db_name, client_name, bay_name, owner_name, contact_phone, payment_network, payment_reference, payment_date, submitted_by_user, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        if ($st = $conn->prepare($insertSql)) {
            $st->bind_param(
                'issssssssss',
                $tenant_id,
                $activeDb,
                $client_name,
                $bay_name,
                $owner_name,
                $contact_phone,
                $payment_network,
                $payment_reference,
                $payment_date,
                $submitted_by,
                $notes
            );
            if ($st->execute()) {
                $success = 'Renewal submission sent. We will confirm after payment verification.';
            } else {
                $error = 'Failed to submit renewal request. Please try again.';
            }
            $st->close();
        } else {
            $error = 'Unable to prepare renewal submission.';
        }
    }
}

$requests = [];
if ($activeDb && $activeDb !== $masterDb) {
    $reqSql = "SELECT * FROM `{$masterDb}`.renewal_requests WHERE tenant_db_name = ? ORDER BY created_at DESC LIMIT 100";
    if ($stReq = $conn->prepare($reqSql)) {
        $stReq->bind_param('s', $activeDb);
        $stReq->execute();
        $resReq = $stReq->get_result();
        while ($row = $resReq->fetch_assoc()) {
            $requests[] = $row;
        }
        $stReq->close();
    }
}

include 'includes/header.php';
?>

<style>
  .renewal-wrap{max-width:1100px;margin:20px auto 0;display:grid;grid-template-columns:1.1fr .9fr;gap:18px}
  .renewal-card{background:#fff;border:1px solid #d9ebf7;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
  .renewal-hdr{padding:14px 18px;border-bottom:1px solid #e8f2fa;font-weight:800;color:#1B3FA0;display:flex;align-items:center;gap:8px}
  .renewal-body{padding:18px}
  .renewal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .renewal-grid .full{grid-column:1/-1}
  .renewal-label{display:block;font-size:.78rem;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px}
  .renewal-input{width:100%;padding:10px 12px;border:1.5px solid #d4e6f5;border-radius:8px;font:inherit}
  .renewal-input:focus{outline:none;border-color:#00AEEF;box-shadow:0 0 0 3px rgba(0,174,239,.15)}
  .renewal-btn{background:linear-gradient(135deg,#1B3FA0,#00AEEF);color:#fff;border:none;border-radius:8px;padding:10px 16px;font-weight:700;cursor:pointer}
  .pay-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px;margin-bottom:14px}
  .pay-row{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;background:#fff;border-radius:8px;border:1px solid #dbeafe;margin-top:8px}
  .chip{display:inline-flex;padding:3px 9px;border-radius:999px;font-size:.72rem;font-weight:800}
  .c-pending{background:#fef3c7;color:#92400e}
  .c-confirmed{background:#dcfce7;color:#166534}
  .c-rejected{background:#fee2e2;color:#991b1b}
  .req-item{border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:10px}
  .req-meta{font-size:.8rem;color:#64748b}
  @media(max-width:960px){.renewal-wrap{grid-template-columns:1fr}.renewal-grid{grid-template-columns:1fr}}
</style>

<div class="renewal-wrap">
  <div class="renewal-card">
    <div class="renewal-hdr"><i class="fas fa-money-check-dollar"></i> Renewal Submission</div>
    <div class="renewal-body">
      <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

      <div class="pay-box">
        <div style="font-weight:800;color:#0f172a;">Payment Instructions (Mobile Money)</div>
        <div class="pay-row"><span>MTN MoMo Number</span><strong>0549195399</strong></div>
        <div class="pay-row"><span>Telecel Cash Number</span><strong>0509729601</strong></div>
        <div class="pay-row"><span>Name (Both Accounts)</span><strong>Jimbaja Godfred</strong></div>
        <div style="margin-top:8px;font-size:.82rem;color:#9a3412;">Use your Client or WashBay name as payment reference when sending money.</div>
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="submit_renewal_request">
        <div class="renewal-grid">
          <div>
            <label class="renewal-label">Client / WashBay Name *</label>
            <input class="renewal-input" name="client_name" required value="<?php echo htmlspecialchars($_POST['client_name'] ?? ($tenant['client_name'] ?? '')); ?>">
          </div>
          <div>
            <label class="renewal-label">Owner / Super Admin Name *</label>
            <input class="renewal-input" name="owner_name" required value="<?php echo htmlspecialchars($_POST['owner_name'] ?? ($_SESSION['full_name'] ?? '')); ?>">
          </div>
          <div>
            <label class="renewal-label">WashBay Branch</label>
            <input class="renewal-input" name="bay_name" value="<?php echo htmlspecialchars($_POST['bay_name'] ?? ($tenant['bay_name'] ?? '')); ?>">
          </div>
          <div>
            <label class="renewal-label">Contact *</label>
            <input class="renewal-input" name="contact_phone" required value="<?php echo htmlspecialchars($_POST['contact_phone'] ?? ($tenant['contact_phone'] ?? '')); ?>">
          </div>
          <div>
            <label class="renewal-label">Payment Date *</label>
            <input class="renewal-input" type="date" name="payment_date" required value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>">
          </div>
          <div>
            <label class="renewal-label">Network *</label>
            <select class="renewal-input" name="payment_network" required>
              <?php $pn = $_POST['payment_network'] ?? 'mtn'; ?>
              <option value="mtn" <?php echo $pn === 'mtn' ? 'selected' : ''; ?>>MTN</option>
              <option value="telecel" <?php echo $pn === 'telecel' ? 'selected' : ''; ?>>Telecel</option>
              <option value="other" <?php echo $pn === 'other' ? 'selected' : ''; ?>>Other</option>
            </select>
          </div>
          <div class="full">
            <label class="renewal-label">Payment Reference *</label>
            <input class="renewal-input" name="payment_reference" required placeholder="Use Client/WashBay name as reference" value="<?php echo htmlspecialchars($_POST['payment_reference'] ?? ''); ?>">
          </div>
          <div class="full">
            <label class="renewal-label">Note (Optional)</label>
            <textarea class="renewal-input" name="notes" rows="3" placeholder="Any extra payment note"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
          </div>
        </div>
        <div style="margin-top:12px;">
          <button class="renewal-btn" type="submit"><i class="fas fa-paper-plane"></i> Submit Renewal Form</button>
        </div>
      </form>
    </div>
  </div>

  <div class="renewal-card">
    <div class="renewal-hdr"><i class="fas fa-receipt"></i> Renewal Evidence / Status</div>
    <div class="renewal-body">
      <?php if (empty($requests)): ?>
        <div style="color:#64748b;">No renewal submission yet. Submit the form after sending payment.</div>
      <?php else: ?>
        <?php foreach ($requests as $r):
          $status = strtolower((string)($r['status'] ?? 'pending'));
          $chip = $status === 'confirmed' ? 'c-confirmed' : ($status === 'rejected' ? 'c-rejected' : 'c-pending');
        ?>
          <div class="req-item">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
              <strong><?php echo htmlspecialchars($r['client_name']); ?></strong>
              <span class="chip <?php echo $chip; ?>"><?php echo ucfirst($status); ?></span>
            </div>
            <div class="req-meta"><?php echo date('d M Y, g:ia', strtotime((string)$r['created_at'])); ?> · Ref: <?php echo htmlspecialchars($r['payment_reference']); ?></div>
            <?php if (!empty($r['confirmation_message'])): ?>
              <div style="margin-top:7px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;">
                <?php echo htmlspecialchars($r['confirmation_message']); ?>
                <?php if (!empty($r['confirmed_at'])): ?>
                  <div class="req-meta" style="margin-top:4px;">Confirmed on <?php echo date('d M Y, g:ia', strtotime((string)$r['confirmed_at'])); ?></div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
