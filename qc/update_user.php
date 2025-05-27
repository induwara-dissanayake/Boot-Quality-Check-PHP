<?php
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Verify CSRF token
if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || $_SERVER['HTTP_X_CSRF_TOKEN'] !== $_SESSION['csrf_token']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

if (!isset($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

$name = trim($_POST['name'] ?? '');
$efc_no = trim($_POST['efc_no'] ?? '');

if (empty($name) || empty($efc_no)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

try {
    // Check if EFC number already exists for other users
    $stmt = $pdo->prepare("SELECT id FROM users WHERE EFC_no = ? AND id != ?");
    $stmt->execute([$efc_no, $_POST['user_id']]);
    if ($stmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'EFC number already exists']);
        exit();
    }

    // Update user
    $stmt = $pdo->prepare("UPDATE users SET name = ?, EFC_no = ? WHERE id = ?");
    $result = $stmt->execute([$name, $efc_no, $_POST['user_id']]);

    if ($result) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to update user']);
    }

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?> 