<?php
require_once 'db.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['po_no'])) {
        throw new Exception('PO number is required');
    }

    $po_no = $_GET['po_no'];
    $check_date = date('Y-m-d'); // Use current date instead of hardcoded date

    // Direct count query
    $stmt = $pdo->prepare("
        SELECT 
            defect_id,
            COUNT(*) as count
        FROM qc_desma_records
        WHERE po_no = ?
        AND check_date = ?
        AND defect_id IS NOT NULL
        GROUP BY defect_id
    ");

    $stmt->execute([$po_no, $check_date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to simple key-value pairs
    $counts = [];
    foreach ($results as $row) {
        $counts[$row['defect_id']] = (int)$row['count'];
    }

    // Debug output
    error_log("PO: $po_no, Date: $check_date");
    error_log("Query results: " . json_encode($results));
    error_log("Final counts: " . json_encode($counts));

    echo json_encode($counts);
} catch (Exception $e) {
    error_log('Error in get_defect_counts.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 