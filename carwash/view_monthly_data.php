<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/monthly_data_errors.log');

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Function to log errors
function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message);
    return $message;
}

// Start output buffering and error handling
ob_start();

// Set error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $message = "Error [$errno] $errstr in $errfile on line $errline";
    logError($message);
    return true;
});

// Set exception handler
set_exception_handler(function($e) {
    logError("Uncaught exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    die("<div class='alert alert-danger'>A system error occurred. Please try again later or contact support.</div>");
});


try {
    // Get month and year from query parameters or use current month/year
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    
    // Log request
    logError("Viewing monthly data for $month/$year");
    
    // Include database configuration
    require_once 'config/database.php';

// Build the query with worker and admin information
$query = "SELECT 
    cw.id,
    cw.number_plate,
    s.name as service_name,
    cs.name as size_name,
    cw.amount,
    cw.created_at,
    cw.status,
    c.name as vehicle_category,
    w.full_name as worker_name,
    u.full_name as admin_name
FROM car_washes cw
LEFT JOIN services s ON cw.service_id = s.id
LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
LEFT JOIN categories c ON cw.category_id = c.id
LEFT JOIN workers w ON cw.worker_id = w.id
LEFT JOIN users u ON cw.admin_id = u.id
WHERE MONTH(cw.created_at) = ? 
AND YEAR(cw.created_at) = ?
AND cw.status IS NOT NULL";

$params = [$month, $year];
$types = 'ii';

// Add search condition if search term exists
if (!empty($search)) {
    $query .= " AND (cw.number_plate LIKE ? OR s.name LIKE ? OR cs.name LIKE ? OR cw.amount = ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    
    // Check if search term is a valid number for amount search
    if (is_numeric($search)) {
        $params[] = $search;
    } else {
        $params[] = 0; // Default value if search is not a number
    }
    $types .= 'sssd';
}

$query .= " ORDER BY cw.created_at DESC";

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Failed to prepare query: " . $conn->error);
    }
    
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result === false) {
        throw new Exception("Failed to get result: " . $stmt->error);
    }
    
    // Get month name
    $monthName = date('F', mktime(0, 0, 0, $month, 10));
    
} catch (Exception $e) {
    // Log the error
    logError("Error in view_monthly_data.php: " . $e->getMessage());
    
    // Display a user-friendly error message
    die("<div class='alert alert-danger'>An error occurred while loading the data. Please try again later.</div>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Monthly Data - Car Wash Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .search-container {
            margin: 20px 0;
        }
        .back-btn {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>All Data for <?php echo $monthName . ' ' . $year; ?></h2>
            <a href="monthly_report.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-secondary back-btn">
                <i class="fas fa-arrow-left"></i> Back to Summary
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="search-container">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                        <div class="col-md-8">
                            <input type="text" name="search" class="form-control" placeholder="Search by plate, service, size, or amount..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                        <div class="col-md-2">
                            <a href="?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table id="monthlyDataTable" class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Number Plate</th>
                                <th>Service</th>
                                <th>Size</th>
                                <th>Category</th>
                                <th>Amount (GHS)</th>
                                <th>Washer</th>
                                <th>Assigned By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            $totalAmount = 0;
                            while ($row = $result->fetch_assoc()): 
                                $totalAmount += $row['amount'];
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['number_plate']); ?></td>
                                    <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['size_name']); ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($row['vehicle_category'])); ?></td>
                                    <td class="text-end"><?php echo number_format($row['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($row['worker_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['admin_name'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-dark">
                                <th colspan="5" class="text-end">Total Amount:</th>
                                <th class="text-end">GHS <?php echo number_format($totalAmount, 2); ?></th>
                                <th colspan="2"></th>
                                <th><?php echo ($counter - 1); ?> records</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#monthlyDataTable').DataTable({
                "pageLength": 25,
                "order": [[0, "asc"]],
                "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                "language": {
                    "search": "Search:",
                    "lengthMenu": "Show _MENU_ entries per page",
                    "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                    "infoEmpty": "No entries to show",
                    "infoFiltered": "(filtered from _MAX_ total entries)",
                    "zeroRecords": "No matching records found"
                }
            });
        });
    </script>
</body>
</html>
