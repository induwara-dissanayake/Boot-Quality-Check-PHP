<?php
require_once 'db.php';
requireLogin();

// Get filter parameters
$defect_type = $_GET['defect_type'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build query
$query = "SELECT qr.*, u.name as checker_name, u.EFC_no, q.username as qcc_username 
          FROM qc_records qr 
          LEFT JOIN users u ON qr.user_id = u.id 
          LEFT JOIN qcc q ON qr.qcc_id = q.id 
          WHERE 1=1";
$params = [];

if ($defect_type) {
    $query .= " AND defect_type = ?";
    $params[] = $defect_type;
}

if ($start_date) {
    $query .= " AND check_date >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $query .= " AND check_date <= ?";
    $params[] = $end_date;
}

$query .= " ORDER BY check_date DESC, id DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - View Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">Boots QC System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="qc_form.php">New QC Entry</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="view_data.php">View Records</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">Quality Check Records</h4>
            </div>
            <div class="card-body">
                <!-- Filter Form -->
                <form method="GET" class="mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="defect_type" class="form-label">Defect Type</label>
                            <select class="form-select" id="defect_type" name="defect_type">
                                <option value="">All Types</option>
                                <option value="None" <?php echo $defect_type === 'None' ? 'selected' : ''; ?>>None</option>
                                <option value="Stitching" <?php echo $defect_type === 'Stitching' ? 'selected' : ''; ?>>Stitching</option>
                                <option value="Sole" <?php echo $defect_type === 'Sole' ? 'selected' : ''; ?>>Sole</option>
                                <option value="Color" <?php echo $defect_type === 'Color' ? 'selected' : ''; ?>>Color</option>
                                <option value="Size" <?php echo $defect_type === 'Size' ? 'selected' : ''; ?>>Size</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Filter</button>
                            <a href="export.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">Export to Excel</a>
                        </div>
                    </div>
                </form>

                <!-- Records Table -->
                <div class="table-responsive">
                    <table class="table table-striped" id="recordsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product ID</th>
                                <th>Date</th>
                                <th>Defect Type</th>
                                <th>Description</th>
                                <th>Quality Checker</th>
                                <th>EFC Number</th>
                                <th>QCC Username</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['id']); ?></td>
                                <td><?php echo htmlspecialchars($record['product_id']); ?></td>
                                <td><?php echo htmlspecialchars($record['check_date']); ?></td>
                                <td><?php echo htmlspecialchars($record['defect_type']); ?></td>
                                <td><?php echo htmlspecialchars($record['description']); ?></td>
                                <td><?php echo htmlspecialchars($record['checker_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['EFC_no']); ?></td>
                                <td><?php echo htmlspecialchars($record['qcc_username']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#recordsTable').DataTable({
                order: [[2, 'desc'], [0, 'desc']],
                pageLength: 25
            });
        });
    </script>
</body>
</html> 