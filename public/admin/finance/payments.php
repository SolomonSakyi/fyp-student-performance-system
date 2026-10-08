<?php
/**
 * payments.php
 *
 * Finance Payments Management
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
require_once $basePath . 'app/services/Finance/FinanceService.php';

$financeService = new FinanceService();
$db = DatabaseHelper::getInstance();

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Handle Record Payment
if (isset($_POST['record_payment'])) {
    $data = [
        'student_id' => $_POST['student_id'] ?? 0,
        'student_bill_id' => $_POST['bill_id'] ?? null,
        'amount' => $_POST['amount'] ?? 0,
        'payment_method_id' => $_POST['payment_method_id'] ?? 0,
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
    header('Location: /admin/finance/payments.php');
    exit;
}

// Get data
$payments = $financeService->getPayments()['data'] ?? [];
$paymentMethods = $financeService->getPaymentMethods()['data'] ?? [];
$students = $db->fetchAll("SELECT id, first_name, last_name, admission_number FROM students WHERE is_active = 1 ORDER BY first_name");
$bills = $db->fetchAll("SELECT id, bill_number, student_id, balance_due FROM student_bills WHERE is_active = 1 AND bill_status NOT IN ('paid', 'cancelled', 'void') AND balance_due > 0");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Finance</title>
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
        .badge.completed { background: #d4edda; color: #155724; }
        .badge.pending { background: #fff3cd; color: #856404; }
        .badge.failed { background: #f8d7da; color: #721c24; }
        .badge.refunded { background: #cce5ff; color: #004085; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 4px; color: #333; font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; }
        .form-group textarea { min-height: 60px; resize: vertical; }
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
        .message {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .student-search-results {
            border: 1px solid #ddd;
            border-radius: 5px;
            max-height: 200px;
            overflow-y: auto;
            display: none;
            background: #fff;
            position: absolute;
            z-index: 100;
            width: 100%;
        }
        .student-search-results .result-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .student-search-results .result-item:hover { background: #f0f7ff; }
        .student-info-box {
            background: #f8fafc;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 12px;
            min-height: 50px;
        }
        .student-info-box .name { font-size: 16px; font-weight: bold; color: #1a3c6e; }
        .student-info-box .admission { color: #666; font-size: 13px; }
        .student-info-box .empty { color: #999; }
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
                <h1>💳 Payments</h1>
                <p style="color: #666; font-size: 13px;">Record and manage payments</p>
            </div>
            <button class="btn btn-green" onclick="openModal('recordPaymentModal')">💳 Record Payment</button>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Payments List -->
        <div class="content-card">
            <div class="card-header">
                <h3>💳 All Payments</h3>
                <span style="font-size:12px; color:#999;"><?php echo count($payments); ?> payments</span>
            </div>
            <?php if (!empty($payments)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Student</th>
                                <th>Bill</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($payment['receipt_number'] ?? '-'); ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')); ?></strong>
                                        <br><small style="color:#999;"><?php echo htmlspecialchars($payment['admission_number'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['bill_number'] ?? 'N/A'); ?></td>
                                    <td><span class="amount">GHS <?php echo number_format($payment['amount'] ?? 0, 2); ?></span></td>
                                    <td><?php echo htmlspecialchars($payment['method_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('d M Y', strtotime($payment['payment_date'] ?? 'now')); ?></td>
                                    <td><span class="badge <?php echo $payment['payment_status'] ?? 'pending'; ?>"><?php echo ucfirst($payment['payment_status'] ?? 'Pending'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No payments recorded yet.</p>
            <?php endif; ?>
        </div>
        
        <!-- Record Payment Modal -->
        <div class="modal" id="recordPaymentModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('recordPaymentModal')">&times;</span>
                <h2>💳 Record Payment</h2>
                
                <!-- Student Search -->
                <div class="form-group" style="position:relative;">
                    <label for="student_search">Search Student</label>
                    <input type="text" id="student_search" placeholder="Type name or admission number..." onkeyup="searchStudents(this.value)">
                    <div class="student-search-results" id="student_results"></div>
                </div>
                
                <!-- Selected Student Info -->
                <div class="form-group">
                    <label>Selected Student</label>
                    <div id="selected_student_info" class="student-info-box">
                        <span class="empty">No student selected. Search above to find a student.</span>
                    </div>
                    <input type="hidden" id="selected_student_id" name="student_id" value="">
                </div>
                
                <form method="POST" action="" id="paymentForm">
                    <input type="hidden" name="record_payment" value="1">
                    
                    <div class="form-group">
                        <label for="bill_id">Select Bill</label>
                        <select id="bill_id" name="bill_id">
                            <option value="">No student selected</option>
                        </select>
                    </div>
                    
                    <!-- Bill Info Display -->
                    <div id="bill_info" style="display:none; background:#f8fafc; padding:12px; border-radius:5px; border:1px solid #e2e8f0; margin-bottom:12px;">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div><strong>Bill:</strong> <span id="bill_number_display">-</span></div>
                            <div><strong>Balance:</strong> <span id="bill_balance_display">-</span></div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount *</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label for="payment_method_id">Payment Method *</label>
                            <select id="payment_method_id" name="payment_method_id" required>
                                <option value="">Select</option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?php echo $method['id']; ?>"><?php echo htmlspecialchars($method['method_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="payment_date">Payment Date</label>
                            <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="payment_reference">Reference Number</label>
                            <input type="text" id="payment_reference" name="payment_reference" placeholder="Transaction reference">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Additional notes" rows="2"></textarea>
                    </div>
                    
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 Record Payment</button>
                        <button type="button" class="btn" onclick="closeModal('recordPaymentModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let selectedStudentId = null;
        let selectedStudentName = '';
        let billsData = [];
        
        function searchStudents(query) {
            const resultsDiv = document.getElementById('student_results');
            if (query.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            fetch('/api/finance/index.php?action=search-students&q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        let html = '';
                        data.data.forEach(student => {
                            html += '<div class="result-item" onclick="selectStudent(' + student.id + ', \'' + student.first_name + ' ' + student.last_name + '\', \'' + student.admission_number + '\')">';
                            html += '<div><strong>' + student.first_name + ' ' + student.last_name + '</strong><br><small style="color:#999;">' + student.admission_number + '</small></div>';
                            html += '<div style="color:#1a3c6e; font-size:12px;">Select →</div>';
                            html += '</div>';
                        });
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div style="padding:10px; color:#999; text-align:center;">No students found</div>';
                        resultsDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }
        
        function selectStudent(id, name, admission) {
            selectedStudentId = id;
            selectedStudentName = name;
            
            document.getElementById('student_search').value = name;
            document.getElementById('selected_student_info').innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span class="name">${name}</span>
                        <br><span class="admission">Admission: ${admission}</span>
                    </div>
                    <span style="background:#1a3c6e; color:#fff; padding:2px 10px; border-radius:20px; font-size:11px;">Selected</span>
                </div>
            `;
            document.getElementById('selected_student_id').value = id;
            document.getElementById('student_results').style.display = 'none';
            
            // Load bills for this student
            fetch('/api/finance/index.php?action=student-bills&student_id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        billsData = data.data;
                        const billSelect = document.getElementById('bill_id');
                        billSelect.innerHTML = '<option value="">Select a bill</option>';
                        if (billsData.length === 0) {
                            billSelect.innerHTML = '<option value="">No outstanding bills</option>';
                            document.getElementById('bill_info').style.display = 'none';
                        } else {
                            billsData.forEach(bill => {
                                const statusText = bill.bill_status.charAt(0).toUpperCase() + bill.bill_status.slice(1);
                                billSelect.innerHTML += `<option value="${bill.id}" data-balance="${bill.balance_due}" data-number="${bill.bill_number}">
                                    ${bill.bill_number} - GHS ${parseFloat(bill.balance_due).toFixed(2)} (${statusText})
                                </option>`;
                            });
                        }
                    }
                });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Update bill info when bill is selected
            document.getElementById('bill_id').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const infoDiv = document.getElementById('bill_info');
                
                if (this.value && selectedOption) {
                    const balance = parseFloat(selectedOption.dataset.balance || 0);
                    const billNumber = selectedOption.dataset.number || '';
                    
                    document.getElementById('bill_number_display').textContent = billNumber;
                    document.getElementById('bill_balance_display').textContent = 'GHS ' + balance.toFixed(2);
                    document.getElementById('amount').max = balance;
                    document.getElementById('amount').placeholder = 'Max: ' + balance.toFixed(2);
                    document.getElementById('amount').value = balance > 0 ? balance.toFixed(2) : '';
                    infoDiv.style.display = 'block';
                } else {
                    infoDiv.style.display = 'none';
                }
            });
        });
        
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