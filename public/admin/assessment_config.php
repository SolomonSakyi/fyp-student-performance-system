<?php
/**
 * Assessment Configuration Dashboard
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// Get statistics
$stats = ['components' => 0, 'configs' => 0, 'assessments' => 0, 'published' => 0];

try {
    $components = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_components WHERE school_id = 1 AND is_active = 1");
    $stats['components'] = $components['total'] ?? 0;
} catch (Exception $e) {}

try {
    $configs = $db->fetchOne("SELECT COUNT(*) as total FROM assessment_configs WHERE school_id = 1 AND is_active = 1");
    $stats['configs'] = $configs['total'] ?? 0;
} catch (Exception $e) {}

try {
    $assessments = $db->fetchOne("SELECT COUNT(*) as total FROM assessments WHERE school_id = 1 AND is_active = 1");
    $stats['assessments'] = $assessments['total'] ?? 0;
} catch (Exception $e) {}

try {
    $published = $db->fetchOne("SELECT COUNT(*) as total FROM assessments WHERE school_id = 1 AND is_published = 1");
    $stats['published'] = $published['total'] ?? 0;
} catch (Exception $e) {}

// Get recent data
$recentConfigs = [];
try {
    $recentConfigs = $db->fetchAll("
        SELECT ac.*, (SELECT COUNT(*) FROM config_components cc WHERE cc.config_id = ac.id AND cc.is_active = 1) as component_count
        FROM assessment_configs ac
        WHERE ac.school_id = 1 AND ac.is_active = 1
        ORDER BY ac.created_at DESC LIMIT 5
    ");
} catch (Exception $e) {}

$recentComponents = [];
try {
    $recentComponents = $db->fetchAll("
        SELECT * FROM assessment_components
        WHERE school_id = 1 AND is_active = 1
        ORDER BY display_order ASC, created_at DESC LIMIT 5
    ");
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Configuration - Admin</title>
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
        .flex { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .text-center { text-align: center; }
        .text-muted { color: #999; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .header { flex-direction: column; align-items: stretch; text-align: center; }
            .header .header-actions { justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-tasks"></i> Assessment Configuration</h1>
                <p>Manage assessment components, configurations, and view results</p>
            </div>
            <div class="header-actions">
                <a href="/admin/assessment_components.php?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Component</a>
                <a href="/admin/assessment_configs.php?action=add" class="btn btn-success"><i class="fas fa-plus"></i> Config</a>
                <a href="/admin/assessment_config.php" class="btn btn-outline"><i class="fas fa-sync"></i> Refresh</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message message-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message message-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon purple"><i class="fas fa-cubes"></i></div>
                <div class="number"><?php echo $stats['components']; ?></div>
                <div class="label">Components</div>
            </div>
            <div class="stat-card">
                <div class="icon blue"><i class="fas fa-layer-group"></i></div>
                <div class="number"><?php echo $stats['configs']; ?></div>
                <div class="label">Configurations</div>
            </div>
            <div class="stat-card">
                <div class="icon orange"><i class="fas fa-file-signature"></i></div>
                <div class="number"><?php echo $stats['assessments']; ?></div>
                <div class="label">Assessments</div>
            </div>
            <div class="stat-card">
                <div class="icon green"><i class="fas fa-check-circle"></i></div>
                <div class="number"><?php echo $stats['published']; ?></div>
                <div class="label">Published</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="flex" style="gap: 15px; padding: 10px 0;">
                <a href="/admin/assessment_components.php?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> New Component</a>
                <a href="/admin/assessment_configs.php?action=add" class="btn btn-success"><i class="fas fa-plus"></i> New Config</a>
                <a href="/admin/assessment_results.php" class="btn btn-outline"><i class="fas fa-chart-bar"></i> View Results</a>
            </div>
        </div>

        <?php if (empty($recentConfigs) && empty($recentComponents)): ?>
            <div class="card">
                <div style="text-align: center; padding: 40px 20px;">
                    <div style="font-size: 48px; color: #c5cae9; margin-bottom: 15px;"><i class="fas fa-tasks"></i></div>
                    <h4 style="color: #1a237e;">Welcome to Assessment Configuration</h4>
                    <p style="color: #999; max-width: 400px; margin: 0 auto 15px;">Get started by creating assessment components and configurations. Components are the building blocks (e.g., CA1, Exam), and configurations define how they are weighted.</p>
                    <div class="flex" style="justify-content: center; gap: 10px;">
                        <a href="/admin/assessment_components.php?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Create Component</a>
                        <a href="/admin/assessment_configs.php?action=add" class="btn btn-success"><i class="fas fa-plus"></i> Create Config</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if (!empty($recentConfigs)): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group"></i> Recent Configurations</h3>
                    <a href="/admin/assessment_configs.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Components</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentConfigs as $config): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($config['config_name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($config['config_code']); ?></code></td>
                                <td><span class="badge badge-info"><?php echo $config['component_count']; ?> components</span></td>
                                <td>
                                    <?php if ($config['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($recentComponents)): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-cubes"></i> Recent Components</h3>
                    <a href="/admin/assessment_components.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Default Weight</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentComponents as $component): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($component['component_name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($component['component_code']); ?></code></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($component['component_type']); ?></span></td>
                                <td><?php echo number_format($component['default_weight'], 2); ?>%</td>
                                <td>
                                    <?php if ($component['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>