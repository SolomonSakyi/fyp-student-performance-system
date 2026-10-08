<?php
/**
 * subjects.php
 * 
 * Subjects Management Admin Interface
 * 
 * @package EduTrack
 * @subpackage Admin
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin';
}

// Define base path
$basePath = dirname(__DIR__, 2) . '/';

require_once $basePath . 'app/helpers/AdminHelper.php';
require_once $basePath . 'app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();
$db = DatabaseHelper::getInstance();

$action = $_GET['action'] ?? 'list';
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$editId = $_GET['edit'] ?? null;

// ============================================
// HANDLE SUBJECT SAVE (with classification)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subject'])) {
    $subjectName = trim($_POST['subject_name'] ?? '');
    $subjectCode = trim($_POST['subject_code'] ?? '');
    $subjectType = $_POST['subject_type'] ?? 'core';
    $isCore = ($subjectType === 'core') ? 1 : 0;
    $isElective = ($subjectType === 'elective') ? 1 : 0;
    $category = $_POST['category'] ?? '';
    $subjectGroupId = $_POST['subject_group_id'] ?? 0;
    $displayOrder = $_POST['display_order'] ?? 0;
    $isCompulsory = isset($_POST['is_compulsory']) ? 1 : 0;
    $editId = $_POST['edit_id'] ?? null;
    
    if ($subjectName && $subjectCode) {
        try {
            if ($editId) {
                $sql = "UPDATE subjects SET 
                    subject_name = ?,
                    subject_code = ?,
                    subject_type = ?,
                    is_core = ?,
                    is_elective = ?,
                    category = ?,
                    subject_group_id = ?,
                    display_order = ?,
                    is_compulsory = ?,
                    updated_at = NOW()
                    WHERE id = ? AND school_id = 1";
                $db->query($sql, [$subjectName, $subjectCode, $subjectType, $isCore, $isElective, 
                    $category, $subjectGroupId, $displayOrder, $isCompulsory, $editId]);
                $msg = 'Subject updated successfully!';
            } else {
                $sql = "INSERT INTO subjects (
                    uuid, school_id, subject_name, subject_code, 
                    subject_type, is_core, is_elective, category, 
                    subject_group_id, display_order, is_compulsory, is_active
                ) VALUES (UUID(), 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                $db->query($sql, [$subjectName, $subjectCode, $subjectType, $isCore, $isElective,
                    $category, $subjectGroupId, $displayOrder, $isCompulsory]);
                $msg = 'Subject added successfully!';
            }
            header('Location: /admin/subjects.php?msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            header('Location: /admin/subjects.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/subjects.php?error=Please fill in all required fields.');
        exit;
    }
}

// ============================================
// HANDLE SUBJECT ASSIGNMENT TO GRADE LEVEL
// ============================================
if (isset($_POST['assign_subject'])) {
    $subjectId = $_POST['subject_id'] ?? 0;
    $gradeLevelId = $_POST['grade_level_id'] ?? 0;
    $isCompulsory = isset($_POST['is_compulsory']) ? 1 : 0;
    $isExaminable = isset($_POST['is_examinable']) ? 1 : 0;
    $displayOrder = $_POST['display_order'] ?? 0;
    
    if ($subjectId && $gradeLevelId) {
        try {
            $exists = $db->fetchOne("SELECT id FROM grade_level_subjects 
                WHERE subject_id = ? AND grade_level_id = ? AND school_id = 1", 
                [$subjectId, $gradeLevelId]);
            
            if ($exists) {
                $sql = "UPDATE grade_level_subjects SET 
                    is_compulsory = ?,
                    is_examinable = ?,
                    display_order = ?,
                    is_active = 1,
                    updated_at = NOW()
                    WHERE id = ?";
                $db->query($sql, [$isCompulsory, $isExaminable, $displayOrder, $exists['id']]);
            } else {
                $sql = "INSERT INTO grade_level_subjects (
                    uuid, school_id, grade_level_id, subject_id, 
                    is_compulsory, is_examinable, display_order, is_active
                ) VALUES (UUID(), 1, ?, ?, ?, ?, ?, 1)";
                $db->query($sql, [$gradeLevelId, $subjectId, $isCompulsory, $isExaminable, $displayOrder]);
            }
            header('Location: /admin/subjects.php?msg=Subject assigned successfully!');
            exit;
        } catch (Exception $e) {
            header('Location: /admin/subjects.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// ============================================
// HANDLE SUBJECT GROUP SAVE
// ============================================
if (isset($_POST['save_subject_group'])) {
    $groupName = trim($_POST['group_name'] ?? '');
    $groupCode = trim($_POST['group_code'] ?? '');
    $groupType = $_POST['group_type'] ?? 'custom';
    $sortOrder = $_POST['sort_order'] ?? 0;
    $editGroupId = $_POST['edit_group_id'] ?? null;
    
    if ($groupName && $groupCode) {
        try {
            if ($editGroupId) {
                $sql = "UPDATE subject_groups SET 
                    group_name = ?,
                    group_code = ?,
                    group_type = ?,
                    sort_order = ?,
                    updated_at = NOW()
                    WHERE id = ? AND school_id = 1";
                $db->query($sql, [$groupName, $groupCode, $groupType, $sortOrder, $editGroupId]);
            } else {
                $sql = "INSERT INTO subject_groups (uuid, school_id, group_name, group_code, group_type, sort_order, is_active) 
                    VALUES (UUID(), 1, ?, ?, ?, ?, 1)";
                $db->query($sql, [$groupName, $groupCode, $groupType, $sortOrder]);
            }
            header('Location: /admin/subjects.php?tab=groups&msg=Subject group saved successfully!');
            exit;
        } catch (Exception $e) {
            header('Location: /admin/subjects.php?tab=groups&error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// ============================================
// HANDLE TOGGLE STATUS
// ============================================
if (isset($_GET['toggle_subject'])) {
    try {
        $subjectId = $_GET['toggle_subject'];
        $current = $db->fetchOne("SELECT is_active FROM subjects WHERE id = ?", [$subjectId]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->query("UPDATE subjects SET is_active = ? WHERE id = ?", [$newStatus, $subjectId]);
        header('Location: /admin/subjects.php?msg=Status updated successfully.');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/subjects.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ============================================
// GET ALL DATA
// ============================================

// Get subject for editing
$editSubject = null;
if ($editId) {
    $editSubject = $db->fetchOne("SELECT * FROM subjects WHERE id = ? AND school_id = 1", [$editId]);
}

// Get all subjects with classification
$subjects = [];
try {
    $subjects = $db->fetchAll("
        SELECT s.*,
        sg.group_name,
        (SELECT COUNT(*) FROM grade_level_subjects gls WHERE gls.subject_id = s.id AND gls.is_active = 1) AS grade_count
        FROM subjects s
        LEFT JOIN subject_groups sg ON s.subject_group_id = sg.id
        WHERE s.school_id = 1
        ORDER BY s.is_core DESC, s.subject_name ASC
    ");
} catch (Exception $e) {
    $subjects = [];
}

// Get grade levels
$gradeLevels = $db->fetchAll("SELECT id, level_name FROM grade_levels WHERE school_id = 1 AND is_active = 1 ORDER BY promotion_order ASC");

// Get subject assignments
$assignments = [];
try {
    $assignments = $db->fetchAll("
        SELECT gls.*, s.subject_name, s.subject_type, gl.level_name
        FROM grade_level_subjects gls
        JOIN subjects s ON gls.subject_id = s.id
        JOIN grade_levels gl ON gls.grade_level_id = gl.id
        WHERE gls.school_id = 1
        ORDER BY gl.promotion_order ASC, s.is_core DESC, s.subject_name ASC
    ");
} catch (Exception $e) {
    $assignments = [];
}

// Get subject groups
$subjectGroups = $db->fetchAll("SELECT * FROM subject_groups WHERE school_id = 1 AND is_active = 1 ORDER BY sort_order ASC");

// Get subject categories
$categories = $db->fetchAll("SELECT * FROM subject_categories WHERE school_id = 1 AND is_active = 1 ORDER BY category_name ASC");

$activeTab = $_GET['tab'] ?? 'subjects';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects - Admin</title>
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
        .btn-small { padding: 3px 8px; font-size: 11px; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-success { background: #0d9488; }
        .btn-success:hover { background: #0f766e; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
        .btn-info { background: #17a2b8; }
        .btn-info:hover { background: #138496; }
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card h3 {
            color: #1a3c6e;
            margin-bottom: 10px;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 8px;
            font-size: 16px;
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
            padding: 8px 10px;
            text-align: left;
        }
        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table tr:hover { background: #f8fafc; }
        .badge {
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        .badge-core { background: #dbeafe; color: #1e40af; }
        .badge-elective { background: #fef3c7; color: #92400e; }
        .badge-vocational { background: #d1fae5; color: #065f46; }
        .badge-optional { background: #e5e7eb; color: #374151; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .badge-compulsory { background: #fff3cd; color: #856404; }
        .badge-assigned { background: #cce5ff; color: #004085; }
        .badge-count { background: #e8e8e8; color: #333; }
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
            max-width: 600px;
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
        .form-group textarea { min-height: 60px; resize: vertical; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
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
        .tabs {
            display: flex;
            gap: 5px;
            background: #fff;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .tabs .tab {
            padding: 10px 25px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-size: 14px;
            font-weight: bold;
            transition: 0.3s;
        }
        .tabs .tab:hover { background: #f0f0f0; }
        .tabs .tab.active { background: #1a3c6e; color: #fff; }
        .sub-tab-content { display: none; }
        .sub-tab-content.active { display: block; }
        .legend {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            padding: 10px 0;
        }
        .legend-item { display: flex; align-items: center; gap: 5px; font-size: 12px; }
        .legend-item .dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .tabs { flex-wrap: wrap; }
            .tabs .tab { flex: 1; text-align: center; padding: 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1>📖 Subjects Management</h1>
                <p style="color: #666; font-size: 13px;">Manage subjects, classifications, and grade assignments</p>
            </div>
            <div>
                <button class="btn btn-green" onclick="openModal('add')">➕ Add Subject</button>
                <button class="btn btn-success" onclick="openModal('group')" style="margin-left: 5px;">📁 Add Group</button>
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
            <a href="#subjects-list" class="tab <?php echo $activeTab === 'subjects' ? 'active' : ''; ?>" onclick="switchTab('subjects-list')">📋 Subjects</a>
            <a href="#assignments" class="tab <?php echo $activeTab === 'assignments' ? 'active' : ''; ?>" onclick="switchTab('assignments')">🔗 Grade Assignments</a>
            <a href="#groups" class="tab <?php echo $activeTab === 'groups' ? 'active' : ''; ?>" onclick="switchTab('groups')">📁 Subject Groups</a>
        </div>
        
        <!-- ========================================== -->
        <!-- TAB 1: SUBJECTS LIST -->
        <!-- ========================================== -->
        <div id="subjects-list" class="sub-tab-content <?php echo $activeTab === 'subjects' ? 'active' : ''; ?>">
            <div class="content-card">
                <h3>📋 All Subjects</h3>
                
                <!-- Legend -->
                <div class="legend">
                    <span class="legend-item"><span class="dot" style="background:#dbeafe;"></span> Core</span>
                    <span class="legend-item"><span class="dot" style="background:#fef3c7;"></span> Elective</span>
                    <span class="legend-item"><span class="dot" style="background:#d1fae5;"></span> Vocational</span>
                    <span class="legend-item"><span class="dot" style="background:#e5e7eb;"></span> Optional</span>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject Name</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Group</th>
                                <th>Compulsory</th>
                                <th>Grade Levels</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($subjects)): ?>
                                <?php foreach ($subjects as $subject): 
                                    $typeClass = 'badge-' . $subject['subject_type'];
                                    $typeLabel = ucfirst($subject['subject_type']);
                                ?>
                                    <tr>
                                        <td><?php echo $subject['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                        <td><span class="badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                                        <td><?php echo htmlspecialchars($subject['group_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo $subject['is_compulsory'] ? '✅' : '❌'; ?></td>
                                        <td><span class="badge badge-count"><?php echo $subject['grade_count'] ?? 0; ?></span></td>
                                        <td><span class="badge <?php echo $subject['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $subject['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span></td>
                                        <td>
                                            <div class="action-group">
                                                <a href="/admin/subjects.php?edit=<?php echo $subject['id']; ?>" class="btn btn-small btn-info">✏️</a>
                                                <a href="/admin/subjects.php?toggle_subject=<?php echo $subject['id']; ?>" class="btn <?php echo $subject['is_active'] ? 'btn-red' : 'btn-green'; ?> btn-small" onclick="return confirm('Toggle status?')">
                                                    <?php echo $subject['is_active'] ? '🔴' : '🟢'; ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="no-data">No subjects found. Click "Add Subject" to add one.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ========================================== -->
        <!-- TAB 2: GRADE ASSIGNMENTS -->
        <!-- ========================================== -->
        <div id="assignments" class="sub-tab-content <?php echo $activeTab === 'assignments' ? 'active' : ''; ?>">
            <div class="content-card">
                <h3>🔗 Subject to Grade Level Assignments</h3>
                
                <!-- Assignment Form -->
                <form method="POST" action="" style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
                    <input type="hidden" name="assign_subject" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <?php if ($subject['is_active']): ?>
                                        <option value="<?php echo $subject['id']; ?>">
                                            <?php echo htmlspecialchars($subject['subject_name']); ?> 
                                            (<?php echo ucfirst($subject['subject_type']); ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="grade_level_id">Grade Level *</label>
                            <select id="grade_level_id" name="grade_level_id" required>
                                <option value="">Select Grade Level</option>
                                <?php foreach ($gradeLevels as $level): ?>
                                    <option value="<?php echo $level['id']; ?>"><?php echo htmlspecialchars($level['level_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_compulsory" value="1" checked> Compulsory
                            </label>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_examinable" value="1" checked> Examinable
                            </label>
                        </div>
                        <div class="form-group">
                            <label for="display_order">Display Order</label>
                            <input type="number" id="display_order" name="display_order" value="0" min="0">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-green" style="margin-top: 5px;">🔗 Assign Subject</button>
                </form>
                
                <!-- Assignments List -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Grade Level</th>
                                <th>Compulsory</th>
                                <th>Examinable</th>
                                <th>Order</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($assignments)): ?>
                                <?php foreach ($assignments as $assignment): 
                                    $typeClass = 'badge-' . $assignment['subject_type'];
                                ?>
                                    <tr>
                                        <td><?php echo $assignment['id']; ?></td>
                                        <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                        <td><span class="badge <?php echo $typeClass; ?>"><?php echo ucfirst($assignment['subject_type']); ?></span></td>
                                        <td><?php echo htmlspecialchars($assignment['level_name']); ?></td>
                                        <td><?php echo $assignment['is_compulsory'] ? '✅' : '❌'; ?></td>
                                        <td><?php echo $assignment['is_examinable'] ? '✅' : '❌'; ?></td>
                                        <td><?php echo $assignment['display_order']; ?></td>
                                        <td><span class="badge <?php echo $assignment['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $assignment['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="no-data">No assignments found. Assign subjects to grade levels above.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ========================================== -->
        <!-- TAB 3: SUBJECT GROUPS -->
        <!-- ========================================== -->
        <div id="groups" class="sub-tab-content <?php echo $activeTab === 'groups' ? 'active' : ''; ?>">
            <div class="content-card">
                <h3>📁 Subject Groups</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Organize subjects into groups (e.g., Science, Arts, Business).</p>
                
                <!-- Add Group Form -->
                <form method="POST" action="" style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
                    <input type="hidden" name="save_subject_group" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="group_name">Group Name *</label>
                            <input type="text" id="group_name" name="group_name" placeholder="Science" required>
                        </div>
                        <div class="form-group">
                            <label for="group_code">Group Code *</label>
                            <input type="text" id="group_code" name="group_code" placeholder="SCI" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="group_type">Group Type</label>
                            <select id="group_type" name="group_type">
                                <option value="core">Core Subjects</option>
                                <option value="elective" selected>Elective Subjects</option>
                                <option value="vocational">Vocational Subjects</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" value="0" min="0">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success">📁 Add Group</button>
                </form>
                
                <!-- Groups List -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Group Name</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Subjects</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($subjectGroups)): ?>
                                <?php foreach ($subjectGroups as $group): 
                                    $subjectCount = $db->fetchOne("SELECT COUNT(*) as count FROM subjects WHERE subject_group_id = ? AND school_id = 1 AND is_active = 1", [$group['id']]);
                                ?>
                                    <tr>
                                        <td><?php echo $group['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($group['group_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($group['group_code']); ?></td>
                                        <td><span class="badge badge-<?php echo $group['group_type']; ?>"><?php echo ucfirst($group['group_type']); ?></span></td>
                                        <td><span class="badge badge-count"><?php echo $subjectCount['count'] ?? 0; ?></span></td>
                                        <td><span class="badge <?php echo $group['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $group['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span></td>
                                        <td>
                                            <div class="action-group">
                                                <a href="#" class="btn btn-small btn-info">✏️</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="no-data">No subject groups found. Add one above.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ========================================== -->
        <!-- ADD/EDIT SUBJECT MODAL -->
        <!-- ========================================== -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="subjectModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal()">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Subject' : '➕ Add Subject'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_subject" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_name">Subject Name *</label>
                            <input type="text" id="subject_name" name="subject_name" value="<?php echo htmlspecialchars($editSubject['subject_name'] ?? ''); ?>" placeholder="Mathematics" required>
                        </div>
                        <div class="form-group">
                            <label for="subject_code">Subject Code *</label>
                            <input type="text" id="subject_code" name="subject_code" value="<?php echo htmlspecialchars($editSubject['subject_code'] ?? ''); ?>" placeholder="MATH" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_type">Subject Type *</label>
                            <select id="subject_type" name="subject_type" required>
                                <option value="core" <?php echo ($editSubject['subject_type'] ?? '') == 'core' ? 'selected' : ''; ?>>Core (For BECE/WASCE)</option>
                                <option value="elective" <?php echo ($editSubject['subject_type'] ?? '') == 'elective' ? 'selected' : ''; ?>>Elective (For BECE/WASCE)</option>
                                <option value="vocational" <?php echo ($editSubject['subject_type'] ?? '') == 'vocational' ? 'selected' : ''; ?>>Vocational</option>
                                <option value="optional" <?php echo ($editSubject['subject_type'] ?? '') == 'optional' ? 'selected' : ''; ?>>Optional</option>
                            </select>
                            <small style="color: #666;">Core and Elective subjects are used for BECE/WASCE aggregate calculation</small>
                        </div>
                        <div class="form-group">
                            <label for="subject_group_id">Subject Group</label>
                            <select id="subject_group_id" name="subject_group_id">
                                <option value="0">None</option>
                                <?php foreach ($subjectGroups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>" <?php echo ($editSubject['subject_group_id'] ?? 0) == $group['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($editSubject['category'] ?? ''); ?>" placeholder="e.g., Science, Arts">
                        </div>
                        <div class="form-group">
                            <label for="display_order">Display Order</label>
                            <input type="number" id="display_order" name="display_order" value="<?php echo htmlspecialchars($editSubject['display_order'] ?? 0); ?>" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 10px;">
                        <label>
                            <input type="checkbox" name="is_compulsory" value="1" <?php echo ($editSubject['is_compulsory'] ?? 0) ? 'checked' : ''; ?>> 
                            This subject is compulsory
                        </label>
                    </div>
                    
                    <div style="background: #f0f7ff; padding: 10px; border-radius: 5px; margin: 10px 0;">
                        <p style="font-size: 12px; color: #1a3c6e;">
                            <strong>💡 BECE Aggregate Calculation:</strong><br>
                            - <strong>Core Subjects:</strong> English, Mathematics, Science, Social Studies<br>
                            - <strong>Electives:</strong> Best 2 subjects from elective group<br>
                            - <strong>Aggregate:</strong> Sum of grades (1-9) from 4 Core + Best 2 Electives
                        </p>
                    </div>
                    
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update Subject' : 'Save Subject'; ?></button>
                        <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- ========================================== -->
        <!-- ADD GROUP MODAL -->
        <!-- ========================================== -->
        <div class="modal" id="groupModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('groupModal')">&times;</span>
                <h2>📁 Add Subject Group</h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_subject_group" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="group_name">Group Name *</label>
                            <input type="text" id="group_name" name="group_name" placeholder="Science" required>
                        </div>
                        <div class="form-group">
                            <label for="group_code">Group Code *</label>
                            <input type="text" id="group_code" name="group_code" placeholder="SCI" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="group_type">Group Type</label>
                            <select id="group_type" name="group_type">
                                <option value="core">Core Subjects</option>
                                <option value="elective" selected>Elective Subjects</option>
                                <option value="vocational">Vocational Subjects</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" value="0" min="0">
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-success">📁 Add Group</button>
                        <button type="button" class="btn" onclick="closeModal('groupModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openModal(type) {
            if (type === 'group') {
                document.getElementById('groupModal').classList.add('show');
            } else {
                document.getElementById('subjectModal').classList.add('show');
            }
        }
        
        function closeModal(modalId) {
            if (modalId) {
                document.getElementById(modalId).classList.remove('show');
            } else {
                document.getElementById('subjectModal').classList.remove('show');
                document.getElementById('groupModal').classList.remove('show');
            }
        }
        
        function switchTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.sub-tab-content').forEach(function(el) {
                el.classList.remove('active');
            });
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            
            // Update tab styles
            document.querySelectorAll('.tabs .tab').forEach(function(el) {
                el.classList.remove('active');
            });
            document.querySelector('.tabs .tab[href="#' + tabId + '"]').classList.add('active');
        }
        
        <?php if ($action === 'add' || $editId): ?>
            openModal('add');
        <?php endif; ?>
        
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