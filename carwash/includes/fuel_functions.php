<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Record a new fuel purchase
 */
function recordFuelPurchase($amount, $liters, $userId) {
    global $conn;
    
    // Check for active purchase
    $stmt = $conn->prepare("SELECT id FROM fuel_purchases WHERE status = 'active' LIMIT 1");
    $stmt->execute();
    if ($stmt->fetch()) {
        throw new Exception("Please finish the current fuel purchase before starting a new one");
    }
    
    $stmt = $conn->prepare("
        INSERT INTO fuel_purchases (amount_cedis, liters, start_date, created_by, status)
        VALUES (?, ?, NOW(), ?, 'active')
    ");
    $stmt->bind_param("ddi", $amount, $liters, $userId);
    $stmt->execute();
    return $conn->insert_id;
}

/**
 * Record a wash during generator use
 */
function recordGeneratorWash($washType, $userId) {
    global $conn;
    
    // Get active fuel purchase
    $stmt = $conn->prepare("
        SELECT id FROM fuel_purchases 
        WHERE status = 'active' 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$fuel = $result->fetch_assoc()) {
        throw new Exception("No active fuel purchase found");
    }
    
    $stmt = $conn->prepare("
        INSERT INTO generator_washes (fuel_purchase_id, wash_type, wash_date, created_by)
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->bind_param("isi", $fuel['id'], $washType, $userId);
    $stmt->execute();
    return $conn->insert_id;
}

/**
 * Finish fuel usage and generate report
 */
function finishFuelUsage($fuelPurchaseId, $userId) {
    global $conn;
    
    $conn->begin_transaction();
    try {
        // Mark fuel purchase as finished
        $update_fuel_stmt = $conn->prepare("
            UPDATE fuel_purchases 
            SET status = 'finished', end_date = NOW() 
            WHERE id = ? AND status = 'active'
        ");
        $update_fuel_stmt->bind_param("i", $fuelPurchaseId);
        $update_fuel_stmt->execute();
        
        if ($update_fuel_stmt->affected_rows === 0) {
            throw new Exception("No active fuel purchase found with ID: $fuelPurchaseId, or it was already finished.");
        }

        // Washes are now inserted into car_washes in real-time.
        // The generator_washes table serves as a log for fuel-specific activity.
        
        $conn->commit();

        // Generate report after committing
        $report = generateFuelReport($fuelPurchaseId);
        return $report;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Generate fuel usage report
 */
function generateFuelReport($fuelPurchaseId) {
    global $conn;
    
    // Get fuel details
    $stmt = $conn->prepare("
        SELECT 
            amount_cedis, 
            liters, 
            price_per_liter,
            start_date,
            end_date
        FROM fuel_purchases 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $fuelPurchaseId);
    $stmt->execute();
    $fuel = $stmt->get_result()->fetch_assoc();
    
    // Get wash counts
    $stmt = $conn->prepare("
        SELECT 
            fp.*,
            COALESCE(COUNT(gw.id), 0) as total_washes,
            COALESCE(SUM(CASE WHEN gw.wash_type = 'car' THEN 1 ELSE 0 END), 0) as total_cars,
            COALESCE(SUM(CASE WHEN gw.wash_type = 'motor' THEN 1 ELSE 0 END), 0) as total_motors,
            COALESCE(SUM(CASE WHEN gw.wash_type = 'carpet' THEN 1 ELSE 0 END), 0) as total_carpets
        FROM fuel_purchases fp
        LEFT JOIN generator_washes gw ON fp.id = gw.fuel_purchase_id
        GROUP BY fp.id
        ORDER BY fp.start_date DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $fuelPurchaseId);
    $stmt->execute();
    $washes = $stmt->get_result()->fetch_assoc();
    
    return [
        'fuel' => $fuel,
        'washes' => $washes,
        'cost_per_wash' => $fuel['amount_cedis'] / max(1, $washes['total_washes'])
    ];
}

/**
 * Get current fuel status
 */
function getCurrentFuelStatus() {
    global $conn;
    
    $status = [
        'has_active' => false,
        'purchase' => null,
        'wash_stats' => null,
        'cost_per_wash' => null
    ];
    
    // Get active purchase
    $stmt = $conn->query("
        SELECT id, amount_cedis, liters, start_date 
        FROM fuel_purchases 
        WHERE status = 'active' 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    
    if ($status['purchase'] = $stmt->fetch_assoc()) {
        $status['has_active'] = true;
        
        // Get wash counts by type
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(wash_type = 'car'), 0) as total_cars,
                COALESCE(SUM(wash_type = 'motor'), 0) as total_motors,
                COALESCE(SUM(wash_type = 'carpet'), 0) as total_carpets,
                COUNT(*) as total_washes
            FROM generator_washes 
            WHERE fuel_purchase_id = ?
        ");
        $stmt->bind_param("i", $status['purchase']['id']);
        $stmt->execute();
        $wash_stats = $stmt->get_result()->fetch_assoc();

        $status['wash_stats'] = $wash_stats;
        
        if ($wash_stats['total_washes'] > 0) {
            $status['cost_per_wash'] = $status['purchase']['amount_cedis'] / $wash_stats['total_washes'];
        }
    }
    
    return $status;
}

/**
 * Get all fuel purchases with stats
 */
function getAllFuelPurchases($limit = 30) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            fp.*,
            (SELECT COUNT(*) FROM generator_washes gw WHERE gw.fuel_purchase_id = fp.id) as total_washes,
            COALESCE((SELECT SUM(CASE WHEN gw.wash_type = 'car' THEN 1 ELSE 0 END) FROM generator_washes gw WHERE gw.fuel_purchase_id = fp.id), 0) as total_cars,
            COALESCE((SELECT SUM(CASE WHEN gw.wash_type = 'motor' THEN 1 ELSE 0 END) FROM generator_washes gw WHERE gw.fuel_purchase_id = fp.id), 0) as total_motors,
            COALESCE((SELECT SUM(CASE WHEN gw.wash_type = 'carpet' THEN 1 ELSE 0 END) FROM generator_washes gw WHERE gw.fuel_purchase_id = fp.id), 0) as total_carpets
        FROM fuel_purchases fp
        WHERE fp.status = 'finished'
        ORDER BY fp.start_date DESC
        LIMIT ?
    ");
    $limit = (int)$limit;
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Notify super admin about fuel completion
 */
function notifySuperAdminFuelFinished($purchaseId, $report) {
    global $conn;
    
    // Get super admin usernames
    $superAdmins = $conn->query("
        SELECT username FROM users WHERE role = 'superadmin' AND status = 'active'
    ")->fetch_all(MYSQLI_ASSOC);
    
    $subject = "Fuel Usage Completed - " . date('M j, Y');
    $message = "
    <h2>Fuel Usage Report</h2>
    <p>A fuel purchase has been marked as finished.</p>
    
    <h3>Fuel Details</h3>
    <p>Amount: GHS " . number_format($report['fuel']['amount_cedis'], 2) . "</p>
    <p>Liters: " . number_format($report['fuel']['liters'], 2) . " L</p>
    <p>Period: " . date('M j, Y H:i', strtotime($report['fuel']['start_date'])) . " to " . 
                  date('M j, Y H:i', strtotime($report['fuel']['end_date'])) . "</p>
    
    <h3>Wash Statistics</h3>
    <p>Total Cars: " . $report['washes']['total_cars'] . "</p>
    <p>Total Motors: " . $report['washes']['total_motors'] . "</p>
    <p>Total Washes: " . $report['washes']['total_washes'] . "</p>
    <p>Cost per Wash: GHS " . number_format($report['cost_per_wash'], 2) . "</p>
    ";
    
    // In a real implementation, you would send emails here
    // For now, we'll log it
    error_log("Fuel report generated for purchase #$purchaseId");
    
    return count($superAdmins); // Return count of notified admins
}
?>
