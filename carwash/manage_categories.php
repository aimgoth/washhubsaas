<?php
require_once 'config/session.php';
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'superadmin') { http_response_code(403); echo 'Access denied. Super Admin only.'; exit; }

$errors = [];
$messages = [];

// Add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $errors[] = 'Category name is required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->bind_param('s', $name);
        if ($stmt->execute()) { $messages[] = "Category \"$name\" added successfully."; } else { $errors[] = 'Insert failed: ' . $conn->error; }
        $stmt->close();
    }
}

// Delete category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) { $messages[] = 'Category deleted successfully.'; } else { $errors[] = 'Delete failed: ' . $conn->error; }
        $stmt->close();
    }
}

$result = $conn->query('SELECT id, name, created_at FROM categories ORDER BY name');
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
if ($result) { $result->free(); }
?>
<?php include 'includes/header.php'; ?>

<style>
    .cs-page { max-width: 860px; margin: 36px auto; padding: 0 20px 60px; }

    .cs-title { display: flex; align-items: center; gap: 14px; margin-bottom: 28px; }
    .cs-title-icon {
        width: 52px; height: 52px; border-radius: 14px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.4rem; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(0,174,239,0.3);
    }
    .cs-title h1 { font-size: 1.75rem; font-weight: 800; color: #1B3FA0; margin: 0; }
    .cs-title p  { font-size: 0.88rem; color: #64748b; margin: 3px 0 0; }

    .cs-alert {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 0.9rem; font-weight: 600; margin-bottom: 14px;
    }
    .cs-alert.ok  { background: #e0f2fe; border-left: 4px solid #00AEEF; color: #0369a1; }
    .cs-alert.err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; }

    .cs-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 16px rgba(0,0,0,0.07);
        margin-bottom: 24px; overflow: hidden;
    }
    .cs-card-header {
        padding: 16px 24px; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        background: #f8faff;
    }
    .cs-card-header h2 {
        font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 9px;
    }
    .cs-card-header h2 i { color: #00AEEF; }
    .cs-badge {
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; font-size: 0.72rem; font-weight: 700;
        padding: 4px 12px; border-radius: 20px; letter-spacing: 0.4px;
    }
    .cs-card-body { padding: 24px; }

    /* Add form */
    .cs-add-form { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; }
    .cs-field { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 220px; }
    .cs-field label {
        font-size: 0.76rem; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .cs-field input[type="text"] {
        padding: 11px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px;
        font-size: 0.93rem; font-weight: 600; color: #1e293b;
        background: #fff; transition: border-color .2s, box-shadow .2s; outline: none; width: 100%;
    }
    .cs-field input[type="text"]:focus {
        border-color: #00AEEF; box-shadow: 0 0 0 3px rgba(0,174,239,0.12);
    }
    .cs-btn-add {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 24px;
        background: linear-gradient(135deg, #00AEEF, #1B3FA0);
        color: #fff; border: none; border-radius: 10px;
        font-size: 0.92rem; font-weight: 700; cursor: pointer;
        transition: filter .2s, transform .15s; white-space: nowrap;
        box-shadow: 0 3px 12px rgba(0,174,239,0.3);
    }
    .cs-btn-add:hover { filter: brightness(1.08); transform: translateY(-1px); }

    /* Table */
    .cs-table-wrap { overflow-x: auto; }
    .cs-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .cs-table thead tr {
        background: linear-gradient(135deg, #1B3FA0, #10b981);
    }
    .cs-table th {
        padding: 14px 20px; font-size: 0.73rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.7px;
        text-align: left; white-space: nowrap; color: #fff;
    }
    .cs-table td {
        padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; vertical-align: middle;
    }
    .cs-table tbody tr:last-child td { border-bottom: none; }
    .cs-table tbody tr:hover td { background: #f0f7ff; transition: background .15s; }

    .cs-row-num {
        display: inline-flex; width: 28px; height: 28px;
        border-radius: 50%; background: #d1fae5;
        color: #065f46; font-size: 0.78rem; font-weight: 800;
        align-items: center; justify-content: center;
    }

    .cat-badge {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 5px 14px; border-radius: 20px;
        font-size: 0.83rem; font-weight: 700;
        background: #e0f2fe; color: #0369a1;
        border: 1px solid #bae6fd;
    }

    .cs-date { color: #334155; font-size: 0.87rem; font-weight: 600; }
    .cs-date-sub { color: #94a3b8; font-size: 0.75rem; margin-top: 2px; }

    .cs-btn-del {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 16px; border-radius: 8px;
        background: #fef2f2; color: #dc2626;
        border: 1.5px solid #fecaca;
        font-size: 0.8rem; font-weight: 700;
        cursor: pointer; transition: background .2s, color .2s, border-color .2s;
    }
    .cs-btn-del:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .cs-empty { text-align: center; padding: 50px 20px; color: #94a3b8; }
    .cs-empty i { font-size: 2.8rem; opacity: 0.25; margin-bottom: 14px; display: block; }
    .cs-empty strong { display: block; font-size: 1rem; color: #64748b; margin-bottom: 6px; }

    .cs-btn-back {
        display: inline-flex; align-items: center; gap: 9px;
        padding: 11px 24px; border-radius: 9999px;
        background: #f1f5f9; color: #475569; font-weight: 700;
        font-size: 0.9rem; text-decoration: none;
        border: 1.5px solid #e2e8f0;
        transition: background .2s, color .2s, border-color .2s;
    }
    .cs-btn-back:hover { background: #1B3FA0; color: #fff; border-color: #1B3FA0; }
</style>

<div class="cs-page">

    <!-- Page Title -->
    <div class="cs-title">
        <div class="cs-title-icon"><i class="fas fa-tags"></i></div>
        <div>
            <h1>Manage Categories</h1>
            <p>Add and manage wash categories such as Cars, Carpets, and Motors.</p>
        </div>
    </div>

    <!-- Alerts -->
    <?php foreach ($messages as $m): ?>
        <div class="cs-alert ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="cs-alert err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>

    <!-- Add Category Card -->
    <div class="cs-card">
        <div class="cs-card-header">
            <h2><i class="fas fa-plus-circle"></i> Add New Category</h2>
        </div>
        <div class="cs-card-body">
            <form method="post" class="cs-add-form">
                <input type="hidden" name="action" value="add">
                <div class="cs-field">
                    <label for="cat_name">Category Name</label>
                    <input type="text" id="cat_name" name="name" placeholder="e.g. Cars, Carpets, Motors" required autocomplete="off">
                </div>
                <button type="submit" class="cs-btn-add">
                    <i class="fas fa-plus"></i> Add Category
                </button>
            </form>
        </div>
    </div>

    <!-- Existing Categories Table -->
    <div class="cs-card">
        <div class="cs-card-header">
            <h2><i class="fas fa-list-ul"></i> Existing Categories</h2>
            <span class="cs-badge"><?php echo count($rows); ?> Categor<?php echo count($rows) !== 1 ? 'ies' : 'y'; ?></span>
        </div>

        <?php if (empty($rows)): ?>
            <div class="cs-empty">
                <i class="fas fa-tags"></i>
                <strong>No categories added yet</strong>
                Use the form above to add your first wash category.
            </div>
        <?php else: ?>
        <div class="cs-table-wrap">
            <table class="cs-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><i class="fas fa-tag" style="margin-right:6px;opacity:.8;"></i>Category Name</th>
                        <th><i class="fas fa-calendar-alt" style="margin-right:6px;opacity:.8;"></i>Date Added</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><span class="cs-row-num"><?php echo $i + 1; ?></span></td>
                        <td>
                            <span class="cat-badge">
                                <i class="fas fa-tag" style="font-size:.75rem;"></i>
                                <?php echo htmlspecialchars($r['name']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="cs-date"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></div>
                            <div class="cs-date-sub"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                        </td>
                        <td style="text-align:center;">
                            <form method="post" style="margin:0;display:inline;"
                                  data-confirm="Delete category &quot;<?php echo htmlspecialchars($r['name'], ENT_QUOTES); ?>&quot;? This cannot be undone.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="cs-btn-del">
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
    <a href="dashboard.php" class="cs-btn-back">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

</div>

<?php include 'includes/footer.php'; ?>
