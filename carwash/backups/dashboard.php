<?php
// Start the session
session_start();

// Check if user is logged in and is admin/superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Get statistics
$stats = [
    'total_washes' => 0,
    'total_revenue' => 0,
    'total_employees' => 0,
    'recent_washes' => []
];

// Get total washes and revenue for the current month
$currentMonth = date('Y-m-01');
$sql = "SELECT COUNT(*) as total_washes, COALESCE(SUM(amount), 0) as total_revenue 
        FROM car_washes 
        WHERE created_at >= ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $currentMonth);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['total_washes'] = $row['total_washes'];
    $stats['total_revenue'] = $row['total_revenue'];
}

// Get total employees (only for superadmin)
if ($_SESSION['role'] === 'superadmin') {
    $sql = "SELECT COUNT(*) as total_employees FROM users WHERE role = 'admin'";
    $result = $conn->query($sql);
    if ($row = $result->fetch_assoc()) {
        $stats['total_employees'] = $row['total_employees'];
    }
}

// Get recent washes (last 5)
$sql = "SELECT cw.*, u.full_name as admin_name 
        FROM car_washes cw
        LEFT JOIN users u ON cw.admin_id = u.id
        ORDER BY cw.created_at DESC
        LIMIT 5";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $stats['recent_washes'][] = $row;
}

$page_title = 'Dashboard';
include 'includes/header.php';
?>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <h1>Dashboard</h1>
        <div style="display: flex; gap: 10px;">
            <a href="washes.php?action=new" class="btn">
                <i class="fas fa-plus"></i> New Wash
            </a>
            <?php if ($_SESSION['role'] === 'superadmin'): ?>
                <a href="users.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 0.9rem;">
                    <i class="fas fa-users"></i> Manage Users
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="card" style="text-align: center; padding: 20px;">
            <div style="font-size: 2.5rem; color: var(--primary-color); margin-bottom: 10px;">
                <i class="fas fa-car"></i>
            </div>
            <h3 style="margin: 10px 0; color: var(--secondary-color);"><?php echo number_format($stats['total_washes']); ?></h3>
            <p style="color: var(--dark-gray);">Washes This Month</p>
        </div>
        
        <div class="card" style="text-align: center; padding: 20px;">
            <div style="font-size: 2.5rem; color: #27ae60; margin-bottom: 10px;">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <h3 style="margin: 10px 0; color: var(--secondary-color);">$<?php echo number_format($stats['total_revenue'], 2); ?></h3>
            <p style="color: var(--dark-gray);">Monthly Revenue</p>
        </div>
        
        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <div class="card" style="text-align: center; padding: 20px;">
            <div style="font-size: 2.5rem; color: #9B59B6; margin-bottom: 10px;">
                <i class="fas fa-users"></i>
            </div>
            <h3 style="margin: 10px 0; color: var(--secondary-color);"><?php echo $stats['total_employees']; ?></h3>
            <p style="color: var(--dark-gray);">Admin Users</p>
        </div>
        <?php endif; ?>
        
        <div class="card" style="text-align: center; padding: 20px;">
            <div style="font-size: 2.5rem; color: #3498DB; margin-bottom: 10px;">
                <i class="fas fa-calendar-day"></i>
            </div>
            <h3 style="margin: 10px 0; color: var(--secondary-color);"><?php echo date('M j, Y'); ?></h3>
            <p style="color: var(--dark-gray);">Today's Date</p>
        </div>
    </div>
    
    <!-- Recent Washes -->
    <div class="card" style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Recent Washes</h2>
            <a href="washes.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 0.9rem;">
                <i class="fas fa-list"></i> View All
            </a>
        </div>
        
        <?php if (count($stats['recent_washes']) > 0): ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: var(--secondary-color); color: white;">
                            <th style="padding: 12px; text-align: left;">Date</th>
                            <th style="padding: 12px; text-align: left;">Car Details</th>
                            <th style="padding: 12px; text-align: left;">Service</th>
                            <th style="padding: 12px; text-align: right;">Amount</th>
                            <th style="padding: 12px; text-align: left;">Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_washes'] as $wash): 
                            $statusClass = '';
                            switch(strtolower($wash['status'])) {
                                case 'completed':
                                    $statusClass = 'background-color: #D4EDDA; color: #155724;';
                                    break;
                                case 'in_progress':
                                    $wash['status'] = 'In Progress';
                                    $statusClass = 'background-color: #FFF3CD; color: #856404;';
                                    break;
                                case 'pending':
                                default:
                                    $statusClass = 'background-color: #F8D7DA; color: #721C24;';
                            }
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px; vertical-align: top;">
                                    <?php echo date('M j, Y', strtotime($wash['created_at'])); ?>
                                    <div style="font-size: 0.8em; color: #666;">
                                        <?php echo date('g:i A', strtotime($wash['created_at'])); ?>
                                    </div>
                                </td>
                                <td style="padding: 12px; vertical-align: top;">
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($wash['car_plate']); ?></div>
                                    <div style="font-size: 0.9em; color: #666;">
                                        <?php echo htmlspecialchars(ucwords(strtolower($wash['car_make'] . ' ' . $wash['car_model']))); ?>
                                    </div>
                                </td>
                                <td style="padding: 12px; vertical-align: top;">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $wash['service_type']))); ?>
                                    <div style="display: inline-block; font-size: 0.75em; padding: 2px 8px; border-radius: 12px; margin-left: 8px; <?php echo $statusClass; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $wash['status'])); ?>
                                    </div>
                                </td>
                                <td style="padding: 12px; text-align: right; vertical-align: top; font-weight: 600;">
                                    $<?php echo number_format($wash['amount'], 2); ?>
                                </td>
                                <td style="padding: 12px; vertical-align: top;">
                                    <?php echo htmlspecialchars($wash['admin_name'] ?? 'System'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="text-align: right; margin-top: 15px;">
                <a href="washes.php" class="btn">View All Washes</a>
            </div>
        <?php else: ?>
            <div style="padding: 30px; text-align: center; color: #666;">
                <i class="fas fa-car" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px; display: block;"></i>
                <p>No recent washes found. Get started by adding a new wash record.</p>
                <a href="washes.php?action=new" class="btn" style="margin-top: 15px;">
                    <i class="fas fa-plus"></i> Add New Wash
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Quick Actions -->
    <div class="card">
        <h2>Quick Actions</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 20px;">
            <a href="washes.php?action=new" class="card" style="text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                <div style="font-size: 2rem; color: var(--primary-color); margin-bottom: 10px;">
                    <i class="fas fa-car"></i>
                </div>
                <h3 style="margin: 0; font-size: 1.1rem;">New Wash</h3>
                <p style="color: #666; font-size: 0.9rem; margin: 5px 0 0;">Record a new car wash</p>
            </a>
            
            <a href="washes.php" class="card" style="text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                <div style="font-size: 2rem; color: #3498DB; margin-bottom: 10px;">
                    <i class="fas fa-list"></i>
                </div>
                <h3 style="margin: 0; font-size: 1.1rem;">View All</h3>
                <p style="color: #666; font-size: 0.9rem; margin: 5px 0 0;">Browse all wash records</p>
            </a>
            
            <a href="reports.php" class="card" style="text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                <div style="font-size: 2rem; color: #9B59B6; margin-bottom: 10px;">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <h3 style="margin: 0; font-size: 1.1rem;">Reports</h3>
                <p style="color: #666; font-size: 0.9rem; margin: 5px 0 0;">View business analytics</p>
            </a>
            
            <?php if ($_SESSION['role'] === 'superadmin'): ?>
            <a href="users.php" class="card" style="text-align: center; padding: 20px; text-decoration: none; color: inherit; transition: transform 0.2s; border: 1px solid #e0e0e0;">
                <div style="font-size: 2rem; color: #E74C3C; margin-bottom: 10px;">
                    <i class="fas fa-users-cog"></i>
                </div>
                <h3 style="margin: 0; font-size: 1.1rem;">Manage Users</h3>
                <p style="color: #666; font-size: 0.9rem; margin: 5px 0 0;">Admin user management</p>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        padding: 20px;
        margin-bottom: 20px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        text-align: left;
        padding: 12px;
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    td {
        padding: 12px;
        border-bottom: 1px solid #eee;
    }
    
    tr:last-child td {
        border-bottom: none;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background-color: var(--primary-color);
        color: white;
        text-decoration: none;
        border-radius: 4px;
        font-weight: 500;
        transition: background-color 0.2s;
    }
    
    .btn:hover {
        background-color: #16A085;
        color: white;
    }
    
    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--primary-color);
        color: var(--primary-color);
    }
    
    .btn-outline:hover {
        background-color: var(--primary-color);
        color: white;
    }
</style>

<?php include 'includes/footer.php'; ?>
