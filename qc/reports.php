<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require 'vendor/autoload.php'; // You'll need to install PhpSpreadsheet via Composer

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

$success = '';
$error = '';

// Fetch all possible defects for headers
$stmt_defects = $pdo->query("SELECT id, defect FROM defects ORDER BY defect");
$defects = $stmt_defects->fetchAll(PDO::FETCH_ASSOC);
$defect_names = array_column($defects, 'defect');

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

        // Get unique operation-defect combinations
        $operation_defects = [];
        foreach ($records as $record) {
            if (!empty($record['defect_name'])) {
                $key = $record['operation'] ? $record['operation'] . ' - ' . $record['defect_name'] : $record['defect_name'];
                if (!isset($operation_defects[$key])) {
                    $operation_defects[$key] = 0;
                }
            }
        }

        // Aggregate data by Date, Style No, and PO No
        $aggregated_data = [];
        foreach ($records as $record) {
            $date = date('Y-m-d', strtotime($record['created_at']));
            $style_no = $record['style_no'];
            $po_no = $record['po_no'];
            $key = $date . '-' . $style_no . '-' . $po_no;

            if (!isset($aggregated_data[$key])) {
                $aggregated_data[$key] = [
                    'date' => $date,
                    'style_no' => $style_no,
                    'po_no' => $po_no,
                    'EFC_no' => $record['EFC_no'] ?? 'N/A',
                    'total_qty' => 0,
                    'pass' => 0,
                    'reject' => 0,
                    'rework' => 0,
                    'defects' => array_fill_keys(array_keys($operation_defects), 0)
                ];
            }

            $quantity = $record['quantity'] ?? 1;
            $aggregated_data[$key]['total_qty'] += $quantity;

            switch ($record['status']) {
                case 'Pass':
                    $aggregated_data[$key]['pass'] += $quantity;
                    break;
                case 'Reject':
                    $aggregated_data[$key]['reject'] += $quantity;
                    if (!empty($record['defect_name'])) {
                        $defect_key = $record['operation'] ? $record['operation'] . ' - ' . $record['defect_name'] : $record['defect_name'];
                        $aggregated_data[$key]['defects'][$defect_key] += $quantity;
                    }
                    break;
                case 'Rework':
                    $aggregated_data[$key]['rework'] += $quantity;
                    if (!empty($record['defect_name'])) {
                        $defect_key = $record['operation'] ? $record['operation'] . ' - ' . $record['defect_name'] : $record['defect_name'];
                        $aggregated_data[$key]['defects'][$defect_key] += $quantity;
                    }
                    break;
            }
        }

        // Create new spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $headers = ['Date', 'Style No', 'PO No', 'EFC No', 'Total QTY', 'PASS', 'REJECT', 'REWORK'];
        $headers = array_merge($headers, array_keys($operation_defects));
        
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $header);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($col) . '1')->getFont()->setBold(true);
            $col++;
        }

        // Add data
        $row = 2;
        foreach ($aggregated_data as $data) {
            $col = 1;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['date']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['style_no']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['po_no']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['EFC_no']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['total_qty']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['pass']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['reject']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['rework']);

            foreach ($operation_defects as $defect_key => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $data['defects'][$defect_key] ?? 0);
            }
            $row++;
        }

        // Auto-size columns
        foreach (range(1, Coordinate::columnIndexFromString($sheet->getHighestColumn())) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        // Create filename
        $filename = ($report_type === 'desma' ? 'DESMA_' : 'QC_') . 'Report_' . date('Y-m-d') . '.xlsx';
        
        // Clear output buffer
        ob_clean();
        
        // Set headers for download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        // Save file
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
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
                        <input type="date" class="form-control" id="start_date" name="start_date" 
                               value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="report_type" class="form-label">Report Type</label>
                        <select class="form-select" id="report_type" name="report_type" required>
                            <option value="qc">QC Records</option>
                            <option value="desma">DESMA Records</option>
                            <option value="both">Both Records</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" name="generate_report" class="btn btn-primary w-100">
                            <i class="fas fa-download me-2"></i>Generate Excel Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
?> 