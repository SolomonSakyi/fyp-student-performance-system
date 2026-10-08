<?php
/**
 * billing.php
 *
 * Finance Billing Management
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

// Fixed path - go up 3 levels to project root
$basePath = dirname(__DIR__, 3) . '/';

require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/services/Finance/FinanceService.php';

$financeService = new FinanceService();
$db = DatabaseHelper::getInstance();

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Handle Generate Bills
if (isset($_POST['generate_bills'])) {
    $params = [
        'academic_year_id' => $_POST['academic_year_id'] ?? 0,
        'academic_term_id' => $_POST['academic_term_id'] ?? 0,
        'grade_level_id' => $_POST['grade_level_id'] ?? null,
        'student_id' => $_POST['student_id'] ?? null,
        'issued_by' => $_SESSION['user_id'] ?? 1
    ];
    
    $result = $financeService->generateBills($params);
    if ($result['success']) {
        $_SESSION['message'] = $result['message'];
    } else {
        $_SESSION['error'] = $result['message'];
    }
    header('Location: /admin/finance/billing.php');
    exit;
}

// Get data
$academicYears = $db->fetchAll("SELECT id, year_name FROM academic_years WHERE is_active = 1 ORDER BY id DESC");
$academicTerms = $db->fetchAll("SELECT id, term_name FROM academic_terms WHERE is_active = 1 ORDER BY term_number");
$gradeLevels = $db->fetchAll("SELECT id, level_name FROM grade_levels WHERE is_active = 1 ORDER BY promotion_order");

// Get bills
$bills = $db->fetchAll("
    SELECT sb.*, s.first_name, s.last_name, s.admission_number,
           ay.year_name, at.term_name
    FROM student_bills sb
    JOIN students s ON sb.student_id = s.id
    JOIN academic_years ay ON sb.academic_year_id = ay.id
    JOIN academic_terms at ON sb.academic_term_id = at.id
    WHERE sb.school_id = 1
    ORDER BY sb.created_at DESC
    LIMIT 100
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing - Finance</title>
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
        .btn-red { background: #dc2626; }
        .btn-red:hover { background: #b91c1c; }
        .btn-info { background: #17a2b8; }
        .btn-info:hover { background: #138496; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
        .btn-small { padding: 4px 10px; font-size: 12px; }
        .content-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f2f5;
        }
        .content-card h3 { color: #1a3c6e; font-size: 16px; }
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
        .badge.paid { background: #d4edda; color: #155724; }
        .badge.issued { background: #cce5ff; color: #004085; }
        .badge.overdue { background: #f8d7da; color: #721c24; }
        .badge.partial { background: #fff3cd; color: #856404; }
        .badge.arrears { background: #fef3c7; color: #92400e; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 4px; color: #333; font-size: 13px; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .btn-row { display: flex; gap: 10px; margin-top: 15px; }
        .btn-row .btn { flex: 1; text-align: center; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal.show { display: block; }
        .modal-content {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            max-width: 700px;
            width: 90%;
            margin: 30px auto;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-content h2 { color: #1a3c6e; margin-bottom: 15px; font-size: 20px; }
        .modal-content .close-modal { float: right; font-size: 24px; cursor: pointer; color: #999; }
        .modal-content .close-modal:hover { color: #333; }
        .no-data { text-align: center; color: #999; padding: 20px; }
        .amount { font-weight: bold; color: #1a3c6e; }
        .filter-box {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 15px;
        }
        .message {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1>📋 Billing Management</h1>
                <p style="color: #666; font-size: 13px;">Generate and manage student bills</p>
            </div>
            <button class="btn btn-info" onclick="openModal('generateBillsModal')">📋 Generate Bills</button>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Bills List -->
        <div class="content-card">
            <div class="card-header">
                <h3>📋 All Bills</h3>
                <span style="font-size:12px; color:#999;">Showing last 100 bills</span>
            </div>
            <?php if (!empty($bills)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Bill #</th>
                                <th>Student</th>
                                <th>Year/Term</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Due Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $bill): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($bill['bill_number']); ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bill['first_name'] . ' ' . $bill['last_name']); ?></strong>
                                        <br><small style="color:#999;"><?php echo htmlspecialchars($bill['admission_number']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill['year_name'] . ' - ' . $bill['term_name']); ?></td>
                                    <td>GHS <?php echo number_format($bill['total_amount'], 2); ?></td>
                                    <td>GHS <?php echo number_format($bill['amount_paid'], 2); ?></td>
                                    <td><span class="amount">GHS <?php echo number_format($bill['balance_due'], 2); ?></span></td>
                                    <td>
                                        <span class="badge <?php echo $bill['bill_status']; ?>"><?php echo ucfirst($bill['bill_status']); ?></span>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($bill['due_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No bills found. Click "Generate Bills" to create bills for students.</p>
            <?php endif; ?>
        </div>
        
        <!-- Generate Bills Modal -->
        <div class="modal" id="generateBillsModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('generateBillsModal')">&times;</span>
                <h2>📋 Generate Student Bills</h2>
                <form method="POST" action="">
                    <input type="hidden" name="generate_bills" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="academic_year_id">Academic Year *</label>
                            <select id="academic_year_id" name="academic_year_id" required>
                                <option value="">Select Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo $year['is_current'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="academic_term_id">Academic Term *</label>
                            <select id="academic_term_id" name="academic_term_id" required>
                                <option value="">Select Term</option>
                                <?php foreach ($academicTerms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>" <?php echo $term['is_current'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="grade_level_id">Grade Level (Optional)</label>
                            <select id="grade_level_id" name="grade_level_id">
                                <option value="">All Levels</option>
                                <?php foreach ($gradeLevels as $level): ?>
                                    <option value="<?php echo $level['id']; ?>"><?php echo htmlspecialchars($level['level_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="student_id">Specific Student (Optional)</label>
                            <select id="student_id" name="student_id">
                                <option value="">All Students</option>
                                <?php
                                $students = $db->fetchAll("SELECT id, first_name, last_name, admission_number FROM students WHERE is_active = 1 ORDER BY first_name LIMIT 100");
                                foreach ($students as $student):
                                ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-info">📋 Generate Bills</button>
                        <button type="button" class="btn" onclick="closeModal('generateBillsModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('show');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        window.onclick = function(event) {
            document.querySelectorAll('.modal').forEach(function(modal) {
                if (event.target == modal) {
                    modal.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>