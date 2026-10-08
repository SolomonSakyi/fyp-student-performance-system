<?php
/**
 * grading.php
 *
 * Enterprise Grading Management Interface
 * With Full Editable Scale Display
 *
 * @package EduTrack
 * @subpackage Admin
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication - temporary bypass for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin';
}

// Define base path - from public/admin to project root (go up 2 levels)
$basePath = dirname(__DIR__, 2) . '/';

// Load required files
require_once $basePath . 'app/helpers/DatabaseHelper.php';
require_once $basePath . 'app/helpers/LoggerHelper.php';
require_once $basePath . 'app/models/Assessment/AssessmentModel.php';
require_once $basePath . 'app/services/Grading/GradingService.php';

// Initialize service
try {
    $gradingService = new GradingService();
} catch (Exception $e) {
    die('Failed to initialize GradingService: ' . $e->getMessage());
}

$db = DatabaseHelper::getInstance();

// Determine active tab
$activeTab = $_GET['tab'] ?? 'scales';
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Add Grading Scale
        if (isset($_POST['add_scale'])) {
            $scaleDataJson = $_POST['scale_data_json'] ?? '';
            $scaleRanges = json_decode($scaleDataJson, true);
            
            if (empty($scaleRanges) || !is_array($scaleRanges)) {
                $template = $gradingService->getScaleTemplate($_POST['scale_type'] ?? 'percentage');
                $scaleRanges = $template['ranges'] ?? [];
            }
            
            $data = [
                'school_id' => 1,
                'scale_name' => trim($_POST['scale_name'] ?? ''),
                'scale_code' => trim($_POST['scale_code'] ?? ''),
                'scale_type' => $_POST['scale_type'] ?? 'percentage',
                'description' => trim($_POST['description'] ?? ''),
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'created_by' => $_SESSION['user_id']
            ];
            
            $result = $gradingService->createGradingScaleWithRanges($data, $scaleRanges);
            
            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
                header('Location: grading.php?tab=scales');
                exit;
            } else {
                $error = $result['message'];
            }
        }
        
        // Update Grading Scale
        if (isset($_POST['update_scale'])) {
            $id = (int)$_POST['scale_id'];
            $scaleDataJson = $_POST['scale_data_json'] ?? '';
            $scaleRanges = json_decode($scaleDataJson, true);
            
            $data = [
                'scale_name' => trim($_POST['scale_name'] ?? ''),
                'scale_code' => trim($_POST['scale_code'] ?? ''),
                'scale_type' => $_POST['scale_type'] ?? 'percentage',
                'description' => trim($_POST['description'] ?? ''),
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            $result = $gradingService->updateGradingScale($id, $data);
            if ($result['success']) {
                $_SESSION['message'] = 'Grading scale updated successfully!';
                header('Location: grading.php?tab=scales');
                exit;
            } else {
                $error = $result['message'];
            }
        }
        
        // Delete Grading Scale
        if (isset($_POST['delete_scale'])) {
            $id = (int)$_POST['scale_id'];
            $result = $gradingService->deleteGradingScale($id);
            if ($result['success']) {
                $_SESSION['message'] = 'Grading scale deleted successfully!';
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: grading.php?tab=scales');
            exit;
        }
        
        // Set Default Scale
        if (isset($_POST['set_default_scale'])) {
            $id = (int)$_POST['scale_id'];
            $result = $gradingService->setDefaultGradingScale($id);
            if ($result['success']) {
                $_SESSION['message'] = 'Default grading scale set successfully!';
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: grading.php?tab=scales');
            exit;
        }
        
        // Add Grading Scheme
        if (isset($_POST['add_scheme'])) {
            $gradeLevelId = null;
            if (!empty($_POST['grade_level_category'])) {
                $levels = $gradingService->getGradeLevelsByCategory($_POST['grade_level_category']);
                if ($levels['success'] && !empty($levels['data'])) {
                    $gradeLevelId = $levels['data'][0]['id'];
                }
            }
            
            $data = [
                'school_id' => 1,
                'grade_level_id' => $gradeLevelId,
                'term_id' => !empty($_POST['term_id']) ? (int)$_POST['term_id'] : null,
                'scheme_name' => trim($_POST['scheme_name'] ?? ''),
                'scheme_code' => trim($_POST['scheme_code'] ?? ''),
                'grading_scale_id' => (int)$_POST['grading_scale_id'],
                'passing_mark' => (float)($_POST['passing_mark'] ?? 50.00),
                'credit_hour_base' => (int)($_POST['credit_hour_base'] ?? 1),
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'created_by' => $_SESSION['user_id']
            ];
            
            $result = $gradingService->createGradingScheme($data);
            if ($result['success']) {
                $_SESSION['message'] = 'Grading scheme added successfully!';
                header('Location: grading.php?tab=schemes');
                exit;
            } else {
                $error = $result['message'];
            }
        }
        
        // Update Grading Scheme
        if (isset($_POST['update_scheme'])) {
            $id = (int)$_POST['scheme_id'];
            
            $gradeLevelId = null;
            if (!empty($_POST['grade_level_category'])) {
                $levels = $gradingService->getGradeLevelsByCategory($_POST['grade_level_category']);
                if ($levels['success'] && !empty($levels['data'])) {
                    $gradeLevelId = $levels['data'][0]['id'];
                }
            }
            
            $data = [
                'grade_level_id' => $gradeLevelId,
                'term_id' => !empty($_POST['term_id']) ? (int)$_POST['term_id'] : null,
                'scheme_name' => trim($_POST['scheme_name'] ?? ''),
                'scheme_code' => trim($_POST['scheme_code'] ?? ''),
                'grading_scale_id' => (int)$_POST['grading_scale_id'],
                'passing_mark' => (float)($_POST['passing_mark'] ?? 50.00),
                'credit_hour_base' => (int)($_POST['credit_hour_base'] ?? 1),
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            $result = $gradingService->updateGradingScheme($id, $data);
            if ($result['success']) {
                $_SESSION['message'] = 'Grading scheme updated successfully!';
                header('Location: grading.php?tab=schemes');
                exit;
            } else {
                $error = $result['message'];
            }
        }
        
        // Delete Grading Scheme
        if (isset($_POST['delete_scheme'])) {
            $id = (int)$_POST['scheme_id'];
            $result = $gradingService->deleteGradingScheme($id);
            if ($result['success']) {
                $_SESSION['message'] = 'Grading scheme deleted successfully!';
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: grading.php?tab=schemes');
            exit;
        }
        
        // Add Scheme Detail
        if (isset($_POST['add_detail'])) {
            $data = [
                'grading_scheme_id' => (int)$_POST['grading_scheme_id'],
                'subject_id' => (int)$_POST['subject_id'],
                'passing_mark' => !empty($_POST['passing_mark']) ? (float)$_POST['passing_mark'] : null,
                'credit_hours' => !empty($_POST['credit_hours']) ? (int)$_POST['credit_hours'] : null,
                'weighting_factor' => (float)($_POST['weighting_factor'] ?? 1.00),
                'created_by' => $_SESSION['user_id']
            ];
            
            $result = $gradingService->createGradingSchemeDetail($data);
            if ($result['success']) {
                $_SESSION['message'] = 'Subject override added successfully!';
                header('Location: grading.php?tab=details&scheme_id=' . $data['grading_scheme_id']);
                exit;
            } else {
                $error = $result['message'];
            }
        }
        
        // Update Scheme Detail
        if (isset($_POST['update_detail'])) {
            $id = (int)$_POST['detail_id'];
            $schemeId = (int)$_POST['grading_scheme_id'];
            $data = [
                'passing_mark' => !empty($_POST['passing_mark']) ? (float)$_POST['passing_mark'] : null,
                'credit_hours' => !empty($_POST['credit_hours']) ? (int)$_POST['credit_hours'] : null,
                'weighting_factor' => (float)($_POST['weighting_factor'] ?? 1.00),
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            $result = $gradingService->updateGradingSchemeDetail($id, $data);
            if ($result['success']) {
                $_SESSION['message'] = 'Subject override updated successfully!';
                header('Location: grading.php?tab=details&scheme_id=' . $schemeId);
                exit;
            } else {
                $error = $result['message'];
            }
        }
        
        // Delete Scheme Detail
        if (isset($_POST['delete_detail'])) {
            $id = (int)$_POST['detail_id'];
            $schemeId = (int)$_POST['grading_scheme_id'];
            $result = $gradingService->deleteGradingSchemeDetail($id);
            if ($result['success']) {
                $_SESSION['message'] = 'Subject override deleted successfully!';
            } else {
                $_SESSION['error'] = $result['message'];
            }
            header('Location: grading.php?tab=details&scheme_id=' . $schemeId);
            exit;
        }
        
    } catch (Exception $e) {
        $error = 'An error occurred: ' . $e->getMessage();
    }
}

// Get data for display
$scales = $gradingService->getGradingScales()['data'] ?? [];
$schemes = $gradingService->getGradingSchemes()['data'] ?? [];
$allScales = $gradingService->getAllGradingScales()['data'] ?? [];

// Get subjects for dropdown
$subjects = $db->fetchAll(
    "SELECT id, subject_name, subject_code FROM subjects WHERE is_active = 1 ORDER BY subject_name"
);

// Get grade levels with categories for display
$gradeLevels = $db->fetchAll(
    "SELECT id, level_name, level_category, promotion_order FROM grade_levels WHERE is_active = 1 ORDER BY promotion_order"
);

// Get grade level categories
$levelCategories = $gradingService->getGradeLevelCategories();

// Get terms for dropdown
$terms = $db->fetchAll(
    "SELECT id, term_name, term_number FROM academic_terms WHERE is_active = 1 ORDER BY term_number"
);

// Get scheme details if scheme_id is provided
$schemeDetails = [];
$selectedSchemeId = $_GET['scheme_id'] ?? null;
if ($selectedSchemeId) {
    $schemeDetails = $gradingService->getGradingSchemeDetails((int)$selectedSchemeId)['data'] ?? [];
}

// Helper function to get category label
function getCategoryLabel($category, $levelCategories) {
    return $levelCategories[$category] ?? ucfirst(str_replace('_', ' ', $category));
}

// Build grade level data for JavaScript
$gradeLevelsByCategory = [];
foreach ($gradeLevels as $level) {
    $cat = $level['level_category'] ?? 'other';
    if (!isset($gradeLevelsByCategory[$cat])) {
        $gradeLevelsByCategory[$cat] = [];
    }
    $gradeLevelsByCategory[$cat][] = $level;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading Management - EduTrack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header { background: #fff; padding: 20px 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #1a3c6e; font-size: 24px; }
        .header h1 span { color: #f59e0b; }
        .header p { color: #666; font-size: 14px; margin-top: 4px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        
        .tabs { display: flex; gap: 4px; background: #fff; border-radius: 12px; padding: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 25px; flex-wrap: wrap; }
        .tabs .tab { padding: 10px 24px; border-radius: 8px; cursor: pointer; text-decoration: none; color: #666; font-size: 14px; font-weight: 600; transition: 0.3s; background: transparent; border: none; }
        .tabs .tab:hover { background: #f0f2f5; color: #1a3c6e; }
        .tabs .tab.active { background: #1a3c6e; color: #fff; }
        
        .content-card { background: #fff; padding: 20px 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid #f0f2f5; }
        .card-header h3 { color: #1a3c6e; font-size: 18px; }
        .card-header .badge-count { background: #1a3c6e; color: #fff; padding: 2px 12px; border-radius: 20px; font-size: 13px; }
        
        .btn { display: inline-block; padding: 8px 18px; background: #1a3c6e; color: #fff; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.3s; }
        .btn:hover { background: #2a4c8e; transform: translateY(-1px); }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .btn-red { background: #dc2626; }
        .btn-red:hover { background: #b91c1c; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
        .btn-info { background: #0ea5e9; }
        .btn-info:hover { background: #0284c7; }
        .btn-outline { background: transparent; color: #1a3c6e; border: 2px solid #1a3c6e; }
        .btn-outline:hover { background: #1a3c6e; color: #fff; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-xs { padding: 2px 10px; font-size: 11px; }
        
        .message { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th { background: #f8fafc; color: #1a3c6e; padding: 10px 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
        table td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        table tr:hover { background: #f8fafc; }
        
        .badge { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .badge-default { background: #fff3cd; color: #856404; }
        .badge-primary { background: #cce5ff; color: #004085; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-level { background: #e8f4fd; color: #0c5460; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; transition: 0.3s; }
        .form-group input:focus, .form-group select:focus { border-color: #1a3c6e; outline: none; box-shadow: 0 0 0 3px rgba(26,60,110,0.1); }
        .form-group .hint { font-size: 11px; color: #999; margin-top: 4px; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .btn-row { display: flex; gap: 10px; margin-top: 15px; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto; }
        .modal.show { display: block; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; max-width: 850px; width: 95%; margin: 30px auto; max-height: 90vh; overflow-y: auto; }
        .modal-content h2 { color: #1a3c6e; margin-bottom: 20px; font-size: 22px; }
        .modal-content .close-modal { float: right; font-size: 28px; cursor: pointer; color: #999; }
        .modal-content .close-modal:hover { color: #333; }
        
        .no-data { text-align: center; color: #999; padding: 30px; font-size: 14px; }
        .action-group { display: flex; gap: 5px; flex-wrap: wrap; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #fff; padding: 15px 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid #1a3c6e; }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1a3c6e; }
        .stat-card .label { color: #666; font-size: 13px; margin-top: 2px; }
        .stat-card .number.green { color: #16a34a; }
        .stat-card .number.orange { color: #f59e0b; }
        .stat-card .number.blue { color: #0ea5e9; }
        
        .range-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .range-table th { background: #1a3c6e; color: #fff; padding: 8px 12px; text-align: center; }
        .range-table td { padding: 6px 12px; text-align: center; border-bottom: 1px solid #e2e8f0; }
        .range-table tr:nth-child(even) { background: #f8fafc; }
        .range-table input { width: 70px; text-align: center; border: 1px solid #d1d5db; border-radius: 4px; padding: 4px; }
        .range-table input:focus { border-color: #1a3c6e; outline: none; box-shadow: 0 0 0 3px rgba(26,60,110,0.1); }
        .range-table .grade-input { width: 50px; font-weight: bold; }
        
        @media (max-width: 768px) {
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-actions { width: 100%; }
            .header-actions .btn { flex: 1; text-align: center; }
            .tabs .tab { flex: 1; text-align: center; padding: 8px 12px; font-size: 12px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .modal-content { max-width: 95%; padding: 15px; }
            .range-table input { width: 50px; font-size: 11px; }
            .range-table .grade-input { width: 40px; }
        }
    </style>
</head>
<body>

<?php include $basePath . 'public/admin/includes/sidebar.php'; ?>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div>
            <h1>📊 Grading <span>Management</span></h1>
            <p>Configure grading scales, schemes, and subject-specific overrides</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-green" onclick="openModal('scaleModal')">➕ Add Scale</button>
            <button class="btn btn-info" onclick="openModal('schemeModal')">➕ Add Scheme</button>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=scales" class="tab <?php echo $activeTab === 'scales' ? 'active' : ''; ?>">📏 Grading Scales</a>
        <a href="?tab=schemes" class="tab <?php echo $activeTab === 'schemes' ? 'active' : ''; ?>">📋 Grading Schemes</a>
        <a href="?tab=details<?php echo $selectedSchemeId ? '&scheme_id=' . $selectedSchemeId : ''; ?>" class="tab <?php echo $activeTab === 'details' ? 'active' : ''; ?>">📝 Subject Overrides</a>
        <a href="?tab=preview" class="tab <?php echo $activeTab === 'preview' ? 'active' : ''; ?>">👁️ Preview Grades</a>
    </div>

    <?php if ($activeTab === 'scales'): ?>
        <!-- GRADING SCALES TAB -->
        <div class="stats-grid">
            <div class="stat-card"><div class="number blue"><?php echo count($scales); ?></div><div class="label">Total Scales</div></div>
            <?php $defaultScale = array_filter($scales, function($s) { return $s['is_default'] == 1; }); ?>
            <div class="stat-card"><div class="number green"><?php echo count($defaultScale); ?></div><div class="label">Default Scales</div></div>
            <?php $activeScales = array_filter($scales, function($s) { return $s['is_active'] == 1; }); ?>
            <div class="stat-card"><div class="number orange"><?php echo count($activeScales); ?></div><div class="label">Active Scales</div></div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h3>Grading Scales</h3>
                <span class="badge-count"><?php echo count($scales); ?> scales</span>
            </div>
            <?php if (!empty($scales)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Scale Name</th><th>Code</th><th>Type</th><th>Range</th><th>Grade</th><th>Point</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scales as $scale): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($scale['scale_name']); ?></strong>
                                        <?php if ($scale['is_default']): ?><span class="badge badge-default">⭐ Default</span><?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($scale['scale_code']); ?></code></td>
                                    <td><?php echo ucfirst($scale['scale_type']); ?></td>
                                    <td><?php echo $scale['min_value']; ?> - <?php echo $scale['max_value']; ?></td>
                                    <td><span class="badge badge-primary"><?php echo htmlspecialchars($scale['grade_letter'] ?? '-'); ?></span></td>
                                    <td><?php echo $scale['grade_point'] ? number_format($scale['grade_point'], 2) : '-'; ?></td>
                                    <td>
                                        <span class="badge <?php echo $scale['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $scale['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn btn-info btn-xs" onclick="editScale(<?php echo htmlspecialchars(json_encode($scale)); ?>)">✏️</button>
                                            <?php if (!$scale['is_default']): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="set_default_scale" value="1">
                                                    <input type="hidden" name="scale_id" value="<?php echo $scale['id']; ?>">
                                                    <button type="submit" class="btn btn-warning btn-xs">⭐</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this grading scale?');">
                                                <input type="hidden" name="delete_scale" value="1">
                                                <input type="hidden" name="scale_id" value="<?php echo $scale['id']; ?>">
                                                <button type="submit" class="btn btn-red btn-xs">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No grading scales configured. Click "Add Scale" to create one.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($activeTab === 'schemes'): ?>
        <!-- GRADING SCHEMES TAB -->
        <div class="stats-grid">
            <div class="stat-card"><div class="number blue"><?php echo count($schemes); ?></div><div class="label">Total Schemes</div></div>
            <?php $defaultSchemes = array_filter($schemes, function($s) { return $s['is_default'] == 1; }); ?>
            <div class="stat-card"><div class="number green"><?php echo count($defaultSchemes); ?></div><div class="label">Default Schemes</div></div>
            <?php $activeSchemes = array_filter($schemes, function($s) { return $s['is_active'] == 1; }); ?>
            <div class="stat-card"><div class="number orange"><?php echo count($activeSchemes); ?></div><div class="label">Active Schemes</div></div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h3>Grading Schemes</h3>
                <span class="badge-count"><?php echo count($schemes); ?> schemes</span>
            </div>
            <?php if (!empty($schemes)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Scheme Name</th><th>Code</th><th>Grade Level</th><th>Term</th><th>Grading Scale</th><th>Pass Mark</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schemes as $scheme): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($scheme['scheme_name']); ?></strong>
                                        <?php if ($scheme['is_default']): ?><span class="badge badge-default">⭐ Default</span><?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($scheme['scheme_code']); ?></code></td>
                                    <td>
                                        <?php 
                                        if (!empty($scheme['level_category'])) {
                                            $label = getCategoryLabel($scheme['level_category'], $levelCategories);
                                            echo '<span class="badge badge-level">' . htmlspecialchars($label) . '</span>';
                                        } elseif (!empty($scheme['level_name'])) {
                                            echo htmlspecialchars($scheme['level_name']);
                                        } else {
                                            echo 'All Levels';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($scheme['term_name'] ?? 'All Terms'); ?></td>
                                    <td><?php echo htmlspecialchars($scheme['grading_scale_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo $scheme['passing_mark']; ?>%</td>
                                    <td>
                                        <span class="badge <?php echo $scheme['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $scheme['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <a href="?tab=details&scheme_id=<?php echo $scheme['id']; ?>" class="btn btn-info btn-xs">📝</a>
                                            <button class="btn btn-info btn-xs" onclick="editScheme(<?php echo htmlspecialchars(json_encode($scheme)); ?>)">✏️</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this grading scheme?');">
                                                <input type="hidden" name="delete_scheme" value="1">
                                                <input type="hidden" name="scheme_id" value="<?php echo $scheme['id']; ?>">
                                                <button type="submit" class="btn btn-red btn-xs">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data">No grading schemes configured. Click "Add Scheme" to create one.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($activeTab === 'details'): ?>
        <!-- SUBJECT OVERRIDES TAB -->
        <div class="content-card">
            <div class="card-header">
                <h3>Subject Overrides</h3>
                <div>
                    <span class="badge-count"><?php echo count($schemeDetails); ?> subjects</span>
                    <?php if ($selectedSchemeId): ?>
                        <button class="btn btn-green btn-sm" onclick="openModal('detailModal')">➕ Add Override</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($selectedSchemeId): ?>
                <?php $scheme = $gradingService->getGradingSchemeById((int)$selectedSchemeId)['data'] ?? null; ?>
                <?php if ($scheme): ?>
                    <div style="background:#f8fafc;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #e2e8f0;">
                        <p><strong>Scheme:</strong> <?php echo htmlspecialchars($scheme['scheme_name']); ?></p>
                        <p><strong>Passing Mark:</strong> <?php echo $scheme['passing_mark']; ?>%</p>
                        <p><strong>Grading Scale:</strong> <?php echo htmlspecialchars($scheme['grading_scale_name'] ?? 'N/A'); ?></p>
                        <p style="font-size:12px;color:#666;">Configure subject-specific overrides below. Leave fields empty to use scheme defaults.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #ffc107;">
                    <p>Select a grading scheme from the "Grading Schemes" tab, then click "Overrides" to manage subject-specific settings.</p>
                    <a href="?tab=schemes" class="btn btn-warning btn-sm">Go to Schemes</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($schemeDetails)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Subject</th><th>Passing Mark</th><th>Credit Hours</th><th>Weighting</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schemeDetails as $detail): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($detail['subject_name']); ?></strong> <span style="color:#999;font-size:11px;">(<?php echo htmlspecialchars($detail['subject_code']); ?>)</span></td>
                                    <td><?php echo $detail['passing_mark'] ? $detail['passing_mark'] . '%' : 'Use default'; ?></td>
                                    <td><?php echo $detail['credit_hours'] ?: 'Use default'; ?></td>
                                    <td><?php echo $detail['weighting_factor']; ?></td>
                                    <td><span class="badge <?php echo $detail['is_active'] ? 'badge-active' : 'badge-inactive'; ?>"><?php echo $detail['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn btn-info btn-xs" onclick="editDetail(<?php echo htmlspecialchars(json_encode($detail)); ?>)">✏️</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this override?');">
                                                <input type="hidden" name="delete_detail" value="1">
                                                <input type="hidden" name="detail_id" value="<?php echo $detail['id']; ?>">
                                                <input type="hidden" name="grading_scheme_id" value="<?php echo $selectedSchemeId; ?>">
                                                <button type="submit" class="btn btn-red btn-xs">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="no-data"><?php echo $selectedSchemeId ? 'No subject overrides configured for this scheme.' : 'Select a scheme to view subject overrides.'; ?></p>
            <?php endif; ?>
        </div>

    <?php elseif ($activeTab === 'preview'): ?>
        <!-- PREVIEW TAB -->
        <div class="content-card">
            <div class="card-header">
                <h3>Grade Preview Calculator</h3>
                <span class="badge-count">Test grade calculations</span>
            </div>
            <div style="max-width:500px;">
                <div class="form-group">
                    <label for="preview_score">Score (%)</label>
                    <input type="number" id="preview_score" value="75" step="0.5" min="0" max="100" onchange="previewGrade()" oninput="previewGrade()">
                </div>
                <div class="form-group">
                    <label for="preview_scheme">Grading Scheme</label>
                    <select id="preview_scheme" onchange="previewGrade()">
                        <option value="">Select a scheme...</option>
                        <?php foreach ($schemes as $scheme): ?>
                            <option value="<?php echo $scheme['id']; ?>" <?php echo $scheme['is_default'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($scheme['scheme_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="preview_result" style="padding:20px;background:#f8fafc;border-radius:8px;border:2px solid #e2e8f0;margin-top:15px;">
                    <p style="color:#999;">Enter a score and select a scheme to see the grade.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ======================== -->
<!-- MODALS -->
<!-- ======================== -->

<!-- Scale Modal -->
<div class="modal" id="scaleModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('scaleModal')">&times;</span>
        <h2 id="scaleModalTitle">Add Grading Scale</h2>
        <form method="POST" onsubmit="return collectScaleData()">
            <input type="hidden" name="add_scale" value="1" id="scale_action">
            <input type="hidden" name="scale_id" value="" id="scale_id">
            <input type="hidden" name="scale_data_json" value="" id="scale_data_json">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="scale_name">Scale Name *</label>
                    <input type="text" id="scale_name" name="scale_name" placeholder="e.g. Alphabet Grade Scale" required>
                </div>
                <div class="form-group">
                    <label for="scale_code">Scale Code *</label>
                    <input type="text" id="scale_code" name="scale_code" placeholder="e.g. LETTER" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="scale_type">Scale Type *</label>
                <select id="scale_type" name="scale_type" required>
                    <option value="letter">🔤 Alphabet (A, B, C, D, E, F)</option>
                    <option value="number">🔢 Numbers (1, 2, 3, 4, 5, 6)</option>
                    <option value="percentage" selected>📊 Percentage (A, B, C, D, E, F)</option>
                    <option value="grade_point">📈 Grade Point (GPA Scale)</option>
                </select>
                <div id="scale_type_hint" style="font-size:12px;color:#999;margin-top:4px;">
                    💡 Select a scale type to load default values
                </div>
            </div>
            
            <div class="form-group">
                <label for="scale_description">Description</label>
                <input type="text" id="scale_description" name="description" placeholder="Optional description">
            </div>
            
            <!-- Full Scale Ranges Display -->
            <div id="scale_ranges_container"></div>
            
            <div class="form-row" style="margin-top:15px;">
                <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:10px;">
                    <input type="checkbox" id="is_default" name="is_default" value="1">
                    <label for="is_default" style="margin:0;">Set as default scale</label>
                </div>
            </div>
            
            <div class="btn-row">
                <button type="submit" class="btn btn-green">Save Scale</button>
                <button type="button" class="btn btn-outline" onclick="closeModal('scaleModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Scheme Modal -->
<div class="modal" id="schemeModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('schemeModal')">&times;</span>
        <h2 id="schemeModalTitle">Add Grading Scheme</h2>
        <form method="POST">
            <input type="hidden" name="add_scheme" value="1" id="scheme_action">
            <input type="hidden" name="scheme_id" value="" id="scheme_id">
            <div class="form-row">
                <div class="form-group">
                    <label for="scheme_name">Scheme Name *</label>
                    <input type="text" id="scheme_name" name="scheme_name" placeholder="e.g. Standard Grading" required>
                </div>
                <div class="form-group">
                    <label for="scheme_code">Scheme Code *</label>
                    <input type="text" id="scheme_code" name="scheme_code" placeholder="e.g. STD" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="grade_level_category">Grade Level</label>
                    <select id="grade_level_category" name="grade_level_category">
                        <option value="">All Levels</option>
                        <option value="pre_school">🏫 Pre School</option>
                        <option value="lower_primary">📚 Lower Primary</option>
                        <option value="upper_primary">📖 Upper Primary</option>
                        <option value="jhs">🎓 J.H.S.</option>
                    </select>
                    <div class="hint">Select a level category. All grade levels within the category will be included.</div>
                </div>
                <div class="form-group">
                    <label for="term_id">Term</label>
                    <select id="term_id" name="term_id">
                        <option value="">All Terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="grading_scale_id">Grading Scale *</label>
                    <select id="grading_scale_id" name="grading_scale_id" required>
                        <option value="">Select a scale...</option>
                        <?php foreach ($allScales as $scale): ?>
                            <option value="<?php echo $scale['id']; ?>" <?php echo $scale['is_default'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($scale['scale_name']); ?> (<?php echo htmlspecialchars($scale['scale_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="passing_mark">Passing Mark (%)</label>
                    <input type="number" id="passing_mark" name="passing_mark" step="0.5" value="50" min="0" max="100">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="credit_hour_base">Credit Hour Base</label>
                    <input type="number" id="credit_hour_base" name="credit_hour_base" value="1" min="1">
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:20px;">
                    <input type="checkbox" id="scheme_is_default" name="is_default" value="1">
                    <label for="scheme_is_default" style="margin:0;">Set as default</label>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-green">Save</button>
                <button type="button" class="btn btn-outline" onclick="closeModal('schemeModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Detail Modal -->
<div class="modal" id="detailModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('detailModal')">&times;</span>
        <h2 id="detailModalTitle">Add Subject Override</h2>
        <form method="POST">
            <input type="hidden" name="add_detail" value="1" id="detail_action">
            <input type="hidden" name="detail_id" value="" id="detail_id">
            <input type="hidden" name="grading_scheme_id" value="<?php echo $selectedSchemeId; ?>" id="detail_scheme_id">
            <div class="form-group">
                <label for="subject_id">Subject *</label>
                <select id="subject_id" name="subject_id" required>
                    <option value="">Select a subject...</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo htmlspecialchars($subject['subject_code']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="detail_passing_mark">Passing Mark (%)</label>
                    <input type="number" id="detail_passing_mark" name="passing_mark" step="0.5" min="0" max="100" placeholder="Use default">
                </div>
                <div class="form-group">
                    <label for="credit_hours">Credit Hours</label>
                    <input type="number" id="credit_hours" name="credit_hours" min="1" placeholder="Use default">
                </div>
            </div>
            <div class="form-group">
                <label for="weighting_factor">Weighting Factor</label>
                <input type="number" id="weighting_factor" name="weighting_factor" step="0.01" value="1.00" min="0.01">
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-green">Save</button>
                <button type="button" class="btn btn-outline" onclick="closeModal('detailModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================== -->
<!-- JAVASCRIPT -->
<!-- ======================== -->

<script>
// Scale templates with full scale ranges
var scaleTemplates = <?php echo json_encode($gradingService->getAllScaleTemplates()); ?>;

// Grade level data for dynamic selection
var gradeLevelsByCategory = <?php echo json_encode($gradeLevelsByCategory); ?>;

// ==============================================================
// SCALE MODAL - AUTO-FILL FUNCTIONS
// ==============================================================

function autoFillScaleDefaults() {
    var scaleType = document.getElementById('scale_type').value;
    var template = scaleTemplates[scaleType];
    var container = document.getElementById('scale_ranges_container');
    var hintDiv = document.getElementById('scale_type_hint');
    
    if (!template || !template.ranges) {
        if (hintDiv) {
            hintDiv.innerHTML = '💡 Select a scale type to load default values';
            hintDiv.style.color = '#999';
            hintDiv.style.background = 'transparent';
            hintDiv.style.padding = '0';
        }
        if (container) {
            container.innerHTML = '';
        }
        return;
    }
    
    // Build the full scale table
    var html = '<div style="margin-top:10px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">';
    html += '<table class="range-table">';
    html += '<thead><tr>';
    html += '<th style="width:15%;">Grade</th>';
    html += '<th style="width:25%;">Min %</th>';
    html += '<th style="width:25%;">Max %</th>';
    html += '<th style="width:25%;">Grade Point</th>';
    html += '</tr></thead><tbody>';
    
    var index = 0;
    template.ranges.forEach(function(range) {
        var rowClass = (index % 2 === 0) ? '' : 'style="background:#f8fafc;"';
        html += '<tr ' + rowClass + '>';
        html += '<td><input type="text" name="scale_grade[]" value="' + range.grade + '" class="grade-input" placeholder="Grade"></td>';
        html += '<td><input type="number" name="scale_min[]" value="' + range.min + '" step="0.01" placeholder="Min"></td>';
        html += '<td><input type="number" name="scale_max[]" value="' + range.max + '" step="0.01" placeholder="Max"></td>';
        html += '<td><input type="number" name="scale_point[]" value="' + range.point + '" step="0.01" placeholder="Point"></td>';
        html += '</tr>';
        index++;
    });
    
    html += '</tbody></table>';
    html += '<div style="padding:8px 12px;background:#f0f4f8;font-size:12px;color:#666;text-align:center;border-top:1px solid #e2e8f0;">';
    html += '✏️ Edit any value above to customize your grading scale. All ' + template.ranges.length + ' grade levels will be saved.';
    html += '</div>';
    html += '</div>';
    
    container.innerHTML = html;
    
    // Show hint
    if (hintDiv) {
        hintDiv.innerHTML = '✅ Loaded <strong>' + template.icon + ' ' + template.label + '</strong> with ' + template.ranges.length + ' grade levels. Edit values above as needed.';
        hintDiv.style.color = '#155724';
        hintDiv.style.background = '#d4edda';
        hintDiv.style.padding = '8px 12px';
        hintDiv.style.borderRadius = '4px';
        hintDiv.style.marginTop = '5px';
    }
}

// ==============================================================
// COLLECT SCALE DATA FOR SUBMISSION
// ==============================================================

function collectScaleData() {
    var gradeInputs = document.querySelectorAll('input[name="scale_grade[]"]');
    var minInputs = document.querySelectorAll('input[name="scale_min[]"]');
    var maxInputs = document.querySelectorAll('input[name="scale_max[]"]');
    var pointInputs = document.querySelectorAll('input[name="scale_point[]"]');
    var scaleData = [];
    
    for (var i = 0; i < gradeInputs.length; i++) {
        var grade = gradeInputs[i].value.trim();
        var min = parseFloat(minInputs[i].value);
        var max = parseFloat(maxInputs[i].value);
        var point = parseFloat(pointInputs[i].value);
        
        // Validate
        if (!grade || isNaN(min) || isNaN(max) || isNaN(point)) {
            alert('Please fill in all fields for grade level: ' + (grade || 'Row ' + (i + 1)));
            return false;
        }
        
        if (min >= max) {
            alert('Min value must be less than max value for grade: ' + grade);
            return false;
        }
        
        scaleData.push({
            grade: grade,
            min: min,
            max: max,
            point: point
        });
    }
    
    if (scaleData.length === 0) {
        alert('No grade levels defined. Please add at least one grade level.');
        return false;
    }
    
    // Store in hidden input for form submission
    document.getElementById('scale_data_json').value = JSON.stringify(scaleData);
    return true;
}

// ==============================================================
// EDIT SCALE - POPULATE MODAL WITH EXISTING DATA
// ==============================================================

function editScale(scale) {
    document.getElementById('scaleModalTitle').textContent = 'Edit Grading Scale';
    document.getElementById('scale_action').name = 'update_scale';
    document.getElementById('scale_id').value = scale.id;
    document.getElementById('scale_name').value = scale.scale_name;
    document.getElementById('scale_code').value = scale.scale_code;
    document.getElementById('scale_type').value = scale.scale_type;
    document.getElementById('scale_description').value = scale.description || '';
    document.getElementById('is_default').checked = scale.is_default == 1;
    
    // Load the scale ranges for this scale type
    var template = scaleTemplates[scale.scale_type];
    if (template && template.ranges) {
        var container = document.getElementById('scale_ranges_container');
        var html = '<div style="margin-top:10px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">';
        html += '<table class="range-table">';
        html += '<thead><tr>';
        html += '<th style="width:15%;">Grade</th>';
        html += '<th style="width:25%;">Min %</th>';
        html += '<th style="width:25%;">Max %</th>';
        html += '<th style="width:25%;">Grade Point</th>';
        html += '</tr></thead><tbody>';
        
        var index = 0;
        template.ranges.forEach(function(range) {
            var rowClass = (index % 2 === 0) ? '' : 'style="background:#f8fafc;"';
            html += '<tr ' + rowClass + '>';
            html += '<td><input type="text" name="scale_grade[]" value="' + range.grade + '" class="grade-input" placeholder="Grade"></td>';
            html += '<td><input type="number" name="scale_min[]" value="' + range.min + '" step="0.01" placeholder="Min"></td>';
            html += '<td><input type="number" name="scale_max[]" value="' + range.max + '" step="0.01" placeholder="Max"></td>';
            html += '<td><input type="number" name="scale_point[]" value="' + range.point + '" step="0.01" placeholder="Point"></td>';
            html += '</tr>';
            index++;
        });
        
        html += '</tbody></table>';
        html += '<div style="padding:8px 12px;background:#f0f4f8;font-size:12px;color:#666;text-align:center;border-top:1px solid #e2e8f0;">';
        html += '✏️ Edit any value above to customize your grading scale.';
        html += '</div>';
        html += '</div>';
        
        container.innerHTML = html;
    }
    
    // Show hint
    var hintDiv = document.getElementById('scale_type_hint');
    if (hintDiv) {
        hintDiv.innerHTML = '📝 Editing existing scale. Modify values above as needed.';
        hintDiv.style.color = '#004085';
        hintDiv.style.background = '#cce5ff';
        hintDiv.style.padding = '8px 12px';
        hintDiv.style.borderRadius = '4px';
        hintDiv.style.marginTop = '5px';
    }
    
    openModal('scaleModal');
}

// ==============================================================
// MODAL CONTROL FUNCTIONS
// ==============================================================

function openModal(id) {
    document.getElementById(id).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    document.body.style.overflow = '';
    
    // Reset Add modal if it's the scale modal
    if (id === 'scaleModal') {
        document.getElementById('scaleModalTitle').textContent = 'Add Grading Scale';
        document.getElementById('scale_action').name = 'add_scale';
        document.getElementById('scale_id').value = '';
        document.getElementById('scale_name').value = '';
        document.getElementById('scale_code').value = '';
        document.getElementById('scale_type').value = 'percentage';
        document.getElementById('scale_description').value = '';
        document.getElementById('is_default').checked = false;
        document.getElementById('scale_ranges_container').innerHTML = '';
        document.getElementById('scale_data_json').value = '';
        
        var hintDiv = document.getElementById('scale_type_hint');
        if (hintDiv) {
            hintDiv.innerHTML = '💡 Select a scale type to load default values';
            hintDiv.style.color = '#999';
            hintDiv.style.background = 'transparent';
            hintDiv.style.padding = '0';
        }
    }
}

// Close modal on outside click
window.onclick = function(event) {
    var modals = document.querySelectorAll('.modal');
    modals.forEach(function(modal) {
        if (event.target == modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    });
}

// ==============================================================
// SCHEME EDIT FUNCTIONS
// ==============================================================

function updateGradeLevels() {
    var category = document.getElementById('grade_level_category').value;
    // The actual grade_level_id is stored in the hidden input
    // For now, we just show the category selection
    // The backend will handle the mapping from category to level IDs
}

function editScheme(scheme) {
    document.getElementById('schemeModalTitle').textContent = 'Edit Grading Scheme';
    document.getElementById('scheme_action').name = 'update_scheme';
    document.getElementById('scheme_id').value = scheme.id;
    document.getElementById('scheme_name').value = scheme.scheme_name;
    document.getElementById('scheme_code').value = scheme.scheme_code;
    
    if (scheme.grade_level_id) {
        var category = getCategoryForLevel(scheme.grade_level_id);
        if (category) {
            document.getElementById('grade_level_category').value = category;
        }
    } else {
        document.getElementById('grade_level_category').value = '';
    }
    
    document.getElementById('term_id').value = scheme.term_id || '';
    document.getElementById('grading_scale_id').value = scheme.grading_scale_id;
    document.getElementById('passing_mark').value = scheme.passing_mark;
    document.getElementById('credit_hour_base').value = scheme.credit_hour_base;
    document.getElementById('scheme_is_default').checked = scheme.is_default == 1;
    openModal('schemeModal');
}

function getCategoryForLevel(levelId) {
    for (var category in gradeLevelsByCategory) {
        var levels = gradeLevelsByCategory[category];
        for (var i = 0; i < levels.length; i++) {
            if (levels[i].id == levelId) {
                return category;
            }
        }
    }
    return null;
}

// ==============================================================
// DETAIL EDIT FUNCTIONS
// ==============================================================

function editDetail(detail) {
    document.getElementById('detailModalTitle').textContent = 'Edit Subject Override';
    document.getElementById('detail_action').name = 'update_detail';
    document.getElementById('detail_id').value = detail.id;
    document.getElementById('detail_scheme_id').value = detail.grading_scheme_id;
    document.getElementById('subject_id').value = detail.subject_id;
    document.getElementById('detail_passing_mark').value = detail.passing_mark || '';
    document.getElementById('credit_hours').value = detail.credit_hours || '';
    document.getElementById('weighting_factor').value = detail.weighting_factor;
    openModal('detailModal');
}

// ==============================================================
// PREVIEW GRADE FUNCTION
// ==============================================================

function previewGrade() {
    var score = document.getElementById('preview_score').value;
    var schemeId = document.getElementById('preview_scheme').value;
    var resultDiv = document.getElementById('preview_result');
    
    if (!score || !schemeId) {
        resultDiv.innerHTML = '<p style="color:#999;">Enter a score and select a scheme to see the grade.</p>';
        return;
    }
    
    fetch('/api/grading/index.php?action=preview&score=' + score + '&scheme_id=' + schemeId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                var d = data.data;
                var passClass = d.is_passing ? 'badge-success' : 'badge-danger';
                var passText = d.is_passing ? '✅ Pass' : '❌ Fail';
                resultDiv.innerHTML = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div><strong>Score:</strong> ${d.percentage}%</div>
                        <div><strong>Grade:</strong> <span class="badge badge-primary">${d.grade_letter || 'N/A'}</span></div>
                        <div><strong>Grade Point:</strong> ${d.grade_point || 'N/A'}</div>
                        <div><strong>Status:</strong> <span class="badge ${passClass}">${passText}</span></div>
                        <div><strong>Passing Mark:</strong> ${d.passing_mark}%</div>
                        <div><strong>Scheme:</strong> ${d.scheme_name || 'N/A'}</div>
                    </div>
                `;
            } else {
                resultDiv.innerHTML = '<p style="color:#dc2626;">Error: ' + (data.message || 'Unable to calculate grade') + '</p>';
            }
        })
        .catch(function() {
            resultDiv.innerHTML = '<p style="color:#dc2626;">Error connecting to server. Please ensure the API is available.</p>';
        });
}

// ==============================================================
// INITIALIZATION
// ==============================================================

document.addEventListener('DOMContentLoaded', function() {
    // Scale type change event
    var scaleTypeSelect = document.getElementById('scale_type');
    if (scaleTypeSelect) {
        scaleTypeSelect.addEventListener('change', autoFillScaleDefaults);
        // Auto-fill on page load if a type is selected
        if (scaleTypeSelect.value) {
            setTimeout(autoFillScaleDefaults, 200);
        }
    }
    
    // Preview tab auto-load
    <?php if ($activeTab === 'preview'): ?>
    setTimeout(previewGrade, 500);
    <?php endif; ?>
    
    // Reset modals on close button click
    document.querySelector('#scaleModal .close-modal').addEventListener('click', function() {
        closeModal('scaleModal');
    });
    
    document.querySelector('#schemeModal .close-modal').addEventListener('click', function() {
        closeModal('schemeModal');
    });
    
    document.querySelector('#detailModal .close-modal').addEventListener('click', function() {
        closeModal('detailModal');
    });
});
</script>
</body>
</html>