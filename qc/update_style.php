<?php
require_once 'db.php';

// Ensure proper JSON response headers
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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
    
    // Check if style exists
    $stmt = $pdo->prepare("SELECT image_path FROM styles WHERE id = ?");
    $stmt->execute([$id]);
    $style = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$style) {
        throw new Exception('Style not found');
    }
    
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
    
    // Update style in database
    $stmt = $pdo->prepare("UPDATE styles SET style_no = ?, po_no = ?, image_path = ? WHERE id = ?");
    $stmt->execute([$style_no, $po_no, $image_path, $id]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 