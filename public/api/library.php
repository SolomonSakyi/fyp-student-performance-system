<?php
/**
 * library.php
 * 
 * Library Management Admin Interface
 * 
 * @package EduTrack
 * @subpackage Admin
 */

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/services/Library/LibraryService.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();
$service = new LibraryService();

$action = $_GET['action'] ?? 'dashboard';
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$activeTab = $_GET['tab'] ?? 'dashboard';

// Handle form submissions
if (isset($_POST['add_book'])) {
    $result = $service->addBook($_POST);
    if ($result['success']) {
        header('Location: /admin/library.php?tab=books&msg=' . urlencode($result['message']));
    } else {
        header('Location: /admin/library.php?tab=books&error=' . urlencode($result['message']));
    }
    exit;
}

if (isset($_POST['add_membership'])) {
    $result = $service->createMembership($_POST);
    if ($result['success']) {
        header('Location: /admin/library.php?tab=members&msg=' . urlencode($result['message']));
    } else {
        header('Location: /admin/library.php?tab=members&error=' . urlencode($result['message']));
    }
    exit;
}

if (isset($_POST['borrow_book'])) {
    $result = $service->borrowBook($_POST);
    if ($result['success']) {
        header('Location: /admin/library.php?tab=loans&msg=' . urlencode($result['message']));
    } else {
        header('Location: /admin/library.php?tab=loans&error=' . urlencode($result['message']));
    }
    exit;
}

if (isset($_POST['return_book'])) {
    $result = $service->returnBook($_POST['loan_id']);
    if ($result['success']) {
        header('Location: /admin/library.php?tab=loans&msg=' . urlencode($result['message']));
    } else {
        header('Location: /admin/library.php?tab=loans&error=' . urlencode($result['message']));
    }
    exit;
}

// Get data
$dashboardData = $service->getDashboardSummary();
$stats = $dashboardData['success'] ? $dashboardData['data']['statistics'] : [];

// Get books
$booksResult = $service->getBooks([], $_GET['page'] ?? 1, 20);
$books = $booksResult['success'] ? $booksResult['data']['books'] : [];
$pagination = $booksResult['success'] ? $booksResult['data']['pagination'] : [];

// Get memberships
$memberships = $service->getMemberships([], $_GET['page'] ?? 1, 20);
$membersData = $memberships['success'] ? $memberships['data']['memberships'] : [];

// Get overdue loans
$overdueLoans = $service->getOverdueLoans();
$overdueData = $overdueLoans['success'] ? $overdueLoans['data'] : [];

// Get branches
$branches = $db->fetchAll("SELECT * FROM library_branches WHERE is_active = 1");
$categories = $db->fetchAll("SELECT * FROM library_categories WHERE is_active = 1");
$publishers = $db->fetchAll("SELECT * FROM publishers WHERE is_active = 1");
$students = $db->fetchAll("SELECT id, first_name, last_name, admission_number FROM students WHERE is_active = 1");

// Get available books for borrowing
$availableCopies = $db->fetchAll("
    SELECT bc.id AS copy_id, bc.copy_number, b.book_title, b.book_code
    FROM book_copies bc
    JOIN books b ON bc.book_id = b.id
    WHERE bc.status = 'available' AND bc.is_active = 1 AND b.is_active = 1
    ORDER BY b.book_title ASC
    LIMIT 100
");

// Get members for loan dropdown
$membersList = $db->fetchAll("
    SELECT m.id, m.membership_number, s.first_name, s.last_name, s.admission_number
    FROM library_memberships m
    LEFT JOIN students s ON m.student_id = s.id
    WHERE m.is_active = 1
    ORDER BY s.first_name ASC
    LIMIT 100
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Management - EduTrack</title>
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
        .header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #1a3c6e;
        }
        .stat-card .number { font-size: 24px; font-weight: bold; color: #1a3c6e; }
        .stat-card .label { color: #666; font-size: 12px; margin-top: 3px; }
        .stat-card .number.green { color: #16a34a; }
        .stat-card .number.red { color: #dc2626; }
        .stat-card .number.orange { color: #f59e0b; }
        .stat-card .number.blue { color: #2563eb; }
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #fff;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tabs .tab {
            padding: 8px 16px;
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
        .btn-small { padding: 3px 8px; font-size: 11px; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .content-card h3 {
            color: #1a3c6e;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 6px;
            font-size: 15px;
        }
        .content-card .card-header h3 { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
        
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 6px 8px;
            text-align: left;
            white-space: nowrap;
        }
        table td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        table tr:hover { background: #f8fafc; }
        
        .badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            display: inline-block;
        }
        .badge.available { background: #d4edda; color: #155724; }
        .badge.borrowed { background: #fff3cd; color: #856404; }
        .badge.overdue { background: #f8d7da; color: #721c24; }
        .badge.returned { background: #cce5ff; color: #004085; }
        .badge.damaged { background: #f8d7da; color: #721c24; }
        .badge.lost { background: #f8d7da; color: #721c24; }
        .badge.active { background: #d4edda; color: #155724; }
        .badge.inactive { background: #f8d7da; color: #721c24; }
        
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
        
        .form-group { margin-bottom: 12px; }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 4px;
            color: #333;
            font-size: 13px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .btn-row { display: flex; gap: 10px; margin-top: 15px; }
        .btn-row .btn { flex: 1; text-align: center; }
        
        .message {
            padding: 8px 15px;
            border-radius: 5px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .no-data { text-align: center; color: #999; padding: 20px; }
        .action-group { display: flex; gap: 5px; flex-wrap: wrap; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs .tab { padding: 5px 10px; font-size: 10px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>📚 Library Management</h1>
                <p style="color: #666; font-size: 13px;">Manage books, members, and loans</p>
            </div>
            <div class="header-actions">
                <a href="#" class="btn btn-green" onclick="openModal('book')">➕ Add Book</a>
                <a href="#" class="btn" onclick="openModal('member')">👤 Add Member</a>
                <a href="#" class="btn btn-warning" onclick="openModal('loan')">📖 Borrow Book</a>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_books'] ?? 0; ?></div>
                <div class="label">📚 Total Books</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_members'] ?? 0; ?></div>
                <div class="label">👤 Members</div>
            </div>
            <div class="stat-card">
                <div class="number blue"><?php echo $stats['active_loans'] ?? 0; ?></div>
                <div class="label">📖 Active Loans</div>
            </div>
            <div class="stat-card">
                <div class="number red"><?php echo $stats['overdue_loans'] ?? 0; ?></div>
                <div class="label">⚠️ Overdue</div>
            </div>
            <div class="stat-card">
                <div class="number orange">GHS <?php echo number_format($stats['total_fines'] ?? 0, 2); ?></div>
                <div class="label">💰 Total Fines</div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="/admin/library.php?tab=dashboard" class="tab <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">📊 Dashboard</a>
            <a href="/admin/library.php?tab=books" class="tab <?php echo $activeTab === 'books' ? 'active' : ''; ?>">📚 Books</a>
            <a href="/admin/library.php?tab=members" class="tab <?php echo $activeTab === 'members' ? 'active' : ''; ?>">👤 Members</a>
            <a href="/admin/library.php?tab=loans" class="tab <?php echo $activeTab === 'loans' ? 'active' : ''; ?>">📖 Loans</a>
            <a href="/admin/library.php?tab=overdue" class="tab <?php echo $activeTab === 'overdue' ? 'active' : ''; ?>">⚠️ Overdue</a>
            <a href="/admin/library.php?tab=settings" class="tab <?php echo $activeTab === 'settings' ? 'active' : ''; ?>">⚙️ Settings</a>
        </div>
        
        <!-- ================================================================ -->
        <!-- TAB: DASHBOARD -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'dashboard'): ?>
        
        <div class="content-card">
            <h3>📊 Library Overview</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-top:10px;">
                <div style="background:#f8fafc;padding:15px;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
                    <div style="font-size:28px;font-weight:bold;color:#16a34a;"><?php echo $stats['total_books'] ?? 0; ?></div>
                    <div style="color:#666;font-size:12px;">Total Books</div>
                </div>
                <div style="background:#f8fafc;padding:15px;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
                    <div style="font-size:28px;font-weight:bold;color:#2563eb;"><?php echo $stats['active_loans'] ?? 0; ?></div>
                    <div style="color:#666;font-size:12px;">Active Loans</div>
                </div>
                <div style="background:#f8fafc;padding:15px;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
                    <div style="font-size:28px;font-weight:bold;color:#f59e0b;"><?php echo $stats['overdue_loans'] ?? 0; ?></div>
                    <div style="color:#666;font-size:12px;">Overdue</div>
                </div>
            </div>
        </div>
        
        <!-- Recent Loans -->
        <div class="content-card">
            <h3>📖 Recent Loans</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Member</th>
                            <th>Loan Date</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($dashboardData['data']['recent_loans'] ?? [])): ?>
                            <?php foreach ($dashboardData['data']['recent_loans'] as $loan): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($loan['book_title'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? '')); ?></td>
                                <td><?php echo isset($loan['loan_date']) ? date('d M Y', strtotime($loan['loan_date'])) : '-'; ?></td>
                                <td><?php echo isset($loan['due_date']) ? date('d M Y', strtotime($loan['due_date'])) : '-'; ?></td>
                                <td><span class="badge <?php echo $loan['status'] ?? 'unknown'; ?>"><?php echo ucfirst($loan['status'] ?? 'Unknown'); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="no-data">No recent loans</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: BOOKS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'books'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>📚 Books</h3>
                <button class="btn btn-green" onclick="openModal('book')">➕ Add Book</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Title</th>
                            <th>Code</th>
                            <th>ISBN</th>
                            <th>Available</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($books)): ?>
                            <?php foreach ($books as $index => $book): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($book['book_title'] ?? 'Unknown'); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($book['book_code'] ?? 'N/A'); ?></code></td>
                                <td><?php echo htmlspecialchars($book['isbn'] ?? 'N/A'); ?></td>
                                <td><?php echo $book['available_copies'] ?? 0; ?></td>
                                <td><?php echo $book['total_copies'] ?? 0; ?></td>
                                <td><span class="badge <?php echo ($book['available_copies'] ?? 0) > 0 ? 'available' : 'borrowed'; ?>">
                                    <?php echo ($book['available_copies'] ?? 0) > 0 ? 'Available' : 'Unavailable'; ?>
                                </span></td>
                                <td>
                                    <div class="action-group">
                                        <a href="#" class="btn btn-small">✏️</a>
                                        <a href="#" class="btn btn-small btn-red">🗑️</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="no-data">No books found. Click "Add Book" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add Book Modal -->
        <div class="modal" id="bookModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('bookModal')">&times;</span>
                <h2>➕ Add Book</h2>
                <form method="POST" action="">
                    <input type="hidden" name="add_book" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="book_title">Book Title *</label>
                            <input type="text" id="book_title" name="book_title" placeholder="Enter book title" required>
                        </div>
                        <div class="form-group">
                            <label for="book_code">Book Code *</label>
                            <input type="text" id="book_code" name="book_code" placeholder="e.g. B001" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="isbn">ISBN</label>
                            <input type="text" id="isbn" name="isbn" placeholder="978-3-16-148410-0">
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch *</label>
                            <select id="branch_id" name="branch_id" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="category_id">Category</label>
                            <select id="category_id" name="category_id">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="publisher_id">Publisher</label>
                            <select id="publisher_id" name="publisher_id">
                                <option value="">Select Publisher</option>
                                <?php foreach ($publishers as $pub): ?>
                                <option value="<?php echo $pub['id']; ?>"><?php echo htmlspecialchars($pub['publisher_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="book_type">Book Type</label>
                            <select id="book_type" name="book_type">
                                <option value="textbook">Textbook</option>
                                <option value="reference">Reference</option>
                                <option value="fiction">Fiction</option>
                                <option value="non_fiction">Non-Fiction</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="total_copies">Total Copies</label>
                            <input type="number" id="total_copies" name="total_copies" value="1" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="summary">Summary</label>
                        <textarea id="summary" name="summary" rows="3" placeholder="Brief summary of the book"></textarea>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 Add Book</button>
                        <button type="button" class="btn" onclick="closeModal('bookModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: MEMBERS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'members'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>👤 Library Members</h3>
                <button class="btn btn-green" onclick="openModal('member')">➕ Add Member</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Member Number</th>
                            <th>Name</th>
                            <th>Admission</th>
                            <th>Type</th>
                            <th>Books</th>
                            <th>Fines</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($membersData)): ?>
                            <?php foreach ($membersData as $index => $member): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><code><?php echo htmlspecialchars($member['membership_number'] ?? ''); ?></code></td>
                                <td><?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($member['admission_number'] ?? 'N/A'); ?></td>
                                <td><span class="badge"><?php echo ucfirst($member['membership_type'] ?? 'student'); ?></span></td>
                                <td><?php echo $member['total_borrowed'] ?? 0; ?></td>
                                <td>GHS <?php echo number_format($member['outstanding_fines'] ?? 0, 2); ?></td>
                                <td><span class="badge <?php echo ($member['is_active'] ?? 0) ? 'active' : 'inactive'; ?>">
                                    <?php echo ($member['is_active'] ?? 0) ? 'Active' : 'Inactive'; ?>
                                </span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="no-data">No members found. Click "Add Member" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add Member Modal -->
        <div class="modal" id="memberModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('memberModal')">&times;</span>
                <h2>👤 Add Library Member</h2>
                <form method="POST" action="">
                    <input type="hidden" name="add_membership" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="student_id">Student</label>
                            <select id="student_id" name="student_id">
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch *</label>
                            <select id="branch_id" name="branch_id" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="membership_type">Membership Type</label>
                            <select id="membership_type" name="membership_type">
                                <option value="student">Student</option>
                                <option value="staff">Staff</option>
                                <option value="teacher">Teacher</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="max_books_allowed">Max Books</label>
                            <input type="number" id="max_books_allowed" name="max_books_allowed" value="5" min="1">
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 Add Member</button>
                        <button type="button" class="btn" onclick="closeModal('memberModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: LOANS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'loans'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>📖 Book Loans</h3>
                <button class="btn btn-green" onclick="openModal('loan')">📖 Borrow Book</button>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Loan Number</th>
                            <th>Book</th>
                            <th>Member</th>
                            <th>Loan Date</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get active loans
                        $activeLoans = $db->fetchAll("
                            SELECT bl.*, b.book_title, b.book_code, bc.copy_number,
                                   s.first_name, s.last_name, s.admission_number,
                                   m.membership_number
                            FROM book_loans bl
                            JOIN book_copies bc ON bl.book_copy_id = bc.id
                            JOIN books b ON bc.book_id = b.id
                            JOIN library_memberships m ON bl.membership_id = m.id
                            LEFT JOIN students s ON m.student_id = s.id
                            WHERE bl.status IN ('borrowed', 'overdue')
                            ORDER BY bl.due_date ASC
                        ");
                        ?>
                        <?php if (!empty($activeLoans)): ?>
                            <?php foreach ($activeLoans as $index => $loan): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><code><?php echo htmlspecialchars($loan['loan_number'] ?? ''); ?></code></td>
                                <td><?php echo htmlspecialchars($loan['book_title'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? '')); ?></td>
                                <td><?php echo isset($loan['loan_date']) ? date('d M Y', strtotime($loan['loan_date'])) : '-'; ?></td>
                                <td><?php echo isset($loan['due_date']) ? date('d M Y', strtotime($loan['due_date'])) : '-'; ?></td>
                                <td><span class="badge <?php echo $loan['status'] ?? 'unknown'; ?>"><?php echo ucfirst($loan['status'] ?? 'Unknown'); ?></span></td>
                                <td>
                                    <div class="action-group">
                                        <?php if ($loan['status'] === 'borrowed' || $loan['status'] === 'overdue'): ?>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="return_book" value="1">
                                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                            <button type="submit" class="btn btn-small btn-green">✅ Return</button>
                                        </form>
                                        <a href="#" class="btn btn-small btn-warning">🔄 Renew</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="no-data">No active loans</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Borrow Book Modal -->
        <div class="modal" id="loanModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('loanModal')">&times;</span>
                <h2>📖 Borrow Book</h2>
                <form method="POST" action="">
                    <input type="hidden" name="borrow_book" value="1">
                    <div class="form-group">
                        <label for="membership_id">Member *</label>
                        <select id="membership_id" name="membership_id" required>
                            <option value="">Select Member</option>
                            <?php foreach ($membersList as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '') . ' (' . ($member['membership_number'] ?? '') . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="book_copy_id">Book Copy *</label>
                        <select id="book_copy_id" name="book_copy_id" required>
                            <option value="">Select Book</option>
                            <?php foreach ($availableCopies as $copy): ?>
                            <option value="<?php echo $copy['copy_id']; ?>">
                                <?php echo htmlspecialchars($copy['book_title'] . ' - ' . $copy['copy_number'] . ' (' . $copy['book_code'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="loan_date">Loan Date</label>
                            <input type="date" id="loan_date" name="loan_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="issued_by">Issued By</label>
                            <input type="text" id="issued_by" name="issued_by" placeholder="Staff name">
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">📖 Borrow Book</button>
                        <button type="button" class="btn" onclick="closeModal('loanModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: OVERDUE -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'overdue'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>⚠️ Overdue Books</h3>
                <span style="font-size:12px; color:#666;">Books that are past their due date</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Book</th>
                            <th>Member</th>
                            <th>Due Date</th>
                            <th>Days Overdue</th>
                            <th>Fine</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($overdueData)): ?>
                            <?php foreach ($overdueData as $index => $loan): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($loan['book_title'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? '')); ?></td>
                                <td><?php echo isset($loan['due_date']) ? date('d M Y', strtotime($loan['due_date'])) : '-'; ?></td>
                                <td><span class="badge overdue"><?php echo $loan['days_overdue'] ?? 0; ?> days</span></td>
                                <td>GHS <?php echo number_format($loan['fine_amount'] ?? 0, 2); ?></td>
                                <td>
                                    <div class="action-group">
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="return_book" value="1">
                                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                            <button type="submit" class="btn btn-small btn-green">✅ Return</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="no-data">🎉 No overdue books found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: SETTINGS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'settings'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>⚙️ Library Settings</h3>
            </div>
            <?php
            $settings = $db->fetchAll("SELECT setting_key, setting_value FROM library_settings WHERE is_active = 1");
            $settingsMap = [];
            foreach ($settings as $s) {
                $settingsMap[$s['setting_key']] = $s['setting_value'];
            }
            ?>
            <form method="POST" action="">
                <input type="hidden" name="save_settings" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="max_loans_per_student">Max Loans per Student</label>
                        <input type="number" id="max_loans_per_student" name="max_loans_per_student" value="<?php echo $settingsMap['max_loans_per_student'] ?? 5; ?>">
                    </div>
                    <div class="form-group">
                        <label for="max_loan_days">Max Loan Days</label>
                        <input type="number" id="max_loan_days" name="max_loan_days" value="<?php echo $settingsMap['max_loan_days'] ?? 14; ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="max_renewals">Max Renewals</label>
                        <input type="number" id="max_renewals" name="max_renewals" value="<?php echo $settingsMap['max_renewals'] ?? 2; ?>">
                    </div>
                    <div class="form-group">
                        <label for="fine_per_day_late">Fine per Day (GHS)</label>
                        <input type="number" id="fine_per_day_late" name="fine_per_day_late" step="0.01" value="<?php echo $settingsMap['fine_per_day_late'] ?? 0.50; ?>">
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-green">💾 Save Settings</button>
                </div>
            </form>
        </div>
        
        <?php endif; ?>
        
    </div>
    
    <script>
        function openModal(type) {
            const modals = {
                'book': 'bookModal',
                'member': 'memberModal',
                'loan': 'loanModal'
            };
            if (modals[type]) {
                document.getElementById(modals[type]).classList.add('show');
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(function(modal) {
                if (event.target == modal) {
                    modal.classList.remove('show');
                }
            });
        }
    </script>
    
</body>
</html>