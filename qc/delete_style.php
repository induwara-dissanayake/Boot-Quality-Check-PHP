<?php
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_POST['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Style ID is required']);
    exit();
}

$id = $_POST['id'];

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get image path before deleting
    $stmt = $pdo->prepare("SELECT image_path FROM styles WHERE id = ?");
    $stmt->execute([$id]);
    $style = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$style) {
        throw new Exception('Style not found');
    }
    
    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM styles WHERE id = ?");
    $stmt->execute([$id]);
    
    // Delete image file
    if (file_exists($style['image_path'])) {
        unlink($style['image_path']);
    }
    
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 