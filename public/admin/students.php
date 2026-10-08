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

// Get next admission number for display
$nextAdmissionNumber = $numbering->peekNextStudentNumber();

// Handle Add/Edit Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['date_of_birth'] ?? '';
    $classSectionId = $_POST['class_section_id'] ?? 0;
    $editId = $_POST['edit_id'] ?? null;
    
    // Personal
    $nationality = $_POST['nationality'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $placeOfBirth = $_POST['place_of_birth'] ?? '';
    $primaryPhone = $_POST['primary_phone'] ?? '';
    $secondaryPhone = $_POST['secondary_phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $address = $_POST['address'] ?? '';
    $bloodGroup = $_POST['blood_group'] ?? '';
    $medicalConditions = $_POST['medical_conditions'] ?? '';
    $heightCm = $_POST['height_cm'] ?? '';
    $weightKg = $_POST['weight_kg'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Father
    $fatherName = $_POST['father_name'] ?? '';
    $fatherPhone = $_POST['father_phone'] ?? '';
    $fatherOccupation = $_POST['father_occupation'] ?? '';
    
    // Mother
    $motherName = $_POST['mother_name'] ?? '';
    $motherPhone = $_POST['mother_phone'] ?? '';
    $motherOccupation = $_POST['mother_occupation'] ?? '';
    
    // Guardian
    $guardianName = $_POST['guardian_name'] ?? '';
    $guardianPhone = $_POST['guardian_phone'] ?? '';
    $guardianRelationship = $_POST['guardian_relationship'] ?? '';
    $guardianOccupation = $_POST['guardian_occupation'] ?? '';
    
    // Parent
    $parentName = $_POST['parent_name'] ?? '';
    $parentPhone = $_POST['parent_phone'] ?? '';
    $parentEmail = $_POST['parent_email'] ?? '';
    
    // Emergency
    $emergencyName = $_POST['emergency_contact_name'] ?? '';
    $emergencyPhone = $_POST['emergency_contact_phone'] ?? '';
    
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
            $filename = 'student_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
            $profilePhoto = $filename;
        }
    }
    
    if ($firstName && $lastName && $gender && $dob && $classSectionId) {
        try {
            if ($editId) {
                // Update existing student
                $sql = "UPDATE students SET
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    gender = ?,
                    date_of_birth = ?,
                    nationality = ?,
                    religion = ?,
                    place_of_birth = ?,
                    primary_phone = ?,
                    secondary_phone = ?,
                    email = ?,
                    address = ?,
                    profile_photo = ?,
                    blood_group = ?,
                    medical_conditions = ?,
                    height_cm = ?,
                    weight_kg = ?,
                    father_name = ?,
                    father_phone = ?,
                    father_occupation = ?,
                    mother_name = ?,
                    mother_phone = ?,
                    mother_occupation = ?,
                    guardian_name = ?,
                    guardian_phone = ?,
                    guardian_relationship = ?,
                    guardian_occupation = ?,
                    parent_name = ?,
                    parent_phone = ?,
                    parent_email = ?,
                    emergency_contact_name = ?,
                    emergency_contact_phone = ?,
                    notes = ?,
                    updated_at = NOW()
                    WHERE id = ? AND school_id = 1";
                $db->query($sql, [
                    $firstName, $middleName, $lastName, $gender, $dob,
                    $nationality, $religion, $placeOfBirth,
                    $primaryPhone, $secondaryPhone, $email, $address,
                    $profilePhoto, $bloodGroup, $medicalConditions, $heightCm, $weightKg,
                    $fatherName, $fatherPhone, $fatherOccupation,
                    $motherName, $motherPhone, $motherOccupation,
                    $guardianName, $guardianPhone, $guardianRelationship, $guardianOccupation,
                    $parentName, $parentPhone, $parentEmail,
                    $emergencyName, $emergencyPhone,
                    $notes, $editId
                ]);
                
                // Update enrollment if class changed
                $sql = "UPDATE student_enrollments SET class_section_id = ? WHERE student_id = ? AND is_active = 1";
                $db->query($sql, [$classSectionId, $editId]);
                
                header('Location: /admin/students.php?msg=Student updated successfully!');
                exit;
            } else {
                // Auto-generate admission number
                $admissionNumber = $numbering->generateStudentNumber();
                
                // Insert new student
                $sql = "INSERT INTO students (
                    uuid, school_id, admission_number,
                    first_name, middle_name, last_name,
                    gender, date_of_birth,
                    nationality, religion, place_of_birth,
                    primary_phone, secondary_phone, email, address,
                    profile_photo, blood_group, medical_conditions,
                    height_cm, weight_kg,
                    father_name, father_phone, father_occupation,
                    mother_name, mother_phone, mother_occupation,
                    guardian_name, guardian_phone, guardian_relationship, guardian_occupation,
                    parent_name, parent_phone, parent_email,
                    emergency_contact_name, emergency_contact_phone,
                    notes, enrollment_status, is_active
                ) VALUES (
                    UUID(), 1, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, 'Active', 1
                )";
                $db->query($sql, [
                    $admissionNumber,
                    $firstName, $middleName, $lastName,
                    $gender, $dob,
                    $nationality, $religion, $placeOfBirth,
                    $primaryPhone, $secondaryPhone, $email, $address,
                    $profilePhoto, $bloodGroup, $medicalConditions,
                    $heightCm, $weightKg,
                    $fatherName, $fatherPhone, $fatherOccupation,
                    $motherName, $motherPhone, $motherOccupation,
                    $guardianName, $guardianPhone, $guardianRelationship, $guardianOccupation,
                    $parentName, $parentPhone, $parentEmail,
                    $emergencyName, $emergencyPhone,
                    $notes
                ]);
                $studentId = $db->lastInsertId();
                
                // Enroll student
                $sql = "INSERT INTO student_enrollments (
                    uuid, school_id, student_id,
                    class_section_id, academic_year_id,
                    academic_term_id, enrollment_date, enrollment_status
                ) VALUES (
                    UUID(), 1, ?,
                    ?, 1,
                    1, CURDATE(), 'Active'
                )";
                $db->query($sql, [$studentId, $classSectionId]);
                
                header('Location: /admin/students.php?msg=Student added successfully! Admission Number: ' . $admissionNumber);
                exit;
            }
        } catch (Exception $e) {
            header('Location: /admin/students.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header('Location: /admin/students.php?error=Please fill in all required fields.');
        exit;
    }
}

// Get student for editing
$editStudent = null;
if ($editId) {
    try {
        $editStudent = $db->fetchOne("
            SELECT s.*, se.class_section_id 
            FROM students s
            LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
            WHERE s.id = ? AND s.school_id = 1
        ", [$editId]);
    } catch (Exception $e) {
        $editStudent = null;
    }
}

// Get all students
$students = [];
try {
    $students = $db->fetchAll("
        SELECT 
            s.*,
            cs.section_name,
            gl.level_name
        FROM students s
        LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
        LEFT JOIN class_sections cs ON se.class_section_id = cs.id
        LEFT JOIN grade_levels gl ON cs.grade_level_id = gl.id
        WHERE s.school_id = 1
        ORDER BY s.id DESC
    ");
} catch (Exception $e) {
    $students = [];
}

// Get classes for dropdown
$classes = [];
try {
    $classes = $db->fetchAll("
        SELECT cs.*, gl.level_name 
        FROM class_sections cs
        JOIN grade_levels gl ON cs.grade_level_id = gl.id
        WHERE cs.is_active = 1
    ");
} catch (Exception $e) {
    $classes = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - Admin</title>
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
            max-width: 750px;
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
        .form-group textarea { min-height: 50px; resize: vertical; }
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
                <h1>👨‍🎓 Students</h1>
                <p style="color: #666; font-size: 13px;">Manage all students in the school</p>
            </div>
            <button class="btn btn-green" onclick="openModal('add')">➕ Add Student</button>
        </div>
        
        <?php if ($message): ?>
            <div class="message success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Student List -->
        <div class="content-card">
            <h3>📋 All Students</h3>
            
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search by name, admission number, or class..." onkeyup="filterTable()">
            </div>
            <div class="table-container">
                <table id="studentTable">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>ID</th>
                            <th>Admission No</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Class</th>
                            <th>Father</th>
                            <th>Mother</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($student['profile_photo']) && file_exists(__DIR__ . '/../../public/uploads/' . $student['profile_photo'])): ?>
                                            <img src="/uploads/<?php echo htmlspecialchars($student['profile_photo']); ?>" class="profile-thumb">
                                        <?php else: ?>
                                            <span class="profile-thumb-placeholder">👤</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $student['id']; ?></td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                    <td><?php echo $student['gender']; ?></td>
                                    <td><?php echo htmlspecialchars($student['section_name'] ?? 'Not Assigned'); ?></td>
                                    <td><?php echo htmlspecialchars($student['father_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['mother_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['primary_phone'] ?? 'N/A'); ?></td>
                                    <td><span class="badge <?php echo $student['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span></td>
                                    <td>
                                        <a href="/admin/students.php?edit=<?php echo $student['id']; ?>" class="btn btn-small">✏️ Edit</a>
                                        <a href="#" class="btn btn-red btn-small" onclick="return confirm('Deactivate this student?')">🔴</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" class="no-data">No students found. Click "Add Student" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Add/Edit Student Modal -->
        <div class="modal <?php echo ($action === 'add' || $editId) ? 'show' : ''; ?>" id="studentModal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal()">&times;</span>
                <h2><?php echo $editId ? '✏️ Edit Student' : '➕ Add Student'; ?></h2>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="save_student" value="1">
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                    <input type="hidden" name="existing_photo" value="<?php echo htmlspecialchars($editStudent['profile_photo'] ?? ''); ?>">
                    
                    <!-- Auto-generated Admission Number -->
                    <div class="section-title"><span class="icon">🔢</span> Admission Number</div>
                    <div class="form-group">
                        <div class="auto-number-box">
                            <div class="label">🎓 Auto-generated Admission Number:</div>
                            <div class="number" id="previewAdmissionNumber">
                                <?php if ($editId): ?>
                                    <?php echo htmlspecialchars($editStudent['admission_number'] ?? ''); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($nextAdmissionNumber); ?>
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
                                <?php if (!empty($editStudent['profile_photo']) && file_exists(__DIR__ . '/../../public/uploads/' . $editStudent['profile_photo'])): ?>
                                    <img src="/uploads/<?php echo htmlspecialchars($editStudent['profile_photo']); ?>" alt="Profile Photo">
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
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($editStudent['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($editStudent['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($editStudent['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gender">Gender *</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select</option>
                                <option value="Male" <?php echo ($editStudent['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($editStudent['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth *</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($editStudent['date_of_birth'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="nationality">Nationality</label>
                            <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($editStudent['nationality'] ?? ''); ?>" placeholder="Ghanaian">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="religion">Religion</label>
                            <input type="text" id="religion" name="religion" value="<?php echo htmlspecialchars($editStudent['religion'] ?? ''); ?>" placeholder="Christianity">
                        </div>
                        <div class="form-group">
                            <label for="place_of_birth">Place of Birth</label>
                            <input type="text" id="place_of_birth" name="place_of_birth" value="<?php echo htmlspecialchars($editStudent['place_of_birth'] ?? ''); ?>" placeholder="Kumasi">
                        </div>
                        <div class="form-group">
                            <label for="blood_group">Blood Group</label>
                            <select id="blood_group" name="blood_group">
                                <option value="">Select</option>
                                <option value="A+" <?php echo ($editStudent['blood_group'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                                <option value="A-" <?php echo ($editStudent['blood_group'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                                <option value="B+" <?php echo ($editStudent['blood_group'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                                <option value="B-" <?php echo ($editStudent['blood_group'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                                <option value="AB+" <?php echo ($editStudent['blood_group'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                <option value="AB-" <?php echo ($editStudent['blood_group'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                <option value="O+" <?php echo ($editStudent['blood_group'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                                <option value="O-" <?php echo ($editStudent['blood_group'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="height_cm">Height (cm)</label>
                            <input type="number" id="height_cm" name="height_cm" value="<?php echo htmlspecialchars($editStudent['height_cm'] ?? ''); ?>" step="0.1" placeholder="150.0">
                        </div>
                        <div class="form-group">
                            <label for="weight_kg">Weight (kg)</label>
                            <input type="number" id="weight_kg" name="weight_kg" value="<?php echo htmlspecialchars($editStudent['weight_kg'] ?? ''); ?>" step="0.1" placeholder="45.0">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="primary_phone">Primary Phone</label>
                            <input type="text" id="primary_phone" name="primary_phone" value="<?php echo htmlspecialchars($editStudent['primary_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="secondary_phone">Secondary Phone</label>
                            <input type="text" id="secondary_phone" name="secondary_phone" value="<?php echo htmlspecialchars($editStudent['secondary_phone'] ?? ''); ?>" placeholder="020XXXXXXX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($editStudent['email'] ?? ''); ?>" placeholder="student@school.com">
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" placeholder="Home address"><?php echo htmlspecialchars($editStudent['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Father Information -->
                    <div class="section-title"><span class="icon">👨</span> Father's Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="father_name">Father's Name</label>
                            <input type="text" id="father_name" name="father_name" value="<?php echo htmlspecialchars($editStudent['father_name'] ?? ''); ?>" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label for="father_phone">Father's Phone</label>
                            <input type="text" id="father_phone" name="father_phone" value="<?php echo htmlspecialchars($editStudent['father_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="father_occupation">Father's Occupation</label>
                            <input type="text" id="father_occupation" name="father_occupation" value="<?php echo htmlspecialchars($editStudent['father_occupation'] ?? ''); ?>" placeholder="Occupation">
                        </div>
                    </div>
                    
                    <!-- Mother Information -->
                    <div class="section-title"><span class="icon">👩</span> Mother's Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="mother_name">Mother's Name</label>
                            <input type="text" id="mother_name" name="mother_name" value="<?php echo htmlspecialchars($editStudent['mother_name'] ?? ''); ?>" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label for="mother_phone">Mother's Phone</label>
                            <input type="text" id="mother_phone" name="mother_phone" value="<?php echo htmlspecialchars($editStudent['mother_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="mother_occupation">Mother's Occupation</label>
                            <input type="text" id="mother_occupation" name="mother_occupation" value="<?php echo htmlspecialchars($editStudent['mother_occupation'] ?? ''); ?>" placeholder="Occupation">
                        </div>
                    </div>
                    
                    <!-- Guardian Information -->
                    <div class="section-title"><span class="icon">🛡️</span> Guardian Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="guardian_name">Guardian's Name</label>
                            <input type="text" id="guardian_name" name="guardian_name" value="<?php echo htmlspecialchars($editStudent['guardian_name'] ?? ''); ?>" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label for="guardian_phone">Guardian's Phone</label>
                            <input type="text" id="guardian_phone" name="guardian_phone" value="<?php echo htmlspecialchars($editStudent['guardian_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="guardian_relationship">Relationship to Student</label>
                            <select id="guardian_relationship" name="guardian_relationship">
                                <option value="">Select</option>
                                <option value="Father" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Father' ? 'selected' : ''; ?>>Father</option>
                                <option value="Mother" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Mother' ? 'selected' : ''; ?>>Mother</option>
                                <option value="Uncle" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Uncle' ? 'selected' : ''; ?>>Uncle</option>
                                <option value="Aunt" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Aunt' ? 'selected' : ''; ?>>Aunt</option>
                                <option value="Grandfather" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Grandfather' ? 'selected' : ''; ?>>Grandfather</option>
                                <option value="Grandmother" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Grandmother' ? 'selected' : ''; ?>>Grandmother</option>
                                <option value="Sibling" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Sibling' ? 'selected' : ''; ?>>Sibling</option>
                                <option value="Other" <?php echo ($editStudent['guardian_relationship'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="guardian_occupation">Guardian's Occupation</label>
                        <input type="text" id="guardian_occupation" name="guardian_occupation" value="<?php echo htmlspecialchars($editStudent['guardian_occupation'] ?? ''); ?>" placeholder="Occupation">
                    </div>
                    
                    <!-- Parent Information -->
                    <div class="section-title"><span class="icon">👨‍👩‍👧</span> Parent/Guardian Contact</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_name">Parent/Guardian Name</label>
                            <input type="text" id="parent_name" name="parent_name" value="<?php echo htmlspecialchars($editStudent['parent_name'] ?? ''); ?>" placeholder="Parent's full name">
                        </div>
                        <div class="form-group">
                            <label for="parent_phone">Parent Phone</label>
                            <input type="text" id="parent_phone" name="parent_phone" value="<?php echo htmlspecialchars($editStudent['parent_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="parent_email">Parent Email</label>
                            <input type="email" id="parent_email" name="parent_email" value="<?php echo htmlspecialchars($editStudent['parent_email'] ?? ''); ?>" placeholder="parent@email.com">
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="section-title"><span class="icon">🆘</span> Emergency Contact</div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="emergency_contact_name">Emergency Contact Name</label>
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($editStudent['emergency_contact_name'] ?? ''); ?>" placeholder="Emergency contact">
                        </div>
                        <div class="form-group">
                            <label for="emergency_contact_phone">Emergency Contact Phone</label>
                            <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo htmlspecialchars($editStudent['emergency_contact_phone'] ?? ''); ?>" placeholder="024XXXXXXX">
                        </div>
                    </div>
                    
                    <!-- Medical Information -->
                    <div class="section-title"><span class="icon">🏥</span> Medical Information</div>
                    <div class="form-group">
                        <label for="medical_conditions">Medical Conditions / Allergies</label>
                        <textarea id="medical_conditions" name="medical_conditions" placeholder="List any medical conditions or allergies"><?php echo htmlspecialchars($editStudent['medical_conditions'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Academic Information -->
                    <div class="section-title"><span class="icon">📚</span> Academic Information</div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="class_section_id">Class *</label>
                            <select id="class_section_id" name="class_section_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo ($editStudent['class_section_id'] ?? '') == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['level_name'] . ' ' . $class['section_code'] . ' - ' . $class['section_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Additional Notes -->
                    <div class="section-title"><span class="icon">📝</span> Additional Notes</div>
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Any additional information about the student"><?php echo htmlspecialchars($editStudent['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="btn-row">
                        <button type="submit" class="btn btn-green">💾 <?php echo $editId ? 'Update Student' : 'Save Student'; ?></button>
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
            const table = document.getElementById('studentTable');
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
            document.getElementById('studentModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('studentModal').classList.remove('show');
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('studentModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        <?php if ($action === 'add' || $editId): ?>
            document.getElementById('studentModal').classList.add('show');
        <?php endif; ?>
    </script>
</body>
</html>