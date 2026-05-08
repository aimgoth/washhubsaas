<?php
// Database configuration with table prefix support

// Database credentials
$DB_SERVER = 'sql303.infinityfree.com';
$DB_USERNAME = 'if0_39762246';
$DB_PASSWORD = 'sybZtLsejrYQDi';
$DB_NAME = 'if0_39762246_appdb';
$DB_PORT = 3306;

// Get client prefix from session or default to empty
$CLIENT_PREFIX = $_SESSION['client_prefix'] ?? '';

// Create connection
$conn = new mysqli($DB_SERVER, $DB_USERNAME, $DB_PASSWORD, $DB_NAME, $DB_PORT);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

/**
 * Execute a prepared query with the given parameters
 * Automatically adds the client prefix to table names in the query
 * 
 * @param string $sql The SQL query with ? placeholders
 * @param string $types The types of the parameters (s=string, i=integer, d=double, b=blob)
 * @param mixed ...$params The parameters to bind
 * @return mysqli_stmt|false The prepared statement or false on failure
 */
function prepare_query($conn, $sql, $types = "", ...$params) {
    global $CLIENT_PREFIX;
    
    // Add prefix to table names (simple implementation - may need refinement)
    $prefixed_sql = preg_replace_callback(
        '/\b(from|join|into|update|table\s+)(`?\w+`?)/i',
        function($matches) use ($CLIENT_PREFIX) {
            $table = trim($matches[2], '`');
            // Don't prefix if it's already prefixed or is a special table
            if (strpos($table, $CLIENT_PREFIX) === 0 || in_array(strtolower($table), ['information_schema.tables', 'mysql.user'])) {
                return $matches[0];
            }
            return $matches[1] . '`' . $CLIENT_PREFIX . $table . '`';
        },
        $sql
    );
    
    $stmt = $conn->prepare($prefixed_sql);
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error . "\nQuery: " . $prefixed_sql);
        return false;
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    return $stmt;
}

/**
 * Get the prefixed table name
 * 
 * @param string $table The base table name
 * @return string The prefixed table name
 */
function table($table) {
    global $CLIENT_PREFIX;
    return $CLIENT_PREFIX . $table;
}
?>
