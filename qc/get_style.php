<?php
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Style ID is required']);
    exit();
}

$id = $_GET['id'];

try {
    $stmt = $pdo->prepare("SELECT id, style_no, po_no FROM styles WHERE id = ?");
    $stmt->execute([$id]);
    $style = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($style) {
        header('Content-Type: application/json');
        echo json_encode($style);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Style not found']);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error']);
} 