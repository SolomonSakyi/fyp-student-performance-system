<?php
require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../app/helpers/NumberingHelper.php';
AdminHelper::requireLogin();

$db = DatabaseHelper::getInstance();
$numbering = new NumberingHelper($db);

$action = $_GET['action'] ?? 'list';
$message = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$editId = $_GET['edit'] ?? null;

// Get next staff number for display
$nextStaffNumber = $numbering->peekNextStaffNumber();

// Handle Add Teacher with auto-generated staff number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_teacher'])) {
    // Personal Information
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['date_of_birth'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $secondaryPhone = $_POST['secondary_phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $secondaryEmail = $_POST['secondary_email'] ?? '';
    $address = $_POST['address'] ?? '';
    $nationality = $_POST['nationality'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $maritalStatus = $_POST['marital_status'] ?? '';
    $emergencyContact = $_POST['emergency_contact'] ?? '';
    $emergencyPhone = $_POST['emergency_phone'] ?? '';
    
    // Employment Information
    $staffCategoryId = $_POST['staff_category_id'] ?? 0;
    $departmentId = $_POST['department_id'] ?? 0;
    $designationId = $_POST['designation_id'] ?? 0;
    $employmentTypeId = $_POST['employment_type_id'] ?? 0;
    $joiningDate = $_POST['joining_date'] ?? '';
    $contractStart = $_POST['contract_start_date'] ?? '';
    $contractEnd = $_POST['contract_end_date'] ?? '';
    $qualification = $_POST['qualification'] ?? '';
    $isTeaching = isset($_POST['is_teaching']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    // Banking Information
    $bankName = $_POST['bank_name'] ?? '';
    $bankBranch = $_POST['bank_branch'] ?? '';
    $accountNumber = $_POST['account_number'] ?? '';
    $ssnitNumber = $_POST['ssnit_number'] ?? '';
    $tinNumber = $_POST['tin_number'] ?? '';
    
    $editId = $_POST['edit_id'] ?? null;
    
    // Handle profile photo upload
    $profilePhoto = $_POST['existing_photo'] ?? '';
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../public/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $file = $_FILES['profile_photo'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array(strtolower($ext), $allowed)) {
            $filename = 'teacher_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
            $profilePhoto = $filename;
        }
    }
    
    if ($firstName && $lastName && $departmentId && $employmentTypeId) {
        try {
            if ($editId) {
                // Update existing person
                $sql = "UPDATE people SET 
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    gender = ?,
                    date_of_birth = ?,
                    primary_phone = ?,
                    secondary_phone = ?,
                    primary_email = ?,
                    secondary_email = ?,
                    address = ?,
                    nationality = ?,
                    religion = ?,
                    marital_status = ?,
                    emergency_contact_name = ?,
                    emergency_contact_phone = ?,
                    profile_photo = ?,
                    updated_at = NOW()
                    WHERE id = (SELECT person_id FROM staff WHERE id = ?)";
                $db->query($sql, [
                    $firstName, $middleName, $lastName, $gender, $dob,
                    $phone, $secondaryPhone, $email, $secondaryEmail,
                    $address, $nationality, $religion, $maritalStatus,
                    $emergencyContact, $emergencyPhone, $profilePhoto,
                    $editId
                ]);
                
                // Update staff
                $sql = "UPDATE staff SET 
                    staff_category_id = ?,
                    department_id = ?,
                    designation_id = ?,
                    employment_type_id = ?,
                    joining_date = ?,
                    contract_start_date = ?,
                    contract_end_date = ?,
                    qualification = ?,
                    bank_name = ?,
                    bank_branch = ?,
                    bank_account_number = ?,
                    ssnit_number = ?,
                    tin_number = ?,
                    is_teaching_staff = ?,
                    notes = ?,
                    updated_at = NOW()
                    WHERE id = ?";
                $db->query($sql, [
                    $staffCategoryId, $departmentId, $designationId,
                    $employmentTypeId, $joiningDate,
                    $contractStart, $contractEnd, $qualification,
                    $bankName, $bankBranch, $accountNumber,
                    $ssnitNumber, $tinNumber, $isTeaching, $notes,
                    $editId
                ]);
                
                header('Location: /admin/teachers.php?msg=Teacher updated successfully!');
                exit;
            } else {
                // Auto-generate staff number
                $staffNumber = $numbering->generateStaffNumber();
                
                // Insert person
                $sql = "INSERT INTO people (
                    uuid, school_id,
                    first_name, middle_name, last_name,
                    gender, date_of_birth,
                    primary_phone, secondary_phone,
                    primary_email, secondary_email,
                    address, nationality, religion, marital_status,
                    emergency_contact_name, emergency_contact_phone,
                    profile_photo,
                    is_active
                ) VALUES (
                    UUID(), 1,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?,
                    1
                )";
                $db->query($sql, [
                    $firstName, $middleName, $lastName,
                    $gender, $dob,
                    $phone, $secondaryPhone,
                    $email, $secondaryEmail,
                    $address, $nationality, $religion, $maritalStatus,
                    $emergencyContact, $emergencyPhone,
                    $profilePhoto
                ]);
                $personId = $db->lastInsertId();
                
                // Insert staff with auto-generated staff number
                $sql = "INSERT INTO staff (
                    uuid, school_id, person_id, staff_number,
                    staff_category_id, department_id, designation_id,
                    employment_type_id, staff_status_id,
                    joining_date, contract_start_date, contract_end_date,
                    qualification, bank_name, bank_branch, bank_account_number,
                    ssnit_number, tin_number,
                    is_teaching_staff, notes, is_active
                ) VALUES (
                    UUID(), 1, ?, ?,
                    ?, ?, ?,
                    ?, 1,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, 1
                )";
                $db->query($sql, [
                    $personId, $staffNumber,
                    $staffCategoryId, $departmentId, $designationId,
                    $employmentTypeId,
                    $joiningDate, $contractStart, $contractEnd,
                    $qualification, $bankName, $bankBranch, $accountNumber,
                    $ssnitNumber, $tinNumber,
                    $isTeaching, $notes
                ]);
                
                header('Location: /admin/teachers.php?msg=Teacher added successfully! Staff Number: ' . $staffNumber);
                exit;
            }
        } catch (Exception $e) {
            header('Location: /admin/teachers.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/teachers.php?error=Please fill in all required fields.');
        exit;
    }
}

// Handle Deactivate/Activate
if (isset($_GET['deactivate']) && $_GET['id']) {
    try {
        $db->query("UPDATE staff SET is_active = 0 WHERE id = ?", [$_GET['id']]);
        header('Location: /admin/teachers.php?msg=Teacher deactivated successfully.');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/teachers.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}
if (isset($_GET['activate']) && $_GET['id']) {
    try {
        $db->query("UPDATE staff SET is_active = 1 WHERE id = ?", [$_GET['id']]);
        header('Location: /admin/teachers.php?msg=Teacher activated successfully.');
        exit;
    } catch (Exception $e) {
        header('Location: /admin/teachers.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Get teacher for editing
$editTeacher = null;
if ($editId) {
    try {
        $editTeacher = $db->fetchOne("
            SELECT st.*, p.*
            FROM staff st
            JOIN people p ON st.person_id = p.id
            WHERE st.id = ? AND st.school_id = 1
        ", [$editId]);
    } catch (Exception $e) {
        $editTeacher = null;
    }
}

// Get all teachers with ALL columns
$teachers = [];
try {
    $teachers = $db->fetchAll("
        SELECT 
            st.id,
            st.staff_number,
            st.is_teaching_staff,
            st.is_active,
            st.qualification,
            st.bank_name,
            st.bank_branch,
            st.bank_account_number,
            st.ssnit_number,
            st.tin_number,
            st.notes,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.gender,
            p.primary_phone,
            p.secondary_phone,
            p.primary_email,
            p.secondary_email,
            p.address,
            p.nationality,
            p.religion,
            p.marital_status,
            p.emergency_contact_name,
            p.emergency_contact_phone,
            p.profile_photo,
            d.department_name,
            des.designation_name
        FROM staff st
        JOIN people p ON st.person_id = p.id
        LEFT JOIN departments d ON st.department_id = d.id
        LEFT JOIN designations des ON st.designation_id = des.id
        WHERE st.school_id = 1
        ORDER BY st.id DESC
        LIMIT 50
    ");
} catch (Exception $e) {
    // If error, try without some columns
    try {
        $teachers = $db->fetchAll("
            SELECT 
                st.id,
                st.staff_number,
                st.is_teaching_staff,
                st.is_active,
                st.qualification,
                st.bank_name,
                st.bank_account_number,
                st.ssnit_number,
                st.tin_number,
                p.first_name,
                p.last_name,
                p.gender,
                p.primary_phone,
                p.primary_email,
                p.address,
                p.profile_photo,
                d.department_name,
                des.designation_name
            FROM staff st
            JOIN people p ON st.person_id = p.id
            LEFT JOIN departments d ON st.department_id = d.id
            LEFT JOIN designations des ON st.designation_id = des.id
            WHERE st.school_id = 1
            ORDER BY st.id DESC
            LIMIT 50
        ");
    } catch (Exception $e2) {
        $teachers = [];
        $error = 'Could not load teachers: ' . $e2->getMessage();
    }
}

// Get dropdown data
$departments = [];
$designations = [];
$employmentTypes = [];
$staffCategories = [];
try {
    $departments = $db->fetchAll("SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
} catch (Exception $e) { $departments = []; }
try {
    $designations = $db->fetchAll("SELECT id, designation_name FROM designations WHERE is_active = 1 ORDER BY designation_name");
} catch (Exception $e) { $designations = []; }
try {
    $employmentTypes = $db->fetchAll("SELECT id, employment_type_name FROM employment_types WHERE is_active = 1 ORDER BY employment_type_name");
} catch (Exception $e) { $employmentTypes = []; }
try {
    $staffCategories = $db->fetchAll("SELECT id, category_name FROM staff_categories WHERE is_active = 1 ORDER BY category_name");
} catch (Exception $e) { $staffCategories = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teachers - Admin</title>
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
        .btn {
            display: inline-block;
            padding: 8px 18px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
        }
        .btn:hover { background: #2a4c8e; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .btn-red { background: #dc2626; }
        .btn-red:hover { background: #b91c1c; }
        .btn-small { padding: 3px 8px; font-size: 11px; }
        .content-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .content-card h3 {
            color: #1a3c6e;
            margin-bottom: 10px;
            border-bottom: 2px solid #1a3c6e;
            padding-bottom: 8px;
            font-size: 16px;
        }
        .table-container { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            background: #1a3c6e;
            color: #fff;
            padding: 8px 10px;
            text-align: left;
        }
        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table tr:hover { background: #f8fafc; }
        .badge {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
        }
        .badge.active { background: #d4edda; color: #155724; }
        .badge.inactive { background: #f8d7da; color: #721c24; }
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
            max-width: 800px;
            width: 95%;
            margin: 20px auto;
            max-height: 95vh;
            overflow-y: auto;
        }
        .modal-content h2 { color: #1a3c6e; margin-bottom: 15px; font-size: 20px; }
        .modal-content .close-modal { float: right; font-size: 24px; cursor: pointer; color: #999; }
        .modal-content .close-modal:hover { color: #333; }
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
        .form-group input[type="file"] { padding: 6px; }
        .form-group textarea { min-height: 60px; resize: vertical; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .btn-row .btn { flex: 1; text-align: center; }
        .message {
            padding: 8px 15px;
            border-radius: 5px;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }
        .search-box input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        .no-data { text-align: center; color: #999; padding: 20px; }
        .section-title {
            color: #1a3c6e;
            font-weight: bold;
            font-size: 14px;
            margin-top: 12px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e2e8f0;
        }
        .section-title .icon { margin-right: 5px; }
        .photo-preview {
            width: 120px;
            height: 150px;
            border: 2px dashed #ddd;
            border-radius: 10px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #f9f9f9;
        }
        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-preview .placeholder {
            color: #999;
            font-size: 12px;
            text-align: center;
        }
        .profile-thumb {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ddd;
        }
        .profile-thumb-placeholder {
            font-size: 20px;
            color: #999;
        }
        .auto-number-box {
            background: #f0f7ff;
            padding: 10px 15px;
            border-radius: 5px;
            border: 1px solid #1a3c6e;
        }
        .auto-number-box .label {
            font-weight: bold;
            color: #1a3c6e;
        }
        .auto-number-box .number {
            font-size: 16px;
            font-weight: bold;
            color: #1a3c6e;
            font-family: monospace;
        }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .main-content { margin-left: 0; }
            .form-row, .form-row-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
   <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <h1>👨‍🏫 Teachers</h1>
                <p style="color: #666; font-size: 13px;">Manage all teachers in the school</p>
            </div>
            <button class="btn btn-green" onclick="openModal()">➕ Add Teacher</button>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Teacher List -->
        <div class="content-card">
            <h3>📋 All Teachers</h3>
            
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search by name, staff number, or department..." onkeyup="filterTable()">
            </div>
            <div class="table-container">
                <table id="teacherTable">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Staff No</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Qualification</th>
                            <th>Bank</th>
                            <th>SSNIT</th>
                            <th>Teaching</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($teachers)): ?>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($teacher['profile_photo']) && file_exists(__DIR__ . '/../../public/uploads/' . $teacher['profile_photo'])): ?>
                                            <img src="/uploads/<?php echo htmlspecialchars($teacher['profile_photo']); ?>" class="profile-thumb">
                                        <?php else: ?>
                                            <span class="profile-thumb-placeholder">👤</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($teacher['staff_number']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['last_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['department_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['designation_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['qualification'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['bank_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['ssnit_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo $teacher['is_teaching_staff'] ? '✅' : '❌'; ?></td>
                                    <td><span class="badge <?php echo $teacher['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $teacher['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span></td>
                                    <td>
                                        <?php if ($teacher['is_active']): ?>
                                            <a href="/admin/teachers.php?deactivate=1&id=<?php echo $teacher['id']; ?>" class="btn btn-red btn-small" onclick="return confirm('Deactivate this teacher?')">🔴</a>
                                        <?php else: ?>
                                            <a href="/admin/teachers.php?activate=1&id=<?php echo $teacher['id']; ?>" class="btn btn-green btn-small">🟢</a>
                                        <?php endif; ?>
                                        <a href="/admin/teachers.php?edit=<?php echo $teacher['id']; ?>" class="btn btn-small">✏️</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" class="no-data">No teachers found. Click "Add Teacher" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add/Edit Teacher Modal -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="teacherModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal()">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Teacher' : '➕ Add Teacher'; ?></h2>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="save_teacher" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    <input type="hidden" name="existing_photo" value="<?php echo htmlspecialchars($editTeacher['profile_photo'] ?? ''); ?>">
                    
                    <!-- Auto-generated Staff Number -->
                    <div class="section-title"><span class="icon">🔢</span> Staff Number</div>
                    <div class="form-group">
                        <div class="auto-number-box">
                            <div class="label">👨‍🏫 Auto-generated Staff Number:</div>
                            <div class="number">
                                <?php if ($editId): ?>
                                    <?php echo htmlspecialchars($editTeacher['staff_number'] ?? ''); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($nextStaffNumber); ?>
                                <?php endif; ?>
                            </div>
                            <small style="color: #666; display: block; margin-top: 5px;">
                                This number will be automatically assigned. 
                                <a href="/admin/numbering.php" style="color: #1a3c6e;">Configure numbering format</a>
                            </small>
                        </div>
                    </div>
                    
                    <!-- Profile Photo -->
                    <div class="section-title"><span class="icon">📸</span> Profile Photo</div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="profile_photo">Upload Photo</label>
                            <input type="file" id="profile_photo" name="profile_photo" accept="image/*" onchange="previewPhoto(event)">
                            <small style="color: #666; font-size: 11px;">JPG, PNG, GIF (max 2MB)</small>
                        </div>
                        <div class="form-group" style="text-align: center;">
                            <div class="photo-preview" id="photoPreview">
                                <?php if (!empty($editTeacher['profile_photo']) && file_exists(__DIR__ . '/../../public/uploads/' . $editTeacher['profile_photo'])): ?>
                                    <img src="/uploads/<?php echo htmlspecialchars($editTeacher['profile_photo']); ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <span class="placeholder">No Photo</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personal Information -->
                    <div class="section-title"><span class="icon">📋</span> Personal Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($editTeacher['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($editTeacher['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($editTeacher['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gender">Gender *</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select</option>
                                <option value="Male" <?php echo ($editTeacher['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($editTeacher['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($editTeacher['date_of_birth'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="nationality">Nationality</label>
                            <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($editTeacher['nationality'] ?? ''); ?>" placeholder="Ghanaian">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="religion">Religion</label>
                            <input type="text" id="religion" name="religion" value="<?php echo htmlspecialchars($editTeacher['religion'] ?? ''); ?>" placeholder="Christianity">
                        </div>
                        <div class="form-group">
                            <label for="marital_status">Marital Status</label>
                            <select id="marital_status" name="marital_status">
                                <option value="">Select</option>
                                <option value="Single" <?php echo ($editTeacher['marital_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                <option value="Married" <?php echo ($editTeacher['marital_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                <option value="Divorced" <?php echo ($editTeacher['marital_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                <option value="Widowed" <?php echo ($editTeacher['marital_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                <option value="Separated" <?php echo ($editTeacher['marital_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="phone">Phone *</label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($editTeacher['primary_phone'] ?? ''); ?>" placeholder="024XXXXXXX" required>
                        </div>
                        <div class="form-group">
                            <label for="secondary_phone">Secondary Phone</label>
                            <input type="text" id="secondary_phone" name="secondary_phone" value="<?php echo htmlspecialchars($editTeacher['secondary_phone'] ?? ''); ?>" placeholder="020XXXXXXX">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($editTeacher['primary_email'] ?? ''); ?>" placeholder="teacher@school.com">
                        </div>
                        <div class="form-group">
                            <label for="secondary_email">Secondary Email</label>
                            <input type="email" id="secondary_email" name="secondary_email" value="<?php echo htmlspecialchars($editTeacher['secondary_email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" placeholder="Home address"><?php echo htmlspecialchars($editTeacher['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="emergency_contact">Emergency Contact Name</label>
                            <input type="text" id="emergency_contact" name="emergency_contact" value="<?php echo htmlspecialchars($editTeacher['emergency_contact_name'] ?? ''); ?>" placeholder="Next of kin">
                        </div>
                        <div class="form-group">
                            <label for="emergency_phone">Emergency Contact Phone</label>
                            <input type="text" id="emergency_phone" name="emergency_phone" value="<?php echo htmlspecialchars($editTeacher['emergency_contact_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                    </div>
                    
                    <!-- Employment Information -->
                    <div class="section-title"><span class="icon">💼</span> Employment Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="staff_category_id">Staff Category *</label>
                            <select id="staff_category_id" name="staff_category_id" required>
                                <option value="">Select</option>
                                <?php foreach ($staffCategories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($editTeacher['staff_category_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="department_id">Department *</label>
                            <select id="department_id" name="department_id" required>
                                <option value="">Select</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($editTeacher['department_id'] ?? '') == $dept['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="designation_id">Designation</label>
                            <select id="designation_id" name="designation_id">
                                <option value="0">None</option>
                                <?php foreach ($designations as $des): ?>
                                    <option value="<?php echo $des['id']; ?>" <?php echo ($editTeacher['designation_id'] ?? '') == $des['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($des['designation_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employment_type_id">Employment Type *</label>
                            <select id="employment_type_id" name="employment_type_id" required>
                                <option value="">Select</option>
                                <?php foreach ($employmentTypes as $et): ?>
                                    <option value="<?php echo $et['id']; ?>" <?php echo ($editTeacher['employment_type_id'] ?? '') == $et['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($et['employment_type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="joining_date">Joining Date *</label>
                            <input type="date" id="joining_date" name="joining_date" value="<?php echo htmlspecialchars($editTeacher['joining_date'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contract_start_date">Contract Start Date</label>
                            <input type="date" id="contract_start_date" name="contract_start_date" value="<?php echo htmlspecialchars($editTeacher['contract_start_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="contract_end_date">Contract End Date</label>
                            <input type="date" id="contract_end_date" name="contract_end_date" value="<?php echo htmlspecialchars($editTeacher['contract_end_date'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="qualification">Qualification</label>
                        <input type="text" id="qualification" name="qualification" value="<?php echo htmlspecialchars($editTeacher['qualification'] ?? ''); ?>" placeholder="B.Ed, M.Sc, etc.">
                    </div>
                    
                    <!-- Banking Information -->
                    <div class="section-title"><span class="icon">🏦</span> Banking Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="bank_name">Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name" value="<?php echo htmlspecialchars($editTeacher['bank_name'] ?? ''); ?>" placeholder="Ghana Commercial Bank">
                        </div>
                        <div class="form-group">
                            <label for="bank_branch">Bank Branch</label>
                            <input type="text" id="bank_branch" name="bank_branch" value="<?php echo htmlspecialchars($editTeacher['bank_branch'] ?? ''); ?>" placeholder="Kumasi Main">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="account_number">Account Number</label>
                            <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars($editTeacher['bank_account_number'] ?? ''); ?>" placeholder="1234567890">
                        </div>
                        <div class="form-group">
                            <label for="ssnit_number">SSNIT Number</label>
                            <input type="text" id="ssnit_number" name="ssnit_number" value="<?php echo htmlspecialchars($editTeacher['ssnit_number'] ?? ''); ?>" placeholder="SN-1234567890">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="tin_number">TIN Number</label>
                        <input type="text" id="tin_number" name="tin_number" value="<?php echo htmlspecialchars($editTeacher['tin_number'] ?? ''); ?>" placeholder="TIN-1234567890">
                    </div>
                    
                    <!-- Additional Options -->
                    <div class="section-title"><span class="icon">⚙️</span> Additional Options</div>
                    <div class="form-group" style="margin-top: 8px;">
                        <label>
                            <input type="checkbox" name="is_teaching" value="1" <?php echo ($editTeacher['is_teaching_staff'] ?? 0) ? 'checked' : ''; ?>> 
                            This is a teaching staff member
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Additional information about this teacher"><?php echo htmlspecialchars($editTeacher['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update Teacher' : 'Save Teacher'; ?></button>
                        <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('teacherTable');
            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let match = false;
                for (let j = 0; j < cells.length; j++) {
                    const text = cells[j].textContent || cells[j].innerText;
                    if (text.toUpperCase().indexOf(filter) > -1) {
                        match = true;
                        break;
                    }
                }
                rows[i].style.display = match ? '' : 'none';
            }
        }
        
        function previewPhoto(event) {
            const reader = new FileReader();
            reader.onload = function() {
                const preview = document.getElementById('photoPreview');
                preview.innerHTML = '<img src="' + reader.result + '" alt="Profile Photo">';
            }
            reader.readAsDataURL(event.target.files[0]);
        }
        
        function openModal() {
            document.getElementById('teacherModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('teacherModal').classList.remove('show');
        }
        
        <?php if ($action === 'add' || $editId): ?>
            document.getElementById('teacherModal').classList.add('show');
        <?php endif; ?>
        
        window.onclick = function(event) {
            const modal = document.getElementById('teacherModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>