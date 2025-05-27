<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

// Debug dump of server and post data (Only for GET requests)
// if ($_SERVER['REQUEST_METHOD'] === 'GET') {
//     echo "<h3>Debug: Server and Post Data</h3>";
//     echo "<pre>Server:\n"; var_dump($_SERVER); echo "</pre>";
//     echo "<pre>POST:\n"; var_dump($_POST); echo "</pre>";
// }

// echo "Debug 1: After autoload.php<br>"; // Debug checkpoint 1

// echo "Debug: \$_SESSION['admin_id'] is: "; var_dump($_SESSION['admin_id']); echo "<br>"; // Debug session variable

// Check admin session
if (!isset($_SESSION['admin_id'])) {
    // Print debug message indicating session is not set
    // echo "Debug: Admin session is NOT set. Redirecting to login.<br>";
    // Then redirect
    header('Location: admin_login.php');
    exit();
} else {
    // Print debug message indicating session is set
    // echo "Debug: Admin session IS set. Proceeding.<br>";
}

$success = '';
$error = '';

// Fetch all possible defects for headers
$stmt_defects = $pdo->query("SELECT id, defect FROM defects ORDER BY defect");
$defects = $stmt_defects->fetchAll(PDO::FETCH_ASSOC);
$defect_names = array_column($defects, 'defect');

// echo "Debug 2: After fetching defects<br>"; // Debug checkpoint 2

// Debug checks for POST request and generate_report (Only for GET requests)
// if ($_SERVER['REQUEST_METHOD'] === 'GET') {
//     echo "Debug: REQUEST_METHOD is: " . $_SERVER['REQUEST_METHOD'] . "<br>";
//     echo "Debug: isset(\$_POST['generate_report']) is: "; var_dump(isset($_POST['generate_report'])); echo "<br>";
// }

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    try {
        // Get date range and report type
        $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        $report_type = $_POST['report_type'] ?? 'qc';

        // Base query for QC records
        $base_query = "
            SELECT
                qr.created_at,
                qr.quantity,
                qr.status,
                s.style_no,
                s.po_no,
                o.operation,
                d.defect AS defect_name,
                u.EFC_no
            FROM qc_records qr
            JOIN styles s ON qr.style_no = s.style_no AND qr.po_no = s.po_no
            LEFT JOIN operations o ON qr.operation_id = o.id
            LEFT JOIN defects d ON qr.defect_id = d.id
            LEFT JOIN users u ON qr.user_id = u.id
            WHERE DATE(qr.created_at) BETWEEN ? AND ?
        ";

        // Base query for DESMA records
        $desma_base_query = "
            SELECT
                qr.created_at,
                qr.quantity,
                qr.status,
                s.style_no,
                s.po_no,
                NULL as operation,
                d.defect AS defect_name,
                u.EFC_no
            FROM qc_desma_records qr
            JOIN styles s ON qr.style_no = s.style_no AND qr.po_no = s.po_no
            LEFT JOIN defects d ON qr.defect_id = d.id
            LEFT JOIN users u ON qr.user_id = u.id
            WHERE DATE(qr.created_at) BETWEEN ? AND ?
        ";

        // Combine queries based on report type
        if ($report_type === 'both') {
            $query = "($base_query) UNION ALL ($desma_base_query) ORDER BY created_at ASC, style_no ASC, po_no ASC";
            $params = [$start_date, $end_date, $start_date, $end_date];
        } else {
            $query = $report_type === 'desma' ? $desma_base_query : $base_query;
            $query .= " ORDER BY created_at ASC, style_no ASC, po_no ASC";
            $params = [$start_date, $end_date];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate data by Date, Style No, PO No, and EFC No (EPF No)
        $aggregated_data = [];
        // Keep track of all possible defect keys encountered in the selected date range for headers
        $all_possible_defect_keys_set = [];

        foreach ($records as $record) {
            $date = date('Y-m-d', strtotime($record['created_at']));
            $style_no = $record['style_no'];
            $po_no = $record['po_no'];
            $efc_no = $record['EFC_no'] ?? 'N/A'; // Get EFC_no (EPF No)

            // Create a unique key including date, style, po, and efc_no
            $key = $date . '-' . $style_no . '-' . $po_no . '-' . $efc_no;

            if (!isset($aggregated_data[$key])) {
                $aggregated_data[$key] = [
                    'date' => $date,
                    'style_no' => $style_no,
                    'po_no' => $po_no,
                    'EFC_no' => $efc_no,
                    'total_qty' => 0,
                    'pass' => 0,
                    'rework_pass' => 0,
                    'reject' => 0,
                    'rework' => 0,
                    'rework_defects' => [], // Initialize rework defects array for this row
                    'reject_defects' => []  // Initialize reject defects array for this row
                ];
            }

            $quantity = $record['quantity'] ?? 1;
            $aggregated_data[$key]['total_qty'] += $quantity;

            switch ($record['status']) {
                case 'Pass':
                    $aggregated_data[$key]['pass'] += $quantity;
                    break;
                case 'Rework Pass':
                    $aggregated_data[$key]['rework_pass'] += $quantity;
                    break;
                case 'Reject':
                    $aggregated_data[$key]['reject'] += $quantity;
                    if (!empty($record['defect_name'])) {
                        $defect_key = $record['operation'] ? $record['operation'] . ' - '. $record['defect_name'] : $record['defect_name'];
                        // Sum quantity for this specific defect under Reject status within this row's context
                        $aggregated_data[$key]['reject_defects'][$defect_key] = ($aggregated_data[$key]['reject_defects'][$defect_key] ?? 0) + $quantity;
                        $all_possible_defect_keys_set[$defect_key] = true; // Add to the set of all possible defect keys
                    }
                    break;
                case 'Rework':
                    $aggregated_data[$key]['rework'] += $quantity;
                    if (!empty($record['defect_name'])) {
                         $defect_key = $record['operation'] ? $record['operation'] . ' - '. $record['defect_name'] : $record['defect_name'];
                        // Sum quantity for this specific defect under Rework status within this row's context
                        $aggregated_data[$key]['rework_defects'][$defect_key] = ($aggregated_data[$key]['rework_defects'][$defect_key] ?? 0) + $quantity;
                         $all_possible_defect_keys_set[$defect_key] = true; // Add to the set of all possible defect keys
                    }
                    break;
            }
        }

        // Identify all unique defect keys that appeared in any Rework or Reject record
        $all_unique_defect_keys_set = [];
        foreach ($records as $record) {
             if (!empty($record['defect_name'])) {
                $key = $record['operation'] ? $record['operation'] . ' - '. $record['defect_name'] : $record['defect_name'];
                if ($record['status'] === 'Rework' || $record['status'] === 'Reject') {
                    $all_unique_defect_keys_set[$key] = true;
                }
            }
        }

        // Build the ordered list of all unique defect keys
        $all_unique_defect_keys = array_keys($all_unique_defect_keys_set);
        uasort($all_unique_defect_keys, function($a, $b) {
            $name_a = strpos($a, ' - ') !== false ? explode(' - ', $a)[1] : $a;
            $name_b = strpos($b, ' - ') !== false ? explode(' - ', $b)[1] : $b;
            return strcmp($name_a, $name_b);
        });

        // Prepare data for Google Sheets
        $headers = ['Date', 'Style No', 'PO No', 'EPF No', 'Total QTY', 'PASS', 'REWORK PASS', 'REWORK'];

        // Add Rework defect headers
        foreach ($all_unique_defect_keys as $defect_key) {
            $defect_name_only = strpos($defect_key, ' - ') !== false ? explode(' - ', $defect_key)[1] : $defect_key;
            $headers[] = trim($defect_name_only);
        }

         // Add REJECT header
        $headers[] = 'REJECT';

        // Add Reject defect headers
        foreach ($all_unique_defect_keys as $defect_key) {
            $defect_name_only = strpos($defect_key, ' - ') !== false ? explode(' - ', $defect_key)[1] : $defect_key;
            $headers[] = trim($defect_name_only);
        }

        $data = [];
        $data[] = $headers; // Add headers as first row
        
        foreach ($aggregated_data as $row) {
            $row_data = [
                $row['date'],
                $row['style_no'],
                $row['po_no'],
                $row['EFC_no'],
                $row['total_qty'],
                $row['pass'],
                $row['rework_pass'],
                $row['rework'] // Value for the REWORK status column
            ];
            
            // Add Rework defect quantities
            foreach ($all_unique_defect_keys as $defect_key) {
                $row_data[] = $row['rework_defects'][$defect_key] ?? 0;
            }

             // Add REJECT total quantity
            $row_data[] = $row['reject'];

            // Add Reject defect quantities
            foreach ($all_unique_defect_keys as $defect_key) {
                $row_data[] = $row['reject_defects'][$defect_key] ?? 0;
            }
            
            $data[] = $row_data;
        }

        // Debug log the aggregated data
        error_log('Aggregated data before CSV generation: ' . print_r($aggregated_data, true));

        // Convert data to CSV format
        $csv_data = '';
        foreach ($data as $rowIndex => $row) {
            $csv_data .= implode(',', array_map(function($value, $colIndex) use ($rowIndex) {
                // Do not wrap 'Date' column (first column) in quotes for any row
                if ($colIndex === 0) {
                    return $value;
                } else {
                    return '"' . str_replace('"', '""', $value) . '"';
                }
            }, $row, array_keys($row))) . "\n";
        }

        // Create filename
        $filename = ($report_type === 'desma' ? 'DESMA_' : 'QC_') . 'Report_' . date('Y-m-d') . '.csv';
        
        // Set headers for download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        // Output CSV data
        echo $csv_data;
        exit();

    } catch (Exception $e) {
        $error = 'Error generating report: ' . $e->getMessage();
        error_log('QC Report Error: ' . $e->getMessage());
    }
}

// Only output HTML if we're not generating a report
if (!isset($_POST['generate_report'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
        }
        
        body {
            background-color: #f8f9fa;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .btn-primary {
            background: var(--accent-color);
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            transition: color 0.2s;
        }
        
        .nav-link:hover, .nav-link.active {
            color: white !important;
        }
        
        /* Date picker styles */
        .flatpickr-calendar {
            width: 100% !important;
            max-width: 300px !important;
        }
        
        .flatpickr-day {
            height: 35px !important;
            line-height: 35px !important;
        }
        
        .form-control[readonly] {
            background-color: #fff;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .flatpickr-calendar {
                position: fixed !important;
                top: 50% !important;
                left: 50% !important;
                transform: translate(-50%, -50%) !important;
                z-index: 9999 !important;
            }
            
            .flatpickr-months {
                height: 40px !important;
            }
            
            .flatpickr-month {
                height: 40px !important;
            }
            
            .flatpickr-current-month {
                padding: 5px 0 !important;
            }
            
            .flatpickr-weekday {
                height: 30px !important;
                line-height: 30px !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="admin_dashboard.php">
                <i class="fas fa-shoe-prints me-2"></i>Boots QC Admin
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_operations.php">
                            <i class="fas fa-tasks me-1"></i>Operations & Defects
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="reports.php">
                            <i class="fas fa-chart-bar me-1"></i>Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_users.php">
                            <i class="fas fa-users me-1"></i>Manage Users
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt me-1"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-excel me-2"></i>Generate QC Report</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="text" class="form-control datepicker" id="start_date" name="start_date" 
                               value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required readonly>
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="text" class="form-control datepicker" id="end_date" name="end_date" 
                               value="<?php echo date('Y-m-d'); ?>" required readonly>
                    </div>
                    <div class="col-md-3">
                        <label for="report_type" class="form-label">Report Type</label>
                        <select class="form-select" id="report_type" name="report_type" required>
                            <option value="desma">DESMA Records</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" name="generate_report" class="btn btn-primary w-100">
                            <i class="fas fa-download me-2"></i>Generate CSV Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                allowInput: true,
                mobile: true,
                disableMobile: false,
                onOpen: function(selectedDates, dateStr, instance) {
                    // Ensure calendar is visible on mobile
                    if (window.innerWidth <= 768) {
                        instance.calendarContainer.style.position = 'fixed';
                        instance.calendarContainer.style.top = '50%';
                        instance.calendarContainer.style.left = '50%';
                        instance.calendarContainer.style.transform = 'translate(-50%, -50%)';
                        instance.calendarContainer.style.zIndex = '9999';
                    }
                }
            });
        });
    </script>
</body>
</html>
<?php
}
?> 