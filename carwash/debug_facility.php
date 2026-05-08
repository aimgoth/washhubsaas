<?php
// Quick diagnostic — delete this file after debugging!
error_reporting(E_ALL); ini_set('display_errors', 1);

// Load .env manually
$envPath = __DIR__ . '/.env';
$envVars = [];
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        [$k, $v] = explode('=', $line, 2);
        $envVars[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

$server   = $envVars['DB_SERVER']   ?? $envVars['DB_HOST']     ?? '127.0.0.1';
$user     = $envVars['DB_USERNAME'] ?? $envVars['DB_USER']     ?? 'root';
$pass     = $envVars['DB_PASSWORD'] ?? $envVars['DB_PASS']     ?? '';
$port     = (int)($envVars['DB_PORT'] ?? 3306);
$masterDb = $envVars['DB_NAME']     ?? 'carwash_db';

echo "<style>body{font-family:monospace;padding:30px;background:#0f172a;color:#e2e8f0;}
.ok{color:#4ade80;} .err{color:#f87171;} .warn{color:#facc15;}
table{border-collapse:collapse;width:100%;} td,th{padding:8px 14px;border:1px solid #334155;text-align:left;}
th{background:#1e293b;}</style>";

echo "<h2 style='color:#38bdf8'>🔍 Facility Code Debug</h2>";

// 1. Test master DB connection
echo "<h3>1. Master DB Connection</h3>";
$conn = @new mysqli($server, $user, $pass, $masterDb, $port);
if ($conn->connect_error) {
    echo "<p class='err'>❌ Cannot connect to master DB '<b>$masterDb</b>': " . htmlspecialchars($conn->connect_error) . "</p>";
    echo "<p class='warn'>⚠️ This is why the facility code fails — MySQL can't even connect to the master database.</p>";
    exit;
} else {
    echo "<p class='ok'>✅ Connected to master DB: <b>$masterDb</b> on $server:$port</p>";
}

// 2. List all databases (to see what tenant DBs exist)
echo "<h3>2. All Databases on MySQL Server</h3>";
$res = $conn->query("SHOW DATABASES");
$dbs = [];
while ($row = $res->fetch_row()) $dbs[] = $row[0];
echo "<table><tr><th>#</th><th>Database Name</th><th>Looks like tenant?</th></tr>";
foreach ($dbs as $i => $db) {
    $isTenant = (strpos($db, 'washhub_') === 0 || strpos($db, 'carwash_') === 0) && $db !== $masterDb;
    echo "<tr><td>" . ($i+1) . "</td><td><b>$db</b></td><td>" . ($isTenant ? "<span class='ok'>YES</span>" : "no") . "</td></tr>";
}
echo "</table>";

// 3. Check tenants table
echo "<h3>3. Tenants Table in Master DB</h3>";
$res2 = $conn->query("SHOW TABLES LIKE 'tenants'");
if ($res2->num_rows === 0) {
    echo "<p class='err'>❌ No 'tenants' table found in '$masterDb'</p>";
} else {
    $tenants = $conn->query("SELECT id, business_name, db_name, status, subscription_end FROM tenants");
    if ($tenants && $tenants->num_rows > 0) {
        echo "<table><tr><th>ID</th><th>Business Name</th><th>DB Name (= Facility Code)</th><th>Status</th><th>Sub End</th></tr>";
        while ($t = $tenants->fetch_assoc()) {
            $expired = !empty($t['subscription_end']) && strtotime($t['subscription_end']) < time();
            $statusClass = ($t['status'] === 'active' && !$expired) ? 'ok' : 'err';
            echo "<tr>
                <td>{$t['id']}</td>
                <td><b>" . htmlspecialchars($t['business_name']) . "</b></td>
                <td><b style='color:#38bdf8'>" . htmlspecialchars($t['db_name']) . "</b></td>
                <td class='$statusClass'>" . htmlspecialchars($t['status']) . ($expired ? ' (EXPIRED)' : '') . "</td>
                <td>" . htmlspecialchars($t['subscription_end'] ?? 'N/A') . "</td>
            </tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warn'>⚠️ Tenants table exists but has no records.</p>";
    }
}

// 4. Test connecting to a specific facility code if provided
$testCode = trim($_GET['code'] ?? '');
if ($testCode !== '') {
    echo "<h3>4. Testing Facility Code: <b style='color:#38bdf8'>" . htmlspecialchars($testCode) . "</b></h3>";
    $testConn = @new mysqli($server, $user, $pass, $testCode, $port);
    if ($testConn->connect_error) {
        echo "<p class='err'>❌ Cannot connect to database '<b>$testCode</b>': " . htmlspecialchars($testConn->connect_error) . "</p>";
        echo "<p class='warn'>Possible reasons: DB doesn't exist, typo in code, or MySQL user '$user' has no access to it.</p>";
    } else {
        echo "<p class='ok'>✅ Connected successfully to '<b>$testCode</b>'! The facility code is valid.</p>";
        // Check users table
        $uCheck = $testConn->query("SHOW TABLES LIKE 'users'");
        if ($uCheck->num_rows) {
            $uCount = $testConn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
            echo "<p class='ok'>✅ Users table exists with <b>$uCount</b> user(s).</p>";
        } else {
            echo "<p class='err'>❌ No 'users' table in this tenant DB. Schema may be incomplete.</p>";
        }
        $testConn->close();
    }
}

echo "<hr style='border-color:#334155;margin-top:30px'>";
echo "<form style='margin-top:20px'><label style='color:#94a3b8'>Test a specific facility code: </label>
<input name='code' value='" . htmlspecialchars($testCode) . "' style='padding:8px;background:#1e293b;color:#fff;border:1px solid #334155;border-radius:6px;width:300px'>
<button type='submit' style='padding:8px 16px;background:#0ea5e9;color:#fff;border:none;border-radius:6px;margin-left:8px;cursor:pointer'>Test</button>
</form>";

echo "<p style='color:#475569;margin-top:30px;font-size:12px'>⚠️ Delete <code>debug_facility.php</code> after you're done debugging.</p>";
$conn->close();
?>
