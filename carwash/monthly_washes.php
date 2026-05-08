<?php
require_once 'config/session.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Inputs
$month = $_GET['month'] ?? date('Y-m'); // format YYYY-MM
$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? ''; // '', cars, carpets, motors
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Dynamically check columns to ensure schema compatibility
$carWashCols = [];
try {
    $cwRes = $conn->query("SHOW COLUMNS FROM car_washes");
    if ($cwRes) {
        while ($c = $cwRes->fetch_assoc()) {
            $carWashCols[strtolower($c['Field'])] = true;
        }
    }
} catch (mysqli_sql_exception $e) { /* ignore */ }

$hasServiceType = isset($carWashCols['service_type']);
$hasCarSize = isset($carWashCols['car_size']);
$hasCarSizeId = isset($carWashCols['car_size_id']);
$hasCategoryId = isset($carWashCols['category_id']);

$svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";
$sizeNameExpr = $hasCarSizeId ? "cs.name" : ($hasCarSize ? "cw.car_size" : "'Standard'");
$catNameExpr = $hasCategoryId ? "c.name" : "'Uncategorized'";

// Resolve month to range
$startDate = date('Y-m-01 00:00:00', strtotime($month . '-01'));
$endDate = date('Y-m-t 23:59:59', strtotime($month . '-01'));

// Build base SQL
$joinCarSize = $hasCarSizeId ? "LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id" : "";
$joinCategory = $hasCategoryId ? "LEFT JOIN categories c ON cw.category_id = c.id" : "";

$select = "SELECT 
            cw.id,
            cw.number_plate,
            cw.amount,
            cw.created_at,
            COALESCE(w.full_name,'N/A') as worker_name,
            COALESCE(u.full_name,'N/A') as admin_name,
            " . $svcNameExpr . " as service_name,
            " . $sizeNameExpr . " as car_size_name,
            COALESCE(" . $catNameExpr . ", '') as category_name
        FROM car_washes cw
        LEFT JOIN workers w ON cw.worker_id = w.id
        LEFT JOIN users u ON cw.admin_id = u.id
        LEFT JOIN services s ON cw.service_id = s.id
        $joinCarSize
        $joinCategory
        WHERE cw.created_at >= ? AND cw.created_at <= ?";

$params = [$startDate, $endDate];
$types = 'ss';

// Scope: if admin, only their records
if ($_SESSION['role'] === 'admin') {
    $select .= ' AND cw.admin_id = ?';
    $params[] = $_SESSION['user_id'];
    $types .= 'i';
}

// Search by plate, worker/admin name, or service
if ($search !== '') {
    if ($hasServiceType) {
        $select .= ' AND (cw.number_plate LIKE ? OR w.full_name LIKE ? OR u.full_name LIKE ? OR s.name LIKE ? OR cw.service_type LIKE ?)';
        $searchTerm = '%' . $search . '%';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        $types .= 'sssss';
    } else {
        $select .= ' AND (cw.number_plate LIKE ? OR w.full_name LIKE ? OR u.full_name LIKE ? OR s.name LIKE ?)';
        $searchTerm = '%' . $search . '%';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        $types .= 'ssss';
    }
}

// Type filter
if ($type === 'carpets') {
    $select .= " AND (LOWER(COALESCE(" . $catNameExpr . ",'')) = 'carpets' OR LOWER(" . $svcNameExpr . ") LIKE '%carpet%')";
} elseif ($type === 'motors') {
    $select .= " AND (LOWER(COALESCE(" . $catNameExpr . ",'')) IN ('motors','motor','bikes','bike') OR LOWER(" . $svcNameExpr . ") LIKE '%motor%' OR LOWER(" . $svcNameExpr . ") LIKE '%bike%')";
} elseif ($type === 'cars') {
    $select .= " AND NOT ((LOWER(COALESCE(" . $catNameExpr . ",'')) = 'carpets' OR LOWER(" . $svcNameExpr . ") LIKE '%carpet%')
                          OR (LOWER(COALESCE(" . $catNameExpr . ",'')) IN ('motors','motor','bikes','bike') OR LOWER(" . $svcNameExpr . ") LIKE '%motor%' OR LOWER(" . $svcNameExpr . ") LIKE '%bike%'))";
}

$order = ' ORDER BY cw.created_at DESC ';
$limit = ' LIMIT ? OFFSET ?';

// Count total for pagination
$countSql = 'SELECT COUNT(*) as cnt FROM (' . $select . ') x';
$stmt = $conn->prepare($countSql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $cntRes = $stmt->get_result();
    $total = ($row = $cntRes->fetch_assoc()) ? (int)$row['cnt'] : 0;
    $totalPages = max(1, (int)ceil($total / $perPage));
    $stmt->close();
} else {
    $total = 0;
    $totalPages = 1;
}

// Query page data
$typesPage = $types . 'ii';
$paramsPage = array_merge($params, [$perPage, $offset]);
$sql = $select . $order . $limit;
$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($typesPage, ...$paramsPage);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
}

$page_title = 'Monthly Wash Log';
include 'includes/header.php';
?>

<style>
    /* Premium SaaS CSS Framework (.mw- prefix for Monthly Washes) */
    .mw-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    .mw-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .mw-title-group { display: flex; align-items: center; gap: 16px; }
    .mw-icon-box { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 4px 12px rgba(0, 174, 239, 0.25); }
    .mw-title-group h2 { margin: 0; font-size: 1.6rem; font-weight: 800; color: #1e293b; }
    
    .mw-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.04); overflow: hidden; }
    
    .mw-toolbar { background: #f8fafc; padding: 20px 24px; border-bottom: 1px solid #e2e8f0; }
    .mw-form { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .mw-input, .mw-select { padding: 10px 16px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem; color: #334155; outline: none; transition: all 0.2s; background: #fff; }
    .mw-input:focus, .mw-select:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0, 174, 239, 0.15); }
    .mw-input[type="month"] { font-family: inherit; }
    
    .mw-btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
    .mw-btn-primary { background: #1B3FA0; color: #fff; box-shadow: 0 2px 8px rgba(27, 63, 160, 0.2); }
    .mw-btn-primary:hover { background: #153282; transform: translateY(-1px); }
    .mw-btn-outline { background: #fff; color: #64748b; border: 1px solid #cbd5e1; }
    .mw-btn-outline:hover { background: #f1f5f9; color: #334155; }
    
    .mw-table-wrapper { width: 100%; overflow-x: auto; }
    .mw-table { width: 100%; border-collapse: collapse; text-align: left; }
    .mw-table th { padding: 16px 24px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #e2e8f0; background: #fff; white-space: nowrap; }
    .mw-table td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .mw-table tbody tr:hover td { background: #f8fafc; }
    .mw-table tbody tr:last-child td { border-bottom: none; }
    
    .mw-badge { display: inline-flex; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; }
    .mw-badge-plate { background: #f1f5f9; color: #334155; font-family: monospace; border: 1px solid #cbd5e1; letter-spacing: 0.5px; }
    .mw-badge-size { background: #e0f2fe; color: #0284c7; }
    .mw-amount { font-weight: 700; color: #059669; font-size: 1.05rem; }
    
    .mw-empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .mw-empty i { font-size: 3rem; margin-bottom: 16px; opacity: 0.5; display: block; color: #cbd5e1; }
    .mw-empty h3 { font-size: 1.2rem; color: #475569; margin: 0 0 8px 0; }
    
    .mw-pagination { display: flex; justify-content: center; gap: 8px; padding: 20px; background: #fff; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
    .mw-page-link { padding: 8px 14px; border-radius: 6px; border: 1px solid #cbd5e1; color: #64748b; font-weight: 600; text-decoration: none; transition: all 0.2s; min-width: 40px; text-align: center; }
    .mw-page-link:hover { background: #f1f5f9; color: #1e293b; }
    .mw-page-link.active { background: #1B3FA0; color: #fff; border-color: #1B3FA0; box-shadow: 0 2px 6px rgba(27,63,160,0.3); }
</style>

<div class="mw-container">
    <div class="mw-header">
        <div class="mw-title-group">
            <div class="mw-icon-box"><i class="fas fa-calendar-check"></i></div>
            <h2>Monthly Wash Log</h2>
        </div>
    </div>

    <div class="mw-card">
        <div class="mw-toolbar">
            <form method="GET" class="mw-form">
                <div style="display: flex; gap: 8px; align-items: center;">
                    <i class="fas fa-calendar-alt" style="color: #64748b;"></i>
                    <input type="month" name="month" class="mw-input" value="<?php echo htmlspecialchars(date('Y-m', strtotime($startDate))); ?>">
                </div>
                
                <input type="text" name="search" class="mw-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search plate, worker, admin" style="min-width: 250px;">
                
                <select name="type" class="mw-select">
                    <option value="" <?php echo $type===''?'selected':''; ?>>All Types</option>
                    <option value="cars" <?php echo $type==='cars'?'selected':''; ?>>Cars Only</option>
                    <option value="carpets" <?php echo $type==='carpets'?'selected':''; ?>>Carpets Only</option>
                    <option value="motors" <?php echo $type==='motors'?'selected':''; ?>>Motors / Bikes</option>
                </select>
                
                <button class="mw-btn mw-btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                
                <?php if ($search !== '' || $type !== '' || $month !== date('Y-m')): ?>
                    <a href="monthly_washes.php" class="mw-btn mw-btn-outline"><i class="fas fa-redo-alt"></i> Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="mw-table-wrapper">
            <?php if (empty($rows)): ?>
                <div class="mw-empty">
                    <i class="fas fa-search"></i>
                    <h3>No Wash Records Found</h3>
                    <p>There are no completed wash records matching your criteria for this month.</p>
                </div>
            <?php else: ?>
                <table class="mw-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Service Details</th>
                            <th>License Plate</th>
                            <th>Attendant</th>
                            <th>Processed By</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $item): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b;"><?php echo date('M j, Y', strtotime($item['created_at'])); ?></div>
                                    <div style="font-size: 0.85rem; color: #64748b;"><?php echo date('g:i A', strtotime($item['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($item['service_name'] ?: 'Custom Wash'); ?></div>
                                    <?php if (!empty($item['car_size_name']) && $item['car_size_name'] !== 'Standard'): ?>
                                        <span class="mw-badge mw-badge-size" style="margin-top: 4px;"><?php echo htmlspecialchars($item['car_size_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['number_plate'] && $item['number_plate'] !== 'N/A'): ?>
                                        <span class="mw-badge mw-badge-plate"><?php echo htmlspecialchars($item['number_plate']); ?></span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-style: italic;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-user-circle" style="color: #cbd5e1; font-size: 1.2rem;"></i>
                                        <span style="font-weight: 500;"><?php echo htmlspecialchars($item['worker_name'] ?: 'Unassigned'); ?></span>
                                    </div>
                                </td>
                                <td style="color: #475569;">
                                    <?php echo htmlspecialchars($item['admin_name'] ?: 'System'); ?>
                                </td>
                                <td style="text-align: right;">
                                    <span class="mw-amount">GHS <?php echo number_format((float)$item['amount'], 2); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="mw-pagination">
                <?php 
                $qs = $_GET; unset($qs['page']); $base = 'monthly_washes.php?' . http_build_query($qs);
                for ($p=1; $p<=$totalPages; $p++): 
                    $activeClass = ($p === $page) ? 'active' : ''; 
                ?>
                <a href="<?php echo $base . '&page=' . $p; ?>" class="mw-page-link <?php echo $activeClass; ?>">
                   <?php echo $p; ?>
                </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
