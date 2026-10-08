<?php
/**
 * Reports Management
 */

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();

// Get statistics
$stats = [];
try {
    $students = $db->fetchOne("SELECT COUNT(*) as total FROM students WHERE school_id = 1 AND is_active = 1");
    $stats['students'] = $students['total'] ?? 0;
} catch (Exception $e) { $stats['students'] = 0; }

try {
    $teachers = $db->fetchOne("SELECT COUNT(*) as total FROM staff WHERE school_id = 1 AND is_active = 1 AND is_teaching_staff = 1");
    $stats['teachers'] = $teachers['total'] ?? 0;
} catch (Exception $e) { $stats['teachers'] = 0; }

try {
    $classes = $db->fetchOne("SELECT COUNT(*) as total FROM class_sections WHERE school_id = 1 AND is_active = 1");
    $stats['classes'] = $classes['total'] ?? 0;
} catch (Exception $e) { $stats['classes'] = 0; }

try {
    $assessments = $db->fetchOne("SELECT COUNT(*) as total FROM assessments WHERE school_id = 1 AND is_active = 1");
    $stats['assessments'] = $assessments['total'] ?? 0;
} catch (Exception $e) { $stats['assessments'] = 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin</title>
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
        .stat-card .number { font-size: 32px; font-weight: bold; color: #1a237e; }
        .stat-card .label { color: #666; font-size: 13px; margin-top: 5px; }
        .stat-card .icon { font-size: 28px; margin-bottom: 8px; }
        .stat-card .icon.blue { color: #448aff; }
        .stat-card .icon.green { color: #66bb6a; }
        .stat-card .icon.orange { color: #ffab40; }
        .stat-card .icon.red { color: #ff5252; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-file-alt"></i> Reports</h1>
                <p>View and generate school reports</p>
            </div>
            <div>
                <a href="#" class="btn btn-primary"><i class="fas fa-download"></i> Export</a>
                <a href="#" class="btn btn-success"><i class="fas fa-print"></i> Print</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon blue"><i class="fas fa-user-graduate"></i></div>
                <div class="number"><?php echo $stats['students']; ?></div>
                <div class="label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="icon green"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="number"><?php echo $stats['teachers']; ?></div>
                <div class="label">Total Teachers</div>
            </div>
            <div class="stat-card">
                <div class="icon orange"><i class="fas fa-users"></i></div>
                <div class="number"><?php echo $stats['classes']; ?></div>
                <div class="label">Total Classes</div>
            </div>
            <div class="stat-card">
                <div class="icon red"><i class="fas fa-tasks"></i></div>
                <div class="number"><?php echo $stats['assessments']; ?></div>
                <div class="label">Assessments</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Available Reports</h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; padding: 10px 0;">
                <a href="#" style="display: block; padding: 20px; background: #f5f5f5; border-radius: 8px; text-decoration: none; color: #333; text-align: center; transition: 0.3s;">
                    <i class="fas fa-user-graduate" style="font-size: 32px; color: #1a237e; display: block; margin-bottom: 10px;"></i>
                    <strong>Student Report</strong>
                    <p style="font-size: 12px; color: #999; margin-top: 5px;">View student profiles and performance</p>
                </a>
                <a href="#" style="display: block; padding: 20px; background: #f5f5f5; border-radius: 8px; text-decoration: none; color: #333; text-align: center; transition: 0.3s;">
                    <i class="fas fa-chart-bar" style="font-size: 32px; color: #1a237e; display: block; margin-bottom: 10px;"></i>
                    <strong>Assessment Report</strong>
                    <p style="font-size: 12px; color: #999; margin-top: 5px;">View assessment results and statistics</p>
                </a>
                <a href="#" style="display: block; padding: 20px; background: #f5f5f5; border-radius: 8px; text-decoration: none; color: #333; text-align: center; transition: 0.3s;">
                    <i class="fas fa-coins" style="font-size: 32px; color: #1a237e; display: block; margin-bottom: 10px;"></i>
                    <strong>Financial Report</strong>
                    <p style="font-size: 12px; color: #999; margin-top: 5px;">View fee collections and payments</p>
                </a>
                <a href="#" style="display: block; padding: 20px; background: #f5f5f5; border-radius: 8px; text-decoration: none; color: #333; text-align: center; transition: 0.3s;">
                    <i class="fas fa-calendar-alt" style="font-size: 32px; color: #1a237e; display: block; margin-bottom: 10px;"></i>
                    <strong>Attendance Report</strong>
                    <p style="font-size: 12px; color: #999; margin-top: 5px;">View student attendance records</p>
                </a>
            </div>
        </div>
    </div>
</body>
</html>