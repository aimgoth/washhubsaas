<?php
// Start output buffering to prevent any accidental output
ob_start();

require_once 'config/session.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    }

// Only superadmin can view archive
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

// Get Dynamic Worker Commission
$worker_pct = 33.33; // Default
try {
    $res_set = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_percentage' LIMIT 1");
    if ($res_set && $row_set = $res_set->fetch_assoc()) {
        $worker_pct = (float)$row_set['setting_value'];
    }
} catch (Exception $e) { /* ignore */ }
$company_pct = 100 - $worker_pct;

// Fetch all active workers for the inactive list calculation
$active_workers_list = [];
try {
    $resW = $conn->query("SELECT full_name FROM workers WHERE status = 'active' ORDER BY full_name ASC");
    if ($resW && $resW->num_rows > 0) {
        while($rw = $resW->fetch_assoc()) { 
            $active_workers_list[] = $rw['full_name']; 
        }
    }
} catch(Exception $e) {}

// Get Wash Bay Name — Priority: Session Brand > Master DB > Local Setting
$bayName = $_SESSION['TENANT_BRAND'] ?? "WashHub"; 

if ($bayName === "WashHub" || $bayName === "WashHub Client") {
    try {
        // 1. Try local tenant settings first (if user customized it)
        $res_bay = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bay_name' LIMIT 1");
        if ($res_bay && $row_bay = $res_bay->fetch_assoc()) {
            $localBay = $row_bay['setting_value'];
            if ($localBay !== "WashHub Client") {
                $bayName = $localBay;
            }
        }
        
        // 2. If still default, double check Master DB (fallback for first load)
        if ($bayName === "WashHub" || $bayName === "WashHub Client") {
            $masterDb = getenv('DB_NAME') ?: 'carwash_db';
            $currentDb = $conn->query("SELECT DATABASE()")->fetch_row()[0];
            $mConn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $masterDb, (int)DB_PORT);
            if ($mConn && !$mConn->connect_error) {
                $stmtM = $mConn->prepare("SELECT client_name, bay_name FROM tenants WHERE db_name = ? LIMIT 1");
                $stmtM->bind_param('s', $currentDb);
                $stmtM->execute();
                $resM = $stmtM->get_result();
                if ($rowM = $resM->fetch_assoc()) {
                    $cName = $rowM['client_name'];
                    $bName = $rowM['bay_name'];
                    $bayName = ($cName === $bName || $bName === '') ? $cName : "$cName — $bName";
                    $_SESSION['TENANT_BRAND'] = $bayName; // Cache it
                }
                $mConn->close();
            }
        }
    } catch (Exception $e) { /* use fallback */ }
}

// Process filters
$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to = isset($_GET['to']) && $_GET['to'] !== '' ? $_GET['to'] : '';

$where = '1=1';
$params = [];
$types = '';

if ($from !== '') { 
    $where .= ' AND report_date >= ?'; 
    $params[] = $from; 
    $types .= 's'; 
}
if ($to !== '') { 
    $where .= ' AND report_date <= ?'; 
    $params[] = $to; 
    $types .= 's'; 
}

// Determine submitted_at availability; fallback to created_at
$hasSubmittedAt = false;
$colCheck = @$conn->query("SHOW COLUMNS FROM daily_reports LIKE 'submitted_at'");
if ($colCheck && $colCheck->num_rows > 0) { 
    $hasSubmittedAt = true; 
}
$submittedExpr = $hasSubmittedAt ? 'submitted_at' : 'created_at';

// Prepare and execute the query
$sql = "SELECT report_date, total_cars_washed, total_motors_washed, total_carpets_washed,
               gross_amount_total, revenue_two_thirds_total, created_by, $submittedExpr AS submitted_at, created_at
        FROM daily_reports
        WHERE $where
        ORDER BY CASE WHEN report_date = CURDATE() THEN 0 ELSE 1 END, report_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) { 
    $stmt->bind_param($types, ...$params); 
}

$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) { 
    $rows[] = $r; 
}

// Aggregate counters for the visible rows
$totalCars = 0; 
$totalMotors = 0; 
$totalCarpets = 0;

foreach ($rows as $r) {
    $totalCars += (int)($r['total_cars_washed'] ?? 0);
    $totalMotors += (int)($r['total_motors_washed'] ?? 0);
    $totalCarpets += (int)($r['total_carpets_washed'] ?? 0);
}

// Clear any output that might have been generated before
ob_clean();
$page_title = 'Daily Reports Archive';
include 'includes/header.php';
?>

  <!-- PDF Generation Libraries (Only for this page) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script>
    // Make jsPDF available globally
    const { jsPDF } = window.jspdf;
    window.jsPDF = jsPDF;
  </script>

  <style>
    /* Base styles for better readability */
    body {
      font-size: 1.1rem; /* Increased base font size */
      color: #000000; /* Ensure all text is black */
    }
    
    .card { 
      background:#fff; 
      border:1px solid #e0e0e0; 
      border-radius:8px; 
      padding:20px; 
      margin:16px 0; 
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    /* Widen the main container so columns don't wrap/scatter */
    .container, .content, .wrapper { 
      max-width: 1400px; 
      margin: 0 auto;
      padding: 0 15px;
    }
    
    /* Table styles with enhanced readability */
    table { 
      width: 100%; 
      table-layout: auto;
      border-collapse: separate;
      border-spacing: 0;
    }
    
    th, td { 
      white-space: nowrap;
      padding: 12px 15px;
      border-bottom: 1px solid #e0e0e0;
      text-align: left;
      font-weight: 600; /* Make all table text bolder */
      color: #000000; /* Ensure text is black */
    }
    
    th { 
      background-color: #f8f9fa;
      font-size: 1.2rem; /* Larger header text */
      font-weight: 700 !important; /* Even bolder headers */
      color: #2c3e50; /* Darker color for headers */
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    td { 
      font-size: 1.1rem; /* Larger cell text */
      font-weight: 600; /* Bolder text */
    }
    
    tr:hover td {
      background-color: #f8f9fa;
    }
    
    /* Table column widths */
    thead th:nth-child(1) { width: 160px; }   /* Date */
    thead th:nth-child(2),
    thead th:nth-child(3),
    thead th:nth-child(4) { width: 100px; }   /* Cars/Motors/Carpets */
    thead th:nth-child(5),
    thead th:nth-child(6) { width: 160px; }  /* Gross / 2/3 Revenue */
    thead th:nth-child(7) { width: 200px; }  /* Submitted At */
    thead th:nth-child(8) { width: 220px; }  /* Actions */
    
    /* Allow horizontal scroll on small screens */
    .card:has(table) { 
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    
    /* Button styles */
    .actions { 
      display: flex; 
      gap: 10px; 
      flex-wrap: wrap;
    }
    
    .btn { 
      display: inline-flex; 
      align-items: center; 
      gap: 8px; 
      padding: 12px 18px; 
      border-radius: 6px; 
      border: 2px solid #2c3e50; 
      background: #2c3e50; 
      color: #fff; 
      text-decoration: none;
      font-weight: 600;
      font-size: 1.1rem;
      transition: all 0.2s ease;
    }
    
    .btn:hover {
      opacity: 0.9;
      transform: translateY(-1px);
    }
    
    .btn-outline { 
      background: transparent; 
      color: #2c3e50; 
      border: 2px solid #2c3e50;
    }
    
    .btn i {
      font-size: 1rem;
    }
    
    /* Counter chips */
    .counter-chip { 
      font-size: 1.1rem; 
      font-weight: 700 !important; 
      padding: 8px 12px !important; 
      border-width: 2px; 
      display: inline-block;
      min-width: 40px;
      text-align: center;
    }
    
    .counter-chip i { 
      font-size: 1rem; 
      margin-right: 5px;
    }
    
    /* Filter section */
    .filters { 
      display: flex; 
      gap: 15px; 
      align-items: flex-end; 
      flex-wrap: wrap;
      margin-bottom: 20px;
      background: #f8f9fa;
      padding: 15px;
      border-radius: 8px;
    }
    
    .filters label { 
      display: block; 
      font-weight: 600; 
      color: #2c3e50; 
      margin-bottom: 8px;
      font-size: 1.1rem;
    }
    
    .filters input { 
      padding: 10px 12px; 
      border: 1px solid #ced4da; 
      border-radius: 6px;
      font-size: 1.1rem;
      min-width: 200px;
    }
    
    .filters button {
      padding: 10px 20px;
      background: #2c3e50;
      color: white;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
      font-size: 1.1rem;
    }
    
    .filters button:hover {
      background: #1a252f;
    }
  </style>

<div class="container">
  <div class="card" style="display:flex; justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Daily Reports Archive</h2>
    <div class="actions">
      <a class="btn btn-outline" href="dashboard.php"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
  </div>

  <div class="card">
    <form class="filters" method="get" action="" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
      <div style="flex: 1; min-width: 220px;">
        <label for="from" style="display: block; font-size: 1.1rem; font-weight: 600; color: #2c3e50; margin-bottom: 8px;">
          <i class="far fa-calendar-alt" style="margin-right: 8px;"></i>From Date
        </label>
        <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($from); ?>" 
               style="width: 100%; padding: 10px 12px; border: 2px solid #ced4da; border-radius: 6px; font-size: 1.1rem;" />
      </div>
      <div style="flex: 1; min-width: 220px;">
        <label for="to" style="display: block; font-size: 1.1rem; font-weight: 600; color: #2c3e50; margin-bottom: 8px;">
          <i class="far fa-calendar-check" style="margin-right: 8px;"></i>To Date
        </label>
        <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($to); ?>" 
               style="width: 100%; padding: 10px 12px; border: 2px solid #ced4da; border-radius: 6px; font-size: 1.1rem;" />
      </div>
      </div>
      <div>
        <button class="btn" type="submit"><i class="fas fa-search"></i> Search</button>
      </div>
    </form>
  </div>

  <div class="card" style="overflow-x: auto;">
    <style>
      .report-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 15px;
        margin: 20px 0;
      }
      .report-table th {
        background-color: #2c3e50;
        color: white;
        padding: 15px 12px;
        text-align: center;
        font-weight: 600;
        font-size: 16px;
        white-space: nowrap;
      }
      .report-table th:first-child { border-top-left-radius: 8px; }
      .report-table th:last-child { border-top-right-radius: 8px; }
      .report-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #e0e0e0;
        vertical-align: middle;
        text-align: center;
      }
      .report-table tr:last-child td { border-bottom: none; }
      .report-table tr:hover { background-color: #f8f9fa; }
      .counter-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 14px;
        margin: 2px 0;
        white-space: nowrap;
      }
      .action-buttons {
        display: flex;
        gap: 8px;
        justify-content: center;
        flex-wrap: wrap;
      }
      .btn-outline {
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.2s;
      }
      .btn-outline:hover {
        transform: translateY(-2px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      }
      .no-data {
        padding: 30px;
        text-align: center;
        color: #666;
        font-size: 16px;
      }
      @media (max-width: 768px) {
        .report-table {
          font-size: 14px;
        }
        .report-table th,
        .report-table td {
          padding: 12px 8px;
        }
      }
    </style>
    <table class="report-table">
      <thead>
        <tr>
          <th><i class="far fa-calendar-alt"></i> Date</th>
          <th><i class="fas fa-car"></i> Cars</th>
          <th><i class="fas fa-motorcycle"></i> Motors</th>
          <th><i class="fas fa-rug"></i> Carpets</th>
          <th><i class="fas fa-money-bill-wave"></i> Gross (GHS)</th>
          <th><i class="fas fa-coins"></i> Closing Amount (<?php echo round($company_pct, 1); ?>%)</th>
          <th><i class="far fa-clock"></i> Submitted</th>
          <th><i class="fas fa-cog"></i> Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="no-data"><i class="far fa-folder-open" style="font-size: 24px; display: block; margin-bottom: 10px; opacity: 0.7;"></i>No archived reports found for the selected period.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): 
            $gross_amount = (float)$r['gross_amount_total'];
            $revenue = (float)$r['revenue_two_thirds_total'];
            $cars = (int)$r['total_cars_washed'];
            $motors = (int)$r['total_motors_washed'];
            $carpets = (int)$r['total_carpets_washed'];
            $total_washes = $cars + $motors + $carpets;
          ?>
            <tr>
              <td style="font-weight: 600; color: #2c3e50;" data-date="<?php echo $r['report_date']; ?>">
                <div><?php echo date('M j, Y', strtotime($r['report_date'])); ?></div>
                <div style="font-size: 13px; color: #7f8c8d; font-weight: normal;">
                  <?php echo date('l', strtotime($r['report_date'])); ?>
                </div>
              </td>
              <td style="font-weight: 600; color: #2c3e50;"><?php echo $cars; ?></td>
              <td style="font-weight: 600; color: #e67e22;"><?php echo $motors; ?></td>
              <td style="font-weight: 600; color: #8e44ad;"><?php echo $carpets; ?></td>
              <td style="font-weight: 600; color: #27ae60;">
                <div>GHS <?php echo number_format($gross_amount, 2); ?></div>
                <div style="font-size: 12px; color: #7f8c8d; font-weight: normal;">
                  <?php echo $total_washes; ?> wash<?php echo $total_washes != 1 ? 'es' : ''; ?>
                </div>
              </td>
              <td style="font-weight: 600; color: #f39c12;">
                GHS <?php echo number_format($revenue, 2); ?>
              </td>
              <td style="padding: 15px; font-size: 1.1rem; font-weight: 600; color: #2c3e50; border-bottom: 1px solid #eee;">
                <div style="white-space: nowrap;">
                  <i class="far fa-clock" style="color: #7f8c8d;"></i> 
                  <?php 
                  $dispTime = !empty($r['submitted_at']) ? $r['submitted_at'] : ($r['created_at'] ?? null);
                  echo $dispTime ? date('g:i A', strtotime($dispTime)) : 'N/A'; 
                  ?>
                </div>
                <div class="counter-chips" style="margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; justify-content: center;">
                  <?php if ($cars > 0): ?>
                    <span class="counter-chip" style="background-color: #e8f4fc; color: #2980b9;">
                      <i class="fas fa-car"></i> <?php echo $cars; ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($motors > 0): ?>
                    <span class="counter-chip" style="background-color: #fdf0e5; color: #e67e22;">
                      <i class="fas fa-motorcycle"></i> <?php echo $motors; ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($carpets > 0): ?>
                    <span class="counter-chip" style="background-color: #f5e9f9; color: #8e44ad;">
                      <i class="fas fa-rug"></i> <?php echo $carpets; ?>
                    </span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="padding: 15px; font-size: 1.1rem; font-weight: 600; color: #2c3e50; border-bottom: 1px solid #eee;">
                <div class="action-buttons">
                  <a href="washes.php?date=<?php echo urlencode($r['report_date']); ?>" 
                     class="btn-outline" 
                     style="background-color: #f8f9fa; color: #2c3e50; border: 1px solid #ddd;">
                    <i class="fas fa-list"></i> View
                  </a>
                  <a href="generate_pdf.php?date=<?php echo urlencode($r['report_date']); ?>" 
                     target="_blank"
                     class="btn-outline"
                     style="background-color: #e8f4fc; color: #2980b9; border: 1px solid #b3d9ff;">
                    <i class="fas fa-file-pdf"></i> Export PDF
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
// Pass PHP branding variables to JavaScript
const APP_NAME = <?php echo json_encode($appName); ?>;
const BAY_NAME = <?php echo json_encode($bayName); ?>;
const BRAND_PRIMARY = '#1B3FA0'; // Navy
const BRAND_ACCENT = '#00AEEF';  // Cyan
const ACTIVE_WORKERS = <?php echo json_encode($active_workers_list); ?>;

// Initialize jsPDF
const { jsPDF } = window.jspdf || {};

// Function to fetch wash records for a specific date
async function fetchWashRecords(dateStr) {
  try {
    const response = await fetch(`get_washes_by_date.php?date=${encodeURIComponent(dateStr)}`);
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    const data = await response.json();
    return data;
  } catch (error) {
    console.error('Error fetching wash records:', error);
    return [];
  }
}

async function printReport(dateStr, event) {
  console.log('Starting PDF generation for date:', dateStr);
  
  // Prevent default link behavior
  event.preventDefault();
  
  // Show loading state
  const button = event.target;
  const originalButtonText = button.innerHTML;
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF...';
  button.disabled = true;
  
  // Create a container for the PDF content
  const container = document.createElement('div');
  container.id = 'pdf-container';
  container.style.position = 'absolute';
  container.style.left = '-9999px';
  container.style.width = '210mm';
  container.style.padding = '20mm';
  container.style.fontFamily = 'Arial, sans-serif';
  container.style.color = '#333';
  document.body.appendChild(container);
  
  try {
    // Find the row with the matching date
    const rows = document.querySelectorAll('tbody tr');
    let targetRow = null;
    
    // Convert date string to YYYY-MM-DD format for comparison
    const normalizeDate = (dateString) => {
      const date = new Date(dateString);
      // If date is invalid, try parsing as is
      if (isNaN(date.getTime())) {
        return dateString.split('T')[0]; // Return the date part if it's in ISO format
      }
      return date.toISOString().split('T')[0];
    };
    
    const targetDate = normalizeDate(dateStr);
    console.log('Normalized target date:', targetDate);
    
    // Find the row with the matching date
    for (const row of rows) {
      const dateCell = row.cells[0];
      if (dateCell) {
        // Get the date from the data attribute if available, otherwise from text
        const rowDate = dateCell.getAttribute('data-date') || 
                       dateCell.querySelector('div:first-child')?.textContent.trim() || '';
        
        console.log('Checking row with date:', rowDate);
        
        // Normalize the row date for comparison
        const normalizedRowDate = normalizeDate(rowDate);
        console.log('Normalized row date:', normalizedRowDate);
        
        if (normalizedRowDate === targetDate) {
          console.log('Found matching row!');
          targetRow = row;
          break;
        }
      }
    }
    
    if (!targetRow) {
      throw new Error('Could not find report for the selected date');
    }
    
    // Fetch the detailed wash records
    const washRecords = await fetchWashRecords(targetDate);
    console.log('Fetched wash records:', washRecords);
    
    // Extract data from the row
    const dateCell = targetRow.cells[0];
    const dateText = dateCell.querySelector('div:first-child')?.textContent.trim() || '';
    const cars = targetRow.cells[1].textContent.trim();
    const motors = targetRow.cells[2].textContent.trim();
    const carpets = targetRow.cells[3].textContent.trim();
    const grossAmount = targetRow.cells[4].querySelector('div')?.textContent.trim() || '';
    const revenue = targetRow.cells[5].textContent.trim();
    const submittedAt = targetRow.cells[6].querySelector('div')?.textContent.trim() || '';
    const totalWashes = parseInt(cars) + parseInt(motors) + parseInt(carpets);
    
    // Create wash records HTML
    let washRecordsHtml = '';
    if (washRecords && washRecords.length > 0) {
      washRecordsHtml = `
        <div style="margin-top: 30px;">
          <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; color: #2c3e50;">
            Detailed Historical Log
          </h3>
          <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px;">
            <thead>
              <tr style="background-color: #f8fafc; color: #1B3FA0; border-bottom: 2px solid #1B3FA0;">
                <th style="padding: 10px; text-align: left;">Service</th>
                <th style="padding: 10px; text-align: left;">Category</th>
                <th style="padding: 10px; text-align: left;">Plate</th>
                <th style="padding: 10px; text-align: right;">Amount</th>
                <th style="padding: 10px; text-align: left;">Washer</th>
              </tr>
            </thead>
            <tbody>
              ${washRecords.map(record => `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 10px; font-weight: 700;">${record.service_name || record.service || '-'}</td>
                  <td style="padding: 10px;">${record.category_name || record.category || '-'}</td>
                  <td style="padding: 10px;">${record.number_plate || record.plate || record.plate_number || '-'}</td>
                  <td style="padding: 10px; text-align: right; font-weight: 700; color: #1B3FA0;">GHS ${parseFloat(record.amount || 0).toFixed(2)}</td>
                  <td style="padding: 10px;">${record.worker_name || record.washer_name || record.washer || 'Unassigned'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
          <div style="margin-top: 15px; text-align: right; font-weight: 800; color: #1B3FA0;">
            Total Records: ${washRecords.length}
          </div>
        </div>
      `;
    }

    // Performance Ranking Logic
    let rankingHtml = '';
    if (washRecords && washRecords.length > 0) {
        const stats = {};
        washRecords.forEach(r => {
            const name = r.worker_name || r.washer_name || r.washer || 'Unassigned';
            if (!stats[name]) stats[name] = { name, count: 0, revenue: 0 };
            stats[name].count++;
            stats[name].revenue += parseFloat(r.amount || 0);
        });

        const sorted = Object.values(stats).sort((a, b) => b.revenue - a.revenue || b.count - a.count);
        const top = sorted[0] || null;
        const mid = sorted.length > 2 ? sorted[Math.floor(sorted.length / 2)] : (sorted.length > 1 ? sorted[1] : null);
        const low = sorted.length > 1 ? sorted[sorted.length - 1] : null;

        const workedNames = new Set(Object.keys(stats));
        const inactive = ACTIVE_WORKERS.filter(name => !workedNames.has(name));

        rankingHtml = `
        <div style="margin-bottom: 20px; font-size: 15px; font-weight: 800; color: #1B3FA0; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">Daily Performance Ranking</div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px;">
            <div style="padding: 12px; border-radius: 10px; border: 1px solid #059669; background: #ecfdf5; text-align: center;">
                <div style="font-size: 9px; font-weight: 800; color: #059669; text-transform: uppercase;">Top Performer</div>
                <div style="font-size: 14px; font-weight: 800; color: #064e3b; margin: 3px 0;">${top ? top.name : 'N/A'}</div>
                <div style="font-size: 10px; color: #059669;">${top ? top.count + ' washes | GHS ' + top.revenue.toFixed(2) : '-'}</div>
            </div>
            <div style="padding: 12px; border-radius: 10px; border: 1px solid #1B3FA0; background: #eff6ff; text-align: center;">
                <div style="font-size: 9px; font-weight: 800; color: #1B3FA0; text-transform: uppercase;">Mid Performer</div>
                <div style="font-size: 14px; font-weight: 800; color: #172554; margin: 3px 0;">${mid ? mid.name : 'N/A'}</div>
                <div style="font-size: 10px; color: #1B3FA0;">${mid ? mid.count + ' washes | GHS ' + mid.revenue.toFixed(2) : '-'}</div>
            </div>
            <div style="padding: 12px; border-radius: 10px; border: 1px solid #eab308; background: #fefce8; text-align: center;">
                <div style="font-size: 9px; font-weight: 800; color: #854d0e; text-transform: uppercase;">Emerging</div>
                <div style="font-size: 14px; font-weight: 800; color: #713f12; margin: 3px 0;">${low && low !== mid ? low.name : 'None'}</div>
                <div style="font-size: 10px; color: #854d0e;">${low && low !== mid ? low.count + ' washes | GHS ' + low.revenue.toFixed(2) : '-'}</div>
            </div>
        </div>
        ${inactive.length > 0 ? `
            <div style="margin-bottom: 20px; padding: 10px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 11px;">
                <strong style="color: #64748b; text-transform: uppercase;">Inactive Workers:</strong> 
                <span style="color: #475569; margin-left: 5px;">${inactive.join(', ')}</span>
            </div>
        ` : ''}
        `;
    }

    // Assemble the complete HTML for the PDF
    const html = `
    <div style="background: #fff; width: 800px; padding: 40px; box-sizing: border-box; font-family: 'Inter', Arial, sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2.5px solid ${BRAND_PRIMARY}; padding-bottom: 20px; margin-bottom: 30px;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <img src="../frontend/new logo.png" alt="Logo" style="height: 50px;">
                <div>
                    <div style="font-size: 22px; font-weight: 800; color: ${BRAND_PRIMARY}; line-height: 1.2;">${APP_NAME}</div>
                    <div style="font-size: 11px; font-weight: 700; color: ${BRAND_ACCENT}; text-transform: uppercase;">Washing Bay Management Software</div>
                </div>
            </div>
            <div style="text-align: right;">
                <h1 style="margin: 0; color: #000; font-size: 20px; font-weight: 900; text-transform: uppercase;">${BAY_NAME}</h1>
                <div style="font-size: 12px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Daily Operations Report</div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; background: #f8fafc; padding: 15px 25px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
            <div>
                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Records Date</div>
                <div style="font-size: 16px; font-weight: 700; color: ${BRAND_PRIMARY};">${dateText}</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Status</div>
                <div style="font-size: 16px; font-weight: 700; color: #059669;">Verified Log</div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Volume</div>
                <div style="font-size: 16px; font-weight: 700; color: ${BRAND_PRIMARY};">${totalWashes} Washes</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
            <div style="padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Cars</div>
                <div style="font-size: 18px; font-weight: 800; color: ${BRAND_ACCENT};">${cars}</div>
            </div>
            <div style="padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Gross Total</div>
                <div style="font-size: 18px; font-weight: 800; color: ${BRAND_PRIMARY};">${grossAmount}</div>
            </div>
            <div style="padding: 15px; background: #065f46; border-radius: 12px; text-align: center; color: #fff;">
                <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; opacity: 0.8; margin-bottom: 5px;">Revenue</div>
                <div style="font-size: 18px; font-weight: 800;">${revenue}</div>
            </div>
            <div style="padding: 15px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Other Items</div>
                <div style="font-size: 18px; font-weight: 800; color: #8b5cf6;">${parseInt(carpets) + parseInt(motors)}</div>
            </div>
        </div>

        ${rankingHtml}

        ${washRecordsHtml}

        <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 10px;">
            <p><strong>${APP_NAME} SaaS Ecosystem</strong> — Intelligence in Motion</p>
            <p>Generated on ${new Date().toLocaleString()}. All data securely verified.</p>
        </div>
    </div>
    `;

    container.innerHTML = html;
    
    try {
      console.log('Starting html2canvas conversion...');
      const canvas = await html2canvas(container, {
        scale: 2,
        useCORS: true,
        logging: true,
        allowTaint: true
      });
      
      console.log('Canvas generated, creating PDF...');
      const pdf = new jsPDF({
        orientation: 'portrait',
        unit: 'mm',
        format: 'a4'
      });
      
      const imgData = canvas.toDataURL('image/png');
      const pdfWidth = pdf.internal.pageSize.getWidth() - 20; // 10mm margins
      const imgHeight = (canvas.height * pdfWidth) / canvas.width;
      
      pdf.addImage(imgData, 'PNG', 10, 10, pdfWidth, imgHeight);
      
      console.log('Saving PDF...');
      pdf.save(`Wash_Report_${dateStr.replace(/\//g, '-')}.pdf`);
      
    } catch (error) {
      console.error('Error generating PDF:', error);
      throw new Error('Failed to generate PDF: ' + error.message);
    }
  } catch (error) {
    console.error('Error in printReport:', error);
    alert('Error generating PDF: ' + (error.message || 'Unknown error occurred'));
  } finally {
    // Clean up
    const container = document.getElementById('pdf-container');
    if (container && container.parentNode) {
      try {
        document.body.removeChild(container);
      } catch (e) {
        console.warn('Error removing container:', e);
      }
    }
    // Restore button state
    if (button) {
      button.innerHTML = originalButtonText;
      button.disabled = false;
    }
  }
  
  return false;
}
</script>

</body>
</html>
