<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if logged in
if (!isset($_SESSION['portal_student_id'])) {
    header('Location: /portal_login.php');
    exit;
}

require_once __DIR__ . '/../app/helpers/DatabaseHelper.php';

$studentId = $_SESSION['portal_student_id'];
$username = $_SESSION['portal_username'];

$db = DatabaseHelper::getInstance();

// Get student info
$sql = "
    SELECT 
        s.*,
        cs.section_name,
        gl.level_name,
        ay.year_name
    FROM students s
    LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
    LEFT JOIN class_sections cs ON se.class_section_id = cs.id
    LEFT JOIN grade_levels gl ON cs.grade_level_id = gl.id
    LEFT JOIN academic_years ay ON se.academic_year_id = ay.id AND ay.is_current = 1
    WHERE s.id = ?
";
$student = $db->fetchOne($sql, [$studentId]);

// Get latest academic summary
$summarySql = "
    SELECT * FROM student_grade_summary
    WHERE student_id = ?
      AND is_active = 1
    ORDER BY academic_year_id DESC, academic_term_id DESC
    LIMIT 1
";
$summary = $db->fetchOne($summarySql, [$studentId]);

// Get attendance summary
$attendanceSql = "
    SELECT 
        total_school_days,
        days_present,
        attendance_percentage
    FROM attendance_summary
    WHERE student_id = ?
    ORDER BY academic_year_id DESC, academic_term_id DESC
    LIMIT 1
";
$attendance = $db->fetchOne($attendanceSql, [$studentId]);

// Get available report cards
$reportSql = "
    SELECT 
        sgs.*,
        ay.year_name,
        at.term_name
    FROM student_grade_summary sgs
    JOIN academic_years ay ON sgs.academic_year_id = ay.id
    JOIN academic_terms at ON sgs.academic_term_id = at.id
    WHERE sgs.student_id = ?
      AND sgs.is_active = 1
    ORDER BY sgs.academic_year_id DESC, sgs.academic_term_id DESC
";
$reports = $db->fetchAll($reportSql, [$studentId]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - EduTrack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .header {
            background: #fff;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header .user-info h1 {
            font-size: 22px;
            color: #1a3c6e;
        }
        .header .user-info p {
            color: #666;
            font-size: 14px;
        }
        .header .logout-btn {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .header .logout-btn:hover {
            background: #b91c1c;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .card h3 {
            color: #1a3c6e;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 10px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .card .stat {
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
        }
        .card .label {
            color: #666;
            font-size: 14px;
        }
        .card .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        .card .detail-row .label {
            font-weight: bold;
            color: #333;
        }
        .full-width {
            grid-column: span 2;
        }
        .report-card-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .report-card-item .info {
            font-size: 14px;
        }
        .report-card-item .info .term {
            font-weight: bold;
        }
        .report-card-item .info .grade {
            color: #666;
        }
        .btn-view {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-view:hover {
            background: #1d4ed8;
        }
        .btn-download {
            background: #16a34a;
            color: #fff;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            margin-left: 5px;
        }
        .btn-download:hover {
            background: #15803d;
        }
        .no-data {
            color: #999;
            font-style: italic;
            padding: 10px 0;
        }
        .change-pwd-link {
            color: #2563eb;
            text-decoration: none;
            margin-right: 15px;
        }
        .change-pwd-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            .full-width {
                grid-column: span 1;
            }
            .header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="user-info">
                <h1>🎓 Welcome, <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
                <p>Admission: <?php echo htmlspecialchars($student['admission_number'] ?? ''); ?> 
                   | Class: <?php echo htmlspecialchars($student['section_name'] ?? 'Not Assigned'); ?>
                   | <?php echo htmlspecialchars($student['year_name'] ?? ''); ?></p>
            </div>
            <div>
                <a href="portal_change_password.php" class="change-pwd-link">🔑 Change Password</a>
                <button class="logout-btn" onclick="window.location.href='portal_logout.php'">🚪 Logout</button>
            </div>
        </div>

        <div class="grid">
            <!-- Academic Summary -->
            <div class="card">
                <h3>📊 Academic Summary</h3>
                <?php if ($summary): ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <div class="stat"><?php echo number_format($summary['average'] ?? 0, 1); ?>%</div>
                            <div class="label">Average</div>
                        </div>
                        <div>
                            <div class="stat"><?php echo htmlspecialchars($summary['grade'] ?? '-'); ?></div>
                            <div class="label">Grade</div>
                        </div>
                        <div>
                            <div class="stat"><?php echo $summary['class_position'] ?? '-'; ?></div>
                            <div class="label">Position</div>
                        </div>
                        <div>
                            <div class="stat"><?php echo $summary['total_subjects'] ?? 0; ?></div>
                            <div class="label">Subjects</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">No academic data available yet.</div>
                <?php endif; ?>
            </div>

            <!-- Attendance Summary -->
            <div class="card">
                <h3>📋 Attendance</h3>
                <?php if ($attendance): ?>
                    <div>
                        <div style="margin-bottom: 10px;">
                            <div class="stat"><?php echo number_format($attendance['attendance_percentage'] ?? 0, 1); ?>%</div>
                            <div class="label">Attendance Rate</div>
                        </div>
                        <div class="detail-row">
                            <span class="label">Days Present</span>
                            <span><?php echo $attendance['days_present'] ?? 0; ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Total School Days</span>
                            <span><?php echo $attendance['total_school_days'] ?? 0; ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">No attendance data available yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Report Cards -->
        <div class="card full-width">
            <h3>📄 Report Cards</h3>
            <?php if (!empty($reports)): ?>
                <?php foreach ($reports as $report): ?>
                    <div class="report-card-item">
                        <div class="info">
                            <span class="term"><?php echo htmlspecialchars($report['term_name'] ?? ''); ?></span>
                            <span style="color: #888; margin: 0 5px;">|</span>
                            <span><?php echo htmlspecialchars($report['year_name'] ?? ''); ?></span>
                            <span style="color: #888; margin: 0 10px;">|</span>
                            <span class="grade">Grade: <?php echo htmlspecialchars($report['grade'] ?? '-'); ?></span>
                            <span style="color: #888; margin: 0 10px;">|</span>
                            <span class="grade">Average: <?php echo number_format($report['average'] ?? 0, 1); ?>%</span>
                        </div>
                        <div>
                            <a href="portal_report.php?student_id=<?php echo $studentId; ?>&year_id=<?php echo $report['academic_year_id']; ?>&term_id=<?php echo $report['academic_term_id']; ?>" class="btn-view" target="_blank">📄 View</a>
                            <a href="portal_download.php?student_id=<?php echo $studentId; ?>&year_id=<?php echo $report['academic_year_id']; ?>&term_id=<?php echo $report['academic_term_id']; ?>" class="btn-download">📥 PDF</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">No report cards available yet.</div>
            <?php endif; ?>
        </div>

        <!-- Personal Information -->
        <div class="card full-width" style="margin-top: 20px;">
            <h3>👤 Personal Information</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                <div class="detail-row"><span class="label">Name</span><span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span></div>
                <div class="detail-row"><span class="label">Admission Number</span><span><?php echo htmlspecialchars($student['admission_number'] ?? ''); ?></span></div>
                <div class="detail-row"><span class="label">Gender</span><span><?php echo htmlspecialchars($student['gender'] ?? ''); ?></span></div>
                <div class="detail-row"><span class="label">Date of Birth</span><span><?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?></span></div>
                <div class="detail-row"><span class="label">Class</span><span><?php echo htmlspecialchars($student['section_name'] ?? 'Not Assigned'); ?></span></div>
                <div class="detail-row"><span class="label">Level</span><span><?php echo htmlspecialchars($student['level_name'] ?? ''); ?></span></div>
            </div>
        </div>

        <div style="text-align: center; color: #888; font-size: 12px; margin-top: 20px;">
            EduTrack - Student Performance Management System
        </div>
    </div>
</body>
</html>