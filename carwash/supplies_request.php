<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/supplies_common.php';
// Directory-only flow: no SMS integration here

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin','superadmin'])) { http_response_code(403); echo 'Forbidden'; exit; }

$omoId = supplies_bootstrap($conn);
$userId = (int)$_SESSION['user_id'];

// Embed mode: when true, do not render page header/footer or outer container
$isEmbed = (isset($_GET['embed']) && (int)$_GET['embed'] === 1);

$err = '';
$msg = '';
$periodClosedSnapshot = null;

// Pending state persistence via session
$pendingData = $_SESSION['omo_pending'] ?? null;
$pending = is_array($pendingData) && !empty($pendingData);

// Allow clearing pending state explicitly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_pending'])) {
    unset($_SESSION['omo_pending']);
    $pendingData = null;
    $pending = false;
    $msg = 'Pending request cleared.';
}

// Handle adjust qty for current open period (inline control)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_qty'])) {
    $newQty = max(0, (int)($_POST['qty_received'] ?? 0));
    if ($newQty <= 0) {
        $err = 'Please enter a valid quantity to update.';
    } else {
        $open = get_current_open_period($conn, $userId, $omoId);
        if (!$open) {
            $err = 'No open period to adjust.';
        } else {
            if ($st = $conn->prepare("UPDATE supplies_periods SET qty_received=? WHERE id=?")) {
                $pid = (int)$open['id'];
                $st->bind_param('ii', $newQty, $pid);
                if ($st->execute()) { $msg = 'Quantity received updated.'; }
                else { $err = 'Failed to update quantity: ' . $conn->error; }
                $st->close();
            } else {
                $err = 'Failed to prepare update statement.';
            }
        }
    }
}

// If AJAX request, respond with JSON and exit early (after processing above)
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' && (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1')
  )
) {
    header('Content-Type: application/json');
    $resp = [
        'ok' => $err === '',
        'message' => $err ? $err : ($msg ?: 'Success'),
        'pending' => (bool)$pending,
        'closed' => (bool)$periodClosedSnapshot,
    ];
    // Build optional HTML snippets to inject client-side
    // Flash message HTML
    $resp['flash_html'] = '<div class="alert '.($err ? 'alert-error' : 'alert-success').'">'.htmlspecialchars($err ?: $msg).'</div>';
    // Closed Period Summary HTML when available
    if ($periodClosedSnapshot) {
        ob_start();
        $t=$periodClosedSnapshot['totals'];
        ?>
        <div class="card" style="margin-top:16px;">
          <h3>Closed Period Summary</h3>
          <div>From: <?php echo htmlspecialchars($periodClosedSnapshot['period']['start_at']); ?> To: <?php echo htmlspecialchars($periodClosedSnapshot['period']['end_at']); ?></div>
          <div style="display:flex; gap:20px; margin-top:8px;">
            <div><strong>Cars:</strong> <?php echo (int)$t['cars']; ?></div>
            <div><strong>Motors:</strong> <?php echo (int)$t['motors']; ?></div>
            <div><strong>Carpets:</strong> <?php echo (int)$t['carpets']; ?></div>
          </div>
          <div style="margin-top:8px;"><strong>Qty Requested:</strong> <?php echo (int)$periodClosedSnapshot['qty_requested']; ?> bags</div>
        </div>
        <?php
        $resp['closed_html'] = trim(ob_get_clean());
    }

    echo json_encode($resp);
    exit;
}

// Load active suppliers for OMO
$contacts = [];
$res = $conn->query("SELECT id, name, phone, is_default FROM suppliers_contacts WHERE active=1 AND item_code='OMO' ORDER BY is_default DESC, name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $contacts[] = $row; }
    $res->close();
}

// Handle first submit (no confirm): store data in session and show Pending persistently
// Ignore when adjusting qty or clearing pending
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['confirm_request'] ?? '0') !== '1'
    && !isset($_POST['adjust_qty'])
    && !isset($_POST['clear_pending'])
) {
    $qtyReq = max(1, (int)($_POST['qty_requested'] ?? 0));
    $notes = trim($_POST['notes'] ?? '');
    $supplierContactId = isset($_POST['supplier_contact_id']) ? (int)$_POST['supplier_contact_id'] : null;
    $supplierPhone = preg_replace('/[^+0-9]/', '', trim($_POST['supplier_phone'] ?? ''));
    $_SESSION['omo_pending'] = [
        'qty_requested' => $qtyReq,
        'notes' => $notes,
        'supplier_contact_id' => $supplierContactId,
        'supplier_phone' => $supplierPhone,
        'saved_at' => date('Y-m-d H:i:s')
    ];
    $pendingData = $_SESSION['omo_pending'];
    $pending = true;
    $msg = $msg ?: 'Request saved. Click Pending to confirm when ready.';
}

// Only proceed with request creation/period close after explicit confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_request']) && $_POST['confirm_request'] === '1') {
    // Use POST values; if missing (e.g., after reload), fall back to session pending data
    $qtyReq = (isset($_POST['qty_requested']) && $_POST['qty_requested']!=='')
        ? max(1, (int)$_POST['qty_requested'])
        : max(1, (int)($pendingData['qty_requested'] ?? 0));
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : trim((string)($pendingData['notes'] ?? ''));
    $supplierContactId = isset($_POST['supplier_contact_id'])
        ? (int)$_POST['supplier_contact_id']
        : (isset($pendingData['supplier_contact_id']) ? (int)$pendingData['supplier_contact_id'] : null);
    $contactMethod = 'call'; // directory-only default
    // Basic phone sanitize: keep + and digits only
    $supplierPhone = preg_replace('/[^+0-9]/', '', trim($_POST['supplier_phone'] ?? ($pendingData['supplier_phone'] ?? '')));
    $now = date('Y-m-d H:i:s');

    // Find open period, or create a minimal one automatically if missing
    $open = get_current_open_period($conn, $userId, $omoId);
    if (!$open) {
        $autoStartAt = isset($pendingData['saved_at']) ? $pendingData['saved_at'] : $now;
        if ($st0 = $conn->prepare("INSERT INTO supplies_periods (admin_user_id, item_id, start_at, qty_received, created_at) VALUES (?,?,?,?,NOW())")) {
            $zero = 0;
            $st0->bind_param('iisi', $userId, $omoId, $autoStartAt, $zero);
            if ($st0->execute()) {
                $newId = $conn->insert_id;
                // reload the open period just created
                if ($stx = $conn->prepare("SELECT * FROM supplies_periods WHERE id=? LIMIT 1")) {
                    $stx->bind_param('i', $newId);
                    $stx->execute();
                    $resx = $stx->get_result();
                    $open = $resx ? $resx->fetch_assoc() : null;
                    $stx->close();
                }
            } else {
                $err = 'No open OMO period found and failed to auto-start one: ' . $conn->error;
            }
            $st0->close();
        } else {
            $err = 'Failed to prepare auto-start of OMO tracking.';
        }
    }
    if (!$err && $open) {
        $startAt = $open['start_at'];
        $endAt = $now;

        // Compute totals between period start and now
        $totals = compute_totals_for_period($conn, $startAt, $endAt);

        // Create request row (directory only) - limited to existing columns
        $reqId = 0;
        if ($stmt = $conn->prepare("INSERT INTO supplies_requests (requester_user_id, item_id, qty_requested, status, notes, supplier_phone, created_at) VALUES (?,?,?,?,?,?,?)")) {
            $status = 'requested';
            $stmt->bind_param('iiissss', $userId, $omoId, $qtyReq, $status, $notes, $supplierPhone, $now);
            if ($stmt->execute()) { $reqId = $conn->insert_id; }
            else { $err = 'Error creating request: ' . $conn->error; }
            $stmt->close();
        } else { $err = 'Failed to prepare request insert.'; }

        if (!$err) {
            // Close the current period and attach totals and request linkage
            $report = [
                'admin_user_id' => $userId,
                'item' => 'OMO',
                'qty_received' => (int)$open['qty_received'],
                'period' => ['start_at' => $startAt, 'end_at' => $endAt],
                'totals' => $totals,
                'qty_requested' => $qtyReq,
                'notes' => $notes
            ];
            $reportJson = json_encode($report);

            if ($stmt = $conn->prepare("UPDATE supplies_periods SET end_at=?, cars_total=?, motors_total=?, carpets_total=?, report_json=?, request_id_end=? WHERE id=?")) {
                $cars = $totals['cars']; $motors = $totals['motors']; $carpets = $totals['carpets'];
                $pid = (int)$open['id'];
                $stmt->bind_param('siiisii', $endAt, $cars, $motors, $carpets, $reportJson, $reqId, $pid);
                if ($stmt->execute()) {
                    $periodClosedSnapshot = $report;
                    // Attempt to auto-start a new tracking period with qty_received = qty_requested (Option A)
                    $newPeriodMsg = '';
                    if ($st2 = $conn->prepare("INSERT INTO supplies_periods (admin_user_id, item_id, start_at, qty_received, created_at) VALUES (?,?,?,?,NOW())")) {
                        $st2->bind_param('iisi', $userId, $omoId, $endAt, $qtyReq);
                        if ($st2->execute()) {
                            $newPeriodMsg = ' New OMO tracking started automatically.';
                        } else {
                            $newPeriodMsg = ' (Warning: could not auto-start new tracking: ' . $conn->error . ')';
                        }
                        $st2->close();
                    } else {
                        $newPeriodMsg = ' (Warning: failed to prepare new tracking start.)';
                    }
                    $msg = 'OMO Request submitted. Period closed.' . $newPeriodMsg;
                    // Clear pending session after success
                    unset($_SESSION['omo_pending']);
                } else {
                    $err = 'Error closing period: ' . $conn->error;
                }
                $stmt->close();
            } else {
                $err = 'Failed to prepare period update.';
            }
        }

        // Directory-only: no SMS is sent automatically.
    }
}

if (!$isEmbed) { include 'includes/header.php'; }
?>
<?php if (!$isEmbed): ?>
<div class="container">
  <h1>Request OMO</h1>
<?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-error" id="omoFlashServerErr"><?php echo $err; ?></div>
  <?php endif; ?>
  <?php if ($msg): ?>
    <div class="alert alert-success" id="omoFlashServerMsg"><?php echo $msg; ?></div>
  <?php endif; ?>

  <div id="omoFlash"></div>

  <?php if ($pending && !$periodClosedSnapshot): ?>
    <div class="alert alert-info">
      A pending OMO request is saved. Click <strong>Pending</strong> to confirm and close the period, or clear it.
      <form method="post" style="display:inline; margin-left:8px;">
        <input type="hidden" name="clear_pending" value="1" />
        <button type="submit" class="btn btn-secondary" style="padding:2px 8px; font-size:12px;">Clear</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="card" style="max-width:520px;">
    <form method="post" id="requestForm">
      <input type="hidden" name="confirm_request" id="confirm_request" value="0" />
      <div style="margin-bottom:12px;">
        <label for="qty_requested" style="display:block; font-weight:600; margin-bottom:6px;">Quantity Requested (bags)</label>
        <input type="number" id="qty_requested" name="qty_requested" min="1" step="1" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" value="<?php echo htmlspecialchars($_POST['qty_requested'] ?? ($pendingData['qty_requested'] ?? '')); ?>" />
      </div>
      <div style="margin-bottom:12px;">
        <label for="supplier_contact_id" style="display:block; font-weight:600; margin-bottom:6px;">Supplier</label>
        <select id="supplier_contact_id" name="supplier_contact_id" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
          <option value="">-- Select Supplier --</option>
          <?php foreach ($contacts as $c): ?>
            <?php
              $selPosted = isset($_POST['supplier_contact_id']) && (int)$_POST['supplier_contact_id'] === (int)$c['id'];
              $selSession = !$selPosted && isset($pendingData['supplier_contact_id']) && (int)$pendingData['supplier_contact_id'] === (int)$c['id'];
              $selectedAttr = $selPosted ? 'selected' : ($selSession ? 'selected' : (!empty($c['is_default']) ? 'selected' : ''));
            ?>
            <option value="<?php echo (int)$c['id']; ?>" data-phone="<?php echo htmlspecialchars($c['phone'] ?? ''); ?>" <?php echo $selectedAttr; ?> >
              <?php echo htmlspecialchars($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label for="supplier_phone" style="display:block; font-weight:600; margin-bottom:6px;">Supplier Phone Number</label>
        <input type="tel" id="supplier_phone" name="supplier_phone" placeholder="e.g. +233XXXXXXXXX" value="<?php echo htmlspecialchars($_POST['supplier_phone'] ?? ($pendingData['supplier_phone'] ?? (getenv('SUPPLIER_OMO_PHONE') ?: ''))); ?>" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" />
        <small style="color:#777;">Auto-fills from selected supplier. You can edit if needed.</small>
      </div>
      <div style="margin-bottom:12px;">
        <label for="notes" style="display:block; font-weight:600; margin-bottom:6px;">Notes (optional)</label>
        <textarea id="notes" name="notes" rows="3" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
      </div>
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button type="submit" id="btn_submit_request" class="btn" style="<?php echo $pending ? 'display:none;' : ''; ?>">
          Save Pending
        </button>
        <button type="button" id="btn_confirm_now" class="btn" style="background:#27ae60;">
          Confirm & Close
        </button>
        <button type="button" id="btn_pending" class="btn" title="Click to confirm this pending request" style="<?php echo $pending ? '' : 'display:none;'; ?>">Pending</button>
        <a class="btn btn-secondary" href="dashboard.php">Cancel</a>
      </div>
    </form>
  </div>

  <!-- Confirmation Modal -->
  <div id="confirmModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="background:#fff; max-width:520px; margin:10% auto; border-radius:6px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
      <div style="padding:14px 16px; border-bottom:1px solid #eee; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-exclamation-triangle" style="color:#e67e22;"></i>
        <strong>Confirm OMO Request</strong>
      </div>
      <div style="padding:16px;">
        <p style="margin:0 0 10px;">Have you received the OMO and want to close the current period with this request?</p>
        <ul style="margin:0 0 10px 18px; color:#555;">
          <li>Click <strong>Confirm</strong> to insert the request and close the period.</li>
          <li>Click <strong>Cancel</strong> to go back and make changes (no data will be saved).</li>
        </ul>
      </div>
      <div style="padding:12px 16px; border-top:1px solid #eee; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" id="btn_cancel_modal" class="btn btn-secondary">Cancel</button>
        <button type="button" id="btn_confirm_modal" class="btn">Confirm</button>
      </div>
    </div>
  </div>

  <script>
  (function(){
    var sel = document.getElementById('supplier_contact_id');
    var phone = document.getElementById('supplier_phone');
    function setPhoneFromOption(){
      var opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      var p = opt.getAttribute('data-phone') || '';
      if (p) { phone.value = p; }
    }
    sel && sel.addEventListener('change', setPhoneFromOption);
    // initialize once on load for default selection
    setPhoneFromOption();
  })();


  // Confirmation flow: show modal when user clicks Pending or Confirm & Close
  (function(){
    var form = document.getElementById('requestForm');
    var modal = document.getElementById('confirmModal');
    var btnCancel = document.getElementById('btn_cancel_modal');
    var btnConfirm = document.getElementById('btn_confirm_modal');
    var confirmField = document.getElementById('confirm_request');
    var btnPending = document.getElementById('btn_pending');
    var btnConfirmNow = document.getElementById('btn_confirm_now');

    function openConfirmModal(){ modal.style.display = 'block'; }
    if (btnPending) { btnPending.addEventListener('click', openConfirmModal); }
    if (btnConfirmNow) { btnConfirmNow.addEventListener('click', openConfirmModal); }
    if (btnCancel) {
      btnCancel.addEventListener('click', function(){
        modal.style.display = 'none';
      });
    }
    function ajaxSubmit(formData, onDone){
      formData.set('ajax','1');
      fetch('supplies_request.php?embed=<?php echo $isEmbed ? '1' : '0'; ?>', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With':'XMLHttpRequest' }
      }).then(function(r){ return r.json(); })
      .then(function(data){ onDone && onDone(data); })
      .catch(function(){ onDone && onDone({ ok:false, message:'Network error' }); });
    }

    // Intercept normal submit (first save pending)
    form.addEventListener('submit', function(e){
      e.preventDefault();
      // If this was triggered by confirm click path, let that handler run instead
      if (confirmField.value === '1') { return; }
      var fd = new FormData(form);
      ajaxSubmit(fd, function(data){
        var flash = document.getElementById('omoFlash');
        if (data && data.ok) {
          flash.innerHTML = data.flash_html || '<div class="alert alert-success">Saved.</div>';
          // Switch to Pending state
          var bPending = document.getElementById('btn_pending');
          var bSubmit = document.getElementById('btn_submit_request');
          if (bPending) bPending.style.display = '';
          if (bSubmit) bSubmit.style.display = 'none';
        } else {
          flash.innerHTML = '<div class="alert alert-error">'+(data && data.message ? data.message : 'Failed to save request')+'</div>';
        }
      });
    });

    if (btnConfirm) {
      btnConfirm.addEventListener('click', function(){
        confirmField.value = '1';
        var fd = new FormData(form);
        ajaxSubmit(fd, function(data){
          modal.style.display = 'none';
          confirmField.value = '0';
          var flash = document.getElementById('omoFlash');
          if (data && data.ok) {
            flash.innerHTML = data.flash_html || '<div class="alert alert-success">Confirmed.</div>';
            // Hide both action buttons after close
            var bPending = document.getElementById('btn_pending');
            var bSubmit = document.getElementById('btn_submit_request');
            var bConfirmNow = document.getElementById('btn_confirm_now');
            if (bPending) bPending.style.display = 'none';
            if (bSubmit) bSubmit.style.display = 'none';
            if (bConfirmNow) bConfirmNow.style.display = 'none';
            // Inject closed summary if provided
            var closedArea = document.getElementById('closedSummaryArea');
            if (!closedArea) {
              closedArea = document.createElement('div');
              closedArea.id = 'closedSummaryArea';
              form.parentNode.appendChild(closedArea);
            }
            if (data.closed && data.closed_html) {
              closedArea.innerHTML = data.closed_html;
            }
          } else {
            flash.innerHTML = '<div class="alert alert-error">'+(data && data.message ? data.message : 'Confirmation failed')+'</div>';
          }
        });
      });
    }
  })();
  </script>

  <?php $open = get_current_open_period($conn, $userId, $omoId); if ($open): ?>
    <div class="card" style="margin-top:16px;">
      <h3>Current Open Period</h3>
      <div>From: <?php echo htmlspecialchars($periodClosedSnapshot['period']['start_at'] ?? $open['start_at']); ?> To: <?php echo htmlspecialchars($periodClosedSnapshot['period']['end_at'] ?? ''); ?></div>
      <div style="display:flex; gap:20px; margin-top:8px; align-items:flex-end; flex-wrap:wrap;">
        <div><strong>Started:</strong> <?php echo htmlspecialchars($open['start_at']); ?></div>
        <div>
          <form method="post" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="adjust_qty" value="1" />
            <label for="adj_qty" style="font-weight:600;">Qty Received:</label>
            <input id="adj_qty" type="number" name="qty_received" min="0" step="1" value="<?php echo (int)$open['qty_received']; ?>" style="width:100px; padding:6px; border:1px solid #ddd; border-radius:4px;" />
            <button type="submit" class="btn btn-secondary">Update Qty</button>
          </form>
        </div>
      </div>
      <div style="color:#777; margin-top:8px;">Submitting a request will close this period and compute totals.</div>
    </div>
  <?php endif; ?>

  <?php if ($periodClosedSnapshot): $t=$periodClosedSnapshot['totals']; ?>
    <div class="card" style="margin-top:16px;">
      <h3>Closed Period Summary</h3>
      <div>From: <?php echo htmlspecialchars($periodClosedSnapshot['period']['start_at']); ?> To: <?php echo htmlspecialchars($periodClosedSnapshot['period']['end_at']); ?></div>
      <div style="display:flex; gap:20px; margin-top:8px;">
        <div><strong>Cars:</strong> <?php echo (int)$t['cars']; ?></div>
        <div><strong>Motors:</strong> <?php echo (int)$t['motors']; ?></div>
        <div><strong>Carpets:</strong> <?php echo (int)$t['carpets']; ?></div>
      </div>
      <div style="margin-top:8px;"><strong>Qty Requested:</strong> <?php echo (int)$periodClosedSnapshot['qty_requested']; ?> bags</div>
    </div>
  <?php endif; ?>
<?php if (!$isEmbed): ?>
</div>
<?php include 'includes/footer.php'; ?>
<?php endif; ?>
