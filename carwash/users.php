<?php
session_start();

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

$error = '';
$success = '';

// Handle user actions (add/edit/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
            case 'edit':
                $id = $_POST['id'] ?? null;
                $full_name = trim($_POST['full_name']);
                $username = trim($_POST['username']);
                $password = $_POST['password'];
                $role = $_POST['role'];
                
                if (empty($full_name) || empty($username) || empty($role)) {
                    $error = 'All fields are required';
                } else {
                    if ($_POST['action'] === 'add' || !empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $password_sql = ", password = ?";
                    } else {
                        $password_sql = "";
                    }
                    
                    if ($_POST['action'] === 'add') {
                        $sql = "INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssss", $full_name, $username, $hashed_password, $role);
                    } else {
                        if (!empty($password_sql)) {
                            $sql = "UPDATE users SET full_name = ?, username = ?$password_sql, role = ? WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("ssssi", $full_name, $username, $hashed_password, $role, $id);
                        } else {
                            $sql = "UPDATE users SET full_name = ?, username = ?, role = ? WHERE id = ?";
                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param("sssi", $full_name, $username, $role, $id);
                        }
                    }
                    
                    if ($stmt->execute()) {
                        $success = 'User ' . ($_POST['action'] === 'add' ? 'added' : 'updated') . ' successfully!';
                    } else {
                        $error = 'Error: ' . $conn->error;
                    }
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                if ($id != $_SESSION['user_id']) { // Prevent deleting own account
                    $sql = "DELETE FROM users WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        $success = 'User deleted successfully!';
                    } else {
                        $error = 'Error deleting user: ' . $conn->error;
                    }
                } else {
                    $error = 'You cannot delete your own account!';
                }
                break;
        }
    }
}

// Get all users
try {
    $sql = "SELECT id, full_name, username, role, created_at FROM users ORDER BY role, full_name";
    $result = $conn->query($sql);
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
} catch (Exception $e) {
    $error = 'Error fetching users: ' . $e->getMessage();
}

// Worker performance logic (daily + monthly) - Note: kept logic intact
$today = date('Y-m-d');
$nowTs = time();
$startTs = strtotime($today . ' 05:00:00');
$endTs = strtotime($today . ' 19:00:00');
$inWorkWindow = ($nowTs >= $startTs && $nowTs < $endTs);

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
$closeFlagPath = $tmpDir . '/close_' . $today . '.flag';
$dayClosed = file_exists($closeFlagPath);

$showDaily = ($inWorkWindow || (!$inWorkWindow && !$dayClosed));
$windowEndTs = min($nowTs, $endTs);
$startStr = date('Y-m-d H:i:s', $startTs);
$endStr = date('Y-m-d H:i:s', $windowEndTs);

$monthStart = date('Y-m-01 00:00:00');
$nowStr = date('Y-m-d H:i:s', $nowTs);

$topDailyWorkers = [];
$topMonthlyWorkers = [];

if ($showDaily) {
    $sqlWD = "SELECT COALESCE(w.full_name, 'Unknown') AS worker_name,
                     COUNT(*) AS washes,
                     COALESCE(SUM(cw.amount),0) AS total_amount
              FROM car_washes cw
              LEFT JOIN workers w ON cw.worker_id = w.id
              WHERE cw.created_at >= ? AND cw.created_at <= ?
              GROUP BY worker_name
              ORDER BY washes DESC, total_amount DESC
              LIMIT 20";
    $stmtWD = $conn->prepare($sqlWD);
    $stmtWD->bind_param('ss', $startStr, $endStr);
    $stmtWD->execute();
    $resWD = $stmtWD->get_result();
    while ($row = $resWD->fetch_assoc()) { $topDailyWorkers[] = $row; }
}

$sqlWM = "SELECT COALESCE(w.full_name, 'Unknown') AS worker_name,
                 COUNT(*) AS washes,
                 COALESCE(SUM(cw.amount),0) AS total_amount
          FROM car_washes cw
          LEFT JOIN workers w ON cw.worker_id = w.id
          WHERE cw.created_at >= ? AND cw.created_at <= ?
          GROUP BY worker_name
          ORDER BY washes DESC, total_amount DESC
          LIMIT 50";
$stmtWM = $conn->prepare($sqlWM);
$stmtWM->bind_param('ss', $monthStart, $nowStr);
$stmtWM->execute();
$resWM = $stmtWM->get_result();
while ($row = $resWM->fetch_assoc()) { $topMonthlyWorkers[] = $row; }
?>

<?php include 'includes/header.php'; ?>

<style>
    .usr-page { max-width: 1100px; margin: 36px auto; padding: 0 20px 60px; }

    .usr-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; justify-content: space-between; flex-wrap: wrap; }
    .usr-title-left { display: flex; align-items: center; gap: 14px; }
    .usr-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .usr-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .usr-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .usr-alert { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 12px; font-size: 0.92rem; font-weight: 600; margin-bottom: 20px; }
    .usr-alert.success { background: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; }
    .usr-alert.error { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    .usr-btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px; border-radius: 10px; font-weight: 700; font-size: 0.92rem;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: #fff;
        text-decoration: none; border: none; cursor: pointer; transition: all .2s;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .usr-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; }

    .usr-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 16px rgba(0,0,0,0.07); overflow: hidden; }
    
    .usr-table-wrap { overflow-x: auto; }
    .usr-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .usr-table thead tr { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .usr-table th { padding: 16px 20px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; text-align: left; color: #475569; white-space: nowrap; }
    .usr-table td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
    .usr-table tbody tr:hover td { background: #f8faff; }
    .usr-table tbody tr:last-child td { border-bottom: none; }

    .usr-name { font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px; }
    .usr-avatar { 
        width: 36px; height: 36px; border-radius: 50%; 
        background: linear-gradient(135deg, #e2e8f0, #cbd5e1); 
        display: flex; align-items: center; justify-content: center; 
        font-weight: 800; color: #475569; font-size: 0.9rem;
    }
    
    .usr-uname { color: #64748b; font-family: monospace; font-size: 0.85rem; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; }

    .usr-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; text-transform: capitalize; }
    .usr-badge.superadmin { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
    .usr-badge.admin { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .usr-badge.cashier { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    .usr-badge.washer { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

    .usr-actions { display: flex; gap: 8px; justify-content: flex-end; }
    .usr-btn-icon {
        width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: center; cursor: pointer;
        background: #fff; color: #64748b; transition: all .2s;
    }
    .usr-btn-icon:hover { background: #f1f5f9; color: #1B3FA0; border-color: #cbd5e1; }
    .usr-btn-icon.delete:hover { background: #fef2f2; color: #ef4444; border-color: #fecaca; }

    /* Modal Styles */
    .usr-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(15,23,42,0.6); backdrop-filter: blur(4px); overflow-y: auto; padding: 40px 20px; }
    .usr-modal-content { 
        background-color: #fff; margin: auto; border-radius: 20px; width: 100%; max-width: 480px; 
        box-shadow: 0 10px 40px rgba(0,0,0,0.15); animation: modalEnter 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }
    @keyframes modalEnter { from { opacity: 0; transform: translateY(20px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    
    .usr-modal-header { padding: 24px 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #f8faff; }
    .usr-modal-header h2 { margin: 0; font-size: 1.25rem; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 10px; }
    .usr-modal-header h2 i { color: #00AEEF; }
    .usr-close { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; background: #f1f5f9; color: #64748b; cursor: pointer; font-size: 1.2rem; transition: all .2s; }
    .usr-close:hover { background: #e2e8f0; color: #0f172a; }

    .usr-modal-body { padding: 30px; }
    .usr-form-group { margin-bottom: 20px; }
    .usr-form-group label { display: block; font-size: 0.88rem; font-weight: 700; color: #475569; margin-bottom: 8px; }
    .usr-form-group input, .usr-form-group select {
        width: 100%; padding: 12px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px;
        font-size: 0.95rem; color: #1e293b; background: #fff; transition: all .2s;
        box-sizing: border-box;
    }
    .usr-form-group input:focus, .usr-form-group select:focus { border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.1); outline: none; }
    .usr-form-hint { font-size: 0.8rem; color: #94a3b8; margin-top: 6px; display: block; }
    
    .usr-modal-footer { padding: 20px 30px; border-top: 1px solid #f1f5f9; background: #f8fafc; display: flex; justify-content: flex-end; gap: 12px; }
    .usr-btn-cancel { padding: 11px 24px; border-radius: 10px; font-weight: 700; font-size: 0.92rem; background: #fff; color: #475569; border: 1px solid #cbd5e1; cursor: pointer; transition: all .2s; }
    .usr-btn-cancel:hover { background: #f1f5f9; color: #0f172a; }
</style>

<div class="usr-page">
    
    <div class="usr-title">
        <div class="usr-title-left">
            <div class="usr-title-icon"><i class="fas fa-users-cog"></i></div>
            <div>
                <h1>System Users</h1>
                <p>Manage access levels, roles, and administrative accounts.</p>
            </div>
        </div>
        <button type="button" class="usr-btn-primary" onclick="showAddUserModal()">
            <i class="fas fa-user-plus"></i> Add New User
        </button>
    </div>
    
    <?php if ($error): ?>
        <div class="usr-alert error"><i class="fas fa-exclamation-triangle"></i> <div><?php echo $error; ?></div></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="usr-alert success"><i class="fas fa-check-circle"></i> <div><?php echo $success; ?></div></div>
    <?php endif; ?>
    
    <div class="usr-card">
        <div class="usr-table-wrap">
            <table class="usr-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Role Level</th>
                        <th>Date Added</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) > 0): ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="usr-name">
                                        <div class="usr-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </div>
                                </td>
                                <td><span class="usr-uname">@<?php echo htmlspecialchars($user['username']); ?></span></td>
                                <td><span class="usr-badge <?php echo strtolower($user['role']); ?>"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                <td style="color:#64748b; font-size:0.85rem;"><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <div class="usr-actions">
                                        <button type="button" class="usr-btn-icon" title="Edit User" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" style="margin:0;" data-confirm="Are you sure you want to permanently delete this user?">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="usr-btn-icon delete" title="Delete User">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="padding: 40px; text-align: center; color: #94a3b8;">
                                <i class="fas fa-users" style="font-size:2rem; margin-bottom:10px; opacity:0.5; display:block;"></i>
                                No users found in the system.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div id="userModal" class="usr-modal">
    <div class="usr-modal-content">
        <div class="usr-modal-header">
            <h2 id="modalTitle"><i class="fas fa-user-edit"></i> <span>Add User</span></h2>
            <div class="usr-close" onclick="closeModal()"><i class="fas fa-times"></i></div>
        </div>
        
        <form id="userForm" method="POST">
            <div class="usr-modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="userId" value="">
                
                <div class="usr-form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required placeholder="e.g. John Doe">
                </div>
                
                <div class="usr-form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required placeholder="e.g. johndoe_admin">
                </div>
                
                <div class="usr-form-group">
                    <label for="password">Password <span id="passwordRequired" style="color:#ef4444;">*</span></label>
                    <input type="password" id="password" name="password" placeholder="Enter secure password">
                    <span id="passwordHelp" class="usr-form-hint"><i class="fas fa-info-circle"></i> Leave blank to keep the current password.</span>
                </div>
                
                <div class="usr-form-group">
                    <label for="role">Access Role</label>
                    <select id="role" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="superadmin">Super Admin</option>
                        <option value="cashier">Cashier</option>
                        <option value="washer">Washer</option>
                    </select>
                </div>
            </div>
            
            <div class="usr-modal-footer">
                <button type="button" class="usr-btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="usr-btn-primary" style="padding: 11px 24px; border-radius:10px; margin:0;"><i class="fas fa-save"></i> Save User</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddUserModal() {
    document.querySelector('#modalTitle span').textContent = 'Add New User';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userForm').reset();
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('passwordHelp').style.display = 'none';
    document.getElementById('password').required = true;
    document.getElementById('userId').value = '';
    document.getElementById('role').value = 'admin';
    
    document.getElementById('userModal').style.display = 'flex';
}

function editUser(user) {
    document.querySelector('#modalTitle span').textContent = 'Edit User';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = user.id;
    document.getElementById('full_name').value = user.full_name;
    document.getElementById('username').value = user.username;
    
    document.getElementById('password').value = '';
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordHelp').style.display = 'block';
    document.getElementById('password').required = false;
    
    document.getElementById('role').value = user.role;
    
    document.getElementById('userModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('userModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('userModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
