<?php
/**
 * index.php
 *
 * Finance Module Dashboard - Complete Enterprise Implementation
 * 
 * @package EduTrack
 * @subpackage Admin\Finance
 * @version 2.0
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication - set default for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin';
    $_SESSION['full_name'] = 'System Administrator';
}

// ============================================================
// PATH CONFIGURATION
// ============================================================
$basePath = dirname(__DIR__, 3) . '/';
$appPath = $basePath . 'app/';

// Load required files
require_once $appPath . 'helpers/DatabaseHelper.php';
require_once $appPath . 'services/Finance/FinanceService.php';
require_once $appPath . 'helpers/LoggerHelper.php';

// ============================================================
// INITIALIZE
// ============================================================

$financeService = new FinanceService();
$db = DatabaseHelper::getInstance();

// Determine active tab
$activeTab = $_GET['tab'] ?? 'dashboard';
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Get default search mode from settings
$settings = $financeService->getSettings()['data'] ?? [];
$defaultSearchMode = $settings['default_search_mode'] ?? 'id';

// ============================================================
// HANDLE POST REQUESTS
// ============================================================

// Handle POST requests for Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $settings = [
            'invoice_prefix' => trim($_POST['invoice_prefix'] ?? 'INV-'),
            'receipt_prefix' => trim($_POST['receipt_prefix'] ?? 'REC-'),
            'default_currency' => trim($_POST['default_currency'] ?? 'GHS'),
            'late_fee_days' => (int)($_POST['late_fee_days'] ?? 30),
            'late_fee_percentage' => (float)($_POST['late_fee_percentage'] ?? 5),
            'reminder_days_before' => (int)($_POST['reminder_days_before'] ?? 7),
            'reminder_days_after' => (int)($_POST['reminder_days_after'] ?? 14),
            'max_installments' => (int)($_POST['max_installments'] ?? 3),
            'due_days' => (int)($_POST['due_days'] ?? 30),
            'default_search_mode' => $_POST['default_search_mode'] ?? 'id',
            'enable_auto_forward' => isset($_POST['enable_auto_forward']) ? 1 : 0,
            'enable_online_payments' => isset($_POST['enable_online_payments']) ? 1 : 0,
            'enable_sms_notifications' => isset($_POST['enable_sms_notifications']) ? 1 : 0,
            'enable_email_notifications' => isset($_POST['enable_email_notifications']) ? 1 : 0,
            'parent_portal_enabled' => isset($_POST['parent_portal_enabled']) ? 1 : 0,
            'student_portal_enabled' => isset($_POST['student_portal_enabled']) ? 1 : 0
        ];
        
        $result = $financeService->saveSettings($settings);
        if ($result['success']) {
            $_SESSION['message'] = 'Settings saved successfully!';
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: /admin/finance/index.php?tab=settings');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: /admin/finance/index.php?tab=settings');
        exit;
    }
}

// Get statistics
$stats = $financeService->getStatistics()['data'] ?? [];

// Get recent payments
$recentPayments = $financeService->getPayments()['data'] ?? [];

// Get all fee categories
$categories = $db->fetchAll("SELECT * FROM fee_categories WHERE school_id = 1 AND is_active = 1 ORDER BY category_name");

// Get all fee structures
$structures = $db->fetchAll("
    SELECT fs.*, gl.level_name, fc.category_name, ay.year_name, at.term_name
    FROM fee_structures fs
    JOIN grade_levels gl ON fs.grade_level_id = gl.id
    JOIN fee_categories fc ON fs.fee_category_id = fc.id
    JOIN academic_years ay ON fs.academic_year_id = ay.id
    JOIN academic_terms at ON fs.academic_term_id = at.id
    WHERE fs.school_id = 1 AND fs.is_active = 1
    ORDER BY gl.promotion_order, fc.category_name
");

// Get all bills
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

// Get all payments
$payments = $financeService->getPayments()['data'] ?? [];

// Get all discounts
$discounts = $db->fetchAll("SELECT * FROM fee_discounts WHERE school_id = 1 AND is_active = 1 ORDER BY discount_name");

// Get all payment methods
$paymentMethods = $db->fetchAll("SELECT * FROM payment_methods WHERE school_id = 1 AND is_active = 1 ORDER BY method_name");

// Get grade levels and academic years for forms
$gradeLevels = $db->fetchAll("SELECT id, level_name FROM grade_levels WHERE is_active = 1 ORDER BY promotion_order");
$academicYears = $db->fetchAll("SELECT id, year_name FROM academic_years WHERE is_active = 1 ORDER BY id DESC");
$academicTerms = $db->fetchAll("SELECT id, term_name FROM academic_terms WHERE is_active = 1 ORDER BY term_number");
$feeCategories = $db->fetchAll("SELECT id, category_name FROM fee_categories WHERE is_active = 1 ORDER BY category_name");
$students = $db->fetchAll("SELECT id, first_name, last_name, admission_number FROM students WHERE is_active = 1 ORDER BY first_name LIMIT 100");

// Handle POST requests for categories, structures, bills
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Category
    if (isset($_POST['add_category'])) {
        try {
            $data = [
                'category_name' => $_POST['category_name'],
                'category_code' => $_POST['category_code'] ?? null,
                'category_type' => $_POST['category_type'] ?? 'custom',
                'is_compulsory' => isset($_POST['is_compulsory']) ? 1 : 0
            ];
            $result = $financeService->createFeeCategory($data);
            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: /admin/finance/index.php?tab=categories');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /admin/finance/index.php?tab=categories');
            exit;
        }
    }
    
    // Add Structure
    if (isset($_POST['add_structure'])) {
        try {
            $data = [
                'grade_level_id' => (int)$_POST['grade_level_id'],
                'fee_category_id' => (int)$_POST['fee_category_id'],
                'academic_year_id' => (int)$_POST['academic_year_id'],
                'academic_term_id' => (int)$_POST['academic_term_id'],
                'amount' => (float)$_POST['amount']
            ];
            $result = $financeService->createFeeStructure($data);
            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: /admin/finance/index.php?tab=structures');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /admin/finance/index.php?tab=structures');
            exit;
        }
    }
    
    // Generate Bills
    if (isset($_POST['generate_bills'])) {
        try {
            $params = [
                'academic_year_id' => (int)$_POST['academic_year_id'],
                'academic_term_id' => (int)$_POST['academic_term_id'],
                'grade_level_id' => !empty($_POST['grade_level_id']) ? (int)$_POST['grade_level_id'] : null,
                'student_id' => !empty($_POST['student_id']) ? (int)$_POST['student_id'] : null,
                'issued_by' => $_SESSION['user_id'] ?? 1
            ];
            
            $result = $financeService->generateBills($params);
            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: /admin/finance/index.php?tab=bills');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /admin/finance/index.php?tab=bills');
            exit;
        }
    }
    
    // Record Payment
    if (isset($_POST['record_payment'])) {
        try {
            $data = [
                'student_id' => (int)$_POST['student_id'],
                'student_bill_id' => !empty($_POST['bill_id']) ? (int)$_POST['bill_id'] : null,
                'amount' => (float)$_POST['amount'],
                'payment_method_id' => (int)$_POST['payment_method_id'],
                'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
                'payment_reference' => $_POST['payment_reference'] ?? null,
                'notes' => $_POST['notes'] ?? null,
                'received_by' => $_SESSION['user_id'] ?? 1
            ];
            
            $result = $financeService->recordPayment($data);
            if ($result['success']) {
                $_SESSION['message'] = $result['message'] . ' - Receipt: ' . $result['receipt_number'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: /admin/finance/index.php?tab=payments');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /admin/finance/index.php?tab=payments');
            exit;
        }
    }
    
    // Forward Arrears
    if (isset($_POST['forward_arrears'])) {
        try {
            $fromTermId = (int)$_POST['from_term_id'];
            $toTermId = (int)$_POST['to_term_id'];
            
            if ($fromTermId <= 0 || $toTermId <= 0) {
                throw new Exception('Please select both source and destination terms');
            }
            
            if ($fromTermId == $toTermId) {
                throw new Exception('Source and destination terms must be different');
            }
            
            $result = $financeService->forwardArrears($fromTermId, $toTermId);
            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: /admin/finance/index.php?tab=arrears');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /admin/finance/index.php?tab=arrears');
            exit;
        }
    }
}

// ============================================================
// GET ARREARS STUDENTS
// ============================================================

$arrearsStudents = $db->fetchAll("
    SELECT s.id, s.first_name, s.last_name, s.admission_number,
           SUM(sb.balance_due) AS total_arrears,
           COUNT(sb.id) AS bill_count
    FROM students s
    JOIN student_bills sb ON s.id = sb.student_id
    WHERE sb.school_id = 1 AND sb.is_active = 1
    AND sb.bill_status NOT IN ('paid', 'cancelled', 'void')
    AND sb.balance_due > 0
    GROUP BY s.id
    HAVING total_arrears > 0
    ORDER BY total_arrears DESC
");

// Get officer info for display
$officerName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Module - EduTrack</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 0; }
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
            z-index: 100;
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
        .header h1 i { margin-right: 10px; color: #f59e0b; }
        .header p { color: #666; font-size: 13px; margin-top: 4px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            padding: 12px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #1a3c6e;
        }
        .stat-card .number { font-size: 20px; font-weight: bold; color: #1a3c6e; }
        .stat-card .label { color: #666; font-size: 11px; margin-top: 2px; }
        .stat-card .number.green { color: #16a34a; }
        .stat-card .number.red { color: #dc2626; }
        .stat-card .number.orange { color: #f59e0b; }
        .stat-card .number.blue { color: #2563eb; }
        .btn {
            display: inline-block;
            padding: 6px 14px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            transition: 0.3s;
        }
        .btn:hover { background: #2a4c8e; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .btn-red { background: #dc2626; }
        .btn-red:hover { background: #b91c1c; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
        .btn-info { background: #17a2b8; }
        .btn-info:hover { background: #138496; }
        .btn-sm { padding: 3px 10px; font-size: 11px; }
        .btn-xs { padding: 2px 8px; font-size: 10px; }
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f2f5;
        }
        .content-card h3 { color: #1a3c6e; font-size: 15px; }
        .content-card h3 i { margin-right: 6px; color: #f59e0b; }
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 6px 10px;
            text-align: left;
        }
        table td {
            padding: 6px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table tr:hover { background: #f8fafc; }
        .badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            display: inline-block;
        }
        .badge-paid { background: #d4edda; color: #155724; }
        .badge-issued { background: #cce5ff; color: #004085; }
        .badge-overdue { background: #f8d7da; color: #721c24; }
        .badge-partial { background: #fff3cd; color: #856404; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .badge-arrears { background: #fef3c7; color: #92400e; }
        .form-group { margin-bottom: 10px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 3px; color: #333; font-size: 12px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
        }
        .form-group textarea { min-height: 50px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .btn-row { display: flex; gap: 10px; margin-top: 12px; }
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
            padding: 20px;
            border-radius: 8px;
            max-width: 700px;
            width: 90%;
            margin: 30px auto;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-content h2 { color: #1a3c6e; margin-bottom: 12px; font-size: 18px; }
        .modal-content h2 i { margin-right: 8px; color: #f59e0b; }
        .modal-content .close-modal { float: right; font-size: 22px; cursor: pointer; color: #999; }
        .modal-content .close-modal:hover { color: #333; }
        .tabs {
            display: flex;
            gap: 4px;
            background: #fff;
            border-radius: 8px;
            padding: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tabs .tab {
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-size: 12px;
            font-weight: bold;
            transition: 0.3s;
        }
        .tabs .tab:hover { background: #f0f0f0; }
        .tabs .tab.active { background: #1a3c6e; color: #fff; }
        .tabs .tab i { margin-right: 5px; }
        .no-data { text-align: center; color: #999; padding: 20px; }
        .amount { font-weight: bold; color: #1a3c6e; }
        .message {
            padding: 8px 14px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .filter-box {
            background: #f8fafc;
            padding: 12px 15px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 12px;
        }
        .search-mode-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px;
            background: #f0f2f5;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        .search-mode-toggle label {
            font-size: 11px;
            font-weight: bold;
            color: #333;
            margin: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .search-mode-toggle input[type="radio"] {
            margin: 0;
            cursor: pointer;
        }
        .search-mode-toggle .mode-indicator {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
            background: #1a3c6e;
            color: #fff;
        }
        .search-result-item {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: 0.2s;
        }
        .search-result-item:hover {
            background: #f0f7ff;
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
        .search-result-item strong {
            color: #1a3c6e;
        }
        .search-result-item .badge-id {
            font-size: 10px;
            color: #666;
            background: #f0f2f5;
            padding: 1px 6px;
            border-radius: 10px;
        }
        #searchResults {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .selected-student-info {
            background: #f0f7ff;
            padding: 10px 14px;
            border-radius: 4px;
            margin-bottom: 12px;
            border: 1px solid #b8daff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .selected-student-info .remove-btn {
            background: #dc2626;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 2px 12px;
            cursor: pointer;
            font-size: 14px;
        }
        .selected-student-info .remove-btn:hover {
            background: #b91c1c;
        }
        .payment-summary {
            background: #e8f5e9;
            padding: 10px 14px;
            border-radius: 4px;
            margin-bottom: 12px;
            border: 1px solid #a5d6a7;
        }
        .officer-info {
            font-size: 11px;
            color: #666;
            padding: 4px 0;
        }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs .tab { padding: 5px 8px; font-size: 10px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-coins"></i> Finance Module</h1>
                <p>Complete financial management for your school</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-info" onclick="openModal('generateBillsModal')"><i class="fas fa-file-invoice"></i> Generate Bills</button>
                <button class="btn btn-green" onclick="openModal('recordPaymentModal')"><i class="fas fa-credit-card"></i> Record Payment</button>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="/admin/finance/index.php?tab=dashboard" class="tab <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="/admin/finance/index.php?tab=categories" class="tab <?php echo $activeTab === 'categories' ? 'active' : ''; ?>">
                <i class="fas fa-tags"></i> Categories
            </a>
            <a href="/admin/finance/index.php?tab=structures" class="tab <?php echo $activeTab === 'structures' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Structures
            </a>
            <a href="/admin/finance/index.php?tab=bills" class="tab <?php echo $activeTab === 'bills' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice"></i> Bills
            </a>
            <a href="/admin/finance/index.php?tab=payments" class="tab <?php echo $activeTab === 'payments' ? 'active' : ''; ?>">
                <i class="fas fa-credit-card"></i> Payments
            </a>
            <a href="/admin/finance/index.php?tab=discounts" class="tab <?php echo $activeTab === 'discounts' ? 'active' : ''; ?>">
                <i class="fas fa-percent"></i> Discounts
            </a>
            <a href="/admin/finance/index.php?tab=arrears" class="tab <?php echo $activeTab === 'arrears' ? 'active' : ''; ?>">
                <i class="fas fa-exclamation-triangle"></i> Arrears
            </a>
            <a href="/admin/finance/index.php?tab=settings" class="tab <?php echo $activeTab === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>

        <!-- ============================================================ -->
        <!-- DASHBOARD TAB -->
        <!-- ============================================================ -->
        <?php if ($activeTab === 'dashboard'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number blue"><?php echo number_format($stats['total_bills'] ?? 0); ?></div>
                <div class="label"><i class="fas fa-file-invoice"></i> Total Bills</div>
            </div>
            <div class="stat-card">
                <div class="number green">GHS <?php echo number_format($stats['collected'] ?? 0, 2); ?></div>
                <div class="label"><i class="fas fa-check-circle"></i> Collected</div>
            </div>
            <div class="stat-card">
                <div class="number red">GHS <?php echo number_format($stats['outstanding'] ?? 0, 2); ?></div>
                <div class="label"><i class="fas fa-exclamation-circle"></i> Outstanding</div>
            </div>
            <div class="stat-card">
                <div class="number orange"><?php echo number_format($stats['overdue_bills'] ?? 0); ?></div>
                <div class="label"><i class="fas fa-clock"></i> Overdue</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button class="btn btn-info" onclick="openModal('generateBillsModal')"><i class="fas fa-file-invoice"></i> Generate Bills</button>
                <button class="btn btn-green" onclick="openModal('recordPaymentModal')"><i class="fas fa-credit-card"></i> Record Payment</button>
                <button class="btn btn-warning" onclick="openModal('forwardArrearsModal')"><i class="fas fa-forward"></i> Forward Arrears</button>
                <a href="/admin/finance/index.php?tab=settings" class="btn"><i class="fas fa-cog"></i> Settings</a>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Payments</h3>
                <a href="/admin/finance/index.php?tab=payments" class="btn btn-sm btn-info">View All</a>
            </div>
            <?php if (!empty($recentPayments)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Receipt #</th><th>Student</th><th>Amount</th><th>Method</th><th>Officer</th><th>Date</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($recentPayments, 0, 10) as $payment): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($payment['receipt_number'] ?? '-'); ?></code></td>
                                    <td><?php echo htmlspecialchars(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')); ?></td>
                                    <td><strong>GHS <?php echo number_format($payment['amount'] ?? 0, 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($payment['method_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['received_by_name'] ?? 'System'); ?></td>
                                    <td><?php echo date('d M Y', strtotime($payment['payment_date'] ?? 'now')); ?></td>
                                    <td><span class="badge badge-<?php echo $payment['payment_status'] ?? 'pending'; ?>"><?php echo ucfirst($payment['payment_status'] ?? 'Pending'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No recent payments found.</p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- CATEGORIES TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'categories'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-tags"></i> Fee Categories</h3>
                <button class="btn btn-green btn-sm" onclick="openModal('categoryModal')"><i class="fas fa-plus"></i> Add Category</button>
            </div>
            <?php if (!empty($categories)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>#</th><th>Name</th><th>Code</th><th>Type</th><th>Compulsory</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $index => $cat): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                                    <td><code><?php echo htmlspecialchars($cat['category_code']); ?></code></td>
                                    <td><span class="badge badge-active"><?php echo ucfirst($cat['category_type']); ?></span></td>
                                    <td><?php echo $cat['is_compulsory'] ? '✅' : '❌'; ?></td>
                                    <td><span class="badge badge-<?php echo $cat['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No fee categories found. Click "Add Category" to create one.</p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- STRUCTURES TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'structures'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-layer-group"></i> Fee Structures</h3>
                <button class="btn btn-green btn-sm" onclick="openModal('structureModal')"><i class="fas fa-plus"></i> Add Structure</button>
            </div>
            <?php if (!empty($structures)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>#</th><th>Grade Level</th><th>Category</th><th>Year</th><th>Term</th><th>Amount</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($structures as $index => $struct): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($struct['level_name']); ?></td>
                                    <td><?php echo htmlspecialchars($struct['category_name']); ?></td>
                                    <td><?php echo htmlspecialchars($struct['year_name']); ?></td>
                                    <td><?php echo htmlspecialchars($struct['term_name']); ?></td>
                                    <td><span class="amount">GHS <?php echo number_format($struct['amount'], 2); ?></span></td>
                                    <td><span class="badge badge-<?php echo $struct['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $struct['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No fee structures found. Click "Add Structure" to create one.</p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- BILLS TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'bills'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-file-invoice"></i> Student Bills</h3>
                <button class="btn btn-info btn-sm" onclick="openModal('generateBillsModal')"><i class="fas fa-plus"></i> Generate Bills</button>
            </div>
            <?php if (!empty($bills)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Bill #</th><th>Student</th><th>Year/Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due Date</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $bill): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($bill['bill_number']); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($bill['first_name'] . ' ' . $bill['last_name']); ?></strong><br><small style="color:#999;"><?php echo htmlspecialchars($bill['admission_number']); ?></small></td>
                                    <td><?php echo htmlspecialchars($bill['year_name'] . ' - ' . $bill['term_name']); ?></td>
                                    <td>GHS <?php echo number_format($bill['total_amount'], 2); ?></td>
                                    <td>GHS <?php echo number_format($bill['amount_paid'], 2); ?></td>
                                    <td><span class="amount">GHS <?php echo number_format($bill['balance_due'], 2); ?></span></td>
                                    <td><span class="badge badge-<?php echo $bill['bill_status']; ?>"><?php echo ucfirst($bill['bill_status']); ?></span></td>
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

        <!-- ============================================================ -->
        <!-- PAYMENTS TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'payments'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-credit-card"></i> Payments</h3>
                <button class="btn btn-green btn-sm" onclick="openModal('recordPaymentModal')"><i class="fas fa-plus"></i> Record Payment</button>
            </div>
            <?php if (!empty($payments)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Receipt #</th><th>Student</th><th>Bill</th><th>Amount</th><th>Method</th><th>Officer</th><th>Date</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($payment['receipt_number'] ?? '-'); ?></code></td>
                                    <td><?php echo htmlspecialchars(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($payment['bill_number'] ?? 'N/A'); ?></td>
                                    <td><span class="amount">GHS <?php echo number_format($payment['amount'] ?? 0, 2); ?></span></td>
                                    <td><?php echo htmlspecialchars($payment['method_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['received_by_name'] ?? 'System'); ?></td>
                                    <td><?php echo date('d M Y', strtotime($payment['payment_date'] ?? 'now')); ?></td>
                                    <td><span class="badge badge-<?php echo $payment['payment_status'] ?? 'pending'; ?>"><?php echo ucfirst($payment['payment_status'] ?? 'Pending'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No payments recorded yet.</p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- DISCOUNTS TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'discounts'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-percent"></i> Discounts</h3>
                <button class="btn btn-green btn-sm" onclick="openModal('discountModal')"><i class="fas fa-plus"></i> Add Discount</button>
            </div>
            <?php if (!empty($discounts)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>#</th><th>Name</th><th>Code</th><th>Type</th><th>Value</th><th>Start</th><th>End</th><th>Uses</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($discounts as $index => $discount): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($discount['discount_name']); ?></strong></td>
                                    <td><code><?php echo htmlspecialchars($discount['discount_code']); ?></code></td>
                                    <td><span class="badge badge-active"><?php echo ucfirst($discount['discount_type']); ?></span></td>
                                    <td><?php echo $discount['discount_type'] === 'percentage' ? $discount['discount_value'] . '%' : 'GHS ' . number_format($discount['discount_value'], 2); ?></td>
                                    <td><?php echo date('d M Y', strtotime($discount['start_date'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($discount['end_date'])); ?></td>
                                    <td><?php echo $discount['uses_count'] . ($discount['max_uses'] ? '/' . $discount['max_uses'] : ''); ?></td>
                                    <td><span class="badge badge-<?php echo $discount['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $discount['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No discounts found.</p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- ARREARS TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'arrears'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Arrears Management</h3>
                <button class="btn btn-warning btn-sm" onclick="openModal('forwardArrearsModal')"><i class="fas fa-forward"></i> Forward Arrears</button>
            </div>
            <div class="filter-box">
                <p style="font-size:12px; color:#666;"><i class="fas fa-info-circle"></i> Students with unpaid bills from previous terms. Click "Forward Arrears" to move them to the next term.</p>
            </div>
            <?php if (!empty($arrearsStudents)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>#</th><th>Student</th><th>Admission</th><th>Total Arrears</th><th>Bill Count</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($arrearsStudents as $index => $student): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <td><span class="amount" style="color:#dc2626;">GHS <?php echo number_format($student['total_arrears'], 2); ?></span></td>
                                    <td><?php echo $student['bill_count']; ?></td>
                                    <td><span class="badge badge-arrears">In Arrears</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data"><i class="fas fa-check-circle" style="color:#16a34a;"></i> No students with arrears found.</p>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- SETTINGS TAB -->
        <!-- ============================================================ -->
        <?php elseif ($activeTab === 'settings'): ?>
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-cog"></i> Finance Settings</h3>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="save_settings" value="1">
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-file-invoice" style="color:#f59e0b;"></i> Billing Settings
                </h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="invoice_prefix">Invoice Prefix</label>
                        <input type="text" id="invoice_prefix" name="invoice_prefix" value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'INV-'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="receipt_prefix">Receipt Prefix</label>
                        <input type="text" id="receipt_prefix" name="receipt_prefix" value="<?php echo htmlspecialchars($settings['receipt_prefix'] ?? 'REC-'); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="default_currency">Default Currency</label>
                        <input type="text" id="default_currency" name="default_currency" value="<?php echo htmlspecialchars($settings['default_currency'] ?? 'GHS'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="max_installments">Maximum Installments</label>
                        <input type="number" id="max_installments" name="max_installments" value="<?php echo (int)($settings['max_installments'] ?? 3); ?>" min="1">
                    </div>
                </div>
                <div class="form-group">
                    <label for="due_days">Days Until Due</label>
                    <input type="number" id="due_days" name="due_days" value="<?php echo (int)($settings['due_days'] ?? 30); ?>" min="1">
                </div>
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-clock" style="color:#f59e0b;"></i> Late Fee & Reminder Settings
                </h4>
                <div class="form-row-3">
                    <div class="form-group">
                        <label for="late_fee_days">Late Fee Days</label>
                        <input type="number" id="late_fee_days" name="late_fee_days" value="<?php echo (int)($settings['late_fee_days'] ?? 30); ?>" min="1">
                    </div>
                    <div class="form-group">
                        <label for="late_fee_percentage">Late Fee Percentage</label>
                        <input type="number" id="late_fee_percentage" name="late_fee_percentage" value="<?php echo (float)($settings['late_fee_percentage'] ?? 5); ?>" step="0.5" min="0">
                    </div>
                    <div class="form-group">
                        <label for="reminder_days_before">Reminder Days Before</label>
                        <input type="number" id="reminder_days_before" name="reminder_days_before" value="<?php echo (int)($settings['reminder_days_before'] ?? 7); ?>" min="1">
                    </div>
                </div>
                <div class="form-group">
                    <label for="reminder_days_after">Reminder Days After Due</label>
                    <input type="number" id="reminder_days_after" name="reminder_days_after" value="<?php echo (int)($settings['reminder_days_after'] ?? 14); ?>" min="1">
                </div>
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-search" style="color:#f59e0b;"></i> Search Settings
                </h4>
                <div class="form-group">
                    <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:12px;">Default Search Mode</label>
                    <div style="display:flex; gap:20px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal;">
                            <input type="radio" name="default_search_mode" value="id" <?php echo ($settings['default_search_mode'] ?? 'id') === 'id' ? 'checked' : ''; ?>>
                            Search by ID/Admission Number (Recommended)
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal;">
                            <input type="radio" name="default_search_mode" value="name" <?php echo ($settings['default_search_mode'] ?? 'id') === 'name' ? 'checked' : ''; ?>>
                            Search by Name
                        </label>
                    </div>
                    <small style="color:#666; font-size:11px;">ID search is more reliable for large student populations with potential name duplicates.</small>
                </div>
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-forward" style="color:#f59e0b;"></i> Arrears Settings
                </h4>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="enable_auto_forward" value="1" <?php echo (($settings['enable_auto_forward'] ?? 0) ? 'checked' : ''); ?>>
                        Automatically forward arrears to next term
                    </label>
                </div>
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-credit-card" style="color:#f59e0b;"></i> Payment Settings
                </h4>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="enable_online_payments" value="1" <?php echo (($settings['enable_online_payments'] ?? 0) ? 'checked' : ''); ?>>
                        Enable Online Payments
                    </label>
                </div>
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-bell" style="color:#f59e0b;"></i> Notification Settings
                </h4>
                <div class="form-row">
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="enable_sms_notifications" value="1" <?php echo (($settings['enable_sms_notifications'] ?? 0) ? 'checked' : ''); ?>>
                            Enable SMS Notifications
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="enable_email_notifications" value="1" <?php echo (($settings['enable_email_notifications'] ?? 0) ? 'checked' : ''); ?>>
                            Enable Email Notifications
                        </label>
                    </div>
                </div>
                
                <h4 style="color:#1a3c6e; margin-top:15px; margin-bottom:10px; border-bottom:2px solid #f0f2f5; padding-bottom:5px;">
                    <i class="fas fa-users" style="color:#f59e0b;"></i> Portal Settings
                </h4>
                <div class="form-row">
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="parent_portal_enabled" value="1" <?php echo (($settings['parent_portal_enabled'] ?? 0) ? 'checked' : ''); ?>>
                            Enable Parent Portal
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="student_portal_enabled" value="1" <?php echo (($settings['student_portal_enabled'] ?? 0) ? 'checked' : ''); ?>>
                            Enable Student Portal
                        </label>
                    </div>
                </div>
                
                <div class="btn-row">
                    <button type="submit" class="btn btn-green"><i class="fas fa-save"></i> Save Settings</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- RECORD PAYMENT MODAL - ENHANCED ENTERPRISE VERSION -->
    <!-- ============================================================ -->
    <div class="modal" id="recordPaymentModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('recordPaymentModal')">&times;</span>
            <h2><i class="fas fa-credit-card"></i> Record Payment</h2>
            
            <div style="background:#f8fafc; padding:12px; border-radius:6px; margin-bottom:15px; border:1px solid #e2e8f0;">
                <p style="font-size:12px; color:#666;">
                    <i class="fas fa-info-circle" style="color:#1a3c6e;"></i> 
                    Search for a student by <strong><?php echo $defaultSearchMode === 'id' ? 'Admission Number or ID' : 'Name'; ?></strong>.
                    <?php if ($defaultSearchMode === 'id'): ?>
                    <span style="color:#1a3c6e; font-weight:bold;">(Recommended for large student populations)</span>
                    <?php endif; ?>
                </p>
                <div class="officer-info">
                    <i class="fas fa-user-tie"></i> Processing Officer: <strong><?php echo htmlspecialchars($officerName); ?></strong>
                    <span style="margin-left:15px;"><i class="fas fa-calendar-alt"></i> <?php echo date('d M Y H:i:s'); ?></span>
                </div>
            </div>
            
            <form method="POST" action="" id="paymentForm">
                <input type="hidden" name="record_payment" value="1">
                
                <!-- Search Mode Toggle -->
                <div class="search-mode-toggle">
                    <span style="font-size:11px; font-weight:bold; color:#333;">Search Mode:</span>
                    <label>
                        <input type="radio" name="search_mode" value="id" <?php echo $defaultSearchMode === 'id' ? 'checked' : ''; ?>>
                        <i class="fas fa-id-card"></i> ID/Admission
                    </label>
                    <label>
                        <input type="radio" name="search_mode" value="name" <?php echo $defaultSearchMode === 'name' ? 'checked' : ''; ?>>
                        <i class="fas fa-user"></i> Name
                    </label>
                    <span class="mode-indicator" id="modeIndicator"><?php echo strtoupper($defaultSearchMode); ?></span>
                </div>
                
                <!-- Student Search -->
                <div class="form-group" style="position:relative;">
                    <label for="student_search">
                        Search Student <span style="color:red;">*</span>
                        <small style="font-weight:normal; color:#666; font-size:10px;">
                            (<?php echo $defaultSearchMode === 'id' ? 'Enter Admission Number or Student ID' : 'Enter Student Name'; ?>)
                        </small>
                    </label>
                    <div style="position:relative;">
                        <input type="text" 
                               id="student_search" 
                               placeholder="<?php echo $defaultSearchMode === 'id' ? 'Type admission number or ID...' : 'Type student name...'; ?>" 
                               autocomplete="off"
                               style="padding-right:80px;">
                        <button type="button" 
                                id="searchStudentBtn" 
                                class="btn btn-info btn-sm" 
                                style="position:absolute; right:5px; top:5px; padding:4px 12px;">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                    <div id="searchResults" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-radius:4px; max-height:250px; overflow-y:auto; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                        <!-- Results will be populated here -->
                    </div>
                </div>
                
                <!-- Selected Student -->
                <div id="selectedStudentInfo" style="display:none;" class="selected-student-info">
                    <div>
                        <strong id="selectedStudentName"></strong>
                        <br>
                        <small style="color:#666;" id="selectedStudentDetails"></small>
                        <div id="selectedStudentBalance" style="font-size:12px; margin-top:3px;"></div>
                    </div>
                    <button type="button" class="remove-btn" onclick="clearSelectedStudent()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Hidden student ID -->
                <input type="hidden" id="pay_student" name="student_id" value="">
                
                <!-- Bill Selection -->
                <div class="form-group">
                    <label for="pay_bill">Select Bill</label>
                    <select id="pay_bill" name="bill_id">
                        <option value="">Select a student first</option>
                    </select>
                    <div id="billSummary" style="margin-top:5px; font-size:11px; color:#666;"></div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="pay_amount">Amount <span style="color:red;">*</span></label>
                        <input type="number" id="pay_amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label for="pay_method">Payment Method <span style="color:red;">*</span></label>
                        <select id="pay_method" name="payment_method_id" required>
                            <option value="">Select</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?php echo $method['id']; ?>"><?php echo htmlspecialchars($method['method_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="pay_date">Payment Date</label>
                        <input type="date" id="pay_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="pay_ref">Reference Number</label>
                        <input type="text" id="pay_ref" name="payment_reference" placeholder="Auto-generated" readonly style="background:#f5f5f5;">
                    </div>
                </div>
                <div class="form-group">
                    <label for="pay_notes">Notes</label>
                    <textarea id="pay_notes" name="notes" placeholder="Additional notes" rows="2"></textarea>
                </div>
                
                <!-- Payment Summary -->
                <div id="paymentSummary" style="display:none;" class="payment-summary">
                    <p style="font-size:13px; margin:0;">
                        <i class="fas fa-check-circle" style="color:#16a34a;"></i>
                        <strong>Payment Summary:</strong>
                        <span id="summaryText">Ready to record payment</span>
                    </p>
                    <p style="font-size:11px; color:#666; margin-top:3px;">
                        <i class="fas fa-user-tie"></i> Officer: <?php echo htmlspecialchars($officerName); ?>
                        <span style="margin-left:10px;"><i class="fas fa-clock"></i> <?php echo date('Y-m-d H:i:s'); ?></span>
                    </p>
                </div>
                
                <div class="btn-row">
                    <button type="submit" class="btn btn-green" id="submitPaymentBtn"><i class="fas fa-save"></i> Record Payment</button>
                    <button type="button" class="btn" onclick="closeModal('recordPaymentModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- OTHER MODALS (Categories, Structures, Generate Bills, Discounts, Forward Arrears) -->
    <!-- ============================================================ -->

    <!-- Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('categoryModal')">&times;</span>
            <h2><i class="fas fa-tag"></i> Add Fee Category</h2>
            <form method="POST" action="">
                <input type="hidden" name="add_category" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="cat_name">Category Name <span style="color:red;">*</span></label>
                        <input type="text" id="cat_name" name="category_name" placeholder="Tuition Fee" required>
                    </div>
                    <div class="form-group">
                        <label for="cat_code">Category Code</label>
                        <input type="text" id="cat_code" name="category_code" placeholder="TUITION">
                    </div>
                </div>
                <div class="form-group">
                    <label for="cat_type">Category Type</label>
                    <select id="cat_type" name="category_type">
                        <option value="tuition">Tuition</option>
                        <option value="levy">Levy</option>
                        <option value="sports">Sports</option>
                        <option value="medical">Medical</option>
                        <option value="canteen">Canteen</option>
                        <option value="transport">Transport</option>
                        <option value="pta">PTA</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_compulsory" value="1" checked>
                        This fee is compulsory
                    </label>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-green"><i class="fas fa-save"></i> Add Category</button>
                    <button type="button" class="btn" onclick="closeModal('categoryModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Structure Modal -->
    <div class="modal" id="structureModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('structureModal')">&times;</span>
            <h2><i class="fas fa-layer-group"></i> Add Fee Structure</h2>
            <form method="POST" action="">
                <input type="hidden" name="add_structure" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="struct_grade">Grade Level <span style="color:red;">*</span></label>
                        <select id="struct_grade" name="grade_level_id" required>
                            <option value="">Select</option>
                            <?php foreach ($gradeLevels as $level): ?>
                                <option value="<?php echo $level['id']; ?>"><?php echo htmlspecialchars($level['level_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="struct_category">Fee Category <span style="color:red;">*</span></label>
                        <select id="struct_category" name="fee_category_id" required>
                            <option value="">Select</option>
                            <?php foreach ($feeCategories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="struct_year">Academic Year <span style="color:red;">*</span></label>
                        <select id="struct_year" name="academic_year_id" required>
                            <option value="">Select</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="struct_term">Academic Term <span style="color:red;">*</span></label>
                        <select id="struct_term" name="academic_term_id" required>
                            <option value="">Select</option>
                            <?php foreach ($academicTerms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="struct_amount">Amount (GHS) <span style="color:red;">*</span></label>
                    <input type="number" id="struct_amount" name="amount" step="0.01" min="0" placeholder="100.00" required>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-green"><i class="fas fa-save"></i> Add Structure</button>
                    <button type="button" class="btn" onclick="closeModal('structureModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Generate Bills Modal -->
    <div class="modal" id="generateBillsModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('generateBillsModal')">&times;</span>
            <h2><i class="fas fa-file-invoice"></i> Generate Student Bills</h2>
            <form method="POST" action="">
                <input type="hidden" name="generate_bills" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="gen_year">Academic Year <span style="color:red;">*</span></label>
                        <select id="gen_year" name="academic_year_id" required>
                            <option value="">Select</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="gen_term">Academic Term <span style="color:red;">*</span></label>
                        <select id="gen_term" name="academic_term_id" required>
                            <option value="">Select</option>
                            <?php foreach ($academicTerms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="gen_grade">Grade Level (Optional)</label>
                        <select id="gen_grade" name="grade_level_id">
                            <option value="">All Levels</option>
                            <?php foreach ($gradeLevels as $level): ?>
                                <option value="<?php echo $level['id']; ?>"><?php echo htmlspecialchars($level['level_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="gen_student">Specific Student (Optional)</label>
                        <select id="gen_student" name="student_id">
                            <option value="">All Students</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-info"><i class="fas fa-file-invoice"></i> Generate Bills</button>
                    <button type="button" class="btn" onclick="closeModal('generateBillsModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Forward Arrears Modal -->
    <div class="modal" id="forwardArrearsModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('forwardArrearsModal')">&times;</span>
            <h2><i class="fas fa-forward"></i> Forward Arrears</h2>
            <p style="color:#666; margin-bottom:15px;">Forward all outstanding bills from the source term to the destination term.</p>
            <form method="POST" action="">
                <input type="hidden" name="forward_arrears" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="from_term">From Term <span style="color:red;">*</span></label>
                        <select id="from_term" name="from_term_id" required>
                            <option value="">Select</option>
                            <?php foreach ($academicTerms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="to_term">To Term <span style="color:red;">*</span></label>
                        <select id="to_term" name="to_term_id" required>
                            <option value="">Select</option>
                            <?php foreach ($academicTerms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-forward"></i> Forward Arrears</button>
                    <button type="button" class="btn" onclick="closeModal('forwardArrearsModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Discount Modal -->
    <div class="modal" id="discountModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('discountModal')">&times;</span>
            <h2><i class="fas fa-percent"></i> Add Discount</h2>
            <form method="POST" action="">
                <input type="hidden" name="add_discount" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="disc_name">Discount Name <span style="color:red;">*</span></label>
                        <input type="text" id="disc_name" name="discount_name" placeholder="Early Bird Discount" required>
                    </div>
                    <div class="form-group">
                        <label for="disc_code">Discount Code</label>
                        <input type="text" id="disc_code" name="discount_code" placeholder="EARLY10">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="disc_type">Discount Type <span style="color:red;">*</span></label>
                        <select id="disc_type" name="discount_type" required>
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="disc_value">Discount Value <span style="color:red;">*</span></label>
                        <input type="number" id="disc_value" name="discount_value" step="0.01" min="0" placeholder="10" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="disc_start">Start Date <span style="color:red;">*</span></label>
                        <input type="date" id="disc_start" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="disc_end">End Date <span style="color:red;">*</span></label>
                        <input type="date" id="disc_end" name="end_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="disc_max_uses">Max Uses (Optional)</label>
                    <input type="number" id="disc_max_uses" name="max_uses" placeholder="Leave empty for unlimited" min="1">
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-green"><i class="fas fa-save"></i> Add Discount</button>
                    <button type="button" class="btn" onclick="closeModal('discountModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- JAVASCRIPT - ENTERPRISE PAYMENT MODULE -->
    <!-- ============================================================ -->
    <script>
    // ============================================================
    // MODAL FUNCTIONS
    // ============================================================
    function openModal(id) {
        document.getElementById(id).classList.add('show');
        document.body.style.overflow = 'hidden';
        if (id === 'recordPaymentModal') {
            generateReferenceNumber();
        }
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
        document.body.style.overflow = '';
        if (id === 'recordPaymentModal') {
            resetPaymentForm();
        }
    }
    
    window.onclick = function(event) {
        document.querySelectorAll('.modal').forEach(function(modal) {
            if (event.target == modal) {
                modal.classList.remove('show');
                document.body.style.overflow = '';
                if (modal.id === 'recordPaymentModal') {
                    resetPaymentForm();
                }
            }
        });
    };

    // ============================================================
    // GENERATE REFERENCE NUMBER
    // ============================================================
    function generateReferenceNumber() {
        var prefix = '<?php echo $settings['receipt_prefix'] ?? 'REC-'; ?>';
        var date = new Date();
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        var random = String(Math.floor(Math.random() * 10000)).padStart(4, '0');
        var ref = prefix + year + month + day + '-' + random;
        document.getElementById('pay_ref').value = ref;
    }

    // ============================================================
    // PAYMENT FORM - RESET
    // ============================================================
    function resetPaymentForm() {
        document.getElementById('student_search').value = '';
        document.getElementById('searchResults').style.display = 'none';
        document.getElementById('selectedStudentInfo').style.display = 'none';
        document.getElementById('pay_student').value = '';
        document.getElementById('pay_bill').innerHTML = '<option value="">Select a student first</option>';
        document.getElementById('pay_amount').value = '';
        document.getElementById('pay_amount').max = '';
        document.getElementById('billSummary').innerHTML = '';
        document.getElementById('paymentSummary').style.display = 'none';
        document.getElementById('pay_ref').value = '';
        generateReferenceNumber();
    }

    // ============================================================
    // CLEAR SELECTED STUDENT
    // ============================================================
    function clearSelectedStudent() {
        document.getElementById('selectedStudentInfo').style.display = 'none';
        document.getElementById('pay_student').value = '';
        document.getElementById('student_search').value = '';
        document.getElementById('pay_bill').innerHTML = '<option value="">Select a student first</option>';
        document.getElementById('pay_amount').value = '';
        document.getElementById('pay_amount').max = '';
        document.getElementById('billSummary').innerHTML = '';
        document.getElementById('paymentSummary').style.display = 'none';
        document.getElementById('searchResults').style.display = 'none';
    }

    // ============================================================
    // STUDENT SEARCH - ENTERPRISE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('student_search');
        var searchBtn = document.getElementById('searchStudentBtn');
        var resultsDiv = document.getElementById('searchResults');
        var payStudent = document.getElementById('pay_student');
        var selectedInfo = document.getElementById('selectedStudentInfo');
        var selectedName = document.getElementById('selectedStudentName');
        var selectedDetails = document.getElementById('selectedStudentDetails');
        var selectedBalance = document.getElementById('selectedStudentBalance');
        var billSelect = document.getElementById('pay_bill');
        var amountInput = document.getElementById('pay_amount');
        var billSummary = document.getElementById('billSummary');
        var paymentSummary = document.getElementById('paymentSummary');
        var summaryText = document.getElementById('summaryText');
        var submitBtn = document.getElementById('submitPaymentBtn');
        var modeIndicator = document.getElementById('modeIndicator');

        // Get search mode from radio buttons
        function getSearchMode() {
            var radios = document.querySelectorAll('input[name="search_mode"]');
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    return radios[i].value;
                }
            }
            return 'id';
        }

        // Update placeholder based on search mode
        function updatePlaceholder() {
            var mode = getSearchMode();
            var input = document.getElementById('student_search');
            var label = document.querySelector('label[for="student_search"] small');
            if (mode === 'id') {
                input.placeholder = 'Type admission number or student ID...';
                if (label) label.textContent = '(Enter Admission Number or Student ID)';
                modeIndicator.textContent = 'ID';
                modeIndicator.style.background = '#1a3c6e';
            } else {
                input.placeholder = 'Type student name...';
                if (label) label.textContent = '(Enter Student Name)';
                modeIndicator.textContent = 'NAME';
                modeIndicator.style.background = '#16a34a';
            }
        }

        // Search function
        function performSearch() {
            var query = searchInput.value.trim();
            var mode = getSearchMode();
            
            if (query.length < 1) {
                resultsDiv.style.display = 'none';
                return;
            }

            resultsDiv.innerHTML = '<div style="padding:10px; color:#999;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            resultsDiv.style.display = 'block';

            fetch('/api/finance/index.php?action=search-students&query=' + encodeURIComponent(query) + '&search_mode=' + mode)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.success && data.data && data.data.length > 0) {
                        var html = '';
                        var modeLabel = mode === 'id' ? 'ID' : 'Name';
                        html += '<div style="padding:4px 12px; background:#f0f2f5; font-size:10px; color:#666; border-bottom:1px solid #ddd;">';
                        html += 'Showing ' + data.data.length + ' results (Search: ' + modeLabel + ')</div>';
                        
                        data.data.forEach(function(student) {
                            var balance = parseFloat(student.total_balance || 0);
                            var badge = balance > 0 ? '<span style="color:#dc2626; font-size:10px;"> (Arrears: GHS ' + balance.toFixed(2) + ')</span>' : '';
                            var outstanding = student.outstanding_bills_count > 0 ? '<span class="badge badge-warning" style="font-size:9px;">' + student.outstanding_bills_count + ' bills</span>' : '<span style="color:#16a34a; font-size:9px;">✅ No bills</span>';
                            
                            html += '<div class="search-result-item" data-id="' + student.id + '" data-name="' + student.first_name + ' ' + student.last_name + '" data-details="' + student.admission_number + ' | ' + (student.level_name || 'No Level') + '" data-balance="' + balance + '" data-bills="' + student.outstanding_bills_count + '">';
                            html += '<strong>' + student.first_name + ' ' + student.last_name + '</strong>';
                            html += ' <span class="badge-id">' + student.admission_number + '</span>';
                            html += '<br><small style="color:#666;">' + (student.level_name || 'No Level');
                            if (student.student_number) {
                                html += ' | Student #: ' + student.student_number;
                            }
                            html += ' | ' + outstanding + badge + '</small>';
                            html += '</div>';
                        });
                        resultsDiv.innerHTML = html;

                        // Add click handlers to results
                        resultsDiv.querySelectorAll('.search-result-item').forEach(function(item) {
                            item.addEventListener('click', function() {
                                var studentId = this.dataset.id;
                                var name = this.dataset.name;
                                var details = this.dataset.details;
                                var balance = parseFloat(this.dataset.balance || 0);
                                var bills = parseInt(this.dataset.bills || 0);
                                selectStudent(studentId, name, details, balance, bills);
                            });
                        });
                    } else {
                        resultsDiv.innerHTML = '<div style="padding:10px; color:#999;">No students found. Try a different search.</div>';
                    }
                })
                .catch(function(error) {
                    console.error('Search error:', error);
                    resultsDiv.innerHTML = '<div style="padding:10px; color:#dc2626;">❌ Error searching. Please try again.</div>';
                });
        }

        // Select a student
        function selectStudent(studentId, name, details, balance, bills) {
            payStudent.value = studentId;
            selectedName.textContent = name;
            selectedDetails.textContent = details;
            
            if (balance > 0) {
                selectedBalance.innerHTML = '<span style="color:#dc2626;">💰 Outstanding Balance: GHS ' + balance.toFixed(2) + ' (' + bills + ' bill(s))</span>';
            } else {
                selectedBalance.innerHTML = '<span style="color:#16a34a;">✅ No outstanding balance</span>';
            }
            
            selectedInfo.style.display = 'flex';
            resultsDiv.style.display = 'none';
            searchInput.value = name;

            // Load bills for this student
            loadStudentBills(studentId);
        }

        // Load student bills
        function loadStudentBills(studentId) {
            billSelect.innerHTML = '<option value="">Loading bills...</option>';
            amountInput.value = '';
            billSummary.innerHTML = '';
            paymentSummary.style.display = 'none';

            fetch('/api/finance/index.php?action=student-bills&student_id=' + studentId)
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    billSelect.innerHTML = '<option value="">Select a bill</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        var hasBills = false;
                        var totalBalance = 0;
                        var billCount = 0;
                        var billOptions = [];
                        
                        data.data.forEach(function(bill) {
                            var balance = parseFloat(bill.balance_due || 0);
                            if (balance > 0 && bill.bill_status !== 'paid' && bill.bill_status !== 'cancelled') {
                                var opt = document.createElement('option');
                                opt.value = bill.id;
                                var billLabel = bill.bill_number + ' - GHS ' + balance.toFixed(2);
                                if (bill.category_name) {
                                    billLabel += ' (' + bill.category_name + ')';
                                }
                                billLabel += ' - Due: ' + bill.due_date;
                                opt.textContent = billLabel;
                                opt.dataset.balance = balance;
                                opt.dataset.total = bill.total_amount;
                                opt.dataset.billNumber = bill.bill_number;
                                opt.dataset.dueDate = bill.due_date;
                                billSelect.appendChild(opt);
                                hasBills = true;
                                totalBalance += balance;
                                billCount++;
                                billOptions.push(bill);
                            }
                        });

                        if (!hasBills) {
                            billSelect.innerHTML = '<option value="">No outstanding bills</option>';
                            billSummary.innerHTML = '<span style="color:#16a34a;">✅ All bills are paid!</span>';
                        } else {
                            billSummary.innerHTML = '<span style="color:#666;">Total outstanding: <strong>GHS ' + totalBalance.toFixed(2) + '</strong> across ' + billCount + ' bill(s)</span>';
                        }
                    } else {
                        billSelect.innerHTML = '<option value="">No bills found for this student</option>';
                        billSummary.innerHTML = '<span style="color:#16a34a;">✅ No outstanding bills</span>';
                    }
                })
                .catch(function(error) {
                    console.error('Error loading bills:', error);
                    billSelect.innerHTML = '<option value="">Error loading bills</option>';
                    billSummary.innerHTML = '<span style="color:#dc2626;">❌ Error loading bills. Please try again.</span>';
                });
        }

        // ============================================================
        // EVENT LISTENERS
        // ============================================================

        // Search mode toggle
        document.querySelectorAll('input[name="search_mode"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                updatePlaceholder();
                // Clear previous results
                resultsDiv.style.display = 'none';
                searchInput.value = '';
                clearSelectedStudent();
            });
        });

        // Search on button click
        if (searchBtn) {
            searchBtn.addEventListener('click', performSearch);
        }

        // Search on Enter key
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    performSearch();
                }
            });
            
            // Auto-search after typing delay
            var searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    if (searchInput.value.trim().length >= 1) {
                        performSearch();
                    } else {
                        resultsDiv.style.display = 'none';
                    }
                }, 400);
            });

            // Close search results when clicking outside
            document.addEventListener('click', function(e) {
                var searchContainer = document.querySelector('.form-group');
                if (searchContainer && !searchContainer.contains(e.target)) {
                    resultsDiv.style.display = 'none';
                }
            });
        }

        // Update amount when bill is selected
        if (billSelect) {
            billSelect.addEventListener('change', function() {
                var selectedOption = this.options[this.selectedIndex];
                if (selectedOption && selectedOption.dataset.balance) {
                    var balance = parseFloat(selectedOption.dataset.balance);
                    amountInput.value = balance.toFixed(2);
                    amountInput.max = balance;
                    amountInput.placeholder = 'Max: ' + balance.toFixed(2);
                    
                    // Update summary
                    var billNumber = selectedOption.dataset.billNumber || 'N/A';
                    var total = parseFloat(selectedOption.dataset.total || 0);
                    var dueDate = selectedOption.dataset.dueDate || 'N/A';
                    paymentSummary.style.display = 'block';
                    summaryText.textContent = 'Bill: ' + billNumber + ' | Total: GHS ' + total.toFixed(2) + ' | Balance: GHS ' + balance.toFixed(2);
                } else {
                    amountInput.value = '';
                    amountInput.max = '';
                    paymentSummary.style.display = 'none';
                }
            });
        }

        // Validate amount on input
        if (amountInput) {
            amountInput.addEventListener('input', function() {
                var val = parseFloat(this.value);
                var max = parseFloat(this.max);
                if (val > max && max > 0) {
                    this.value = max.toFixed(2);
                }
                if (val > 0 && max > 0) {
                    paymentSummary.style.display = 'block';
                    summaryText.textContent = 'Payment: GHS ' + val.toFixed(2) + ' (Balance: GHS ' + max.toFixed(2) + ')';
                } else {
                    paymentSummary.style.display = 'none';
                }
            });
        }

        // Form validation before submit
        var paymentForm = document.getElementById('paymentForm');
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                var studentId = payStudent.value;
                var amount = parseFloat(amountInput.value);
                var method = document.getElementById('pay_method').value;
                
                if (!studentId) {
                    e.preventDefault();
                    alert('Please search and select a student.');
                    return false;
                }
                
                if (!amount || amount <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid amount greater than zero.');
                    return false;
                }
                
                if (!method) {
                    e.preventDefault();
                    alert('Please select a payment method.');
                    return false;
                }
                
                // Double-check the amount doesn't exceed balance
                var selectedOption = billSelect.options[billSelect.selectedIndex];
                if (selectedOption && selectedOption.dataset.balance) {
                    var balance = parseFloat(selectedOption.dataset.balance);
                    if (amount > balance) {
                        e.preventDefault();
                        alert('Payment amount (GHS ' + amount.toFixed(2) + ') exceeds the bill balance (GHS ' + balance.toFixed(2) + ').');
                        return false;
                    }
                }
                
                // Confirm payment
                if (!confirm('Confirm payment of GHS ' + amount.toFixed(2) + ' for student ' + selectedName.textContent + '?')) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        }

        // Initialize
        updatePlaceholder();
        generateReferenceNumber();
    });
    </script>
</body>
</html>