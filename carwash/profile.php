<?php
require_once 'config/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

$error = '';
$success = '';

// Get current user details
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    header('Location: logout.php');
    exit();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($full_name)) {
        $error = 'Full name is required';
    } elseif (empty($username)) {
        $error = 'Username is required';
    } else {
        // Check if username is already taken
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt_check->bind_param("si", $username, $_SESSION['user_id']);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $error = 'This username is already taken. Please choose another one.';
        }
    }
    
    if (empty($error) && !empty($new_password)) {
        // If changing password, validate current password
        if (empty($current_password)) {
            $error = 'Current password is required to change password';
        } elseif (!password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        }
    }
    
    if (empty($error)) {
        try {
            if (!empty($new_password)) {
                // Update with new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET full_name = ?, username = ?, password = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $full_name, $username, $hashed_password, $_SESSION['user_id']);
            } else {
                // Update without password change
                $sql = "UPDATE users SET full_name = ?, username = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssi", $full_name, $username, $_SESSION['user_id']);
            }
            
            if ($stmt->execute()) {
                $success = 'Profile updated successfully!';
                // Update session data
                $_SESSION['full_name'] = $full_name;
                $_SESSION['username'] = $username;
                // Refresh user data
                $user['full_name'] = $full_name;
                $user['username'] = $username;
            } else {
                $error = 'Failed to update profile';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$page_title = 'Account Profile';
include 'includes/header.php';
?>

<style>
/* Premium Profile Framework */
.pf-container { max-width: 1000px; margin: 40px auto; padding: 0 20px 80px; font-family: 'Inter', system-ui, sans-serif; }

.pf-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; }
.pf-title-area { display: flex; align-items: center; gap: 16px; }
.pf-icon-box { 
    width: 56px; height: 56px; border-radius: 16px; 
    background: linear-gradient(135deg, #00AEEF, #1B3FA0); 
    display: flex; align-items: center; justify-content: center; 
    color: white; font-size: 1.5rem; 
    box-shadow: 0 8px 16px rgba(0, 174, 239, 0.25);
}
.pf-title-area h1 { margin: 0; font-size: 1.8rem; font-weight: 800; color: #1e293b; letter-spacing: -0.5px; }
.pf-title-area p { margin: 4px 0 0; color: #64748b; font-size: 0.95rem; }

.pf-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start; }
@media (max-width: 860px) { .pf-grid { grid-template-columns: 1fr; } }

.pf-card { 
    background: #fff; border-radius: 20px; 
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); 
    border: 1px solid #f1f5f9; overflow: hidden;
}

.pf-card-info { padding: 30px; text-align: center; }
.pf-avatar {
    width: 100px; height: 100px; border-radius: 50%;
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;
    font-size: 2.5rem; color: #64748b; font-weight: 800;
    border: 4px solid #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.pf-info-name { font-size: 1.4rem; font-weight: 800; color: #1e293b; margin: 0 0 5px; }
.pf-info-role { 
    display: inline-block; padding: 6px 16px; border-radius: 20px; 
    font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
    background: #eff6ff; color: #1B3FA0; border: 1px solid #bfdbfe; margin-bottom: 20px;
}
.pf-info-stats { text-align: left; background: #f8fafc; border-radius: 12px; padding: 15px; margin-top: 10px; }
.pf-stat-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
.pf-stat-row:last-child { border-bottom: none; }
.pf-stat-label { color: #64748b; font-size: 0.85rem; font-weight: 600; }
.pf-stat-val { color: #334155; font-size: 0.85rem; font-weight: 700; }

.pf-card-form { padding: 30px; }
.pf-section-title { font-size: 1.1rem; font-weight: 800; color: #0f172a; margin: 0 0 20px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
.pf-section-title i { color: #00AEEF; }

.pf-form-group { margin-bottom: 20px; }
.pf-label { display: block; font-size: 0.85rem; font-weight: 700; color: #475569; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.pf-input { 
    width: 100%; padding: 12px 16px; border-radius: 10px; border: 1.5px solid #e2e8f0; 
    font-size: 0.95rem; color: #1e293b; font-weight: 500; transition: all 0.2s; box-sizing: border-box; background: #fff;
}
.pf-input:focus { outline: none; border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0, 174, 239, 0.15); }
.pf-input::placeholder { color: #94a3b8; }
.pf-hint { font-size: 0.8rem; color: #64748b; margin-top: 6px; display: block; }

.pf-btn { 
    display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; 
    border-radius: 10px; font-weight: 700; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; border: none;
}
.pf-btn-primary { background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: white; box-shadow: 0 4px 12px rgba(0, 174, 239, 0.3); }
.pf-btn-primary:hover { transform: translateY(-1px); filter: brightness(1.1); box-shadow: 0 6px 16px rgba(0, 174, 239, 0.4); }

.pf-alert { padding: 16px 20px; border-radius: 12px; font-weight: 600; font-size: 0.95rem; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
.pf-alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.pf-alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
</style>

<div class="pf-container">
    <div class="pf-header">
        <div class="pf-title-area">
            <div class="pf-icon-box"><i class="fas fa-user-shield"></i></div>
            <div>
                <h1>Account Settings</h1>
                <p>Manage your profile and security preferences.</p>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="pf-alert pf-alert-error">
            <i class="fas fa-exclamation-circle" style="font-size:1.2rem;"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="pf-alert pf-alert-success">
            <i class="fas fa-check-circle" style="font-size:1.2rem;"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <div class="pf-grid">
        <!-- Left Sidebar / Info -->
        <div class="pf-card">
            <div class="pf-card-info">
                <?php 
                    $initials = '';
                    $nameParts = explode(' ', $user['full_name']);
                    if (count($nameParts) >= 2) {
                        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
                    } else {
                        $initials = strtoupper(substr($user['full_name'], 0, 2));
                    }
                ?>
                <div class="pf-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <h2 class="pf-info-name"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                <div class="pf-info-role"><?php echo htmlspecialchars($user['role']); ?></div>
                
                <div class="pf-info-stats">
                    <div class="pf-stat-row">
                        <span class="pf-stat-label">Member Since</span>
                        <span class="pf-stat-val"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                    </div>
                    <div class="pf-stat-row">
                        <span class="pf-stat-label">Last Updated</span>
                        <span class="pf-stat-val"><?php echo date('M j, Y', strtotime($user['updated_at'] ?? $user['created_at'])); ?></span>
                    </div>
                    <div class="pf-stat-row">
                        <span class="pf-stat-label">Account ID</span>
                        <span class="pf-stat-val">#<?php echo str_pad($user['id'], 4, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Form Area -->
        <div class="pf-card">
            <div class="pf-card-form">
                <form method="POST">
                    <h3 class="pf-section-title"><i class="fas fa-id-card"></i> Personal Information</h3>
                    
                    <div class="pf-form-group">
                        <label class="pf-label" for="full_name">Full Name <span style="color:#ef4444">*</span></label>
                        <input type="text" id="full_name" name="full_name" class="pf-input" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>
                    
                    <div class="pf-form-group" style="margin-bottom: 30px;">
                        <label class="pf-label" for="username">Username <span style="color:#ef4444">*</span></label>
                        <input type="text" id="username" name="username" class="pf-input" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        <span class="pf-hint">This is used for logging into your account. Must be unique.</span>
                    </div>

                    <h3 class="pf-section-title"><i class="fas fa-lock"></i> Security settings</h3>
                    
                    <div class="pf-form-group">
                        <label class="pf-label" for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="pf-input" placeholder="Enter current password">
                        <span class="pf-hint">Required only if you are changing your password.</span>
                    </div>
                    
                    <div class="pf-form-group">
                        <label class="pf-label" for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="pf-input" placeholder="Enter new password">
                    </div>
                    
                    <div class="pf-form-group">
                        <label class="pf-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="pf-input" placeholder="Repeat new password">
                    </div>

                    <div style="margin-top: 35px; border-top: 1px solid #f1f5f9; padding-top: 20px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="pf-btn pf-btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
