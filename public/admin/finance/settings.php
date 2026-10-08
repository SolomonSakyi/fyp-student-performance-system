<?php
/**
 * settings.php
 *
 * Enterprise Finance Settings
 * Complete configuration management for the Finance Module
 * 
 * @package EduTrack
 * @subpackage Admin\Finance
 * @version 2.0
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

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Handle Save Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $settings = [
            // General Settings
            'school_name' => $_POST['school_name'] ?? 'Church of Christ International School',
            'school_address' => $_POST['school_address'] ?? '',
            'school_phone' => $_POST['school_phone'] ?? '',
            'school_email' => $_POST['school_email'] ?? '',
            'default_currency' => $_POST['default_currency'] ?? 'GHS',
            'currency_symbol' => $_POST['currency_symbol'] ?? 'GH₵',
            'date_format' => $_POST['date_format'] ?? 'd M Y',
            'timezone' => $_POST['timezone'] ?? 'Africa/Accra',
            
            // Billing Settings
            'invoice_prefix' => $_POST['invoice_prefix'] ?? 'INV-',
            'receipt_prefix' => $_POST['receipt_prefix'] ?? 'REC-',
            'invoice_terms' => $_POST['invoice_terms'] ?? 'Payment is due within 30 days.',
            'invoice_footer' => $_POST['invoice_footer'] ?? 'Thank you for your business.',
            'next_invoice_number' => $_POST['next_invoice_number'] ?? 1001,
            'next_receipt_number' => $_POST['next_receipt_number'] ?? 1001,
            
            // Fee Settings
            'late_fee_days' => $_POST['late_fee_days'] ?? 30,
            'late_fee_percentage' => $_POST['late_fee_percentage'] ?? 5.00,
            'late_fee_fixed' => $_POST['late_fee_fixed'] ?? 0,
            'max_installments' => $_POST['max_installments'] ?? 3,
            'minimum_deposit_percentage' => $_POST['minimum_deposit_percentage'] ?? 25,
            
            // Notification Settings
            'reminder_days_before' => $_POST['reminder_days_before'] ?? 7,
            'reminder_days_after' => $_POST['reminder_days_after'] ?? 14,
            'enable_sms_notifications' => isset($_POST['enable_sms_notifications']) ? 1 : 0,
            'enable_email_notifications' => isset($_POST['enable_email_notifications']) ? 1 : 0,
            'sms_provider' => $_POST['sms_provider'] ?? 'none',
            'email_template_bill' => $_POST['email_template_bill'] ?? '',
            'email_template_payment' => $_POST['email_template_payment'] ?? '',
            'email_template_overdue' => $_POST['email_template_overdue'] ?? '',
            
            // Portal Settings
            'parent_portal_enabled' => isset($_POST['parent_portal_enabled']) ? 1 : 0,
            'student_portal_enabled' => isset($_POST['student_portal_enabled']) ? 1 : 0,
            'portal_allow_payments' => isset($_POST['portal_allow_payments']) ? 1 : 0,
            'portal_show_balance' => isset($_POST['portal_show_balance']) ? 1 : 0,
            
            // Payment Settings
            'enable_online_payments' => isset($_POST['enable_online_payments']) ? 1 : 0,
            'default_gateway' => $_POST['default_gateway'] ?? 'paystack',
            'payment_instructions' => $_POST['payment_instructions'] ?? '',
            
            // Arrears Settings
            'enable_auto_forward' => isset($_POST['enable_auto_forward']) ? 1 : 0,
            'auto_forward_days' => $_POST['auto_forward_days'] ?? 7,
            
            // Accounting Settings
            'enable_accounting' => isset($_POST['enable_accounting']) ? 1 : 0,
            'default_account_receivable' => $_POST['default_account_receivable'] ?? '1300',
            'default_account_cash' => $_POST['default_account_cash'] ?? '1200',
            'default_account_revenue' => $_POST['default_account_revenue'] ?? '4100',
            
            // Security Settings
            'enable_audit_log' => isset($_POST['enable_audit_log']) ? 1 : 0,
            'enable_2fa' => isset($_POST['enable_2fa']) ? 1 : 0,
            'session_timeout' => $_POST['session_timeout'] ?? 3600,
            'max_login_attempts' => $_POST['max_login_attempts'] ?? 5
        ];
        
        foreach ($settings as $key => $value) {
            $existing = $db->fetchOne("SELECT id FROM finance_settings WHERE setting_key = ?", [$key]);
            if ($existing) {
                $db->query("UPDATE finance_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$value, $key]);
            } else {
                $db->query("INSERT INTO finance_settings (uuid, school_id, setting_key, setting_value, setting_category, is_active, created_at)
                            VALUES (UUID(), 1, ?, ?, 'general', 1, NOW())", [$key, $value]);
            }
        }
        
        $_SESSION['message'] = '✅ Settings saved successfully!';
        header('Location: /admin/finance/settings.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = '❌ ' . $e->getMessage();
        header('Location: /admin/finance/settings.php');
        exit;
    }
}

// Get current settings
$settings = [];
$rows = $db->fetchAll("SELECT setting_key, setting_value FROM finance_settings WHERE is_active = 1");
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get payment gateways for dropdown
$gateways = $db->fetchAll("SELECT gateway_code, gateway_name FROM payment_gateway_configs WHERE is_active = 1");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Settings - EduTrack</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            display: flex;
            min-height: 100vh;
        }
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
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 { color: #1a3c6e; font-size: 24px; }
        .header h1 i { margin-right: 10px; color: #f59e0b; }
        .header p { color: #666; font-size: 13px; margin-top: 5px; }
        
        .btn {
            display: inline-block;
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
            font-weight: 500;
        }
        .btn-primary { background: #1a3c6e; color: #fff; }
        .btn-primary:hover { background: #2a4c8e; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover { background: #15803d; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-outline { background: transparent; border: 2px solid #1a3c6e; color: #1a3c6e; }
        .btn-outline:hover { background: #1a3c6e; color: #fff; }
        
        .content-card {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f2f5;
        }
        .content-card .card-header h3 {
            color: #1a3c6e;
            font-size: 18px;
        }
        .content-card .card-header h3 i {
            margin-right: 8px;
            color: #f59e0b;
        }
        .content-card .card-header .badge-count {
            background: #e8eaf6;
            color: #1a3c6e;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
            font-size: 13px;
        }
        .form-group label .required { color: #dc2626; }
        .form-group .hint {
            font-size: 11px;
            color: #999;
            margin-top: 3px;
        }
        .form-group input, 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            transition: 0.3s;
            font-family: inherit;
        }
        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            border-color: #1a3c6e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 60, 110, 0.1);
        }
        .form-group textarea { min-height: 60px; resize: vertical; }
        .form-group .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 0;
        }
        .form-group .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .form-group .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: 400;
        }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .form-row-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px; }
        
        .btn-row { display: flex; gap: 10px; margin-top: 20px; }
        .btn-row .btn { flex: 1; text-align: center; }
        
        .message {
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        
        .section-divider {
            margin: 25px 0 15px;
            border-top: 2px solid #e2e8f0;
            position: relative;
        }
        .section-divider .section-title {
            position: absolute;
            top: -12px;
            left: 15px;
            background: #fff;
            padding: 0 15px;
            font-weight: 700;
            color: #1a3c6e;
            font-size: 14px;
        }
        .section-divider .section-title i {
            margin-right: 8px;
            color: #f59e0b;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .settings-grid .full-width { grid-column: 1 / -1; }
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #fff;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tabs .tab {
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-size: 13px;
            font-weight: 600;
            transition: 0.3s;
            background: transparent;
            border: none;
        }
        .tabs .tab:hover { background: #f0f2f5; color: #1a3c6e; }
        .tabs .tab.active { background: #1a3c6e; color: #fff; }
        .tabs .tab i { margin-right: 8px; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-3, .form-row-4 { grid-template-columns: 1fr; }
            .settings-grid { grid-template-columns: 1fr; }
            .tabs .tab { flex: 1; text-align: center; padding: 8px 12px; font-size: 11px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-cog"></i> Finance Settings</h1>
                <p>Complete configuration management for the Finance Module</p>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" data-tab="general"><i class="fas fa-building"></i> General</button>
            <button class="tab" data-tab="billing"><i class="fas fa-file-invoice"></i> Billing</button>
            <button class="tab" data-tab="fees"><i class="fas fa-coins"></i> Fees</button>
            <button class="tab" data-tab="notifications"><i class="fas fa-bell"></i> Notifications</button>
            <button class="tab" data-tab="payments"><i class="fas fa-credit-card"></i> Payments</button>
            <button class="tab" data-tab="accounting"><i class="fas fa-book"></i> Accounting</button>
            <button class="tab" data-tab="security"><i class="fas fa-shield-alt"></i> Security</button>
        </div>
        
        <form method="POST" action="">
            <input type="hidden" name="save_settings" value="1">
            
            <!-- ============================================================ -->
            <!-- TAB 1: GENERAL SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-general" class="tab-content active">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-building"></i> General Settings</h3>
                        <span class="badge-count">School Information</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group full-width">
                            <label for="school_name">School Name <span class="required">*</span></label>
                            <input type="text" id="school_name" name="school_name" value="<?php echo htmlspecialchars($settings['school_name'] ?? 'Church of Christ International School'); ?>" placeholder="Enter school name">
                        </div>
                        <div class="form-group">
                            <label for="school_address">School Address</label>
                            <input type="text" id="school_address" name="school_address" value="<?php echo htmlspecialchars($settings['school_address'] ?? ''); ?>" placeholder="Enter school address">
                        </div>
                        <div class="form-group">
                            <label for="school_phone">School Phone</label>
                            <input type="text" id="school_phone" name="school_phone" value="<?php echo htmlspecialchars($settings['school_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="school_email">School Email</label>
                            <input type="email" id="school_email" name="school_email" value="<?php echo htmlspecialchars($settings['school_email'] ?? ''); ?>" placeholder="info@school.com">
                        </div>
                        <div class="form-group">
                            <label for="default_currency">Default Currency <span class="required">*</span></label>
                            <select id="default_currency" name="default_currency">
                                <option value="GHS" <?php echo ($settings['default_currency'] ?? 'GHS') == 'GHS' ? 'selected' : ''; ?>>GHS - Ghana Cedis</option>
                                <option value="USD" <?php echo ($settings['default_currency'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                <option value="GBP" <?php echo ($settings['default_currency'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                                <option value="EUR" <?php echo ($settings['default_currency'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                <option value="NGN" <?php echo ($settings['default_currency'] ?? '') == 'NGN' ? 'selected' : ''; ?>>NGN - Nigerian Naira</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="currency_symbol">Currency Symbol</label>
                            <input type="text" id="currency_symbol" name="currency_symbol" value="<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'GH₵'); ?>" placeholder="GH₵">
                        </div>
                        <div class="form-group">
                            <label for="date_format">Date Format</label>
                            <select id="date_format" name="date_format">
                                <option value="d M Y" <?php echo ($settings['date_format'] ?? 'd M Y') == 'd M Y' ? 'selected' : ''; ?>>15 Jan 2025</option>
                                <option value="M d, Y" <?php echo ($settings['date_format'] ?? '') == 'M d, Y' ? 'selected' : ''; ?>>Jan 15, 2025</option>
                                <option value="Y-m-d" <?php echo ($settings['date_format'] ?? '') == 'Y-m-d' ? 'selected' : ''; ?>>2025-01-15</option>
                                <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') == 'd/m/Y' ? 'selected' : ''; ?>>15/01/2025</option>
                                <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') == 'm/d/Y' ? 'selected' : ''; ?>>01/15/2025</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="timezone">Timezone</label>
                            <select id="timezone" name="timezone">
                                <option value="Africa/Accra" <?php echo ($settings['timezone'] ?? 'Africa/Accra') == 'Africa/Accra' ? 'selected' : ''; ?>>Africa/Accra (GMT+0)</option>
                                <option value="Africa/Lagos" <?php echo ($settings['timezone'] ?? '') == 'Africa/Lagos' ? 'selected' : ''; ?>>Africa/Lagos (GMT+1)</option>
                                <option value="Africa/Nairobi" <?php echo ($settings['timezone'] ?? '') == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (GMT+3)</option>
                                <option value="Africa/Johannesburg" <?php echo ($settings['timezone'] ?? '') == 'Africa/Johannesburg' ? 'selected' : ''; ?>>Africa/Johannesburg (GMT+2)</option>
                                <option value="UTC" <?php echo ($settings['timezone'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- TAB 2: BILLING SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-billing" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-invoice"></i> Billing Settings</h3>
                        <span class="badge-count">Invoice Configuration</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="invoice_prefix">Invoice Prefix</label>
                            <input type="text" id="invoice_prefix" name="invoice_prefix" value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'INV-'); ?>" placeholder="INV-">
                            <div class="hint">Prefix for all invoice numbers</div>
                        </div>
                        <div class="form-group">
                            <label for="receipt_prefix">Receipt Prefix</label>
                            <input type="text" id="receipt_prefix" name="receipt_prefix" value="<?php echo htmlspecialchars($settings['receipt_prefix'] ?? 'REC-'); ?>" placeholder="REC-">
                            <div class="hint">Prefix for all receipt numbers</div>
                        </div>
                        <div class="form-group">
                            <label for="next_invoice_number">Next Invoice Number</label>
                            <input type="number" id="next_invoice_number" name="next_invoice_number" value="<?php echo htmlspecialchars($settings['next_invoice_number'] ?? 1001); ?>" min="1">
                            <div class="hint">The next invoice number to be used</div>
                        </div>
                        <div class="form-group">
                            <label for="next_receipt_number">Next Receipt Number</label>
                            <input type="number" id="next_receipt_number" name="next_receipt_number" value="<?php echo htmlspecialchars($settings['next_receipt_number'] ?? 1001); ?>" min="1">
                            <div class="hint">The next receipt number to be used</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="invoice_terms">Invoice Terms & Conditions</label>
                            <textarea id="invoice_terms" name="invoice_terms" rows="3"><?php echo htmlspecialchars($settings['invoice_terms'] ?? 'Payment is due within 30 days. Late payments may incur additional charges.'); ?></textarea>
                            <div class="hint">Terms that appear on all invoices</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="invoice_footer">Invoice Footer</label>
                            <textarea id="invoice_footer" name="invoice_footer" rows="2"><?php echo htmlspecialchars($settings['invoice_footer'] ?? 'Thank you for your business.'); ?></textarea>
                            <div class="hint">Footer text that appears on all invoices</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- TAB 3: FEES SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-fees" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-coins"></i> Fee Settings</h3>
                        <span class="badge-count">Fee Configuration</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="late_fee_days">Late Fee Days</label>
                            <input type="number" id="late_fee_days" name="late_fee_days" value="<?php echo htmlspecialchars($settings['late_fee_days'] ?? 30); ?>" min="1">
                            <div class="hint">Days after due date to apply late fee</div>
                        </div>
                        <div class="form-group">
                            <label for="late_fee_percentage">Late Fee Percentage (%)</label>
                            <input type="number" id="late_fee_percentage" name="late_fee_percentage" value="<?php echo htmlspecialchars($settings['late_fee_percentage'] ?? 5); ?>" step="0.5" min="0" max="100">
                            <div class="hint">Percentage of total amount for late fee</div>
                        </div>
                        <div class="form-group">
                            <label for="late_fee_fixed">Fixed Late Fee (GHS)</label>
                            <input type="number" id="late_fee_fixed" name="late_fee_fixed" value="<?php echo htmlspecialchars($settings['late_fee_fixed'] ?? 0); ?>" step="0.5" min="0">
                            <div class="hint">Fixed amount for late fee (overrides percentage if set)</div>
                        </div>
                        <div class="form-group">
                            <label for="max_installments">Maximum Installments</label>
                            <input type="number" id="max_installments" name="max_installments" value="<?php echo htmlspecialchars($settings['max_installments'] ?? 3); ?>" min="1">
                            <div class="hint">Maximum number of installment payments allowed</div>
                        </div>
                        <div class="form-group">
                            <label for="minimum_deposit_percentage">Minimum Deposit (%)</label>
                            <input type="number" id="minimum_deposit_percentage" name="minimum_deposit_percentage" value="<?php echo htmlspecialchars($settings['minimum_deposit_percentage'] ?? 25); ?>" min="0" max="100">
                            <div class="hint">Minimum percentage required as deposit</div>
                        </div>
                        <div class="form-group full-width">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_auto_forward" name="enable_auto_forward" value="1" <?php echo ($settings['enable_auto_forward'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_auto_forward">Automatically forward arrears to next term</label>
                            </div>
                            <div class="hint">When enabled, unpaid bills will be automatically forwarded when term changes</div>
                        </div>
                        <div class="form-group">
                            <label for="auto_forward_days">Auto-Forward Days</label>
                            <input type="number" id="auto_forward_days" name="auto_forward_days" value="<?php echo htmlspecialchars($settings['auto_forward_days'] ?? 7); ?>" min="1">
                            <div class="hint">Days after term end to auto-forward arrears</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- TAB 4: NOTIFICATIONS SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-notifications" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Notification Settings</h3>
                        <span class="badge-count">Alert Configuration</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="reminder_days_before">Reminder Days Before Due</label>
                            <input type="number" id="reminder_days_before" name="reminder_days_before" value="<?php echo htmlspecialchars($settings['reminder_days_before'] ?? 7); ?>" min="0">
                            <div class="hint">Send reminder this many days before due date</div>
                        </div>
                        <div class="form-group">
                            <label for="reminder_days_after">Reminder Days After Due</label>
                            <input type="number" id="reminder_days_after" name="reminder_days_after" value="<?php echo htmlspecialchars($settings['reminder_days_after'] ?? 14); ?>" min="0">
                            <div class="hint">Send reminder this many days after due date</div>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_sms_notifications" name="enable_sms_notifications" value="1" <?php echo ($settings['enable_sms_notifications'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_sms_notifications">Enable SMS Notifications</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_email_notifications" name="enable_email_notifications" value="1" <?php echo ($settings['enable_email_notifications'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_email_notifications">Enable Email Notifications</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="sms_provider">SMS Provider</label>
                            <select id="sms_provider" name="sms_provider">
                                <option value="none" <?php echo ($settings['sms_provider'] ?? 'none') == 'none' ? 'selected' : ''; ?>>None</option>
                                <option value="twilio" <?php echo ($settings['sms_provider'] ?? '') == 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                                <option value="africas_talking" <?php echo ($settings['sms_provider'] ?? '') == 'africas_talking' ? 'selected' : ''; ?>>Africa's Talking</option>
                                <option value="hubtel" <?php echo ($settings['sms_provider'] ?? '') == 'hubtel' ? 'selected' : ''; ?>>Hubtel</option>
                                <option value="sendgrid" <?php echo ($settings['sms_provider'] ?? '') == 'sendgrid' ? 'selected' : ''; ?>>SendGrid</option>
                            </select>
                            <div class="hint">Select your SMS service provider</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="email_template_bill">Bill Generation Email Template</label>
                            <textarea id="email_template_bill" name="email_template_bill" rows="3"><?php echo htmlspecialchars($settings['email_template_bill'] ?? "Dear [Student Name],\n\nA new bill has been generated for you.\nBill Number: [Bill Number]\nAmount: [Amount]\nDue Date: [Due Date]\n\nPlease make payment before the due date.\n\nThank you,\n[School Name]"); ?></textarea>
                            <div class="hint">Use [Student Name], [Bill Number], [Amount], [Due Date], [School Name] as placeholders</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="email_template_payment">Payment Confirmation Email Template</label>
                            <textarea id="email_template_payment" name="email_template_payment" rows="3"><?php echo htmlspecialchars($settings['email_template_payment'] ?? "Dear [Student Name],\n\nWe have received your payment of [Amount].\nReceipt Number: [Receipt Number]\nDate: [Date]\n\nThank you for your prompt payment.\n\n[School Name]"); ?></textarea>
                            <div class="hint">Use [Student Name], [Amount], [Receipt Number], [Date], [School Name] as placeholders</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="email_template_overdue">Overdue Notice Email Template</label>
                            <textarea id="email_template_overdue" name="email_template_overdue" rows="3"><?php echo htmlspecialchars($settings['email_template_overdue'] ?? "Dear [Student Name],\n\nYour payment of [Amount] is now OVERDUE.\nBill Number: [Bill Number]\nDays Overdue: [Days Overdue]\n\nPlease make immediate payment to avoid further charges.\n\n[School Name]"); ?></textarea>
                            <div class="hint">Use [Student Name], [Amount], [Bill Number], [Days Overdue], [School Name] as placeholders</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- TAB 5: PAYMENTS SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-payments" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-credit-card"></i> Payment Settings</h3>
                        <span class="badge-count">Payment Gateway Configuration</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group full-width">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_online_payments" name="enable_online_payments" value="1" <?php echo ($settings['enable_online_payments'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_online_payments">Enable Online Payments</label>
                            </div>
                            <div class="hint">Allow parents and students to pay online via payment gateways</div>
                        </div>
                        <div class="form-group">
                            <label for="default_gateway">Default Payment Gateway</label>
                            <select id="default_gateway" name="default_gateway">
                                <option value="">Select Gateway</option>
                                <?php foreach ($gateways as $gateway): ?>
                                    <option value="<?php echo $gateway['gateway_code']; ?>" <?php echo ($settings['default_gateway'] ?? 'paystack') == $gateway['gateway_code'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($gateway['gateway_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Default payment gateway for online payments</div>
                        </div>
                        <div class="form-group full-width">
                            <label for="payment_instructions">Payment Instructions</label>
                            <textarea id="payment_instructions" name="payment_instructions" rows="4"><?php echo htmlspecialchars($settings['payment_instructions'] ?? "Payments can be made via:\n1. Bank Transfer to: [Bank Name], Account: [Account Number]\n2. Mobile Money: [Mobile Money Number]\n3. Online via our secure payment portal\n\nPlease quote the student admission number as reference."); ?></textarea>
                            <div class="hint">Instructions displayed to parents and students for making payments</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- TAB 6: ACCOUNTING SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-accounting" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-book"></i> Accounting Settings</h3>
                        <span class="badge-count">General Ledger Configuration</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group full-width">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_accounting" name="enable_accounting" value="1" <?php echo ($settings['enable_accounting'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_accounting">Enable Accounting Engine</label>
                            </div>
                            <div class="hint">Automatically post financial transactions to the general ledger</div>
                        </div>
                        <div class="form-group">
                            <label for="default_account_receivable">Accounts Receivable Account</label>
                            <select id="default_account_receivable" name="default_account_receivable">
                                <option value="1300" <?php echo ($settings['default_account_receivable'] ?? '1300') == '1300' ? 'selected' : ''; ?>>1300 - Accounts Receivable</option>
                            </select>
                            <div class="hint">Default account for student receivables</div>
                        </div>
                        <div class="form-group">
                            <label for="default_account_cash">Cash/Bank Account</label>
                            <select id="default_account_cash" name="default_account_cash">
                                <option value="1200" <?php echo ($settings['default_account_cash'] ?? '1200') == '1200' ? 'selected' : ''; ?>>1200 - Cash at Bank</option>
                                <option value="1100" <?php echo ($settings['default_account_cash'] ?? '') == '1100' ? 'selected' : ''; ?>>1100 - Cash in Hand</option>
                            </select>
                            <div class="hint">Default account for cash/bank transactions</div>
                        </div>
                        <div class="form-group">
                            <label for="default_account_revenue">Revenue Account</label>
                            <select id="default_account_revenue" name="default_account_revenue">
                                <option value="4100" <?php echo ($settings['default_account_revenue'] ?? '4100') == '4100' ? 'selected' : ''; ?>>4100 - Tuition Fees</option>
                                <option value="4200" <?php echo ($settings['default_account_revenue'] ?? '') == '4200' ? 'selected' : ''; ?>>4200 - Levy Fees</option>
                                <option value="4300" <?php echo ($settings['default_account_revenue'] ?? '') == '4300' ? 'selected' : ''; ?>>4300 - Sports Fees</option>
                                <option value="4400" <?php echo ($settings['default_account_revenue'] ?? '') == '4400' ? 'selected' : ''; ?>>4400 - PTA Fees</option>
                                <option value="4700" <?php echo ($settings['default_account_revenue'] ?? '') == '4700' ? 'selected' : ''; ?>>4700 - Miscellaneous Income</option>
                            </select>
                            <div class="hint">Default account for revenue transactions</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- TAB 7: SECURITY SETTINGS -->
            <!-- ============================================================ -->
            <div id="tab-security" class="tab-content">
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-shield-alt"></i> Security Settings</h3>
                        <span class="badge-count">Security Configuration</span>
                    </div>
                    <div class="settings-grid">
                        <div class="form-group full-width">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_audit_log" name="enable_audit_log" value="1" <?php echo ($settings['enable_audit_log'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_audit_log">Enable Audit Logging</label>
                            </div>
                            <div class="hint">Log all financial transactions for audit purposes</div>
                        </div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="enable_2fa" name="enable_2fa" value="1" <?php echo ($settings['enable_2fa'] ?? 0) ? 'checked' : ''; ?>>
                                <label for="enable_2fa">Enable Two-Factor Authentication</label>
                            </div>
                            <div class="hint">Require 2FA for financial transactions</div>
                        </div>
                        <div class="form-group">
                            <label for="session_timeout">Session Timeout (seconds)</label>
                            <input type="number" id="session_timeout" name="session_timeout" value="<?php echo htmlspecialchars($settings['session_timeout'] ?? 3600); ?>" min="60" step="60">
                            <div class="hint">Inactivity timeout for finance module sessions</div>
                        </div>
                        <div class="form-group">
                            <label for="max_login_attempts">Max Login Attempts</label>
                            <input type="number" id="max_login_attempts" name="max_login_attempts" value="<?php echo htmlspecialchars($settings['max_login_attempts'] ?? 5); ?>" min="1">
                            <div class="hint">Maximum failed login attempts before lockout</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="btn-row">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save All Settings</button>
                <button type="reset" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</button>
            </div>
        </form>
    </div>
    
    <script>
        // Tab switching
        document.querySelectorAll('.tabs .tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                document.querySelectorAll('.tabs .tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                // Add active class to clicked tab
                this.classList.add('active');
                
                // Hide all tab contents
                document.querySelectorAll('.tab-content').forEach(function(content) {
                    content.classList.remove('active');
                });
                
                // Show selected tab content
                var tabId = this.dataset.tab;
                document.getElementById('tab-' + tabId).classList.add('active');
            });
        });
    </script>
</body>
</html>