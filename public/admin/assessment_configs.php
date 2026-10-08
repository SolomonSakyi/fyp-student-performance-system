<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();

$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$editId = isset($_GET['edit']) ? intval($_GET['edit']) : null;
$deleteId = isset($_GET['delete']) ? intval($_GET['delete']) : null;
$viewId = isset($_GET['view']) ? intval($_GET['view']) : null;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle Delete
if ($deleteId) {
    try {
        $db->query("DELETE FROM config_components WHERE config_id = ?", [$deleteId]);
        $db->query("UPDATE assessment_configs SET is_active = 0 WHERE id = ? AND school_id = 1", [$deleteId]);
        header('Location: /admin/assessment_configs.php?msg=Configuration deleted successfully');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/assessment_configs.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_config'])) {
    $configName = trim($_POST['config_name'] ?? '');
    $configCode = trim($_POST['config_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $editId = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : null;
    $components = $_POST['components'] ?? [];
    $weights = $_POST['weights'] ?? [];

    if ($configName && $configCode) {
        try {
            if ($editId) {
                if ($isDefault) {
                    $db->query("UPDATE assessment_configs SET is_default = 0 WHERE school_id = 1");
                }
                $sql = "UPDATE assessment_configs SET 
                        config_name = ?, config_code = ?, description = ?,
                        is_default = ?, is_active = ?
                        WHERE id = ? AND school_id = 1";
                $db->query($sql, [$configName, $configCode, $description, $isDefault, $isActive, $editId]);
                $db->query("DELETE FROM config_components WHERE config_id = ?", [$editId]);
                $configId = $editId;
                $msg = 'Configuration updated successfully!';
            } else {
                if ($isDefault) {
                    $db->query("UPDATE assessment_configs SET is_default = 0 WHERE school_id = 1");
                }
                $sql = "INSERT INTO assessment_configs 
                        (uuid, school_id, config_name, config_code, description, is_default, is_active)
                        VALUES (UUID(), 1, ?, ?, ?, ?, ?)";
                $db->query($sql, [$configName, $configCode, $description, $isDefault, $isActive]);
                $configId = $db->lastInsertId();
                $msg = 'Configuration created successfully!';
            }

            // Save component weights
            foreach ($components as $componentId) {
                $weight = isset($weights[$componentId]) ? floatval($weights[$componentId]) : 0;
                if ($weight > 0) {
                    $db->query("
                        INSERT INTO config_components 
                        (uuid, school_id, config_id, component_id, weight_percentage, is_required, display_order, is_active)
                        VALUES (UUID(), 1, ?, ?, ?, 1, 0, 1)
                    ", [$configId, $componentId, $weight]);
                }
            }

            header('Location: /admin/assessment_configs.php?msg=' . urlencode($msg));
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Please fill in all required fields.';
    }
}

// Get all configurations
$configs = [];
try {
    $configs = $db->fetchAll("
        SELECT 
            ac.*,
            (SELECT COUNT(*) FROM config_components cc WHERE cc.config_id = ac.id AND cc.is_active = 1) as component_count,
            (SELECT SUM(cc.weight_percentage) FROM config_components cc WHERE cc.config_id = ac.id AND cc.is_active = 1) as total_weight
        FROM assessment_configs ac
        WHERE ac.school_id = 1
        ORDER BY ac.is_default DESC, ac.config_name ASC
    ");
} catch (Exception $e) {
    $error = "Error loading configurations: " . $e->getMessage();
    $configs = [];
}

// Get all components for dropdown
$allComponents = [];
try {
    $allComponents = $db->fetchAll("
        SELECT *
        FROM assessment_components
        WHERE school_id = 1 AND is_active = 1
        ORDER BY display_order ASC, component_name ASC
    ");
} catch (Exception $e) {
    // Silently handle - may not have components yet
    $allComponents = [];
}

// Get config for editing
$editConfig = null;
$editComponents = [];
if ($editId) {
    try {
        $editConfig = $db->fetchOne("SELECT * FROM assessment_configs WHERE id = ? AND school_id = 1", [$editId]);
        if ($editConfig) {
            $editComponents = $db->fetchAll("
                SELECT cc.*, ac.component_name, ac.component_code
                FROM config_components cc
                JOIN assessment_components ac ON cc.component_id = ac.id
                WHERE cc.config_id = ? AND cc.is_active = 1
            ", [$editId]);
        }
    } catch (Exception $e) {
        $error = "Error loading config: " . $e->getMessage();
    }
}

// Get config for viewing
$viewConfig = null;
$viewComponents = [];
if ($viewId) {
    try {
        $viewConfig = $db->fetchOne("SELECT * FROM assessment_configs WHERE id = ? AND school_id = 1", [$viewId]);
        if ($viewConfig) {
            $viewComponents = $db->fetchAll("
                SELECT cc.*, ac.component_name, ac.component_code, ac.component_type, ac.max_score
                FROM config_components cc
                JOIN assessment_components ac ON cc.component_id = ac.id
                WHERE cc.config_id = ? AND cc.is_active = 1
                ORDER BY cc.display_order ASC
            ", [$viewId]);
        }
    } catch (Exception $e) {
        $error = "Error loading view: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Configurations - Admin</title>
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
        .btn-outline { background: transparent; border: 2px solid #1a237e; color: #1a237e; }
        .btn-outline:hover { background: #1a237e; color: #fff; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
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
        .badge-primary { background: #e8eaf6; color: #1a237e; }
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
        .form-actions { display: flex; gap: 10px; margin-top: 15px; }
        .form-actions .btn { flex: 1; text-align: center; }
        .component-weight {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 8px 12px;
            background: #f5f5f5;
            border-radius: 5px;
            margin-bottom: 8px;
        }
        .component-weight .name { flex: 1; font-weight: 500; }
        .component-weight .weight-input { width: 100px; }
        .component-weight .weight-input input {
            width: 100%;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
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
            padding: 30px;
            border-radius: 10px;
            max-width: 700px;
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
        .text-center { text-align: center; }
        .text-muted { color: #999; }
        .mt-10 { margin-top: 10px; }
        .mb-10 { margin-bottom: 10px; }
        .weight-summary {
            background: #e8f5e9;
            padding: 10px 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-state .icon { font-size: 48px; color: #c5cae9; margin-bottom: 15px; }
        .empty-state h4 { color: #1a237e; font-size: 18px; margin-bottom: 10px; }
        .empty-state p { color: #999; max-width: 400px; margin: 0 auto 15px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .form-row { grid-template-columns: 1fr; }
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
                <h1><i class="fas fa-layer-group"></i> Assessment Configurations</h1>
                <p>Create and manage flexible assessment configurations with custom weightings</p>
            </div>
            <div class="header-actions">
                <button onclick="openModal()" class="btn btn-primary"><i class="fas fa-plus"></i> New Config</button>
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
                <h3><i class="fas fa-list"></i> All Configurations</h3>
                <span class="badge-count"><?php echo count($configs); ?> configs</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Components</th>
                            <th>Total Weight</th>
                            <th>Default</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($configs)): ?>
                            <?php foreach ($configs as $config): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($config['config_name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($config['config_code']); ?></code></td>
                                <td><span class="badge badge-info"><?php echo $config['component_count']; ?> components</span></td>
                                <td>
                                    <span class="badge <?php echo ($config['total_weight'] ?? 0) == 100 ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo number_format($config['total_weight'] ?? 0, 2); ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php if ($config['is_default']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Default</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($config['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/admin/assessment_configs.php?view=<?php echo $config['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                                    <a href="/admin/assessment_configs.php?edit=<?php echo $config['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                    <a href="/admin/assessment_configs.php?delete=<?php echo $config['id']; ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Delete this configuration?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">No configurations found. Click "New Config" to create one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- View Config Modal -->
    <?php if ($viewConfig && $viewComponents): ?>
    <div class="modal show" id="viewModal">
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('viewModal').classList.remove('show')">&times;</span>
            <h2><?php echo htmlspecialchars($viewConfig['config_name']); ?></h2>
            <p style="color: #666; margin-bottom: 15px;"><?php echo htmlspecialchars($viewConfig['description'] ?? 'No description'); ?></p>
            
            <?php if ($viewConfig['is_default']): ?>
                <p><span class="badge badge-success"><i class="fas fa-check"></i> Default Configuration</span></p>
            <?php endif; ?>
            
            <h3 style="margin-top: 20px; margin-bottom: 10px;">📊 Components & Weightings</h3>
            <table>
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Type</th>
                        <th>Weight</th>
                        <th>Max Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $totalWeight = 0; ?>
                    <?php foreach ($viewComponents as $comp): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($comp['component_name']); ?></strong></td>
                        <td><span class="badge badge-info"><?php echo ucfirst($comp['component_type']); ?></span></td>
                        <td><?php echo number_format($comp['weight_percentage'], 2); ?>%</td>
                        <td><?php echo number_format($comp['max_score'], 2); ?></td>
                    </tr>
                    <?php $totalWeight += $comp['weight_percentage']; ?>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold; background: #f5f5f5;">
                        <td colspan="2" style="text-align: right;">Total:</td>
                        <td colspan="2">
                            <span class="badge <?php echo $totalWeight == 100 ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo number_format($totalWeight, 2); ?>%
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div style="margin-top: 15px;">
                <button onclick="document.getElementById('viewModal').classList.remove('show')" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal for Add/Edit -->
    <div class="modal <?php echo ($action == 'add' || $editId) ? 'show' : ''; ?>" id="configModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2><?php echo $editId ? '✏️ Edit Configuration' : '➕ New Assessment Configuration'; ?></h2>
            <form method="POST" action="" id="configForm">
                <input type="hidden" name="save_config" value="1">
                <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="config_name">Config Name <span style="color: red;">*</span></label>
                        <input type="text" id="config_name" name="config_name" 
                               value="<?php echo htmlspecialchars($editConfig['config_name'] ?? ''); ?>" 
                               placeholder="e.g., Standard Assessment" required>
                    </div>
                    <div class="form-group">
                        <label for="config_code">Config Code <span style="color: red;">*</span></label>
                        <input type="text" id="config_code" name="config_code" 
                               value="<?php echo htmlspecialchars($editConfig['config_code'] ?? ''); ?>" 
                               placeholder="e.g., STANDARD" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Brief description of this configuration"><?php echo htmlspecialchars($editConfig['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_default" value="1" <?php echo ($editConfig['is_default'] ?? 0) ? 'checked' : ''; ?>>
                            Set as Default
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_active" value="1" <?php echo ($editConfig['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            Active
                        </label>
                    </div>
                </div>
                
                <h3 style="margin-top: 20px; margin-bottom: 10px;">📊 Component Weightings</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 10px;">Assign weight percentages to each component. Total must equal 100%.</p>
                
                <?php if (!empty($allComponents)): ?>
                    <div id="component-list">
                        <?php foreach ($allComponents as $component): 
                            $existingWeight = 0;
                            foreach ($editComponents as $ec) {
                                if ($ec['component_id'] == $component['id']) {
                                    $existingWeight = $ec['weight_percentage'];
                                    break;
                                }
                            }
                        ?>
                        <div class="component-weight">
                            <div class="name">
                                <?php echo htmlspecialchars($component['component_name']); ?>
                                <span style="color: #999; font-weight: normal;">(<?php echo htmlspecialchars($component['component_code']); ?>)</span>
                            </div>
                            <div class="weight-input">
                                <input type="number" 
                                       name="weights[<?php echo $component['id']; ?>]" 
                                       value="<?php echo number_format($existingWeight, 2); ?>"
                                       step="0.01" min="0" max="100"
                                       placeholder="0.00"
                                       class="weight-field"
                                       data-component-id="<?php echo $component['id']; ?>">
                                <input type="hidden" name="components[]" value="<?php echo $component['id']; ?>">
                            </div>
                            <span style="font-size: 12px; color: #666; width: 30px;">%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon"><i class="fas fa-cubes"></i></div>
                        <h4>No Components Available</h4>
                        <p>Please create assessment components first before creating a configuration.</p>
                        <a href="/admin/assessment_components.php?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Create Component</a>
                    </div>
                <?php endif; ?>
                
                <div id="weight-summary" class="weight-summary">
                    <strong>Total Weight:</strong> <span id="total-weight-display">0.00</span>%
                    <span id="weight-status"></span>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success" id="submitBtn"><?php echo $editId ? 'Update Config' : 'Create Config'; ?></button>
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const weightFields = document.querySelectorAll('.weight-field');
            const totalDisplay = document.getElementById('total-weight-display');
            const weightStatus = document.getElementById('weight-status');
            const submitBtn = document.getElementById('submitBtn');
            
            function calculateTotal() {
                let total = 0;
                weightFields.forEach(function(field) {
                    const val = parseFloat(field.value) || 0;
                    total += val;
                });
                
                totalDisplay.textContent = total.toFixed(2);
                
                if (Math.abs(total - 100) < 0.01) {
                    weightStatus.innerHTML = ' ✅ Perfect!';
                    weightStatus.style.color = '#2e7d32';
                    if (submitBtn) submitBtn.disabled = false;
                } else if (total > 100) {
                    weightStatus.innerHTML = ' ⚠️ Exceeds 100%!';
                    weightStatus.style.color = '#c62828';
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    weightStatus.innerHTML = ` ⚠️ ${(100 - total).toFixed(2)}% remaining`;
                    weightStatus.style.color = '#f57f17';
                    if (submitBtn) submitBtn.disabled = false;
                }
            }
            
            weightFields.forEach(function(field) {
                field.addEventListener('input', calculateTotal);
            });
            
            calculateTotal();
        });
        
        function openModal() {
            document.getElementById('configModal').classList.add('show');
        }
        function closeModal() {
            document.getElementById('configModal').classList.remove('show');
        }
        <?php if ($action == 'add' || $editId): ?>
            openModal();
        <?php endif; ?>
        window.onclick = function(event) {
            const modal = document.getElementById('configModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>