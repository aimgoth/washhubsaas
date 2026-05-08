<?php
require_once 'config/session.php';
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'superadmin') { http_response_code(403); echo 'Access denied. Super Admin only.'; exit; }

$errors = [];
$messages = [];

// Add service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($name === '') {
        $errors[] = 'Service name is required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO services (name, description) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $description);
        if ($stmt->execute()) { $messages[] = "Service \"$name\" added successfully."; } else { $errors[] = 'Insert failed: ' . $conn->error; }
        $stmt->close();
    }
}

// Delete service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM services WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) { $messages[] = 'Service deleted successfully.'; } else { $errors[] = 'Delete failed: ' . $conn->error; }
        $stmt->close();
    }
}

// Fetch services
$r1 = $conn->query('SELECT id, name, description, created_at FROM services ORDER BY name');
$services = $r1 ? $r1->fetch_all(MYSQLI_ASSOC) : [];
if ($r1) $r1->free();
?>
<?php include 'includes/header.php'; ?>

<style>
    .sv-page { max-width: 900px; margin: 36px auto; padding: 0 20px 60px; }

    .sv-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; }
    .sv-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .sv-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .sv-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .sv-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 0.9rem; font-weight: 600; margin-bottom: 14px;
    }
    .sv-alert.ok  { background: #e0f2fe; border-left: 4px solid #00AEEF; color: #0369a1; }
    .sv-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    .sv-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 16px rgba(0,0,0,0.07);
        margin-bottom: 24px; overflow: hidden;
    }
    .sv-card-header {
        padding: 16px 24px; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        background: #f8faff;
    }
    .sv-card-header h2 {
        font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 9px;
    }
    .sv-card-header h2 i { color: #00AEEF; }
    .sv-badge {
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; font-size: 0.72rem; font-weight: 700;
        padding: 4px 12px; border-radius: 20px; letter-spacing: 0.4px;
    }
    .sv-card-body { padding: 24px; }

    /* Add form */
    .sv-add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media(max-width:600px){ .sv-add-grid { grid-template-columns: 1fr; } }
    .sv-field { display: flex; flex-direction: column; gap: 6px; }
    .sv-field label {
        font-size: 0.76rem; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .sv-field input[type="text"],
    .sv-field textarea {
        padding: 11px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px;
        font-size: 0.93rem; font-weight: 600; color: #1e293b;
        background: #fff; transition: border-color .2s, box-shadow .2s;
        outline: none; width: 100%; font-family: inherit; resize: vertical;
    }
    .sv-field input:focus, .sv-field textarea:focus {
        border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.12);
    }
    .sv-btn-add {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-size: 0.92rem; font-weight: 700; cursor: pointer;
        transition: filter .2s, transform .15s; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3); margin-top: 8px;
    }
    .sv-btn-add:hover { filter: brightness(1.08); transform: translateY(-1px); }

    /* Table */
    .sv-table-wrap { overflow-x: auto; }
    .sv-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .sv-table thead tr { background: linear-gradient(135deg, #1B3FA0, #00AEEF); }
    .sv-table th {
        padding: 14px 20px; font-size: 0.73rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        text-align: left; white-space: nowrap; color: #fff;
    }
    .sv-table td {
        padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; vertical-align: middle;
    }
    .sv-table tbody tr:last-child td { border-bottom: none; }
    .sv-table tbody tr:hover td { background: #f0f7ff; transition: background .15s; }

    .sv-row-num {
        display: inline-flex; width: 28px; height: 28px; border-radius: 50%;
        background: #e0f2fe; color: #0369a1;
        font-size: 0.78rem; font-weight: 800;
        align-items: center; justify-content: center;
    }
    .svc-badge {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 5px 14px; border-radius: 20px;
        font-size: 0.83rem; font-weight: 700;
        background: #e0f2fe; color: #0369a1;
        border: 1px solid #bae6fd;
    }
    .sv-desc { color: #64748b; font-size: 0.82rem; margin-top: 4px; font-style: italic; }

    .sv-date { color: #334155; font-size: 0.87rem; font-weight: 600; }
    .sv-date-sub { color: #94a3b8; font-size: 0.75rem; margin-top: 2px; }

    .sv-btn-del {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 16px; border-radius: 8px;
        background: #fef2f2; color: #dc2626;
        border: 1.5px solid #fecaca;
        font-size: 0.8rem; font-weight: 700;
        cursor: pointer; transition: background .2s, color .2s, border-color .2s;
    }
    .sv-btn-del:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .sv-empty { text-align: center; padding: 50px 20px; color: #94a3b8; }
    .sv-empty i { font-size: 2.8rem; opacity: 0.25; margin-bottom: 14px; display: block; }
    .sv-empty strong { display: block; font-size: 1rem; color: #64748b; margin-bottom: 6px; }

    .sv-btn-back {
        display: inline-flex; align-items: center; gap: 9px;
        padding: 11px 24px; border-radius: 9999px;
        background: #f1f5f9; color: #475569; font-weight: 700;
        font-size: 0.9rem; text-decoration: none;
        border: 1.5px solid #e2e8f0;
        transition: background .2s, color .2s, border-color .2s;
    }
    .sv-btn-back:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }
</style>

<div class="sv-page">

    <!-- Page Title -->
    <div class="sv-title">
        <div class="sv-title-icon"><i class="fas fa-list-ul"></i></div>
        <div>
            <h1>Manage Services</h1>
            <p>Add and manage the wash services offered at your bay.</p>
        </div>
    </div>

    <!-- Alerts -->
    <?php foreach ($messages as $m): ?>
        <div class="sv-alert ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="sv-alert err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>

    <!-- Add Service Card -->
    <div class="sv-card">
        <div class="sv-card-header">
            <h2><i class="fas fa-plus-circle"></i> Add New Service</h2>
        </div>
        <div class="sv-card-body">
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="sv-add-grid">
                    <div class="sv-field">
                        <label for="svc_name">Service Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="svc_name" name="name" placeholder="e.g. Full Wash, Interior Clean" required autocomplete="off">
                    </div>
                    <div class="sv-field">
                        <label for="svc_desc">Description (optional)</label>
                        <textarea id="svc_desc" name="description" rows="2" placeholder="Brief description of this service..."></textarea>
                    </div>
                </div>
                <button type="submit" class="sv-btn-add">
                    <i class="fas fa-plus"></i> Add Service
                </button>
            </form>
        </div>
    </div>

    <!-- Existing Services Table -->
    <div class="sv-card">
        <div class="sv-card-header">
            <h2><i class="fas fa-list"></i> Existing Services</h2>
            <span class="sv-badge"><?php echo count($services); ?> Service<?php echo count($services) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (empty($services)): ?>
            <div class="sv-empty">
                <i class="fas fa-concierge-bell"></i>
                <strong>No services added yet</strong>
                Use the form above to add your first wash service.
            </div>
        <?php else: ?>
        <div class="sv-table-wrap">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><i class="fas fa-concierge-bell" style="margin-right:6px;opacity:.8;"></i>Service Name</th>
                        <th><i class="fas fa-info-circle" style="margin-right:6px;opacity:.8;"></i>Description</th>
                        <th><i class="fas fa-calendar-alt" style="margin-right:6px;opacity:.8;"></i>Date Added</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $i => $s): ?>
                    <tr>
                        <td><span class="sv-row-num"><?php echo $i + 1; ?></span></td>
                        <td>
                            <span class="svc-badge">
                                <i class="fas fa-concierge-bell" style="font-size:.75rem;"></i>
                                <?php echo htmlspecialchars($s['name']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($s['description'])): ?>
                                <span class="sv-desc"><?php echo htmlspecialchars($s['description']); ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;font-size:.82rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="sv-date"><?php echo date('M j, Y', strtotime($s['created_at'])); ?></div>
                            <div class="sv-date-sub"><?php echo date('g:i A', strtotime($s['created_at'])); ?></div>
                        </td>
                        <td style="text-align:center;">
                            <form method="post" style="margin:0;display:inline;"
                                  data-confirm="Delete service &quot;<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>&quot;? This cannot be undone.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <button type="submit" class="sv-btn-del">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Back Button -->
    <a href="dashboard.php" class="sv-btn-back">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

</div>

<?php include 'includes/footer.php'; ?>
