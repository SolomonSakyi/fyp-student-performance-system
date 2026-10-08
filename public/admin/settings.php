<?php
/**
 * Settings Management
 */

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();

// Get school settings
$settings = [];
try {
    $settings = $db->fetchOne("SELECT * FROM schools WHERE id = 1");
} catch (Exception $e) {
    $error = "Error loading settings: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin</title>
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
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        .settings-group {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .settings-group h4 {
            color: #1a237e;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .settings-group .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }
        .settings-group .setting-item:last-child { border-bottom: none; }
        .settings-group .setting-item .label { color: #666; }
        .settings-group .setting-item .value { font-weight: 500; color: #1a237e; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .settings-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-cog"></i> Settings</h1>
                <p>Configure school settings and preferences</p>
            </div>
            <div>
                <a href="#" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-school"></i> School Information</h3>
            </div>
            <div class="settings-grid">
                <div class="settings-group">
                    <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                    <div class="setting-item">
                        <span class="label">School Name</span>
                        <span class="value"><?php echo htmlspecialchars($settings['school_name'] ?? 'EduTrack Demo School'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">School Code</span>
                        <span class="value"><?php echo htmlspecialchars($settings['school_code'] ?? 'EDU001'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">School Type</span>
                        <span class="value"><?php echo ucfirst($settings['school_type'] ?? 'Private'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Level</span>
                        <span class="value"><?php echo ucfirst($settings['level'] ?? 'Combined'); ?></span>
                    </div>
                </div>

                <div class="settings-group">
                    <h4><i class="fas fa-map-marker-alt"></i> Address</h4>
                    <div class="setting-item">
                        <span class="label">Address</span>
                        <span class="value"><?php echo htmlspecialchars($settings['address'] ?? '123 Education Street'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">City</span>
                        <span class="value"><?php echo htmlspecialchars($settings['city'] ?? 'Accra'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">State/Region</span>
                        <span class="value"><?php echo htmlspecialchars($settings['state'] ?? 'Greater Accra'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Country</span>
                        <span class="value"><?php echo htmlspecialchars($settings['country'] ?? 'Ghana'); ?></span>
                    </div>
                </div>

                <div class="settings-group">
                    <h4><i class="fas fa-phone"></i> Contact</h4>
                    <div class="setting-item">
                        <span class="label">Phone</span>
                        <span class="value"><?php echo htmlspecialchars($settings['phone'] ?? '+233-123-456-789'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Email</span>
                        <span class="value"><?php echo htmlspecialchars($settings['email'] ?? 'info@edutrackdemo.edu.gh'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Website</span>
                        <span class="value"><?php echo htmlspecialchars($settings['website'] ?? 'www.edutrackdemo.edu.gh'); ?></span>
                    </div>
                </div>

                <div class="settings-group">
                    <h4><i class="fas fa-calendar-alt"></i> Academic</h4>
                    <div class="setting-item">
                        <span class="label">Terminology</span>
                        <span class="value"><?php echo htmlspecialchars($settings['grade_level_terminology'] ?? 'Basic'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Start From</span>
                        <span class="value"><?php echo htmlspecialchars($settings['start_from'] ?? 'Nursery 1'); ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Has Primary</span>
                        <span class="value"><?php echo ($settings['has_primary'] ?? 1) ? '✅ Yes' : '❌ No'; ?></span>
                    </div>
                    <div class="setting-item">
                        <span class="label">Has JHS</span>
                        <span class="value"><?php echo ($settings['has_jhs'] ?? 1) ? '✅ Yes' : '❌ No'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>