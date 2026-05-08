<?php
// Superadmin: Fault & Repair Expenses Overview
require_once 'config/session.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Inputs with safe defaults (current month)
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : $monthStart;
$to = isset($_GET['to']) && $_GET['to'] !== '' ? $_GET['to'] : $today;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build base where
$where = 'WHERE DATE(re.created_at) >= ? AND DATE(re.created_at) <= ?';
$params = [$from, $to];
$types = 'ss';

if ($search !== '') {
    $where .= ' AND (re.description LIKE ? OR u.full_name LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term; $params[] = $term;
    $types .= 'ss';
}

// Totals
$total_amount = 0.0;
try {
    $sqlTotal = "SELECT COALESCE(SUM(re.amount),0) AS total FROM repair_expenses re LEFT JOIN users u ON re.created_by = u.id $where";
    $stmt = $conn->prepare($sqlTotal);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $total_amount = (float)$row['total']; }
    $stmt->close();
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Count
$total = 0; $totalPages = 1;
try {
    $sqlCnt = "SELECT COUNT(*) AS cnt FROM repair_expenses re LEFT JOIN users u ON re.created_by = u.id $where";
    $stmt = $conn->prepare($sqlCnt);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) { $total = (int)$r['cnt']; }
    $stmt->close();
    $totalPages = max(1, (int)ceil($total / $perPage));
} catch (mysqli_sql_exception $e) { /* ignore */ }

// Page data
$rows = [];
try {
    $sql = "SELECT re.id, re.description, re.amount, re.created_at, COALESCE(u.full_name,'') AS by_name
            FROM repair_expenses re LEFT JOIN users u ON re.created_by = u.id
            $where ORDER BY re.created_at DESC LIMIT ? OFFSET ?";
    $typesPage = $types . 'ii';
    $paramsPage = array_merge($params, [$perPage, $offset]);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($typesPage, ...$paramsPage);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    $stmt->close();
} catch (mysqli_sql_exception $e) { /* ignore */ }

$page_title = 'Fault & Repairs Overview';
include 'includes/header.php';
?>
<style>
    .re-page { max-width: 1100px; margin: 36px auto; padding: 0 20px 60px; }

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
    
    .re-total-badge {
        background: #e0f2fe; border: 1px solid #bae6fd;
        color: #0369a1; font-size: 0.95rem; font-weight: 800;
        padding: 6px 16px; border-radius: 20px;
        display: inline-flex; align-items: center; gap: 6px;
    }

    /* Filters */
    .re-filter-form { display: flex; gap: 16px; align-items: flex-end; padding: 24px; flex-wrap: wrap; background: #fff; }
    .re-field { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 180px; }
    .re-field.search { flex: 2; min-width: 250px; }
    .re-field label {
        font-size: 0.76rem; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .re-field input {
        padding: 11px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px;
        font-size: 0.93rem; font-weight: 600; color: #1e293b;
        background: #fff; transition: border-color .2s, box-shadow .2s; outline: none; width: 100%;
        font-family: inherit;
    }
    .re-field input:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.12); }
    
    .re-filter-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .re-btn-primary {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 11px 24px; height: 46px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-size: 0.92rem; font-weight: 700; cursor: pointer; text-decoration: none;
        transition: filter .2s, transform .15s; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .re-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }
    
    .re-btn-reset {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 11px 20px; height: 46px;
        background: #fff; color: #64748b; font-weight: 700;
        font-size: 0.92rem; text-decoration: none;
        border: 1.5px solid #e2e8f0; border-radius: 10px;
        transition: all .2s; white-space: nowrap;
    }
    .re-btn-reset:hover { background: #f1f5f9; color: #1e293b; border-color: #cbd5e1; }

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
        color: #1e293b; vertical-align: middle;
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

    /* Pagination */
    .re-pagination { display: flex; justify-content: center; gap: 8px; padding: 20px; flex-wrap: wrap; border-top: 1px solid #f1f5f9; }
    .re-page-btn {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 36px; height: 36px; padding: 0 10px;
        border-radius: 8px; font-weight: 700; font-size: 0.88rem;
        background: #fff; color: #64748b; border: 1.5px solid #e2e8f0;
        text-decoration: none; transition: all .2s;
    }
    .re-page-btn:hover { background: #f1f5f9; color: #1e293b; border-color: #cbd5e1; }
    .re-page-btn.active {
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; box-shadow: 0 2px 8px rgba(0,174,239,0.25);
    }
</style>

<div class="re-page">
    
    <!-- Page Title -->
    <div class="re-title">
        <div class="re-title-left">
            <div class="re-title-icon"><i class="fas fa-tools"></i></div>
            <div>
                <h1>Fault & Repairs Overview</h1>
                <p>Track and filter maintenance expenses across your washing bay.</p>
            </div>
        </div>
        <a href="dashboard.php" class="re-btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <!-- Filters Card -->
    <div class="re-card">
        <form method="GET" class="re-filter-form">
            <div class="re-field">
                <label>From Date</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="re-field">
                <label>To Date</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <div class="re-field search">
                <label>Search Query</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="By description or admin name...">
            </div>
            <div class="re-filter-actions">
                <button type="submit" class="re-btn-primary"><i class="fas fa-filter"></i> Apply Filter</button>
                <?php if ($search !== '' || $from !== $monthStart || $to !== $today): ?>
                    <a href="repair_expenses_overview.php" class="re-btn-reset"><i class="fas fa-undo"></i> Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results Table Card -->
    <div class="re-card">
        <div class="re-card-header">
            <h2><i class="fas fa-list-alt"></i> Expense Records</h2>
            <div class="re-total-badge">
                <i class="fas fa-coins" style="opacity:0.7;"></i>
                Total: GHS <?php echo number_format($total_amount, 2); ?>
            </div>
        </div>

        <?php if (!$rows): ?>
            <div class="re-empty">
                <i class="fas fa-search-dollar"></i>
                <strong>No expenses found</strong>
                There are no repair records matching your selected dates or search query.
            </div>
        <?php else: ?>
            <div class="re-table-wrap">
                <table class="re-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-calendar-alt" style="margin-right:6px;opacity:.8;"></i>Date & Time</th>
                            <th><i class="fas fa-user-shield" style="margin-right:6px;opacity:.8;"></i>Logged By</th>
                            <th><i class="fas fa-info-circle" style="margin-right:6px;opacity:.8;"></i>Description</th>
                            <th style="text-align:right;"><i class="fas fa-money-bill-wave" style="margin-right:6px;opacity:.8;"></i>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <div class="re-date"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></div>
                                <div class="re-date-sub"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                            </td>
                            <td>
                                <span class="re-admin-badge">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($r['by_name']); ?>
                                </span>
                            </td>
                            <td style="line-height:1.5;">
                                <?php echo nl2br(htmlspecialchars($r['description'])); ?>
                            </td>
                            <td>
                                <span class="re-amount">GHS <?php echo number_format((float)$r['amount'], 2); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="re-pagination">
                <?php $qs = $_GET; unset($qs['page']); $base = 'repair_expenses_overview.php?' . http_build_query($qs); ?>
                <?php for ($p=1; $p<=$totalPages; $p++): $active = ($p === $page); ?>
                    <a href="<?php echo $base . '&page=' . $p; ?>" class="re-page-btn <?php echo $active ? 'active' : ''; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<?php include 'includes/footer.php'; ?>
