<?php
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_operation'])) {
        $operation = trim($_POST['operation']);
        if (!empty($operation)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO operations (operation) VALUES (?)");
                $stmt->execute([$operation]);
                $success = "Operation added successfully!";
            } catch (PDOException $e) {
                $error = "Error adding operation. It might already exist.";
            }
        }
    } elseif (isset($_POST['add_defect'])) {
        $defect = trim($_POST['defect']);
        if (!empty($defect)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO defects (defect) VALUES (?)");
                $stmt->execute([$defect]);
                $success = "Defect added successfully!";
            } catch (PDOException $e) {
                $error = "Error adding defect. It might already exist.";
            }
        }
    }
}

// Get all operations
$stmt = $pdo->query("SELECT * FROM operations ORDER BY operation");
$operations = $stmt->fetchAll();

// Get all defects
$stmt = $pdo->query("SELECT * FROM defects ORDER BY defect");
$defects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - Manage Operations & Defects</title>
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
            height: 100%;
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

        .list-group-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border: none;
            margin-bottom: 8px;
            background-color: #fff;
            border-radius: 8px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }

        .list-group-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-box input {
            padding-left: 40px;
            border-radius: 20px;
            border: 1px solid #dee2e6;
            transition: all 0.3s;
        }

        .search-box input:focus {
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            border-color: var(--accent-color);
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .list-container {
            max-height: 500px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .list-container::-webkit-scrollbar {
            width: 6px;
        }

        .list-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .list-container::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 10px;
        }

        .list-container::-webkit-scrollbar-thumb:hover {
            background: #2980b9;
        }

        .badge {
            background: var(--accent-color);
            padding: 6px 10px;
            border-radius: 15px;
            font-weight: normal;
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
                        <a class="nav-link active" href="admin_operations.php">
                            <i class="fas fa-tasks me-1"></i>Operations & Defects
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
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

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Manage Operations</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-4">
                            <div class="input-group">
                                <input type="text" class="form-control" name="operation" 
                                       placeholder="Enter new operation" required>
                                <button type="submit" name="add_operation" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Add
                                </button>
                            </div>
                        </form>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" class="form-control" id="operationSearch" 
                                   placeholder="Search operations...">
                        </div>
                        <div class="list-container">
                            <div class="list-group" id="operationsList">
                                <?php foreach ($operations as $operation): ?>
                                    <div class="list-group-item">
                                        <span><?php echo htmlspecialchars($operation['operation']); ?></span>
                                        <span class="badge">ID: <?php echo $operation['id']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Manage Defects</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-4">
                            <div class="input-group">
                                <input type="text" class="form-control" name="defect" 
                                       placeholder="Enter new defect" required>
                                <button type="submit" name="add_defect" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Add
                                </button>
                            </div>
                        </form>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" class="form-control" id="defectSearch" 
                                   placeholder="Search defects...">
                        </div>
                        <div class="list-container">
                            <div class="list-group" id="defectsList">
                                <?php foreach ($defects as $defect): ?>
                                    <div class="list-group-item">
                                        <span><?php echo htmlspecialchars($defect['defect']); ?></span>
                                        <span class="badge">ID: <?php echo $defect['id']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Live search for operations
            $('#operationSearch').on('keyup', function() {
                var value = $(this).val().toLowerCase();
                $('#operationsList .list-group-item').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                });
            });

            // Live search for defects
            $('#defectSearch').on('keyup', function() {
                var value = $(this).val().toLowerCase();
                $('#defectsList .list-group-item').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                });
            });

            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000);
        });
    </script>
</body>
</html> 