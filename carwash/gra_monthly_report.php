<?php
require_once 'config/session.php';
require_once 'config/database.php';

// Ensure session is started before checking role
if (session_status() === PHP_SESSION_NONE) {
    }

function generateLiveSample(mysqli $conn, string $start, string $end): array
{
    $report = [
        'total_revenue' => 0,
        'target_amount' => 0,
        'extracted_total' => 0,
        'status' => 'live',
        'note' => 'Temporary live sample'
    ];
    $items = [];
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS total
         FROM car_washes
         WHERE created_at >= ? AND created_at <= ?'
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $totalRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalRevenue = (float)($totalRow['total'] ?? 0);
    $report['total_revenue'] = $totalRevenue;
    $report['target_amount'] = getTargetAmount($totalRevenue);

    $candidateSql = "SELECT cw.id,
                cw.amount,
                cw.created_at AS wash_date,
                cw.number_plate,
                COALESCE(w.full_name, 'N/A') AS washer_name,
                COALESCE(c.name, 'Unknown') AS vehicle_category,
                COALESCE(cs.name, 'Regular') AS size_name,
                COALESCE(s.name, 'General Service') AS service_name
          FROM car_washes cw
          LEFT JOIN workers w ON cw.worker_id = w.id
          LEFT JOIN categories c ON cw.category_id = c.id
          LEFT JOIN services s ON cw.service_id = s.id
          LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
          WHERE cw.created_at BETWEEN ? AND ?
          ORDER BY RAND()
          LIMIT 2500";
    $candidateStmt = $conn->prepare($candidateSql);
    $candidateStmt->bind_param('ss', $start, $end);
    $candidateStmt->execute();
    $candidates = $candidateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $candidateStmt->close();

    $selected = [];
    $accumulated = 0.0;
    foreach ($candidates as $candidate) {
        if ($accumulated >= $report['target_amount']) {
            break;
        }
        $amount = (float)$candidate['amount'];
        if ($amount <= 0) {
            continue;
        }
        $selected[] = $candidate;
        $accumulated += $amount;
    }
    if (empty($selected) && !empty($candidates)) {
        $selected[] = $candidates[0];
        $accumulated = (float)$candidates[0]['amount'];
    }

    $report['extracted_total'] = $accumulated;
    return [$report, $selected];
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    header('Location: login.php');
    exit();
}

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

$month = isset($_REQUEST['month']) ? (int)$_REQUEST['month'] : (int)date('n');
$year = isset($_REQUEST['year']) ? (int)$_REQUEST['year'] : (int)date('Y');
$month = max(1, min(12, $month));
$year = max(2020, min((int)date('Y') + 1, $year));

$periodStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$periodEnd = date('Y-m-t 23:59:59', strtotime($periodStart));

$errors = [];
$messages = [];
$report = null;
$reportItems = [];

$graTablesExist = false;
$tableCheck = $conn->query("SHOW TABLES LIKE 'gra_monthly_reports'");
if ($tableCheck) {
    $graTablesExist = $tableCheck->num_rows > 0;
    $tableCheck->close();
}

function getTargetAmount(float $totalRevenue): float
{
    if ($totalRevenue < 25000) {
        return 7000;
    }
    if ($totalRevenue < 30000) {
        return 9000;
    }
    if ($totalRevenue < 35000) {
        return 10000;
    }
    return 12000;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    try {
        if (!$graTablesExist) {
            list($report, $reportItems) = generateLiveSample($conn, $periodStart, $periodEnd);
            $skipPersistence = true;
            throw new Exception('SKIP_PERSISTENCE');
        }

        $stmt = $conn->prepare(
            'SELECT COALESCE(SUM(amount), 0) as total FROM car_washes WHERE created_at >= ? AND created_at <= ? AND status = ?'
        );
        if (!$stmt) {
            throw new Exception('Revenue query failed: ' . $conn->error);
        }
        $statusCompleted = 'completed';
        $stmt->bind_param('sss', $periodStart, $periodEnd, $statusCompleted);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalRevenue = (float)($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $targetAmount = getTargetAmount($totalRevenue);
        $note = sprintf('Targeted sample for %s %d (revenue: GHS %.2f)', $months[$month], $year, $totalRevenue);

        $sql = "SELECT
            cw.id,
            cw.amount,
            cw.created_at,
            cw.number_plate,
            COALESCE(w.full_name, 'N/A') as washer_name,
            COALESCE(c.name, 'Other') as vehicle_category,
            COALESCE(cs.name, 'Undefined') as size_name,
            COALESCE(s.name, cw.service_type, 'General Service') as service_name
        FROM car_washes cw
        LEFT JOIN workers w ON cw.worker_id = w.id
        LEFT JOIN categories c ON cw.category_id = c.id
        LEFT JOIN services s ON cw.service_id = s.id
        LEFT JOIN car_sizes cs ON cw.car_size_id = cs.id
        WHERE cw.status = ?
          AND cw.created_at >= ?
          AND cw.created_at <= ?
        ORDER BY RAND()
        LIMIT 2500";

        $candidateStmt = $conn->prepare($sql);
        if (!$candidateStmt) {
            throw new Exception('Candidate query failed: ' . $conn->error);
        }
        $candidateStmt->bind_param('sss', $statusCompleted, $periodStart, $periodEnd);
        $candidateStmt->execute();
        $candidates = $candidateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $candidateStmt->close();

        $selected = [];
        $accumulated = 0.0;
        foreach ($candidates as $candidate) {
            if ($accumulated >= $targetAmount) {
                break;
            }
            $amount = (float)$candidate['amount'];
            if ($amount <= 0) {
                continue;
            }
            $selected[] = $candidate;
            $accumulated += $amount;
        }

        if (empty($selected) && !empty($candidates)) {
            $selected[] = $candidates[0];
            $accumulated = (float)$candidates[0]['amount'];
        }

        $extractedTotal = $accumulated;

        $insertReport = $conn->prepare(
            'INSERT INTO gra_monthly_reports
            (report_month, report_year, total_revenue, target_amount, extracted_total, status, note)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_revenue = VALUES(total_revenue),
                target_amount = VALUES(target_amount),
                extracted_total = VALUES(extracted_total),
                status = VALUES(status),
                note = VALUES(note),
                updated_at = NOW()'
        );
        if (!$insertReport) {
            throw new Exception('Report insert failed: ' . $conn->error);
        }
        $statusValue = 'generated';
        $insertReport->bind_param(
            'iidddds',
            $month,
            $year,
            $totalRevenue,
            $targetAmount,
            $extractedTotal,
            $statusValue,
            $note
        );
        if (!$insertReport->execute()) {
            throw new Exception('Failed to save report: ' . $insertReport->error);
        }
        $insertReport->close();

        $reportId = $conn->insert_id;
        if ($reportId === 0) {
            $fetchId = $conn->prepare('SELECT id FROM gra_monthly_reports WHERE report_month = ? AND report_year = ?');
            $fetchId->bind_param('ii', $month, $year);
            $fetchId->execute();
            $fetchId->bind_result($reportId);
            $fetchId->fetch();
            $fetchId->close();
        }

        if (!$reportId) {
            throw new Exception('Unable to determine report id for GRA data');
        }

        $clearItems = $conn->prepare('DELETE FROM gra_monthly_report_items WHERE gra_report_id = ?');
        $clearItems->bind_param('i', $reportId);
        $clearItems->execute();
        $clearItems->close();

        $itemInsert = $conn->prepare(
            'INSERT INTO gra_monthly_report_items
            (gra_report_id, car_wash_id, washer_name, vehicle_category, amount, size_name, service_name, number_plate, wash_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
        );
        if (!$itemInsert) {
            throw new Exception('Failed to prepare item insert: ' . $conn->error);
        }

        foreach ($selected as $row) {
            $washDate = date('Y-m-d H:i:s', strtotime($row['created_at']));
            $notes = sprintf('Sampled on %s', date('Y-m-d H:i:s'));
            $itemInsert->bind_param(
                'iissdsssss',
                $reportId,
                $row['id'],
                $row['washer_name'],
                $row['vehicle_category'],
                $row['amount'],
                $row['size_name'],
                $row['service_name'],
                $row['number_plate'],
                $washDate,
                $notes
            );
            if (!$itemInsert->execute()) {
                throw new Exception('Failed to insert item: ' . $itemInsert->error);
            }
        }
        $itemInsert->close();

        $conn->commit();
        $messages[] = 'GRA sample report generated successfully.';
    } catch (Exception $ex) {
        $conn->rollback();
        if ($ex->getMessage() !== 'SKIP_PERSISTENCE') {
            $errors[] = $ex->getMessage();
        }
    }
}

if ($graTablesExist) {
    $loadReport = $conn->prepare('SELECT * FROM gra_monthly_reports WHERE report_month = ? AND report_year = ? LIMIT 1');
    $loadReport->bind_param('ii', $month, $year);
    $loadReport->execute();
    $reportResult = $loadReport->get_result();
    $report = $reportResult->fetch_assoc();
    $loadReport->close();
} else {
    list($report, $reportItems) = generateLiveSample($conn, $periodStart, $periodEnd);
}

if ($report && $graTablesExist) {
    $itemsStmt = $conn->prepare('SELECT * FROM gra_monthly_report_items WHERE gra_report_id = ? ORDER BY amount DESC, id ASC');
    $itemsStmt->bind_param('i', $report['id']);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $reportItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
} elseif (!$graTablesExist && empty($reportItems)) {
    // ensure reportItems is at least set from live sample
    list($report, $reportItems) = generateLiveSample($conn, $periodStart, $periodEnd);
}

$yearOptions = [];
$yearQuery = $conn->query('SELECT DISTINCT YEAR(created_at) as year FROM car_washes ORDER BY year DESC');
while ($row = $yearQuery->fetch_assoc()) {
    $yearOptions[] = (int)$row['year'];
}
if (empty($yearOptions)) {
    $yearOptions = [(int)date('Y')];
}

$reportTitle = sprintf('All Records for %s %d', $months[$month], $year);
$pageTitle = 'All Records';
include 'includes/header.php';
?>
<style>
    .gra-report-card {
        border-radius: 0.75rem;
        box-shadow: 0 0.25rem 0.75rem rgba(15, 23, 42, 0.08);
        border: 1px solid #e5e7eb;
    }

    .gra-report-table-wrapper {
        border-radius: 0.75rem;
        overflow: hidden;
        box-shadow: 0 0.35rem 1.1rem rgba(15, 23, 42, 0.10);
        border: 1px solid #e5e7eb;
    }

    .table.gra-report-table {
        margin-bottom: 0;
        border-collapse: collapse;
        font-size: 0.85rem;
        table-layout: fixed;
        width: 100%;
    }

    .table.gra-report-table thead th {
        background: linear-gradient(135deg, #0d6efd, #2563eb);
        color: #ffffff;
        border-bottom: 0;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-size: 0.78rem;
    }

    .table.gra-report-table th,
    .table.gra-report-table td {
        border: 1px solid #d1d5db !important;
        padding: 0.5rem 0.6rem;
        vertical-align: middle;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Narrower columns for index, washer, category, service so Plate has room */
    .table.gra-report-table th:nth-child(1),
    .table.gra-report-table td:nth-child(1) {
        width: 4%;
        text-align: center;
    }

    .table.gra-report-table th:nth-child(2),
    .table.gra-report-table td:nth-child(2) {
        width: 14%;
    }

    .table.gra-report-table th:nth-child(3),
    .table.gra-report-table td:nth-child(3) {
        width: 14%;
    }

    .table.gra-report-table th:nth-child(4),
    .table.gra-report-table td:nth-child(4) {
        width: 10%;
    }

    .table.gra-report-table th:nth-child(5),
    .table.gra-report-table td:nth-child(5) {
        width: 10%;
    }

    .table.gra-report-table th:nth-child(6),
    .table.gra-report-table td:nth-child(6) {
        width: 16%;
    }

    .table.gra-report-table th:nth-child(7),
    .table.gra-report-table td:nth-child(7) {
        width: 16%;
    }

    .table.gra-report-table th:nth-child(8),
    .table.gra-report-table td:nth-child(8) {
        width: 16%;
        white-space: normal;
        text-overflow: clip;
    }

    .table.gra-report-table tbody tr:nth-child(even) {
        background-color: #f9fafb;
    }

    .table.gra-report-table tbody tr:hover {
        background-color: #eef2ff;
    }

    @media print {
        body {
            background: #ffffff;
        }

        .btn,
        form,
        .alert {
            display: none !important;
        }

        .gra-report-table-wrapper {
            box-shadow: none;
            border-color: #000000;
        }

        .table.gra-report-table th,
        .table.gra-report-table td {
            border-color: #000000 !important;
            white-space: nowrap;
        }

        /* In print, still allow the Plate column to wrap so it is not cut off */
        .table.gra-report-table th:nth-child(8),
        .table.gra-report-table td:nth-child(8) {
            white-space: normal;
        }
    }
</style>
<div class="container mt-4">
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars(implode(' | ', $errors)); ?>
        </div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars(implode(' | ', $messages)); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="generate">
                <div class="col-auto">
                    <label class="form-label" for="month">Month</label>
                    <select class="form-select" name="month" id="month">
                        <?php foreach ($months as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $value === $month ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label" for="year">Year</label>
                    <select class="form-select" name="year" id="year">
                        <?php foreach ($yearOptions as $yearOption): ?>
                            <option value="<?php echo $yearOption; ?>" <?php echo $yearOption === $year ? 'selected' : ''; ?>>
                                <?php echo $yearOption; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary w-100">Generate GRA Sample</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report): ?>
        <div class="row justify-content-center mb-2">
            <div class="col-md-5 col-lg-4">
                <div class="card text-center mb-3">
                    <div class="card-body">
                        <h6 class="text-muted">Total Revenue</h6>
                        <p class="fs-4 fw-bold">GHS <?php echo number_format($report['target_amount'], 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-lg-4">
                <div class="card text-center mb-3">
                    <div class="card-body">
                        <h6 class="text-muted">All Records</h6>
                        <p class="fs-4 fw-bold"><?php echo count($reportItems); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card gra-report-card gra-report-table-wrapper">
            <div class="card-body">
                <div class="text-center mb-3">
                    <h5 class="mb-2"><?php echo htmlspecialchars($reportTitle); ?></h5>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a href="monthly_report.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Summary
                        </a>
                        <button type="button" class="btn btn-primary" onclick="window.print();">
                            <i class="fas fa-file-pdf"></i> Download / Print PDF
                        </button>
                    </div>
                </div>
                <h6 class="card-subtitle text-muted mb-3">All Records Details</h6>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered gra-report-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Washer</th>
                                <th>Category</th>
                                <th>Amount (GHS)</th>
                                <th>Size</th>
                                <th>Service</th>
                                <th>Date</th>
                                <th>Plate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportItems as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($item['washer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['vehicle_category']); ?></td>
                                    <td class="text-end"><?php echo number_format($item['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($item['size_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['service_name']); ?></td>
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
                                    <td><?php echo htmlspecialchars($item['number_plate']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            No GRA sample has been generated for this period yet. Use the button above to build the extraction.
        </div>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
