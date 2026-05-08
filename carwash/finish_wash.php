<?php
require_once 'config/session.php';
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['superadmin', 'admin'])) {
    header('Location: login.php');
    exit();
}

$wash_id = (int)($_GET['id'] ?? 0);
if ($wash_id <= 0) { header('Location: washes.php'); exit(); }

// Fetch wash details
$stmt = $conn->prepare('SELECT * FROM car_washes WHERE id = ?');
$stmt->bind_param('i', $wash_id);
$stmt->execute();
$wash = $stmt->get_result()->fetch_assoc();

if (!$wash || $wash['status'] !== 'in_progress') {
    header('Location: washes.php');
    exit();
}

$is_delayed = false;
if ($wash['planned_end']) {
    $planned_end_time = new DateTime($wash['planned_end']);
    $now = new DateTime();
    if ($now > $planned_end_time) {
        $is_delayed = true;
    }
}

$delay_reasons = [];
if ($is_delayed) {
    $delay_reasons = $conn->query('SELECT id, reason FROM delay_reasons WHERE is_active = 1 ORDER BY reason')->fetch_all(MYSQLI_ASSOC);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $completed_at = (new DateTime())->format('Y-m-d H:i:s');
    $delay_reason_id = !empty($_POST['delay_reason_id']) ? (int)$_POST['delay_reason_id'] : null;
    $delay_notes = trim($_POST['delay_notes'] ?? '');

    if ($is_delayed && !$delay_reason_id) {
        $error = 'A reason for the delay is required.';
    } else {
        $stmt = $conn->prepare('UPDATE car_washes SET status = ?, completed_at = ?, delay_reason_id = ?, delay_notes = ? WHERE id = ?');
        $status = 'completed';
        $stmt->bind_param('ssisi', $status, $completed_at, $delay_reason_id, $delay_notes, $wash_id);
        if ($stmt->execute()) {
            $_SESSION['flash_success'] = 'Wash marked as complete.';
            header('Location: washes.php');
            exit();
        } else {
            $error = 'Failed to update wash status: ' . $conn->error;
        }
    }
}

$page_title = 'Finish Wash';
include 'includes/header.php';
?>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Finish Wash</h2>
            <a href="washes.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            
            <p><strong>Plate:</strong> <?= htmlspecialchars($wash['number_plate']) ?></p>
            <p><strong>Started:</strong> <?= date('g:i A', strtotime($wash['started_at'])) ?></p>
            <p><strong>Planned End:</strong> <?= $wash['planned_end'] ? date('g:i A', strtotime($wash['planned_end'])) : 'N/A' ?></p>
            
            <?php if ($is_delayed): ?>
                <div class="alert alert-warning">This wash is delayed. Please provide a reason.</div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($is_delayed): ?>
                <div class="form-group">
                    <label for="delay_reason_id">Delay Reason <span class="required">*</span></label>
                    <select id="delay_reason_id" name="delay_reason_id" required>
                        <option value="">Select a reason</option>
                        <?php foreach ($delay_reasons as $reason): ?>
                            <option value="<?= $reason['id'] ?>"><?= htmlspecialchars($reason['reason']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="delay_notes">Delay Notes (optional)</label>
                    <textarea id="delay_notes" name="delay_notes" rows="3"></textarea>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn"><i class="fas fa-check-circle"></i> Mark as Complete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
