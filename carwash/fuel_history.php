<?php
require_once 'config/session.php';

// Restrict access to super admins only
if ($_SESSION['role'] !== 'superadmin') {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    header('Location: dashboard.php');
    exit;
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Only admin and superadmin can access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admins only.';
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';
require_once 'includes/fuel_functions.php';

$page_title = 'Fuel Purchase History';
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Get all fuel purchases with stats
try {
    $purchases = getAllFuelPurchases(100); // Get last 100 purchases
    $purchaseData = [];
    
    // Prepare data for display
    $purchaseData = [];
    foreach ($purchases as $purchase) {
        $purchase['formatted_date'] = date('M j, Y', strtotime($purchase['start_date']));
        $purchaseData[] = $purchase;
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

include 'includes/header.php';
?>

<div class="container-fluid px-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Fuel Management</h1>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

            
            </div>
            
    <!-- Fuel Purchase Records -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">Fuel Purchase Records</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="fuelTable" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Amount (GHS)</th>
                            <th class="text-end">Liters</th>
                            <th class="text-center">Duration</th>
                            <th class="text-center">Cars</th>
                            <th class="text-center">Motors</th>
                            <th class="text-center">Carpets</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchaseData)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-gas-pump fa-3x opacity-25 mb-2"></i>
                                        <p class="mb-0">No fuel purchase records found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($purchaseData as $purchase): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <?= $purchase['formatted_date'] ?>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?= number_format($purchase['amount_cedis'], 2) ?>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format($purchase['liters'], 1) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $start = new DateTime($purchase['start_date']);
                                        $end = !empty($purchase['end_date']) ? new DateTime($purchase['end_date']) : $start;
                                        $interval = $start->diff($end);
                                        echo $interval->format('%h hr %i min');
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $purchase['total_cars'] ?: '0' ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $purchase['total_motors'] ?: '0' ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $purchase['total_carpets'] ?: '0' ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="fuel_report.php?id=<?= $purchase['id'] ?>" 
                                               class="btn btn-outline-primary" 
                                               title="View Report">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($_SESSION['role'] === 'superadmin' && $purchase['total_washes'] == 0): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger"
                                                        onclick="confirmDelete(<?= $purchase['id'] ?>, '<?= $purchase['formatted_date'] ?>')"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    Showing <span class="fw-semibold"><?= count($purchaseData) ?></span> record<?= count($purchaseData) != 1 ? 's' : '' ?>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshTable">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Refresh button
    document.getElementById('refreshTable').addEventListener('click', function() {
        window.location.reload();
    });

});

// View report
function viewReport(id) {
    window.location.href = 'fuel_report.php?id=' + id;
}


// Delete confirmation with SweetAlert2
function confirmDelete(id, date) {
    Swal.fire({
        title: 'Delete Fuel Purchase?',
        html: `Are you sure you want to delete the fuel purchase record for <b>${date}</b>?<br><br><span class='text-danger'>This action cannot be undone!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Submit the form to delete the record
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'delete_fuel_purchase.php';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= $_SESSION['csrf_token'] ?? '' ?>';
            
            form.appendChild(idInput);
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>

                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    Showing <span class="fw-semibold"><?= count($purchaseData) ?></span> record<?= count($purchaseData) != 1 ? 's' : '' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Delete confirmation with SweetAlert2
function confirmDelete(id, date) {
    Swal.fire({
        title: 'Delete Fuel Record?',
        html: `Are you sure you want to delete the fuel record for <b>${date}</b>?<br>This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Submit delete request
            fetch(`delete_fuel_purchase.php?id=${id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire(
                        'Deleted!',
                        'The fuel record has been deleted.',
                        'success'
                    ).then(() => {
                        // Reload the page after successful deletion
                        window.location.reload();
                    });
                } else {
                    Swal.fire(
                        'Error!',
                        data.message || 'Failed to delete the record.',
                        'error'
                    );
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire(
                    'Error!',
                    'An error occurred while deleting the record.',
                    'error'
                );
            });
        }
    });
}

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});

// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const table = document.getElementById('fuelTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    searchInput.addEventListener('keyup', function() {
        const searchText = this.value.toLowerCase();
        
        for (let i = 0; i < rows.length; i++) {
            const rowText = rows[i].textContent.toLowerCase();
            rows[i].style.display = rowText.includes(searchText) ? '' : 'none';
        }
    });
});
</script>

<style>
/* Global Table Styles */
.table-responsive {
    font-size: 0.9rem;
    border-radius: 0.5rem;
    overflow: hidden;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}

.table {
    margin-bottom: 0;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.table thead th {
    background-color: #4e73df;
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    padding: 1rem;
    border: none;
    vertical-align: middle;
}

.table tbody tr {
    transition: all 0.2s ease;
    background-color: #fff;
}

.table tbody tr:nth-child(even) {
    background-color: #f8f9fc;
}

.table tbody tr:hover {
    background-color: #f1f3f9;
    transform: translateY(-1px);
    box-shadow: 0 0.15rem 0.5rem rgba(0, 0, 0, 0.05);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-color: #e3e6f0;
    border-top: 1px solid #e3e6f0;
}

.badge {
    font-size: 0.75em;
    font-weight: 500;
    padding: 0.4em 0.8em;
    border-radius: 0.35rem;
}

.badge-success {
    background-color: #1cc88a;
}

.badge-warning {
    background-color: #f6c23e;
    color: #2c3e50;
}

.badge-danger {
    background-color: #e74a3b;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
}

.pagination {
    margin: 1rem 0 0 0;
}

.page-link {
    color: #4e73df;
    border: 1px solid #e3e6f0;
    padding: 0.5rem 0.75rem;
    transition: all 0.2s;
}

.page-item.active .page-link {
    background-color: #4e73df;
    border-color: #4e73df;
}

.page-link:hover {
    color: #224abe;
    background-color: #eaecf4;
    border-color: #dddfeb;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .card-body {
        padding: 0.5rem;
    }
    
    .table-responsive {
        font-size: 0.85rem;
        box-shadow: none;
    }
    
    .table thead th,
    .table tbody td {
        padding: 0.75rem 0.5rem;
    }
    
    .btn-sm {
        padding: 0.2rem 0.4rem;
        font-size: 0.75rem;
    }
}

</style>

<?php include 'includes/footer.php'; ?>
