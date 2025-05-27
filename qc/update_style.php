<?php
require_once 'db.php';

// Set proper headers for all responses
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-TOKEN');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Verify CSRF token
if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || $_SERVER['HTTP_X_CSRF_TOKEN'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

if (!isset($_POST['style_id']) || !isset($_POST['style_no']) || !isset($_POST['po_no'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$id = $_POST['style_id'];
$style_no = $_POST['style_no'];
$po_no = $_POST['po_no'];

// Validate style number (4 digits)
if (!preg_match('/^\d{4}$/', $style_no)) {
    echo json_encode(['success' => false, 'message' => 'Style number must be exactly 4 digits']);
    exit();
}

// Validate PO number (6 digits)
if (!preg_match('/^\d{6}$/', $po_no)) {
    echo json_encode(['success' => false, 'message' => 'PO number must be exactly 6 digits']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Check if style exists and get current values
    $stmt = $pdo->prepare("SELECT style_no, po_no, image_path FROM styles WHERE id = ?");
    $stmt->execute([$id]);
    $style = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$style) {
        throw new Exception('Style not found');
    }
    
    $old_style_no = $style['style_no'];
    $old_po_no = $style['po_no'];
    $image_path = $style['image_path'];
    
    // Handle image upload if new image is provided
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        
        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception('Invalid file type. Only JPG, JPEG, and PNG files are allowed.');
        }
        
        $new_filename = uniqid() . '.' . $file_extension;
        $new_image_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $new_image_path)) {
            // Delete old image
            if (file_exists($image_path)) {
                unlink($image_path);
            }
            $image_path = $new_image_path;
        } else {
            throw new Exception('Error uploading image');
        }
    }

    // Update the style record
    $stmt = $pdo->prepare("UPDATE styles SET style_no = ?, po_no = ?, image_path = ? WHERE id = ?");
    $result = $stmt->execute([$style_no, $po_no, $image_path, $id]);

    if (!$result) {
        throw new Exception('Failed to update style');
    }

    // Update related records
    $stmt = $pdo->prepare("
        UPDATE qc_desma_records 
        SET style_no = ?, po_no = ? 
        WHERE style_no = ? AND po_no = ?
    ");
    $stmt->execute([$style_no, $po_no, $old_style_no, $old_po_no]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Style updated successfully']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error updating style: ' . $e->getMessage()
    ]);
} 