<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once '../config/database.php';
$pdo = getDBConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_sales_chart') {
    $range = $_POST['range'] ?? 'day';

    try {
        switch ($range) {
            case 'day':
                // Hourly sales for today
                $stmt = $pdo->query("
                    SELECT HOUR(t.created_at) AS period, COALESCE(SUM(t.total_amount), 0) AS total
                    FROM transactions t
                    LEFT JOIN sales s ON s.transaction_id = t.id
                    WHERE (s.status IS NULL OR s.status != 'voided')
                      AND DATE(t.created_at) = CURDATE()
                    GROUP BY HOUR(t.created_at)
                    ORDER BY period
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data = array_fill(0, 24, 0);
                foreach ($rows as $r) {
                    $data[(int)$r['period']] = (float)$r['total'];
                }
                // Only show business hours (6AM - 10PM)
                $labels = [];
                $values = [];
                for ($h = 6; $h <= 22; $h++) {
                    $labels[] = ($h <= 12 ? $h : $h - 12) . ($h < 12 ? 'AM' : 'PM');
                    $values[] = $data[$h];
                }
                break;

            case 'week':
                // Daily sales for the last 7 days
                $stmt = $pdo->query("
                    SELECT DATE(t.created_at) AS period, COALESCE(SUM(t.total_amount), 0) AS total
                    FROM transactions t
                    LEFT JOIN sales s ON s.transaction_id = t.id
                    WHERE (s.status IS NULL OR s.status != 'voided')
                      AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                    GROUP BY DATE(t.created_at)
                    ORDER BY period
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $dateMap = [];
                foreach ($rows as $r) {
                    $dateMap[$r['period']] = (float)$r['total'];
                }
                $labels = [];
                $values = [];
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = date('D', strtotime($date)); // Mon, Tue, etc
                    $values[] = $dateMap[$date] ?? 0;
                }
                break;

            case 'month':
                // Weekly sales for the last 4 weeks
                $stmt = $pdo->query("
                    SELECT 
                        YEARWEEK(t.created_at, 1) AS period,
                        MIN(DATE(t.created_at)) AS week_start,
                        COALESCE(SUM(t.total_amount), 0) AS total
                    FROM transactions t
                    LEFT JOIN sales s ON s.transaction_id = t.id
                    WHERE (s.status IS NULL OR s.status != 'voided')
                      AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY YEARWEEK(t.created_at, 1)
                    ORDER BY period
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $labels = [];
                $values = [];
                foreach ($rows as $r) {
                    $labels[] = date('M d', strtotime($r['week_start']));
                    $values[] = (float)$r['total'];
                }
                if (empty($labels)) {
                    $labels = ['No data'];
                    $values = [0];
                }
                break;

            case 'year':
                // Monthly sales for the current year
                $stmt = $pdo->query("
                    SELECT MONTH(t.created_at) AS period, COALESCE(SUM(t.total_amount), 0) AS total
                    FROM transactions t
                    LEFT JOIN sales s ON s.transaction_id = t.id
                    WHERE (s.status IS NULL OR s.status != 'voided')
                      AND YEAR(t.created_at) = YEAR(NOW())
                    GROUP BY MONTH(t.created_at)
                    ORDER BY period
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                $monthMap = [];
                foreach ($rows as $r) {
                    $monthMap[(int)$r['period']] = (float)$r['total'];
                }
                $labels = [];
                $values = [];
                $currentMonth = (int)date('n');
                for ($m = 1; $m <= $currentMonth; $m++) {
                    $labels[] = $monthNames[$m];
                    $values[] = $monthMap[$m] ?? 0;
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid range']);
                exit;
        }

        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'values' => $values
        ]);
    } catch (PDOException $e) {
        error_log("Dashboard chart error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
