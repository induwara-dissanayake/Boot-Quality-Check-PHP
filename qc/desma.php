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

// Get all defects from the database
$defects = $pdo->query("
    SELECT defects.id, defects.defect, COUNT(qr.id) as count 
    FROM defects 
    LEFT JOIN qc_desma_records qr ON defects.id = qr.defect_id 
    GROUP BY defects.id, defects.defect
    ORDER BY defects.defect
")->fetchAll();

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

            // Only require defect for Rework and Reject status
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
                ) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME())
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

            // Debug log
            error_log('DESMA QC Record SQL Parameters: ' . print_r($params, true));

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
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .action-buttons .btn {
            padding: 10px;
            font-weight: 500;
            border-radius: 8px;
            font-size: 0.95rem;
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
            padding: 10px;
            font-weight: 500;
            border-radius: 8px;
            font-size: 0.95rem;
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
                        <a class="nav-link" href="statistics.php">QC Statistics</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="desma.php">DESMA</a>
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
                    <div class="selection-row">
                        <input type="radio" name="defect" id="defOther" value="">
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

        // Handle "Other" radio button clicks
        document.querySelector('#defOther').addEventListener('change', function() {
            const searchSection = this.nextElementSibling;
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

        // Handle search input
        document.querySelector('.search-input').addEventListener('input', function() {
            const searchResults = this.nextElementSibling;
            const searchTerm = this.value.toLowerCase();
            
            if (searchTerm.length < 2) {
                searchResults.innerHTML = '';
                return;
            }
            
            const filteredItems = allDefects.filter(item => 
                item.defect.toLowerCase().includes(searchTerm)
            );
            
            searchResults.innerHTML = filteredItems.map(item => `
                <div class="search-result-item" data-id="${item.id}">
                    ${item.defect}
                </div>
            `).join('');
            
            // Add click handlers to search results
            searchResults.querySelectorAll('.search-result-item').forEach(result => {
                result.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const text = this.textContent.trim();
                    
                    // Update the "Other" radio button's label
                    const otherLabel = document.querySelector('#defOther').nextElementSibling;
                    otherLabel.textContent = text;
                    
                    // Set the value of the "Other" radio button
                    const otherRadio = otherLabel.previousElementSibling;
                    otherRadio.value = id;
                    
                    // Clear search
                    this.closest('.search-section').querySelector('.search-input').value = '';
                    searchResults.innerHTML = '';
                    
                    // Enable complete button if status is not Pass
                    if (currentStatus !== 'Pass') {
                        completeBtn.disabled = false;
                    }
                });
            });
        });

        // Handle image search
        document.getElementById('styleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const styleNo = document.getElementById('style_no').value;
            const poNo = document.getElementById('po_no').value;
            const imageContainer = document.getElementById('imageContainer');

            fetch('desma.php', {
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

        // Disable defect checkboxes by default
        document.querySelectorAll('.defect-check').forEach(checkbox => {
            checkbox.disabled = true;
        });

        // Optimize row selection
        document.querySelectorAll('.selection-row').forEach(row => {
            const radio = row.querySelector('input[type="radio"]');
            const label = row.querySelector('label');
            
            row.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (this.closest('.defect-box').classList.contains('disabled')) {
                    return;
                }
                
                const id = radio.value;
                
                this.closest('.defect-box').querySelectorAll('.selection-row').forEach(r => {
                    r.classList.remove('selected');
                });
                
                this.classList.add('selected');
                radio.checked = true;
                
                if (id === '') {
                    const searchSection = this.nextElementSibling;
                    if (searchSection.classList.contains('search-section')) {
                        searchSection.style.display = 'block';
                        searchSection.querySelector('.search-input').focus();
                    }
                } else {
                    const searchSection = this.nextElementSibling;
                    if (searchSection.classList.contains('search-section')) {
                        searchSection.style.display = 'none';
                    }
                }
                
                if (currentStatus !== 'Pass') {
                    const defectSelected = document.querySelector('#defectBox .selection-row.selected');
                    completeBtn.disabled = !defectSelected;
                }
            });
        });

        // Handle search inputs
        document.querySelectorAll('.search-input').forEach(input => {
            input.addEventListener('input', function() {
                const searchResults = this.nextElementSibling;
                const searchTerm = this.value.toLowerCase();
                
                if (searchTerm.length < 2) {
                    searchResults.innerHTML = '';
                    return;
                }
                
                const filteredItems = allDefects.filter(item => 
                    item.defect.toLowerCase().includes(searchTerm)
                );
                
                searchResults.innerHTML = filteredItems.map(item => `
                    <div class="search-result-item" data-id="${item.id}">
                        ${item.defect}
                    </div>
                `).join('');
                
                searchResults.querySelectorAll('.search-result-item').forEach(result => {
                    result.addEventListener('click', function() {
                        const id = this.dataset.id;
                        const text = this.textContent.trim();
                        
                        const otherLabel = this.closest('.defect-box').querySelector('#defOther').nextElementSibling;
                        otherLabel.textContent = text;
                        
                        const otherRadio = otherLabel.previousElementSibling;
                        otherRadio.value = id;
                        
                        this.closest('.search-section').querySelector('.search-input').value = '';
                        searchResults.innerHTML = '';
                        
                        if (currentStatus !== 'Pass') {
                            const defectSelected = document.querySelector('#defectBox .selection-row.selected');
                            completeBtn.disabled = !defectSelected;
                        }
                    });
                });
            });
        });

        // Update status button handler
        document.querySelectorAll('.action-buttons .btn').forEach(button => {
            button.addEventListener('click', function() {
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

                if (currentStatus === 'Pass') {
                    // For Pass status
                    document.querySelector('.defect-box').classList.add('disabled');
                    completeBtn.disabled = false;
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

                // Enable complete button if a defect is selected and status is not Pass
                if (currentStatus !== 'Pass') {
                    completeBtn.disabled = false;
                }
            });
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

        // Update the complete button click handler
        completeBtn.addEventListener('click', async function() {
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
            if (currentStatus !== 'Pass') {
                const selectedDefect = document.querySelector('.selection-row.selected');
                if (!selectedDefect) {
                    alert('Please select a defect for ' + currentStatus);
                    return;
                }
                defectId = selectedDefect.querySelector('input[type="radio"]').value;
            }

            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('style_no', styleNo);
            formData.append('po_no', poNo);
            formData.append('status', currentStatus);
            if (defectId) formData.append('defect_id', defectId);

            showLoading();

            try {
                const data = await handleSecurityCheck(formData);
                
                if (data.success) {
                    sessionStorage.setItem('lastStyleNo', data.style_no);
                    sessionStorage.setItem('lastPoNo', data.po_no);
                    
                    // Reset the form
                    document.querySelectorAll('.selection-row').forEach(row => {
                        row.classList.remove('selected');
                    });
                    document.querySelectorAll('input[type="radio"]').forEach(radio => {
                        radio.checked = false;
                    });
                    document.querySelectorAll('.action-buttons .btn').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    document.querySelector('.defect-box').classList.add('disabled');
                    completeBtn.disabled = true;
                    currentStatus = null;
                    
                    location.reload();
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

        // Restore values on page load
        document.addEventListener('DOMContentLoaded', function() {
            const lastStyleNo = sessionStorage.getItem('lastStyleNo');
            const lastPoNo = sessionStorage.getItem('lastPoNo');
            
            if (lastStyleNo && lastPoNo) {
                document.getElementById('style_no').value = lastStyleNo;
                document.getElementById('po_no').value = lastPoNo;
                
                const imageContainer = document.getElementById('imageContainer');
                fetch('desma.php', {
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
    </script>
</body>
</html> 