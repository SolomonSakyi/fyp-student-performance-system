<?php

/**
 * Register Staff - Tenant Admin registers a new staff member.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Staff
 * @version 1.0
 * @filepath public/platform/tenant/staff/create.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Twelfth file of the tenant-surface sweep (first under staff/).
 *   Three changes:
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. This file's inline
 *       sidebar carried six items (Dashboard; Schools, Campuses;
 *       Staff, Students; Settings) and no Subscription,
 *       Notifications, or Logout nav link. The partial carries
 *       all nine. After the refactor, this page renders the
 *       Subscription, Notifications, and Logout items as well.
 *       The inline sidebar's Settings link pointed at
 *       /platform/tenant/settings.php; the partial's Settings
 *       link points at /platform/tenant/settings/index.php —
 *       the live shell. The refactor corrects that link.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - A docblock was added. The file previously carried no
 *       @version, @package, or @filepath tag. The new docblock
 *       carries them.
 *   Every other line of the file is byte-identical to the
 *   pre-sweep version. The @package tag is 'EduTrack' — it names
 *   the codebase package, not the product.
 */

// ERROR REPORTING - ENABLED FOR DEBUGGING
// ============================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// SESSION & AUTHENTICATION
// ============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// ORGANIZATIONAL CONTEXT
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$campusId = $_SESSION['campus_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found.'];
    header('Location: /platform/tenants/select.php');
    exit;
}

$pageTitle = 'Register Staff - Student 360 Platform';
$currentPage = 'staff';

// DATABASE & CONFIGURATION
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

$db = DatabaseHelper::getInstance();

// ============================================
// FILE UPLOAD HELPER
// ============================================
function handleProfilePhotoUpload($fileInputName, $tenantId, $personId, $projectRoot)
{
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$fileInputName];

    if ($file['size'] > 20 * 1024 * 1024) {
        throw new Exception('Profile photo must be smaller than 20MB.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG, GIF or WEBP images are allowed for profile photos.');
    }
    $ext = $allowed[$mime];

    $uploadDir = $projectRoot . '/public/uploads/tenants/' . (int)$tenantId . '/staff/' . (int)$personId . '/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }
    }

    $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Failed to save profile photo.');
    }

    return '/uploads/tenants/' . (int)$tenantId . '/staff/' . (int)$personId . '/' . $filename;
}

// ============================================
// STAFF NUMBER GENERATOR — server-side, always fresh on create
// ============================================
function generateStaffNumber($db, $tenantId)
{
    $year = date('Y');
    $prefix = 'EMP-' . $year . '-';

    // Use a unique lock name per tenant and per year
    $lockName = 'staff_num_' . (int)$tenantId . '_' . $year;
    try {
        $db->execute("SELECT GET_LOCK(?, 10)", [$lockName]);

        // The sequence starts at character 10 (1-indexed) because:
        // E(1) M(2) P(3) -(4) Y(5) Y(6) Y(7) Y(8) -(9) -> sequence starts at 10
        $seqResult = $db->fetchOne(
            "SELECT MAX(CAST(SUBSTRING(staff_number, 10) AS UNSIGNED)) as max_seq
             FROM staff
             WHERE tenant_id = ? AND staff_number LIKE ?",
            [$tenantId, $prefix . '%']
        );
        $nextSeq = ($seqResult['max_seq'] ?? 0) + 1;
        return $prefix . str_pad($nextSeq, 6, '0', STR_PAD_LEFT);
    } finally {
        try {
            $db->execute("SELECT RELEASE_LOCK(?)", [$lockName]);
        } catch (Exception $e) {
        }
    }
}

// ============================================
// AJAX ENDPOINT — Live duplicate email check
// ============================================
if (isset($_GET['ajax_check_email']) && $_GET['ajax_check_email'] == '1') {
    header('Content-Type: application/json');
    $email = trim($_GET['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['exists' => false, 'valid' => false]);
        exit;
    }
    $existing = $db->fetchOne(
        "SELECT id FROM persons WHERE email = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$email, $tenantId]
    );
    echo json_encode(['exists' => (bool)$existing, 'valid' => true]);
    exit;
}

// LOOKUP DATA
$staffCategories = $db->fetchAll("SELECT DISTINCT id, category_name FROM staff_categories WHERE is_active = 1 ORDER BY sort_order");
$staffTypes = $db->fetchAll("SELECT DISTINCT id, type_name, staff_category_id FROM staff_types WHERE is_active = 1 ORDER BY sort_order");
$employmentTypes = $db->fetchAll("SELECT DISTINCT id, employment_type_name, is_contract FROM employment_types WHERE is_active = 1 ORDER BY employment_type_name");
$staffStatuses = $db->fetchAll("SELECT DISTINCT id, status_name FROM staff_statuses WHERE is_active = 1 ORDER BY status_name");
$departments = $db->fetchAll("SELECT DISTINCT id, department_name FROM departments WHERE school_id = ? AND is_active = 1 ORDER BY department_name", [$schoolId]);
$qualificationLevels = $db->fetchAll("SELECT DISTINCT id, level_name FROM qualification_levels WHERE is_active = 1 ORDER BY sort_order");
$identificationTypes = $db->fetchAll("SELECT DISTINCT id, type_name FROM identification_types WHERE is_active = 1 ORDER BY sort_order");
$licenseTypes = $db->fetchAll("SELECT DISTINCT id, type_name, requires_teaching FROM license_types WHERE is_active = 1 ORDER BY sort_order");
$classes = $db->fetchAll("SELECT DISTINCT id, class_name, class_code FROM classes WHERE tenant_id = ? AND is_active = 1 ORDER BY class_name", [$tenantId]);
$subjects = $db->fetchAll("SELECT DISTINCT id, subject_name, subject_code FROM subjects WHERE tenant_id = ? AND is_active = 1 ORDER BY subject_name", [$tenantId]);
$academicYears = $db->fetchAll("SELECT DISTINCT id, year_name FROM academic_years WHERE tenant_id = ? AND is_active = 1 ORDER BY year_name DESC", [$tenantId]);
$academicTerms = $db->fetchAll("SELECT DISTINCT id, term_name FROM academic_terms WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order", [$tenantId]);
$streams = $db->fetchAll("SELECT DISTINCT id, stream_name FROM streams WHERE tenant_id = ? AND is_active = 1 ORDER BY stream_name", [$tenantId]);
$managers = $db->fetchAll("SELECT s.id, p.first_name, p.last_name, s.staff_number FROM staff s LEFT JOIN persons p ON s.person_id = p.id WHERE s.tenant_id = ? AND s.is_active = 1 AND s.deleted_at IS NULL ORDER BY p.first_name", [$tenantId]);
$platformUsers = $db->fetchAll("SELECT DISTINCT id, first_name, last_name, username FROM platform_users WHERE tenant_id = ? AND is_active = 1 ORDER BY first_name", [$tenantId]);

$tenantName = '';
$tenant = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
if ($tenant) {
    $tenantName = $tenant['tenant_name'] ?? 'Tenant #' . $tenantId;
}

$schoolName = '';
if ($schoolId > 0) {
    $school = $db->fetchOne("SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL", [$schoolId]);
    if ($school) {
        $schoolName = $school['school_name'] ?? '';
    }
}

if ($schoolId <= 0) {
    $firstSchool = $db->fetchOne("SELECT id FROM schools WHERE tenant_id = ? AND deleted_at IS NULL LIMIT 1", [$tenantId]);
    if ($firstSchool) {
        $schoolId = $firstSchool['id'];
        $_SESSION['school_id'] = $schoolId;
    } else {
        $db->execute("INSERT INTO schools (tenant_id, school_name, school_code, status, is_active, created_at) VALUES (?, 'Default School', 'DEF0001', 'active', 1, NOW())", [$tenantId]);
        $schoolId = $db->getValue("SELECT LAST_INSERT_ID()");
        $_SESSION['school_id'] = $schoolId;
    }
    $departments = $db->fetchAll("SELECT DISTINCT id, department_name FROM departments WHERE school_id = ? AND is_active = 1 ORDER BY department_name", [$schoolId]);
}

// Preview staff number (display only, never submitted)
$previewStaffNumber = generateStaffNumber($db, $tenantId);

$assignmentTypes = ['class_teacher' => 'Class Teacher', 'subject_teacher' => 'Subject Teacher', 'special_education' => 'Special Education'];
$disciplineTypes = [
    'learning_disability' => 'Learning Disability',
    'autism' => 'Autism Spectrum Disorder',
    'adhd' => 'ADHD',
    'dyslexia' => 'Dyslexia',
    'dyscalculia' => 'Dyscalculia',
    'dysgraphia' => 'Dysgraphia',
    'physical_disability' => 'Physical Disability',
    'visual_impairment' => 'Visual Impairment',
    'hearing_impairment' => 'Hearing Impairment',
    'speech_language' => 'Speech/Language Disorder',
    'emotional_behavioral' => 'Emotional/Behavioral Disorder',
    'intellectual_disability' => 'Intellectual Disability',
    'other' => 'Other'
];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$genotypes = ['AA', 'AS', 'SS', 'AC', 'SC', 'CC'];
$biometricTypes = ['fingerprint' => 'Fingerprint', 'facial' => 'Facial Recognition', 'iris' => 'Iris Scan', 'voice' => 'Voice Recognition', 'other' => 'Other'];
$fingerPositions = [
    'right_thumb' => 'Right Thumb',
    'right_index' => 'Right Index',
    'right_middle' => 'Right Middle',
    'right_ring' => 'Right Ring',
    'right_pinky' => 'Right Pinky',
    'left_thumb' => 'Left Thumb',
    'left_index' => 'Left Index',
    'left_middle' => 'Left Middle',
    'left_ring' => 'Left Ring',
    'left_pinky' => 'Left Pinky'
];
$onboardingItems = [
    'identity_verified' => 'Identity verified',
    'contact_verified' => 'Contact info verified',
    'emergency_contact' => 'Emergency contact',
    'national_id' => 'National ID verified',
    'qualifications_submitted' => 'Qualifications submitted',
    'qualifications_verified' => 'Qualifications verified',
    'licenses_submitted' => 'Licenses submitted',
    'licenses_verified' => 'Licenses verified',
    'employment_docs' => 'Employment docs',
    'contract_uploaded' => 'Contract uploaded',
    'bank_details' => 'Bank details',
    'payroll_profile' => 'Payroll profile',
    'profile_photo' => 'Profile photo',
    'signature_captured' => 'Signature captured',
    'biometric_enrolled' => 'Biometric enrolled',
    'system_account' => 'System account',
    'system_role' => 'System role',
    'academic_assignments' => 'Assignments configured',
    'onboarding_completed' => 'Onboarding completed'
];

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$errors = [];
$formData = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $formData = $_POST;
    $db->beginTransaction();

    try {
        // --- Step 1: Validate ---
        foreach (['first_name', 'last_name', 'email', 'primary_phone'] as $field) {
            if (empty($_POST[$field])) $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
        foreach (['staff_category_id', 'staff_type_id', 'employment_type_id', 'staff_status_id', 'hire_date'] as $field) {
            if (empty($_POST[$field])) $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }

        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!empty($_POST['email'])) {
            $existing = $db->fetchOne("SELECT id FROM persons WHERE email = ? AND tenant_id = ? AND deleted_at IS NULL", [trim($_POST['email']), $tenantId]);
            if ($existing) $errors[] = 'A person with email "' . htmlspecialchars($_POST['email']) . '" already exists. Please use a different email.';
        }
        if (!empty($_POST['primary_phone'])) {
            $existing = $db->fetchOne("SELECT id FROM persons WHERE primary_phone = ? AND tenant_id = ? AND deleted_at IS NULL", [trim($_POST['primary_phone']), $tenantId]);
            if ($existing) $errors[] = 'A person with primary phone "' . htmlspecialchars($_POST['primary_phone']) . '" already exists.';
        }
        if (!empty($_POST['primary_phone']) && !preg_match('/^[\+\d\s\-\(\)]{7,20}$/', $_POST['primary_phone'])) {
            $errors[] = 'Please enter a valid primary phone number.';
        }
        if (!empty($_POST['date_of_birth']) && strtotime($_POST['date_of_birth']) > strtotime('-5 years')) {
            $errors[] = 'Please enter a valid date of birth.';
        }
        if (!empty($_POST['hire_date']) && strtotime($_POST['hire_date']) === false) {
            $errors[] = 'Please enter a valid hire date.';
        }

        if (!empty($_POST['employment_type_id'])) {
            $empType = $db->fetchOne("SELECT is_contract FROM employment_types WHERE id = ? AND is_active = 1", [$_POST['employment_type_id']]);
            if ($empType && $empType['is_contract'] == 1) {
                if (empty($_POST['contract_start_date'])) $errors[] = 'Contract start date is required.';
                if (empty($_POST['contract_end_date'])) $errors[] = 'Contract end date is required.';
                if (!empty($_POST['contract_start_date']) && !empty($_POST['contract_end_date'])) {
                    if (strtotime($_POST['contract_end_date']) <= strtotime($_POST['contract_start_date'])) {
                        $errors[] = 'Contract end date must be after start date.';
                    }
                }
            }
        }

        if (isset($_POST['is_teaching_staff']) && $_POST['is_teaching_staff'] == 1) {
            if (isset($_POST['assignments']) && is_array($_POST['assignments'])) {
                $hasValidAssignment = false;
                foreach ($_POST['assignments'] as $index => $assignment) {
                    if (empty($assignment['academic_year_id'])) $errors[] = 'Academic Year is required for assignment #' . ($index + 1) . '.';
                    if (empty($assignment['assignment_type'])) $errors[] = 'Assignment Type is required for assignment #' . ($index + 1) . '.';
                    $type = $assignment['assignment_type'] ?? '';
                    if ($type == 'class_teacher' && (empty($assignment['class_ids']) || !is_array($assignment['class_ids']))) {
                        $errors[] = 'Class(es) is required for Class Teacher assignment #' . ($index + 1) . '.';
                    }
                    if ($type == 'subject_teacher') {
                        if (empty($assignment['subject_ids']) || !is_array($assignment['subject_ids'])) $errors[] = 'Subject(s) is required for Subject Teacher assignment #' . ($index + 1) . '.';
                        if (empty($assignment['class_ids']) || !is_array($assignment['class_ids'])) $errors[] = 'Class(es) is required for Subject Teacher assignment #' . ($index + 1) . '.';
                    }
                    if ($type == 'special_education') {
                        if (empty($assignment['discipline_type'])) $errors[] = 'Discipline Type is required for Special Education assignment #' . ($index + 1) . '.';
                        if (empty($assignment['discipline_name'])) $errors[] = 'Discipline Name is required for Special Education assignment #' . ($index + 1) . '.';
                        if (empty($assignment['class_ids']) || !is_array($assignment['class_ids'])) $errors[] = 'Assigned Class(es) is required for Special Education assignment #' . ($index + 1) . '.';
                    }
                    if (!empty($assignment['academic_year_id']) && !empty($assignment['assignment_type'])) $hasValidAssignment = true;
                }
                if (!$hasValidAssignment) $errors[] = 'Teaching staff must have at least one complete academic assignment.';
            } else {
                $errors[] = 'Teaching staff must have at least one academic assignment.';
            }
        }

        if (isset($_POST['emergency_contacts']) && is_array($_POST['emergency_contacts'])) {
            $hasValidEmergency = false;
            foreach ($_POST['emergency_contacts'] as $contact) {
                if (!empty($contact['name']) && !empty($contact['relationship']) && !empty($contact['primary_phone'])) {
                    $hasValidEmergency = true;
                    break;
                }
            }
            if (!$hasValidEmergency) $errors[] = 'At least one complete emergency contact is required.';
        } else {
            $errors[] = 'At least one emergency contact is required.';
        }

        if (!empty($errors)) {
            $db->rollBack();
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/staff/create.php');
            exit;
        }

        // --- Step 2: Create Person Record ---
        $personData = [
            'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'first_name' => trim($_POST['first_name']),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'last_name' => trim($_POST['last_name']),
            'preferred_name' => trim($_POST['preferred_name'] ?? ''),
            'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
            'gender' => !empty($_POST['gender']) ? $_POST['gender'] : null,
            'nationality' => trim($_POST['nationality'] ?? ''),
            'religion' => trim($_POST['religion'] ?? ''),
            'marital_status' => trim($_POST['marital_status'] ?? ''),
            'home_town' => trim($_POST['home_town'] ?? ''),
            'primary_phone' => trim($_POST['primary_phone'] ?? ''),
            'secondary_phone' => trim($_POST['secondary_phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'secondary_email' => trim($_POST['secondary_email'] ?? ''),
            'work_email' => trim($_POST['work_email'] ?? ''),
            'work_phone' => trim($_POST['work_phone'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'town_city' => trim($_POST['town_city'] ?? ''),
            'district' => trim($_POST['district'] ?? ''),
            'gps_address' => trim($_POST['gps_address'] ?? ''),
            'post_address' => trim($_POST['post_address'] ?? ''),
            'region' => trim($_POST['region'] ?? ''),
            'created_at' => date('Y-m-d H:i:s')
        ];
        $f = array_keys($personData);
        $p = array_fill(0, count($f), '?');
        $personId = $db->insert("INSERT INTO persons (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($personData));
        if (!$personId) throw new Exception('Failed to create person record.');

        // --- Handle profile photo upload (if provided) ---
        if (!empty($_FILES['profile_photo']['name'])) {
            $photoUrl = handleProfilePhotoUpload('profile_photo', $tenantId, $personId, $projectRoot);
            if ($photoUrl) {
                $db->execute(
                    "UPDATE persons SET profile_photo_url = ? WHERE id = ? AND tenant_id = ?",
                    [$photoUrl, $personId, $tenantId]
                );
            }
        }

        // --- Step 3: Generate Staff Number (server-side, always fresh) ---
        // The submitted value is ignored by design — a fresh unique number is computed here.
        // Acquire an advisory lock and hold it through the INSERT to prevent race conditions.
        $year = date('Y');
        $prefix = 'EMP-' . $year . '-';
        $lockName = 'staff_num_' . (int)$tenantId . '_' . $year;

        try {
            $db->execute("SELECT GET_LOCK(?, 10)", [$lockName]);

            $seqResult = $db->fetchOne(
                "SELECT MAX(CAST(SUBSTRING(staff_number, 10) AS UNSIGNED)) as max_seq
                 FROM staff
                 WHERE tenant_id = ? AND staff_number LIKE ?",
                [$tenantId, $prefix . '%']
            );
            $nextSeq = ($seqResult['max_seq'] ?? 0) + 1;
            $staffNumber = $prefix . str_pad($nextSeq, 6, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            // If lock fails, fall back to a unique timestamp-based suffix
            $staffNumber = $prefix . str_pad(floor(microtime(true) * 1000) % 1000000, 6, '0', STR_PAD_LEFT);
        }

        // --- Step 4: Staff Record ---
        $staffData = [
            'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'school_id' => $schoolId,
            'campus_id' => !empty($_POST['campus_id']) ? (int)$_POST['campus_id'] : 0,
            'staff_number' => $staffNumber,
            'staff_category_id' => (int)$_POST['staff_category_id'],
            'staff_type_id' => (int)$_POST['staff_type_id'],
            'employment_type_id' => (int)$_POST['employment_type_id'],
            'staff_status_id' => (int)$_POST['staff_status_id'],
            'department_id' => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
            'reporting_manager_id' => !empty($_POST['reporting_manager_id']) ? (int)$_POST['reporting_manager_id'] : null,
            'platform_user_id' => !empty($_POST['platform_user_id']) ? (int)$_POST['platform_user_id'] : null,
            'job_title' => trim($_POST['job_title'] ?? ''),
            'hire_date' => $_POST['hire_date'],
            'contract_start_date' => !empty($_POST['contract_start_date']) ? $_POST['contract_start_date'] : null,
            'contract_end_date' => !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null,
            'probation_start_date' => !empty($_POST['probation_start']) ? $_POST['probation_start'] : null,
            'probation_end_date' => !empty($_POST['probation_end']) ? $_POST['probation_end'] : null,
            'confirmation_date' => !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : null,
            'work_location' => trim($_POST['work_location'] ?? ''),
            'max_teaching_hours' => !empty($_POST['max_teaching_hours']) ? (int)$_POST['max_teaching_hours'] : 0,
            'specialties' => trim($_POST['specialties'] ?? ''),
            'is_teaching_staff' => isset($_POST['is_teaching_staff']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 1,
            'notes' => trim($_POST['notes'] ?? ''),
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $f = array_keys($staffData);
        $p = array_fill(0, count($f), '?');
        $staffId = $db->insert("INSERT INTO staff (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($staffData));

        // Release the advisory lock immediately after the INSERT succeeds
        try {
            $db->execute("SELECT RELEASE_LOCK(?)", [$lockName]);
        } catch (Exception $e) {
        }

        if (!$staffId) throw new Exception('Failed to create staff record.');

        // --- Step 5: Emergency Contacts ---
        if (isset($_POST['emergency_contacts']) && is_array($_POST['emergency_contacts'])) {
            foreach ($_POST['emergency_contacts'] as $index => $contact) {
                if (empty($contact['name']) || empty($contact['relationship']) || empty($contact['primary_phone'])) continue;
                $data = [
                    'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    'staff_id' => $staffId,
                    'contact_name' => trim($contact['name']),
                    'relationship' => trim($contact['relationship']),
                    'primary_phone' => trim($contact['primary_phone']),
                    'secondary_phone' => trim($contact['secondary_phone'] ?? ''),
                    'email' => trim($contact['email'] ?? ''),
                    'address' => trim($contact['address'] ?? ''),
                    'is_primary' => isset($contact['is_primary']) ? 1 : 0,
                    'sort_order' => $index,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $f = array_keys($data);
                $p = array_fill(0, count($f), '?');
                $db->insert("INSERT INTO staff_emergency_contacts (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
            }
        }

        // --- Step 6: Qualifications ---
        if (isset($_POST['qualifications']) && is_array($_POST['qualifications'])) {
            foreach ($_POST['qualifications'] as $qual) {
                if (empty($qual['level_id']) || empty($qual['name']) || empty($qual['institution']) || empty($qual['year'])) continue;
                $levelName = '';
                foreach ($qualificationLevels as $lvl) {
                    if ($lvl['id'] == $qual['level_id']) {
                        $levelName = $lvl['level_name'];
                        break;
                    }
                }
                $data = [
                    'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    'staff_id' => $staffId,
                    'qualification_level' => $levelName,
                    'qualification_name' => trim($qual['name']),
                    'major_field' => trim($qual['major'] ?? ''),
                    'institution_name' => trim($qual['institution']),
                    'country' => trim($qual['country'] ?? ''),
                    'year_of_graduation' => (int)$qual['year'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $f = array_keys($data);
                $p = array_fill(0, count($f), '?');
                $db->insert("INSERT INTO staff_qualifications (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
            }
        }

        // --- Step 7: Identifications ---
        if (isset($_POST['identifications']) && is_array($_POST['identifications'])) {
            foreach ($_POST['identifications'] as $ident) {
                if (empty($ident['type_id']) || empty($ident['number'])) continue;
                $typeName = '';
                foreach ($identificationTypes as $t) {
                    if ($t['id'] == $ident['type_id']) {
                        $typeName = $t['type_name'];
                        break;
                    }
                }
                $data = [
                    'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    'staff_id' => $staffId,
                    'identification_type' => $typeName,
                    'identification_number' => trim($ident['number']),
                    'issuing_authority' => trim($ident['authority'] ?? ''),
                    'expiration_date' => !empty($ident['expiration']) ? $ident['expiration'] : null,
                    'is_primary' => isset($ident['is_primary']) ? 1 : 0,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $f = array_keys($data);
                $p = array_fill(0, count($f), '?');
                $db->insert("INSERT INTO staff_identifications (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
            }
        }

        // --- Step 8: Licenses ---
        if (isset($_POST['licenses']) && is_array($_POST['licenses'])) {
            foreach ($_POST['licenses'] as $license) {
                if (empty($license['type_id']) || empty($license['name']) || empty($license['number']) || empty($license['authority']) || empty($license['issue_date'])) continue;
                $typeName = '';
                foreach ($licenseTypes as $t) {
                    if ($t['id'] == $license['type_id']) {
                        $typeName = $t['type_name'];
                        break;
                    }
                }
                $data = [
                    'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    'staff_id' => $staffId,
                    'license_type' => $typeName,
                    'license_name' => trim($license['name']),
                    'license_number' => trim($license['number']),
                    'issuing_authority' => trim($license['authority']),
                    'issue_date' => $license['issue_date'],
                    'expiration_date' => !empty($license['expiration']) ? $license['expiration'] : null,
                    'endorsements' => trim($license['endorsements'] ?? ''),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $f = array_keys($data);
                $p = array_fill(0, count($f), '?');
                $db->insert("INSERT INTO staff_licenses (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
            }
        }

        // --- Step 9: Academic Assignments ---
        if (isset($_POST['is_teaching_staff']) && $_POST['is_teaching_staff'] == 1) {
            if (isset($_POST['assignments']) && is_array($_POST['assignments'])) {
                foreach ($_POST['assignments'] as $assignment) {
                    if (empty($assignment['academic_year_id']) || empty($assignment['assignment_type'])) continue;
                    $classIds = (isset($assignment['class_ids']) && is_array($assignment['class_ids'])) ? implode(',', array_filter(array_map('intval', $assignment['class_ids']))) : null;
                    $streamIds = (isset($assignment['stream_ids']) && is_array($assignment['stream_ids'])) ? implode(',', array_filter(array_map('intval', $assignment['stream_ids']))) : null;
                    $subjectIds = (isset($assignment['subject_ids']) && is_array($assignment['subject_ids'])) ? implode(',', array_filter(array_map('intval', $assignment['subject_ids']))) : null;

                    $data = [
                        'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                        'staff_id' => $staffId,
                        'assignment_type' => trim($assignment['assignment_type']),
                        'academic_year_id' => (int)$assignment['academic_year_id'],
                        'academic_term_id' => !empty($assignment['academic_term_id']) ? (int)$assignment['academic_term_id'] : null,
                        'class_id' => null,
                        'stream_id' => null,
                        'class_ids' => $classIds,
                        'stream_ids' => $streamIds,
                        'subject_ids' => $subjectIds,
                        'discipline_type' => ($assignment['assignment_type'] == 'special_education') ? trim($assignment['discipline_type'] ?? '') : null,
                        'discipline_name' => ($assignment['assignment_type'] == 'special_education') ? trim($assignment['discipline_name'] ?? '') : null,
                        'is_primary' => isset($assignment['is_primary']) ? 1 : 0,
                        'is_active' => 1,
                        'created_by' => $userId,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $f = array_keys($data);
                    $p = array_fill(0, count($f), '?');
                    $db->insert("INSERT INTO staff_academic_assignments (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
                }
            }
        }

        // --- Step 10: Biometric Data ---
        if (isset($_POST['biometric_data']) && is_array($_POST['biometric_data'])) {
            foreach ($_POST['biometric_data'] as $biometric) {
                if (empty($biometric['biometric_type'])) continue;
                $bioType = trim($biometric['biometric_type']);
                $data = [
                    'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    'staff_id' => $staffId,
                    'biometric_template' => trim($biometric['biometric_template'] ?? ''),
                    'biometric_type' => $bioType,
                    'finger_position' => ($bioType == 'fingerprint') ? trim($biometric['finger_position'] ?? '') : null,
                    'template_format' => trim($biometric['template_format'] ?? 'ISO_19794_2'),
                    'quality_score' => !empty($biometric['quality_score']) ? (int)$biometric['quality_score'] : 0,
                    'is_primary' => isset($biometric['is_primary']) ? 1 : 0,
                    'is_active' => 1,
                    'captured_by' => $userId,
                    'captured_at' => date('Y-m-d H:i:s'),
                    'notes' => trim($biometric['notes'] ?? ''),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $f = array_keys($data);
                $p = array_fill(0, count($f), '?');
                $db->insert("INSERT INTO staff_biometric_data (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
            }
        }

        // --- Step 11: Biographic Data ---
        $hasGenotypeColumn = false;
        try {
            $colCheck = $db->fetchOne("SHOW COLUMNS FROM staff_biographic_data LIKE 'genotype'");
            $hasGenotypeColumn = !empty($colCheck);
        } catch (Exception $e) {
            $hasGenotypeColumn = false;
        }

        $bioGraphicData = [
            'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            'staff_id' => $staffId,
            'blood_type' => !empty($_POST['blood_group']) ? trim($_POST['blood_group']) : null,
            'distinguishing_marks' => trim($_POST['distinguishing_marks'] ?? ''),
            'allergies' => trim($_POST['allergies'] ?? ''),
            'medical_conditions' => trim($_POST['medical_conditions'] ?? ''),
            'emergency_medical_notes' => trim($_POST['emergency_medical_notes'] ?? ''),
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($hasGenotypeColumn) {
            $bioGraphicData['genotype'] = !empty($_POST['genotype']) ? trim($_POST['genotype']) : null;
        }

        $f = array_keys($bioGraphicData);
        $p = array_fill(0, count($f), '?');
        $db->insert("INSERT INTO staff_biographic_data (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($bioGraphicData));

        // --- Step 12: Signature ---
        if (!empty($_POST['signature_data'])) {
            $data = [
                'uuid' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                'staff_id' => $staffId,
                'signature_data' => trim($_POST['signature_data']),
                'signature_type' => 'captured',
                'mime_type' => 'image/png',
                'is_primary' => 1,
                'is_active' => 1,
                'captured_by' => $userId,
                'captured_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ];
            $f = array_keys($data);
            $p = array_fill(0, count($f), '?');
            $db->insert("INSERT INTO staff_signatures (" . implode(', ', $f) . ") VALUES (" . implode(', ', $p) . ")", array_values($data));
        }

        error_log("Staff created successfully: ID={$staffId}, Number={$staffNumber}");
        $db->commit();
        $success = true;

        $_SESSION['success'] = 'Staff member registered successfully. Staff Number: ' . $staffNumber;

        if (isset($_POST['save_and_new']) && $_POST['save_and_new'] == '1') {
            header('Location: /platform/tenant/staff/create.php');
        } else {
            header('Location: /platform/tenant/staff/index.php');
        }
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Staff creation error: ' . $e->getMessage());
        $errors[] = 'An error occurred while creating the staff record: ' . $e->getMessage();
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/staff/create.php');
        exit;
    }
}

if (isset($_SESSION['errors'])) {
    $errors = $_SESSION['errors'];
    unset($_SESSION['errors']);
}
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (empty($formData)) {
    $formData = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden !important;
            width: 100%;
            max-width: 100%;
            background: #f0f2f5;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #1a1a2e;
        }

        .container-fluid {
            padding: 0;
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .row {
            margin: 0;
            width: 100%;
            max-width: 100%;
        }

        [class*="col-"] {
            padding-left: 12px;
            padding-right: 12px;
        }

        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 22px;
            cursor: pointer;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            position: fixed;
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
            padding: 0;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            margin: 0;
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .sidebar .nav {
            padding: 16px 12px;
        }

        .sidebar .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 12px 8px;
            font-weight: 600;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 16px;
            border-radius: 10px;
            margin: 2px 0;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 12px;
            font-size: 15px;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
        }

        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            padding: 20px;
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 24px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 0;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }

        .top-bar .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e9ecef;
            color: #6c757d;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 500;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-success:hover {
            background: #218838;
            color: #fff;
        }

        .btn-outline-primary {
            background: transparent;
            border: 2px solid #4facfe;
            color: #4facfe;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 500;
        }

        .btn-outline-primary:hover {
            background: #4facfe;
            color: #fff;
        }

        .btn-outline-danger {
            background: transparent;
            border: 2px solid #dc3545;
            color: #dc3545;
            border-radius: 8px;
            padding: 4px 12px;
            font-weight: 500;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .tenant-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-banner .tenant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-banner .tenant-info i {
            font-size: 24px;
            color: #4facfe;
        }

        .tenant-banner .tenant-info .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-banner .tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-label .required {
            color: #dc3545;
            margin-left: 2px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 44px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .field-error-message {
            display: none;
            font-size: 12px;
            color: #dc3545;
            margin-top: 4px;
        }

        .field-error-message.show {
            display: block;
        }

        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1) !important;
            background-color: #fff8f8 !important;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 18px 20px 18px 22px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            animation: alertSlideIn 0.35s cubic-bezier(0.21, 1.02, 0.73, 1);
        }

        .alert-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 5px;
        }

        .alert-pro .alert-pro-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-pro .alert-pro-content {
            flex: 1;
            padding-top: 2px;
        }

        .alert-pro .alert-pro-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
            letter-spacing: -0.2px;
        }

        .alert-pro .alert-pro-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.7;
        }

        .alert-pro .alert-pro-close {
            background: transparent;
            border: none;
            color: inherit;
            opacity: 0.5;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .alert-pro .alert-pro-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.06);
        }

        .alert-pro.alert-pro-error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffeaea 100%);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-pro.alert-pro-error::before {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .alert-pro.alert-pro-error .alert-pro-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .alert-pro.alert-pro-error .alert-pro-title {
            color: #991b1b;
        }

        .alert-pro.alert-pro-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-pro.alert-pro-success::before {
            background: linear-gradient(180deg, #22c55e, #16a34a);
        }

        .alert-pro.alert-pro-success .alert-pro-icon {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .alert-pro.alert-pro-success .alert-pro-title {
            color: #166534;
        }

        @keyframes alertSlideIn {
            from {
                opacity: 0;
                transform: translateY(-12px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .alert-pro.fade-out {
            opacity: 0;
            transform: translateY(-12px);
            transition: all 0.3s ease;
        }

        .field-inline-error {
            display: none;
            color: #dc2626;
            font-size: 12px;
            margin-top: 4px;
            font-weight: 500;
        }

        .field-inline-error.show {
            display: block;
        }

        .field-inline-error i {
            margin-right: 4px;
        }

        .validation-summary-pro {
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 24px;
            display: none;
            animation: alertSlideIn 0.3s ease;
        }

        .validation-summary-pro.show {
            display: block;
        }

        .validation-summary-pro .vs-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .validation-summary-pro .vs-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .validation-summary-pro .vs-title {
            font-weight: 700;
            color: #991b1b;
            font-size: 14px;
        }

        .validation-summary-pro ul {
            margin: 0;
            padding-left: 20px;
            color: #7f1d1d;
            font-size: 13px;
            line-height: 1.8;
        }

        .repeatable-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 12px;
            border: 1px solid #e9ecef;
            position: relative;
        }

        .repeatable-section .remove-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 2px 8px;
            font-size: 12px;
        }

        .assignment-fields {
            display: none;
            padding: 12px;
            background: #fff;
            border-radius: 8px;
            margin-top: 8px;
            border: 1px solid #e9ecef;
        }

        .assignment-fields.active {
            display: block;
        }

        .contract-fields {
            display: none;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 8px;
            border: 1px solid #e9ecef;
        }

        .contract-fields.active {
            display: block;
        }

        .context-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            background: #e3f0ff;
            color: #0d6efd;
        }

        .onboarding-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 4px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
            border: 1px solid #e9ecef;
        }

        .onboarding-grid .onboard-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 0;
            font-size: 12px;
        }

        .checkbox-grid-container {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
            border: 1px solid #e9ecef;
        }

        .checkbox-grid-container .form-check {
            padding: 2px 0;
            margin: 0;
        }

        .logout-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 8px;
            padding: 6px 12px;
            cursor: pointer;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .biometric-finger-position {
            display: none;
        }

        .staff-number-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
            }

            .sidebar .nav-link {
                justify-content: center;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: inline;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .onboarding-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-plus me-2"></i>Register Staff</h1>
                        <p>Complete the form below to register a new staff member</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/staff/index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Staff</a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <span class="tenant-name"><?php echo htmlspecialchars($tenantName); ?></span>
                        <?php if ($schoolName): ?><span class="context-badge"><i class="fas fa-school me-1"></i><?php echo htmlspecialchars($schoolName); ?></span><?php endif; ?>
                    </div>
                    <span class="tenant-badge"><i class="fas fa-user-shield me-1"></i>Staff Registration</span>
                </div>

                <div class="validation-summary-pro" id="validationSummary">
                    <div class="vs-header">
                        <div class="vs-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="vs-title">Please correct the following before submitting</div>
                    </div>
                    <ul id="validationErrorList"></ul>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Registration could not be completed</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverErrorBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($successMessage)): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Success!</div>
                            <div style="font-size: 13px;"><?php echo htmlspecialchars($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <form id="staffForm" method="POST" action="/platform/tenant/staff/create.php" enctype="multipart/form-data" novalidate>

                    <!-- PERSONAL INFORMATION -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-user me-2 text-primary"></i>Personal Information</h6>
                            <small class="text-muted">Basic personal details</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" placeholder="Enter first name" value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>" required autofocus>
                                    <div class="field-error-message" id="first_name_error">First name is required</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="middle_name">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Enter middle name" value="<?php echo htmlspecialchars($formData['middle_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Enter last name" value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>" required>
                                    <div class="field-error-message" id="last_name_error">Last name is required</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="preferred_name">Preferred Name</label>
                                    <input type="text" class="form-control" id="preferred_name" name="preferred_name" placeholder="Preferred display name" value="<?php echo htmlspecialchars($formData['preferred_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="date_of_birth">Date of Birth</label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($formData['date_of_birth'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="gender">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select...</option>
                                        <option value="Male" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="nationality">Nationality</label>
                                    <input type="text" class="form-control" id="nationality" name="nationality" placeholder="Enter nationality" value="<?php echo htmlspecialchars($formData['nationality'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="home_town">Home Town</label>
                                    <input type="text" class="form-control" id="home_town" name="home_town" placeholder="Place of origin" value="<?php echo htmlspecialchars($formData['home_town'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="religion">Religion</label>
                                    <input type="text" class="form-control" id="religion" name="religion" placeholder="Religious affiliation" value="<?php echo htmlspecialchars($formData['religion'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="marital_status">Marital Status</label>
                                    <select class="form-select" id="marital_status" name="marital_status">
                                        <option value="">Select...</option>
                                        <option value="Single" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] == 'Single') ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] == 'Married') ? 'selected' : ''; ?>>Married</option>
                                        <option value="Divorced" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] == 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
                                        <option value="Widowed" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] == 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                                        <option value="Separated" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] == 'Separated') ? 'selected' : ''; ?>>Separated</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="profile_photo">Profile Photo</label>
                                    <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                    <div class="form-text">JPG, PNG, GIF or WEBP. Max 20MB.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CONTACT INFORMATION -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-address-book me-2 text-primary"></i>Contact Information</h6>
                            <small class="text-muted">Residential and contact details</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="primary_phone">Primary Phone <span class="required">*</span></label>
                                    <input type="tel" class="form-control" id="primary_phone" name="primary_phone" placeholder="+233 24 123 4567" value="<?php echo htmlspecialchars($formData['primary_phone'] ?? ''); ?>" required>
                                    <div class="field-error-message" id="primary_phone_error">Primary phone is required</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="secondary_phone">Secondary Phone</label>
                                    <input type="tel" class="form-control" id="secondary_phone" name="secondary_phone" placeholder="+233 24 987 6543" value="<?php echo htmlspecialchars($formData['secondary_phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="email">Personal Email <span class="required">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="personal@email.com" value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
                                    <div class="field-error-message" id="email_error">Valid email is required</div>
                                    <div class="field-inline-error" id="email_duplicate_error"><i class="fas fa-exclamation-triangle"></i> This email address is already registered. Please use a different email.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="secondary_email">Secondary Email</label>
                                    <input type="email" class="form-control" id="secondary_email" name="secondary_email" placeholder="secondary@email.com" value="<?php echo htmlspecialchars($formData['secondary_email'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="work_email">Work Email</label>
                                    <input type="email" class="form-control" id="work_email" name="work_email" placeholder="work@school.edu" value="<?php echo htmlspecialchars($formData['work_email'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="work_phone">Work Phone</label>
                                    <input type="tel" class="form-control" id="work_phone" name="work_phone" placeholder="+233 30 123 4567" value="<?php echo htmlspecialchars($formData['work_phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="address">Residential Address</label>
                                    <input type="text" class="form-control" id="address" name="address" placeholder="Street address" value="<?php echo htmlspecialchars($formData['address'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="gps_address">GPS Address</label>
                                    <input type="text" class="form-control" id="gps_address" name="gps_address" placeholder="e.g., GA-123-4567" value="<?php echo htmlspecialchars($formData['gps_address'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="post_address">Postal Address</label>
                                    <input type="text" class="form-control" id="post_address" name="post_address" placeholder="P.O. Box" value="<?php echo htmlspecialchars($formData['post_address'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="town_city">Town/City</label>
                                    <input type="text" class="form-control" id="town_city" name="town_city" placeholder="City" value="<?php echo htmlspecialchars($formData['town_city'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="district">Municipal/District</label>
                                    <input type="text" class="form-control" id="district" name="district" placeholder="District" value="<?php echo htmlspecialchars($formData['district'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="region">Region</label>
                                    <input type="text" class="form-control" id="region" name="region" placeholder="Region" value="<?php echo htmlspecialchars($formData['region'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- EMERGENCY CONTACTS -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-phone-alt me-2 text-primary"></i>Emergency Contacts</h6>
                            <small class="text-muted">At least one complete emergency contact is required</small>
                        </div>
                        <div class="card-body-custom">
                            <div id="emergencyContactsContainer">
                                <?php
                                $emergencyContacts = isset($formData['emergency_contacts']) ? $formData['emergency_contacts'] : [[]];
                                $eIndex = 0;
                                foreach ($emergencyContacts as $index => $contact):
                                ?>
                                    <div class="repeatable-section emergency-contact-section" id="emergencyContact_<?php echo $eIndex; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeEmergencyContact(this)" <?php echo $eIndex == 0 ? 'style="display: none;"' : ''; ?>><i class="fas fa-times"></i></button>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="emg_name_<?php echo $eIndex; ?>">Contact Name <span class="required">*</span></label>
                                                <input type="text" class="form-control emergency-contact-name" id="emg_name_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][name]" placeholder="Full name" value="<?php echo htmlspecialchars($contact['name'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="emg_rel_<?php echo $eIndex; ?>">Relationship <span class="required">*</span></label>
                                                <input type="text" class="form-control emergency-contact-relationship" id="emg_rel_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][relationship]" placeholder="e.g., Spouse, Parent" value="<?php echo htmlspecialchars($contact['relationship'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="emg_phone_<?php echo $eIndex; ?>">Primary Phone <span class="required">*</span></label>
                                                <input type="tel" class="form-control emergency-contact-phone" id="emg_phone_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][primary_phone]" placeholder="+233 24 123 4567" value="<?php echo htmlspecialchars($contact['primary_phone'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="emg_sphone_<?php echo $eIndex; ?>">Secondary Phone</label>
                                                <input type="tel" class="form-control" id="emg_sphone_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][secondary_phone]" placeholder="+233 24 987 6543" value="<?php echo htmlspecialchars($contact['secondary_phone'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" for="emg_email_<?php echo $eIndex; ?>">Email</label>
                                                <input type="email" class="form-control" id="emg_email_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][email]" placeholder="emergency@email.com" value="<?php echo htmlspecialchars($contact['email'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="emg_addr_<?php echo $eIndex; ?>">Address</label>
                                                <input type="text" class="form-control" id="emg_addr_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][address]" placeholder="Address" value="<?php echo htmlspecialchars($contact['address'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-2 mb-2">
                                                <div class="form-check mt-4">
                                                    <input type="checkbox" class="form-check-input" id="emg_primary_<?php echo $eIndex; ?>" name="emergency_contacts[<?php echo $eIndex; ?>][is_primary]" value="1" <?php echo (isset($contact['is_primary']) && $contact['is_primary'] == 1) ? 'checked' : ($eIndex == 0 ? 'checked' : ''); ?>>
                                                    <label class="form-check-label" for="emg_primary_<?php echo $eIndex; ?>">Primary</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="emergency-contact-error text-danger small mt-1" style="display: none;">Complete all required fields for this emergency contact</div>
                                    </div>
                                <?php $eIndex++;
                                endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addEmergencyContact()"><i class="fas fa-plus me-1"></i> Add Emergency Contact</button>
                        </div>
                    </div>

                    <!-- IDENTIFICATION DOCUMENTS -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-id-card me-2 text-primary"></i>Identification Documents</h6>
                            <small class="text-muted">National ID, Passport, etc.</small>
                        </div>
                        <div class="card-body-custom">
                            <div id="identificationContainer">
                                <?php
                                $identifications = isset($formData['identifications']) ? $formData['identifications'] : [[]];
                                $idIndex = 0;
                                foreach ($identifications as $index => $ident):
                                ?>
                                    <div class="repeatable-section" id="identification_<?php echo $idIndex; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeIdentification(this)" <?php echo $idIndex == 0 ? 'style="display: none;"' : ''; ?>><i class="fas fa-times"></i></button>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="id_type_<?php echo $idIndex; ?>">ID Type <span class="required">*</span></label>
                                                <select class="form-select" id="id_type_<?php echo $idIndex; ?>" name="identifications[<?php echo $idIndex; ?>][type_id]" required>
                                                    <option value="">Select...</option>
                                                    <?php foreach ($identificationTypes as $type): ?>
                                                        <option value="<?php echo $type['id']; ?>" <?php echo (isset($ident['type_id']) && $ident['type_id'] == $type['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="id_num_<?php echo $idIndex; ?>">ID Number <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="id_num_<?php echo $idIndex; ?>" name="identifications[<?php echo $idIndex; ?>][number]" placeholder="ID number" value="<?php echo htmlspecialchars($ident['number'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="id_auth_<?php echo $idIndex; ?>">Issuing Authority</label>
                                                <input type="text" class="form-control" id="id_auth_<?php echo $idIndex; ?>" name="identifications[<?php echo $idIndex; ?>][authority]" placeholder="Issuing body" value="<?php echo htmlspecialchars($ident['authority'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="id_exp_<?php echo $idIndex; ?>">Expiration Date</label>
                                                <input type="date" class="form-control" id="id_exp_<?php echo $idIndex; ?>" name="identifications[<?php echo $idIndex; ?>][expiration]" value="<?php echo htmlspecialchars($ident['expiration'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-8 mb-2">
                                                <label class="form-label" for="id_doc_<?php echo $idIndex; ?>">Document Upload</label>
                                                <input type="file" class="form-control" id="id_doc_<?php echo $idIndex; ?>" name="identifications[<?php echo $idIndex; ?>][document]" accept=".pdf,.jpg,.jpeg,.png">
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check mt-4">
                                                    <input type="checkbox" class="form-check-input" id="id_primary_<?php echo $idIndex; ?>" name="identifications[<?php echo $idIndex; ?>][is_primary]" value="1" <?php echo (isset($ident['is_primary']) && $ident['is_primary'] == 1) ? 'checked' : ($idIndex == 0 ? 'checked' : ''); ?>>
                                                    <label class="form-check-label" for="id_primary_<?php echo $idIndex; ?>">Primary ID</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php $idIndex++;
                                endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addIdentification()"><i class="fas fa-plus me-1"></i> Add ID Document</button>
                        </div>
                    </div>

                    <!-- QUALIFICATIONS -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-graduation-cap me-2 text-primary"></i>Qualifications</h6>
                            <small class="text-muted">Educational qualifications and certifications</small>
                        </div>
                        <div class="card-body-custom">
                            <div id="qualificationsContainer">
                                <?php
                                $qualifications = isset($formData['qualifications']) ? $formData['qualifications'] : [[]];
                                $qIndex = 0;
                                foreach ($qualifications as $index => $qual):
                                ?>
                                    <div class="repeatable-section" id="qualification_<?php echo $qIndex; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeQualification(this)" <?php echo $qIndex == 0 ? 'style="display:none;"' : ''; ?>><i class="fas fa-times"></i></button>
                                        <div class="row">
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="qual_level_<?php echo $qIndex; ?>">Qualification Level <span class="required">*</span></label>
                                                <select class="form-select" id="qual_level_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][level_id]" required>
                                                    <option value="">Select...</option>
                                                    <?php foreach ($qualificationLevels as $level): ?>
                                                        <option value="<?php echo $level['id']; ?>" <?php echo (isset($qual['level_id']) && $qual['level_id'] == $level['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($level['level_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="qual_name_<?php echo $qIndex; ?>">Qualification Name <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="qual_name_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][name]" placeholder="e.g., Bachelor of Science" value="<?php echo htmlspecialchars($qual['name'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="qual_major_<?php echo $qIndex; ?>">Major/Specialization</label>
                                                <input type="text" class="form-control" id="qual_major_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][major]" placeholder="e.g., Computer Science" value="<?php echo htmlspecialchars($qual['major'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="qual_year_<?php echo $qIndex; ?>">Year of Graduation <span class="required">*</span></label>
                                                <input type="number" class="form-control" id="qual_year_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][year]" placeholder="YYYY" min="1970" max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($qual['year'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="qual_inst_<?php echo $qIndex; ?>">Institution <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="qual_inst_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][institution]" placeholder="University/College name" value="<?php echo htmlspecialchars($qual['institution'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label" for="qual_country_<?php echo $qIndex; ?>">Country</label>
                                                <input type="text" class="form-control" id="qual_country_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][country]" placeholder="Enter country" value="<?php echo htmlspecialchars($qual['country'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 mb-2">
                                                <label class="form-label" for="qual_cert_<?php echo $qIndex; ?>">Certificate Upload</label>
                                                <input type="file" class="form-control" id="qual_cert_<?php echo $qIndex; ?>" name="qualifications[<?php echo $qIndex; ?>][certificate]" accept=".pdf,.jpg,.jpeg,.png">
                                            </div>
                                        </div>
                                    </div>
                                <?php $qIndex++;
                                endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addQualification()"><i class="fas fa-plus me-1"></i> Add Qualification</button>
                        </div>
                    </div>

                    <!-- LICENSES -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-certificate me-2 text-primary"></i>Licenses & Certifications</h6>
                            <small class="text-muted">Professional licenses and certifications</small>
                        </div>
                        <div class="card-body-custom">
                            <div id="licensesContainer">
                                <?php
                                $licenses = isset($formData['licenses']) ? $formData['licenses'] : [[]];
                                $licenseIndex = 0;
                                foreach ($licenses as $index => $license):
                                ?>
                                    <div class="repeatable-section" id="license_<?php echo $licenseIndex; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeLicense(this)" <?php echo $licenseIndex == 0 ? 'style="display:none;"' : ''; ?>><i class="fas fa-times"></i></button>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="lic_type_<?php echo $licenseIndex; ?>">License Type <span class="required">*</span></label>
                                                <select class="form-select" id="lic_type_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][type_id]" required>
                                                    <option value="">Select...</option>
                                                    <?php foreach ($licenseTypes as $type): ?>
                                                        <option value="<?php echo $type['id']; ?>" <?php echo (isset($license['type_id']) && $license['type_id'] == $type['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="lic_name_<?php echo $licenseIndex; ?>">License Name <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="lic_name_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][name]" placeholder="License name" value="<?php echo htmlspecialchars($license['name'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="lic_num_<?php echo $licenseIndex; ?>">License Number <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="lic_num_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][number]" placeholder="License number" value="<?php echo htmlspecialchars($license['number'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="lic_auth_<?php echo $licenseIndex; ?>">Issuing Authority <span class="required">*</span></label>
                                                <input type="text" class="form-control" id="lic_auth_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][authority]" placeholder="Issuing body" value="<?php echo htmlspecialchars($license['authority'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="lic_issue_<?php echo $licenseIndex; ?>">Issue Date <span class="required">*</span></label>
                                                <input type="date" class="form-control" id="lic_issue_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][issue_date]" value="<?php echo htmlspecialchars($license['issue_date'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="lic_exp_<?php echo $licenseIndex; ?>">Expiration Date</label>
                                                <input type="date" class="form-control" id="lic_exp_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][expiration]" value="<?php echo htmlspecialchars($license['expiration'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" for="lic_endorse_<?php echo $licenseIndex; ?>">Endorsements / Specializations</label>
                                                <input type="text" class="form-control" id="lic_endorse_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][endorsements]" placeholder="e.g., Mathematics, Science" value="<?php echo htmlspecialchars($license['endorsements'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 mb-2">
                                                <label class="form-label" for="lic_doc_<?php echo $licenseIndex; ?>">Document Upload</label>
                                                <input type="file" class="form-control" id="lic_doc_<?php echo $licenseIndex; ?>" name="licenses[<?php echo $licenseIndex; ?>][document]" accept=".pdf,.jpg,.jpeg,.png">
                                            </div>
                                        </div>
                                    </div>
                                <?php $licenseIndex++;
                                endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addLicense()"><i class="fas fa-plus me-1"></i> Add License / Certificate</button>
                        </div>
                    </div>

                    <!-- EMPLOYMENT INFORMATION -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-briefcase me-2 text-primary"></i>Employment Information</h6>
                            <small class="text-muted">Job and employment details</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="staff_number_display">Staff Number <span class="staff-number-badge"><i class="fas fa-magic"></i> Auto-generated</span></label>
                                    <input type="text" class="form-control" id="staff_number_display" placeholder="Auto-generated" value="<?php echo htmlspecialchars($previewStaffNumber); ?>" readonly style="background:#f8f9fa; cursor:not-allowed;">
                                    <input type="hidden" id="staff_number" name="staff_number" value="">
                                    <div class="form-text">This is automatically generated on save and cannot be edited.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="staff_category_id">Staff Category <span class="required">*</span></label>
                                    <select class="form-select" id="staff_category_id" name="staff_category_id" required>
                                        <option value="">Select Staff Category...</option>
                                        <?php foreach ($staffCategories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" <?php echo (isset($formData['staff_category_id']) && $formData['staff_category_id'] == $cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="staff_type_id">Staff Type <span class="required">*</span></label>
                                    <select class="form-select" id="staff_type_id" name="staff_type_id" required>
                                        <option value="">Select category first...</option>
                                        <?php foreach ($staffTypes as $type): ?>
                                            <option value="<?php echo $type['id']; ?>" data-category="<?php echo $type['staff_category_id']; ?>" <?php echo (isset($formData['staff_type_id']) && $formData['staff_type_id'] == $type['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="employment_type_id">Employment Type <span class="required">*</span></label>
                                    <select class="form-select" id="employment_type_id" name="employment_type_id" required>
                                        <option value="">Select...</option>
                                        <?php foreach ($employmentTypes as $type): ?>
                                            <option value="<?php echo $type['id']; ?>" data-contract="<?php echo $type['is_contract']; ?>" <?php echo (isset($formData['employment_type_id']) && $formData['employment_type_id'] == $type['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['employment_type_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="staff_status_id">Staff Status <span class="required">*</span></label>
                                    <select class="form-select" id="staff_status_id" name="staff_status_id" required>
                                        <option value="">Select...</option>
                                        <?php foreach ($staffStatuses as $status): ?>
                                            <option value="<?php echo $status['id']; ?>" <?php echo (isset($formData['staff_status_id']) && $formData['staff_status_id'] == $status['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status['status_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="hire_date">Hire Date <span class="required">*</span></label>
                                    <input type="date" class="form-control" id="hire_date" name="hire_date" value="<?php echo htmlspecialchars($formData['hire_date'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="contract-fields" id="contractFields">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="contract_start_date">Contract Start Date</label>
                                        <input type="date" class="form-control" id="contract_start_date" name="contract_start_date" value="<?php echo htmlspecialchars($formData['contract_start_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="contract_end_date">Contract End Date</label>
                                        <input type="date" class="form-control" id="contract_end_date" name="contract_end_date" value="<?php echo htmlspecialchars($formData['contract_end_date'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="probation_start">Probation Start Date</label>
                                    <input type="date" class="form-control" id="probation_start" name="probation_start" value="<?php echo htmlspecialchars($formData['probation_start'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="probation_end">Probation End Date</label>
                                    <input type="date" class="form-control" id="probation_end" name="probation_end" value="<?php echo htmlspecialchars($formData['probation_end'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="confirmation_date">Confirmation Date</label>
                                    <input type="date" class="form-control" id="confirmation_date" name="confirmation_date" value="<?php echo htmlspecialchars($formData['confirmation_date'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="work_location">Work Location</label>
                                    <input type="text" class="form-control" id="work_location" name="work_location" placeholder="Office/room/location" value="<?php echo htmlspecialchars($formData['work_location'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="department_id">Department</label>
                                    <select class="form-select" id="department_id" name="department_id">
                                        <option value="">Select department...</option>
                                        <?php foreach ($departments as $dep): ?>
                                            <option value="<?php echo $dep['id']; ?>" <?php echo (isset($formData['department_id']) && $formData['department_id'] == $dep['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dep['department_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="job_title">Job Title</label>
                                    <input type="text" class="form-control" id="job_title" name="job_title" placeholder="Official job title" value="<?php echo htmlspecialchars($formData['job_title'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="reporting_manager_id">Reporting Manager</label>
                                    <select class="form-select" id="reporting_manager_id" name="reporting_manager_id">
                                        <option value="">Select manager...</option>
                                        <?php foreach ($managers as $mgr): ?>
                                            <option value="<?php echo $mgr['id']; ?>" <?php echo (isset($formData['reporting_manager_id']) && $formData['reporting_manager_id'] == $mgr['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mgr['first_name'] . ' ' . $mgr['last_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="max_teaching_hours">Max Teaching Hours</label>
                                    <input type="number" class="form-control" id="max_teaching_hours" name="max_teaching_hours" placeholder="e.g., 20" value="<?php echo htmlspecialchars($formData['max_teaching_hours'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="is_teaching_staff" name="is_teaching_staff" value="1" <?php echo (isset($formData['is_teaching_staff']) && $formData['is_teaching_staff'] == 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_teaching_staff">Teaching Staff</label>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" <?php echo (!isset($formData['is_active']) || $formData['is_active'] == 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Active Status</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="platform_user_id">Platform User</label>
                                    <select class="form-select" id="platform_user_id" name="platform_user_id">
                                        <option value="">Select platform user...</option>
                                        <?php foreach ($platformUsers as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo (isset($formData['platform_user_id']) && $formData['platform_user_id'] == $user['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="specialties">Specialties</label>
                                    <input type="text" class="form-control" id="specialties" name="specialties" placeholder="e.g., Mathematics, Science" value="<?php echo htmlspecialchars($formData['specialties'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label" for="notes">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Additional notes"><?php echo htmlspecialchars($formData['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ACADEMIC ASSIGNMENTS -->
                    <div id="academicAssignmentsSection" style="<?php echo (isset($formData['is_teaching_staff']) && $formData['is_teaching_staff'] == 1) ? 'display:block;' : 'display:none;'; ?>">
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Academic Assignments</h6>
                                <small class="text-muted">Teaching assignments and class allocations</small>
                            </div>
                            <div class="card-body-custom">
                                <div id="assignmentsContainer">
                                    <?php
                                    $assignments = isset($formData['assignments']) ? $formData['assignments'] : [[]];
                                    $aIndex = 0;
                                    foreach ($assignments as $index => $assignment):
                                    ?>
                                        <div class="repeatable-section" id="assignment_<?php echo $aIndex; ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeAssignment(this)" <?php echo $aIndex == 0 ? 'style="display:none;"' : ''; ?>><i class="fas fa-times"></i></button>
                                            <div class="row">
                                                <div class="col-md-3 mb-2">
                                                    <label class="form-label" for="asg_year_<?php echo $aIndex; ?>">Academic Year <span class="required">*</span></label>
                                                    <select class="form-select" id="asg_year_<?php echo $aIndex; ?>" name="assignments[<?php echo $aIndex; ?>][academic_year_id]" required>
                                                        <option value="">Select...</option>
                                                        <?php foreach ($academicYears as $year): ?>
                                                            <option value="<?php echo $year['id']; ?>" <?php echo (isset($assignment['academic_year_id']) && $assignment['academic_year_id'] == $year['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($year['year_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 mb-2">
                                                    <label class="form-label" for="asg_term_<?php echo $aIndex; ?>">Academic Term</label>
                                                    <select class="form-select" id="asg_term_<?php echo $aIndex; ?>" name="assignments[<?php echo $aIndex; ?>][academic_term_id]">
                                                        <option value="">Select...</option>
                                                        <?php foreach ($academicTerms as $term): ?>
                                                            <option value="<?php echo $term['id']; ?>" <?php echo (isset($assignment['academic_term_id']) && $assignment['academic_term_id'] == $term['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($term['term_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 mb-2">
                                                    <label class="form-label" for="asg_type_<?php echo $aIndex; ?>">Assignment Type <span class="required">*</span></label>
                                                    <select class="form-select assignment-type" id="asg_type_<?php echo $aIndex; ?>" name="assignments[<?php echo $aIndex; ?>][assignment_type]" required>
                                                        <option value="">Select...</option>
                                                        <?php foreach ($assignmentTypes as $key => $label): ?>
                                                            <option value="<?php echo $key; ?>" <?php echo (isset($assignment['assignment_type']) && $assignment['assignment_type'] == $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 mb-2">
                                                    <div class="form-check mt-4">
                                                        <input type="checkbox" class="form-check-input" id="asg_primary_<?php echo $aIndex; ?>" name="assignments[<?php echo $aIndex; ?>][is_primary]" value="1" <?php echo (isset($assignment['is_primary']) && $assignment['is_primary'] == 1) ? 'checked' : ($aIndex == 0 ? 'checked' : ''); ?>>
                                                        <label class="form-check-label" for="asg_primary_<?php echo $aIndex; ?>">Primary</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="assignment-fields" id="classTeacherFields_<?php echo $aIndex; ?>">
                                                <div class="mb-2">
                                                    <label class="form-label">Class(es) <span class="required">*</span></label>
                                                    <div class="checkbox-grid-container">
                                                        <?php foreach ($classes as $class): ?>
                                                            <div class="form-check">
                                                                <input type="checkbox" class="form-check-input" id="ct_class_<?php echo $aIndex; ?>_<?php echo $class['id']; ?>" name="assignments[<?php echo $aIndex; ?>][class_ids][]" value="<?php echo $class['id']; ?>" <?php echo (isset($assignment['class_ids']) && is_array($assignment['class_ids']) && in_array($class['id'], $assignment['class_ids'])) ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="ct_class_<?php echo $aIndex; ?>_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="assignment-fields" id="subjectTeacherFields_<?php echo $aIndex; ?>">
                                                <div class="row">
                                                    <div class="col-md-6 mb-2">
                                                        <label class="form-label">Subject(s) <span class="required">*</span></label>
                                                        <div class="checkbox-grid-container">
                                                            <?php foreach ($subjects as $subject): ?>
                                                                <div class="form-check">
                                                                    <input type="checkbox" class="form-check-input" id="st_subj_<?php echo $aIndex; ?>_<?php echo $subject['id']; ?>" name="assignments[<?php echo $aIndex; ?>][subject_ids][]" value="<?php echo $subject['id']; ?>" <?php echo (isset($assignment['subject_ids']) && is_array($assignment['subject_ids']) && in_array($subject['id'], $assignment['subject_ids'])) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" for="st_subj_<?php echo $aIndex; ?>_<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label class="form-label">Class(es) <span class="required">*</span></label>
                                                        <div class="checkbox-grid-container">
                                                            <?php foreach ($classes as $class): ?>
                                                                <div class="form-check">
                                                                    <input type="checkbox" class="form-check-input" id="st_class_<?php echo $aIndex; ?>_<?php echo $class['id']; ?>" name="assignments[<?php echo $aIndex; ?>][class_ids][]" value="<?php echo $class['id']; ?>" <?php echo (isset($assignment['class_ids']) && is_array($assignment['class_ids']) && in_array($class['id'], $assignment['class_ids'])) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" for="st_class_<?php echo $aIndex; ?>_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-12 mb-2">
                                                        <label class="form-label">Stream(s)</label>
                                                        <div class="checkbox-grid-container">
                                                            <?php foreach ($streams as $stream): ?>
                                                                <div class="form-check">
                                                                    <input type="checkbox" class="form-check-input" id="st_stream_<?php echo $aIndex; ?>_<?php echo $stream['id']; ?>" name="assignments[<?php echo $aIndex; ?>][stream_ids][]" value="<?php echo $stream['id']; ?>" <?php echo (isset($assignment['stream_ids']) && is_array($assignment['stream_ids']) && in_array($stream['id'], $assignment['stream_ids'])) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" for="st_stream_<?php echo $aIndex; ?>_<?php echo $stream['id']; ?>"><?php echo htmlspecialchars($stream['stream_name']); ?></label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="assignment-fields" id="specialEducationFields_<?php echo $aIndex; ?>">
                                                <div class="row">
                                                    <div class="col-md-3 mb-2">
                                                        <label class="form-label" for="se_type_<?php echo $aIndex; ?>">Discipline Type <span class="required">*</span></label>
                                                        <select class="form-select" id="se_type_<?php echo $aIndex; ?>" name="assignments[<?php echo $aIndex; ?>][discipline_type]" required>
                                                            <option value="">Select...</option>
                                                            <?php foreach ($disciplineTypes as $key => $label): ?>
                                                                <option value="<?php echo $key; ?>" <?php echo (isset($assignment['discipline_type']) && $assignment['discipline_type'] == $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label class="form-label" for="se_name_<?php echo $aIndex; ?>">Discipline Name <span class="required">*</span></label>
                                                        <input type="text" class="form-control" id="se_name_<?php echo $aIndex; ?>" name="assignments[<?php echo $aIndex; ?>][discipline_name]" placeholder="e.g., Reading Intervention" value="<?php echo htmlspecialchars($assignment['discipline_name'] ?? ''); ?>" required>
                                                    </div>
                                                    <div class="col-md-6 mb-2">
                                                        <label class="form-label">Assigned Class(es) <span class="required">*</span></label>
                                                        <div class="checkbox-grid-container">
                                                            <?php foreach ($classes as $class): ?>
                                                                <div class="form-check">
                                                                    <input type="checkbox" class="form-check-input" id="se_class_<?php echo $aIndex; ?>_<?php echo $class['id']; ?>" name="assignments[<?php echo $aIndex; ?>][class_ids][]" value="<?php echo $class['id']; ?>" <?php echo (isset($assignment['class_ids']) && is_array($assignment['class_ids']) && in_array($class['id'], $assignment['class_ids'])) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" for="se_class_<?php echo $aIndex; ?>_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php $aIndex++;
                                    endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addAssignment()"><i class="fas fa-plus me-1"></i> Add Assignment</button>
                            </div>
                        </div>
                    </div>

                    <!-- BIOMETRIC DATA -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-fingerprint me-2 text-primary"></i>Biometric Data</h6>
                            <small class="text-muted">Biometric enrollment information - <strong class="text-success">Optional</strong></small>
                        </div>
                        <div class="card-body-custom">
                            <div id="biometricContainer">
                                <?php
                                $biometricData = isset($formData['biometric_data']) ? $formData['biometric_data'] : [[]];
                                $bIndex = 0;
                                foreach ($biometricData as $index => $biometric):
                                ?>
                                    <div class="repeatable-section" id="biometric_<?php echo $bIndex; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeBiometric(this)" <?php echo $bIndex == 0 ? 'style="display: none;"' : ''; ?>><i class="fas fa-times"></i></button>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="bio_type_<?php echo $bIndex; ?>">Biometric Type</label>
                                                <select class="form-select biometric-type-select" id="bio_type_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][biometric_type]" data-index="<?php echo $bIndex; ?>">
                                                    <?php foreach ($biometricTypes as $key => $label): ?>
                                                        <option value="<?php echo $key; ?>" <?php echo (isset($biometric['biometric_type']) && $biometric['biometric_type'] == $key) ? 'selected' : ($key == 'fingerprint' ? 'selected' : ''); ?>><?php echo htmlspecialchars($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 mb-2 biometric-finger-position" id="fingerPosition_<?php echo $bIndex; ?>" <?php echo (isset($biometric['biometric_type']) && $biometric['biometric_type'] == 'fingerprint') ? 'style="display: block;"' : 'style="display: none;"'; ?>>
                                                <label class="form-label" for="bio_finger_<?php echo $bIndex; ?>">Finger Position</label>
                                                <select class="form-select" id="bio_finger_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][finger_position]">
                                                    <option value="">Select...</option>
                                                    <?php foreach ($fingerPositions as $key => $label): ?>
                                                        <option value="<?php echo $key; ?>" <?php echo (isset($biometric['finger_position']) && $biometric['finger_position'] == $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label" for="bio_template_<?php echo $bIndex; ?>">Biometric Template</label>
                                                <textarea class="form-control" id="bio_template_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][biometric_template]" rows="2" placeholder="Base64 encoded template"><?php echo htmlspecialchars($biometric['biometric_template'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check mt-4">
                                                    <input type="checkbox" class="form-check-input" id="bio_primary_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][is_primary]" value="1" <?php echo (isset($biometric['is_primary']) && $biometric['is_primary'] == 1) ? 'checked' : ($bIndex == 0 ? 'checked' : ''); ?>>
                                                    <label class="form-check-label" for="bio_primary_<?php echo $bIndex; ?>">Primary</label>
                                                </div>
                                                <div class="mt-2">
                                                    <label class="form-label" for="bio_quality_<?php echo $bIndex; ?>">Quality Score</label>
                                                    <input type="number" class="form-control" id="bio_quality_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][quality_score]" placeholder="0-100" min="0" max="100" value="<?php echo htmlspecialchars($biometric['quality_score'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" for="bio_format_<?php echo $bIndex; ?>">Template Format</label>
                                                <input type="text" class="form-control" id="bio_format_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][template_format]" placeholder="e.g., ISO_19794_2" value="<?php echo htmlspecialchars($biometric['template_format'] ?? 'ISO_19794_2'); ?>">
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label" for="bio_notes_<?php echo $bIndex; ?>">Notes</label>
                                                <input type="text" class="form-control" id="bio_notes_<?php echo $bIndex; ?>" name="biometric_data[<?php echo $bIndex; ?>][notes]" placeholder="Additional notes" value="<?php echo htmlspecialchars($biometric['notes'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                <?php $bIndex++;
                                endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addBiometric()"><i class="fas fa-plus me-1"></i> Add Biometric</button>
                        </div>
                    </div>

                    <!-- MEDICAL INFORMATION -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-heartbeat me-2 text-primary"></i>Medical Information</h6>
                            <small class="text-muted">Health details - <strong class="text-success">Optional</strong></small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="blood_group">Blood Group</label>
                                    <select class="form-select" id="blood_group" name="blood_group">
                                        <option value="">Select...</option>
                                        <?php foreach ($bloodGroups as $bg): ?>
                                            <option value="<?php echo $bg; ?>" <?php echo (isset($formData['blood_group']) && $formData['blood_group'] == $bg) ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="genotype">Genotype</label>
                                    <select class="form-select" id="genotype" name="genotype">
                                        <option value="">Select...</option>
                                        <?php foreach ($genotypes as $gt): ?>
                                            <option value="<?php echo $gt; ?>" <?php echo (isset($formData['genotype']) && $formData['genotype'] == $gt) ? 'selected' : ''; ?>><?php echo $gt; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="distinguishing_marks">Distinguishing Marks</label>
                                    <input type="text" class="form-control" id="distinguishing_marks" name="distinguishing_marks" placeholder="Birthmarks, scars, tattoos, etc." value="<?php echo htmlspecialchars($formData['distinguishing_marks'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="allergies">Allergies</label>
                                    <textarea class="form-control" id="allergies" name="allergies" rows="2" placeholder="Known allergies"><?php echo htmlspecialchars($formData['allergies'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="medical_conditions">Medical Conditions</label>
                                    <textarea class="form-control" id="medical_conditions" name="medical_conditions" rows="2" placeholder="Chronic conditions"><?php echo htmlspecialchars($formData['medical_conditions'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label" for="emergency_medical_notes">Emergency Medical Notes</label>
                                    <textarea class="form-control" id="emergency_medical_notes" name="emergency_medical_notes" rows="2" placeholder="For emergency responders"><?php echo htmlspecialchars($formData['emergency_medical_notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DIGITAL SIGNATURE -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-signature me-2 text-primary"></i>Digital Signature</h6>
                            <small class="text-muted">Capture or upload staff signature</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label" for="signature_data">Signature Data</label>
                                    <textarea class="form-control" id="signature_data" name="signature_data" rows="3" placeholder="Base64 encoded signature image"><?php echo htmlspecialchars($formData['signature_data'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ONBOARDING CHECKLIST -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-check-double me-2 text-primary"></i>Onboarding Checklist</h6>
                            <small class="text-muted">Track onboarding progress</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="onboarding-grid">
                                <?php foreach ($onboardingItems as $key => $label): ?>
                                    <div class="onboard-item">
                                        <input type="checkbox" class="form-check-input" id="onb_<?php echo $key; ?>" name="onboarding[<?php echo $key; ?>]" value="1" <?php echo (isset($formData['onboarding'][$key]) && $formData['onboarding'][$key] == 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="onb_<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
                                        <input type="text" class="form-control form-control-sm" style="flex:1; margin-left:8px;" id="onb_notes_<?php echo $key; ?>" name="onboarding_notes[<?php echo $key; ?>]" placeholder="Notes" value="<?php echo htmlspecialchars($formData['onboarding_notes'][$key] ?? ''); ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- FORM ACTIONS -->
                    <div class="card-custom">
                        <div class="card-body-custom">
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-save me-2"></i> Register Staff</button>
                                <button type="submit" name="save_and_new" value="1" class="btn btn-success"><i class="fas fa-plus me-2"></i> Save & Add Another</button>
                                <a href="/platform/tenant/staff/index.php" class="btn btn-outline-secondary"><i class="fas fa-times me-2"></i> Cancel</a>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });

        const emailField = document.getElementById('email');
        const emailDuplicateError = document.getElementById('email_duplicate_error');
        if (emailField) {
            let emailTimeout = null;
            emailField.addEventListener('blur', function() {
                const email = this.value.trim();
                if (!email || !email.match(/^[\w\.\-]+@[\w\.\-]+\.\w+$/)) {
                    if (emailDuplicateError) emailDuplicateError.classList.remove('show');
                    return;
                }
                if (emailTimeout) clearTimeout(emailTimeout);
                emailTimeout = setTimeout(() => {
                    fetch('/platform/tenant/staff/create.php?ajax_check_email=1&email=' + encodeURIComponent(email))
                        .then(r => r.json())
                        .then(data => {
                            if (data.exists) {
                                emailField.classList.add('field-error');
                                if (emailDuplicateError) emailDuplicateError.classList.add('show');
                            } else {
                                if (emailDuplicateError) emailDuplicateError.classList.remove('show');
                                emailField.classList.remove('field-error');
                            }
                        })
                        .catch(() => {});
                }, 250);
            });
            emailField.addEventListener('input', function() {
                if (emailDuplicateError) emailDuplicateError.classList.remove('show');
                this.classList.remove('field-error');
            });
        }

        const staffCategoryEl = document.getElementById('staff_category_id');
        if (staffCategoryEl) {
            staffCategoryEl.addEventListener('change', function() {
                const categoryId = this.value;
                const typeSelect = document.getElementById('staff_type_id');
                typeSelect.value = '';
                typeSelect.querySelectorAll('option').forEach(option => {
                    if (option.value === '') {
                        option.style.display = '';
                        return;
                    }
                    option.style.display = (option.getAttribute('data-category') == categoryId) ? '' : 'none';
                });
            });
        }

        const empTypeEl = document.getElementById('employment_type_id');
        if (empTypeEl) {
            empTypeEl.addEventListener('change', function() {
                const sel = this.options[this.selectedIndex];
                const isContract = sel ? sel.getAttribute('data-contract') : '0';
                document.getElementById('contractFields').classList.toggle('active', isContract == '1');
            });
        }

        const isTeachingEl = document.getElementById('is_teaching_staff');
        const academicSection = document.getElementById('academicAssignmentsSection');

        function syncAcademicSection() {
            if (!isTeachingEl || !academicSection) return;
            const visible = isTeachingEl.checked;
            academicSection.style.display = visible ? 'block' : 'none';
            academicSection.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.classList.contains('assignment-type')) {
                    el.disabled = !visible;
                    return;
                }
                const activeField = el.closest('.assignment-fields');
                if (activeField && !activeField.classList.contains('active')) {
                    el.disabled = true;
                    return;
                }
                el.disabled = !visible;
            });
        }

        if (isTeachingEl) {
            isTeachingEl.addEventListener('change', syncAcademicSection);
        }

        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('assignment-type')) {
                const container = e.target.closest('.repeatable-section');
                if (!container) return;
                const idx = container.id.replace('assignment_', '');
                const type = e.target.value;
                container.querySelectorAll('.assignment-fields').forEach(f => {
                    f.classList.remove('active');
                    f.querySelectorAll('input, select, textarea').forEach(el => el.disabled = true);
                });
                let target = null;
                if (type == 'class_teacher') target = document.getElementById('classTeacherFields_' + idx);
                else if (type == 'subject_teacher') target = document.getElementById('subjectTeacherFields_' + idx);
                else if (type == 'special_education') target = document.getElementById('specialEducationFields_' + idx);
                if (target) {
                    target.classList.add('active');
                    target.querySelectorAll('input, select, textarea').forEach(el => el.disabled = false);
                }
            }
            if (e.target && e.target.classList.contains('biometric-type-select')) {
                const idx = e.target.getAttribute('data-index');
                const div = document.getElementById('fingerPosition_' + idx);
                if (div) div.style.display = (e.target.value == 'fingerprint') ? 'block' : 'none';
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.assignment-type').forEach(function(select) {
                const container = select.closest('.repeatable-section');
                if (!container) return;
                const idx = container.id.replace('assignment_', '');
                const type = select.value;
                container.querySelectorAll('.assignment-fields').forEach(f => {
                    f.classList.remove('active');
                    f.querySelectorAll('input, select, textarea').forEach(el => el.disabled = true);
                });
                let target = null;
                if (type == 'class_teacher') target = document.getElementById('classTeacherFields_' + idx);
                else if (type == 'subject_teacher') target = document.getElementById('subjectTeacherFields_' + idx);
                else if (type == 'special_education') target = document.getElementById('specialEducationFields_' + idx);
                if (target) {
                    target.classList.add('active');
                    target.querySelectorAll('input, select, textarea').forEach(el => el.disabled = false);
                }
            });
            syncAcademicSection();
        });

        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(function() {
                successBox.classList.add('fade-out');
                setTimeout(() => successBox.remove(), 300);
            }, 6000);
        }

        function addEmergencyContact() {
            const c = document.getElementById('emergencyContactsContainer');
            const idx = c.querySelectorAll('.repeatable-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section emergency-contact-section';
            t.id = 'emergencyContact_' + idx;
            t.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeEmergencyContact(this)"><i class="fas fa-times"></i></button>
        <div class="row">
            <div class="col-md-3 mb-2"><label class="form-label" for="emg_name_${idx}">Contact Name <span class="required">*</span></label><input type="text" class="form-control emergency-contact-name" id="emg_name_${idx}" name="emergency_contacts[${idx}][name]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="emg_rel_${idx}">Relationship <span class="required">*</span></label><input type="text" class="form-control emergency-contact-relationship" id="emg_rel_${idx}" name="emergency_contacts[${idx}][relationship]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="emg_phone_${idx}">Primary Phone <span class="required">*</span></label><input type="tel" class="form-control emergency-contact-phone" id="emg_phone_${idx}" name="emergency_contacts[${idx}][primary_phone]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="emg_sphone_${idx}">Secondary Phone</label><input type="tel" class="form-control" id="emg_sphone_${idx}" name="emergency_contacts[${idx}][secondary_phone]"></div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-2"><label class="form-label" for="emg_email_${idx}">Email</label><input type="email" class="form-control" id="emg_email_${idx}" name="emergency_contacts[${idx}][email]"></div>
            <div class="col-md-4 mb-2"><label class="form-label" for="emg_addr_${idx}">Address</label><input type="text" class="form-control" id="emg_addr_${idx}" name="emergency_contacts[${idx}][address]"></div>
            <div class="col-md-2 mb-2"><div class="form-check mt-4"><input type="checkbox" class="form-check-input" id="emg_primary_${idx}" name="emergency_contacts[${idx}][is_primary]" value="1"><label class="form-check-label" for="emg_primary_${idx}">Primary</label></div></div>
        </div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
        }

        function removeEmergencyContact(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('emergencyContactsContainer');
            if (s && c.querySelectorAll('.repeatable-section').length > 1) {
                s.remove();
                const fs = c.querySelector('.repeatable-section');
                if (fs) {
                    const fb = fs.querySelector('.remove-btn');
                    if (fb) fb.style.display = 'none';
                }
            }
        }

        function addAssignment() {
            const c = document.getElementById('assignmentsContainer');
            const idx = c.querySelectorAll('.repeatable-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section';
            t.id = 'assignment_' + idx;
            t.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeAssignment(this)"><i class="fas fa-times"></i></button>
        <div class="row">
            <div class="col-md-3 mb-2"><label class="form-label" for="asg_year_${idx}">Academic Year <span class="required">*</span></label><select class="form-select" id="asg_year_${idx}" name="assignments[${idx}][academic_year_id]" required><option value="">Select...</option><?php foreach ($academicYears as $year): ?><option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="asg_term_${idx}">Academic Term</label><select class="form-select" id="asg_term_${idx}" name="assignments[${idx}][academic_term_id]"><option value="">Select...</option><?php foreach ($academicTerms as $term): ?><option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="asg_type_${idx}">Assignment Type <span class="required">*</span></label><select class="form-select assignment-type" id="asg_type_${idx}" name="assignments[${idx}][assignment_type]" required><option value="">Select...</option><?php foreach ($assignmentTypes as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><div class="form-check mt-4"><input type="checkbox" class="form-check-input" id="asg_primary_${idx}" name="assignments[${idx}][is_primary]" value="1"><label class="form-check-label" for="asg_primary_${idx}">Primary</label></div></div>
        </div>
        <div class="assignment-fields" id="classTeacherFields_${idx}"><div class="mb-2"><label class="form-label">Class(es) <span class="required">*</span></label><div class="checkbox-grid-container"><?php foreach ($classes as $class): ?><div class="form-check"><input type="checkbox" class="form-check-input" id="ct_class_${idx}_<?php echo $class['id']; ?>" name="assignments[${idx}][class_ids][]" value="<?php echo $class['id']; ?>"><label class="form-check-label" for="ct_class_${idx}_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label></div><?php endforeach; ?></div></div></div>
        <div class="assignment-fields" id="subjectTeacherFields_${idx}"><div class="row"><div class="col-md-6 mb-2"><label class="form-label">Subject(s) <span class="required">*</span></label><div class="checkbox-grid-container"><?php foreach ($subjects as $subject): ?><div class="form-check"><input type="checkbox" class="form-check-input" id="st_subj_${idx}_<?php echo $subject['id']; ?>" name="assignments[${idx}][subject_ids][]" value="<?php echo $subject['id']; ?>"><label class="form-check-label" for="st_subj_${idx}_<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></label></div><?php endforeach; ?></div></div><div class="col-md-6 mb-2"><label class="form-label">Class(es) <span class="required">*</span></label><div class="checkbox-grid-container"><?php foreach ($classes as $class): ?><div class="form-check"><input type="checkbox" class="form-check-input" id="st_class_${idx}_<?php echo $class['id']; ?>" name="assignments[${idx}][class_ids][]" value="<?php echo $class['id']; ?>"><label class="form-check-label" for="st_class_${idx}_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label></div><?php endforeach; ?></div></div></div><div class="row"><div class="col-md-12 mb-2"><label class="form-label">Stream(s)</label><div class="checkbox-grid-container"><?php foreach ($streams as $stream): ?><div class="form-check"><input type="checkbox" class="form-check-input" id="st_stream_${idx}_<?php echo $stream['id']; ?>" name="assignments[${idx}][stream_ids][]" value="<?php echo $stream['id']; ?>"><label class="form-check-label" for="st_stream_${idx}_<?php echo $stream['id']; ?>"><?php echo htmlspecialchars($stream['stream_name']); ?></label></div><?php endforeach; ?></div></div></div></div>
        <div class="assignment-fields" id="specialEducationFields_${idx}"><div class="row"><div class="col-md-3 mb-2"><label class="form-label" for="se_type_${idx}">Discipline Type <span class="required">*</span></label><select class="form-select" id="se_type_${idx}" name="assignments[${idx}][discipline_type]" required><option value="">Select...</option><?php foreach ($disciplineTypes as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div><div class="col-md-3 mb-2"><label class="form-label" for="se_name_${idx}">Discipline Name <span class="required">*</span></label><input type="text" class="form-control" id="se_name_${idx}" name="assignments[${idx}][discipline_name]" required></div><div class="col-md-6 mb-2"><label class="form-label">Assigned Class(es) <span class="required">*</span></label><div class="checkbox-grid-container"><?php foreach ($classes as $class): ?><div class="form-check"><input type="checkbox" class="form-check-input" id="se_class_${idx}_<?php echo $class['id']; ?>" name="assignments[${idx}][class_ids][]" value="<?php echo $class['id']; ?>"><label class="form-check-label" for="se_class_${idx}_<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></label></div><?php endforeach; ?></div></div></div></div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
            if (typeof syncAcademicSection === 'function') syncAcademicSection();
        }

        function removeAssignment(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('assignmentsContainer');
            if (s && c.querySelectorAll('.repeatable-section').length > 1) {
                s.remove();
                const fr = c.querySelector('.remove-btn');
                if (fr) fr.style.display = 'none';
            }
        }

        function addIdentification() {
            const c = document.getElementById('identificationContainer');
            const idx = c.querySelectorAll('.repeatable-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section';
            t.id = 'identification_' + idx;
            t.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeIdentification(this)"><i class="fas fa-times"></i></button>
        <div class="row">
            <div class="col-md-3 mb-2"><label class="form-label" for="id_type_${idx}">ID Type <span class="required">*</span></label><select class="form-select" id="id_type_${idx}" name="identifications[${idx}][type_id]" required><option value="">Select...</option><?php foreach ($identificationTypes as $type): ?><option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="id_num_${idx}">ID Number <span class="required">*</span></label><input type="text" class="form-control" id="id_num_${idx}" name="identifications[${idx}][number]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="id_auth_${idx}">Issuing Authority</label><input type="text" class="form-control" id="id_auth_${idx}" name="identifications[${idx}][authority]"></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="id_exp_${idx}">Expiration Date</label><input type="date" class="form-control" id="id_exp_${idx}" name="identifications[${idx}][expiration]"></div>
        </div>
        <div class="row"><div class="col-md-8 mb-2"><label class="form-label" for="id_doc_${idx}">Document Upload</label><input type="file" class="form-control" id="id_doc_${idx}" name="identifications[${idx}][document]" accept=".pdf,.jpg,.jpeg,.png"></div>
        <div class="col-md-4 mb-2"><div class="form-check mt-4"><input type="checkbox" class="form-check-input" id="id_primary_${idx}" name="identifications[${idx}][is_primary]" value="1"><label class="form-check-label" for="id_primary_${idx}">Primary ID</label></div></div></div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
        }

        function removeIdentification(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('identificationContainer');
            if (s && c.querySelectorAll('.repeatable-section').length > 1) {
                s.remove();
                const fr = c.querySelector('.remove-btn');
                if (fr) fr.style.display = 'none';
            }
        }

        function addQualification() {
            const c = document.getElementById('qualificationsContainer');
            const idx = c.querySelectorAll('.repeatable-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section';
            t.id = 'qualification_' + idx;
            t.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeQualification(this)"><i class="fas fa-times"></i></button>
        <div class="row">
            <div class="col-md-4 mb-2"><label class="form-label" for="qual_level_${idx}">Qualification Level <span class="required">*</span></label><select class="form-select" id="qual_level_${idx}" name="qualifications[${idx}][level_id]" required><option value="">Select...</option><?php foreach ($qualificationLevels as $level): ?><option value="<?php echo $level['id']; ?>"><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4 mb-2"><label class="form-label" for="qual_name_${idx}">Qualification Name <span class="required">*</span></label><input type="text" class="form-control" id="qual_name_${idx}" name="qualifications[${idx}][name]" required></div>
            <div class="col-md-4 mb-2"><label class="form-label" for="qual_major_${idx}">Major/Specialization</label><input type="text" class="form-control" id="qual_major_${idx}" name="qualifications[${idx}][major]"></div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-2"><label class="form-label" for="qual_year_${idx}">Year of Graduation <span class="required">*</span></label><input type="number" class="form-control" id="qual_year_${idx}" name="qualifications[${idx}][year]" min="1970" max="<?php echo date('Y'); ?>" required></div>
            <div class="col-md-4 mb-2"><label class="form-label" for="qual_inst_${idx}">Institution <span class="required">*</span></label><input type="text" class="form-control" id="qual_inst_${idx}" name="qualifications[${idx}][institution]" required></div>
            <div class="col-md-4 mb-2"><label class="form-label" for="qual_country_${idx}">Country</label><input type="text" class="form-control" id="qual_country_${idx}" name="qualifications[${idx}][country]"></div>
        </div>
        <div class="row"><div class="col-md-12 mb-2"><label class="form-label" for="qual_cert_${idx}">Certificate Upload</label><input type="file" class="form-control" id="qual_cert_${idx}" name="qualifications[${idx}][certificate]" accept=".pdf,.jpg,.jpeg,.png"></div></div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
        }

        function removeQualification(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('qualificationsContainer');
            if (s && c.querySelectorAll('.repeatable-section').length > 1) {
                s.remove();
                const fr = c.querySelector('.remove-btn');
                if (fr) fr.style.display = 'none';
            }
        }

        function addLicense() {
            const c = document.getElementById('licensesContainer');
            const idx = c.querySelectorAll('.repeatable-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section';
            t.id = 'license_' + idx;
            t.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeLicense(this)"><i class="fas fa-times"></i></button>
        <div class="row">
            <div class="col-md-3 mb-2"><label class="form-label" for="lic_type_${idx}">License Type <span class="required">*</span></label><select class="form-select" id="lic_type_${idx}" name="licenses[${idx}][type_id]" required><option value="">Select...</option><?php foreach ($licenseTypes as $type): ?><option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="lic_name_${idx}">License Name <span class="required">*</span></label><input type="text" class="form-control" id="lic_name_${idx}" name="licenses[${idx}][name]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="lic_num_${idx}">License Number <span class="required">*</span></label><input type="text" class="form-control" id="lic_num_${idx}" name="licenses[${idx}][number]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="lic_auth_${idx}">Issuing Authority <span class="required">*</span></label><input type="text" class="form-control" id="lic_auth_${idx}" name="licenses[${idx}][authority]" required></div>
        </div>
        <div class="row">
            <div class="col-md-3 mb-2"><label class="form-label" for="lic_issue_${idx}">Issue Date <span class="required">*</span></label><input type="date" class="form-control" id="lic_issue_${idx}" name="licenses[${idx}][issue_date]" required></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="lic_exp_${idx}">Expiration Date</label><input type="date" class="form-control" id="lic_exp_${idx}" name="licenses[${idx}][expiration]"></div>
            <div class="col-md-6 mb-2"><label class="form-label" for="lic_endorse_${idx}">Endorsements</label><input type="text" class="form-control" id="lic_endorse_${idx}" name="licenses[${idx}][endorsements]"></div>
        </div>
        <div class="row"><div class="col-md-12 mb-2"><label class="form-label" for="lic_doc_${idx}">Document Upload</label><input type="file" class="form-control" id="lic_doc_${idx}" name="licenses[${idx}][document]" accept=".pdf,.jpg,.jpeg,.png"></div></div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
        }

        function removeLicense(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('licensesContainer');
            if (s && c.querySelectorAll('.repeatable-section').length > 1) {
                s.remove();
                const fr = c.querySelector('.remove-btn');
                if (fr) fr.style.display = 'none';
            }
        }

        function addBiometric() {
            const c = document.getElementById('biometricContainer');
            const idx = c.querySelectorAll('.repeatable-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section';
            t.id = 'biometric_' + idx;
            t.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger remove-btn" onclick="removeBiometric(this)"><i class="fas fa-times"></i></button>
        <div class="row">
            <div class="col-md-3 mb-2"><label class="form-label" for="bio_type_${idx}">Biometric Type</label><select class="form-select biometric-type-select" id="bio_type_${idx}" name="biometric_data[${idx}][biometric_type]" data-index="${idx}"><?php foreach ($biometricTypes as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo $key == 'fingerprint' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2 biometric-finger-position" id="fingerPosition_${idx}" style="display:block;"><label class="form-label" for="bio_finger_${idx}">Finger Position</label><select class="form-select" id="bio_finger_${idx}" name="biometric_data[${idx}][finger_position]"><option value="">Select...</option><?php foreach ($fingerPositions as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 mb-2"><label class="form-label" for="bio_template_${idx}">Biometric Template</label><textarea class="form-control" id="bio_template_${idx}" name="biometric_data[${idx}][biometric_template]" rows="2"></textarea></div>
            <div class="col-md-3 mb-2"><div class="form-check mt-4"><input type="checkbox" class="form-check-input" id="bio_primary_${idx}" name="biometric_data[${idx}][is_primary]" value="1"><label class="form-check-label" for="bio_primary_${idx}">Primary</label></div><div class="mt-2"><label class="form-label" for="bio_quality_${idx}">Quality Score</label><input type="number" class="form-control" id="bio_quality_${idx}" name="biometric_data[${idx}][quality_score]" min="0" max="100"></div></div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-2"><label class="form-label" for="bio_format_${idx}">Template Format</label><input type="text" class="form-control" id="bio_format_${idx}" name="biometric_data[${idx}][template_format]" value="ISO_19794_2"></div>
            <div class="col-md-6 mb-2"><label class="form-label" for="bio_notes_${idx}">Notes</label><input type="text" class="form-control" id="bio_notes_${idx}" name="biometric_data[${idx}][notes]"></div>
        </div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
        }

        function removeBiometric(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('biometricContainer');
            if (s && c.querySelectorAll('.repeatable-section').length > 1) {
                s.remove();
                const fr = c.querySelector('.remove-btn');
                if (fr) fr.style.display = 'none';
            }
        }

        document.getElementById('staffForm').addEventListener('submit', function(e) {
            const errors = [];
            let valid = true;
            let firstErrorField = null;

            document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
            document.querySelectorAll('.field-error-message.show').forEach(el => el.classList.remove('show'));
            document.querySelectorAll('.field-inline-error.show').forEach(el => el.classList.remove('show'));
            document.getElementById('validationSummary').classList.remove('show');
            document.getElementById('validationErrorList').innerHTML = '';

            const isTeachingChecked = document.getElementById('is_teaching_staff') && document.getElementById('is_teaching_staff').checked;

            this.querySelectorAll('[required]').forEach(field => {
                if (field.type === 'hidden' || field.disabled || field.type === 'file') return;
                const insideAssignments = field.closest('#assignmentsContainer') || field.closest('#academicAssignmentsSection');
                if (insideAssignments && !isTeachingChecked) return;
                const hiddenAncestor = field.closest('#academicAssignmentsSection');
                if (hiddenAncestor && hiddenAncestor.style.display === 'none') return;
                const af = field.closest('.assignment-fields');
                if (af && !af.classList.contains('active')) return;

                if (field.type === 'checkbox' && field.name.includes('[]')) {
                    const name = field.name;
                    const checked = document.querySelectorAll(`input[name="${name}"]:checked`);
                    if (checked.length === 0) {
                        const container = field.closest('.checkbox-grid-container');
                        if (container) {
                            const label = container.parentElement.querySelector('.form-label');
                            if (label) {
                                errors.push(label.textContent.replace('*', '').trim() + ' is required.');
                                container.style.borderColor = '#dc3545';
                                container.style.boxShadow = '0 0 0 4px rgba(220,53,69,0.1)';
                            }
                        }
                        valid = false;
                        if (!firstErrorField) firstErrorField = field;
                    }
                    return;
                }

                if (!field.value || (field.tagName === 'SELECT' && field.value === '')) {
                    field.classList.add('field-error');
                    const em = field.closest('.mb-2, .mb-3').querySelector('.field-error-message');
                    if (em) em.classList.add('show');
                    valid = false;
                    if (!errors.includes(field.name.replace(/[\[\]]/g, '') + ' is required.')) {
                        errors.push(field.name.replace(/[\[\]]/g, '') + ' is required.');
                    }
                    if (!firstErrorField) firstErrorField = field;
                }
            });

            const emailEl = document.getElementById('email');
            if (emailEl && emailEl.value && !emailEl.value.match(/^[\w\.\-]+@[\w\.\-]+\.\w+$/)) {
                emailEl.classList.add('field-error');
                const em = document.getElementById('email_error');
                if (em) {
                    em.textContent = 'Please enter a valid email address.';
                    em.classList.add('show');
                }
                valid = false;
                errors.push('Please enter a valid email address.');
                if (!firstErrorField) firstErrorField = emailEl;
            }

            if (emailDuplicateError && emailDuplicateError.classList.contains('show')) {
                valid = false;
                errors.push('This email address is already registered.');
                if (!firstErrorField) firstErrorField = emailEl;
            }

            const phoneEl = document.getElementById('primary_phone');
            if (phoneEl && phoneEl.value && !phoneEl.value.match(/^[\+\d\s\-\(\)]{7,20}$/)) {
                phoneEl.classList.add('field-error');
                const em = document.getElementById('primary_phone_error');
                if (em) {
                    em.textContent = 'Please enter a valid phone number.';
                    em.classList.add('show');
                }
                valid = false;
                errors.push('Please enter a valid primary phone number.');
                if (!firstErrorField) firstErrorField = phoneEl;
            }

            const contactSections = document.querySelectorAll('#emergencyContactsContainer .repeatable-section');
            let hasValidEmergency = false;
            contactSections.forEach(section => {
                const n = section.querySelector('.emergency-contact-name');
                const r = section.querySelector('.emergency-contact-relationship');
                const p = section.querySelector('.emergency-contact-phone');
                const ed = section.querySelector('.emergency-contact-error');
                if (n && r && p && n.value.trim() && r.value.trim() && p.value.trim()) {
                    hasValidEmergency = true;
                    if (ed) ed.style.display = 'none';
                } else if (ed) {
                    ed.style.display = 'block';
                    if (!firstErrorField && n && !n.value.trim()) firstErrorField = n;
                }
            });
            if (!hasValidEmergency) {
                valid = false;
                errors.push('At least one complete emergency contact is required.');
            }

            const isTeaching = document.getElementById('is_teaching_staff');
            if (isTeaching && isTeaching.checked) {
                const sections = document.querySelectorAll('#assignmentsContainer .repeatable-section');
                let hasValidAssignment = false;
                let assignmentErrors = [];
                if (sections.length === 0) {
                    errors.push('Teaching staff must have at least one academic assignment.');
                    valid = false;
                } else {
                    sections.forEach((section, index) => {
                        const year = section.querySelector('select[name*="[academic_year_id]"]');
                        const type = section.querySelector('select.assignment-type');
                        if (year && year.value && type && type.value) {
                            hasValidAssignment = true;
                            if (type.value === 'class_teacher') {
                                const cc = section.querySelectorAll('input[name*="[class_ids][]"]:checked');
                                if (cc.length === 0) assignmentErrors.push('Class(es) is required for Class Teacher assignment #' + (index + 1) + '.');
                            } else if (type.value === 'subject_teacher') {
                                const sc = section.querySelectorAll('input[name*="[subject_ids][]"]:checked');
                                const cc = section.querySelectorAll('input[name*="[class_ids][]"]:checked');
                                if (sc.length === 0) assignmentErrors.push('Subject(s) is required for Subject Teacher assignment #' + (index + 1) + '.');
                                if (cc.length === 0) assignmentErrors.push('Class(es) is required for Subject Teacher assignment #' + (index + 1) + '.');
                            } else if (type.value === 'special_education') {
                                const dt = section.querySelector('select[name*="[discipline_type]"]');
                                const dn = section.querySelector('input[name*="[discipline_name]"]');
                                const cc = section.querySelectorAll('input[name*="[class_ids][]"]:checked');
                                if (!dt || !dt.value) assignmentErrors.push('Discipline Type is required for Special Education assignment #' + (index + 1) + '.');
                                if (!dn || !dn.value.trim()) assignmentErrors.push('Discipline Name is required for Special Education assignment #' + (index + 1) + '.');
                                if (cc.length === 0) assignmentErrors.push('Assigned Class(es) is required for Special Education assignment #' + (index + 1) + '.');
                            }
                        }
                    });
                    if (!hasValidAssignment) {
                        errors.push('Teaching staff must have at least one complete academic assignment.');
                        valid = false;
                    }
                    assignmentErrors.forEach(err => {
                        if (!errors.includes(err)) errors.push(err);
                    });
                }
            }

            if (!valid || errors.length > 0) {
                e.preventDefault();
                const summary = document.getElementById('validationSummary');
                const list = document.getElementById('validationErrorList');
                list.innerHTML = '';
                [...new Set(errors)].forEach(error => {
                    const li = document.createElement('li');
                    li.textContent = error;
                    list.appendChild(li);
                });
                summary.classList.add('show');
                window.scrollTo({
                    top: summary.getBoundingClientRect().top + window.scrollY - 100,
                    behavior: 'smooth'
                });
                if (firstErrorField) {
                    setTimeout(() => {
                        firstErrorField.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        setTimeout(() => firstErrorField.focus({
                            preventScroll: true
                        }), 400);
                    }, 500);
                }
                return false;
            }

            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Registering...';
            }
        });

        document.querySelectorAll('.form-control, .form-select').forEach(function(el) {
            el.addEventListener('input', function() {
                this.classList.remove('field-error');
                const em = this.closest('.mb-2, .mb-3').querySelector('.field-error-message');
                if (em) em.classList.remove('show');
                const c = this.closest('.checkbox-grid-container');
                if (c) {
                    c.style.borderColor = '';
                    c.style.boxShadow = '';
                }
            });
            el.addEventListener('change', function() {
                this.classList.remove('field-error');
                const em = this.closest('.mb-2, .mb-3').querySelector('.field-error-message');
                if (em) em.classList.remove('show');
                const c = this.closest('.checkbox-grid-container');
                if (c) {
                    c.style.borderColor = '';
                    c.style.boxShadow = '';
                }
            });
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }
    </script>
</body>

</html>