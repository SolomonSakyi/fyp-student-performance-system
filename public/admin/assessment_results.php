<?php
/**
 * Assessment Results View
 * Admin interface for viewing assessment results and statistics
 */

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();

$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$assessmentId = isset($_GET['id']) ? intval($_GET['id']) : null;

// Get statistics
$stats = [
    'total' => 0,
    'published' => 0,
    'draft' => 0,
    'scores' => 0,
    'students' => 0,
    'avg_score' => 0
];

try {
    $total = $db->fetchOne("SELECT COUNT(*) as total FROM assessments WHERE school_id = 1");
    $stats['total'] = $total['total'] ?? 0;
} catch (Exception $e) {}

try {
    $published = $db->fetchOne("SELECT COUNT(*) as total FROM assessments WHERE school_id = 1 AND is_published = 1");
    $stats['published'] = $published['total'] ?? 0;
} catch (Exception $e) {}

try {
    $draft = $db->fetchOne("SELECT COUNT(*) as total FROM assessments WHERE school_id = 1 AND is_published = 0");
    $stats['draft'] = $draft['total'] ?? 0;
} catch (Exception $e) {}

try {
    $scores = $db->fetchOne("SELECT COUNT(*) as total FROM student_scores WHERE school_id = 1");
    $stats['scores'] = $scores['total'] ?? 0;
} catch (Exception $e) {}

try {
    $students = $db->fetchOne("SELECT COUNT(*) as total FROM students WHERE school_id = 1 AND is_active = 1");
    $stats['students'] = $students['total'] ?? 0;
} catch (Exception $e) {}

try {
    $avg = $db->fetchOne("SELECT AVG(percentage_score) as avg FROM student_scores WHERE school_id = 1");
    $stats['avg_score'] = number_format($avg['avg'] ?? 0, 1);
} catch (Exception $e) {}

// Get all assessments with details
$assessments = [];
try {
    $assessments = $db->fetchAll("
        SELECT 
            a.*,
            s.subject_name,
            cs.section_name,
            gl.level_name,
            at.name as assessment_type_name,
            (SELECT COUNT(*) FROM student_scores ss WHERE ss.assessment_id = a.id) as score_count,
            (SELECT AVG(ss.percentage_score) FROM student_scores ss WHERE ss.assessment_id = a.id) as avg_score
        FROM assessments a
        JOIN subjects s ON a.subject_id = s.id
        JOIN class_sections cs ON a.class_section_id = cs.id
        JOIN grade_levels gl ON cs.grade_level_id = gl.id
        JOIN assessment_types at ON a.assessment_type_id = at.id
        WHERE a.school_id = 1
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
} catch (Exception $e) {}

// Get a specific assessment for detailed view
$assessmentDetails = null;
$assessmentScores = [];
if ($assessmentId) {
    try {
        $assessmentDetails = $db->fetchOne("
            SELECT 
                a.*,
                s.subject_name,
                cs.section_name,
                gl.level_name,
                at.name as assessment_type_name,
                (SELECT COUNT(*) FROM student_scores ss WHERE ss.assessment_id = a.id) as score_count
            FROM assessments a
            JOIN subjects s ON a.subject_id = s.id
            JOIN class_sections cs ON a.class_section_id = cs.id
            JOIN grade_levels gl ON cs.grade_level_id = gl.id
            JOIN assessment_types at ON a.assessment_type_id = at.id
            WHERE a.id = ? AND a.school_id = 1
        ", [$assessmentId]);
        
        if ($assessmentDetails) {
            $assessmentScores = $db->fetchAll("
                SELECT 
                    ss.*,
                    s.admission_number,
                    s.first_name,
                    s.last_name
                FROM student_scores ss
                JOIN students s ON ss.student_id = s.id
                WHERE ss.assessment_id = ? AND ss.school_id = 1
                ORDER BY ss.percentage_score DESC
            ", [$assessmentId]);
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Results - Admin</title>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-card .number { font-size: 28px; font-weight: bold; color: #1a237e; }
        .stat-card .label { color: #666; font-size: 12px; margin-top: 5px; }
        .stat-card .icon { font-size: 24px; margin-bottom: 5px; }
        .stat-card .icon.purple { color: #7c4dff; }
        .stat-card .icon.blue { color: #448aff; }
        .stat-card .icon.green { color: #69f0ae; }
        .stat-card .icon.orange { color: #ffab40; }
        .stat-card .icon.red { color: #ff5252; }
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
        .message {
            padding: 12px 18px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .message-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .message-error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .message-info { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }
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
        @media (max-width: 768px) {
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .header { flex-direction: column; align-items: stretch; text-align: center; }
            .header .header-actions { justify-content: center; }
            table { font-size: 12px; }
            table th, table td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <<?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-chart-bar"></i> Assessment Results</h1>
                <p>View all assessment results and student performance</p>
            </div>
            <div class="header-actions">
                <?php if ($assessmentId): ?>
                    <a href="/admin/assessment_results.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
                <?php endif; ?>
                <a href="/admin/assessment_config.php" class="btn btn-outline"><i class="fas fa-cog"></i> Config</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message message-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message message-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($assessmentId && $assessmentDetails): ?>
            <!-- Detailed Assessment View -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><?php echo htmlspecialchars($assessmentDetails['assessment_title']); ?></h3>
                        <p style="color: #666; font-size: 13px;">
                            <?php echo htmlspecialchars($assessmentDetails['subject_name']); ?> | 
                            <?php echo htmlspecialchars($assessmentDetails['section_name']); ?> | 
                            <?php echo htmlspecialchars($assessmentDetails['level_name']); ?>
                        </p>
                    </div>
                    <div>
                        <?php if ($assessmentDetails['is_published']): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> Published</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><i class="fas fa-pencil-alt"></i> Draft</span>
                        <?php endif; ?>
                        <span class="badge badge-info"><?php echo $assessmentDetails['score_count']; ?> scores</span>
                    </div>
                </div>
                <div style="margin-bottom: 15px;">
                    <span class="badge badge-secondary">Date: <?php echo date('d M Y', strtotime($assessmentDetails['assessment_date'])); ?></span>
                    <span class="badge badge-secondary">Type: <?php echo htmlspecialchars($assessmentDetails['assessment_type_name']); ?></span>
                    <span class="badge badge-secondary">Max Score: <?php echo $assessmentDetails['max_score']; ?></span>
                </div>
                
                <?php if (!empty($assessmentScores)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Admission</th>
                                    <th>Student Name</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; ?>
                                <?php foreach ($assessmentScores as $score): ?>
                                <tr>
                                    <td><?php echo $rank++; ?></td>
                                    <td><?php echo htmlspecialchars($score['admission_number']); ?></td>
                                    <td><?php echo htmlspecialchars($score['first_name'] . ' ' . $score['last_name']); ?></td>
                                    <td><?php echo $score['score_obtained']; ?> / <?php echo $assessmentDetails['max_score']; ?></td>
                                    <td><?php echo number_format($score['percentage_score'], 1); ?>%</td>
                                    <td><span class="badge badge-info"><?php echo $score['grade'] ?? 'N/A'; ?></span></td>
                                    <td>
                                        <?php if (($score['percentage_score'] ?? 0) >= 50): ?>
                                            <span class="badge badge-success">Pass</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Fail</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon"><i class="fas fa-inbox"></i></div>
                        <h4>No Scores Yet</h4>
                        <p>No student scores have been entered for this assessment.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon purple"><i class="fas fa-file-signature"></i></div>
                    <div class="number"><?php echo $stats['total']; ?></div>
                    <div class="label">Total Assessments</div>
                </div>
                <div class="stat-card">
                    <div class="icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="number"><?php echo $stats['published']; ?></div>
                    <div class="label">Published</div>
                </div>
                <div class="stat-card">
                    <div class="icon orange"><i class="fas fa-pencil-alt"></i></div>
                    <div class="number"><?php echo $stats['draft']; ?></div>
                    <div class="label">Drafts</div>
                </div>
                <div class="stat-card">
                    <div class="icon blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="number"><?php echo $stats['scores']; ?></div>
                    <div class="label">Scores Entered</div>
                </div>
                <div class="stat-card">
                    <div class="icon red"><i class="fas fa-users"></i></div>
                    <div class="number"><?php echo $stats['students']; ?></div>
                    <div class="label">Students</div>
                </div>
                <div class="stat-card">
                    <div class="icon green"><i class="fas fa-chart-line"></i></div>
                    <div class="number"><?php echo $stats['avg_score']; ?>%</div>
                    <div class="label">Average Score</div>
                </div>
            </div>

            <!-- All Assessments -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> All Assessments</h3>
                    <span class="badge-count"><?php echo $stats['total']; ?> assessments</span>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Scores</th>
                                <th>Avg</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($assessments)): ?>
                                <?php foreach ($assessments as $ass): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ass['assessment_title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($ass['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ass['section_name']); ?></td>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars($ass['assessment_type_name']); ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($ass['assessment_date'])); ?></td>
                                    <td><?php echo $ass['score_count']; ?></td>
                                    <td><?php echo number_format($ass['avg_score'] ?? 0, 1); ?>%</td>
                                    <td>
                                        <?php if ($ass['is_published']): ?>
                                            <span class="badge badge-success">Published</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/admin/assessment_results.php?id=<?php echo $ass['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="text-center text-muted" style="padding: 30px;">No assessments found. Assessments will appear here once teachers create them.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>