<?php
require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
AdminHelper::requireLogin();
$db = DatabaseHelper::getInstance();

$action = $_GET['action'] ?? 'list';
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$editId = $_GET['edit'] ?? null;

// ============================================
// GET SCHOOL GRADE CONFIGURATION
// ============================================
$gradeConfig = $db->fetchOne("SELECT * FROM school_grade_config WHERE school_id = 1");
$terminology = $gradeConfig['terminology'] ?? 'Basic';

// ============================================
// HANDLE CLASS CRUD OPERATIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_class'])) {
    $gradeLevelId = $_POST['grade_level_id'] ?? 0;
    $academicYearId = $_POST['academic_year_id'] ?? 0;
    $sectionCode = trim($_POST['section_code'] ?? '');
    $sectionName = trim($_POST['section_name'] ?? '');
    $classTeacherId = $_POST['class_teacher_id'] ?? 0;
    $capacity = $_POST['capacity'] ?? 0;
    $editId = $_POST['edit_id'] ?? null;
    
    if ($gradeLevelId && $academicYearId && $sectionCode && $sectionName) {
        try {
            if ($editId) {
                $sql = "UPDATE class_sections SET 
                    grade_level_id = ?,
                    academic_year_id = ?,
                    section_code = ?,
                    section_name = ?,
                    class_teacher_id = ?,
                    capacity = ?,
                    updated_at = NOW()
                    WHERE id = ? AND school_id = 1";
                $db->query($sql, [$gradeLevelId, $academicYearId, $sectionCode, $sectionName, $classTeacherId, $capacity, $editId]);
                $msg = 'Class updated successfully!';
            } else {
                $sql = "INSERT INTO class_sections (
                    uuid, school_id, grade_level_id, academic_year_id, 
                    section_code, section_name, class_teacher_id, capacity, is_active
                ) VALUES (UUID(), 1, ?, ?, ?, ?, ?, ?, 1)";
                $db->query($sql, [$gradeLevelId, $academicYearId, $sectionCode, $sectionName, $classTeacherId, $capacity]);
                $msg = 'Class added successfully!';
            }
            header('Location: /admin/classes.php?msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            header('Location: /admin/classes.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/classes.php?error=Please fill in all required fields.');
        exit;
    }
}

// ============================================
// HANDLE TOGGLE STATUS
// ============================================
if (isset($_GET['toggle_class'])) {
    try {
        $classId = $_GET['toggle_class'];
        $current = $db->fetchOne("SELECT is_active FROM class_sections WHERE id = ?", [$classId]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->query("UPDATE class_sections SET is_active = ? WHERE id = ?", [$newStatus, $classId]);
        header('Location: /admin/classes.php?msg=Status updated successfully.');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/classes.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ============================================
// GET CLASS FOR EDITING
// ============================================
$editClass = null;
if ($editId) {
    $editClass = $db->fetchOne("SELECT * FROM class_sections WHERE id = ? AND school_id = 1", [$editId]);
}

// ============================================
// GET ALL DATA
// ============================================

// Get all classes with dynamic level names
$classes = [];
try {
    $classes = $db->fetchAll("
        SELECT 
            cs.*,
            gl.level_name,
            gl.section,
            gl.promotion_order,
            ay.year_name,
            CONCAT(p.first_name, ' ', p.last_name) AS teacher_name,
            (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_section_id = cs.id AND se.is_active = 1) AS student_count
        FROM class_sections cs
        JOIN grade_levels gl ON cs.grade_level_id = gl.id
        JOIN academic_years ay ON cs.academic_year_id = ay.id
        LEFT JOIN staff st ON cs.class_teacher_id = st.id
        LEFT JOIN people p ON st.person_id = p.id
        WHERE cs.school_id = 1
        ORDER BY gl.promotion_order ASC, cs.section_code ASC
    ");
} catch (Exception $e) {
    $classes = [];
}

// Get grade levels for dropdown (with dynamic naming)
$gradeLevels = $db->fetchAll("
    SELECT gl.*, sgc.terminology 
    FROM grade_levels gl
    CROSS JOIN school_grade_config sgc
    WHERE gl.school_id = 1 
    AND gl.is_active = 1 
    AND sgc.school_id = 1
    ORDER BY gl.promotion_order ASC
");

// Get academic years for dropdown
$academicYears = $db->fetchAll("SELECT id, year_name FROM academic_years WHERE school_id = 1 AND is_active = 1 ORDER BY id DESC");

// Get teachers for dropdown
$teachers = $db->fetchAll("
    SELECT st.id, CONCAT(p.first_name, ' ', p.last_name) AS name 
    FROM staff st
    JOIN people p ON st.person_id = p.id
    WHERE st.school_id = 1 AND st.is_active = 1
    ORDER BY p.first_name ASC
");

// Get the current terminology for display
$config = $db->fetchOne("SELECT terminology FROM school_grade_config WHERE school_id = 1");
$currentTerminology = $config['terminology'] ?? 'Basic';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes - Admin</title>
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
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .student-count { font-weight: bold; color: #1a3c6e; }
        .terminology-badge {
            background: #e8f0fe;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            color: #1a3c6e;
            display: inline-block;
        }
        .level-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }
        .level-preview .tag {
            background: #f0f7ff;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            border: 1px solid #dbeafe;
        }
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
            max-width: 550px;
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
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
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
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
   <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1>📚 Classes</h1>
                <p style="color: #666; font-size: 13px;">
                    Manage all classes in the school 
                    <span class="terminology-badge">Terminology: <?php echo htmlspecialchars($currentTerminology); ?></span>
                </p>
            </div>
            <button class="btn btn-green" onclick="openModal()">➕ Add Class</button>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="content-card">
            <h3>📋 All Classes</h3>
            
            <!-- Terminology Info -->
            <div style="background: #f0f7ff; padding: 10px 15px; border-radius: 5px; margin-bottom: 15px; border-left: 4px solid #1a3c6e;">
                <p style="font-size: 13px; color: #1a3c6e;">
                    <strong>💡 Current Level Terminology:</strong> 
                    <span style="background: #1a3c6e; color: #fff; padding: 2px 12px; border-radius: 12px; font-size: 13px;">
                        <?php echo htmlspecialchars($currentTerminology); ?>
                    </span>
                    — Class names are automatically generated based on this terminology.
                    <a href="/admin/academic.php?tab=gradelevels" style="color: #1a3c6e; font-weight: bold;">Change Terminology</a>
                </p>
                <div class="level-preview">
                    <span style="font-size: 12px; color: #666;">Preview:</span>
                    <?php
                    $previewLevels = $db->fetchAll("
                        SELECT level_name FROM grade_levels 
                        WHERE school_id = 1 AND is_active = 1 
                        ORDER BY promotion_order ASC LIMIT 5
                    ");
                    foreach ($previewLevels as $level) {
                        echo '<span class="tag">' . htmlspecialchars($level['level_name']) . '</span>';
                    }
                    ?>
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Class Name</th>
                            <th>Level</th>
                            <th>Academic Year</th>
                            <th>Class Teacher</th>
                            <th>Students</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($classes)): ?>
                            <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td><?php echo $class['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($class['section_name']); ?></strong>
                                        <br>
                                        <small style="color: #888; font-size: 10px;">
                                            Code: <?php echo htmlspecialchars($class['section_code']); ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($class['level_name']); ?></td>
                                    <td><?php echo htmlspecialchars($class['year_name']); ?></td>
                                    <td><?php echo htmlspecialchars($class['teacher_name'] ?? 'Not Assigned'); ?></td>
                                    <td><span class="student-count"><?php echo $class['student_count'] ?? 0; ?></span></td>
                                    <td><?php echo $class['capacity'] ?? '∞'; ?></td>
                                    <td><span class="badge <?php echo $class['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo $class['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span></td>
                                    <td>
                                        <div class="action-group">
                                            <a href="/admin/classes.php?edit=<?php echo $class['id']; ?>" class="btn btn-small">✏️</a>
                                            <a href="/admin/classes.php?toggle_class=<?php echo $class['id']; ?>" class="btn <?php echo $class['is_active'] ? 'btn-red' : 'btn-green'; ?> btn-small" onclick="return confirm('Toggle status?')">
                                                <?php echo $class['is_active'] ? '🔴' : '🟢'; ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="no-data">No classes found. Click "Add Class" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- ========================================== -->
        <!-- ADD/EDIT CLASS MODAL -->
        <!-- ========================================== -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="classModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal()">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Class' : '➕ Add Class'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_class" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="grade_level_id">Grade Level *</label>
                            <select id="grade_level_id" name="grade_level_id" required>
                                <option value="">Select Level</option>
                                <?php foreach ($gradeLevels as $level): ?>
                                    <option value="<?php echo $level['id']; ?>" <?php echo ($editClass['grade_level_id'] ?? '') == $level['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($level['level_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="academic_year_id">Academic Year *</label>
                            <select id="academic_year_id" name="academic_year_id" required>
                                <option value="">Select Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo ($editClass['academic_year_id'] ?? '') == $year['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="section_code">Section Code *</label>
                            <input type="text" id="section_code" name="section_code" value="<?php echo htmlspecialchars($editClass['section_code'] ?? ''); ?>" placeholder="A" required>
                            <small style="color: #666;">e.g., A, B, C</small>
                        </div>
                        <div class="form-group">
                            <label for="section_name">Section Name *</label>
                            <input type="text" id="section_name" name="section_name" value="<?php echo htmlspecialchars($editClass['section_name'] ?? ''); ?>" placeholder="Basic 1A" required>
                            <small style="color: #666;">e.g., <?php echo $currentTerminology; ?> 1A</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="capacity">Capacity</label>
                            <input type="number" id="capacity" name="capacity" value="<?php echo htmlspecialchars($editClass['capacity'] ?? ''); ?>" placeholder="40" min="0">
                        </div>
                        <div class="form-group">
                            <label for="class_teacher_id">Class Teacher</label>
                            <select id="class_teacher_id" name="class_teacher_id">
                                <option value="0">Not Assigned</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" <?php echo ($editClass['class_teacher_id'] ?? 0) == $teacher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Show current terminology -->
                    <div style="background: #f0f7ff; padding: 8px 12px; border-radius: 5px; margin-top: 10px; font-size: 12px; color: #666;">
                        <strong>💡 Note:</strong> Level names follow the 
                        <span style="background: #1a3c6e; color: #fff; padding: 1px 8px; border-radius: 10px; font-size: 11px;"><?php echo htmlspecialchars($currentTerminology); ?></span> 
                        terminology configured in Academic Settings.
                        <a href="/admin/academic.php?tab=gradelevels" style="color: #1a3c6e;">Change</a>
                    </div>
                    
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update Class' : 'Save Class'; ?></button>
                        <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openModal() {
            document.getElementById('classModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('classModal').classList.remove('show');
        }
        
        <?php if ($action === 'add' || $editId): ?>
            openModal();
        <?php endif; ?>
        
        window.onclick = function(event) {
            const modal = document.getElementById('classModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>