<?php
require_once 'config/session.php';
require_once 'config/database.php';

// Only Super Admins and Admins should manage prices
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) { 
    header('Location: login.php'); 
    exit; 
}

$messages = [];
$errors = [];

// 1. Ensure the prices table exists (SaaS auto-migration)
try {
    $conn->query("CREATE TABLE IF NOT EXISTS prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL,
        car_size_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        UNIQUE KEY unique_service_size (service_id, car_size_id)
    )");
} catch (Exception $e) {
    $errors[] = "Failed to initialize pricing table: " . $e->getMessage();
}

// 2. Handle form submission (Saving Prices)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prices'])) {
    $success_count = 0;
    
    // Begin transaction
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO prices (service_id, car_size_id, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = ?");
        
        foreach ($_POST['prices'] as $service_id => $sizes) {
            foreach ($sizes as $car_size_id => $amount) {
                $service_id = (int)$service_id;
                $car_size_id = (int)$car_size_id;
                $amount = (float)$amount;
                
                $stmt->bind_param('iidd', $service_id, $car_size_id, $amount, $amount);
                $stmt->execute();
                $success_count++;
            }
        }
        $conn->commit();
        $messages[] = "Pricing matrix updated successfully! Saved $success_count pricing rules.";
    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = "Error saving prices: " . $e->getMessage();
    }
}

// 3. Fetch Data for the Matrix
$services = [];
$res_serv = $conn->query("SELECT id, name FROM services ORDER BY name");
if ($res_serv) { while($row = $res_serv->fetch_assoc()) { $services[] = $row; } }

$car_sizes = [];
$res_size = $conn->query("SELECT id, name FROM car_sizes ORDER BY name");
if ($res_size) { while($row = $res_size->fetch_assoc()) { $car_sizes[] = $row; } }

// Fetch existing prices into a lookup array: $pricing_data[service_id][car_size_id] = amount
$pricing_data = [];
$res_prices = $conn->query("SELECT service_id, car_size_id, amount FROM prices");
if ($res_prices) {
    while($row = $res_prices->fetch_assoc()) {
        $pricing_data[$row['service_id']][$row['car_size_id']] = $row['amount'];
    }
}

?>
<?php include 'includes/header.php'; ?>

<style>
    .pm-page { max-width: 1100px; margin: 36px auto; padding: 0 20px 60px; }

    /* Header section */
    .pm-header-wrap { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 28px; }
    .pm-title { display: flex; align-items: center; gap: 16px; }
    .pm-title-icon {
        width: 56px; height: 56px; border-radius: 16px;
        background: linear-gradient(135deg, #10b981, #059669);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.6rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(16,185,129,0.3);
    }
    .pm-title h1 { font-size: 1.8rem; font-weight: 800; color: #064e3b; margin: 0; letter-spacing:-0.5px; }
    .pm-title p  { font-size: 0.95rem; color: #64748b; margin: 4px 0 0; }

    /* Alerts */
    .pm-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 14px 20px; border-radius: 10px;
        font-size: 0.95rem; font-weight: 600; margin-bottom: 20px;
    }
    .pm-alert.ok  { background: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; }
    .pm-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    /* The Matrix Card */
    .pm-card {
        background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;
        box-shadow: 0 4px 24px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 24px;
    }
    .pm-card-top {
        background: #f8fafc; padding: 18px 24px; border-bottom: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center;
    }
    .pm-card-top h2 { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin: 0; display:flex; align-items:center; gap:8px; }
    .pm-card-top h2 i { color: #10b981; }

    /* The Matrix Table */
    .pm-table-wrap { width: 100%; overflow-x: auto; }
    .pm-matrix { width: 100%; border-collapse: collapse; min-width: 600px; }
    .pm-matrix th, .pm-matrix td { border: 1px solid #f1f5f9; padding: 16px 20px; text-align: center; }
    
    .pm-matrix thead th {
        background: #f8fafc; color: #475569; font-size: 0.8rem; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.8px;
    }
    .pm-matrix thead th:first-child { text-align: left; width: 220px; background: #f1f5f9; }
    
    .pm-matrix tbody th {
        background: #f8fafc; text-align: left; font-size: 0.95rem; font-weight: 700;
        color: #1e293b; border-right: 2px solid #e2e8f0;
    }
    
    /* Input Styling inside Matrix */
    .pm-input-wrapper {
        position: relative; display: inline-flex; align-items: center;
        background: #fff; border: 2px solid #e2e8f0; border-radius: 8px;
        transition: all 0.2s; overflow: hidden; max-width: 140px;
    }
    .pm-input-wrapper:focus-within { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
    .pm-currency {
        background: #f1f5f9; color: #64748b; font-weight: 700; font-size: 0.85rem;
        padding: 10px 12px; border-right: 1px solid #e2e8f0;
    }
    .pm-input {
        border: none; padding: 10px; font-size: 1rem; font-weight: 600; color: #0f172a;
        width: 100%; text-align: right; outline: none; background: transparent;
    }

    /* Footer / Actions */
    .pm-card-footer {
        padding: 20px 24px; background: #fff; border-top: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center;
    }
    .pm-help { color: #64748b; font-size: 0.85rem; display:flex; align-items:center; gap:6px; }
    
    .pm-btn-save {
        background: linear-gradient(135deg, #10b981, #059669); color: #fff;
        border: none; padding: 12px 32px; border-radius: 10px; font-size: 1rem;
        font-weight: 700; cursor: pointer; transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(16,185,129,0.25); display: flex; align-items: center; gap:8px;
    }
    .pm-btn-save:hover { filter: brightness(1.1); transform: translateY(-1px); }

    .pm-empty-state { text-align: center; padding: 60px 20px; }
    .pm-empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 16px; }
    .pm-empty-state h3 { color: #334155; font-size: 1.2rem; margin-bottom: 8px; }
    .pm-empty-state p { color: #64748b; font-size: 0.95rem; max-width: 400px; margin: 0 auto 20px; }
    .pm-btn-outline {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
        border: 2px solid #e2e8f0; border-radius: 8px; color: #475569;
        font-weight: 600; text-decoration: none; transition: all 0.2s;
    }
    .pm-btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; color: #1e293b; }
</style>

<div class="pm-page">
    
    <div class="pm-header-wrap">
        <div class="pm-title">
            <div class="pm-title-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div>
                <h1>Pricing Matrix</h1>
                <p>Configure automated pricing for every service and car size combination.</p>
            </div>
        </div>
        <a href="dashboard.php" class="pm-btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <!-- Alerts -->
    <?php foreach ($messages as $m): ?>
        <div class="pm-alert ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="pm-alert err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>

    <?php if (empty($services) || empty($car_sizes)): ?>
        <div class="pm-card">
            <div class="pm-empty-state">
                <i class="fas fa-tools"></i>
                <h3>Setup Required</h3>
                <p>You need to add at least one Service and one Car Size before you can configure the pricing matrix.</p>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <a href="manage_services.php" class="pm-btn-outline"><i class="fas fa-plus"></i> Add Services</a>
                    <a href="manage_car_sizes.php" class="pm-btn-outline"><i class="fas fa-plus"></i> Add Sizes</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <form method="POST">
            <div class="pm-card">
                <div class="pm-card-top">
                    <h2><i class="fas fa-th"></i> Service Pricing Grid</h2>
                </div>
                
                <div class="pm-table-wrap">
                    <table class="pm-matrix">
                        <thead>
                            <tr>
                                <th>Services \ Sizes</th>
                                <?php foreach ($car_sizes as $size): ?>
                                    <th><?php echo htmlspecialchars($size['name']); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <tr>
                                    <th>
                                        <?php echo htmlspecialchars($service['name']); ?>
                                    </th>
                                    
                                    <?php foreach ($car_sizes as $size): 
                                        $current_price = isset($pricing_data[$service['id']][$size['id']]) ? $pricing_data[$service['id']][$size['id']] : '0.00';
                                    ?>
                                        <td>
                                            <div class="pm-input-wrapper">
                                                <span class="pm-currency">₵</span>
                                                <input type="number" step="0.01" min="0" class="pm-input" 
                                                       name="prices[<?php echo $service['id']; ?>][<?php echo $size['id']; ?>]" 
                                                       value="<?php echo number_format($current_price, 2, '.', ''); ?>">
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pm-card-footer">
                    <div class="pm-help">
                        <i class="fas fa-info-circle text-blue-500"></i> Set a price to 0.00 if the service is not applicable for that size.
                    </div>
                    <button type="submit" class="pm-btn-save">
                        <i class="fas fa-save"></i> Save All Prices
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>
