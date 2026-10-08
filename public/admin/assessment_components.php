<?php
/**
 * Assessment Components Management
 * Admin interface for creating, editing, and deleting assessment components
 */

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();

$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$editId = isset($_GET['edit']) ? intval($_GET['edit']) : null;
$deleteId = isset($_GET['delete']) ? intval($_GET['delete']) : null;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle Delete
if ($deleteId) {
    try {
        $used = $db->fetchOne("SELECT COUNT(*) as total FROM config_components WHERE component_id = ?", [$deleteId]);
        if ($used && $used['total'] > 0) {
            header('Location: /admin/assessment_components.php?error=This component is used in configurations and cannot be deleted.');
            exit;
        }
        $db->query("UPDATE assessment_components SET is_active = 0 WHERE id = ? AND school_id = 1", [$deleteId]);
        header('Location: /admin/assessment_components.php?msg=Component deleted successfully');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/assessment_components.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_component'])) {
    $componentName = trim($_POST['component_name'] ?? '');
    $componentCode = trim($_POST['component_code'] ?? '');
    $componentType = $_POST['component_type'] ?? 'custom';
    $defaultWeight = floatval($_POST['default_weight'] ?? 0);
    $maxScore = floatval($_POST['max_score'] ?? 100);
    $description = trim($_POST['description'] ?? '');
    $displayOrder = intval($_POST['display_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $editId = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : null;

    if ($componentName && $componentCode) {
        try {
            if ($editId) {
                $sql = "UPDATE assessment_components SET 
                        component_name = ?, component_code = ?, component_type = ?,
                        default_weight = ?, max_score = ?, description = ?,
                        display_order = ?, is_active = ?
                        WHERE id = ? AND school_id = 1";
                $db->query($sql, [$componentName, $componentCode, $componentType, 
                                 $defaultWeight, $maxScore, $description, 
                                 $displayOrder, $isActive, $editId]);
                $msg = 'Component updated successfully!';
            } else {
                $sql = "INSERT INTO assessment_components 
                        (uuid, school_id, component_name, component_code, component_type,
                         default_weight, max_score, description, display_order, is_active)
                        VALUES (UUID(), 1, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db->query($sql, [$componentName, $componentCode, $componentType,
                                 $defaultWeight, $maxScore, $description,
                                 $displayOrder, $isActive]);
                $msg = 'Component created successfully!';
            }
            header('Location: /admin/assessment_components.php?msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Please fill in all required fields.';
    }
}

// Get all components
$components = [];
try {
    $components = $db->fetchAll("
        SELECT *
        FROM assessment_components
        WHERE school_id = 1
        ORDER BY display_order ASC, component_name ASC
    ");
} catch (Exception $e) {
    $error = "Error loading components: " . $e->getMessage();
}

// Get component for editing
$editComponent = null;
if ($editId) {
    try {
        $editComponent = $db->fetchOne("SELECT * FROM assessment_components WHERE id = ? AND school_id = 1", [$editId]);
    } catch (Exception $e) {
        $error = "Error loading component: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Components - Admin</title>
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
            width: 250px;
            min-height: 100vh;
            background: #1a237e;
            color: #fff;
            padding: 20px 0;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar .logo { text-align: center; padding: 20px 0; font-size: 24px; font-weight: bold; border-bottom: 1px solid #283593; }
        .sidebar .logo span { color: #ffd54f; }
        .sidebar .nav-item {
            display: block;
            padding: 12px 25px;
            color: #c5cae9;
            text-decoration: none;
            transition: 0.3s;
            border-left: 3px solid transparent;
        }
        .sidebar .nav-item:hover,
        .sidebar .nav-item.active {
            background: #283593;
            color: #fff;
            border-left-color: #ffd54f;
        }
        .sidebar .nav-item i { margin-right: 10px; width: 20px; text-align: center; }
        .main-content { margin-left: 250px; padding: 30px; width: 100%; }
        .header {
            background: #fff;
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 { font-size: 24px; color: #1a237e; }
        .header h1 i { margin-right: 10px; color: #ffd54f; }
        .header p { color: #666; font-size: 14px; margin-top: 5px; }
        .header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
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
        .btn-primary { background: #1a237e; color: #fff; }
        .btn-primary:hover { background: #283593; }
        .btn-success { background: #2e7d32; color: #fff; }
        .btn-success:hover { background: #388e3c; }
        .btn-danger { background: #c62828; color: #fff; }
        .btn-danger:hover { background: #d32f2f; }
        .btn-info { background: #0d47a1; color: #fff; }
        .btn-info:hover { background: #1565c0; }
        .btn-warning { background: #f57f17; color: #fff; }
        .btn-warning:hover { background: #f9a825; }
        .btn-outline { background: transparent; border: 2px solid #1a237e; color: #1a237e; }
        .btn-outline:hover { background: #1a237e; color: #fff; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-xs { padding: 2px 8px; font-size: 11px; }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 20px;
            margin-bottom: 25px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-header h3 { color: #1a237e; font-size: 18px; }
        .card-header h3 i { margin-right: 8px; color: #ffd54f; }
        .card-header .badge-count {
            background: #e8eaf6;
            color: #1a237e;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            background: #f5f5f5;
            color: #333;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
        }
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        table tr:hover { background: #f5f5f5; }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-success { background: #e8f5e9; color: #2e7d32; }
        .badge-warning { background: #fff3e0; color: #e65100; }
        .badge-danger { background: #ffebee; color: #c62828; }
        .badge-info { background: #e3f2fd; color: #0d47a1; }
        .badge-secondary { background: #f5f5f5; color: #616161; }
        .badge-purple { background: #f3e5f5; color: #6a1b9a; }
        .message {
            padding: 12px 18px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .message-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .message-error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; font-size: 13px; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
            font-family: inherit;
        }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .form-actions { display: flex; gap: 10px; margin-top: 15px; }
        .form-actions .btn { flex: 1; text-align: center; }
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
            padding: 30px;
            border-radius: 10px;
            max-width: 600px;
            width: 90%;
            margin: 30px auto;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .modal-content .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        .modal-content .close-modal:hover { color: #333; }
        .modal-content h2 { color: #1a237e; margin-bottom: 15px; }
        .flex { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .flex-center { display: flex; justify-content: center; align-items: center; }
        .text-center { text-align: center; }
        .text-muted { color: #999; }
        .mt-10 { margin-top: 10px; }
        .mb-10 { margin-bottom: 10px; }
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-state .icon { font-size: 48px; color: #c5cae9; margin-bottom: 15px; }
        .empty-state h4 { color: #1a237e; font-size: 18px; margin-bottom: 10px; }
        .empty-state p { color: #999; max-width: 400px; margin: 0 auto 15px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: stretch; text-align: center; }
            .header .header-actions { justify-content: center; }
            table { font-size: 12px; }
            table th, table td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-cubes"></i> Assessment Components</h1>
                <p>Create and manage assessment components with flexible weightings</p>
            </div>
            <div class="header-actions">
                <button onclick="openModal()" class="btn btn-primary"><i class="fas fa-plus"></i> New Component</button>
                <a href="/admin/assessment_config.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message message-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message message-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> All Components</h3>
                <span class="badge-count"><?php echo count($components); ?> components</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Default Weight</th>
                            <th>Max Score</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($components)): ?>
                            <?php foreach ($components as $component): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($component['component_name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($component['component_code']); ?></code></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($component['component_type']); ?></span></td>
                                <td><?php echo number_format($component['default_weight'], 2); ?>%</td>
                                <td><?php echo number_format($component['max_score'], 2); ?></td>
                                <td>
                                    <?php if ($component['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/admin/assessment_components.php?edit=<?php echo $component['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                    <a href="/admin/assessment_components.php?delete=<?php echo $component['id']; ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Delete this component? This cannot be undone.')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">No components found. Click "New Component" to create one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal <?php echo ($action == 'add' || $editId) ? 'show' : ''; ?>" id="componentModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2><?php echo $editId ? '✏️ Edit Component' : '➕ Add New Component'; ?></h2>
            <form method="POST" action="">
                <input type="hidden" name="save_component" value="1">
                <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                
                <div class="form-group">
                    <label for="component_name">Component Name <span style="color: red;">*</span></label>
                    <input type="text" id="component_name" name="component_name" 
                           value="<?php echo htmlspecialchars($editComponent['component_name'] ?? ''); ?>" 
                           placeholder="e.g., Continuous Assessment 1" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="component_code">Component Code <span style="color: red;">*</span></label>
                        <input type="text" id="component_code" name="component_code" 
                               value="<?php echo htmlspecialchars($editComponent['component_code'] ?? ''); ?>" 
                               placeholder="e.g., CA1" required>
                    </div>
                    <div class="form-group">
                        <label for="component_type">Component Type</label>
                        <select id="component_type" name="component_type">
                            <option value="ca" <?php echo ($editComponent['component_type'] ?? '') == 'ca' ? 'selected' : ''; ?>>CA (Continuous Assessment)</option>
                            <option value="exam" <?php echo ($editComponent['component_type'] ?? '') == 'exam' ? 'selected' : ''; ?>>Exam</option>
                            <option value="practical" <?php echo ($editComponent['component_type'] ?? '') == 'practical' ? 'selected' : ''; ?>>Practical</option>
                            <option value="project" <?php echo ($editComponent['component_type'] ?? '') == 'project' ? 'selected' : ''; ?>>Project</option>
                            <option value="assignment" <?php echo ($editComponent['component_type'] ?? '') == 'assignment' ? 'selected' : ''; ?>>Assignment</option>
                            <option value="quiz" <?php echo ($editComponent['component_type'] ?? '') == 'quiz' ? 'selected' : ''; ?>>Quiz</option>
                            <option value="homework" <?php echo ($editComponent['component_type'] ?? '') == 'homework' ? 'selected' : ''; ?>>Homework</option>
                            <option value="test" <?php echo ($editComponent['component_type'] ?? '') == 'test' ? 'selected' : ''; ?>>Test</option>
                            <option value="custom" <?php echo ($editComponent['component_type'] ?? '') == 'custom' ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row-3">
                    <div class="form-group">
                        <label for="default_weight">Default Weight (%)</label>
                        <input type="number" id="default_weight" name="default_weight" 
                               value="<?php echo htmlspecialchars($editComponent['default_weight'] ?? 0); ?>" 
                               step="0.01" min="0" max="100">
                    </div>
                    <div class="form-group">
                        <label for="max_score">Max Score</label>
                        <input type="number" id="max_score" name="max_score" 
                               value="<?php echo htmlspecialchars($editComponent['max_score'] ?? 100); ?>" 
                               step="0.5" min="0">
                    </div>
                    <div class="form-group">
                        <label for="display_order">Display Order</label>
                        <input type="number" id="display_order" name="display_order" 
                               value="<?php echo htmlspecialchars($editComponent['display_order'] ?? 0); ?>" 
                               min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Brief description of this component"><?php echo htmlspecialchars($editComponent['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?php echo ($editComponent['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        Active
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success"><?php echo $editId ? 'Update Component' : 'Create Component'; ?></button>
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('componentModal').classList.add('show');
        }
        function closeModal() {
            document.getElementById('componentModal').classList.remove('show');
        }
        <?php if ($action == 'add' || $editId): ?>
            openModal();
        <?php endif; ?>
        window.onclick = function(event) {
            const modal = document.getElementById('componentModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>