<?php
session_start();

// Check if user is logged in and has proper role (superadmin only for global settings)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("location: login.php");
    exit;
}

require_once 'config/database.php';

$error = '';
$success = '';

// --- Dynamic Branding & Commission Logic ---
$worker_pct = 33.33; // Default
$bay_display_name = "WashHub Client"; // Default
try {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Handle setting update
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update Commission
        if (isset($_POST['worker_percentage']) && ($_POST['update_commission_flag'] === '1')) {
            $new_pct = floatval($_POST['worker_percentage'] ?? 33.33);
            if ($new_pct >= 0 && $new_pct <= 100) {
                $stmt_set = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('worker_percentage', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $new_pct_str = (string)$new_pct;
                $stmt_set->bind_param("ss", $new_pct_str, $new_pct_str);
                if ($stmt_set->execute()) {
                    $success = "Global business commission updated to " . $new_pct . "%";
                    $worker_pct = $new_pct;
                }
            }
        }
        // Update Bay Name
        if (isset($_POST['bay_name']) && ($_POST['update_branding_flag'] === '1')) {
            $new_name = trim($_POST['bay_name'] ?? '');
            if ($new_name !== '') {
                $stmt_name = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('bay_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt_name->bind_param("ss", $new_name, $new_name);
                if ($stmt_name->execute()) {
                    $success = "Washing Bay Name updated to: " . htmlspecialchars($new_name);
                    $bay_display_name = $new_name;
                }
            }
        }
    }
    
    // Load current values
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('worker_percentage', 'bay_name')");
    while ($row = $res->fetch_assoc()) {
        if ($row['setting_key'] === 'worker_percentage') $worker_pct = (float)$row['setting_value'];
        if ($row['setting_key'] === 'bay_name') $bay_display_name = $row['setting_value'];
    }
    
    // Ensure defaults exist
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('worker_percentage', '33.33')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('bay_name', 'WashHub Client')");

} catch (Exception $e) { $error = "Configuration error: " . $e->getMessage(); }

$page_title = 'Business Settings';
include 'includes/header.php';
?>

<div class="container" style="max-width: 900px; margin: 40px auto; padding: 0 20px 80px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, #00AEEF, #1B3FA0); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; box-shadow: 0 8px 16px rgba(0, 174, 239, 0.25);">
                <i class="fas fa-cogs"></i>
            </div>
            <div>
                <h1 style="margin: 0; font-size: 1.8rem; font-weight: 800; color: #1e293b;">Business Settings</h1>
                <p style="margin: 4px 0 0; color: #64748b; font-size: 0.95rem;">Configure global rules and branding.</p>
            </div>
        </div>
        <a href="dashboard.php" class="btn btn-outline" style="border-radius: 10px; font-weight: 700;">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="border-radius: 12px; font-weight: 600; padding: 15px 20px; margin-bottom:20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success" style="border-radius: 12px; font-weight: 600; padding: 15px 20px; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; margin-bottom:20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Branding Settings Card -->
    <div class="card" style="border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; overflow: hidden; border-left: 5px solid #1B3FA0; margin-bottom: 30px;">
        <div style="padding: 20px 30px; background: #f8faff; border-bottom: 1px solid #f1f5f9;">
            <h2 style="font-size: 1.1rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-id-card" style="color: #1B3FA0;"></i> Business Branding
            </h2>
        </div>
        <div style="padding: 30px;">
            <form method="POST" id="branding-settings-form">
                <input type="hidden" name="update_branding_flag" id="update_branding_flag" value="0">
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: flex-end;">
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Washing Bay Name</label>
                        <input type="text" name="bay_name" 
                               style="width: 100%; padding: 14px; border-radius: 12px; border: 2px solid #e2e8f0; font-size: 1.1rem; font-weight: 700; color: #1e293b; transition: border-color 0.2s;" 
                               value="<?php echo htmlspecialchars($bay_display_name); ?>"
                               onfocus="this.style.borderColor='#1B3FA0'"
                               onblur="this.style.borderColor='#e2e8f0'">
                        <p style="font-size: 0.8rem; color: #64748b; margin: 0;">This name will appear on all Daily Reports and PDF exports.</p>
                    </div>
                    <button type="button" class="btn" style="padding: 14px 25px; border-radius: 12px; background: #1B3FA0; color: white; font-weight: 700; border: none;" 
                            onclick="document.getElementById('update_branding_flag').value = '1'; document.getElementById('branding-settings-form').submit();">
                        Update Name
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Global Settings Card -->
    <div class="card" style="border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; overflow: hidden; border-left: 5px solid #00AEEF;">
        <div style="padding: 20px 30px; background: #f8faff; border-bottom: 1px solid #f1f5f9;">
            <h2 style="font-size: 1.1rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-percent" style="color: #00AEEF;"></i> Revenue Sharing Model
            </h2>
        </div>
        <div style="padding: 30px;">
            <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 25px; font-weight: 500; line-height: 1.5;">
                This setting defines how income is shared between the **Business (Company)** and the **Workers**.
            </p>
            
            <form method="POST" id="global-settings-form">
                <input type="hidden" name="update_commission_flag" id="update_commission_flag" value="0">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px; align-items: flex-end;">
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Worker Share %</label>
                        <input type="number" step="0.01" id="worker_percentage_global" name="worker_percentage" 
                               style="width: 100%; padding: 14px; border-radius: 12px; border: 2px solid #e2e8f0; font-size: 1.3rem; font-weight: 800; text-align: center; color: #1e293b; background: #f0f9ff; border-color: #00AEEF;" 
                               value="<?php echo $worker_pct; ?>">
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Company Share %</label>
                        <input type="text" disabled 
                               style="width: 100%; padding: 14px; border-radius: 12px; border: 2px dashed #cbd5e1; font-size: 1.3rem; font-weight: 800; text-align: center; color: #64748b; background: #f8fafc;" 
                               value="<?php echo 100 - $worker_pct; ?>%">
                    </div>

                    <div style="padding-top: 10px;">
                        <button type="button" class="btn" style="width: 100%; padding: 14px; border-radius: 12px; background: linear-gradient(135deg, #00AEEF, #1B3FA0); color: white; font-weight: 700; border: none; box-shadow: 0 4px 12px rgba(0, 174, 239, 0.3);" 
                                onclick="WashHubConfirm({ title: 'Update Global Rates', message: 'This will change the revenue calculations across your entire business. Continue?', type: 'success', onConfirm: () => { document.getElementById('update_commission_flag').value = '1'; document.getElementById('global-settings-form').submit(); } });">
                            <i class="fas fa-save"></i> Save Rates
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
</div>

<?php include 'includes/footer.php'; ?>
