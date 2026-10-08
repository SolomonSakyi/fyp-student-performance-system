<?php
require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/helpers/NumberingHelper.php';
AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();
$numbering = new NumberingHelper($db);
$config = $numbering->getConfig();

$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// Handle configuration update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_numbering'])) {
    $data = [
        'student_prefix' => trim($_POST['student_prefix'] ?? 'CCI'),
        'student_separator' => trim($_POST['student_separator'] ?? '/'),
        'student_padding_length' => (int)($_POST['student_padding_length'] ?? 4),
        'student_include_year' => isset($_POST['student_include_year']) ? 1 : 0,
        'staff_prefix' => trim($_POST['staff_prefix'] ?? 'STF'),
        'staff_separator' => trim($_POST['staff_separator'] ?? '/'),
        'staff_padding_length' => (int)($_POST['staff_padding_length'] ?? 4),
        'staff_include_year' => isset($_POST['staff_include_year']) ? 1 : 0,
    ];
    
    try {
        $numbering->updateConfig($data);
        header('Location: /admin/numbering.php?msg=Numbering configuration updated successfully!');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/numbering.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Get next numbers preview
$studentPreview = $numbering->peekNextStudentNumber();
$staffPreview = $numbering->peekNextStaffNumber();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Numbering Configuration - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            padding: 0;
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
        .content-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card h3 {
            color: #1a3c6e;
            margin-bottom: 15px;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 10px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .btn-row .btn { flex: 1; text-align: center; }
        .message {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .preview-box {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #1a3c6e;
            margin-top: 15px;
        }
        .preview-box .label { font-weight: bold; color: #1a3c6e; }
        .preview-box .number { font-size: 18px; font-weight: bold; color: #1a3c6e; font-family: monospace; }
        .info-box {
            background: #fef3c7;
            padding: 10px 15px;
            border-radius: 5px;
            border-left: 4px solid #f59e0b;
            margin: 15px 0;
        }
        .info-box p { font-size: 13px; color: #92400e; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">📊 Edu<span>Track</span></div>
        <a href="/admin/index.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
        <a href="/admin/students.php" class="nav-item"><span class="icon">👨‍🎓</span> Students</a>
        <a href="/admin/teachers.php" class="nav-item"><span class="icon">👨‍🏫</span> Teachers</a>
        <a href="/admin/classes.php" class="nav-item"><span class="icon">📚</span> Classes</a>
        <a href="/admin/subjects.php" class="nav-item"><span class="icon">📖</span> Subjects</a>
        <a href="/admin/academic.php" class="nav-item"><span class="icon">📅</span> Academic</a>
        <a href="/admin/fees.php" class="nav-item"><span class="icon">💰</span> Fees</a>
        <a href="/admin/reports.php" class="nav-item"><span class="icon">📄</span> Reports</a>
        <a href="/admin/users.php" class="nav-item"><span class="icon">👤</span> Users</a>
        <a href="/admin/numbering.php" class="nav-item active"><span class="icon">🔢</span> Numbering</a>
        <a href="/admin/settings.php" class="nav-item"><span class="icon">⚙️</span> Settings</a>
        <a href="/admin/logout.php" class="nav-item" style="color: #fca5a5;"><span class="icon">🚪</span> Logout</a>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1>🔢 Numbering Configuration</h1>
            <p style="color: #666; font-size: 13px;">Configure how student admission numbers and staff numbers are generated</p>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Preview Current Settings -->
        <div class="content-card">
            <h3>📊 Current Number Preview</h3>
            <div class="form-row-2">
                <div class="preview-box">
                    <div class="label">🎓 Next Student Admission Number</div>
                    <div class="number"><?php echo htmlspecialchars($studentPreview); ?></div>
                </div>
                <div class="preview-box">
                    <div class="label">👨‍🏫 Next Staff Number</div>
                    <div class="number"><?php echo htmlspecialchars($staffPreview); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Configuration Form -->
        <div class="content-card">
            <h3>⚙️ Numbering Settings</h3>
            <form method="POST" action="">
                <input type="hidden" name="save_numbering" value="1">
                
                <h4 style="color: #1a3c6e; margin: 15px 0 10px;">🎓 Student Admission Number</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="student_prefix">Prefix</label>
                        <input type="text" id="student_prefix" name="student_prefix" value="<?php echo htmlspecialchars($config['student_prefix']); ?>" placeholder="CCI">
                        <small style="color: #666;">e.g., CCI, GHS, SHS</small>
                    </div>
                    <div class="form-group">
                        <label for="student_separator">Separator</label>
                        <input type="text" id="student_separator" name="student_separator" value="<?php echo htmlspecialchars($config['student_separator']); ?>" placeholder="/" maxlength="5">
                        <small style="color: #666;">e.g., /, -, .</small>
                    </div>
                    <div class="form-group">
                        <label for="student_padding_length">Padding Length</label>
                        <input type="number" id="student_padding_length" name="student_padding_length" value="<?php echo $config['student_padding_length']; ?>" min="1" max="10">
                        <small style="color: #666;">Number of digits (e.g., 4 = 0001)</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="student_include_year" value="1" <?php echo $config['student_include_year'] ? 'checked' : ''; ?>>
                        Include Year (e.g., CCI/2026/0001)
                    </label>
                </div>
                
                <h4 style="color: #1a3c6e; margin: 20px 0 10px;">👨‍🏫 Staff Number</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label for="staff_prefix">Prefix</label>
                        <input type="text" id="staff_prefix" name="staff_prefix" value="<?php echo htmlspecialchars($config['staff_prefix']); ?>" placeholder="STF">
                        <small style="color: #666;">e.g., STF, EMP, TCH</small>
                    </div>
                    <div class="form-group">
                        <label for="staff_separator">Separator</label>
                        <input type="text" id="staff_separator" name="staff_separator" value="<?php echo htmlspecialchars($config['staff_separator']); ?>" placeholder="/" maxlength="5">
                        <small style="color: #666;">e.g., /, -, .</small>
                    </div>
                    <div class="form-group">
                        <label for="staff_padding_length">Padding Length</label>
                        <input type="number" id="staff_padding_length" name="staff_padding_length" value="<?php echo $config['staff_padding_length']; ?>" min="1" max="10">
                        <small style="color: #666;">Number of digits (e.g., 4 = 0001)</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="staff_include_year" value="1" <?php echo $config['staff_include_year'] ? 'checked' : ''; ?>>
                        Include Year (e.g., STF/2026/0001)
                    </label>
                </div>
                
                <div class="info-box">
                    <p>
                        <strong>⚠️ Note:</strong> Changing these settings will affect ALL future numbers. 
                        Current numbers are set to: Student: <?php echo $config['student_current_number']; ?>, Staff: <?php echo $config['staff_current_number']; ?>
                    </p>
                </div>
                
                <div class="btn-row">
                    <button type="submit" class="btn btn-green">💾 Save Configuration</button>
                    <a href="/admin/index.php" class="btn">← Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>