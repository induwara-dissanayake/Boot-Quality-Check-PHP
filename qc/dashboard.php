<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user details
$stmt = $pdo->prepare("SELECT EFC_no, name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get most common operations (last 30 days) or first 10 operations
$operations = $pdo->query("
    WITH operation_counts AS (
        SELECT o.id, o.operation, COUNT(*) as count 
        FROM qc_records qr 
        JOIN operations o ON qr.operation_id = o.id 
        WHERE qr.check_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY o.id, o.operation
    )
    SELECT id, operation, count
    FROM operation_counts
    ORDER BY count DESC
    LIMIT 10
")->fetchAll();

// If no operations found in last 30 days, get first 10 operations
if (empty($operations)) {
    $operations = $pdo->query("
        SELECT id, operation, 0 as count
        FROM operations 
        ORDER BY operation 
        LIMIT 10
    ")->fetchAll();
}

// Get most common defects (last 30 days) or first 10 defects
$defects = $pdo->query("
    WITH defect_counts AS (
        SELECT d.id, d.defect, COUNT(*) as count 
        FROM qc_records qr 
        JOIN defects d ON qr.defect_id = d.id 
        WHERE qr.check_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY d.id, d.defect
    )
    SELECT id, defect, count
    FROM defect_counts
    ORDER BY count DESC
    LIMIT 10
")->fetchAll();

// If no defects found in last 30 days, get first 10 defects
if (empty($defects)) {
    $defects = $pdo->query("
        SELECT id, defect, 0 as count
        FROM defects 
        ORDER BY defect 
        LIMIT 10
    ")->fetchAll();
}

// Get all operations and defects for search
$allOperations = $pdo->query("SELECT id, operation FROM operations ORDER BY operation")->fetchAll();
$allDefects = $pdo->query("SELECT id, defect FROM defects ORDER BY defect")->fetchAll();

// Get today's QC statistics
$today = date('Y-m-d');
$stats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'Pass' THEN 1 ELSE 0 END) as pass_count,
        SUM(CASE WHEN status = 'Rework' THEN 1 ELSE 0 END) as rework_count,
        SUM(CASE WHEN status = 'Reject' THEN 1 ELSE 0 END) as reject_count
    FROM qc_records 
    WHERE qcc_id = ? AND check_date = ?
");
$stats->execute([$_SESSION['user_id'], $today]);
$daily_stats = $stats->fetch();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Handle image request
    if (isset($_POST['style_no']) && isset($_POST['po_no']) && !isset($_POST['action'])) {
        $stmt = $pdo->prepare("SELECT image_path FROM styles WHERE style_no = ? AND po_no = ?");
        $stmt->execute([$_POST['style_no'], $_POST['po_no']]);
        $style = $stmt->fetch();
        echo json_encode(['image_path' => $style ? $style['image_path'] : null]);
        exit();
    }
    
    // Handle QC record submission
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $response = ['success' => false, 'message' => ''];
        
        try {
            // Validate style number format (4 digits)
            if (!preg_match('/^\d{4}$/', $_POST['style_no'])) {
                throw new Exception('Style number must be exactly 4 digits');
            }

            // Validate PO number format (6 digits)
            if (!preg_match('/^\d{6}$/', $_POST['po_no'])) {
                throw new Exception('PO number must be exactly 6 digits');
            }

            // Validate style and PO exist in database
            $stmt = $pdo->prepare("SELECT id FROM styles WHERE style_no = ? AND po_no = ?");
            $stmt->execute([$_POST['style_no'], $_POST['po_no']]);
            if (!$stmt->fetch()) {
                throw new Exception('Invalid style number or PO number combination');
            }

            // Debug log
            error_log('POST data: ' . print_r($_POST, true));
            error_log('Session data: ' . print_r($_SESSION, true));

            // Validate required fields
            if (empty($_POST['style_no']) || empty($_POST['po_no']) || empty($_POST['status'])) {
                throw new Exception('Missing required fields: ' . 
                    'style_no=' . (empty($_POST['style_no']) ? 'empty' : 'set') . 
                    ', po_no=' . (empty($_POST['po_no']) ? 'empty' : 'set') . 
                    ', status=' . (empty($_POST['status']) ? 'empty' : 'set'));
            }

            // For rework/reject, validate operation and defect
            if ($_POST['status'] !== 'Pass' && (empty($_POST['operation_id']) || empty($_POST['defect_id']))) {
                throw new Exception('Operation and defect are required for rework/reject: ' . 
                    'operation_id=' . (empty($_POST['operation_id']) ? 'empty' : 'set') . 
                    ', defect_id=' . (empty($_POST['defect_id']) ? 'empty' : 'set'));
            }

            // Validate session
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('User not logged in');
            }

            $stmt = $pdo->prepare("
                INSERT INTO qc_records (
                    style_no, po_no, operation_id, defect_id, 
                    user_id, qcc_id, status, quantity, check_date, check_time
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME())
            ");
            
            $params = [
                $_POST['style_no'],
                $_POST['po_no'],
                $_POST['operation_id'] ?? null,
                $_POST['defect_id'] ?? null,
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_POST['status'],
                $_POST['quantity'] ?? 1
            ];

            // Debug log
            error_log('SQL Parameters: ' . print_r($params, true));

            $result = $stmt->execute($params);

            if (!$result) {
                throw new Exception('Failed to insert record: ' . implode(', ', $stmt->errorInfo()));
            }
            
            $response['success'] = true;
            $response['message'] = 'QC record saved successfully';
            $response['style_no'] = $_POST['style_no'];
            $response['po_no'] = $_POST['po_no'];
        } catch (Exception $e) {
            $response['message'] = 'Error saving QC record: ' . $e->getMessage();
            error_log('QC Record Error: ' . $e->getMessage());
        }
        
        echo json_encode($response);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --border-color: #dee2e6;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f6fa;
        }

        .main-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .navbar {
            background-color: var(--primary-color) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 600;
            font-size: 1.4rem;
        }

        .stats-box {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .stats-box h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            background: var(--light-bg);
        }

        .stat-item.pass { background-color: rgba(46, 204, 113, 0.1); }
        .stat-item.rework { background-color: rgba(241, 196, 15, 0.1); }
        .stat-item.reject { background-color: rgba(231, 76, 60, 0.1); }

        .stat-item .count {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .stat-item .label {
            font-size: 0.9rem;
            color: #666;
        }

        .search-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .search-section .form-group {
            margin-bottom: 15px;
        }

        .search-section .form-label {
            font-weight: 500;
            color: var(--primary-color);
            margin-bottom: 8px;
        }

        .search-section .form-control {
            border-radius: 8px;
            border: 1px solid var(--border-color);
            padding: 10px 15px;
            height: auto;
        }

        .search-section .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            height: auto;
        }

        .content-box {
            display: grid;
            grid-template-columns: 1fr 1fr 300px;
            gap: 20px;
            margin-bottom: 30px;
        }

        .operation-box {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .operation-box h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .selection-row {
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            position: relative;
        }
        .selection-row:hover {
            background-color: var(--light-bg);
        }
        .selection-row.selected {
            background-color: var(--success-color);
            color: white;
            border-color: var(--success-color);
        }
        .selection-row.selected label {
            color: white;
        }
        .selection-row input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            left: 0;
            top: 0;
            margin: 0;
            cursor: pointer;
        }
        .selection-row label {
            flex: 1;
            margin-bottom: 0;
            cursor: pointer;
            pointer-events: none;
        }
        .selection-row .badge {
            pointer-events: none;
        }

        .image-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .image-container {
            width: 100%;
            height: 300px;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background-color: var(--light-bg);
            margin-bottom: 20px;
        }

        .image-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .image-placeholder {
            color: #6c757d;
            text-align: center;
        }

        .image-placeholder i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .image-placeholder p {
            font-size: 0.9rem;
            margin: 0;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .action-buttons .btn {
            padding: 12px;
            font-weight: 500;
            border-radius: 8px;
        }

        .btn-pass {
            background-color: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .btn-rework {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
            color: black;
        }

        .btn-reject {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
            color: white;
        }

        .btn-complete {
            width: 100%;
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            color: white;
            padding: 12px;
            font-weight: 500;
            border-radius: 8px;
        }

        .search-section {
            margin-top: 15px;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--light-bg);
        }

        .search-input {
            margin-bottom: 10px;
        }

        .search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: white;
        }

        .search-result-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s;
        }

        .search-result-item:hover {
            background-color: var(--light-bg);
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .operation-box.disabled,
        .defect-box.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .operation-box.disabled .selection-row,
        .defect-box.disabled .selection-row {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .operation-box.disabled .selection-row:hover,
        .defect-box.disabled .selection-row:hover {
            background-color: #f8f9fa;
        }

        .operation-box.disabled .selection-row.selected,
        .defect-box.disabled .selection-row.selected {
            background-color: #e9ecef;
        }

        .complete-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .complete-btn:hover:not(:disabled) {
            background-color: #218838;
            transform: translateY(-1px);
        }

        .complete-btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .status-btn {
            padding: 8px 16px;
            border-radius: 5px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .status-btn.active {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .status-btn.pass.active {
            background-color: #28a745;
            color: white;
        }

        .status-btn.rework.active {
            background-color: #ffc107;
            color: #000;
        }

        .status-btn.reject.active {
            background-color: #dc3545;
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .content-box {
                grid-template-columns: 1fr 1fr 250px;
                gap: 15px;
            }

            .image-container {
                height: 250px;
            }
        }

        @media (max-width: 992px) {
            .main-container {
                padding: 15px;
            }

            .content-box {
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            .image-section {
                grid-column: span 2;
            }

            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }

            .stat-item {
                padding: 12px;
            }

            .stat-item .count {
                font-size: 1.5rem;
            }

            .search-section {
                padding: 15px;
            }

            .selection-row {
                padding: 10px 12px;
            }
        }

        @media (max-width: 768px) {
            .content-box {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .image-section {
                grid-column: span 1;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .search-section .row {
                flex-direction: column;
            }

            .search-section .col-md-4 {
                width: 100%;
                margin-bottom: 10px;
            }

            .search-section .btn {
                width: 100%;
            }

            .action-buttons {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .action-buttons .btn {
                width: 100%;
            }

            .image-container {
                height: 200px;
            }

            .navbar-brand {
                font-size: 1.2rem;
            }

            .selection-row {
                padding: 8px 10px;
            }

            .selection-row label {
                font-size: 0.9rem;
            }

            .badge {
                font-size: 0.7rem;
            }
        }

        @media (max-width: 576px) {
            .main-container {
                padding: 10px;
            }

            .stats-box {
                padding: 15px;
            }

            .search-section {
                padding: 12px;
            }

            .operation-box, .defect-box, .image-section {
                padding: 15px;
            }

            .image-container {
                height: 180px;
            }

            .btn-complete {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
        }

        /* Add smooth transitions for responsive changes */
        .content-box, .stats-grid, .search-section, .selection-row, .image-container {
            transition: all 0.3s ease;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">Boots QC System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="statistics.php">QC Statistics</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="desma.php">DESMA</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="fas fa-user me-1"></i>
                            <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['EFC_no']); ?>)
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="main-container">
        <form id="styleForm">
            <div class="search-section">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="style_no" class="form-label">Style Number</label>
                            <input type="text" class="form-control" id="style_no" name="style_no" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="po_no" class="form-label">PO Number</label>
                            <input type="text" class="form-control" id="po_no" name="po_no" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i>Search
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-box">
                <div class="operation-box" id="operationBox">
                    <h6>Operation</h6>
                    <?php foreach ($operations as $operation): ?>
                    <div class="selection-row">
                        <input class="form-check-input operation-check" type="radio" 
                               name="operation" id="op<?php echo $operation['id']; ?>" 
                               value="<?php echo $operation['id']; ?>">
                        <label class="form-check-label" for="op<?php echo $operation['id']; ?>">
                            <?php echo htmlspecialchars($operation['operation']); ?>
                            <?php if ($operation['count'] > 0): ?>
                            <span class="badge bg-secondary ms-2"><?php echo $operation['count']; ?></span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <div class="selection-row">
                        <input class="form-check-input operation-check" type="radio" 
                               name="operation" id="opOther" value="">
                        <label class="form-check-label" for="opOther">Other</label>
                    </div>
                    <div class="search-section" style="display: none;">
                        <input type="text" class="form-control search-input" placeholder="Search operation...">
                        <div class="search-results"></div>
                    </div>
                </div>
                <div class="operation-box" id="defectBox">
                    <h6>Defect</h6>
                    <?php foreach ($defects as $defect): ?>
                    <div class="selection-row">
                        <input class="form-check-input defect-check" type="radio" 
                               name="defect" id="def<?php echo $defect['id']; ?>" 
                               value="<?php echo $defect['id']; ?>">
                        <label class="form-check-label" for="def<?php echo $defect['id']; ?>">
                            <?php echo htmlspecialchars($defect['defect']); ?>
                            <?php if ($defect['count'] > 0): ?>
                            <span class="badge bg-secondary ms-2"><?php echo $defect['count']; ?></span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <div class="selection-row">
                        <input class="form-check-input defect-check" type="radio" 
                               name="defect" id="defOther" value="">
                        <label class="form-check-label" for="defOther">Other</label>
                    </div>
                    <div class="search-section" style="display: none;">
                        <input type="text" class="form-control search-input" placeholder="Search defect...">
                        <div class="search-results"></div>
                    </div>
                </div>
                <div class="image-section">
                    <div class="image-container" id="imageContainer">
                        <div class="image-placeholder">
                            <i class="fas fa-image"></i>
                            <p>Enter style number and PO number to view image</p>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-pass" data-status="Pass">Pass</button>
                        <button type="button" class="btn btn-rework" data-status="Rework">Rework</button>
                        <button type="button" class="btn btn-reject" data-status="Reject">Reject</button>
                    </div>
                    <button type="button" class="btn btn-complete" id="completeBtn" disabled>Complete</button>
                    <div class="text-center mt-2">
                        <a href="admin_login.php" class="text-muted" style="font-size: 0.8rem; text-decoration: none;">
                            <i class="fas fa-lock me-1"></i>Admin Login
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStatus = null;
        const operationBox = document.getElementById('operationBox');
        const defectBox = document.getElementById('defectBox');
        const completeBtn = document.getElementById('completeBtn');

        // Store all operations and defects for search
        const allOperations = <?php echo json_encode($allOperations); ?>;
        const allDefects = <?php echo json_encode($allDefects); ?>;

        let selectedOperationId = null;
        let selectedDefectId = null;

        // Handle image search
        document.getElementById('styleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const styleNo = document.getElementById('style_no').value;
            const poNo = document.getElementById('po_no').value;
            const imageContainer = document.getElementById('imageContainer');

            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `style_no=${encodeURIComponent(styleNo)}&po_no=${encodeURIComponent(poNo)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.image_path) {
                    imageContainer.innerHTML = `<img src="${data.image_path}" alt="Style Image">`;
                } else {
                    imageContainer.innerHTML = `
                        <div class="image-placeholder">
                            <i class="fas fa-exclamation-circle"></i>
                            <p>No image found for this style and PO number</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                imageContainer.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading image</p>
                    </div>
                `;
            });
        });

        // Add input validation for style and PO numbers
        document.getElementById('style_no').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4);
        });

        document.getElementById('po_no').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
        });

        // Disable operation and defect checkboxes by default
        document.querySelectorAll('.operation-check, .defect-check').forEach(checkbox => {
            checkbox.disabled = true;
        });

        // Optimize row selection
        document.querySelectorAll('.selection-row').forEach(row => {
            const radio = row.querySelector('input[type="radio"]');
            const label = row.querySelector('label');
            
            // Handle row click
            row.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Don't allow selection if the box is disabled
                if (this.closest('.operation-box').classList.contains('disabled')) {
                    return;
                }
                
                const type = this.closest('.operation-box').id === 'operationBox' ? 'operation' : 'defect';
                const id = radio.value;
                
                // Remove selected class from all rows in this box
                this.closest('.operation-box').querySelectorAll('.selection-row').forEach(r => {
                    r.classList.remove('selected');
                });
                
                // Add selected class to clicked row
                this.classList.add('selected');
                
                // Check the radio button
                radio.checked = true;
                
                // Handle "Other" selection
                if (id === '') {
                    const searchSection = this.nextElementSibling;
                    if (searchSection.classList.contains('search-section')) {
                        searchSection.style.display = 'block';
                        searchSection.querySelector('.search-input').focus();
                    }
                } else {
                    // Hide search section if it's visible
                    const searchSection = this.nextElementSibling;
                    if (searchSection.classList.contains('search-section')) {
                        searchSection.style.display = 'none';
                    }
                }
                
                // Enable complete button if both are selected
                if (currentStatus !== 'Pass') {
                    const operationSelected = document.querySelector('#operationBox .selection-row.selected');
                    const defectSelected = document.querySelector('#defectBox .selection-row.selected');
                    completeBtn.disabled = !(operationSelected && defectSelected);
                }
            });
        });

        // Remove the old event listeners for radio buttons since we're handling clicks on the row
        document.querySelectorAll('.operation-check, .defect-check').forEach(checkbox => {
            checkbox.removeEventListener('change', null);
        });

        // Handle search inputs
        document.querySelectorAll('.search-input').forEach(input => {
            input.addEventListener('input', function() {
                const type = this.closest('.operation-box').id === 'operationBox' ? 'operation' : 'defect';
                const searchResults = this.nextElementSibling;
                const searchTerm = this.value.toLowerCase();
                
                if (searchTerm.length < 2) {
                    searchResults.innerHTML = '';
                    return;
                }
                
                const items = type === 'operation' ? allOperations : allDefects;
                const filteredItems = items.filter(item => 
                    item[type].toLowerCase().includes(searchTerm)
                );
                
                searchResults.innerHTML = filteredItems.map(item => `
                    <div class="search-result-item" data-id="${item.id}">
                        ${item[type]}
                    </div>
                `).join('');
                
                // Handle search result clicks
                searchResults.querySelectorAll('.search-result-item').forEach(result => {
                    result.addEventListener('click', function() {
                        const id = this.dataset.id;
                        const text = this.textContent.trim();
                        
                        // Update the "Other" radio button's label
                        const otherLabel = this.closest('.operation-box').querySelector(`#${type === 'operation' ? 'op' : 'def'}Other`).nextElementSibling;
                        otherLabel.textContent = text;
                        
                        // Set the value of the "Other" radio button
                        const otherRadio = otherLabel.previousElementSibling;
                        otherRadio.value = id;
                        
                        // Clear search
                        this.closest('.search-section').querySelector('.search-input').value = '';
                        searchResults.innerHTML = '';
                        
                        // Enable complete button if both are selected
                        if (currentStatus !== 'Pass') {
                            const operationSelected = document.querySelector('#operationBox .selection-row.selected');
                            const defectSelected = document.querySelector('#defectBox .selection-row.selected');
                            completeBtn.disabled = !(operationSelected && defectSelected);
                        }
                    });
                });
            });
        });

        // Update status button handler
        document.querySelectorAll('.action-buttons .btn').forEach(button => {
            button.addEventListener('click', function() {
                currentStatus = this.dataset.status;
                
                // Reset all buttons
                document.querySelectorAll('.action-buttons .btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');

                // Enable/disable operation and defect boxes
                if (currentStatus === 'Pass') {
                    operationBox.classList.add('disabled');
                    defectBox.classList.add('disabled');
                    document.querySelectorAll('.operation-check, .defect-check').forEach(checkbox => {
                        checkbox.disabled = true;
                        checkbox.checked = false;
                    });
                    document.querySelectorAll('.selection-row').forEach(row => {
                        row.classList.remove('selected');
                    });
                    document.querySelectorAll('.search-section').forEach(section => {
                        if (section.closest('.operation-box')) {
                            section.style.display = 'none';
                        }
                    });
                    completeBtn.disabled = false;
                } else {
                    operationBox.classList.remove('disabled');
                    defectBox.classList.remove('disabled');
                    document.querySelectorAll('.operation-check, .defect-check').forEach(checkbox => {
                        checkbox.disabled = false;
                    });
                    completeBtn.disabled = true;
                }
            });
        });

        // Update complete button click handler
        completeBtn.addEventListener('click', function() {
            const styleNo = document.getElementById('style_no').value;
            const poNo = document.getElementById('po_no').value;
            
            // Get the selected operation and defect values
            const selectedOperation = document.querySelector('#operationBox .selection-row.selected');
            const selectedDefect = document.querySelector('#defectBox .selection-row.selected');
            const operationId = selectedOperation ? selectedOperation.querySelector('input[type="radio"]').value : null;
            const defectId = selectedDefect ? selectedDefect.querySelector('input[type="radio"]').value : null;

            // Validate style number format
            if (!/^\d{4}$/.test(styleNo)) {
                alert('Style number must be exactly 4 digits');
                return;
            }

            // Validate PO number format
            if (!/^\d{6}$/.test(poNo)) {
                alert('PO number must be exactly 6 digits');
                return;
            }

            // Debug log for selection values
            console.log('Selection values:', {
                operationId,
                defectId,
                currentStatus,
                selectedOperation: selectedOperation ? selectedOperation.textContent.trim() : null,
                selectedDefect: selectedDefect ? selectedDefect.textContent.trim() : null
            });

            if (currentStatus !== 'Pass' && (!operationId || !defectId)) {
                alert('Please select both operation and defect for ' + currentStatus);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('style_no', styleNo);
            formData.append('po_no', poNo);
            formData.append('status', currentStatus);
            if (operationId) formData.append('operation_id', operationId);
            if (defectId) formData.append('defect_id', defectId);

            // Debug log
            console.log('Submitting form data:', {
                style_no: styleNo,
                po_no: poNo,
                status: currentStatus,
                operation_id: operationId,
                defect_id: defectId
            });

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Server response:', data);
                if (data.success) {
                    // Store style and PO in sessionStorage
                    sessionStorage.setItem('lastStyleNo', data.style_no);
                    sessionStorage.setItem('lastPoNo', data.po_no);
                    
                    // Reset form
                    document.querySelectorAll('.operation-check, .defect-check').forEach(checkbox => {
                        checkbox.disabled = true;
                        checkbox.checked = false;
                    });
                    document.querySelectorAll('.selection-row').forEach(row => {
                        row.classList.remove('selected');
                    });
                    document.querySelectorAll('.action-buttons .btn').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    document.querySelectorAll('.search-section').forEach(section => {
                        section.style.display = 'none';
                    });
                    operationBox.classList.add('disabled');
                    defectBox.classList.add('disabled');
                    completeBtn.disabled = true;
                    currentStatus = null;
                    
                    // Reload page to update statistics
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error occurred'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving QC record: ' + error.message);
            });
        });

        // Add this at the start of your script to restore values on page load
        document.addEventListener('DOMContentLoaded', function() {
            const lastStyleNo = sessionStorage.getItem('lastStyleNo');
            const lastPoNo = sessionStorage.getItem('lastPoNo');
            
            if (lastStyleNo && lastPoNo) {
                document.getElementById('style_no').value = lastStyleNo;
                document.getElementById('po_no').value = lastPoNo;
                
                // Trigger image load
                const imageContainer = document.getElementById('imageContainer');
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `style_no=${encodeURIComponent(lastStyleNo)}&po_no=${encodeURIComponent(lastPoNo)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.image_path) {
                        imageContainer.innerHTML = `<img src="${data.image_path}" alt="Style Image">`;
                    }
                });
            }
        });

        // Handle "Other" radio button clicks
        document.querySelectorAll('#opOther, #defOther').forEach(radio => {
            radio.addEventListener('change', function() {
                const type = this.id.startsWith('op') ? 'operation' : 'defect';
                const searchSection = this.closest('.operation-box').querySelector('.search-section');
                const searchInput = searchSection.querySelector('.search-input');
                
                if (this.checked) {
                    searchSection.style.display = 'block';
                    searchInput.focus();
                } else {
                    searchSection.style.display = 'none';
                    searchInput.value = '';
                    searchSection.querySelector('.search-results').innerHTML = '';
                }
            });
        });
    </script>
</body>
</html> 