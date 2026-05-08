<?php
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
try {
    $m = new mysqli('127.0.0.1', 'root', '', 'carwash_db', 3306);
    $res = $m->query('SHOW TABLES'); 
    
    // First, list all tables
    $tables = [];
    while ($row = $res->fetch_array()) {
        $tables[] = $row[0];
    }
    
    // Try to drop each table
    $m->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        try {
            $m->query("DROP TABLE IF EXISTS `$t`");
            echo "Successfully dropped: $t\n";
        } catch (Exception $e) {
            echo "Failed to drop $t - ". $e->getMessage() ."\n";
            // If dropping fails because it doesn't exist in engine, we must delete the .ibd file physically?
            // Actually DROP TABLE usually forces it to drop even if corrupted in MyISAM/InnoDB
        }
    }
    $m->query('SET FOREIGN_KEY_CHECKS = 1');
    echo "Done dropping tables.\n";
} catch (Exception $e) {
    echo "Fatal Error: ". $e->getMessage() ."\n";
}
