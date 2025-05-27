<?php
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $style_no = $_POST['style_no'] ?? '';
    $po_no = $_POST['po_no'] ?? '';
    
    // Handle image upload
    $image = $_FILES['image'] ?? null;
    $image_path = '';
    
    if ($image && $image['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_filename = uniqid() . '.' . $file_extension;
            $image_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($image['tmp_name'], $image_path)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO styles (style_no, po_no, image_path) VALUES (?, ?, ?)");
                    $stmt->execute([$style_no, $po_no, $image_path]);
                    $success = 'Style added successfully!';
                } catch (PDOException $e) {
                    $error = 'Error adding style: ' . $e->getMessage();
                    // Delete uploaded file if database insert fails
                    if (file_exists($image_path)) {
                        unlink($image_path);
                    }
                }
            } else {
                $error = 'Error uploading image';
            }
        } else {
            $error = 'Invalid file type. Only JPG, JPEG, and PNG files are allowed.';
        }
    } else {
        $error = 'Please select an image';
    }
}

// Get all styles
$stmt = $pdo->query("SELECT * FROM styles ORDER BY created_at DESC");
$styles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <title>Boots QC - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            margin-bottom: 1.5rem;
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
        
        .table {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .img-thumbnail {
            border-radius: 8px;
            transition: transform 0.2s;
            max-width: 80px;
            height: auto;
        }
        
        .img-thumbnail:hover {
            transform: scale(1.1);
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            transition: color 0.2s;
        }
        
        .nav-link:hover, .nav-link.active {
            color: white !important;
        }
        
        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        /* Responsive styles */
        @media (max-width: 991.98px) {
            .container {
                padding: 0 15px;
            }
            
            .card {
                margin-bottom: 1rem;
            }
            
            .table-responsive {
                margin: 0 -15px;
            }
            
            .table td, .table th {
                padding: 0.5rem;
                font-size: 0.9rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
            }
            
            .img-thumbnail {
                max-width: 60px;
            }
            
            .modal-dialog {
                margin: 0.5rem;
            }
        }

        @media (max-width: 767.98px) {
            .navbar-brand {
                font-size: 1.1rem;
            }
            
            .nav-link {
                padding: 0.5rem 1rem;
            }
            
            .card-header h5 {
                font-size: 1.1rem;
            }
            
            .form-label {
                font-size: 0.9rem;
            }
            
            .form-text {
                font-size: 0.8rem;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .table td, .table th {
                white-space: nowrap;
            }
            
            .actions-cell {
                display: flex;
                gap: 0.5rem;
            }
            
            .actions-cell .btn {
                width: auto;
                margin-bottom: 0;
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
                        <a class="nav-link active" href="admin_operations.php">
                            <i class="fas fa-tasks me-1"></i>Operations & Defects
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
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
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add New Style</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" id="styleForm">
                            <div class="mb-3">
                                <label for="style_no" class="form-label">Style Number</label>
                                <input type="text" class="form-control" id="style_no" name="style_no" 
                                       pattern="[0-9]{4}" maxlength="4" required
                                       title="Style number must be exactly 4 digits">
                                <div class="form-text">Must be exactly 4 digits</div>
                            </div>
                            <div class="mb-3">
                                <label for="po_no" class="form-label">PO Number</label>
                                <input type="text" class="form-control" id="po_no" name="po_no" 
                                       pattern="[0-9]{6}" maxlength="6" required
                                       title="PO number must be exactly 6 digits">
                                <div class="form-text">Must be exactly 6 digits</div>
                            </div>
                            <div class="mb-3">
                                <label for="image" class="form-label">Style Image</label>
                                <input type="file" class="form-control" id="image" name="image" accept="image/*" required>
                                <div class="form-text">Allowed formats: JPG, JPEG, PNG</div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Add Style
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Styles & POs List</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="stylesTable">
                                <thead>
                                    <tr>
                                        <th>Style No</th>
                                        <th>PO No</th>
                                        <th>Image</th>
                                        <th>Added Date</th>
                                        <th class="actions-cell">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($styles as $style): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($style['style_no']); ?></td>
                                        <td><?php echo htmlspecialchars($style['po_no']); ?></td>
                                        <td>
                                            <img src="<?php echo htmlspecialchars($style['image_path']); ?>" 
                                                 alt="Style Image" 
                                                 class="img-thumbnail" 
                                                 loading="lazy">
                                        </td>
                                        <td><?php echo htmlspecialchars($style['created_at']); ?></td>
                                        <td class="actions-cell">
                                            <button class="btn btn-sm btn-primary" onclick="editStyle(<?php echo $style['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteStyle(<?php echo $style['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Style Modal -->
    <div class="modal fade" id="editStyleModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Style</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editStyleForm" enctype="multipart/form-data">
                        <input type="hidden" id="edit_style_id" name="style_id">
                        <div class="mb-3">
                            <label for="edit_style_no" class="form-label">Style Number</label>
                            <input type="text" class="form-control" id="edit_style_no" name="style_no" 
                                   pattern="[0-9]{4}" maxlength="4" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_po_no" class="form-label">PO Number</label>
                            <input type="text" class="form-control" id="edit_po_no" name="po_no" 
                                   pattern="[0-9]{6}" maxlength="6" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_image" class="form-label">Style Image</label>
                            <input type="file" class="form-control" id="edit_image" name="image" accept="image/*">
                            <div class="form-text">Leave empty to keep current image</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateStyle()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Add showAlert function
        function showAlert(message, type = 'success') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // Insert the alert at the top of the card
            const firstCard = document.querySelector('.card');
            firstCard.insertBefore(alertDiv, firstCard.firstChild);
            
            // Remove the alert after 5 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        $(document).ready(function() {
            // Add CSRF token to all AJAX requests
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            $('#stylesTable').DataTable({
                order: [[3, 'desc']],
                pageLength: 10,
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search styles..."
                }
            });

            // Form validation
            $('#styleForm, #editStyleForm').on('submit', function(e) {
                const styleNo = $(this).find('input[name="style_no"]').val();
                const poNo = $(this).find('input[name="po_no"]').val();
                
                if (!/^\d{4}$/.test(styleNo)) {
                    e.preventDefault();
                    alert('Style number must be exactly 4 digits');
                    return false;
                }
                
                if (!/^\d{6}$/.test(poNo)) {
                    e.preventDefault();
                    alert('PO number must be exactly 6 digits');
                    return false;
                }
            });
        });

        function editStyle(id) {
            // Fetch style data and populate modal
            $.ajax({
                url: 'get_style.php',
                method: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        alert(response.error);
                        return;
                    }
                    $('#edit_style_id').val(response.id);
                    $('#edit_style_no').val(response.style_no);
                    $('#edit_po_no').val(response.po_no);
                    $('#editStyleModal').modal('show');
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    alert('Error fetching style data. Please try again.');
                }
            });
        }

        function updateStyle() {
            const formData = new FormData($('#editStyleForm')[0]);
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            
            // Show loading state
            const submitBtn = $('.modal-footer .btn-primary');
            const originalContent = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Updating...');
            submitBtn.prop('disabled', true);
            
            $.ajax({
                url: 'update_style.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                success: function(response) {
                    if (response.success) {
                        // Close the modal
                        $('#editStyleModal').modal('hide');
                        
                        // Show success message
                        showAlert(response.message || 'Style updated successfully', 'success');
                        
                        // Reload the page to show updated data
                        location.reload();
                    } else {
                        showAlert(response.message || 'Error updating style', 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                    
                    showAlert('Error updating style. Please try again.', 'danger');
                },
                complete: function() {
                    // Reset button state
                    submitBtn.html(originalContent);
                    submitBtn.prop('disabled', false);
                }
            });
        }

        function deleteStyle(id) {
            if (!confirm('Are you sure you want to delete this style? This action cannot be undone.')) {
                return;
            }

            // Show loading state
            const deleteBtn = event.target.closest('button');
            const originalContent = deleteBtn.innerHTML;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            deleteBtn.disabled = true;

            fetch('delete_style.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: 'id=' + id
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Get the DataTable instance
                    const table = $('#stylesTable').DataTable();
                    
                    // Find and remove the row
                    const row = $(`tr[data-id="${id}"]`);
                    if (row.length) {
                        table.row(row).remove().draw();
                    } else {
                        // If row not found, reload the page
                        location.reload();
                    }
                    
                    showAlert('Style deleted successfully', 'success');
                } else {
                    throw new Error(data.message || 'Error deleting style');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert(error.message || 'Error deleting style. Please try again.', 'danger');
            })
            .finally(() => {
                // Reset button state
                deleteBtn.innerHTML = originalContent;
                deleteBtn.disabled = false;
            });
        }
    </script>
</body>
</html> 