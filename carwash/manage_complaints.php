<?php
require_once 'config/session.php';
require_once 'config/database.php';
require_once 'includes/csrf.php';

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: login.php');
    exit();
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    $action = $_POST['action'];
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $adminNotes = trim($_POST['admin_notes'] ?? '');
    
    if ($action === 'update_status' && $complaintId > 0) {
        try {
            $stmt = $conn->prepare("UPDATE customer_complaints 
                                  SET status = ?, 
                                      admin_notes = ?, 
                                      resolved_at = IF(? = 'resolved', NOW(), resolved_at),
                                      updated_at = NOW()
                                  WHERE id = ?");
            $status = $_POST['status'] ?? 'pending';
            $stmt->bind_param('sssi', $status, $adminNotes, $status, $complaintId);
            $stmt->execute();
            
            $_SESSION['flash_message'] = 'Complaint updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $error = "Error updating complaint: " . $e->getMessage();
        }
    }
}

// Get all complaints with optional filtering
$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$query = "SELECT c.*, 
          DATE_FORMAT(c.created_at, '%b %e, %Y %h:%i %p') as formatted_date,
          CASE 
              WHEN c.status = 'pending' THEN 'Pending'
              WHEN c.status = 'in_progress' THEN 'In Progress'
              WHEN c.status = 'resolved' THEN 'Resolved'
              ELSE c.status 
          END as status_label
          FROM customer_complaints c 
          WHERE 1=1";

$params = [];
$types = '';

if ($status !== 'all') {
    $query .= " AND c.status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($search)) {
    $query .= " AND (c.customer_name LIKE ? OR c.worker_name LIKE ? OR c.details LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    $types .= 'sss';
}

$query .= " ORDER BY c.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$complaints = $result->fetch_all(MYSQLI_ASSOC);

// Get status counts
$statusCounts = [
    'all' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'resolved' => 0
];

$countStmt = $conn->query("SELECT status, COUNT(*) as count FROM customer_complaints GROUP BY status");
while ($row = $countStmt->fetch_assoc()) {
    $statusCounts[$row['status']] = (int)$row['count'];
}
$statusCounts['all'] = array_sum($statusCounts);

$pageTitle = 'Manage Customer Complaints';
require_once 'includes/header.php';
?>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <h1>Customer Complaints</h1>
        <a href="dashboard.php" class="btn" style="display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-success" style="background-color: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
            <?php 
            echo htmlspecialchars($_SESSION['flash_message']);
            unset($_SESSION['flash_message']);
            ?>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="?status=all" class="btn <?php echo $status === 'all' ? 'active' : 'outline'; ?>" style="border-radius: 20px; padding: 8px 16px;">
                    All <span class="badge"><?php echo $statusCounts['all']; ?></span>
                </a>
                <a href="?status=pending" class="btn <?php echo $status === 'pending' ? 'active' : 'outline'; ?>" style="border-radius: 20px; padding: 8px 16px;">
                    Pending <span class="badge"><?php echo $statusCounts['pending']; ?></span>
                </a>
                <a href="?status=in_progress" class="btn <?php echo $status === 'in_progress' ? 'active' : 'outline'; ?>" style="border-radius: 20px; padding: 8px 16px;">
                    In Progress <span class="badge"><?php echo $statusCounts['in_progress']; ?></span>
                </a>
                <a href="?status=resolved" class="btn <?php echo $status === 'resolved' ? 'active' : 'outline'; ?>" style="border-radius: 20px; padding: 8px 16px;">
                    Resolved <span class="badge"><?php echo $statusCounts['resolved']; ?></span>
                </a>
            </div>
            
            <form method="GET" style="display: flex; gap: 10px;">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                <div class="search-box" style="position: relative;">
                    <input type="text" name="search" placeholder="Search complaints..." value="<?php echo htmlspecialchars($search); ?>" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 20px; width: 250px; padding-right: 35px;">
                    <button type="submit" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #666; cursor: pointer;">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <?php if (!empty($search)): ?>
                    <a href="?status=<?php echo urlencode($status); ?>" class="btn outline" style="border-radius: 20px; padding: 8px 16px;">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($complaints)): ?>
            <div style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 5rem; color: #e0e0e0; margin-bottom: 20px;">
                    <i class="far fa-comment-dots"></i>
                </div>
                <h3 style="color: #666; margin-bottom: 10px;">No complaints found</h3>
                <p style="color: #999; margin: 0;">
                    <?php echo !empty($search) ? 'Try a different search term' : 'All caught up! No complaints to display.'; ?>
                </p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                            <th style="padding: 12px; text-align: left;">Date</th>
                            <th style="padding: 12px; text-align: left;">Customer</th>
                            <th style="padding: 12px; text-align: left;">Type</th>
                            <th style="padding: 12px; text-align: left;">Worker</th>
                            <th style="padding: 12px; text-align: left;">Status</th>
                            <th style="padding: 12px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $complaint): ?>
                            <tr style="border-bottom: 1px solid #e9ecef;">
                                <td style="padding: 15px 12px; vertical-align: top;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($complaint['formatted_date']); ?></div>
                                    <div style="font-size: 0.85em; color: #6c757d;">
                                        <?php 
                                        $timeAgo = new DateTime($complaint['created_at']);
                                        $now = new DateTime();
                                        $interval = $timeAgo->diff($now);
                                        
                                        if ($interval->y > 0) {
                                            echo $interval->y . ' ' . ($interval->y === 1 ? 'year' : 'years') . ' ago';
                                        } elseif ($interval->m > 0) {
                                            echo $interval->m . ' ' . ($interval->m === 1 ? 'month' : 'months') . ' ago';
                                        } elseif ($interval->d > 0) {
                                            echo $interval->d . ' ' . ($interval->d === 1 ? 'day' : 'days') . ' ago';
                                        } elseif ($interval->h > 0) {
                                            echo $interval->h . ' ' . ($interval->h === 1 ? 'hour' : 'hours') . ' ago';
                                        } elseif ($interval->i > 0) {
                                            echo $interval->i . ' ' . ($interval->i === 1 ? 'minute' : 'minutes') . ' ago';
                                        } else {
                                            echo 'Just now';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td style="padding: 15px 12px; vertical-align: top;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($complaint['customer_name']); ?></div>
                                    <?php if (!empty($complaint['contact_info'])): ?>
                                        <div style="font-size: 0.85em; color: #6c757d;">
                                            <?php 
                                            $contact = htmlspecialchars($complaint['contact_info']);
                                            if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                                                echo '<a href="mailto:' . $contact . '" style="color: #6c757d; text-decoration: none;">' . $contact . '</a>';
                                            } elseif (preg_match('/^\+?[\d\s-]+$/', $contact)) {
                                                echo '<a href="tel:' . preg_replace('/[^\d+]/', '', $contact) . '" style="color: #6c757d; text-decoration: none;">' . $contact . '</a>';
                                            } else {
                                                echo $contact;
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 15px 12px; vertical-align: top;">
                                    <?php 
                                    $typeLabels = [
                                        'service' => 'Service Quality',
                                        'worker' => 'Staff Issue',
                                        'facility' => 'Facility',
                                        'management' => 'Management',
                                        'other' => 'Other'
                                    ];
                                    $typeClass = [
                                        'service' => 'info',
                                        'worker' => 'danger',
                                        'facility' => 'warning',
                                        'management' => 'primary',
                                        'other' => 'secondary'
                                    ];
                                    $type = $complaint['complaint_type'] ?? 'other';
                                    $label = $typeLabels[$type] ?? ucfirst($type);
                                    $class = $typeClass[$type] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $class; ?>" style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 500; background-color: #e9ecef; color: #495057;">
                                        <?php echo htmlspecialchars($label); ?>
                                    </span>
                                </td>
                                <td style="padding: 15px 12px; vertical-align: top;">
                                    <?php echo !empty($complaint['worker_name']) ? htmlspecialchars($complaint['worker_name']) : '—'; ?>
                                </td>
                                <td style="padding: 15px 12px; vertical-align: top;">
                                    <?php 
                                    $statusClass = [
                                        'pending' => 'warning',
                                        'in_progress' => 'info',
                                        'resolved' => 'success'
                                    ][$complaint['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $statusClass; ?>" style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 500; background-color: #fff3cd; color: #856404;">
                                        <?php echo htmlspecialchars($complaint['status_label']); ?>
                                    </span>
                                </td>
                                <td style="padding: 15px 12px; text-align: right; vertical-align: top;">
                                    <button type="button" class="btn btn-sm btn-outline-primary view-complaint" data-id="<?php echo $complaint['id']; ?>" style="padding: 6px 12px; font-size: 0.85em;">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <!-- Details Row (Hidden by default) -->
                            <tr class="complaint-details" id="details-<?php echo $complaint['id']; ?>" style="display: none; background-color: #f8f9fa;">
                                <td colspan="6" style="padding: 20px;">
                                    <div style="display: flex; gap: 30px;">
                                        <div style="flex: 1;">
                                            <h4 style="margin-top: 0; margin-bottom: 15px; color: #333; font-size: 1.1em;">Complaint Details</h4>
                                            <div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 15px;">
                                                <?php echo nl2br(htmlspecialchars($complaint['details'])); ?>
                                            </div>
                                            
                                            <?php if (!empty($complaint['admin_notes'])): ?>
                                                <div style="margin-top: 20px;">
                                                    <h5 style="margin-bottom: 10px; color: #6c757d; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.5px;">Admin Notes</h5>
                                                    <div style="background: #f8f9fa; padding: 12px 15px; border-radius: 6px; border-left: 3px solid #4e73df; font-size: 0.95em; line-height: 1.5;">
                                                        <?php echo nl2br(htmlspecialchars($complaint['admin_notes'])); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($complaint['resolved_at'])): ?>
                                                <div style="margin-top: 15px; font-size: 0.85em; color: #6c757d;">
                                                    <i class="far fa-check-circle" style="color: #28a745;"></i>
                                                    Resolved on <?php echo date('M j, Y \a\t g:i a', strtotime($complaint['resolved_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="width: 300px;">
                                            <h4 style="margin-top: 0; margin-bottom: 15px; color: #333; font-size: 1.1em;">Update Status</h4>
                                            <form method="post" class="status-form" style="margin-bottom: 20px;">
                                                <?php echo generateCSRFTokenInput(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                                                
                                                <div class="form-group" style="margin-bottom: 15px;">
                                                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #495057;">Status</label>
                                                    <select name="status" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.95em;">
                                                        <option value="pending" <?php echo $complaint['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="in_progress" <?php echo $complaint['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="resolved" <?php echo $complaint['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="form-group" style="margin-bottom: 15px;">
                                                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #495057;">Add Note</label>
                                                    <textarea name="admin_notes" rows="3" class="form-control" placeholder="Add internal notes here..." style="width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.95em; resize: vertical; min-height: 80px;"><?php echo htmlspecialchars($complaint['admin_notes'] ?? ''); ?></textarea>
                                                </div>
                                                
                                                <button type="submit" class="btn" style="width: 100%; padding: 10px; background-color: #4e73df; color: white; border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                                                    <i class="fas fa-save"></i> Update Complaint
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 30px; text-align: center; color: #6c757d; font-size: 0.9em;">
                Showing <?php echo count($complaints); ?> of <?php echo $statusCounts['all']; ?> complaints
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add jQuery if not already included -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    console.log('jQuery loaded and document ready');
    
    // Handle view button click
    $('.view-complaint').on('click', function(e) {
        e.preventDefault();
        const complaintId = $(this).data('id');
        const detailsRow = $('#details-' + complaintId);
        
        // Toggle the details row
        detailsRow.slideToggle(200);
        
        // Toggle the button icon
        const icon = $(this).find('i');
        if (icon.hasClass('fa-eye')) {
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
            $(this).html('<i class="fas fa-eye-slash"></i> Hide');
        } else {
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
            $(this).html('<i class="fas fa-eye"></i> View');
        }
    });
    
    // Handle form submissions with AJAX
    $('.status-form').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const submitButton = form.find('button[type="submit"]');
        const originalButtonText = submitButton.html();
        
        // Show loading state
        submitButton.html('<i class="fas fa-spinner fa-spin"></i> Updating...').prop('disabled', true);
        
        // Submit form via AJAX
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: form.serialize(),
            success: function(response) {
                // Reload the page to show updated status
                window.location.reload();
            },
            error: function() {
                alert('An error occurred. Please try again.');
                submitButton.html(originalButtonText).prop('disabled', false);
            }
        });
    });
});
</script>

<!-- View Complaint Modal -->
<div class="modal fade" id="viewComplaintModal" tabindex="-1" role="dialog" aria-labelledby="viewComplaintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="padding: 15px 20px; border-bottom: 1px solid #e9ecef;">
                <h5 class="modal-title" id="viewComplaintModalLabel">Complaint Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="background: none; border: none; font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; opacity: 0.5; cursor: pointer;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="complaintDetails" style="padding: 20px;">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer" style="padding: 15px 20px; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" style="padding: 8px 16px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: 500;
    }
    
    .badge-warning {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .badge-info {
        background-color: #cce5ff;
        color: #004085;
    }
    
    .badge-success {
        background-color: #d4edda;
        color: #155724;
    }
    
    .badge-danger {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .badge-primary {
        background-color: #cce5ff;
        color: #004085;
    }
    
    .badge-secondary {
        background-color: #e2e3e5;
        color: #383d41;
    }
    
    .btn-outline-primary {
        background-color: transparent;
        color: #4e73df;
        border: 1px solid #4e73df;
    }
    
    .btn-outline-primary:hover {
        background-color: #4e73df;
        color: white;
    }
    
    .btn.active {
        background-color: #4e73df;
        color: white;
        border-color: #4e73df;
    }
    
    .btn.outline {
        background-color: transparent;
        color: #4e73df;
        border: 1px solid #4e73df;
    }
    
    .btn.outline:hover {
        background-color: #4e73df;
        color: white;
    }
    
    .badge {
        margin-left: 6px;
        background-color: #e9ecef;
        color: #495057;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75em;
    }
    
    @media (max-width: 768px) {
        .search-box {
            width: 100%;
            margin-top: 10px;
        }
        
        .search-box input {
            width: 100% !important;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Function to toggle complaint details
    function toggleComplaintDetails(button) {
        const complaintId = button.getAttribute('data-id');
        const detailsRow = document.getElementById(`details-${complaintId}`);
        
        if (!detailsRow) {
            console.error('Details row not found for ID:', complaintId);
            return;
        }
        
        // Toggle display
        const isVisible = detailsRow.style.display === 'table-row';
        detailsRow.style.display = isVisible ? 'none' : 'table-row';
        button.innerHTML = isVisible ? 
            '<i class="fas fa-eye"></i> View' : 
            '<i class="fas fa-eye-slash"></i> Hide';
        
        // Scroll to the details if opening
        if (!isVisible) {
            setTimeout(() => {
                detailsRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 50);
        }
    }
    
    // Add click event to all view buttons
    document.querySelectorAll('.view-complaint').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            toggleComplaintDetails(this);
        });
    });
    
    // Handle form submissions with AJAX
    document.querySelectorAll('.status-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitButton = this.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            
            // Show loading state
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text().then(html => {
                        // This is a fallback in case the response isn't a redirect
                        document.documentElement.innerHTML = html;
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the complaint. Please try again.');
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            });
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
