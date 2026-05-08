<?php
require_once __DIR__ . '/config/database.php';

// Ensure core tables exist and seed OMO item
function supplies_bootstrap(mysqli $conn): int {
    $conn->query("CREATE TABLE IF NOT EXISTS supplies_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        unit VARCHAR(50) NOT NULL DEFAULT 'bags',
        threshold INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS supplies_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requester_user_id INT NOT NULL,
        item_id INT NOT NULL,
        qty_requested INT NOT NULL,
        status ENUM('pending','approved','denied','delivered','received') NOT NULL DEFAULT 'pending',
        notes VARCHAR(255) NULL,
        supplier_phone VARCHAR(32) NULL,
        created_at DATETIME NOT NULL,
        decided_by INT NULL,
        decided_at DATETIME NULL,
        supplier_notified_at DATETIME NULL,
        INDEX(requester_user_id), INDEX(item_id), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure supplier_phone column exists for upgrades
    try {
        $col = $conn->query("SHOW COLUMNS FROM supplies_requests LIKE 'supplier_phone'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE supplies_requests ADD COLUMN supplier_phone VARCHAR(32) NULL AFTER notes");
        }
    } catch (mysqli_sql_exception $e) { /* ignore */ }

    $conn->query("CREATE TABLE IF NOT EXISTS supplies_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        delivered_by_user_id INT NULL,
        delivered_at DATETIME NOT NULL,
        qty_delivered INT NOT NULL,
        INDEX(request_id), INDEX(delivered_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS supplies_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_user_id INT NOT NULL,
        item_id INT NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NULL,
        qty_received INT NOT NULL DEFAULT 0,
        request_id_start INT NULL,
        request_id_end INT NULL,
        cars_total INT NOT NULL DEFAULT 0,
        motors_total INT NOT NULL DEFAULT 0,
        carpets_total INT NOT NULL DEFAULT 0,
        report_json JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(admin_user_id), INDEX(item_id), INDEX(start_at), INDEX(end_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed OMO item if not exists
    $omoId = 0;
    if ($stmt = $conn->prepare("SELECT id FROM supplies_items WHERE name = 'OMO' LIMIT 1")) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) { $omoId = (int)$row['id']; }
        $stmt->close();
    }
    if ($omoId === 0) {
        if ($stmt = $conn->prepare("INSERT INTO supplies_items (name, unit, threshold) VALUES ('OMO','bags',0)")) {
            $stmt->execute();
            $omoId = $conn->insert_id;
            $stmt->close();
        }
    }
    return $omoId;
}

// Compute totals between two timestamps inclusive over flexible date columns in car_washes
function compute_totals_for_period(mysqli $conn, string $startAt, string $endAt): array {
    $cwCols = [];
    if ($rs = $conn->query("SHOW COLUMNS FROM car_washes")) {
        while ($c = $rs->fetch_assoc()) { $cwCols[strtolower($c['Field'])] = true; }
    }
    $hasCreatedAt = isset($cwCols['created_at']);
    $hasCompletedAt = isset($cwCols['completed_at']);
    $hasTimestamp = isset($cwCols['timestamp']);
    $dateConds = [];
    if ($hasCreatedAt)  $dateConds[] = "(cw.created_at BETWEEN ? AND ?)";
    if ($hasCompletedAt)$dateConds[] = "(cw.completed_at BETWEEN ? AND ?)";
    if ($hasTimestamp)  $dateConds[] = "(cw.timestamp BETWEEN ? AND ?)";
    if (empty($dateConds)) return ['cars'=>0,'motors'=>0,'carpets'=>0];
    $where = '(' . implode(' OR ', $dateConds) . ')';

    // Determine vehicle type/service mapping
    // Prefer a vehicle_type column; else infer via service name keywords
    $hasVehType = isset($cwCols['vehicle_type']);

    $sql = "SELECT 
                SUM(CASE 
                    WHEN " . ($hasVehType ? "cw.vehicle_type='car'" : "LOWER(s.name) LIKE '%car%'") . " THEN 1 ELSE 0 END) AS cars,
                SUM(CASE 
                    WHEN " . ($hasVehType ? "cw.vehicle_type='motor'" : "LOWER(s.name) LIKE '%motor%'") . " THEN 1 ELSE 0 END) AS motors,
                SUM(CASE 
                    WHEN " . ($hasVehType ? "cw.vehicle_type='carpet'" : "LOWER(s.name) LIKE '%carpet%'") . " THEN 1 ELSE 0 END) AS carpets
            FROM car_washes cw
            LEFT JOIN services s ON cw.service_id = s.id
            WHERE $where";

    $bindCountPerCond = 2; // for BETWEEN ? AND ?
    $bindTotal = $bindCountPerCond * count($dateConds);
    $types = str_repeat('s', $bindTotal);
    $params = [];
    foreach ($dateConds as $_) { $params[] = $startAt; $params[] = $endAt; }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : ['cars'=>0,'motors'=>0,'carpets'=>0];
    $stmt->close();

    return [
        'cars' => (int)($row['cars'] ?? 0),
        'motors' => (int)($row['motors'] ?? 0),
        'carpets' => (int)($row['carpets'] ?? 0)
    ];
}

function get_current_open_period(mysqli $conn, int $adminUserId, int $itemId): ?array {
    if ($stmt = $conn->prepare("SELECT * FROM supplies_periods WHERE admin_user_id=? AND item_id=? AND end_at IS NULL ORDER BY start_at DESC LIMIT 1")) {
        $stmt->bind_param('ii', $adminUserId, $itemId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
    return null;
}

/**
 * Ensure an open OMO period exists for the admin (session-based wash tracking)
 * Returns the open period row (existing or newly created)
 */
function ensure_open_omo_period(mysqli $conn, int $adminUserId): ?array {
    // Ensure core tables and OMO item exist
    $omoId = supplies_bootstrap($conn);

    // Try to get an existing open period first
    $open = get_current_open_period($conn, $adminUserId, $omoId);
    if ($open) { return $open; }

    // Otherwise, create a new open period starting now with qty_received = 0
    $now = date('Y-m-d H:i:s');
    if ($stmt = $conn->prepare("INSERT INTO supplies_periods (admin_user_id, item_id, start_at, qty_received, created_at) VALUES (?,?,?,?,NOW())")) {
        $zero = 0;
        $stmt->bind_param('iisi', $adminUserId, $omoId, $now, $zero);
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            $stmt->close();
            if ($stx = $conn->prepare("SELECT * FROM supplies_periods WHERE id=? LIMIT 1")) {
                $stx->bind_param('i', $newId);
                $stx->execute();
                $resx = $stx->get_result();
                $row = $resx ? $resx->fetch_assoc() : null;
                $stx->close();
                return $row ?: null;
            }
            return null;
        }
        $stmt->close();
    }
    return null;
}

?>
