<?php
require_once 'config/session.php';
require_once 'config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    }

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    header('Location: login.php');
    exit();
}

$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($reportId <= 0) {
    die('Invalid report identifier.');
}

$stmt = $conn->prepare('SELECT * FROM gra_monthly_reports WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $reportId);
$stmt->execute();
$reportResult = $stmt->get_result();
$report = $reportResult ? $reportResult->fetch_assoc() : null;
$stmt->close();

if (!$report) {
    die('Report not found.');
}

$items = [];
$itemStmt = $conn->prepare('SELECT * FROM gra_monthly_report_items WHERE gra_report_id = ? ORDER BY wash_date DESC, id DESC');
$itemStmt->bind_param('i', $reportId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
if ($itemResult) {
    while ($row = $itemResult->fetch_assoc()) {
        $items[] = $row;
    }
}
$itemStmt->close();

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$page_title = 'GRA Sample ' . ($months[$report['report_month']] ?? 'Month') . ' ' . $report['report_year'];
include 'includes/header.php';
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="text-muted mb-0">Selected wash entries held for Ghana Revenue Authority submissions.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="gra_monthly_reports.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to list
            </a>
            <button class="btn btn-primary" onclick="window.print();">
                <i class="fas fa-file-pdf"></i> Download as PDF
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <p class="text-muted mb-1">Monthly Revenue</p>
                    <h3>GHS <?php echo number_format($report['total_revenue'], 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <p class="text-muted mb-1">Target Extract</p>
                    <h3>GHS <?php echo number_format($report['target_amount'], 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-light">
                <div class="card-body">
                    <p class="text-muted mb-1">Extracted Total</p>
                    <h3 class="text-success">GHS <?php echo number_format($report['extracted_total'], 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center bg-light">
                <div class="card-body">
                    <p class="text-muted mb-1">Records</p>
                    <h3><?php echo count($items); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Sampled Wash Records</h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Washer</th>
                            <th>Category</th>
                            <th class="text-end">Amount</th>
                            <th>Size</th>
                            <th>Service</th>
                            <th>Date</th>
                            <th>Plate</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No sample items yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($item['washer_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($item['vehicle_category'] ?? 'Unknown'); ?></td>
                                    <td class="text-end">GHS <?php echo number_format($item['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($item['size_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($item['service_name'] ?? 'General'); ?></td>
                                    <td>
                                        <?php
                                        $washDate = $item['wash_date'] ?? $item['created_at'] ?? null;
                                        if (!empty($washDate)) {
                                            echo date('Y-m-d H:i', strtotime($washDate));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['number_plate'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($item['notes'] ?? 'Sampled entry'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    window.addEventListener('afterprint', function() {
        // Optional: show toast or reset UI after print
    });
</script>

<?php include 'includes/footer.php'; ?>
