<?php
require_once 'db.php';

// Set timezone to Asia/Colombo
date_default_timezone_set('Asia/Colombo');

// Set MySQL timezone
$pdo->exec("SET time_zone = '+05:30'");

// Debug logging for timezone
error_log('PHP Timezone: ' . date_default_timezone_get());
error_log('Current date: ' . date('Y-m-d'));
error_log('Current time: ' . date('H:i:s'));

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user details
$stmt = $pdo->prepare("SELECT EFC_no, name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get all defects from the database
$po_no = $_GET['po_no'] ?? '';
$check_date = date('Y-m-d'); // Use current date instead of hardcoded date

// Debug log the query parameters
error_log('Defect count query - PO: ' . $po_no . ', Date: ' . $check_date);

$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.defect,
        COALESCE((
            SELECT COUNT(*) 
            FROM qc_desma_records qr 
            WHERE qr.defect_id = d.id 
            AND qr.po_no = ? 
            AND DATE(qr.check_date) = ?
        ), 0) as count
    FROM defects d
    ORDER BY d.defect
");

// Debug log the query execution
error_log('Executing defect count query with parameters: ' . print_r([$po_no, $check_date], true));

$stmt->execute([$po_no, $check_date]);
$defects = $stmt->fetchAll();

// Debug log the results
error_log('Defect count results: ' . print_r($defects, true));

// Get all defects for search
$allDefects = $pdo->query("SELECT id, defect FROM defects ORDER BY defect")->fetchAll();

// Get today's QC statistics
$today = date('Y-m-d');
$stats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'Pass' THEN 1 ELSE 0 END) as pass_count,
        SUM(CASE WHEN status = 'Rework' THEN 1 ELSE 0 END) as rework_count,
        SUM(CASE WHEN status = 'Reject' THEN 1 ELSE 0 END) as reject_count
    FROM qc_desma_records 
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
            // Debug log
            error_log('DESMA QC Record Request: ' . print_r($_POST, true));
            
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

            // Validate required fields
            if (empty($_POST['style_no']) || empty($_POST['po_no']) || empty($_POST['status'])) {
                throw new Exception('Missing required fields');
            }

            // Debug log for status and defect validation
            error_log('Status: ' . $_POST['status']);
            error_log('Defect ID: ' . (isset($_POST['defect_id']) ? $_POST['defect_id'] : 'not set'));

            // FIXED: Only require defect for 'Rework' and 'Reject', not for 'Pass' or 'Rework Pass'
            // Debug logging to see what's happening
            error_log('DEBUG - Status received: "' . $_POST['status'] . '"');
            error_log('DEBUG - Status === Rework: ' . ($_POST['status'] === 'Rework' ? 'true' : 'false'));
            error_log('DEBUG - Status === Reject: ' . ($_POST['status'] === 'Reject' ? 'true' : 'false'));
            error_log('DEBUG - Defect ID: ' . (isset($_POST['defect_id']) ? $_POST['defect_id'] : 'NOT SET'));

            // Only require defect for 'Rework' and 'Reject' status
            if (($_POST['status'] === 'Rework' || $_POST['status'] === 'Reject') && empty($_POST['defect_id'])) {
                throw new Exception('Defect is required for rework/reject');
            }

            // Validate session
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('User not logged in');
            }

            $stmt = $pdo->prepare("
                INSERT INTO qc_desma_records (
                    style_no, po_no, defect_id, 
                    user_id, qcc_id, status, quantity, check_date, check_time
                ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_DATE(), CURRENT_TIME())
            ");
            
            $params = [
                $_POST['style_no'],
                $_POST['po_no'],
                $_POST['defect_id'] ?? null,
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_POST['status'],
                $_POST['quantity'] ?? 1
            ];

            // Debug log the insert parameters
            error_log('Inserting QC record with parameters: ' . print_r($params, true));
            error_log('Current server date: ' . date('Y-m-d'));
            error_log('Current server time: ' . date('H:i:s'));

            $result = $stmt->execute($params);

            if (!$result) {
                throw new Exception('Failed to insert record: ' . implode(', ', $stmt->errorInfo()));
            }
            
            $response['success'] = true;
            $response['message'] = 'DESMA QC record saved successfully';
            $response['style_no'] = $_POST['style_no'];
            $response['po_no'] = $_POST['po_no'];
        } catch (Exception $e) {
            $response['message'] = 'Error saving DESMA QC record: ' . $e->getMessage();
            error_log('DESMA QC Record Error: ' . $e->getMessage());
        }
        
        // Debug log
        error_log('DESMA QC Record Response: ' . json_encode($response));
        
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
    <title>Boots QC - DESMA</title>
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
            border-top: 1px solid var(--border-color);
            padding: 15px;
        }

        .search-section .form-group {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 5px;
        }

        .search-results {
            position: absolute;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
        }

        .search-result-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item:hover {
            background-color: var(--light-bg);
        }

        .content-box {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 20px;
            margin-bottom: 30px;
        }

        .defect-box {
            background: white;
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            max-height: 600px;
            overflow-y: auto;
            position: relative;
        }

        .defect-box h6 {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.1rem;
            position: sticky;
            top: 0;
            background: white;
            z-index: 2;
            border-bottom: 1px solid var(--border-color);
            margin: 0;
            padding: 15px 15px 10px 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .defects-container {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 15px;
        }

        .defects-column {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .selection-row {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 0;
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
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
        }

        .image-container {
            width: 100%;
            height: 250px;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background-color: var(--light-bg);
            margin-bottom: 15px;
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
            margin-bottom: 12px;
        }

        .action-buttons .btn {
            padding: 12px;
            font-weight: 500;
            border-radius: 8px;
            font-size: 0.95rem;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-pass {
            background-color: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .btn-rework-pass {
            background-color: #27ae60;
            border-color: #27ae60;
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
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            color: white;
            padding: 12px;
            font-weight: 500;
            border-radius: 8px;
            font-size: 0.95rem;
            height: 45px;
        }

        .action-buttons .row {
            margin: 0;
        }

        .action-buttons .col-6 {
            padding: 0 4px;
        }

        .admin-link {
            text-align: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
        }

        .admin-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .admin-link a:hover {
            color: var(--secondary-color);
        }

        .defect-box.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .defect-box.disabled .selection-row {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .defect-box.disabled .selection-row:hover {
            background-color: #f8f9fa;
        }

        .defect-box.disabled .selection-row.selected {
            background-color: #e9ecef;
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .content-box {
                grid-template-columns: 1fr 280px;
                gap: 15px;
            }

            .defect-box {
                max-height: 500px;
            }

            .image-container {
                height: 220px;
            }
        }

        @media (max-width: 992px) {
            .content-box {
                grid-template-columns: 1fr 250px;
                gap: 15px;
            }

            .defect-box {
                max-height: 450px;
            }

            .defects-container {
                grid-template-columns: 1fr;
            }

            .image-container {
                height: 200px;
            }

            .action-buttons {
                margin-bottom: 10px;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }

            .search-section {
                padding: 12px;
            }

            .content-box {
                grid-template-columns: 1fr 220px;
                gap: 12px;
            }

            .defect-box {
                max-height: 400px;
            }

            .image-container {
                height: 180px;
            }

            .selection-row {
                padding: 8px 10px;
            }

            .action-buttons .btn {
                padding: 8px;
                font-size: 0.9rem;
            }

            .btn-complete {
                padding: 8px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .content-box {
                grid-template-columns: 1fr 200px;
                gap: 10px;
            }

            .defect-box h6 {
                padding: 12px 12px 8px 12px;
                font-size: 1rem;
            }

            .defects-container {
                padding: 12px;
                gap: 6px;
            }

            .defects-column {
                gap: 6px;
            }

            .selection-row {
                padding: 6px 8px;
                font-size: 0.9rem;
            }

            .image-container {
                height: 160px;
            }

            .image-section {
                padding: 12px;
            }

            .action-buttons {
                gap: 6px;
            }
        }

        /* Add these styles to your existing CSS */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .loading-spinner i {
            font-size: 2rem;
            color: var(--primary-color);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .suggestion-list {
            position: absolute;
            width: 100%;
            max-height: 150px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
        }

        .suggestion-item {
            padding: 8px 12px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .suggestion-item:hover {
            background-color: var(--light-bg);
        }

        .suggestion-item .badge {
            float: right;
            background-color: var(--primary-color);
        }

        .form-group {
            position: relative;
        }

        /* Add these styles */
        .other-defect-section {
            border-top: 1px solid var(--border-color);
            padding-top: 10px;
        }

        .other-search-box {
            padding: 10px;
            background: white;
        }

        .search-results {
            position: absolute;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
        }

        .search-result-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
        }

        .search-result-item:hover {
            background-color: var(--light-bg);
        }
    </style>
</head>
<body>
    <div class="loading-overlay">
        <div class="loading-spinner">
            <i class="fas fa-spinner"></i>
            <p>Processing request...</p>
        </div>
    </div>

    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">Boots QC System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="statistics.php">DESMA Statistics</a>
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
                            <div class="suggestion-list" id="styleSuggestions"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="po_no" class="form-label">PO Number</label>
                            <input type="text" class="form-control" id="po_no" name="po_no" required>
                            <div class="suggestion-list" id="poSuggestions"></div>
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
                <div class="defect-box">
                    <h6>Defect</h6>
                    <div class="defects-container">
                        <div class="defects-column">
                            <?php
                            $totalDefects = count($defects);
                            $halfCount = ceil($totalDefects / 2);
                            for ($i = 0; $i < $halfCount; $i++) {
                                $defect = $defects[$i];
                            ?>
                            <div class="selection-row" data-value="<?php echo $defect['id']; ?>">
                                <input type="radio" name="defect" value="<?php echo $defect['id']; ?>" class="me-2">
                                <span><?php echo $defect['defect']; ?></span>
                                <span class="badge bg-secondary ms-2"><?php echo $defect['count']; ?></span>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="defects-column">
                            <?php
                            for ($i = $halfCount; $i < $totalDefects; $i++) {
                                $defect = $defects[$i];
                            ?>
                            <div class="selection-row" data-value="<?php echo $defect['id']; ?>">
                                <input type="radio" name="defect" value="<?php echo $defect['id']; ?>" class="me-2">
                                <span><?php echo $defect['defect']; ?></span>
                                <span class="badge bg-secondary ms-2"><?php echo $defect['count']; ?></span>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="other-defect-section">
                        <div class="selection-row">
                            <input type="radio" name="defect" id="defOther" value="">
                            <label class="form-check-label" for="defOther">Other</label>
                        </div>
                        <div class="other-search-box" style="display: none;">
                            <input type="text" class="form-control" id="otherDefectSearch" placeholder="Search defect...">
                            <div id="otherDefectResults" class="search-results"></div>
                        </div>
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
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <button type="button" class="btn btn-pass w-100" data-status="Pass">Pass</button>
                            </div>
                            <div class="col-6">
                                <button type="button" class="btn btn-rework-pass w-100" data-status="Rework Pass">Rework Pass</button>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <button type="button" class="btn btn-rework w-100" data-status="Rework">Rework</button>
                            </div>
                            <div class="col-6">
                                <button type="button" class="btn btn-reject w-100" data-status="Reject">Reject</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-complete w-100 mt-2" id="completeBtn" disabled>Complete</button>
                    <div class="admin-link">
                        <a href="admin_login.php">
                            <i class="fas fa-user-shield"></i>
                            Admin Login
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStatus = null;
        const defectBox = document.querySelector('.defect-box');
        const completeBtn = document.getElementById('completeBtn');

        // Store all defects for search
        const allDefects = <?php echo json_encode($allDefects); ?>;
        const initialDefects = <?php echo json_encode($defects); ?>;

        // Add this to your existing JavaScript
        let recentEntries = JSON.parse(localStorage.getItem('recentEntries') || '[]');
        const maxRecentEntries = 5;

        function updateRecentEntries(styleNo, poNo) {
            const entry = { styleNo, poNo, timestamp: Date.now() };
            recentEntries = recentEntries.filter(e => !(e.styleNo === styleNo && e.poNo === poNo));
            recentEntries.unshift(entry);
            if (recentEntries.length > maxRecentEntries) {
                recentEntries.pop();
            }
            localStorage.setItem('recentEntries', JSON.stringify(recentEntries));
        }

        function showSuggestions(input, suggestions, type) {
            const value = input.value.toLowerCase();
            if (value.length < 2) {
                suggestions.style.display = 'none';
                return;
            }

            const filtered = recentEntries.filter(entry => 
                entry[type].toLowerCase().includes(value)
            );

            if (filtered.length > 0) {
                suggestions.innerHTML = filtered.map(entry => `
                    <div class="suggestion-item" data-style="${entry.styleNo}" data-po="${entry.poNo}">
                        ${entry[type]}
                        <span class="badge">${type === 'styleNo' ? entry.poNo : entry.styleNo}</span>
                    </div>
                `).join('');
                suggestions.style.display = 'block';

                // Add click handlers
                suggestions.querySelectorAll('.suggestion-item').forEach(item => {
                    item.addEventListener('click', () => {
                        document.getElementById('style_no').value = item.dataset.style;
                        document.getElementById('po_no').value = item.dataset.po;
                        suggestions.style.display = 'none';
                        // Trigger image load
                        loadImage(item.dataset.style, item.dataset.po);
                    });
                });
            } else {
                suggestions.style.display = 'none';
            }
        }

        // Add event listeners for suggestions
        document.getElementById('style_no').addEventListener('input', function() {
            showSuggestions(this, document.getElementById('styleSuggestions'), 'styleNo');
        });

        document.getElementById('po_no').addEventListener('input', function() {
            showSuggestions(this, document.getElementById('poSuggestions'), 'poNo');
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.form-group')) {
                document.getElementById('styleSuggestions').style.display = 'none';
                document.getElementById('poSuggestions').style.display = 'none';
            }
        });

        // Update the form submission handler to save recent entries
        document.getElementById('styleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const styleNo = document.getElementById('style_no').value;
            const poNo = document.getElementById('po_no').value;
            
            if (!/^\d{4}$/.test(styleNo)) {
                alert('Style number must be exactly 4 digits');
                return;
            }

            if (!/^\d{6}$/.test(poNo)) {
                alert('PO number must be exactly 6 digits');
                return;
            }

            // Save to recent entries
            updateRecentEntries(styleNo, poNo);

            // Rest of your existing form submission code...
        });

        // Handle form submission
        document.getElementById('styleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const styleNo = document.getElementById('style_no').value;
            const poNo = document.getElementById('po_no').value;
            
            if (!/^\d{4}$/.test(styleNo)) {
                alert('Style number must be exactly 4 digits');
                return;
            }

            if (!/^\d{6}$/.test(poNo)) {
                alert('PO number must be exactly 6 digits');
                return;
            }

            // Store values in session storage
            sessionStorage.setItem('lastStyleNo', styleNo);
            sessionStorage.setItem('lastPoNo', poNo);

            // Update the URL with the new PO number
            const url = new URL(window.location.href);
            url.searchParams.set('po_no', poNo);
            window.location.href = url.toString();
        });

        // Load image when style and PO are available
        async function loadImage(styleNo, poNo) {
            const imageContainer = document.getElementById('imageContainer');
            
            try {
                const response = await fetch('desma.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `style_no=${encodeURIComponent(styleNo)}&po_no=${encodeURIComponent(poNo)}`
                });
                
                const data = await response.json();
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
            } catch (error) {
                console.error('Error:', error);
                imageContainer.innerHTML = `
                    <div class="image-placeholder">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading image</p>
                    </div>
                `;
            }
        }

        // Restore values on page load
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const poNo = urlParams.get('po_no') || sessionStorage.getItem('lastPoNo');
            const styleNo = sessionStorage.getItem('lastStyleNo');
            
            if (poNo) {
                document.getElementById('po_no').value = poNo;
            }
            if (styleNo) {
                document.getElementById('style_no').value = styleNo;
            }

            // Load image if both style and PO are available
            if (styleNo && poNo) {
                loadImage(styleNo, poNo);
            }
        });

        // Update status button handler
        document.querySelectorAll('.action-buttons .btn').forEach(button => {
            button.addEventListener('click', async function() {
                currentStatus = this.dataset.status;
                
                // Remove active class from all buttons
                document.querySelectorAll('.action-buttons .btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');

                // Reset defect selection
                document.querySelectorAll('.selection-row').forEach(row => {
                    row.classList.remove('selected');
                });
                document.querySelectorAll('input[type="radio"]').forEach(radio => {
                    radio.checked = false;
                });

                // Handle instant submission for Pass and Rework Pass
                if (currentStatus === 'Pass' || currentStatus === 'Rework Pass') {
                    const styleNo = document.getElementById('style_no').value;
                    const poNo = document.getElementById('po_no').value;
                    
                    console.log('Submitting form with status:', currentStatus);
                    console.log('Style No:', styleNo);
                    console.log('PO No:', poNo);
                    
                    if (!/^\d{4}$/.test(styleNo)) {
                        alert('Style number must be exactly 4 digits');
                        return;
                    }

                    if (!/^\d{6}$/.test(poNo)) {
                        alert('PO number must be exactly 6 digits');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'save');
                    formData.append('style_no', styleNo);
                    formData.append('po_no', poNo);
                    formData.append('status', currentStatus);
                    formData.append('quantity', 1); // Add default quantity

                    console.log('Form data being sent:', Object.fromEntries(formData));

                    showLoading();

                    try {
                        const data = await handleSecurityCheck(formData);
                        console.log('Server response:', data);
                        
                        if (data.success) {
                            // Store values in session storage
                            sessionStorage.setItem('lastStyleNo', data.style_no);
                            sessionStorage.setItem('lastPoNo', data.po_no);
                            
                            // Update the URL with the current PO number
                            const url = new URL(window.location.href);
                            url.searchParams.set('po_no', data.po_no);
                            window.location.href = url.toString();
                        } else {
                            alert('Error: ' + (data.message || 'Unknown error occurred'));
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert(error.message || 'Error saving DESMA QC record. Please try again.');
                    } finally {
                        hideLoading();
                    }
                } else {
                    // For Rework/Reject status
                    document.querySelector('.defect-box').classList.remove('disabled');
                    completeBtn.disabled = true;
                }
            });
        });

        // Handle defect selection
        document.querySelectorAll('.selection-row').forEach(row => {
            row.addEventListener('click', function() {
                if (this.closest('.defect-box').classList.contains('disabled')) {
                    return;
                }

                const radio = this.querySelector('input[type="radio"]');
                const allRows = document.querySelectorAll('.selection-row');
                
                allRows.forEach(r => r.classList.remove('selected'));
                this.classList.add('selected');
                radio.checked = true;

                // Enable complete button if a defect is selected and status is Rework or Reject
                if (currentStatus === 'Rework' || currentStatus === 'Reject') {
                    completeBtn.disabled = false;
                }
            });
        });

        // Initially disable defect box
        document.querySelector('.defect-box').classList.add('disabled');

        // Update the complete button click handler to only handle Rework/Reject
        completeBtn.addEventListener('click', async function() {
            if (currentStatus !== 'Rework' && currentStatus !== 'Reject') {
                return;
            }

            const styleNo = document.getElementById('style_no').value;
            const poNo = document.getElementById('po_no').value;
            
            if (!/^\d{4}$/.test(styleNo)) {
                alert('Style number must be exactly 4 digits');
                return;
            }

            if (!/^\d{6}$/.test(poNo)) {
                alert('PO number must be exactly 6 digits');
                return;
            }

            let defectId = null;
            const selectedDefect = document.querySelector('.selection-row.selected');
            if (!selectedDefect) {
                alert('Please select a defect for ' + currentStatus);
                return;
            }
            defectId = selectedDefect.querySelector('input[type="radio"]').value;

            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('style_no', styleNo);
            formData.append('po_no', poNo);
            formData.append('status', currentStatus);
            formData.append('defect_id', defectId);
            formData.append('quantity', 1); // Add default quantity

            showLoading();

            try {
                const data = await handleSecurityCheck(formData);
                
                if (data.success) {
                    // Store values in session storage
                    sessionStorage.setItem('lastStyleNo', data.style_no);
                    sessionStorage.setItem('lastPoNo', data.po_no);
                    
                    // Update the URL with the current PO number
                    const url = new URL(window.location.href);
                    url.searchParams.set('po_no', data.po_no);
                    window.location.href = url.toString();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error occurred'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Error saving DESMA QC record. Please try again.');
            } finally {
                hideLoading();
            }
        });

        // Replace the existing "Other" search functionality with this new code
        document.querySelector('#defOther').addEventListener('change', function() {
            const searchBox = document.querySelector('.other-search-box');
            const searchInput = document.getElementById('otherDefectSearch');
            
            if (this.checked) {
                searchBox.style.display = 'block';
                setTimeout(() => {
                    searchInput.focus();
                }, 100);
            } else {
                searchBox.style.display = 'none';
                searchInput.value = '';
                document.getElementById('otherDefectResults').style.display = 'none';
            }
        });

        document.getElementById('otherDefectSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const resultsDiv = document.getElementById('otherDefectResults');
            
            if (searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            const filteredItems = allDefects.filter(item => 
                item.defect.toLowerCase().includes(searchTerm)
            );
            
            if (filteredItems.length === 0) {
                resultsDiv.innerHTML = '<div class="search-result-item">No results found</div>';
            } else {
                resultsDiv.innerHTML = filteredItems.map(item => `
                    <div class="search-result-item" data-id="${item.id}">
                        ${item.defect}
                    </div>
                `).join('');
            }
            
            resultsDiv.style.display = 'block';
            
            // Add click handlers to search results
            resultsDiv.querySelectorAll('.search-result-item').forEach(result => {
                result.addEventListener('click', function() {
                    if (this.textContent.trim() === 'No results found') return;
                    
                    const id = this.dataset.id;
                    const text = this.textContent.trim();
                    
                    // Update the "Other" radio button's label
                    const otherLabel = document.querySelector('#defOther').nextElementSibling;
                    otherLabel.textContent = text;
                    
                    // Set the value of the "Other" radio button
                    const otherRadio = otherLabel.previousElementSibling;
                    otherRadio.value = id;
                    
                    // Clear search
                    document.getElementById('otherDefectSearch').value = '';
                    resultsDiv.style.display = 'none';
                    
                    // Enable complete button if status is not Pass
                    if (currentStatus !== 'Pass') {
                        completeBtn.disabled = false;
                    }
                });
            });
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            const searchBox = document.querySelector('.other-search-box');
            const resultsDiv = document.getElementById('otherDefectResults');
            
            if (!searchBox.contains(e.target) && !e.target.closest('#defOther')) {
                resultsDiv.style.display = 'none';
            }
        });

        // Add input validation for style and PO numbers
        document.getElementById('style_no').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4);
        });

        document.getElementById('po_no').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
        });

        // Disable defect checkboxes by default
        document.querySelectorAll('.defect-check').forEach(checkbox => {
            checkbox.disabled = true;
        });

        // Add these utility functions at the top of your script
        const showLoading = () => {
            document.querySelector('.loading-overlay').style.display = 'flex';
        };

        const hideLoading = () => {
            document.querySelector('.loading-overlay').style.display = 'none';
        };

        const handleSecurityCheck = async (formData, retryCount = 0) => {
            if (retryCount >= 3) {
                throw new Error('Unable to complete the request. Please refresh the page and try again.');
            }

            try {
                const response = await fetch('desma.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                const text = await response.text();
                
                // Check if it's the security check page
                if (text.includes('infinityfree.net/errors/') || text.includes('aes.js')) {
                    console.log(`Security check detected, retry attempt ${retryCount + 1}`);
                    // Wait for 2 seconds before retrying
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    return handleSecurityCheck(formData, retryCount + 1);
                }

                // Try to parse as JSON
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server response:', text);
                    throw new Error('Invalid response from server. Please try again.');
                }
            } catch (error) {
                if (retryCount < 2) {
                    console.log(`Request failed, retry attempt ${retryCount + 1}`);
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    return handleSecurityCheck(formData, retryCount + 1);
                }
                throw error;
            }
        };
    </script>
</body>
</html> 