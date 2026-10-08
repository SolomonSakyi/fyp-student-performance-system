<?php
/**
 * reports.php
 *
 * Finance Reports
 * 
 * @package EduTrack
 * @subpackage Admin\Finance
 * @version 1.0
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin';
}

$basePath = dirname(__DIR__, 3) . '/';

require_once $basePath . 'app/helpers/DatabaseHelper.php';

$db = DatabaseHelper::getInstance();

// Get statistics
$stats = [];
$result = $db->fetchOne("SELECT SUM(balance_due) as total FROM student_bills WHERE school_id = 1 AND is_active = 1 AND bill_status NOT IN ('paid', 'cancelled', 'void')");
$stats['outstanding'] = $result['total'] ?? 0;

$result = $db->fetchOne("SELECT SUM(amount) as total FROM payments WHERE school_id = 1 AND payment_status = 'completed' AND is_active = 1");
$stats['collected'] = $result['total'] ?? 0;

// Get payment method summary
$paymentMethods = $db->fetchAll("
    SELECT pm.method_name, COUNT(p.id) as count, SUM(p.amount) as total
    FROM payments p
    JOIN payment_methods pm ON p.payment_method_id = pm.id
    WHERE p.payment_status = 'completed' AND p.is_active = 1
    GROUP BY pm.method_name
    ORDER BY total DESC
");

// Get term summary
$termSummary = $db->fetchAll("
    SELECT ay.year_name, at.term_name,
           COUNT(DISTINCT sb.student_id) as students,
           SUM(sb.total_amount) as total_billed,
           SUM(sb.amount_paid) as total_paid,
           SUM(sb.balance_due) as outstanding
    FROM student_bills sb
    JOIN academic_years ay ON sb.academic_year_id = ay.id
    JOIN academic_terms at ON sb.academic_term_id = at.id
    WHERE sb.is_active = 1
    GROUP BY ay.year_name, at.term_name
    ORDER BY ay.id DESC, at.term_number DESC
    LIMIT 5
");

// Get outstanding by grade level
$outstandingByGrade = $db->fetchAll("
    SELECT gl.level_name,
           COUNT(DISTINCT s.id) as students,
           SUM(sb.balance_due) as total_outstanding
    FROM students s
    JOIN student_bills sb ON s.id = sb.student_id
    JOIN student_enrollments se ON s.id = se.student_id
    JOIN grade_levels gl ON se.grade_level_id = gl.id
    WHERE sb.school_id = 1 AND sb.is_active = 1
    AND sb.bill_status NOT IN ('paid', 'cancelled', 'void')
    AND sb.balance_due > 0
    GROUP BY gl.level_name
    ORDER BY total_outstanding DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Reports</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 0; }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 220px;
            height: 100%;
            background: #1a3c6e;
            color: #fff;
            padding: 20px 0;
            overflow-y: auto;
        }
        .sidebar .logo { text-align: center; padding: 10px 0 20px; border-bottom: 1px solid #2a4c8e; font-size: 18px; font-weight: bold; }
        .sidebar .logo span { color: #f59e0b; }
        .sidebar .nav-item {
            display: block;
            padding: 12px 25px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .sidebar .nav-item:hover, .sidebar .nav-item.active { background: #2a4c8e; color: #fff; }
        .sidebar .nav-item .icon { margin-right: 10px; }
        .main-content { margin-left: 220px; padding: 20px; }
        .header {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 { color: #1a3c6e; font-size: 22px; }
        .btn {
            display: inline-block;
            padding: 8px 18px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
        }
        .btn:hover { background: #2a4c8e; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .btn-info { background: #17a2b8; }
        .btn-info:hover { background: #138496; }
        .content-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card h3 {
            color: #1a3c6e;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f2f5;
            font-size: 16px;
        }
        .content-card h3 .badge-count {
            float: right;
            background: #e8eaf6;
            color: #1a237e;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: normal;
        }
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 8px 12px;
            text-align: left;
        }
        table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        table tr:hover { background: #f8fafc; }
        .badge {
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        .badge.blue { background: #cce5ff; color: #004085; }
        .badge.green { background: #d4edda; color: #155724; }
        .badge.orange { background: #fff3cd; color: #856404; }
        .badge.red { background: #f8d7da; color: #721c24; }
        .badge.purple { background: #f3e5f5; color: #6a1b9a; }
        .no-data { text-align: center; color: #999; padding: 20px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #1a3c6e;
        }
        .stat-card .number { font-size: 24px; font-weight: bold; color: #1a3c6e; }
        .stat-card .label { color: #666; font-size: 12px; margin-top: 3px; }
        .stat-card .number.green { color: #16a34a; }
        .stat-card .number.red { color: #dc2626; }
        .stat-card .number.orange { color: #f59e0b; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1>📄 Finance Reports</h1>
                <p style="color: #666; font-size: 13px;">View financial reports and analytics</p>
            </div>
            <div>
                <button class="btn btn-info" onclick="window.print()">🖨️ Print</button>
                <button class="btn btn-green" onclick="exportCSV()">📥 Export CSV</button>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number green">GHS <?php echo number_format($stats['collected'] ?? 0, 2); ?></div>
                <div class="label">💰 Total Collected</div>
            </div>
            <div class="stat-card">
                <div class="number red">GHS <?php echo number_format($stats['outstanding'] ?? 0, 2); ?></div>
                <div class="label">📤 Outstanding</div>
            </div>
        </div>
        
        <!-- Payment Methods -->
        <div class="content-card">
            <h3>💳 Payment Methods <span class="badge-count"><?php echo count($paymentMethods); ?> methods</span></h3>
            <?php if (!empty($paymentMethods)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                                <th>Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentMethods as $method): ?>
                                <tr>
                                    <td><span class="badge blue"><?php echo htmlspecialchars($method['method_name']); ?></span></td>
                                    <td><?php echo $method['count']; ?></td>
                                    <td><strong>GHS <?php echo number_format($method['total'], 2); ?></strong></td>
                                    <td>GHS <?php echo $method['count'] > 0 ? number_format($method['total'] / $method['count'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No payment data available</p>
            <?php endif; ?>
        </div>
        
        <!-- Term Summary -->
        <div class="content-card">
            <h3>📋 Term Summary <span class="badge-count"><?php echo count($termSummary); ?> terms</span></h3>
            <?php if (!empty($termSummary)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Term</th>
                                <th>Students</th>
                                <th>Billed</th>
                                <th>Paid</th>
                                <th>Outstanding</th>
                                <th>Collection %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($termSummary as $row): 
                                $collectionRate = $row['total_billed'] > 0 ? ($row['total_paid'] / $row['total_billed']) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['year_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['term_name']); ?></td>
                                    <td><?php echo $row['students']; ?></td>
                                    <td>GHS <?php echo number_format($row['total_billed'], 2); ?></td>
                                    <td>GHS <?php echo number_format($row['total_paid'], 2); ?></td>
                                    <td><span class="badge <?php echo $row['outstanding'] > 0 ? 'orange' : 'green'; ?>">GHS <?php echo number_format($row['outstanding'], 2); ?></span></td>
                                    <td><span class="badge <?php echo $collectionRate >= 80 ? 'green' : ($collectionRate >= 50 ? 'orange' : 'red'); ?>"><?php echo number_format($collectionRate, 1); ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No term data available</p>
            <?php endif; ?>
        </div>
        
        <!-- Outstanding by Grade -->
        <div class="content-card">
            <h3>📤 Outstanding by Grade Level</h3>
            <?php if (!empty($outstandingByGrade)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Grade Level</th>
                                <th>Students</th>
                                <th>Total Outstanding</th>
                                <th>Average per Student</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outstandingByGrade as $grade): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($grade['level_name']); ?></td>
                                    <td><?php echo $grade['students']; ?></td>
                                    <td><span class="badge red">GHS <?php echo number_format($grade['total_outstanding'], 2); ?></span></td>
                                    <td>GHS <?php echo $grade['students'] > 0 ? number_format($grade['total_outstanding'] / $grade['students'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No outstanding data available</p>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function exportCSV() {
            alert('CSV export feature coming soon!');
        }
    </script>
</body>
</html>