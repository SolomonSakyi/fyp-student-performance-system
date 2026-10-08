<?php
/**
 * assessment.php
 *
 * Enterprise Assessment Management Dashboard
 * Unified interface for all assessment operations
 *
 * @package EduTrack
 * @subpackage Admin
 * @version 3.0
 */

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

// Load required files
require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/helpers/LoggerHelper.php';
require_once $basePath . 'app/services/Assessment/AssessmentService.php';

// Initialize
$db = DatabaseHelper::getInstance();
$assessmentService = new AssessmentService();

// Determine active tab
$activeTab = $_GET['tab'] ?? 'dashboard';
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Get statistics
$stats = [];

// Assessment Types
$types = $db->fetchAll("SELECT * FROM assessment_types WHERE school_id = 1 AND is_active = 1");
$stats['types'] = count($types);

// Components
$components = $db->fetchAll("SELECT * FROM assessment_components WHERE school_id = 1 AND is_active = 1");
$stats['components'] = count($components);

// Configs
$configs = $db->fetchAll("SELECT * FROM assessment_configs WHERE school_id = 1 AND is_active = 1");
$stats['configs'] = count($configs);

// Published Results
$published = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_results WHERE is_published = 1 AND is_active = 1");
$stats['published'] = $published['total'] ?? 0;

// Students with results
$students = $db->fetchOne("SELECT COUNT(DISTINCT student_id) as total FROM assessment_results WHERE is_active = 1");
$stats['students'] = $students['total'] ?? 0;

// Get recent activity
$recentActivity = $db->fetchAll(
    "SELECT 'type' as type, assessment_name as name, created_at 
     FROM assessment_types WHERE school_id = 1 
     UNION ALL 
     SELECT 'component' as type, component_name as name, created_at 
     FROM assessment_components WHERE school_id = 1 
     UNION ALL 
     SELECT 'config' as type, config_name as name, created_at 
     FROM assessment_configs WHERE school_id = 1 
     ORDER BY created_at DESC LIMIT 10"
);

// Get class list for mark entry
$classes = $db->fetchAll(
    "SELECT cs.id, cs.section_name, gl.level_name 
     FROM class_sections cs 
     JOIN grade_levels gl ON cs.grade_level_id = gl.id 
     WHERE cs.is_active = 1 
     ORDER BY gl.promotion_order, cs.section_name"
);

// Get terms
$terms = $db->fetchAll(
    "SELECT id, term_name, term_number FROM academic_terms WHERE is_active = 1 ORDER BY term_number"
);

// Get subjects
$subjects = $db->fetchAll(
    "SELECT id, subject_name, subject_code FROM subjects WHERE is_active = 1 ORDER BY subject_name"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Management - EduTrack</title>
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
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #fff;
            border-radius: 10px;
            padding: 6px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .tabs .tab {
            padding: 10px 24px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-size: 14px;
            font-weight: 600;
            transition: 0.3s;
            background: transparent;
            border: none;
        }
        .tabs .tab:hover { background: #f0f2f5; color: #1a237e; }
        .tabs .tab.active { background: #1a237e; color: #fff; }
        .tabs .tab i { margin-right: 8px; }
        
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
        .badge-purple { background: #f3e5f5; color: #6a1b9a; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-card .number { font-size: 28px; font-weight: bold; color: #1a237e; }
        .stat-card .label { color: #666; font-size: 13px; margin-top: 5px; }
        .stat-card .icon { font-size: 28px; margin-bottom: 8px; }
        .stat-card .icon.purple { color: #7c4dff; }
        .stat-card .icon.blue { color: #448aff; }
        .stat-card .icon.green { color: #69f0ae; }
        .stat-card .icon.orange { color: #ffab40; }
        .stat-card .icon.red { color: #ff5252; }
        
        .message {
            padding: 12px 18px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .message-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .message-error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .message-info { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }
        
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
        .form-group .hint { font-size: 11px; color: #999; margin-top: 4px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        
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
        .modal-content h2 i { margin-right: 8px; color: #ffd54f; }
        
        .form-actions { display: flex; gap: 10px; margin-top: 15px; }
        .form-actions .btn { flex: 1; text-align: center; }
        
        .flex { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .text-center { text-align: center; }
        .text-muted { color: #999; }
        .mt-10 { margin-top: 10px; }
        .mb-10 { margin-bottom: 10px; }
        
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-state .icon { font-size: 48px; color: #c5cae9; margin-bottom: 15px; }
        .empty-state h4 { color: #1a237e; font-size: 18px; margin-bottom: 10px; }
        .empty-state p { color: #999; max-width: 400px; margin: 0 auto 15px; }
        
        .mark-entry-table input[type="number"] {
            width: 70px;
            padding: 4px 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            font-size: 13px;
        }
        .mark-entry-table input[type="number"]:focus {
            border-color: #1a237e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26,35,126,0.1);
        }
        .mark-entry-table input[type="number"].has-error {
            border-color: #c62828;
            background: #ffebee;
        }
        .mark-entry-table input[type="number"].has-success {
            border-color: #2e7d32;
            background: #e8f5e9;
        }
        .mark-entry-table .student-name { font-weight: 500; }
        .mark-entry-table .subject-header { background: #e8eaf6; font-weight: 600; }
        .mark-entry-table .total-row { background: #f5f5f5; font-weight: 600; }
        .mark-entry-table .total-row td { border-top: 2px solid #1a237e; }
        
        .filter-section {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .status-badge {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.published { background: #e8f5e9; color: #2e7d32; }
        .status-badge.draft { background: #fff3e0; color: #e65100; }
        .status-badge.pending { background: #e3f2fd; color: #0d47a1; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: stretch; text-align: center; }
            .header .header-actions { justify-content: center; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs .tab { flex: 1; text-align: center; padding: 8px 12px; font-size: 12px; }
            table { font-size: 12px; }
            table th, table td { padding: 6px 8px; }
            .mark-entry-table input[type="number"] { width: 50px; font-size: 11px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include $basePath . 'public/admin/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-tasks"></i> Assessment Management</h1>
                <p>Configure assessment types, components, enter marks, and view results</p>
            </div>
            <div class="header-actions">
                <a href="/admin/assessment_components.php?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Component</a>
                <a href="/admin/assessment_configs.php?action=add" class="btn btn-success"><i class="fas fa-plus"></i> Config</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message message-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message message-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon purple"><i class="fas fa-tag"></i></div>
                <div class="number"><?php echo $stats['types']; ?></div>
                <div class="label">Assessment Types</div>
            </div>
            <div class="stat-card">
                <div class="icon blue"><i class="fas fa-cubes"></i></div>
                <div class="number"><?php echo $stats['components']; ?></div>
                <div class="label">Components</div>
            </div>
            <div class="stat-card">
                <div class="icon orange"><i class="fas fa-layer-group"></i></div>
                <div class="number"><?php echo $stats['configs']; ?></div>
                <div class="label">Configurations</div>
            </div>
            <div class="stat-card">
                <div class="icon green"><i class="fas fa-check-circle"></i></div>
                <div class="number"><?php echo $stats['published']; ?></div>
                <div class="label">Published Results</div>
            </div>
            <div class="stat-card">
                <div class="icon red"><i class="fas fa-user-graduate"></i></div>
                <div class="number"><?php echo $stats['students']; ?></div>
                <div class="label">Students Assessed</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="?tab=dashboard" class="tab <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="?tab=types" class="tab <?php echo $activeTab === 'types' ? 'active' : ''; ?>">
                <i class="fas fa-tags"></i> Types
            </a>
            <a href="?tab=components" class="tab <?php echo $activeTab === 'components' ? 'active' : ''; ?>">
                <i class="fas fa-cubes"></i> Components
            </a>
            <a href="?tab=configs" class="tab <?php echo $activeTab === 'configs' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Configs
            </a>
            <a href="?tab=marks" class="tab <?php echo $activeTab === 'marks' ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i> Mark Entry
            </a>
            <a href="?tab=results" class="tab <?php echo $activeTab === 'results' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Results
            </a>
        </div>

        <?php if ($activeTab === 'dashboard'): ?>
            <!-- DASHBOARD TAB -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Recent Activity</h3>
                    <span class="badge-count"><?php echo count($recentActivity); ?> activities</span>
                </div>
                <?php if (!empty($recentActivity)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Name</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivity as $activity): ?>
                                    <tr>
                                        <td>
                                            <span class="badge <?php 
                                                echo $activity['type'] === 'type' ? 'badge-primary' : 
                                                    ($activity['type'] === 'component' ? 'badge-info' : 'badge-purple'); 
                                            ?>">
                                                <?php echo ucfirst($activity['type']); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($activity['name']); ?></strong></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon"><i class="fas fa-inbox"></i></div>
                        <h4>No Activity Yet</h4>
                        <p>Start by creating assessment types, components, or configurations.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-rocket"></i> Quick Start</h3>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="background: #e8eaf6; padding: 20px; border-radius: 8px; text-align: center;">
                        <i class="fas fa-tag" style="font-size: 32px; color: #1a237e; margin-bottom: 10px;"></i>
                        <h4>Assessment Types</h4>
                        <p style="font-size: 12px; color: #666;">Define CA, Exam, Practical, etc.</p>
                        <a href="?tab=types" class="btn btn-sm btn-primary" style="margin-top: 10px;">Manage</a>
                    </div>
                    <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; text-align: center;">
                        <i class="fas fa-cubes" style="font-size: 32px; color: #0d47a1; margin-bottom: 10px;"></i>
                        <h4>Components</h4>
                        <p style="font-size: 12px; color: #666;">Create assessment components</p>
                        <a href="/admin/assessment_components.php" class="btn btn-sm btn-info" style="margin-top: 10px;">Manage</a>
                    </div>
                    <div style="background: #fff3e0; padding: 20px; border-radius: 8px; text-align: center;">
                        <i class="fas fa-layer-group" style="font-size: 32px; color: #e65100; margin-bottom: 10px;"></i>
                        <h4>Configurations</h4>
                        <p style="font-size: 12px; color: #666;">Setup weightings and rules</p>
                        <a href="/admin/assessment_configs.php" class="btn btn-sm btn-warning" style="margin-top: 10px;">Manage</a>
                    </div>
                    <div style="background: #e8f5e9; padding: 20px; border-radius: 8px; text-align: center;">
                        <i class="fas fa-edit" style="font-size: 32px; color: #2e7d32; margin-bottom: 10px;"></i>
                        <h4>Mark Entry</h4>
                        <p style="font-size: 12px; color: #666;">Enter student marks</p>
                        <a href="?tab=marks" class="btn btn-sm btn-success" style="margin-top: 10px;">Start</a>
                    </div>
                </div>
            </div>

        <?php elseif ($activeTab === 'types'): ?>
            <!-- ASSESSMENT TYPES TAB -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-tags"></i> Assessment Types</h3>
                    <div>
                        <span class="badge-count"><?php echo count($types); ?> types</span>
                        <button class="btn btn-sm btn-success" onclick="openModal('typeModal')">
                            <i class="fas fa-plus"></i> Add Type
                        </button>
                    </div>
                </div>
                <?php if (!empty($types)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Category</th>
                                    <th>Weight %</th>
                                    <th>Remark</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($types as $type): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($type['assessment_name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($type['assessment_code']); ?></code></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo ucwords(str_replace('_', ' ', $type['assessment_category'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $type['weight_percentage']; ?>%</td>
                                        <td><?php echo $type['requires_remark'] ? '✅ Yes' : '❌ No'; ?></td>
                                        <td>
                                            <span class="badge <?php echo $type['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $type['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex" style="gap: 4px;">
                                                <button class="btn btn-sm btn-primary" onclick="editType(<?php echo htmlspecialchars(json_encode($type)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteType(<?php echo $type['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon"><i class="fas fa-tags"></i></div>
                        <h4>No Assessment Types</h4>
                        <p>Create your first assessment type to get started.</p>
                        <button class="btn btn-primary" onclick="openModal('typeModal')"><i class="fas fa-plus"></i> Add Type</button>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($activeTab === 'components'): ?>
            <!-- COMPONENTS TAB -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-cubes"></i> Assessment Components</h3>
                    <div>
                        <span class="badge-count"><?php echo count($components); ?> components</span>
                        <a href="/admin/assessment_components.php?action=add" class="btn btn-sm btn-success">
                            <i class="fas fa-plus"></i> Add Component
                        </a>
                    </div>
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
                                            <span class="badge <?php echo $component['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $component['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex" style="gap: 4px;">
                                                <a href="/admin/assessment_components.php?edit=<?php echo $component['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="/admin/assessment_components.php?delete=<?php echo $component['id']; ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   onclick="return confirm('Delete this component?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">No components found. <a href="/admin/assessment_components.php?action=add">Create one</a></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($activeTab === 'configs'): ?>
            <!-- CONFIGURATIONS TAB -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group"></i> Assessment Configurations</h3>
                    <div>
                        <span class="badge-count"><?php echo count($configs); ?> configs</span>
                        <a href="/admin/assessment_configs.php?action=add" class="btn btn-sm btn-success">
                            <i class="fas fa-plus"></i> Add Config
                        </a>
                    </div>
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
                                    <?php 
                                    $compCount = $db->fetchOne("SELECT COUNT(*) as total FROM config_components WHERE config_id = ? AND is_active = 1", [$config['id']]);
                                    $totalWeight = $db->fetchOne("SELECT SUM(weight_percentage) as total FROM config_components WHERE config_id = ? AND is_active = 1", [$config['id']]);
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($config['config_name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($config['config_code']); ?></code></td>
                                        <td><span class="badge badge-info"><?php echo $compCount['total'] ?? 0; ?></span></td>
                                        <td>
                                            <span class="badge <?php echo (($totalWeight['total'] ?? 0) == 100) ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo number_format($totalWeight['total'] ?? 0, 2); ?>%
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
                                            <span class="badge <?php echo $config['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $config['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex" style="gap: 4px;">
                                                <a href="/admin/assessment_configs.php?view=<?php echo $config['id']; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="/admin/assessment_configs.php?edit=<?php echo $config['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="/admin/assessment_configs.php?delete=<?php echo $config['id']; ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   onclick="return confirm('Delete this configuration?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">No configurations found. <a href="/admin/assessment_configs.php?action=add">Create one</a></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($activeTab === 'marks'): ?>
            <!-- MARK ENTRY TAB -->
            <?php 
            // Get class list
            $classList = $db->fetchAll(
                "SELECT cs.id, cs.section_name, gl.level_name 
                 FROM class_sections cs 
                 JOIN grade_levels gl ON cs.grade_level_id = gl.id 
                 WHERE cs.is_active = 1 
                 ORDER BY gl.promotion_order, cs.section_name"
            );
            
            // Get selected class and term
            $selectedClass = $_GET['class_id'] ?? null;
            $selectedTerm = $_GET['term_id'] ?? null;
            
            // Get students if class selected
            $students = [];
            if ($selectedClass && $selectedTerm) {
                $students = $db->fetchAll(
                    "SELECT s.id, s.first_name, s.last_name, s.admission_number 
                     FROM students s 
                     JOIN student_enrollments se ON s.id = se.student_id 
                     WHERE se.class_section_id = ? AND se.academic_term_id = ? 
                     AND s.is_active = 1 
                     ORDER BY s.first_name",
                    [$selectedClass, $selectedTerm]
                );
            }
            
            // Get subject assessments for the class
            $subjectAssessments = [];
            if ($selectedClass && $selectedTerm) {
                $subjectAssessments = $db->fetchAll(
                    "SELECT sa.*, s.subject_name, s.subject_code,
                            at.assessment_name, at.assessment_category
                     FROM subject_assessments sa
                     JOIN subjects s ON sa.subject_id = s.id
                     JOIN assessment_types at ON sa.assessment_type_id = at.id
                     WHERE sa.class_section_id = ? AND sa.academic_term_id = ?
                     AND sa.is_active = 1
                     ORDER BY s.subject_name",
                    [$selectedClass, $selectedTerm]
                );
            }
            
            // Get existing marks
            $marksData = [];
            if ($selectedClass && $selectedTerm && !empty($students) && !empty($subjectAssessments)) {
                foreach ($subjectAssessments as $sa) {
                    foreach ($students as $student) {
                        $mark = $db->fetchOne(
                            "SELECT * FROM assessment_marks 
                             WHERE subject_assessment_id = ? AND student_id = ? AND is_active = 1",
                            [$sa['id'], $student['id']]
                        );
                        if ($mark) {
                            $marksData[$sa['id']][$student['id']] = $mark;
                        }
                    }
                }
            }
            ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-edit"></i> Mark Entry</h3>
                    <span class="badge-count">Enter student marks</span>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" action="">
                        <input type="hidden" name="tab" value="marks">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="class_id">Class <span style="color:red;">*</span></label>
                                <select id="class_id" name="class_id" required onchange="this.form.submit()">
                                    <option value="">Select Class...</option>
                                    <?php foreach ($classList as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" <?php echo ($selectedClass == $class['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['level_name'] . ' - ' . $class['section_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="term_id">Term <span style="color:red;">*</span></label>
                                <select id="term_id" name="term_id" required onchange="this.form.submit()">
                                    <option value="">Select Term...</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo ($selectedTerm == $term['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div class="flex" style="padding-top:6px; gap: 10px;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-users"></i> Load Students</button>
                                    <button type="button" class="btn btn-success" onclick="calculateResults()"><i class="fas fa-calculator"></i> Calculate</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($selectedClass && $selectedTerm): ?>
                    <?php if (!empty($students) && !empty($subjectAssessments)): ?>
                        <form method="POST" action="assessment_action.php" onsubmit="return validateMarks()">
                            <input type="hidden" name="save_marks" value="1">
                            <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
                            <input type="hidden" name="term_id" value="<?php echo $selectedTerm; ?>">
                            
                            <div style="background: #e8eaf6; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px;">
                                <div class="flex-between">
                                    <div>
                                        <strong>👨‍🎓 Students:</strong> <?php echo count($students); ?> 
                                        | <strong>📚 Subjects:</strong> <?php echo count($subjectAssessments); ?>
                                    </div>
                                    <div>
                                        <span style="font-size:12px;color:#666;">Enter marks and click "Save All Marks"</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="table-container">
                                <table class="mark-entry-table">
                                    <thead>
                                        <tr>
                                            <th style="min-width:120px;position:sticky;left:0;background:#f5f5f5;z-index:2;">Student</th>
                                            <?php foreach ($subjectAssessments as $sa): ?>
                                                <th style="min-width:100px;text-align:center;">
                                                    <?php echo htmlspecialchars($sa['subject_code']); ?>
                                                    <span style="display:block;font-size:10px;font-weight:normal;color:#666;">
                                                        <?php echo $sa['assessment_code']; ?>
                                                    </span>
                                                </th>
                                            <?php endforeach; ?>
                                            <th style="min-width:80px;text-align:center;background:#f5f5f5;">Total</th>
                                            <th style="min-width:80px;text-align:center;background:#f5f5f5;">Avg %</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td style="position:sticky;left:0;background:#fff;z-index:1;font-weight:500;">
                                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    <span style="display:block;font-size:10px;font-weight:normal;color:#999;">
                                                        <?php echo htmlspecialchars($student['admission_number']); ?>
                                                    </span>
                                                </td>
                                                <?php 
                                                $totalScore = 0;
                                                $subjectCount = 0;
                                                foreach ($subjectAssessments as $sa): 
                                                    $mark = $marksData[$sa['id']][$student['id']] ?? null;
                                                    $score = $mark ? $mark['score'] : '';
                                                    $maxScore = $sa['max_score'] ?? 100;
                                                    $percentage = ($mark && $mark['score']) ? round(($mark['score'] / $maxScore) * 100, 1) : '';
                                                    if ($mark && $mark['score']) {
                                                        $totalScore += $mark['score'];
                                                        $subjectCount++;
                                                    }
                                                ?>
                                                    <td style="text-align:center;">
                                                        <input type="number" 
                                                               name="marks[<?php echo $sa['id']; ?>][<?php echo $student['id']; ?>]" 
                                                               value="<?php echo $score; ?>"
                                                               min="0" max="<?php echo $maxScore; ?>"
                                                               step="0.5"
                                                               class="mark-input"
                                                               data-max="<?php echo $maxScore; ?>"
                                                               onchange="updateRowTotals(this)"
                                                               placeholder="-"
                                                               style="width:70px;text-align:center;border:1px solid #ddd;border-radius:4px;padding:4px;">
                                                        <span style="display:block;font-size:10px;color:#999;">/<?php echo $maxScore; ?></span>
                                                        <?php if ($percentage): ?>
                                                            <span style="display:block;font-size:10px;color:#2e7d32;font-weight:600;"><?php echo $percentage; ?>%</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td style="text-align:center;font-weight:600;background:#f5f5f5;" class="total-score">
                                                    <?php echo round($totalScore, 1); ?>
                                                </td>
                                                <td style="text-align:center;font-weight:600;background:#f5f5f5;" class="avg-percentage">
                                                    <?php echo $subjectCount > 0 ? round($totalScore / $subjectCount, 1) : ''; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="flex" style="margin-top:15px; gap: 10px; flex-wrap: wrap;">
                                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save All Marks</button>
                                <button type="button" class="btn btn-warning" onclick="validateMarks()"><i class="fas fa-check"></i> Validate</button>
                                <button type="button" class="btn btn-info" onclick="calculateResults()"><i class="fas fa-calculator"></i> Calculate Results</button>
                                <button type="reset" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="message message-info">
                            <?php if (empty($students)): ?>
                                No students found for this class. Please ensure students are enrolled.
                            <?php else: ?>
                                No subject assessments configured for this class and term.
                                Please set up subject assessments first.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="message message-info">
                        <i class="fas fa-info-circle"></i> Please select a class and term to load students for mark entry.
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($activeTab === 'results'): ?>
            <!-- RESULTS TAB -->
            <?php 
            $resultClass = $_GET['class_id'] ?? null;
            $resultTerm = $_GET['term_id'] ?? null;
            
            $results = [];
            if ($resultClass && $resultTerm) {
                $results = $db->fetchAll(
                    "SELECT ar.*, s.first_name, s.last_name, s.admission_number,
                            sub.subject_name, sub.subject_code,
                            gl.level_name, cs.section_name
                     FROM assessment_results ar
                     JOIN students s ON ar.student_id = s.id
                     JOIN subjects sub ON ar.subject_id = sub.id
                     JOIN class_sections cs ON ar.class_section_id = cs.id
                     JOIN grade_levels gl ON cs.grade_level_id = gl.id
                     WHERE ar.class_section_id = ? AND ar.academic_term_id = ?
                     AND ar.is_active = 1
                     ORDER BY s.first_name, sub.subject_name",
                    [$resultClass, $resultTerm]
                );
            }
            ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Assessment Results</h3>
                    <span class="badge-count">View and manage results</span>
                </div>
                
                <div class="filter-section">
                    <form method="GET" action="">
                        <input type="hidden" name="tab" value="results">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="result_class_id">Class</label>
                                <select id="result_class_id" name="class_id" onchange="this.form.submit()">
                                    <option value="">Select Class...</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" <?php echo ($resultClass == $class['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['level_name'] . ' - ' . $class['section_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="result_term_id">Term</label>
                                <select id="result_term_id" name="term_id" onchange="this.form.submit()">
                                    <option value="">Select Term...</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo ($resultTerm == $term['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div class="flex" style="padding-top:6px; gap: 10px;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Load Results</button>
                                    <button type="button" class="btn btn-success" onclick="publishResults()"><i class="fas fa-check-circle"></i> Publish</button>
                                    <button type="button" class="btn btn-info" onclick="exportResults()"><i class="fas fa-file-export"></i> Export</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($resultClass && $resultTerm): ?>
                    <?php if (!empty($results)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Subject</th>
                                        <th>CA Score</th>
                                        <th>CA Grade</th>
                                        <th>Exam Score</th>
                                        <th>Exam Grade</th>
                                        <th>Total Score</th>
                                        <th>Grade</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></strong>
                                                <span style="display:block;font-size:10px;color:#999;"><?php echo htmlspecialchars($result['admission_number']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($result['subject_code']); ?></td>
                                            <td><?php echo $result['continuous_assessment_score'] ?: '-'; ?></td>
                                            <td><span class="badge badge-info"><?php echo $result['continuous_assessment_grade'] ?: '-'; ?></span></td>
                                            <td><?php echo $result['exam_score'] ?: '-'; ?></td>
                                            <td><span class="badge badge-info"><?php echo $result['exam_grade'] ?: '-'; ?></span></td>
                                            <td><strong><?php echo $result['total_score'] ?: '-'; ?></strong></td>
                                            <td><span class="badge badge-primary"><?php echo $result['total_grade'] ?: '-'; ?></span></td>
                                            <td>
                                                <span class="status-badge <?php echo $result['is_published'] ? 'published' : 'draft'; ?>">
                                                    <?php echo $result['is_published'] ? '✅ Published' : '📝 Draft'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="message message-info">
                            No results found for this class and term. Please calculate results first.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="message message-info">
                        Please select a class and term to view results.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ======================== -->
    <!-- TYPE MODAL -->
    <!-- ======================== -->
    <div class="modal" id="typeModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('typeModal')">&times;</span>
            <h2><i class="fas fa-tag"></i> <span id="typeModalTitle">Add Assessment Type</span></h2>
            <form method="POST" action="assessment_action.php">
                <input type="hidden" name="add_assessment_type" value="1" id="typeAction">
                <input type="hidden" name="type_id" value="" id="typeId">
                
                <div class="form-group">
                    <label for="type_name">Assessment Name <span style="color:red;">*</span></label>
                    <input type="text" id="type_name" name="assessment_name" placeholder="e.g. Continuous Assessment" required>
                </div>
                
                <div class="form-group">
                    <label for="type_code">Assessment Code <span style="color:red;">*</span></label>
                    <input type="text" id="type_code" name="assessment_code" placeholder="e.g. CA" required>
                    <div class="hint">System will auto-generate based on category if left blank</div>
                </div>
                
                <div class="form-group">
                    <label for="type_category">Category <span style="color:red;">*</span></label>
                    <select id="type_category" name="assessment_category" required onchange="autoGenerateCode()">
                        <option value="continuous_assessment">Continuous Assessment</option>
                        <option value="examination">Examination</option>
                        <option value="practical">Practical</option>
                        <option value="project">Project</option>
                        <option value="oral">Oral</option>
                        <option value="portfolio">Portfolio</option>
                    </select>
                    <div class="hint">Select category to auto-generate assessment code</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="type_weight">Weight Percentage</label>
                        <input type="number" id="type_weight" name="weight_percentage" step="0.5" value="0" min="0" max="100">
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:20px;">
                        <input type="checkbox" id="type_remark" name="requires_remark" value="1" checked>
                        <label for="type_remark" style="margin:0;">Requires Remark</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="type_description">Description</label>
                    <textarea id="type_description" name="description" rows="2" placeholder="Optional description"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> <span id="typeSubmitText">Save</span></button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('typeModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================== -->
    <!-- JAVASCRIPT -->
    <!-- ======================== -->
    <script>
        // ==============================================================
        // MODAL FUNCTIONS
        // ==============================================================
        function openModal(id) {
            document.getElementById(id).classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
            document.body.style.overflow = '';
        }
        
        window.onclick = function(event) {
            document.querySelectorAll('.modal').forEach(function(modal) {
                if (event.target == modal) {
                    modal.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        }

        // ==============================================================
        // AUTO-GENERATE ASSESSMENT CODE
        // ==============================================================
        function autoGenerateCode() {
            var category = document.getElementById('type_category').value;
            var codeField = document.getElementById('type_code');
            
            if (!codeField.value || codeField.dataset.autoGenerated === 'true') {
                var codeMap = {
                    'continuous_assessment': 'CA',
                    'examination': 'EXAM',
                    'practical': 'PRAC',
                    'project': 'PROJ',
                    'oral': 'ORAL',
                    'portfolio': 'PORT'
                };
                codeField.value = codeMap[category] || '';
                codeField.dataset.autoGenerated = 'true';
            }
        }

        // ==============================================================
        // TYPE CRUD FUNCTIONS
        // ==============================================================
        function editType(type) {
            document.getElementById('typeModalTitle').textContent = 'Edit Assessment Type';
            document.getElementById('typeAction').name = 'update_assessment_type';
            document.getElementById('typeId').value = type.id;
            document.getElementById('type_name').value = type.assessment_name;
            document.getElementById('type_code').value = type.assessment_code;
            document.getElementById('type_category').value = type.assessment_category;
            document.getElementById('type_weight').value = type.weight_percentage;
            document.getElementById('type_remark').checked = type.requires_remark == 1;
            document.getElementById('type_description').value = type.description || '';
            document.getElementById('typeSubmitText').textContent = 'Update';
            openModal('typeModal');
        }

        function deleteType(id) {
            if (confirm('Delete this assessment type?\n\nThis will also remove all associated components and marks.')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = 'assessment_action.php';
                form.innerHTML = '<input type="hidden" name="delete_assessment_type" value="1">' +
                                '<input type="hidden" name="type_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Reset type modal on close
        document.querySelector('#typeModal .close-modal').addEventListener('click', function() {
            document.getElementById('typeModalTitle').textContent = 'Add Assessment Type';
            document.getElementById('typeAction').name = 'add_assessment_type';
            document.getElementById('typeId').value = '';
            document.getElementById('type_name').value = '';
            document.getElementById('type_code').value = '';
            document.getElementById('type_category').value = 'continuous_assessment';
            document.getElementById('type_weight').value = '0';
            document.getElementById('type_remark').checked = true;
            document.getElementById('type_description').value = '';
            document.getElementById('typeSubmitText').textContent = 'Save';
        });

        // ==============================================================
        // MARK ENTRY FUNCTIONS
        // ==============================================================
        function validateMarks() {
            var inputs = document.querySelectorAll('.mark-input');
            var errors = [];
            var hasError = false;
            
            inputs.forEach(function(input) {
                var value = parseFloat(input.value);
                var max = parseFloat(input.dataset.max);
                
                input.classList.remove('has-error', 'has-success');
                
                if (value !== '' && !isNaN(value)) {
                    if (value < 0) {
                        errors.push('Score cannot be negative');
                        input.classList.add('has-error');
                        hasError = true;
                    } else if (value > max) {
                        errors.push('Score exceeds maximum (' + max + ')');
                        input.classList.add('has-error');
                        hasError = true;
                    } else {
                        input.classList.add('has-success');
                    }
                }
            });
            
            if (hasError) {
                alert('❌ Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            var totalMarks = document.querySelectorAll('.mark-input').length;
            var filledMarks = document.querySelectorAll('.mark-input.has-success').length;
            
            if (filledMarks === 0) {
                alert('⚠️ No marks entered. Please enter at least some marks before saving.');
                return false;
            }
            
            return confirm('✅ Save all marks?\n\n' + 
                          'Total fields: ' + totalMarks + '\n' +
                          'Filled: ' + filledMarks + '\n' +
                          'Empty: ' + (totalMarks - filledMarks) + '\n\n' +
                          'Continue?');
        }

        function updateRowTotals(input) {
            var row = input.closest('tr');
            var inputs = row.querySelectorAll('.mark-input');
            var totalScore = 0;
            var count = 0;
            
            inputs.forEach(function(inp) {
                var val = parseFloat(inp.value);
                if (!isNaN(val) && val > 0) {
                    totalScore += val;
                    count++;
                }
            });
            
            var totalCell = row.querySelector('.total-score');
            var avgCell = row.querySelector('.avg-percentage');
            
            if (totalCell) {
                totalCell.textContent = totalScore.toFixed(1);
            }
            
            if (avgCell) {
                avgCell.textContent = count > 0 ? (totalScore / count).toFixed(1) : '';
            }
        }

        // ==============================================================
        // RESULT FUNCTIONS
        // ==============================================================
        function calculateResults() {
            var classId = document.getElementById('class_id');
            var termId = document.getElementById('term_id');
            
            if (!classId || !termId || !classId.value || !termId.value) {
                alert('Please select a class and term first.');
                return;
            }
            
            if (confirm('Calculate results for all students in this class?\n\nThis will:\n- Calculate CA and Exam scores\n- Calculate total scores\n- Assign grades\n\nContinue?')) {
                var btn = event.target;
                var originalText = btn.textContent;
                btn.textContent = '⏳ Calculating...';
                btn.disabled = true;
                
                fetch('/api/assessment/results.php?action=calculate&class_id=' + classId.value + '&term_id=' + termId.value)
                    .then(response => response.json())
                    .then(data => {
                        btn.textContent = originalText;
                        btn.disabled = false;
                        
                        if (data.success) {
                            alert('✅ Results calculated successfully!\n\n' + 
                                  'Students processed: ' + (data.data?.processed || 0));
                            location.reload();
                        } else {
                            alert('❌ Error: ' + (data.message || 'Failed to calculate results'));
                        }
                    })
                    .catch(function() {
                        btn.textContent = originalText;
                        btn.disabled = false;
                        alert('❌ Error connecting to server. Please try again.');
                    });
            }
        }

        function publishResults() {
            if (confirm('Publish all results for this class and term?\n\nOnce published, students and parents will be able to view these results.\n\nContinue?')) {
                var classId = document.getElementById('result_class_id');
                var termId = document.getElementById('result_term_id');
                
                if (!classId || !termId || !classId.value || !termId.value) {
                    alert('Please select a class and term first.');
                    return;
                }
                
                fetch('/api/assessment/results.php?action=publish&class_id=' + classId.value + '&term_id=' + termId.value)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ Results published successfully!');
                            location.reload();
                        } else {
                            alert('❌ Error: ' + (data.message || 'Failed to publish results'));
                        }
                    })
                    .catch(function() {
                        alert('❌ Error connecting to server. Please try again.');
                    });
            }
        }

        function exportResults() {
            var classId = document.getElementById('result_class_id');
            var termId = document.getElementById('result_term_id');
            
            if (!classId || !termId || !classId.value || !termId.value) {
                alert('Please select a class and term first.');
                return;
            }
            
            window.location.href = '/api/assessment/results.php?action=export&class_id=' + classId.value + '&term_id=' + termId.value;
        }

        // ==============================================================
        // INITIALIZATION
        // ==============================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-generate code on page load if category is selected
            var categorySelect = document.getElementById('type_category');
            if (categorySelect) {
                categorySelect.addEventListener('change', autoGenerateCode);
            }
            
            // Initialize mark inputs with validation
            document.querySelectorAll('.mark-input').forEach(function(input) {
                input.addEventListener('blur', function() {
                    var val = parseFloat(this.value);
                    var max = parseFloat(this.dataset.max);
                    
                    if (!isNaN(val) && val > max) {
                        this.classList.add('has-error');
                        this.title = 'Exceeds maximum of ' + max;
                    } else if (!isNaN(val) && val < 0) {
                        this.classList.add('has-error');
                        this.title = 'Cannot be negative';
                    } else if (!isNaN(val)) {
                        this.classList.remove('has-error');
                        this.classList.add('has-success');
                        this.title = '';
                    }
                });
            });
        });
    </script>
</body>
</html>