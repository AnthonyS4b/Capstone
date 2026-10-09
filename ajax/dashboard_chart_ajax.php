<?php
session_start();
require_once dirname(__DIR__) . '/includes/security.php';
security_require_role(['owner']);
security_require_post_csrf();
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
                // Monthly sales for one year: this year by default, or the year picked
                // in the dashboard's year dropdown. Past years show all 12 months.
                $thisYear = (int)date('Y');
                $year = filter_var($_POST['year'] ?? $thisYear, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => $thisYear]]) ?: $thisYear;

                // With a month picked in the month dropdown as well: that month's sales
                // day by day. The current month stops at today.
                $month = filter_var($_POST['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: null;
                if ($month !== null) {
                    $monthStart = sprintf('%04d-%02d-01', $year, $month);
                    $stmt = $pdo->prepare("
                        SELECT DAY(t.created_at) AS period, COALESCE(SUM(t.total_amount), 0) AS total
                        FROM transactions t
                        LEFT JOIN sales s ON s.transaction_id = t.id
                        WHERE (s.status IS NULL OR s.status != 'voided')
                          AND t.created_at >= :month_start AND t.created_at < :next_month_start
                        GROUP BY DAY(t.created_at)
                        ORDER BY period
                    ");
                    $stmt->execute([':month_start' => $monthStart, ':next_month_start' => date('Y-m-d', strtotime("$monthStart +1 month"))]);
                    $dayMap = [];
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $dayMap[(int)$r['period']] = (float)$r['total'];
                    }
                    $labels = [];
                    $values = [];
                    $isThisMonth = $year === $thisYear && $month === (int)date('n');
                    $lastDay = $isThisMonth ? (int)date('j') : (int)date('t', strtotime($monthStart));
                    for ($d = 1; $d <= $lastDay; $d++) {
                        $labels[] = (string)$d;
                        $values[] = $dayMap[$d] ?? 0;
                    }
                    break;
                }

                $stmt = $pdo->prepare("
                    SELECT MONTH(t.created_at) AS period, COALESCE(SUM(t.total_amount), 0) AS total
                    FROM transactions t
                    LEFT JOIN sales s ON s.transaction_id = t.id
                    WHERE (s.status IS NULL OR s.status != 'voided')
                      AND t.created_at >= :year_start AND t.created_at < :next_year_start
                    GROUP BY MONTH(t.created_at)
                    ORDER BY period
                ");
                $stmt->execute([':year_start' => "$year-01-01", ':next_year_start' => ($year + 1) . '-01-01']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                $monthMap = [];
                foreach ($rows as $r) {
                    $monthMap[(int)$r['period']] = (float)$r['total'];
                }
                $labels = [];
                $values = [];
                $lastMonth = $year === $thisYear ? (int)date('n') : 12;
                for ($m = 1; $m <= $lastMonth; $m++) {
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
} elseif ($action === 'get_stat_details') {
    // What is behind a dashboard stat card, for the window a click opens.
    // Each list applies the card's own rule (voided sales left out), so its
    // totals equal the number on the card. Everything is returned display-ready.
    $type = $_POST['type'] ?? '';
    $page = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 20;
    $notVoided = "NOT EXISTS (SELECT 1 FROM sales s WHERE s.transaction_id = t.id AND s.status = 'voided')";
    $peso = fn($n) => '₱' . number_format((float)$n, 2);

    try {
        if ($type === 'orders_today') {
            // Products ordered today, best seller (by sales) first
            $rows = $pdo->query("
                SELECT COALESCE(p.name, 'Deleted product') AS name, p.unit,
                       SUM(ti.quantity) AS qty,
                       COUNT(DISTINCT ti.transaction_id) AS orders,
                       SUM(ROUND(ti.quantity * ti.price, 2)) AS amount
                FROM transaction_items ti
                JOIN transactions t ON t.id = ti.transaction_id
                LEFT JOIN products p ON p.id = ti.product_id
                WHERE DATE(t.created_at) = CURDATE() AND $notVoided
                GROUP BY ti.product_id, p.name, p.unit
                ORDER BY amount DESC, name
            ")->fetchAll(PDO::FETCH_ASSOC);
            $orders = (int)$pdo->query("SELECT COUNT(*) FROM transactions t WHERE DATE(t.created_at) = CURDATE() AND $notVoided")->fetchColumn();

            echo json_encode([
                'success'  => true,
                'title'    => 'Orders Today',
                'subtitle' => 'Products ordered today, ' . date('F j, Y'),
                'summary'  => [
                    ['label' => 'Orders', 'value' => number_format($orders)],
                    ['label' => 'Different products', 'value' => number_format(count($rows))],
                    ['label' => 'Sales', 'value' => $peso(array_sum(array_column($rows, 'amount')))],
                ],
                'columns'  => [
                    ['label' => 'Product'],
                    ['label' => 'Quantity', 'align' => 'right'],
                    ['label' => 'In orders', 'align' => 'right'],
                    ['label' => 'Sales', 'align' => 'right'],
                ],
                'rows'     => array_map(fn($r) => [
                    $r['name'],
                    format_unit_quantity($r['qty'], $r['unit']),
                    number_format((int)$r['orders']),
                    $peso($r['amount']),
                ], $rows),
                'empty'    => 'No products have been ordered yet today.',
                'page'     => 1, 'total_pages' => 1, 'total_rows' => count($rows), 'per_page' => max(1, count($rows)),
                'link'     => null,
            ]);
            exit;
        }

        // The three transaction lists differ only in their time window and wording
        $lists = [
            'sales_today' => [
                'where' => 'DATE(t.created_at) = CURDATE()',
                'title' => "Today's Sales",
                'subtitle' => 'Sales transactions today, ' . date('F j, Y'),
                'date' => 'g:i A',
                'empty' => 'No sales yet today.',
            ],
            'transactions' => [
                'where' => '1 = 1',
                'title' => 'Total Transactions',
                'subtitle' => 'Every completed sale, newest first',
                'date' => 'M j, Y · g:i A',
                'empty' => 'No transactions yet.',
            ],
            'avg_order' => [
                'where' => 't.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                'title' => 'Avg. Order Value',
                'subtitle' => 'Sales in the last 30 days that make up the average',
                'date' => 'M j, Y · g:i A',
                'empty' => 'No sales in the last 30 days.',
            ],
        ];
        if (!isset($lists[$type])) {
            echo json_encode(['success' => false, 'message' => 'Unknown card']);
            exit;
        }
        $list = $lists[$type];

        $totals = $pdo->query("
            SELECT COUNT(*) AS n, COALESCE(SUM(t.total_amount), 0) AS total, COALESCE(AVG(t.total_amount), 0) AS average,
                   COALESCE(MIN(t.total_amount), 0) AS lowest, COALESCE(MAX(t.total_amount), 0) AS highest
            FROM transactions t
            WHERE {$list['where']} AND $notVoided
        ")->fetch(PDO::FETCH_ASSOC);
        $totalRows = (int)$totals['n'];
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = min($page, $totalPages);

        $stmt = $pdo->prepare("
            SELECT t.id, t.created_at, t.total_amount, t.payment_method,
                   (SELECT s.cashier_name FROM sales s WHERE s.transaction_id = t.id LIMIT 1) AS cashier,
                   (SELECT COUNT(*) FROM transaction_items ti WHERE ti.transaction_id = t.id) AS items
            FROM transactions t
            WHERE {$list['where']} AND $notVoided
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $summary = [
            ['label' => 'Transactions', 'value' => number_format($totalRows)],
            ['label' => 'Total sales', 'value' => $peso($totals['total'])],
        ];
        if ($type === 'avg_order') {
            $summary = [
                ['label' => 'Average order', 'value' => $peso($totals['average'])],
                ['label' => 'Transactions', 'value' => number_format($totalRows)],
                ['label' => 'Lowest', 'value' => $peso($totals['lowest'])],
                ['label' => 'Highest', 'value' => $peso($totals['highest'])],
            ];
        }

        echo json_encode([
            'success'  => true,
            'title'    => $list['title'],
            'subtitle' => $list['subtitle'],
            'summary'  => $summary,
            'columns'  => [
                ['label' => 'Receipt no.'],
                ['label' => $type === 'sales_today' ? 'Time' : 'Date'],
                ['label' => 'Cashier'],
                ['label' => 'Payment'],
                ['label' => 'Items', 'align' => 'right'],
                ['label' => 'Amount', 'align' => 'right'],
            ],
            'rows'     => array_map(fn($r) => [
                'TRX-' . str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT),
                date($list['date'], strtotime($r['created_at'])),
                $r['cashier'] ?: '—',
                strtolower((string)$r['payment_method']) === 'gcash' ? 'GCash' : 'Cash',
                number_format((int)$r['items']),
                $peso($r['total_amount']),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'empty'    => $list['empty'],
            'page'     => $page, 'total_pages' => $totalPages, 'total_rows' => $totalRows, 'per_page' => $perPage,
            'link'     => ['href' => 'Sales_Transactions.php', 'text' => 'Open Sales transactions'],
        ]);
    } catch (PDOException $e) {
        error_log('Dashboard stat details error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not load the details right now.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
