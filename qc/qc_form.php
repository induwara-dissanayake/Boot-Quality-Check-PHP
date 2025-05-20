<?php
require_once 'db.php';
requireLogin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'] ?? '';
    $check_date = $_POST['check_date'] ?? '';
    $defect_type = $_POST['defect_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $user_id = $_SESSION['user_id'] ?? null;
    $qcc_id = $_SESSION['qcc_id'] ?? null;

    if (empty($product_id) || empty($check_date) || empty($defect_type)) {
        $error = 'Please fill in all required fields';
    } else if (!$user_id || !$qcc_id) {
        $error = 'User session error. Please login again.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO qc_records (product_id, check_date, defect_type, description, user_id, qcc_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $check_date, $defect_type, $description, $user_id, $qcc_id]);
            $success = 'Quality check record added successfully!';
        } catch (PDOException $e) {
            $error = 'Error adding record: ' . $e->getMessage();
        }
    }
}

// Get user information
$stmt = $pdo->prepare("SELECT name, EFC_no FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - New Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                        <a class="nav-link active" href="qc_form.php">New QC Entry</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="view_data.php">View Records</a>
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
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">New Quality Check Entry</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <strong>Quality Checker:</strong> <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['EFC_no']); ?>)
                        </div>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="product_id" class="form-label">Product ID</label>
                                <input type="text" class="form-control" id="product_id" name="product_id" required>
                            </div>
                            <div class="mb-3">
                                <label for="check_date" class="form-label">Check Date</label>
                                <input type="date" class="form-control" id="check_date" name="check_date" required>
                            </div>
                            <div class="mb-3">
                                <label for="defect_type" class="form-label">Defect Type</label>
                                <select class="form-select" id="defect_type" name="defect_type" required>
                                    <option value="">Select Defect Type</option>
                                    <option value="None">None</option>
                                    <option value="Stitching">Stitching</option>
                                    <option value="Sole">Sole</option>
                                    <option value="Color">Color</option>
                                    <option value="Size">Size</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Submit</button>
                                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 