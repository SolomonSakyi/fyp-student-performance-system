<?php
/**
 * attendance.php
 * 
 * Complete Enterprise Attendance Management Admin Interface
 * Features: Dashboard, Mark Attendance, Risk Analysis, Biometrics, RFID, QR Codes, Exceptions
 * 
 * @package EduTrack
 * @subpackage Admin
 */

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/services/Attendance/AttendanceService.php';

AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();
$service = new AttendanceService();

$action = $_GET['action'] ?? 'dashboard';
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$activeTab = $_GET['tab'] ?? 'dashboard';
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$sessionDate = $_GET['date'] ?? date('Y-m-d');
$sessionType = $_GET['type'] ?? 'morning';
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$exceptionId = isset($_GET['exception_id']) ? (int)$_GET['exception_id'] : 0;

// ================================================================
// HANDLE POST ACTIONS
// ================================================================

// Save attendance
if (isset($_POST['save_attendance'])) {
    $classId = (int)$_POST['class_id'];
    $date = $_POST['date'] ?? date('Y-m-d');
    $type = $_POST['session_type'] ?? 'morning';
    $attendanceData = $_POST['attendance'] ?? [];

    if ($classId && !empty($attendanceData)) {
        $result = $service->saveClassAttendance([
            'class_id' => $classId,
            'date' => $date,
            'session_type' => $type,
            'attendance' => $attendanceData,
            'method_id' => 7
        ]);

        if ($result['success']) {
            header('Location: /admin/attendance.php?tab=mark&msg=' . urlencode($result['message']) . '&class_id=' . $classId . '&date=' . $date);
        } else {
            header('Location: /admin/attendance.php?tab=mark&error=' . urlencode($result['message']) . '&class_id=' . $classId . '&date=' . $date);
        }
        exit;
    }
}

// Save exception
if (isset($_POST['save_exception'])) {
    try {
        $data = [
            'student_id' => $_POST['student_id'] ?? null,
            'staff_id' => $_POST['staff_id'] ?? null,
            'exception_date' => $_POST['exception_date'],
            'start_time' => $_POST['start_time'] ?? null,
            'end_time' => $_POST['end_time'] ?? null,
            'exception_type' => $_POST['exception_type'] ?? 'other',
            'reason' => $_POST['reason'],
            'supporting_document' => $_POST['supporting_document'] ?? null,
            'notes' => $_POST['notes'] ?? null
        ];

        $result = $service->createException($data);

        if ($result['success']) {
            header('Location: /admin/attendance.php?tab=exceptions&msg=' . urlencode($result['message']));
        } else {
            header('Location: /admin/attendance.php?tab=exceptions&error=' . urlencode($result['message']));
        }
        exit;

    } catch (Exception $e) {
        header('Location: /admin/attendance.php?tab=exceptions&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Register RFID card
if (isset($_POST['register_rfid'])) {
    try {
        $data = [
            'card_number' => $_POST['card_number'],
            'uid' => $_POST['uid'],
            'student_id' => $_POST['student_id'] ?? null,
            'staff_id' => $_POST['staff_id'] ?? null,
            'card_type' => $_POST['card_type'] ?? 'student',
            'issued_date' => $_POST['issued_date'] ?? date('Y-m-d'),
            'expiry_date' => $_POST['expiry_date'] ?? null,
            'issued_by' => 1
        ];

        $result = $service->registerRfidCard($data);

        if ($result['success']) {
            header('Location: /admin/attendance.php?tab=rfid&msg=' . urlencode($result['message']));
        } else {
            header('Location: /admin/attendance.php?tab=rfid&error=' . urlencode($result['message']));
        }
        exit;

    } catch (Exception $e) {
        header('Location: /admin/attendance.php?tab=rfid&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Register device
if (isset($_POST['register_device'])) {
    try {
        $data = [
            'device_name' => $_POST['device_name'],
            'device_serial' => $_POST['device_serial'],
            'device_type_id' => $_POST['device_type_id'],
            'device_code' => $_POST['device_code'] ?? $_POST['device_serial'],
            'location' => $_POST['location'] ?? null,
            'ip_address' => $_POST['ip_address'] ?? null,
            'port_number' => $_POST['port_number'] ?? null
        ];

        $result = $service->registerDevice($data);

        if ($result['success']) {
            header('Location: /admin/attendance.php?tab=devices&msg=' . urlencode($result['message']));
        } else {
            header('Location: /admin/attendance.php?tab=devices&error=' . urlencode($result['message']));
        }
        exit;

    } catch (Exception $e) {
        header('Location: /admin/attendance.php?tab=devices&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Complete session
if (isset($_GET['complete_session'])) {
    $sessionId = (int)$_GET['complete_session'];
    $result = $service->completeSession($sessionId, 1);

    if ($result['success']) {
        header('Location: /admin/attendance.php?tab=mark&msg=' . urlencode($result['message']));
    } else {
        header('Location: /admin/attendance.php?tab=mark&error=' . urlencode($result['message']));
    }
    exit;
}

// ================================================================
// GET DATA
// ================================================================

// Get all classes
$classes = $db->fetchAll("
    SELECT cs.*, gl.level_name 
    FROM class_sections cs
    JOIN grade_levels gl ON cs.grade_level_id = gl.id
    WHERE cs.school_id = 1 AND cs.is_active = 1
    ORDER BY gl.promotion_order, cs.section_name
");

// Get attendance statuses
$statuses = $db->fetchAll("
    SELECT * FROM attendance_statuses 
    WHERE school_id = 1 AND is_active = 1 
    ORDER BY sort_order
");

// Get class attendance if class selected
$classAttendance = null;
if ($classId > 0) {
    $classAttendance = $service->getClassAttendance($classId, $sessionDate, $sessionType);
}

// Get statistics
$stats = $service->getStatistics();
$todaySummary = $service->getTodaySummary();
$recentAttendance = $service->getRecentAttendance(50);

// Get devices
$devices = $service->getDevices();

// Get device types
$deviceTypes = $db->fetchAll("SELECT * FROM attendance_device_types WHERE is_active = 1 ORDER BY type_name");

// Get students for dropdown
$students = $db->fetchAll("
    SELECT id, first_name, last_name, admission_number 
    FROM students 
    WHERE school_id = 1 AND is_active = 1 
    ORDER BY first_name
");

// Get RFID cards
$rfidCards = $db->fetchAll("
    SELECT r.*, s.first_name, s.last_name, s.admission_number
    FROM attendance_rfid_cards r
    LEFT JOIN students s ON r.student_id = s.id
    WHERE r.school_id = 1 AND r.is_active = 1
    ORDER BY r.created_at DESC
");

// Get exceptions
$exceptions = $db->fetchAll("
    SELECT e.*, s.first_name, s.last_name, s.admission_number
    FROM attendance_exceptions e
    LEFT JOIN students s ON e.student_id = s.id
    WHERE e.school_id = 1 AND e.is_active = 1
    ORDER BY e.created_at DESC
");

// Get risk analysis
$riskStudents = $service->getRiskAnalysis([
    'term_id' => 1,
    'threshold' => 75,
    'limit' => 50
]);

// Get biometric templates
$biometricTemplates = $db->fetchAll("
    SELECT b.*, s.first_name, s.last_name, s.admission_number
    FROM attendance_biometric_templates b
    LEFT JOIN students s ON b.student_id = s.id
    WHERE b.school_id = 1 AND b.is_active = 1
    ORDER BY b.created_at DESC
    LIMIT 50
");

// Get QR codes
$qrCodes = $db->fetchAll("
    SELECT q.*, s.first_name, s.last_name, s.admission_number
    FROM attendance_qr_codes q
    LEFT JOIN students s ON q.student_id = s.id
    WHERE q.school_id = 1 AND q.is_active = 1
    ORDER BY q.created_at DESC
    LIMIT 50
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - EduTrack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 0; }
        
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
        .sidebar .logo { text-align: center; padding: 10px 0 20px; border-bottom: 1px solid #2a4c8e; font-size: 18px; font-weight: bold; }
        .sidebar .logo span { color: #f59e0b; }
        .sidebar .nav-item {
            display: block;
            padding: 12px 25px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            transition: 0.3s;
        }
        .sidebar .nav-item:hover, .sidebar .nav-item.active { background: #2a4c8e; color: #fff; }
        .sidebar .nav-item .icon { margin-right: 10px; }
        
        .main-content { margin-left: 220px; padding: 20px; }
        
        .header {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 { color: #1a3c6e; font-size: 22px; }
        .header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #1a3c6e;
        }
        .stat-card .number { font-size: 22px; font-weight: bold; color: #1a3c6e; }
        .stat-card .label { color: #666; font-size: 11px; margin-top: 3px; }
        .stat-card .number.green { color: #16a34a; }
        .stat-card .number.red { color: #dc2626; }
        .stat-card .number.orange { color: #f59e0b; }
        .stat-card .number.blue { color: #2563eb; }
        .stat-card .number.purple { color: #7c3aed; }
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #fff;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tabs .tab {
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #666;
            font-size: 12px;
            font-weight: bold;
            transition: 0.3s;
        }
        .tabs .tab:hover { background: #f0f0f0; }
        .tabs .tab.active { background: #1a3c6e; color: #fff; }
        
        .btn {
            display: inline-block;
            padding: 6px 14px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            transition: 0.3s;
        }
        .btn:hover { background: #2a4c8e; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .btn-red { background: #dc2626; }
        .btn-red:hover { background: #b91c1c; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
        .btn-small { padding: 3px 8px; font-size: 11px; }
        .btn-success { background: #16a34a; }
        .btn-success:hover { background: #15803d; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-info { background: #17a2b8; }
        .btn-info:hover { background: #138496; }
        
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .content-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .content-card h3 {
            color: #1a3c6e;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 6px;
            font-size: 15px;
        }
        .content-card .card-header h3 { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
        
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 6px 8px;
            text-align: left;
            white-space: nowrap;
        }
        table td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        table tr:hover { background: #f8fafc; }
        
        .badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            display: inline-block;
        }
        .badge.Present { background: #d4edda; color: #155724; }
        .badge.Absent { background: #f8d7da; color: #721c24; }
        .badge.Late { background: #fff3cd; color: #856404; }
        .badge.Excused { background: #cce5ff; color: #004085; }
        .badge.Low { background: #d4edda; color: #155724; }
        .badge.Moderate { background: #fff3cd; color: #856404; }
        .badge.High { background: #f8d7da; color: #721c24; }
        .badge.Critical { background: #dc3545; color: #fff; }
        .badge.online { background: #d4edda; color: #155724; }
        .badge.offline { background: #f8d7da; color: #721c24; }
        .badge.maintenance { background: #fff3cd; color: #856404; }
        .badge.pending { background: #fff3cd; color: #856404; }
        .badge.approved { background: #d4edda; color: #155724; }
        .badge.rejected { background: #f8d7da; color: #721c24; }
        
        .form-group { margin-bottom: 12px; }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 4px;
            color: #333;
            font-size: 13px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .btn-row { display: flex; gap: 10px; margin-top: 15px; }
        .btn-row .btn { flex: 1; text-align: center; }
        
        .message {
            padding: 8px 15px;
            border-radius: 5px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .no-data { text-align: center; color: #999; padding: 20px; }
        .action-group { display: flex; gap: 5px; flex-wrap: wrap; }
        
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
            margin: 10px 0;
        }
        .attendance-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.3s;
        }
        .attendance-item:hover { background: #f0f7ff; border-color: #1a3c6e; }
        .attendance-item .student-info { flex: 1; }
        .attendance-item .student-info .name { font-weight: bold; font-size: 13px; }
        .attendance-item .student-info .admission { font-size: 11px; color: #999; }
        .attendance-item .status-buttons { display: flex; gap: 4px; }
        .attendance-item .status-buttons .btn {
            padding: 3px 8px;
            font-size: 10px;
            border-radius: 4px;
            border: 2px solid transparent;
        }
        .attendance-item .status-buttons .btn.active {
            border: 2px solid #1a3c6e;
            font-weight: bold;
        }
        .btn-status-P { background: #d4edda; color: #155724; }
        .btn-status-P.active { background: #28a745; color: #fff; }
        .btn-status-A { background: #f8d7da; color: #721c24; }
        .btn-status-A.active { background: #dc3545; color: #fff; }
        .btn-status-L { background: #fff3cd; color: #856404; }
        .btn-status-L.active { background: #ffc107; color: #fff; }
        .btn-status-E { background: #cce5ff; color: #004085; }
        .btn-status-E.active { background: #17a2b8; color: #fff; }
        
        .risk-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .risk-item:last-child { border-bottom: none; }
        
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
            padding: 25px;
            border-radius: 10px;
            max-width: 700px;
            width: 90%;
            margin: 30px auto;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-content h2 { color: #1a3c6e; margin-bottom: 15px; font-size: 20px; }
        .modal-content .close-modal { float: right; font-size: 24px; cursor: pointer; color: #999; }
        .modal-content .close-modal:hover { color: #333; }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .status-indicator.online { background: #28a745; }
        .status-indicator.offline { background: #dc3545; }
        .status-indicator.maintenance { background: #ffc107; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .attendance-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs .tab { padding: 5px 8px; font-size: 10px; }
            .modal-content { width: 95%; margin: 10px auto; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>📋 Attendance Management</h1>
                <p style="color: #666; font-size: 13px;">Complete attendance with biometric, RFID, QR, and risk analysis</p>
            </div>
            <div class="header-actions">
                <a href="/admin/attendance.php?tab=mark" class="btn btn-green">📝 Mark Attendance</a>
                <a href="/admin/attendance.php?tab=devices" class="btn btn-info">🖐️ Devices</a>
                <a href="/admin/attendance.php?tab=risk" class="btn btn-warning">⚠️ Risk Analysis</a>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $todaySummary['total_expected'] ?? 0; ?></div>
                <div class="label">👨‍🎓 Total Students</div>
            </div>
            <div class="stat-card">
                <div class="number green"><?php echo $todaySummary['total_present'] ?? 0; ?></div>
                <div class="label">✅ Present Today</div>
            </div>
            <div class="stat-card">
                <div class="number red"><?php echo ($todaySummary['total_expected'] ?? 0) - ($todaySummary['total_present'] ?? 0); ?></div>
                <div class="label">❌ Absent Today</div>
            </div>
            <div class="stat-card">
                <div class="number blue">
                    <?php 
                    $expected = $todaySummary['total_expected'] ?? 0;
                    $present = $todaySummary['total_present'] ?? 0;
                    echo $expected > 0 ? round(($present / $expected) * 100, 1) . '%' : '0%';
                    ?>
                </div>
                <div class="label">📊 Attendance Rate</div>
            </div>
            <div class="stat-card">
                <div class="number orange"><?php echo count($devices); ?></div>
                <div class="label">🖐️ Devices</div>
            </div>
            <div class="stat-card">
                <div class="number purple"><?php echo count($rfidCards); ?></div>
                <div class="label">💳 RFID Cards</div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <a href="/admin/attendance.php?tab=dashboard" class="tab <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">📊 Dashboard</a>
            <a href="/admin/attendance.php?tab=mark" class="tab <?php echo $activeTab === 'mark' ? 'active' : ''; ?>">📝 Mark</a>
            <a href="/admin/attendance.php?tab=risk" class="tab <?php echo $activeTab === 'risk' ? 'active' : ''; ?>">⚠️ Risk</a>
            <a href="/admin/attendance.php?tab=devices" class="tab <?php echo $activeTab === 'devices' ? 'active' : ''; ?>">🖐️ Devices</a>
            <a href="/admin/attendance.php?tab=rfid" class="tab <?php echo $activeTab === 'rfid' ? 'active' : ''; ?>">💳 RFID</a>
            <a href="/admin/attendance.php?tab=biometric" class="tab <?php echo $activeTab === 'biometric' ? 'active' : ''; ?>">🔐 Biometric</a>
            <a href="/admin/attendance.php?tab=qr" class="tab <?php echo $activeTab === 'qr' ? 'active' : ''; ?>">📱 QR</a>
            <a href="/admin/attendance.php?tab=exceptions" class="tab <?php echo $activeTab === 'exceptions' ? 'active' : ''; ?>">📋 Exceptions</a>
            <a href="/admin/attendance.php?tab=recent" class="tab <?php echo $activeTab === 'recent' ? 'active' : ''; ?>">📄 Recent</a>
        </div>
        
        <!-- ================================================================ -->
        <!-- TAB: DASHBOARD -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'dashboard'): ?>
        
        <div class="content-card">
            <h3>📊 Today's Overview - <?php echo date('l, F j, Y'); ?></h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-top:10px;">
                <div style="background:#f8fafc;padding:15px;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
                    <div style="font-size:28px;font-weight:bold;color:#16a34a;"><?php echo $todaySummary['total_present'] ?? 0; ?></div>
                    <div style="color:#666;font-size:12px;">Present</div>
                </div>
                <div style="background:#f8fafc;padding:15px;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
                    <div style="font-size:28px;font-weight:bold;color:#dc2626;"><?php echo ($todaySummary['total_expected'] ?? 0) - ($todaySummary['total_present'] ?? 0); ?></div>
                    <div style="color:#666;font-size:12px;">Absent</div>
                </div>
                <div style="background:#f8fafc;padding:15px;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
                    <div style="font-size:28px;font-weight:bold;color:#2563eb;">
                        <?php 
                        $expected = $todaySummary['total_expected'] ?? 0;
                        $present = $todaySummary['total_present'] ?? 0;
                        echo $expected > 0 ? round(($present / $expected) * 100, 1) . '%' : '0%';
                        ?>
                    </div>
                    <div style="color:#666;font-size:12px;">Attendance Rate</div>
                </div>
            </div>
        </div>
        
        <!-- Device Status -->
        <div class="content-card">
            <h3>🖐️ Device Status</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-top:10px;">
                <?php if (!empty($devices)): ?>
                    <?php foreach ($devices as $device): ?>
                    <div style="background:#f8fafc;padding:15px;border-radius:8px;border:1px solid #e2e8f0;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong><?php echo htmlspecialchars($device['device_name']); ?></strong>
                            <span>
                                <span class="status-indicator <?php echo $device['status']; ?>"></span>
                                <span class="badge <?php echo $device['status']; ?>"><?php echo ucfirst($device['status']); ?></span>
                            </span>
                        </div>
                        <div style="font-size:12px;color:#666;margin-top:5px;">
                            <?php echo ucfirst($device['device_type'] ?? 'Unknown'); ?> | <?php echo htmlspecialchars($device['location'] ?? 'N/A'); ?>
                        </div>
                        <div style="font-size:11px;color:#999;margin-top:3px;">
                            Last connected: <?php echo $device['last_connected'] ? date('d M Y H:i', strtotime($device['last_connected'])) : 'Never'; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data">No devices configured.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: MARK ATTENDANCE -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'mark'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>📝 Mark Attendance</h3>
            </div>
            
            <form method="GET" action="" style="margin-bottom:15px;">
                <input type="hidden" name="tab" value="mark">
                <div class="form-row">
                    <div class="form-group">
                        <label for="class_id">Select Class</label>
                        <select id="class_id" name="class_id" onchange="this.form.submit()" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $classId == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['level_name'] . ' - ' . $class['section_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" value="<?php echo $sessionDate; ?>" onchange="this.form.submit()">
                    </div>
                    <div class="form-group">
                        <label for="type">Session Type</label>
                        <select id="type" name="type" onchange="this.form.submit()">
                            <option value="morning" <?php echo $sessionType === 'morning' ? 'selected' : ''; ?>>Morning</option>
                            <option value="afternoon" <?php echo $sessionType === 'afternoon' ? 'selected' : ''; ?>>Afternoon</option>
                            <option value="evening" <?php echo $sessionType === 'evening' ? 'selected' : ''; ?>>Evening</option>
                        </select>
                    </div>
                </div>
            </form>
            
            <?php if ($classId > 0 && $classAttendance && !empty($classAttendance['students'])): ?>
            
            <form method="POST" action="">
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="date" value="<?php echo $sessionDate; ?>">
                <input type="hidden" name="session_type" value="<?php echo $sessionType; ?>">
                <input type="hidden" name="save_attendance" value="1">
                
                <?php if ($classAttendance['session_id']): ?>
                <div style="background:#f0f7ff;padding:8px 12px;border-radius:5px;margin-bottom:10px;font-size:12px;">
                    <strong>Session:</strong> <?php echo $classAttendance['session_id']; ?> | 
                    <strong>Total Students:</strong> <?php echo $classAttendance['total']; ?>
                    <a href="/admin/attendance.php?complete_session=<?php echo $classAttendance['session_id']; ?>" 
                       class="btn btn-small btn-success" style="float:right;" 
                       onclick="return confirm('Complete this session? This will lock it.')">
                       ✅ Complete Session
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="attendance-grid">
                    <?php foreach ($classAttendance['students'] as $student): ?>
                    <div class="attendance-item">
                        <div class="student-info">
                            <div class="name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                            <div class="admission"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                        </div>
                        <div class="status-buttons">
                            <?php 
                            $currentStatus = $student['attendance_status'] ?? 'P';
                            if ($currentStatus === 'Present') $currentStatus = 'P';
                            else if ($currentStatus === 'Absent') $currentStatus = 'A';
                            else if ($currentStatus === 'Late') $currentStatus = 'L';
                            else if ($currentStatus === 'Excused') $currentStatus = 'E';
                            else $currentStatus = 'P';
                            
                            $statusCodes = ['P' => 'P', 'A' => 'A', 'L' => 'L', 'E' => 'E'];
                            foreach ($statusCodes as $code => $label):
                            ?>
                            <button type="button" 
                                    class="btn btn-status-<?php echo $code; ?> <?php echo $currentStatus === $code ? 'active' : ''; ?>" 
                                    onclick="selectStatus(this, '<?php echo $student['student_id']; ?>', '<?php echo $code; ?>')">
                                <?php echo $label; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="attendance[<?php echo $student['student_id']; ?>][student_id]" value="<?php echo $student['student_id']; ?>">
                        <input type="hidden" name="attendance[<?php echo $student['student_id']; ?>][status]" id="status_<?php echo $student['student_id']; ?>" value="<?php echo $currentStatus; ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="btn-row" style="margin-top:15px;">
                    <button type="submit" class="btn btn-green">💾 Save Attendance</button>
                    <button type="button" class="btn btn-success" onclick="markAll('P')">✅ All Present</button>
                    <button type="button" class="btn btn-danger" onclick="markAll('A')">❌ All Absent</button>
                    <button type="button" class="btn btn-warning" onclick="markAll('L')">⏰ All Late</button>
                </div>
            </form>
            
            <?php elseif ($classId > 0): ?>
                <div class="no-data">No students found in this class.</div>
            <?php else: ?>
                <div class="no-data">Select a class to mark attendance.</div>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: RISK ANALYSIS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'risk'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>⚠️ Attendance Risk Analysis</h3>
                <span style="font-size:12px; color:#666;">
                    Students with attendance below 80%
                </span>
            </div>
            
            <?php if ($riskStudents['success'] && !empty($riskStudents['data'])): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Admission</th>
                            <th>Class</th>
                            <th>Sessions</th>
                            <th>Absences</th>
                            <th>Lates</th>
                            <th>Percentage</th>
                            <th>Risk Level</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riskStudents['data'] as $index => $risk): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($risk['first_name'] . ' ' . $risk['last_name']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($risk['admission_number']); ?></td>
                            <td><?php echo htmlspecialchars($risk['level_name'] . ' - ' . $risk['section_name']); ?></td>
                            <td><?php echo $risk['total_sessions']; ?></td>
                            <td><?php echo $risk['absences']; ?></td>
                            <td><?php echo $risk['lates'] ?? 0; ?></td>
                            <td><strong><?php echo $risk['percentage']; ?>%</strong></td>
                            <td><span class="badge <?php echo $risk['risk_level']; ?>"><?php echo $risk['risk_level']; ?></span></td>
                            <td>
                                <div class="action-group">
                                    <a href="#" class="btn btn-small btn-warning" onclick="notifyParent(<?php echo $risk['id']; ?>)">📧 Notify</a>
                                    <a href="/admin/attendance.php?tab=exceptions&student_id=<?php echo $risk['id']; ?>" class="btn btn-small btn-info">📋 Excuse</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;font-size:12px;color:#666;">
                <strong>Total at risk:</strong> <?php echo $riskStudents['total_at_risk']; ?> students
            </div>
            <?php else: ?>
                <div class="no-data">No students at risk. 🎉</div>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: DEVICES -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'devices'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>🖐️ Biometric Devices</h3>
                <button class="btn btn-green" onclick="openModal('device')">➕ Add Device</button>
            </div>
            
            <?php if (!empty($devices)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Device Name</th>
                            <th>Serial</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Last Connected</th>
                            <th>API Key</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $index => $device): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($device['device_name']); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($device['device_serial']); ?></code></td>
                            <td><?php echo ucfirst($device['device_type'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($device['location'] ?? 'N/A'); ?></td>
                            <td><span class="badge <?php echo $device['status']; ?>"><?php echo ucfirst($device['status']); ?></span></td>
                            <td><?php echo $device['last_connected'] ? date('d M Y H:i', strtotime($device['last_connected'])) : 'Never'; ?></td>
                            <td><code style="font-size:10px;"><?php echo substr($device['api_key'] ?? '', 0, 20); ?>...</code></td>
                            <td>
                                <div class="action-group">
                                    <a href="#" class="btn btn-small" onclick="testDevice(<?php echo $device['id']; ?>)">🔌</a>
                                    <a href="#" class="btn btn-small btn-red" onclick="deleteDevice(<?php echo $device['id']; ?>)">🗑️</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="no-data">No devices registered.</div>
            <?php endif; ?>
        </div>
        
        <!-- Device Registration Modal -->
        <div class="modal" id="deviceModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('deviceModal')">&times;</span>
                <h2>➕ Register Device</h2>
                <form method="POST" action="">
                    <input type="hidden" name="register_device" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="device_name">Device Name *</label>
                            <input type="text" id="device_name" name="device_name" placeholder="Main Entrance Scanner" required>
                        </div>
                        <div class="form-group">
                            <label for="device_serial">Device Serial *</label>
                            <input type="text" id="device_serial" name="device_serial" placeholder="SN-2026-001" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="device_type_id">Device Type *</label>
                            <select id="device_type_id" name="device_type_id" required>
                                <option value="">Select Type</option>
                                <?php foreach ($deviceTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="device_code">Device Code</label>
                            <input type="text" id="device_code" name="device_code" placeholder="ENT-001">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="location">Location</label>
                            <input type="text" id="location" name="location" placeholder="Main Gate">
                        </div>
                        <div class="form-group">
                            <label for="ip_address">IP Address</label>
                            <input type="text" id="ip_address" name="ip_address" placeholder="192.168.1.100">
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 Register Device</button>
                        <button type="button" class="btn" onclick="closeModal('deviceModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: RFID CARDS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'rfid'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>💳 RFID/NFC Cards</h3>
                <button class="btn btn-green" onclick="openModal('rfid')">➕ Register Card</button>
            </div>
            
            <?php if (!empty($rfidCards)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Card Number</th>
                            <th>UID</th>
                            <th>Student/Staff</th>
                            <th>Type</th>
                            <th>Issued Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rfidCards as $index => $card): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><code><?php echo htmlspecialchars($card['card_number']); ?></code></td>
                            <td><code><?php echo htmlspecialchars($card['uid']); ?></code></td>
                            <td>
                                <?php if ($card['student_id']): ?>
                                <?php echo htmlspecialchars($card['first_name'] . ' ' . $card['last_name']); ?>
                                <br><small style="color:#999;"><?php echo htmlspecialchars($card['admission_number']); ?></small>
                                <?php else: ?>
                                <span style="color:#999;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $card['card_type']; ?>"><?php echo ucfirst($card['card_type']); ?></span></td>
                            <td><?php echo date('d M Y', strtotime($card['issued_date'])); ?></td>
                            <td>
                                <?php if ($card['is_blocked']): ?>
                                <span class="badge rejected">Blocked</span>
                                <?php else: ?>
                                <span class="badge approved">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <?php if (!$card['is_blocked']): ?>
                                    <a href="#" class="btn btn-small btn-red" onclick="blockCard(<?php echo $card['id']; ?>)">🔒</a>
                                    <?php endif; ?>
                                    <a href="#" class="btn btn-small btn-red" onclick="deleteCard(<?php echo $card['id']; ?>)">🗑️</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="no-data">No RFID cards registered.</div>
            <?php endif; ?>
        </div>
        
        <!-- RFID Registration Modal -->
        <div class="modal" id="rfidModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('rfidModal')">&times;</span>
                <h2>💳 Register RFID Card</h2>
                <form method="POST" action="">
                    <input type="hidden" name="register_rfid" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="card_number">Card Number *</label>
                            <input type="text" id="card_number" name="card_number" placeholder="CARD-001" required>
                        </div>
                        <div class="form-group">
                            <label for="uid">Card UID *</label>
                            <input type="text" id="uid" name="uid" placeholder="AB:CD:EF:12:34:56" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="student_id">Student</label>
                            <select id="student_id" name="student_id">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="card_type">Card Type</label>
                            <select id="card_type" name="card_type">
                                <option value="student">Student</option>
                                <option value="staff">Staff</option>
                                <option value="visitor">Visitor</option>
                                <option value="temporary">Temporary</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="issued_date">Issued Date</label>
                            <input type="date" id="issued_date" name="issued_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="expiry_date">Expiry Date</label>
                            <input type="date" id="expiry_date" name="expiry_date">
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 Register Card</button>
                        <button type="button" class="btn" onclick="closeModal('rfidModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: BIOMETRIC TEMPLATES -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'biometric'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>🔐 Biometric Templates</h3>
                <button class="btn btn-green" onclick="openModal('biometric')">➕ Enroll Template</button>
            </div>
            
            <?php if (!empty($biometricTemplates)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Finger</th>
                            <th>Quality</th>
                            <th>Enrolled</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($biometricTemplates as $index => $template): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <?php if ($template['student_id']): ?>
                                <?php echo htmlspecialchars($template['first_name'] . ' ' . $template['last_name']); ?>
                                <br><small style="color:#999;"><?php echo htmlspecialchars($template['admission_number']); ?></small>
                                <?php else: ?>
                                <span style="color:#999;">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?php echo ucfirst($template['biometric_type']); ?></span></td>
                            <td><?php echo str_replace('_', ' ', ucfirst($template['finger_position'] ?? 'N/A')); ?></td>
                            <td><?php echo $template['quality_score'] ? $template['quality_score'] . '%' : 'N/A'; ?></td>
                            <td><?php echo date('d M Y', strtotime($template['enrollment_date'])); ?></td>
                            <td>
                                <?php if ($template['is_primary']): ?>
                                <span class="badge approved">Primary</span>
                                <?php else: ?>
                                <span class="badge">Backup</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <a href="#" class="btn btn-small btn-red" onclick="deleteTemplate(<?php echo $template['id']; ?>)">🗑️</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="no-data">No biometric templates enrolled.</div>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: QR CODES -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'qr'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>📱 QR Codes</h3>
                <button class="btn btn-green" onclick="openModal('qr')">➕ Generate QR</button>
            </div>
            
            <?php if (!empty($qrCodes)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>QR Code</th>
                            <th>Student</th>
                            <th>Purpose</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qrCodes as $index => $qr): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><code style="font-size:10px;"><?php echo substr($qr['qr_code'], 0, 20); ?>...</code></td>
                            <td>
                                <?php if ($qr['student_id']): ?>
                                <?php echo htmlspecialchars($qr['first_name'] . ' ' . $qr['last_name']); ?>
                                <?php else: ?>
                                <span style="color:#999;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ucwords(str_replace('_', ' ', $qr['purpose'])); ?></td>
                            <td><?php echo date('d M Y H:i', strtotime($qr['expires_at'])); ?></td>
                            <td>
                                <?php if ($qr['is_used']): ?>
                                <span class="badge">Used</span>
                                <?php elseif (strtotime($qr['expires_at']) < time()): ?>
                                <span class="badge rejected">Expired</span>
                                <?php else: ?>
                                <span class="badge approved">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <a href="#" class="btn btn-small" onclick="viewQR('<?php echo $qr['qr_code']; ?>')">👁️</a>
                                    <a href="#" class="btn btn-small btn-red" onclick="deleteQR(<?php echo $qr['id']; ?>)">🗑️</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="no-data">No QR codes generated.</div>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: EXCEPTIONS -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'exceptions'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>📋 Attendance Exceptions</h3>
                <button class="btn btn-green" onclick="openModal('exception')">➕ Add Exception</button>
            </div>
            
            <?php if (!empty($exceptions)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exceptions as $index => $exception): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <?php if ($exception['student_id']): ?>
                                <?php echo htmlspecialchars($exception['first_name'] . ' ' . $exception['last_name']); ?>
                                <br><small style="color:#999;"><?php echo htmlspecialchars($exception['admission_number']); ?></small>
                                <?php else: ?>
                                <span style="color:#999;">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($exception['exception_date'])); ?></td>
                            <td><span class="badge"><?php echo ucfirst($exception['exception_type']); ?></span></td>
                            <td><?php echo htmlspecialchars(substr($exception['reason'], 0, 50)) . '...'; ?></td>
                            <td><span class="badge <?php echo $exception['status']; ?>"><?php echo ucfirst($exception['status']); ?></span></td>
                            <td>
                                <div class="action-group">
                                    <?php if ($exception['status'] === 'pending'): ?>
                                    <a href="#" class="btn btn-small btn-success" onclick="approveException(<?php echo $exception['id']; ?>)">✅ Approve</a>
                                    <a href="#" class="btn btn-small btn-danger" onclick="rejectException(<?php echo $exception['id']; ?>)">❌ Reject</a>
                                    <?php endif; ?>
                                    <a href="#" class="btn btn-small">👁️</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="no-data">No exceptions recorded.</div>
            <?php endif; ?>
        </div>
        
        <!-- Exception Modal -->
        <div class="modal" id="exceptionModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('exceptionModal')">&times;</span>
                <h2>📋 Add Exception</h2>
                <form method="POST" action="">
                    <input type="hidden" name="save_exception" value="1">
                    <div class="form-group">
                        <label for="student_id">Student</label>
                        <select id="student_id" name="student_id">
                            <option value="">Select Student</option>
                            <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="exception_date">Exception Date *</label>
                            <input type="date" id="exception_date" name="exception_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="exception_type">Exception Type</label>
                            <select id="exception_type" name="exception_type">
                                <option value="medical">Medical</option>
                                <option value="emergency">Emergency</option>
                                <option value="family">Family</option>
                                <option value="official">Official</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time">
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="reason">Reason *</label>
                        <textarea id="reason" name="reason" rows="3" placeholder="Detailed reason for exception..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea id="notes" name="notes" rows="2" placeholder="Any additional information..."></textarea>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 Save Exception</button>
                        <button type="button" class="btn" onclick="closeModal('exceptionModal')">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php endif; ?>
        
        <!-- ================================================================ -->
        <!-- TAB: RECENT -->
        <!-- ================================================================ -->
        <?php if ($activeTab === 'recent'): ?>
        
        <div class="content-card">
            <div class="card-header">
                <h3>📄 Recent Attendance Records</h3>
                <span style="font-size:12px; color:#666;">Last 50 records</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Date</th>
                            <th>Session</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentAttendance)): ?>
                            <?php foreach ($recentAttendance as $index => $record): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></strong>
                                    <br><small style="color:#999;"><?php echo htmlspecialchars($record['admission_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($record['level_name'] . ' - ' . $record['section_name']); ?></td>
                                <td><?php echo date('d M Y', strtotime($record['session_date'])); ?></td>
                                <td><?php echo ucfirst($record['session_type']); ?></td>
                                <td><span class="badge <?php echo $record['status_name']; ?>"><?php echo $record['status_name']; ?></span></td>
                                <td><?php echo $record['check_in_time'] ? date('h:i A', strtotime($record['check_in_time'])) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($record['method_name'] ?? 'Manual'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="no-data">No attendance records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php endif; ?>
        
    </div>
    
    <!-- ================================================================ -->
    <!-- JAVASCRIPT -->
    <!-- ================================================================ -->
    <script>
        // ================================================================
        // ATTENDANCE MARKING
        // ================================================================
        
        function selectStatus(button, studentId, status) {
            const parent = button.parentElement;
            const buttons = parent.querySelectorAll('.btn');
            buttons.forEach(function(btn) {
                btn.classList.remove('active');
            });
            button.classList.add('active');
            document.getElementById('status_' + studentId).value = status;
        }
        
        function markAll(status) {
            const items = document.querySelectorAll('.attendance-item');
            items.forEach(function(item) {
                const buttons = item.querySelectorAll('.status-buttons .btn');
                buttons.forEach(function(btn) {
                    if (btn.textContent.trim() === status) {
                        btn.click();
                    }
                });
            });
        }
        
        // ================================================================
        // MODAL FUNCTIONS
        // ================================================================
        
        function openModal(type) {
            const modals = {
                'device': 'deviceModal',
                'rfid': 'rfidModal',
                'biometric': 'biometricModal',
                'qr': 'qrModal',
                'exception': 'exceptionModal'
            };
            if (modals[type]) {
                document.getElementById(modals[type]).classList.add('show');
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(function(modal) {
                if (event.target == modal) {
                    modal.classList.remove('show');
                }
            });
        }
        
        // ================================================================
        // DEVICE FUNCTIONS
        // ================================================================
        
        function testDevice(deviceId) {
            if (confirm('Test connection to device?')) {
                alert('Testing device connection... (This would ping the device via API)');
            }
        }
        
        function deleteDevice(deviceId) {
            if (confirm('Are you sure you want to delete this device?')) {
                alert('Device deletion would be processed here.');
            }
        }
        
        // ================================================================
        // RFID FUNCTIONS
        // ================================================================
        
        function blockCard(cardId) {
            if (confirm('Block this RFID card?')) {
                alert('Card blocked successfully!');
            }
        }
        
        function deleteCard(cardId) {
            if (confirm('Delete this RFID card?')) {
                alert('Card deleted successfully!');
            }
        }
        
        // ================================================================
        // BIOMETRIC FUNCTIONS
        // ================================================================
        
        function deleteTemplate(templateId) {
            if (confirm('Delete this biometric template?')) {
                alert('Template deleted successfully!');
            }
        }
        
        // ================================================================
        // QR CODE FUNCTIONS
        // ================================================================
        
        function viewQR(qrCode) {
            alert('QR Code: ' + qrCode + '\n\nScan this QR code to mark attendance.');
        }
        
        function deleteQR(qrId) {
            if (confirm('Delete this QR code?')) {
                alert('QR code deleted successfully!');
            }
        }
        
        // ================================================================
        // EXCEPTION FUNCTIONS
        // ================================================================
        
        function approveException(exceptionId) {
            if (confirm('Approve this exception?')) {
                alert('Exception approved successfully!');
            }
        }
        
        function rejectException(exceptionId) {
            const reason = prompt('Rejection reason:');
            if (reason) {
                alert('Exception rejected: ' + reason);
            }
        }
        
        // ================================================================
        // NOTIFICATION FUNCTIONS
        // ================================================================
        
        function notifyParent(studentId) {
            if (confirm('Send attendance notification to parent?')) {
                alert('Notification sent to parent successfully!');
            }
        }
    </script>
    
</body>
</html>