<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user details
$stmt = $pdo->prepare("SELECT EFC_no, name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get today's QC statistics with quantity
$today = $pdo->query('SELECT CURDATE()')->fetchColumn();
error_log('Debug - Using MySQL Date: ' . $today);

$stats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'Pass' THEN quantity ELSE 0 END) as pass_count,
        SUM(CASE WHEN status = 'Rework' THEN quantity ELSE 0 END) as rework_count,
        SUM(CASE WHEN status = 'Reject' THEN quantity ELSE 0 END) as reject_count,
        SUM(quantity) as total_count,
        COUNT(*) as total_records
    FROM qc_desma_records 
    WHERE qcc_id = ? AND check_date = ?
");

// Debug the query parameters
error_log('Debug - User ID: ' . $_SESSION['user_id']);
error_log('Debug - Today: ' . $today);

$stats->execute([$_SESSION['user_id'], $today]);
$daily_stats = $stats->fetch();

// Debug the results
error_log('Debug - Daily Stats Query: ' . $stats->queryString);
error_log('Debug - Daily Stats Results: ' . print_r($daily_stats, true));

// Let's also check if there are any records for today
$check_records = $pdo->prepare("
    SELECT id, status, quantity, check_date, created_at 
    FROM qc_desma_records 
    WHERE qcc_id = ? AND check_date = ?
    LIMIT 5
");
$check_records->execute([$_SESSION['user_id'], $today]);
$sample_records = $check_records->fetchAll(PDO::FETCH_ASSOC);
error_log('Debug - Sample Records for Today: ' . print_r($sample_records, true));

// Let's also check all records for this user to see what dates we have
$all_records = $pdo->prepare("
    SELECT id, status, quantity, check_date, created_at 
    FROM qc_desma_records 
    WHERE qcc_id = ?
    ORDER BY check_date DESC
    LIMIT 5
");
$all_records->execute([$_SESSION['user_id']]);
$recent_records = $all_records->fetchAll(PDO::FETCH_ASSOC);
error_log('Debug - Recent Records: ' . print_r($recent_records, true));

// Get weekly statistics
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$weekly_stats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'Pass' THEN quantity ELSE 0 END) as pass_count,
        SUM(CASE WHEN status = 'Rework' THEN quantity ELSE 0 END) as rework_count,
        SUM(CASE WHEN status = 'Reject' THEN quantity ELSE 0 END) as reject_count,
        SUM(quantity) as total_count
    FROM qc_desma_records 
    WHERE qcc_id = ? AND check_date BETWEEN ? AND ?
");
$weekly_stats->execute([$_SESSION['user_id'], $week_start, $week_end]);
$weekly_data = $weekly_stats->fetch();

// Debug log
error_log('Week Start: ' . $week_start);
error_log('Week End: ' . $week_end);
error_log('Weekly Stats: ' . print_r($weekly_data, true));

// Calculate percentages
function calculatePercentage($value, $total) {
    if ($total == 0) return 0;
    return round(($value / $total) * 100, 1);
}

$daily_percentages = [
    'pass' => calculatePercentage($daily_stats['pass_count'], $daily_stats['total_count']),
    'rework' => calculatePercentage($daily_stats['rework_count'], $daily_stats['total_count']),
    'reject' => calculatePercentage($daily_stats['reject_count'], $daily_stats['total_count'])
];

$weekly_percentages = [
    'pass' => calculatePercentage($weekly_data['pass_count'], $weekly_data['total_count']),
    'rework' => calculatePercentage($weekly_data['rework_count'], $weekly_data['total_count']),
    'reject' => calculatePercentage($weekly_data['reject_count'], $weekly_data['total_count'])
];

// Debug log
error_log('Daily Percentages: ' . print_r($daily_percentages, true));
error_log('Weekly Percentages: ' . print_r($weekly_percentages, true));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - DESMA Statistics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --border-color: #dee2e6;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f6fa;
        }

        .main-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .navbar {
            background-color: var(--primary-color) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 600;
            font-size: 1.4rem;
        }

        .stats-box {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .stats-box h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            background: var(--light-bg);
            transition: transform 0.2s;
        }

        .stat-item:hover {
            transform: translateY(-2px);
        }

        .stat-item.pass { background-color: rgba(46, 204, 113, 0.1); }
        .stat-item.rework { background-color: rgba(241, 196, 15, 0.1); }
        .stat-item.reject { background-color: rgba(231, 76, 60, 0.1); }

        .stat-item .count {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .stat-item .label {
            font-size: 0.9rem;
            color: #666;
        }

        .stat-item .percentage {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }

        .period-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 10px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">Boots QC System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="statistics.php">DESMA Statistics</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="desma.php">DESMA</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="fas fa-user me-1"></i>
                            <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['EFC_no']); ?>)
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="main-container">
        <div class="stats-box">
            <h6>Today's DESMA QC Statistics</h6>
            <div class="period-label"><?php echo date('F j, Y'); ?></div>
            <div class="stats-grid">
                <div class="stat-item pass">
                    <div class="count"><?php echo number_format($daily_stats['pass_count'] ?? 0); ?></div>
                    <div class="label">Pass</div>
                    <div class="percentage"><?php echo $daily_percentages['pass']; ?>%</div>
                </div>
                <div class="stat-item rework">
                    <div class="count"><?php echo number_format($daily_stats['rework_count'] ?? 0); ?></div>
                    <div class="label">Rework</div>
                    <div class="percentage"><?php echo $daily_percentages['rework']; ?>%</div>
                </div>
                <div class="stat-item reject">
                    <div class="count"><?php echo number_format($daily_stats['reject_count'] ?? 0); ?></div>
                    <div class="label">Reject</div>
                    <div class="percentage"><?php echo $daily_percentages['reject']; ?>%</div>
                </div>
            </div>
        </div>

        <div class="stats-box">
            <h6>This Week's DESMA QC Statistics</h6>
            <div class="period-label"><?php echo date('F j', strtotime($week_start)) . ' - ' . date('F j, Y', strtotime($week_end)); ?></div>
            <div class="stats-grid">
                <div class="stat-item pass">
                    <div class="count"><?php echo number_format($weekly_data['pass_count'] ?? 0); ?></div>
                    <div class="label">Pass</div>
                    <div class="percentage"><?php echo $weekly_percentages['pass']; ?>%</div>
                </div>
                <div class="stat-item rework">
                    <div class="count"><?php echo number_format($weekly_data['rework_count'] ?? 0); ?></div>
                    <div class="label">Rework</div>
                    <div class="percentage"><?php echo $weekly_percentages['rework']; ?>%</div>
                </div>
                <div class="stat-item reject">
                    <div class="count"><?php echo number_format($weekly_data['reject_count'] ?? 0); ?></div>
                    <div class="label">Reject</div>
                    <div class="percentage"><?php echo $weekly_percentages['reject']; ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 