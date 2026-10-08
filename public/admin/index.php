<?php
require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/helpers/LoggerHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();
$logger = new LoggerHelper();
$adminName = AdminHelper::getUsername();

// Get statistics with error handling for missing tables
$stats = [
    'students' => 0,
    'teachers' => 0,
    'classes' => 0,
    'subjects' => 0,
    'grade_levels' => 0,
    'users' => 0,
    'pending' => 0
];

// Try to get each count with fallback
try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM students WHERE is_active = 1");
    $stats['students'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['students'] = 0;
}

try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM staff WHERE is_active = 1");
    $stats['teachers'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['teachers'] = 0;
}

try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM class_sections WHERE is_active = 1");
    $stats['classes'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['classes'] = 0;
}

try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM subjects WHERE is_active = 1");
    $stats['subjects'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['subjects'] = 0;
}

try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM grade_levels WHERE is_active = 1");
    $stats['grade_levels'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['grade_levels'] = 0;
}

try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM users WHERE is_active = 1");
    $stats['users'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['users'] = 0;
}

try {
    $result = $db->fetchOne("SELECT COUNT(*) AS count FROM grade_calculations WHERE is_calculated = 0");
    $stats['pending'] = $result['count'] ?? 0;
} catch (Exception $e) {
    $stats['pending'] = 0;
}

// Get recent enrollments
$recent = [];
try {
    $recent = $db->fetchAll("
        SELECT 
            s.first_name,
            s.last_name,
            cs.section_name,
            se.enrollment_date,
            se.enrollment_status
        FROM student_enrollments se
        JOIN students s ON se.student_id = s.id
        JOIN class_sections cs ON se.class_section_id = cs.id
        ORDER BY se.created_at DESC
        LIMIT 10
    ");
} catch (Exception $e) {
    $recent = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EduTrack</title>
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
        .sidebar .logo {
            text-align: center;
            padding: 10px 0 20px;
            border-bottom: 1px solid #2a4c8e;
            font-size: 18px;
            font-weight: bold;
        }
        .sidebar .logo span { color: #f59e0b; }
        .sidebar .nav-item {
            display: block;
            padding: 12px 25px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .sidebar .nav-item:hover {
            background: #2a4c8e;
            color: #fff;
        }
        .sidebar .nav-item.active {
            background: #2a4c8e;
            color: #fff;
        }
        .sidebar .nav-item .icon { margin-right: 10px; }
        .main-content {
            margin-left: 220px;
            padding: 20px;
        }
        .header {
            background: #fff;
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 { color: #1a3c6e; font-size: 24px; }
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header .logout-btn {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .header .logout-btn:hover { background: #b91c1c; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #1a3c6e;
        }
        .stat-card .label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .stat-card.pending .number { color: #dc2626; }
        .quick-actions {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .quick-actions h3 {
            color: #1a3c6e;
            margin-bottom: 15px;
        }
        .quick-actions .btn-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .quick-actions .btn {
            padding: 12px 20px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        .quick-actions .btn:hover { background: #2a4c8e; }
        .quick-actions .btn.green { background: #16a34a; }
        .quick-actions .btn.green:hover { background: #15803d; }
        .quick-actions .btn.orange { background: #f59e0b; }
        .quick-actions .btn.orange:hover { background: #d97706; }
        .content-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .content-card h3 {
            color: #1a3c6e;
            margin-bottom: 10px;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 10px;
        }
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 10px;
            text-align: left;
        }
        table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table tr:hover { background: #f8fafc; }
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>📊 Dashboard</h1>
                <p style="color: #666; font-size: 14px;">Welcome back, <?php echo htmlspecialchars($adminName); ?>!</p>
            </div>
            <div class="user-info">
                <span style="color: #666;"><?php echo date('l, F j, Y'); ?></span>
                <button class="logout-btn" onclick="window.location.href='/admin/logout.php'">🚪 Logout</button>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['students']; ?></div>
                <div class="label">👨‍🎓 Students</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['teachers']; ?></div>
                <div class="label">👨‍🏫 Teachers</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['classes']; ?></div>
                <div class="label">📚 Classes</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['subjects']; ?></div>
                <div class="label">📖 Subjects</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['grade_levels']; ?></div>
                <div class="label">📊 Grade Levels</div>
            </div>
            <div class="stat-card pending">
                <div class="number"><?php echo $stats['pending']; ?></div>
                <div class="label">⏳ Pending Results</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h3>⚡ Quick Actions</h3>
            <div class="btn-grid">
                <a href="/admin/students.php?action=add" class="btn green">➕ Add Student</a>
                <a href="/admin/teachers.php?action=add" class="btn">👨‍🏫 Add Teacher</a>
                <a href="/admin/classes.php?action=add" class="btn">📚 Add Class</a>
                <a href="/admin/subjects.php?action=add" class="btn">📖 Add Subject</a>
                <a href="/admin/academic.php" class="btn orange">📅 Manage Academic Year</a>
                <a href="/admin/reports.php" class="btn green">📄 Generate Reports</a>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="content-card">
            <h3>📋 Recent Activity</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Action</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent)): ?>
                            <?php foreach ($recent as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['section_name']); ?></td>
                                    <td>Enrolled</td>
                                    <td><?php echo date('d M Y', strtotime($row['enrollment_date'])); ?></td>
                                    <td><span class="status-badge active"><?php echo htmlspecialchars($row['enrollment_status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; color: #999;">No recent activity.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div style="text-align: center; color: #888; font-size: 12px; margin-top: 20px; padding: 20px;">
            EduTrack - Student Performance Management System | Admin Panel
        </div>
    </div>
</body>
</html>