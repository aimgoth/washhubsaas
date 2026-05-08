<?php
// Fault & Repair Expenses - Admin entry and list
ob_start();
require_once 'config/session.php';
if (session_status() === PHP_SESSION_NONE) {
    }

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Flash helper
function flash($key) {
    if (!empty($_SESSION[$key])) { $v = $_SESSION[$key]; unset($_SESSION[$key]); return $v; }
    return null;
}

// Handle create via PRG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_expense'])) {
    $description = trim($_POST['description'] ?? '');
    $amount = trim($_POST['amount'] ?? '');

    $errors = [];
    if ($description === '') { $errors[] = 'Description is required'; }
    if ($amount === '' || !is_numeric($amount) || floatval($amount) < 0) { $errors[] = 'Amount must be a non-negative number'; }

    if ($errors) {
        $_SESSION['flash_errors'] = $errors;
        $_SESSION['flash_old'] = ['description' => $description, 'amount' => $amount];
        header('Location: repair_expenses.php');
        exit();
    }

    $sql = 'INSERT INTO repair_expenses (description, amount, created_by) VALUES (?, ?, ?)';
    $stmt = $conn->prepare($sql);
    $amt = number_format((float)$amount, 2, '.', '');
    $stmt->bind_param('sdi', $description, $amt, $_SESSION['user_id']);
    if ($stmt->execute()) {
        $_SESSION['flash_success'] = 'Expense recorded successfully';
    } else {
        $_SESSION['flash_errors'] = ['Failed to save expense: ' . $conn->error];
    }
    header('Location: repair_expenses.php');
    exit();
}

$errors = flash('flash_errors') ?? [];
$success = flash('flash_success');
$old = flash('flash_old') ?? ['description' => '', 'amount' => ''];

// Fetch today's and recent expenses
$today = date('Y-m-d');
$today_total = 0.0;
$today_items = [];
try {
    $stmt = $conn->prepare("SELECT id, description, amount, created_at FROM repair_expenses WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $today_items[] = $row; $today_total += (float)$row['amount']; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Recent (last 20)
$recent = [];
try {
    $stmt = $conn->prepare("SELECT re.id, re.description, re.amount, re.created_at, u.full_name AS by_name
                             FROM repair_expenses re LEFT JOIN users u ON re.created_by = u.id
                             ORDER BY re.created_at DESC LIMIT 20");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $recent[] = $row; }
} catch (mysqli_sql_exception $e) { /* ignore */ }

$page_title = 'Repair Expenses';
include 'includes/header.php';
?>
<style>
    .re-page { max-width: 1000px; margin: 36px auto; padding: 0 20px 60px; }

    .re-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .re-title-left { display: flex; align-items: center; gap: 14px; }
    .re-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .re-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .re-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .re-btn-back {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px; border-radius: 10px;
        background: #f1f5f9; color: #475569; font-weight: 700;
        font-size: 0.92rem; text-decoration: none;
        border: 1.5px solid #e2e8f0;
        transition: background .2s, color .2s, border-color .2s;
    }
    .re-btn-back:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }

    .re-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 0.9rem; font-weight: 600; margin-bottom: 14px;
    }
    .re-alert.ok  { background: #e0f2fe; border-left: 4px solid #00AEEF; color: #0369a1; }
    .re-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    .re-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 16px rgba(0,0,0,0.07);
        margin-bottom: 24px; overflow: hidden;
    }
    .re-card-header {
        padding: 16px 24px; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        background: #f8faff; flex-wrap: wrap; gap: 10px;
    }
    .re-card-header h2 {
        font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 9px;
    }
    .re-card-header h2 i { color: #00AEEF; }

    /* Form */
    .re-form { padding: 24px; display: grid; gap: 16px; }
    .re-field { display: flex; flex-direction: column; gap: 6px; }
    .re-field label {
        font-size: 0.76rem; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .re-field input, .re-field textarea {
        padding: 11px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px;
        font-size: 0.93rem; font-weight: 600; color: #1e293b;
        background: #fff; transition: border-color .2s, box-shadow .2s; outline: none; width: 100%;
        font-family: inherit; resize: vertical;
    }
    .re-field input:focus, .re-field textarea:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.12); }
    .re-btn-primary {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 12px 24px; margin-top: 8px; justify-self: start;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-size: 0.92rem; font-weight: 700; cursor: pointer; text-decoration: none;
        transition: filter .2s, transform .15s; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .re-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }

    /* Table */
    .re-table-wrap { overflow-x: auto; }
    .re-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .re-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .re-table th {
        padding: 14px 20px; font-size: 0.73rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        text-align: left; white-space: nowrap; color: #fff;
    }
    .re-table td {
        padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; vertical-align: middle; line-height: 1.5;
    }
    .re-table tbody tr:last-child td { border-bottom: none; }
    .re-table tbody tr:hover td { background: #f0f7ff; transition: background .15s; }

    .re-date { color: #334155; font-size: 0.87rem; font-weight: 600; }
    .re-date-sub { color: #94a3b8; font-size: 0.75rem; margin-top: 2px; }
    
    .re-admin-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 12px; border-radius: 20px; background: #f8fafc;
        color: #475569; font-size: 0.82rem; font-weight: 700; border: 1px solid #e2e8f0;
    }
    .re-admin-badge i { color: #00AEEF; font-size: 0.9rem; }

    .re-amount {
        font-weight: 800; color: #059669; font-size: 0.98rem; text-align: right; display: block;
    }

    .re-empty { text-align: center; padding: 50px 20px; color: #94a3b8; }
    .re-empty i { font-size: 2.8rem; opacity: 0.25; margin-bottom: 14px; display: block; }
    .re-empty strong { display: block; font-size: 1rem; color: #64748b; margin-bottom: 6px; }
    
    .re-total-badge {
        background: #e0f2fe; border: 1px solid #bae6fd;
        color: #0369a1; font-size: 0.95rem; font-weight: 800;
        padding: 6px 16px; border-radius: 20px;
        display: inline-flex; align-items: center; gap: 6px;
    }
</style>

<div class="re-page">
    
    <!-- Page Title -->
    <div class="re-title">
        <div class="re-title-left">
            <div class="re-title-icon"><i class="fas fa-tools"></i></div>
            <div>
                <h1>Repair Expenses</h1>
                <p>Log and view maintenance costs for the washing bay.</p>
            </div>
        </div>
        <a href="dashboard.php" class="re-btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="re-alert ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="re-alert err">
            <i class="fas fa-exclamation-circle"></i> 
            <div>
            <?php foreach ($errors as $e): ?>
                <div><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Add Expense Form -->
    <div class="re-card">
        <div class="re-card-header">
            <h2><i class="fas fa-plus-circle"></i> Add New Expense</h2>
        </div>
        <form method="POST" action="repair_expenses.php" class="re-form">
            <div class="re-field">
                <label>Description <span style="color:#ef4444;">*</span></label>
                <textarea name="description" rows="3" required placeholder="What was repaired or purchased?"><?php echo htmlspecialchars($old['description']); ?></textarea>
            </div>
            <div class="re-field" style="max-width: 300px;">
                <label>Amount (GHS) <span style="color:#ef4444;">*</span></label>
                <input type="number" name="amount" min="0" step="0.01" value="<?php echo htmlspecialchars($old['amount']); ?>" required placeholder="e.g. 50.00" />
            </div>
            <button type="submit" name="create_expense" value="1" class="re-btn-primary">
                <i class="fas fa-save"></i> Save Expense
            </button>
        </form>
    </div>

    <!-- Today's Expenses -->
    <div class="re-card">
        <div class="re-card-header">
            <h2><i class="fas fa-calendar-day"></i> Today's Expenses (<?php echo htmlspecialchars($today); ?>)</h2>
            <div class="re-total-badge">
                <i class="fas fa-coins" style="opacity:0.7;"></i>
                Total Spent: <?php echo number_format($today_total, 2); ?>
            </div>
        </div>
        <?php if (!$today_items): ?>
            <div class="re-empty">
                <i class="fas fa-receipt"></i>
                <strong>No expenses recorded today</strong>
            </div>
        <?php else: ?>
            <div class="re-table-wrap">
                <table class="re-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-clock" style="margin-right:6px;opacity:.8;"></i>Time</th>
                            <th><i class="fas fa-info-circle" style="margin-right:6px;opacity:.8;"></i>Description</th>
                            <th style="text-align:right;"><i class="fas fa-money-bill-wave" style="margin-right:6px;opacity:.8;"></i>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($today_items as $it): ?>
                            <tr>
                                <td><div class="re-date-sub" style="font-weight:700; color:#475569;"><?php echo htmlspecialchars(date('g:i A', strtotime($it['created_at']))); ?></div></td>
                                <td><?php echo nl2br(htmlspecialchars($it['description'])); ?></td>
                                <td><span class="re-amount"><?php echo number_format($it['amount'], 2); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Expenses -->
    <div class="re-card">
        <div class="re-card-header">
            <h2><i class="fas fa-history"></i> Recent Expenses</h2>
        </div>
        <?php if (!$recent): ?>
            <div class="re-empty">
                <i class="fas fa-folder-open"></i>
                <strong>No expense records yet</strong>
            </div>
        <?php else: ?>
            <div class="re-table-wrap">
                <table class="re-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-calendar-alt" style="margin-right:6px;opacity:.8;"></i>Date</th>
                            <th><i class="fas fa-user-shield" style="margin-right:6px;opacity:.8;"></i>Logged By</th>
                            <th><i class="fas fa-info-circle" style="margin-right:6px;opacity:.8;"></i>Description</th>
                            <th style="text-align:right;"><i class="fas fa-money-bill-wave" style="margin-right:6px;opacity:.8;"></i>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td>
                                    <div class="re-date"><?php echo htmlspecialchars(date('M j, Y', strtotime($r['created_at']))); ?></div>
                                    <div class="re-date-sub"><?php echo htmlspecialchars(date('g:i A', strtotime($r['created_at']))); ?></div>
                                </td>
                                <td>
                                    <span class="re-admin-badge">
                                        <i class="fas fa-user-circle"></i>
                                        <?php echo htmlspecialchars($r['by_name'] ?? 'Admin'); ?>
                                    </span>
                                </td>
                                <td><?php echo nl2br(htmlspecialchars($r['description'])); ?></td>
                                <td><span class="re-amount"><?php echo number_format($r['amount'], 2); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
ob_end_flush();
