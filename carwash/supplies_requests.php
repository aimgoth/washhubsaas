<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') { 
    http_response_code(403); 
    echo 'Forbidden'; 
    exit; 
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/supplies_common.php';

$page_title = 'OMO Request History';
$err = '';
$msg = '';
$minimal = isset($_GET['minimal']);
$isEmbed = (isset($_GET['embed']) && (int)$_GET['embed'] === 1);

// Ensure OMO item exists and get its ID
$omoId = supplies_bootstrap($conn);

// Optional debug mode: add ?debug=1 to see errors on page safely during troubleshooting
if (isset($_GET['debug'])) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

if (!function_exists('j')) { 
    function j($v){ 
        return json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); 
    } 
}

// Load only the most recently closed OMO period (inserted when admin confirmed a request)
$rows = [];
$sql = "SELECT p.*, r.qty_requested, r.notes AS request_notes, r.created_at AS request_created_at,
               u.username AS requester_name, s.name AS supplier_name, s.phone AS supplier_phone
        FROM supplies_periods p
        LEFT JOIN supplies_requests r ON r.id = p.request_id_end
        LEFT JOIN users u ON u.id = r.requester_user_id
        LEFT JOIN suppliers_contacts s ON s.id = r.supplier_contact_id
        WHERE p.item_id = " . (int)$omoId . " AND p.end_at IS NOT NULL
        ORDER BY p.end_at DESC
        LIMIT 1";
try {
  $res = $conn->query($sql);
} catch (Throwable $ex) {
  $res = false;
  if ($err === '') { $err = 'SQL error: '.$ex->getMessage(); }
}
if ($res === false) {
  if ($err === '') { $err = 'SQL error: '.$conn->error; }
}
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }

// Plain debug mode to bypass templates if needed
if (isset($_GET['plain'])) {
    header('Content-Type: text/plain');
    echo "OMO Request History - Plain Mode\n";
    if ($err) { echo "ERR: $err\n"; }
    echo "Rows: ".count($rows)."\n";
    foreach ($rows as $r) {
        echo "#".$r['id']." supplier:".($r['supplier_name'] ?? '')." created:".($r['created_at'] ?? '')."\n";
    }
    exit;
}

if ($minimal && !$isEmbed) {
  ?><!DOCTYPE html>
  <html lang="en"><head><meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <style>
    
    .container{max-width:1100px;margin:0 auto}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px}
    .alert{padding:8px 10px;border-radius:6px;margin-bottom:10px}
    .alert-error{background:#fdecea;color:#c0392b;border:1px solid #fadbd8}
    .alert-success{background:#ecf9f1;color:#1e7e34;border:1px solid #d4edda}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid #eee}
    .btn{padding:6px 10px;border:1px solid #ddd;border-radius:4px;background:#f7f7f7;cursor:pointer}
    .btn-outline{background:#fff}
    .input{padding:6px 8px;border:1px solid #ddd;border-radius:4px}
    .badge{display:inline-block;padding:2px 6px;font-size:12px;border-radius:4px}
    .bg-info{background:#e3f2fd}
    .bg-success{background:#e8f5e9}
    .bg-warning{background:#fff3e0}
    .bg-secondary{background:#eceff1}
  </style>
  </head><body>
  <?php
} else if (!$isEmbed) {
  if (file_exists(__DIR__.'/includes/admin_header.php')) {
    include 'includes/admin_header.php';
  } else {
    include 'includes/header.php';
  }
}
?>
<style>
.row-actions { display:flex; gap:6px; flex-wrap: wrap; }
.small { font-size: 12px; color: #666; }
form.inline { display:inline; }
.input { padding:6px 8px; border:1px solid #ddd; border-radius:4px; }
.badge { display:inline-block; padding:2px 6px; font-size:12px; border-radius: 4px; }
.bg-info{ background:#e3f2fd; }
.bg-success{ background:#e8f5e9; }
.bg-warning{ background:#fff3e0; }
.bg-secondary{ background:#eceff1; }
</style>
<?php if (!$isEmbed): ?>
<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
  <h1 style="color: #2c3e50; margin-bottom: 24px; text-align: center;">OMO Request History</h1>
<?php endif; ?>
  <?php if ($err): ?>
  <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 12px 15px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
    <?php echo htmlspecialchars($err); ?>
  </div>
  <?php endif; ?>
  <?php if ($msg): ?>
  <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px 15px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 20px;">
    <?php echo htmlspecialchars($msg); ?>
  </div>
  <?php endif; ?>

  <div class="card" style="max-width: 1000px; margin: 0 auto; padding: 20px;">
    <?php if (empty($rows)): ?>
      <div style="text-align: center; padding: 20px; color: #666;">No closed OMO period found yet.</div>
    <?php else: $p = $rows[0];
      // prefer report_json if present for totals snapshot
      $t = ['cars'=>($p['cars_total'] ?? 0), 'motors'=>($p['motors_total'] ?? 0), 'carpets'=>($p['carpets_total'] ?? 0)];
      if (!empty($p['report_json'])) {
        $dec = json_decode($p['report_json'], true);
        if (is_array($dec) && isset($dec['totals'])) { $t = $dec['totals']; }
      }
    ?>
      <h2 style="margin-top:0; text-align: center; color: #2c3e50; margin-bottom: 24px;">Latest Closed OMO Period</h2>
      
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
          <thead>
            <tr style="background-color: var(--secondary-color); color: white;">
              <th style="padding: 12px; text-align: left;">Supplied Date</th>
              <th style="padding: 12px; text-align: left;">Admin Confirmed</th>
              <th style="padding: 12px; text-align: left;">Period Closed</th>
              <th style="padding: 12px; text-align: left;">Supplier</th>
              <th style="padding: 12px; text-align: left;">Requester</th>
              <th style="padding: 12px; text-align: right;">Qty Received</th>
            </tr>
          </thead>
          <tbody>
            <tr style="border-bottom: 1px solid #eee;">
              <td style="padding: 12px;"><?php echo !empty($p['start_at']) ? date('M j, Y H:i', strtotime($p['start_at'])) : 'N/A'; ?></td>
              <td style="padding: 12px;"><?php echo !empty($p['request_created_at']) ? date('M j, Y H:i', strtotime($p['request_created_at'])) : 'N/A'; ?></td>
              <td style="padding: 12px;"><?php echo !empty($p['end_at']) ? date('M j, Y H:i', strtotime($p['end_at'])) : 'N/A'; ?></td>
              <td style="padding: 12px;"><?php echo !empty($p['supplier_name']) ? htmlspecialchars($p['supplier_name']) : 'N/A'; ?></td>
              <td style="padding: 12px;"><?php echo !empty($p['requester_name']) ? htmlspecialchars($p['requester_name']) : 'N/A'; ?></td>
              <td style="padding: 12px; text-align: right; font-weight: 600;"><?php echo number_format((int)($p['qty_received'] ?? 0)); ?> bags</td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div style="background: #f8f9fa; border-radius: 8px; padding: 20px; margin-top: 30px; border: 1px solid #e9ecef;">
        <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--secondary-color); text-align: center;">Wash Totals For This Period</h3>
        <div style="display: flex; justify-content: space-around; flex-wrap: wrap; gap: 15px; margin-top: 15px;">
          <div style="text-align: center; flex: 1; min-width: 120px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
            <div style="font-size: 24px; font-weight: 700; color: #2c3e50;"><?php echo number_format((int)($t['cars'] ?? 0)); ?></div>
            <div style="color: #6c757d; font-size: 14px; margin-top: 5px;">Cars Washed</div>
          </div>
          <div style="text-align: center; flex: 1; min-width: 120px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
            <div style="font-size: 24px; font-weight: 700; color: #2c3e50;"><?php echo number_format((int)($t['motors'] ?? 0)); ?></div>
            <div style="color: #6c757d; font-size: 14px; margin-top: 5px;">Motors Washed</div>
          </div>
          <div style="text-align: center; flex: 1; min-width: 120px; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
            <div style="font-size: 24px; font-weight: 700; color: #2c3e50;"><?php echo number_format((int)($t['carpets'] ?? 0)); ?></div>
            <div style="color: #6c757d; font-size: 14px; margin-top: 5px;">Carpets Washed</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  </div>
<?php if (!$isEmbed): ?>
</div>
<?php if ($minimal) { ?>
  </body></html>
<?php } else { if (file_exists(__DIR__.'/includes/admin_footer.php')) { include 'includes/admin_footer.php'; } else { include 'includes/footer.php'; } } ?>
<?php endif; ?>
