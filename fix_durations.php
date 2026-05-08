<?php
$files = [
    __DIR__ . '/carwash/end_task_delayed.php',
    __DIR__ . '/carwash/end_tasks.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);

    // Patch end_task_delayed.php
    if (strpos($file, 'end_task_delayed.php') !== false) {
        $content = preg_replace(
            '/(\$stmt = \$conn->prepare\(\'SELECT duration_minutes FROM service_durations[^\']+\'\);\s*\$stmt->bind_param[^\n]+\n\s*\$stmt->execute\(\);\s*\$res_dur = \$stmt->get_result\(\);\s*if \(\$row_dur = \$res_dur->fetch_assoc\(\)\) \{\s*\$duration_minutes = \(int\)\$row_dur\[\'duration_minutes\'\];\s*\})/',
            '// Duration calculation removed',
            $content
        );
    }
    
    // Patch end_tasks.php
    if (strpos($file, 'end_tasks.php') !== false) {
        // Fix the SELECT in the join
        $content = preg_replace(
            '/\(SELECT duration_minutes FROM service_durations WHERE service_id = wt\.service_id AND car_size_id = wt\.car_size_id LIMIT 1\) as service_duration,/',
            '0 as service_duration,',
            $content
        );
        
        // Fix the direct query
        $content = preg_replace(
            '/(\$stmt_dur = \$conn->prepare\(\'SELECT duration_minutes FROM service_durations[^\']+\'\);\s*\$stmt_dur->bind_param[^\n]+\n\s*\$stmt_dur->execute\(\);\s*\$res_dur = \$stmt_dur->get_result\(\);\s*if \(\$row_dur = \$res_dur->fetch_assoc\(\)\) \{\s*\$duration_minutes = \(int\)\$row_dur\[\'duration_minutes\'\];\s*\})/',
            '// Duration calculation removed',
            $content
        );
    }
    
    file_put_contents($file, $content);
}
echo "Done";
