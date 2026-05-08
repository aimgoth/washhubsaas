<?php
session_start();

// Check if user is logged in and is admin/superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Handle search and filters
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Detect legacy columns and build expressions
$hasServiceType = false; $hasCarSize = false;
try { $r = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'service_type'"); if ($r && $r->num_rows > 0) { $hasServiceType = true; } } catch (mysqli_sql_exception $e) { $hasServiceType = false; }
try { $r2 = $conn->query("SHOW COLUMNS FROM car_washes LIKE 'car_size'"); if ($r2 && $r2->num_rows > 0) { $hasCarSize = true; } } catch (mysqli_sql_exception $e) { $hasCarSize = false; }
$svcNameExpr = $hasServiceType ? "COALESCE(s.name, cw.service_type)" : "s.name";
$sizeNameExpr = $hasCarSize ? "COALESCE(cs.name, cw.car_size)" : "cs.name";

// Build the query
$sql = "SELECT 
            cw.*, 
            " . $svcNameExpr . " AS service_name,
            " . $sizeNameExpr . " AS car_size_name,
            e.full_name as employee_name,
            a.full_name as admin_name
        FROM car_washes cw
        LEFT JOIN services s ON cw.service_id = s.id
        LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
        LEFT JOIN users e ON cw.worker_id = e.id
        LEFT JOIN users a ON cw.admin_id = a.id
        WHERE 1=1";

$params = [];
$types = '';

// Add search condition
if (!empty($search)) {
    $sql .= " AND (cw.number_plate LIKE ? OR cw.car_make LIKE ? OR cw.car_model LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

// Add date filter
if (!empty($date_from)) {
    $sql .= " AND DATE(cw.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $sql .= " AND DATE(cw.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Add sorting (map legacy columns to computed aliases)
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';
$valid_columns = ['created_at', 'number_plate', 'service_name', 'car_size_name', 'amount', 'admin_name', 'employee_name'];
// Accept legacy names from UI and map
if ($sort === 'service_type') { $sort = 'service_name'; }
if ($sort === 'car_size') { $sort = 'car_size_name'; }
$sort = in_array($sort, $valid_columns) ? $sort : 'created_at';
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
$sql .= " ORDER BY $sort $order";

// Prepare and execute the query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$page_title = 'Car Washes';
include 'includes/header.php';
?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Car Wash Records</h2>
            <a href="washes_new.php" class="btn">
                <i class="fas fa-plus"></i> New Wash
            </a>
        </div>
        
        <div class="card-body">
            <!-- Search and Filter Form -->
            <form method="GET" class="filter-form">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" placeholder="Plate, Make, or Model"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_from">Date From</label>
                        <input type="date" id="date_from" name="date_from" 
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_to">Date To</label>
                        <input type="date" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="washes.php" class="btn btn-outline">
                            <i class="fas fa-sync"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
            
            <!-- Wash Records Table -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <a href="?sort=created_at&order=<?php echo $sort === 'created_at' && $order === 'DESC' ? 'ASC' : 'DESC'; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                    Date/Time
                                    <?php if ($sort === 'created_at'): ?>
                                        <i class="fas fa-sort-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>Car Details</th>
                            <th>Service</th>
                            <th class="text-right">
                                <a href="?sort=amount&order=<?php echo $sort === 'amount' && $order === 'DESC' ? 'ASC' : 'DESC'; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                                    Amount
                                    <?php if ($sort === 'amount'): ?>
                                        <i class="fas fa-sort-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>Employee</th>
                            <th>Admin</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($wash = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="nowrap">
                                        <div><?php echo date('M j, Y', strtotime($wash['created_at'])); ?></div>
                                        <div class="text-muted small"><?php echo date('g:i A', strtotime($wash['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($wash['number_plate']); ?></div>
                                        <div class="text-muted small">
                                            <?php 
                                            $car_details = [];
                                            if (!empty($wash['car_make'])) $car_details[] = $wash['car_make'];
                                            if (!empty($wash['car_model'])) $car_details[] = $wash['car_model'];
                                            echo htmlspecialchars(implode(' ', $car_details)); 
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($wash['service_name'] ?? ($wash['service_type'] ?? '')); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($wash['car_size_name'] ?? ($wash['car_size'] ?? '')); ?></div>
                                    </td>
                                    <td class="text-right font-weight-bold">
                                        GHS <?php echo number_format((float)$wash['amount'], 2); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($wash['employee_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($wash['admin_name'] ?? 'N/A'); ?></td>
                                    <td class="actions">
                                        <a href="#" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="#" class="btn-icon" title="Print Receipt">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="empty-state">
                                        <i class="fas fa-car"></i>
                                        <p>No wash records found.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination would go here -->
            
        </div>
    </div>
</div>

<style>
/* Filter Form */
.filter-form {
    margin-bottom: 25px;
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 6px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    align-items: end;
}

.form-group {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #495057;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 5px;
}

/* Table Styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #333;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

th a {
    color: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

th a:hover {
    color: var(--primary-color);
}

tr:last-child td {
    border-bottom: none;
}

/* Empty State */
.empty-state {
    padding: 20px;
    text-align: center;
    color: #6c757d;
}

.empty-state i {
    font-size: 2rem;
    opacity: 0.5;
    margin-bottom: 10px;
    display: block;
}

.empty-state p {
    margin: 0;
}

/* Actions */
.actions {
    display: flex;
    gap: 8px;
}

.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 4px;
    background-color: #f8f9fa;
    color: #333;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-icon:hover {
    background-color: #e9ecef;
    color: var(--primary-color);
}

/* Responsive */
@media (max-width: 992px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        justify-content: flex-start;
    }
    
    th, td {
        padding: 10px 8px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
