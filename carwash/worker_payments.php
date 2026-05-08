<?php
session_start();

// Check if user is logged in and has proper role (admin or superadmin)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

$error = '';
$success = '';
$worker_washes = [];
$selected_worker = null;
$payment_summary = null;
$all_workers = [];

// Get all workers for dropdown
// Get only workers who HAVE pending (unconfirmed) washes
$sql = "SELECT DISTINCT w.id, w.full_name 
        FROM workers w 
        JOIN car_washes cw ON w.id = cw.worker_id 
        WHERE w.status = 'active' 
        AND (cw.payment_confirmed IS NULL OR cw.payment_confirmed = 0)
        ORDER BY w.full_name";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_workers[] = $row;
}

// --- Dynamic Commission Logic ---
$worker_pct = 33.33; // Default
try {
    $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $worker_pct = (float)$row['setting_value'];
    }
} catch (Exception $e) { /* silent fail */ }


// Optional return target and GET-based preselection
$returnTarget = isset($_GET['return']) ? trim($_GET['return']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['worker_id'])) {
    $worker_id = intval($_GET['worker_id']);
    if ($worker_id > 0) {
        $sql = "SELECT w.id as worker_id, w.full_name as worker_name,
                       cw.id, cw.number_plate, cw.amount, cw.created_at,
                       s.name as service_name, cs.name as car_size_name
                FROM workers w
                LEFT JOIN car_washes cw ON w.id = cw.worker_id 
                LEFT JOIN services s ON cw.service_id = s.id
                LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
                WHERE w.id = ? 
                AND (cw.payment_confirmed IS NULL OR cw.payment_confirmed = 0)
                ORDER BY cw.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $worker_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!$selected_worker) {
                $selected_worker = [ 'id' => $row['worker_id'], 'name' => $row['worker_name'] ];
            }
            if ($row['id']) { $worker_washes[] = $row; }
        }
        if (!empty($worker_washes)) {
            $total_amount = array_sum(array_column($worker_washes, 'amount'));
            $worker_share = ($total_amount * $worker_pct) / 100;
            $admin_share = $total_amount - $worker_share;
            $payment_summary = [
                'total_amount' => $total_amount,
                'admin_share' => $admin_share,
                'worker_share' => $worker_share,
                'wash_count' => count($worker_washes)
            ];
        }
        // Preselect in UI using $_POST fallback logic by setting a temporary value
        $_POST['worker_id'] = (string)$worker_id;
    }
}

// Handle worker selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_worker'])) {
    $worker_id = intval($_POST['worker_id']);
    
    if ($worker_id > 0) {
        // Get worker and their unconfirmed washes for today
        $sql = "SELECT w.id as worker_id, w.full_name as worker_name,
                       cw.id, cw.number_plate, cw.amount, cw.created_at,
                       s.name as service_name, cs.name as car_size_name
                FROM workers w
                LEFT JOIN car_washes cw ON w.id = cw.worker_id 
                LEFT JOIN services s ON cw.service_id = s.id
                LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
                WHERE w.id = ? 
                AND (cw.payment_confirmed IS NULL OR cw.payment_confirmed = 0)
                ORDER BY cw.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $worker_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if (!$selected_worker) {
                $selected_worker = [
                    'id' => $row['worker_id'],
                    'name' => $row['worker_name']
                ];
            }
            if ($row['id']) { // Only add if there's a wash record
                $worker_washes[] = $row;
            }
        }
        
        // Calculate payment summary
        if (!empty($worker_washes)) {
            $total_amount = array_sum(array_column($worker_washes, 'amount'));
            $worker_share = ($total_amount * $worker_pct) / 100;
            $admin_share = $total_amount - $worker_share;
            
            $payment_summary = [
                'total_amount' => $total_amount,
                'admin_share' => $admin_share,
                'worker_share' => $worker_share,
                'wash_count' => count($worker_washes)
            ];
        }
    }
}

// Handle payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['confirm_payment']) || isset($_POST['close_account']))) {
    $worker_id = intval($_POST['worker_id']);
    
    // First add the columns if they don't exist
    $conn->query("ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS payment_confirmed TINYINT(1) DEFAULT 0");
    $conn->query("ALTER TABLE car_washes ADD COLUMN IF NOT EXISTS payment_confirmed_at DATETIME NULL");
    
    // Update all today's washes for this worker as payment confirmed
    $sql = "UPDATE car_washes 
            SET payment_confirmed = 1, payment_confirmed_at = NOW() 
            WHERE worker_id = ? 
                AND (payment_confirmed IS NULL OR payment_confirmed = 0)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $worker_id);
    
    if ($stmt->execute()) {
        $success = isset($_POST['close_account']) ? 'Account closed - worker payment confirmed and records archived' : 'Payment confirmed for all washes';
        // Reset search results
        $worker_washes = [];
        $selected_worker = null;
        $payment_summary = null;
    } else {
        $error = 'Error processing request: ' . $conn->error;
    }
}

$page_title = 'Worker Pay Center';
include 'includes/header.php';
?>

<style>
/* Premium Worker Payment Framework */
.wp-container { max-width: 1100px; margin: 40px auto; padding: 0 20px 80px; font-family: 'Inter', system-ui, sans-serif; }

.wp-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
.wp-title-area { display: flex; align-items: center; gap: 16px; }
.wp-icon-box { 
    width: 56px; height: 56px; border-radius: 16px; 
    background: linear-gradient(135deg, #00AEEF, #1B3FA0); 
    display: flex; align-items: center; justify-content: center; 
    color: white; font-size: 1.5rem; 
    box-shadow: 0 8px 16px rgba(0, 174, 239, 0.25);
}
.wp-title-area h1 { margin: 0; font-size: 1.8rem; font-weight: 800; color: #1e293b; letter-spacing: -0.5px; }
.wp-title-area p { margin: 4px 0 0; color: #64748b; font-size: 0.95rem; }

.wp-card { 
    background: #fff; border-radius: 20px; 
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); 
    border: 1px solid #f1f5f9; overflow: hidden;
    margin-bottom: 30px;
}

.wp-card-header { padding: 25px 30px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
.wp-card-header h2 { font-size: 1.1rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
.wp-card-header h2 i { color: #00AEEF; }

.wp-card-body { padding: 30px; }

/* Search Form */
.wp-search-grid { display: grid; grid-template-columns: 1fr auto; gap: 15px; align-items: flex-end; }
@media (max-width: 600px) { .wp-search-grid { grid-template-columns: 1fr; } }

.wp-form-group { display: flex; flex-direction: column; gap: 8px; }
.wp-label { font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
.wp-select { 
    width: 100%; padding: 12px 16px; border-radius: 10px; border: 1.5px solid #e2e8f0; 
    font-size: 0.95rem; color: #1e293b; font-weight: 600; transition: all 0.2s; background: #fff;
}
.wp-select:focus { outline: none; border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0, 174, 239, 0.15); }

/* Worker Profile Header */
.wp-worker-profile { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; background: #f8fafc; padding: 20px; border-radius: 15px; border: 1px solid #e2e8f0; }
.wp-worker-avatar { 
    width: 64px; height: 64px; border-radius: 50%; 
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1); 
    display: flex; align-items: center; justify-content: center; 
    font-size: 1.5rem; color: #64748b; font-weight: 800;
}
.wp-worker-info h2 { margin: 0; font-size: 1.4rem; font-weight: 800; color: #1e293b; }
.wp-worker-info p { margin: 4px 0 0; color: #64748b; font-size: 0.9rem; font-weight: 600; }

/* Table */
.wp-table-wrapper { overflow-x: auto; margin-bottom: 30px; }
.wp-table { width: 100%; border-collapse: collapse; min-width: 600px; }
.wp-table th { padding: 16px 20px; background: #f8fafc; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #64748b; text-align: left; border-bottom: 2px solid #f1f5f9; }
.wp-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; font-weight: 600; color: #334155; }
.wp-table tr:hover td { background: #fcfdfe; }
.wp-plate { font-family: monospace; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; border: 1px solid #e2e8f0; color: #1e293b; letter-spacing: 1px; }

/* Summary Cards */
.wp-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 35px; }
.wp-summary-card { padding: 25px; border-radius: 20px; color: white; position: relative; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.wp-summary-card.total { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.wp-summary-card.admin { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
.wp-summary-card.worker { background: linear-gradient(135deg, #10b981, #059669); }

.wp-summary-card h4 { margin: 0 0 10px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }
.wp-summary-card .amount { font-size: 1.8rem; font-weight: 800; margin-bottom: 5px; }
.wp-summary-card .subtitle { font-size: 0.85rem; font-weight: 600; opacity: 0.8; }
.wp-summary-card i { position: absolute; right: -10px; bottom: -10px; font-size: 5rem; opacity: 0.15; transform: rotate(-15deg); }

/* Buttons */
.wp-btn { 
    display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; 
    border-radius: 12px; font-weight: 700; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none;
    text-decoration: none;
}
.wp-btn-primary { background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: white; box-shadow: 0 4px 12px rgba(0, 174, 239, 0.3); }
.wp-btn-primary:hover { transform: translateY(-2px); filter: brightness(1.1); box-shadow: 0 6px 16px rgba(0, 174, 239, 0.4); }

.wp-btn-outline { background: #fff; color: #1B3FA0; border: 2px solid #e2e8f0; }
.wp-btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

.wp-btn-success { background: linear-gradient(135deg, #10b981, #059669); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
.wp-btn-success:hover { transform: translateY(-2px); filter: brightness(1.1); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4); }

.wp-btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
.wp-btn-danger:hover { transform: translateY(-2px); filter: brightness(1.1); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4); }

/* Alerts */
.wp-alert { padding: 16px 20px; border-radius: 12px; font-weight: 600; font-size: 0.95rem; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; }
.wp-alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.wp-alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

.wp-no-data { text-align: center; padding: 60px 20px; color: #64748b; }
.wp-no-data i { font-size: 3rem; margin-bottom: 20px; opacity: 0.3; }
.wp-no-data p { font-size: 1.1rem; font-weight: 600; }
</style>

<div class="wp-container">
    <div class="wp-header">
        <div class="wp-title-area">
            <div class="wp-icon-box"><i class="fas fa-wallet"></i></div>
            <div>
                <h1>Worker Pay Center</h1>
                <p>Verify and process daily worker earnings.</p>
            </div>
        </div>
        <?php if (!empty($returnTarget)): ?>
            <a class="wp-btn wp-btn-outline" href="<?php echo htmlspecialchars($returnTarget); ?>">
                <i class="fas fa-arrow-left"></i> Back to Report
            </a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="wp-alert wp-alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="wp-alert wp-alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>


    <!-- Worker Selection Card -->
    <div class="wp-card">
        <div class="wp-card-header">
            <h2><i class="fas fa-user-search"></i> Select Worker to Process Payments</h2>
        </div>
        <div class="wp-card-body">
            <form method="POST" id="worker-select-form">
                <div class="wp-search-grid">
                    <div class="wp-form-group">
                        <label class="wp-label" for="worker_id">Choose Worker</label>
                        <select id="worker_id" name="worker_id" class="wp-select" required>
                            <option value="">-- Select a Worker --</option>
                            <?php foreach ($all_workers as $worker): ?>
                                <option value="<?php echo $worker['id']; ?>" 
                                        <?php echo (
                                            (isset($_POST['worker_id']) && $_POST['worker_id'] == $worker['id']) ||
                                            (isset($_GET['worker_id']) && $_GET['worker_id'] == $worker['id'])
                                        ) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($worker['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button type="submit" name="select_worker" class="wp-btn wp-btn-outline" style="border-color: #1B3FA0; color: #1B3FA0; background: #fff; padding: 12px 30px;">
                            <i class="fas fa-user-check"></i> Load Worker Washes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_worker && !empty($worker_washes)): ?>
        <!-- Worker Stats and Washes -->
        <div class="wp-worker-profile">
            <?php 
                $initials = '';
                $nameParts = explode(' ', $selected_worker['name']);
                $initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
            ?>
            <div class="wp-worker-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="wp-worker-info">
                <h2><?php echo htmlspecialchars($selected_worker['name']); ?></h2>
                <p>All Pending Unconfirmed Washes (<?php echo count($worker_washes); ?> cars)</p>
            </div>
        </div>

        <div class="wp-card">
            <div class="wp-card-header">
                <h2><i class="fas fa-list-ul"></i> Today's Unconfirmed Washes</h2>
            </div>
            <div class="wp-table-wrapper">
                <table class="wp-table">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Service Type</th>
                            <th>Size</th>
                            <th>Time</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($worker_washes as $wash): ?>
                            <tr>
                                <td><span class="wp-plate"><?php echo htmlspecialchars($wash['number_plate']); ?></span></td>
                                <td><?php echo htmlspecialchars($wash['service_name']); ?></td>
                                <td><?php echo htmlspecialchars($wash['car_size_name']); ?></td>
                                <td><?php echo date('g:i A', strtotime($wash['created_at'])); ?></td>
                                <td style="text-align: right; color: #10b981;">GHS <?php echo number_format($wash['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($payment_summary): ?>
            <!-- Payment Summary Cards -->
            <div class="wp-summary-grid">
                <div class="wp-summary-card total">
                    <h4>Total Gross</h4>
                    <div class="amount">GHS <?php echo number_format($payment_summary['total_amount'], 2); ?></div>
                    <div class="subtitle"><?php echo $payment_summary['wash_count']; ?> cars processed</div>
                    <i class="fas fa-calculator"></i>
                </div>
                
                <div class="wp-summary-card admin">
                    <h4>Admin Share</h4>
                    <div class="amount">GHS <?php echo number_format($payment_summary['admin_share'], 2); ?></div>
                    <div class="subtitle">Remaining Gross (<?php echo 100 - $worker_pct; ?>%)</div>
                    <i class="fas fa-building"></i>
                </div>
                
                <div class="wp-summary-card worker">
                    <h4>Worker Share</h4>
                    <div class="amount">GHS <?php echo number_format($payment_summary['worker_share'], 2); ?></div>
                    <div class="subtitle">Worker Earnings (<?php echo $worker_pct; ?>%)</div>
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; justify-content: center; margin-top: 20px;">
                <form id="confirm-payment-form" method="POST">
                    <input type="hidden" name="worker_id" value="<?php echo $selected_worker['id']; ?>">
                    <input type="hidden" name="confirm_payment" value="1">
                    <button type="button" class="wp-btn wp-btn-primary" onclick="WashHubConfirm({ title: 'Confirm Payment', message: 'Mark these specific washes as paid?', type: 'success', onConfirm: () => { document.getElementById('confirm-payment-form').submit(); } });">
                        <i class="fas fa-check-double"></i> Confirm Payment for these Washes
                    </button>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($selected_worker && empty($worker_washes)): ?>
        <div class="wp-card">
            <div class="wp-no-data">
                <i class="fas fa-coffee"></i>
                <p>No unconfirmed washes found for <strong><?php echo htmlspecialchars($selected_worker['name']); ?></strong>.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="wp-card">
            <div class="wp-no-data">
                <i class="fas fa-user-clock"></i>
                <p>Please select a worker to view today's earnings and washes.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
