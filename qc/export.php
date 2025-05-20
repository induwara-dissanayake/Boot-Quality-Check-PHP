<?php
require_once 'db.php';
requireLogin();

// Get filter parameters
$defect_type = $_GET['defect_type'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build query
$query = "SELECT * FROM qc_records WHERE 1=1";
$params = [];

if ($defect_type) {
    $query .= " AND defect_type = ?";
    $params[] = $defect_type;
}

if ($start_date) {
    $query .= " AND check_date >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $query .= " AND check_date <= ?";
    $params[] = $end_date;
}

$query .= " ORDER BY check_date DESC, id DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Create new Spreadsheet object
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator('Boots QC System')
    ->setLastModifiedBy('Boots QC System')
    ->setTitle('Quality Check Report')
    ->setSubject('Quality Check Records')
    ->setDescription('Quality Check Records Export');

// Set headers
$sheet->setCellValue('A1', 'ID');
$sheet->setCellValue('B1', 'Product ID');
$sheet->setCellValue('C1', 'Date');
$sheet->setCellValue('D1', 'Defect Type');
$sheet->setCellValue('E1', 'Description');

// Style the header row
$headerStyle = [
    'font' => [
        'bold' => true,
    ],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'CCCCCC',
        ],
    ],
];
$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

// Add data
$row = 2;
foreach ($records as $record) {
    $sheet->setCellValue('A' . $row, $record['id']);
    $sheet->setCellValue('B' . $row, $record['product_id']);
    $sheet->setCellValue('C' . $row, $record['check_date']);
    $sheet->setCellValue('D' . $row, $record['defect_type']);
    $sheet->setCellValue('E' . $row, $record['description']);
    $row++;
}

// Auto-size columns
foreach (range('A', 'E') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="qc_report.xlsx"');
header('Cache-Control: max-age=0');

// Save file to PHP output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit; 