<?php

/**
 * Student Edit - Edit an existing student record
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/edit.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students edit file of the students-surface sweep. Four changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - A v1.0 [SWEEP] entry was added above this docblock.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. The partial carries the
 *       top-level items and — when $currentPage is 'students' —
 *       the student sub-menu.
 *     - The CSS rules that style .nav-sub,
 *       .nav-sub .nav-link, .nav-sub .nav-link.active, and
 *       .nav-subgroup-label are added to this file's <style>
 *       block so the partial's sub-menu renders with correct
 *       indentation and active-state styling.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.0). The @package tag remains 'EduTrack'.
 *
 * Behaviour locked in:
 *   - Student number is read-only (never editable, never regenerated)
 *   - Emergency contacts: REPLACE-ALL on save (soft-delete existing, re-insert)
 *   - Documents: existing rows shown with a "Remove" checkbox (only deleted
 *     when checked); new uploads are appended
 *   - Guardian fields fully editable, including guardian_person_id link
 *   - Photo: if a new one is uploaded, old file is deleted from disk
 */

// ============================================
// ERROR REPORTING
// ============================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ============================================
// SESSION & AUTH
// ============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

$tenantId     = $_SESSION['tenant_id'] ?? 0;
$schoolId     = $_SESSION['school_id'] ?? 0;
$campusId     = $_SESSION['campus_id'] ?? 0;
$userId       = $_SESSION['user_id'] ?? 0;
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found. Please select a tenant first.'];
    header('Location: /platform/tenants/select.php');
    exit;
}

$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId <= 0) {
    $_SESSION['errors'] = ['Invalid student ID.'];
    header('Location: /platform/tenant/students/index.php');
    exit;
}

$pageTitle   = 'Edit Student - Student 360 Platform';
$currentPage = 'students';

// ============================================
// DATABASE
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ============================================
// HELPERS
// ============================================
function uuidv4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function handleImageUpload(string $inputName, int $tenantId, string $subdir, string $projectRoot): ?string
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$inputName];

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Image must be smaller than 5MB.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG, GIF or WEBP images are allowed.');
    }
    $ext = $allowed[$mime];

    $uploadDir = $projectRoot . '/public/uploads/tenants/' . $tenantId . '/students/' . $subdir . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Failed to create upload directory.');
    }

    $filename = $subdir . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Failed to save file.');
    }
    return '/uploads/tenants/' . $tenantId . '/students/' . $subdir . '/' . $filename;
}

function handleDocumentUpload(string $inputName, int $tenantId, string $subdir, string $projectRoot): ?array
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$inputName];

    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('Document must be smaller than 10MB.');
    }
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new Exception('Only PDF, JPG or PNG documents are allowed.');
    }
    $ext = $allowed[$mime];

    $uploadDir = $projectRoot . '/public/uploads/tenants/' . $tenantId . '/students/' . $subdir . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Failed to create upload directory.');
    }

    $filename = 'doc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Failed to save document.');
    }
    return [
        'file_path'     => '/uploads/tenants/' . $tenantId . '/students/' . $subdir . '/' . $filename,
        'original_name' => $file['name'] ?? '',
        'mime_type'     => $mime,
        'file_size'     => (int)$file['size'],
    ];
}

// ============================================
// LOAD STUDENT
// ============================================
$student = $db->fetchOne(
    "SELECT * FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
    [$studentId, $tenantId]
);
if (!$student) {
    $_SESSION['errors'] = ['Student record not found.'];
    header('Location: /platform/tenant/students/index.php');
    exit;
}

// Load emergency contacts and documents
$emergencyContacts = $db->fetchAll(
    "SELECT * FROM student_emergency_contacts
     WHERE student_id = ? AND deleted_at IS NULL
     ORDER BY is_primary DESC, sort_order ASC, id ASC",
    [$studentId]
);
if (empty($emergencyContacts)) $emergencyContacts = [[]];

$documents = $db->fetchAll(
    "SELECT * FROM student_documents
     WHERE student_id = ? AND deleted_at IS NULL
     ORDER BY is_primary DESC, id ASC",
    [$studentId]
);

// ============================================
// AJAX: student email duplicate check (excludes self)
// ============================================
if (isset($_GET['ajax_check_email']) && $_GET['ajax_check_email'] === '1') {
    header('Content-Type: application/json');
    $email = trim($_GET['email'] ?? '');
    $exclude = (int)($_GET['exclude_student_id'] ?? 0);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['exists' => false, 'valid' => false]);
        exit;
    }
    $sql = "SELECT id FROM students WHERE tenant_id = ? AND email = ? AND deleted_at IS NULL";
    $params = [$tenantId, $email];
    if ($exclude > 0) {
        $sql .= " AND id != ?";
        $params[] = $exclude;
    }
    $row = $db->fetchOne($sql, $params);
    echo json_encode(['exists' => (bool)$row, 'valid' => true]);
    exit;
}

// ============================================
// AJAX: person search for guardian link
// ============================================
if (isset($_GET['ajax_search_persons']) && $_GET['ajax_search_persons'] === '1') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['results' => []]);
        exit;
    }
    try {
        $like = '%' . $q . '%';
        $rows = $db->fetchAll(
            "SELECT id, first_name, middle_name, last_name, email, primary_phone
             FROM persons
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND (first_name LIKE ? OR last_name LIKE ?
                    OR CONCAT_WS(' ', first_name, last_name) LIKE ?
                    OR email LIKE ? OR primary_phone LIKE ?)
             ORDER BY first_name, last_name LIMIT 10",
            [$tenantId, $like, $like, $like, $like, $like]
        );
        $results = [];
        foreach ($rows as $r) {
            $full = trim(preg_replace(
                '/\s+/',
                ' ',
                ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')
            ));
            $init = strtoupper(mb_substr($r['first_name'] ?? '', 0, 1) . mb_substr($r['last_name'] ?? '', 0, 1));
            $results[] = [
                'id'       => (int)$r['id'],
                'name'     => $full !== '' ? $full : ('Person #' . $r['id']),
                'email'    => $r['email'] ?? '',
                'phone'    => $r['primary_phone'] ?? '',
                'initials' => $init !== '' ? $init : 'PN',
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['results' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// LOOKUPS
// ============================================
$academicYears  = $db->fetchAll("SELECT id, year_name FROM academic_years WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY year_name DESC", [$tenantId]);
$academicTerms  = $db->fetchAll("SELECT id, term_name FROM academic_terms WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order", [$tenantId]);
$classes        = $db->fetchAll("SELECT id, class_name, class_code FROM classes WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY class_name", [$tenantId]);
$streams        = $db->fetchAll("SELECT id, stream_name FROM streams WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY stream_name", [$tenantId]);
$campuses       = $db->fetchAll("SELECT id, campus_name FROM campuses WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY campus_name", [$tenantId]);
$admissionTypes = $db->fetchAll("SELECT id, type_name FROM admission_types WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order", [$tenantId]);

$bloodGroups   = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$genotypes     = ['AA', 'AS', 'SS', 'AC', 'SC', 'CC'];
$relationships = ['Father', 'Mother', 'Guardian', 'Uncle', 'Aunt', 'Grandparent', 'Sibling', 'Other'];

$tenantName = '';
$tenant = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
if ($tenant) $tenantName = $tenant['tenant_name'] ?? ('Tenant #' . $tenantId);

$schoolName = '';
if ($schoolId > 0) {
    $s = $db->fetchOne("SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL", [$schoolId]);
    if ($s) $schoolName = $s['school_name'] ?? '';
}

// Linked guardian person (if any)
$linkedPerson = null;
if (!empty($student['guardian_person_id'])) {
    $linkedPerson = $db->fetchOne(
        "SELECT id, first_name, middle_name, last_name, email, primary_phone
         FROM persons WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$student['guardian_person_id'], $tenantId]
    );
}

// ============================================
// HANDLE POST
// ============================================
$errors   = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;
    $db->beginTransaction();

    try {
        // ---- Step 1: Validate required ----
        $required = [
            'first_name'     => 'First name',
            'last_name'      => 'Last name',
            'gender'         => 'Gender',
            'date_of_birth'  => 'Date of birth',
            'class_id'       => 'Class / Academic Level',
            'admission_date' => 'Admission date',
            'guardian_name'  => 'Guardian name',
            'guardian_phone' => 'Guardian phone',
        ];
        foreach ($required as $k => $label) {
            if (empty($_POST[$k])) $errors[] = $label . ' is required.';
        }

        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!empty($_POST['guardian_email']) && !filter_var($_POST['guardian_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid guardian email.';
        }

        // Duplicate email check (excluding self)
        if (!empty($_POST['email'])) {
            $exists = $db->fetchOne(
                "SELECT id FROM students WHERE tenant_id = ? AND email = ? AND deleted_at IS NULL AND id != ?",
                [$tenantId, trim($_POST['email']), $studentId]
            );
            if ($exists) $errors[] = 'Another student with this email already exists.';
        }

        if (!empty($errors)) {
            $db->rollBack();
            $_SESSION['errors']    = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/tenant/students/edit.php?id=' . $studentId);
            exit;
        }

        // ---- Step 2: Update student row ----
        $studentData = [
            'campus_id'             => !empty($_POST['campus_id']) ? (int)$_POST['campus_id'] : null,
            'class_id'              => !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null,
            'academic_year_id'      => !empty($_POST['academic_year_id']) ? (int)$_POST['academic_year_id'] : null,
            'academic_term_id'      => !empty($_POST['academic_term_id']) ? (int)$_POST['academic_term_id'] : null,
            'stream_id'             => !empty($_POST['stream_id']) ? (int)$_POST['stream_id'] : null,
            'first_name'            => trim($_POST['first_name']),
            'middle_name'           => trim($_POST['middle_name'] ?? ''),
            'last_name'             => trim($_POST['last_name']),
            'preferred_name'        => trim($_POST['preferred_name'] ?? ''),
            'date_of_birth'         => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
            'place_of_birth'        => trim($_POST['place_of_birth'] ?? ''),
            'gender'                => !empty($_POST['gender']) ? $_POST['gender'] : null,
            'nationality'           => trim($_POST['nationality'] ?? ''),
            'religion'              => trim($_POST['religion'] ?? ''),
            'primary_phone'         => trim($_POST['primary_phone'] ?? ''),
            'secondary_phone'       => trim($_POST['secondary_phone'] ?? ''),
            'email'                 => trim($_POST['email'] ?? ''),
            'address'               => trim($_POST['address'] ?? ''),
            'town_city'             => trim($_POST['town_city'] ?? ''),
            'district'              => trim($_POST['district'] ?? ''),
            'region'                => trim($_POST['region'] ?? ''),
            'admission_date'        => !empty($_POST['admission_date']) ? $_POST['admission_date'] : null,
            'admission_type'        => trim($_POST['admission_type'] ?? ''),
            'enrollment_date'       => !empty($_POST['admission_date']) ? $_POST['admission_date'] : null,
            'guardian_person_id'    => !empty($_POST['guardian_person_id']) ? (int)$_POST['guardian_person_id'] : null,
            'guardian_name'         => trim($_POST['guardian_name'] ?? ''),
            'guardian_relationship' => trim($_POST['guardian_relationship'] ?? ''),
            'guardian_phone'        => trim($_POST['guardian_phone'] ?? ''),
            'guardian_alt_phone'    => trim($_POST['guardian_alt_phone'] ?? ''),
            'guardian_email'        => trim($_POST['guardian_email'] ?? ''),
            'guardian_address'      => trim($_POST['guardian_address'] ?? ''),
            'guardian_portal_access' => !empty($_POST['guardian_portal_access']) ? 1 : 0,
            'guardian_sms'          => !empty($_POST['guardian_sms']) ? 1 : 0,
            'guardian_email_notify' => !empty($_POST['guardian_email_notify']) ? 1 : 0,
            'guardian_is_primary'   => !empty($_POST['guardian_is_primary']) ? 1 : 0,
            'blood_group'           => trim($_POST['blood_group'] ?? ''),
            'genotype'              => trim($_POST['genotype'] ?? ''),
            'allergies'             => trim($_POST['allergies'] ?? ''),
            'medical_conditions'    => trim($_POST['medical_conditions'] ?? ''),
            'special_needs'         => trim($_POST['special_needs'] ?? ''),
            'sports'                => !empty($_POST['sports']) ? 1 : 0,
            'canteen'               => !empty($_POST['canteen']) ? 1 : 0,
            'medical_service'       => !empty($_POST['medical_service']) ? 1 : 0,
            'transport'             => !empty($_POST['transport']) ? 1 : 0,
            'updated_at'            => date('Y-m-d H:i:s'),
        ];

        $set  = [];
        $vals = [];
        foreach ($studentData as $k => $v) {
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $studentId;
        $vals[] = $tenantId;
        $db->execute("UPDATE students SET " . implode(', ', $set) . " WHERE id = ? AND tenant_id = ?", $vals);

        // ---- Step 3: Photo replacement ----
        if (!empty($_FILES['profile_photo']['name'])) {
            $newPhoto = handleImageUpload('profile_photo', $tenantId, 'photo_' . $studentId, $projectRoot);
            if ($newPhoto) {
                $oldPhoto = $student['profile_photo_url'] ?? '';
                if ($oldPhoto && strpos($oldPhoto, '/uploads/') === 0) {
                    $oldPath = $projectRoot . '/public' . $oldPhoto;
                    if (is_file($oldPath)) @unlink($oldPath);
                }
                $db->execute(
                    "UPDATE students SET profile_photo_url = ? WHERE id = ? AND tenant_id = ?",
                    [$newPhoto, $studentId, $tenantId]
                );
            }
        }

        // ---- Step 4: Emergency contacts — REPLACE-ALL ----
        $db->execute(
            "UPDATE student_emergency_contacts SET deleted_at = NOW() WHERE student_id = ? AND tenant_id = ?",
            [$studentId, $tenantId]
        );
        if (!empty($_POST['emergency_contacts']) && is_array($_POST['emergency_contacts'])) {
            $idx = 0;
            foreach ($_POST['emergency_contacts'] as $c) {
                if (empty($c['name']) || empty($c['primary_phone'])) {
                    $idx++;
                    continue;
                }
                $row = [
                    'uuid'            => uuidv4(),
                    'student_id'      => $studentId,
                    'tenant_id'       => $tenantId,
                    'contact_name'    => trim($c['name']),
                    'relationship'    => trim($c['relationship'] ?? ''),
                    'primary_phone'   => trim($c['primary_phone']),
                    'secondary_phone' => trim($c['secondary_phone'] ?? ''),
                    'email'           => trim($c['email'] ?? ''),
                    'address'         => trim($c['address'] ?? ''),
                    'is_primary'      => !empty($c['is_primary']) ? 1 : 0,
                    'sort_order'      => $idx,
                    'is_active'       => 1,
                    'created_by'      => $userId ?: null,
                    'created_at'      => date('Y-m-d H:i:s'),
                ];
                $f = array_keys($row);
                $p = array_fill(0, count($f), '?');
                $db->insert(
                    "INSERT INTO student_emergency_contacts (`" . implode('`,`', $f) . "`) VALUES (" . implode(',', $p) . ")",
                    array_values($row)
                );
                $idx++;
            }
        }

        // ---- Step 5: Documents ----
        // 5a. Remove selected existing documents
        if (!empty($_POST['remove_documents']) && is_array($_POST['remove_documents'])) {
            foreach ($_POST['remove_documents'] as $docId) {
                $docId = (int)$docId;
                if ($docId <= 0) continue;
                $docRow = $db->fetchOne(
                    "SELECT file_path FROM student_documents
                     WHERE id = ? AND student_id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$docId, $studentId, $tenantId]
                );
                if ($docRow && !empty($docRow['file_path'])) {
                    $p = $projectRoot . '/public' . $docRow['file_path'];
                    if (is_file($p)) @unlink($p);
                }
                $db->execute(
                    "UPDATE student_documents SET deleted_at = NOW()
                     WHERE id = ? AND student_id = ? AND tenant_id = ?",
                    [$docId, $studentId, $tenantId]
                );
            }
        }

        // 5b. Append new documents
        if (!empty($_FILES['documents']['name']) && is_array($_FILES['documents']['name'])) {
            $count = count($_FILES['documents']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

                $_FILES['__doc_single'] = [
                    'name'     => $_FILES['documents']['name'][$i],
                    'type'     => $_FILES['documents']['type'][$i],
                    'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                    'error'    => $_FILES['documents']['error'][$i],
                    'size'     => $_FILES['documents']['size'][$i],
                ];
                $meta = handleDocumentUpload('__doc_single', $tenantId, 'docs_' . $studentId, $projectRoot);
                unset($_FILES['__doc_single']);
                if (!$meta) continue;

                $docType = trim($_POST['document_types'][$i] ?? '');
                $row = [
                    'uuid'          => uuidv4(),
                    'student_id'    => $studentId,
                    'tenant_id'     => $tenantId,
                    'document_type' => $docType,
                    'title'         => trim($_POST['document_titles'][$i] ?? $meta['original_name']),
                    'file_path'     => $meta['file_path'],
                    'original_name' => $meta['original_name'],
                    'mime_type'     => $meta['mime_type'],
                    'file_size'     => $meta['file_size'],
                    'is_primary'    => 0,
                    'is_active'     => 1,
                    'uploaded_by'   => $userId ?: null,
                    'created_at'    => date('Y-m-d H:i:s'),
                ];
                $f = array_keys($row);
                $p = array_fill(0, count($f), '?');
                $db->insert(
                    "INSERT INTO student_documents (`" . implode('`,`', $f) . "`) VALUES (" . implode(',', $p) . ")",
                    array_values($row)
                );
            }
        }

        $db->commit();
        $_SESSION['success'] = 'Student record updated successfully.';

        if (!empty($_POST['save_and_continue'])) {
            header('Location: /platform/tenant/students/edit.php?id=' . $studentId);
        } else {
            header('Location: /platform/tenant/students/view.php?id=' . $studentId);
        }
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Student update error: ' . $e->getMessage());
        $errors[] = 'An error occurred while updating the student record: ' . $e->getMessage();
        $_SESSION['errors']    = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/edit.php?id=' . $studentId);
        exit;
    }
}

// ============================================
// FLASH / FORM STATE
// ============================================
if (isset($_SESSION['errors'])) {
    $errors = $_SESSION['errors'];
    unset($_SESSION['errors']);
}
$useForm = false;
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    $useForm  = true;
    unset($_SESSION['form_data']);
}
$successMessage = null;
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Value resolver: prefers flash form_data, else the loaded DB row.
function v(string $key, $default = ''): string
{
    global $formData, $useForm, $student;
    if ($useForm && array_key_exists($key, $formData)) {
        return htmlspecialchars((string)($formData[$key] ?? ''));
    }
    if (array_key_exists($key, $student)) {
        return htmlspecialchars((string)($student[$key] ?? ''));
    }
    return htmlspecialchars((string)$default);
}
function chk(string $key, $defaultIfUnset = 0): bool
{
    global $formData, $useForm, $student;
    if ($useForm) {
        if (array_key_exists($key, $formData)) return !empty($formData[$key]);
        // Checkbox missing from flash = user unchecked it
        if (in_array($key, [
            'guardian_is_primary',
            'guardian_portal_access',
            'guardian_sms',
            'guardian_email_notify',
            'sports',
            'canteen',
            'medical_service',
            'transport'
        ], true)) {
            return false;
        }
        return false;
    }
    return ((int)($student[$key] ?? $defaultIfUnset)) === 1;
}

$fullName    = trim(preg_replace(
    '/\s+/',
    ' ',
    ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? '')
));
$displayName = !empty($student['preferred_name']) ? $student['preferred_name'] : $fullName;
if ($displayName === '') $displayName = 'Student #' . $studentId;
$initials    = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1));
if ($initials === '') $initials = 'ST';

// Emergency contacts source for rendering (form data wins)
$renderEC = $useForm && !empty($formData['emergency_contacts']) && is_array($formData['emergency_contacts'])
    ? $formData['emergency_contacts']
    : ($emergencyContacts ?: [[]]);
if (empty($renderEC)) $renderEC = [[]];

// Linked person initials
$linkedInit = 'PN';
if ($linkedPerson) {
    $linkedInit = strtoupper(substr($linkedPerson['first_name'] ?? '', 0, 1) . substr($linkedPerson['last_name'] ?? '', 0, 1));
    if ($linkedInit === '') $linkedInit = 'PN';
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
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.2);
        }

        .sidebar-toggle:hover {
            background: #2a2a4e;
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
        }

        .sidebar .sidebar-header h4 i {
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
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 12px;
            font-size: 15px;
        }

        /* [SWEEP] Sub-menu styles for the partial's student sub-menu. */
        .sidebar .nav-sub {
            padding-left: 24px;
        }

        .sidebar .nav-sub .nav-link {
            font-size: 13px;
            padding: 8px 14px;
            color: rgba(255, 255, 255, 0.55);
        }

        .sidebar .nav-sub .nav-link.active {
            background: rgba(79, 172, 254, 0.18);
            color: #fff;
            box-shadow: none;
        }

        .sidebar .nav-subgroup-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(255, 255, 255, 0.35);
            padding: 8px 14px 2px;
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
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
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
            letter-spacing: -0.5px;
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

        .top-bar .header-actions .btn {
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
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
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
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
            max-width: 1000px;
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
            display: flex;
            align-items: center;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .section-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            margin-right: 10px;
            flex-shrink: 0;
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

        textarea.form-control {
            height: auto;
            min-height: 70px;
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

        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1) !important;
            background-color: #fff8f8 !important;
        }

        .staff-number-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
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

        .validation-summary-pro {
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 24px;
            display: none;
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

        .services-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .service-item {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .service-item:hover {
            border-color: #4facfe;
            background: #f0f7ff;
        }

        .service-item input:checked~span {
            color: #0d6efd;
            font-weight: 600;
        }

        .service-item .form-check-input {
            margin: 0;
            flex-shrink: 0;
        }

        .service-item span {
            font-size: 13px;
        }

        .service-item i {
            color: #6c757d;
            font-size: 16px;
        }

        .guardian-person-card {
            background: #f0f7ff;
            border: 2px solid #4facfe;
            border-radius: 10px;
            padding: 12px 16px;
            display: none;
            margin-top: 8px;
            align-items: center;
            gap: 12px;
        }

        .guardian-person-card.show {
            display: flex;
        }

        .guardian-person-card .gp-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        /* Person autocomplete */
        .person-autocomplete-wrap {
            position: relative;
        }

        .person-autocomplete-wrap .form-control {
            padding-right: 36px;
        }

        .person-autocomplete-wrap .search-spinner {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            color: #4facfe;
            font-size: 13px;
            display: none;
        }

        .person-autocomplete-wrap.loading .search-spinner {
            display: block;
        }

        .person-autocomplete-wrap .clear-search {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #adb5bd;
            cursor: pointer;
            font-size: 13px;
            padding: 2px 4px;
            display: none;
            border-radius: 4px;
        }

        .person-autocomplete-wrap.has-value .clear-search {
            display: block;
        }

        .person-autocomplete-wrap.has-value .search-spinner {
            display: none;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
            max-height: 340px;
            overflow-y: auto;
            z-index: 2000;
            display: none;
            padding: 6px;
        }

        .autocomplete-dropdown.show {
            display: block;
        }

        .autocomplete-dropdown .ac-header {
            font-size: 10px;
            font-weight: 700;
            color: #adb5bd;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 8px 12px 4px;
        }

        .autocomplete-dropdown .ac-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 12px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .autocomplete-dropdown .ac-item:hover,
        .autocomplete-dropdown .ac-item.active {
            background: #f0f7ff;
        }

        .autocomplete-dropdown .ac-item.active {
            background: linear-gradient(135deg, #e3f0ff 0%, #e8f5ff 100%);
            box-shadow: inset 3px 0 0 #4facfe;
        }

        .autocomplete-dropdown .ac-avatar-text {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: #fff;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            border: 2px solid #e3f0ff;
        }

        .autocomplete-dropdown .ac-body {
            flex: 1;
            min-width: 0;
        }

        .autocomplete-dropdown .ac-name {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .autocomplete-dropdown .ac-meta {
            font-size: 11px;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .autocomplete-dropdown .ac-meta i {
            font-size: 10px;
            margin-right: 3px;
        }

        .autocomplete-dropdown .ac-empty {
            padding: 20px 16px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
        }

        .current-photo-preview {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-top: 8px;
        }

        .current-photo-preview img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e3f0ff;
        }

        .current-photo-preview .cp-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
        }

        /* Documents list */
        .existing-doc {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 8px;
        }

        .existing-doc .ed-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #e3f0ff;
            color: #0d6efd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .existing-doc .ed-body {
            flex: 1;
            min-width: 0;
        }

        .existing-doc .ed-title {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .existing-doc .ed-meta {
            font-size: 11px;
            color: #6c757d;
        }

        .existing-doc .form-check {
            margin: 0;
            padding-left: 1.5em;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 24px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }

            .sidebar .nav-label {
                display: none;
            }

            .sidebar .nav-sub {
                display: none;
            }

            .sidebar .sidebar-footer .user-info span {
                display: none;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: center;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }

            .sidebar-toggle {
                display: none;
            }

            .services-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                position: fixed;
                z-index: 1000;
                top: 0;
                left: 0;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .sidebar-header h4 {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
                font-size: 15px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 10px 16px;
                justify-content: flex-start;
            }

            .sidebar .nav-label {
                display: block;
            }

            .sidebar .nav-sub {
                display: block;
            }

            .sidebar .sidebar-footer .user-info span {
                display: inline;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: flex-start;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .tenant-banner {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .services-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .services-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-edit me-2"></i>Edit Student</h1>
                        <p>Update information for <strong><?php echo htmlspecialchars($displayName); ?></strong></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/view.php?id=<?php echo $studentId; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-eye me-2"></i> View Profile
                        </a>
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <span class="tenant-name"><?php echo htmlspecialchars($tenantName); ?></span>
                        <?php if ($schoolName): ?>
                            <span class="tenant-badge"><i class="fas fa-school me-1"></i><?php echo htmlspecialchars($schoolName); ?></span>
                        <?php endif; ?>
                        <span class="tenant-badge"><i class="fas fa-id-badge me-1"></i><?php echo htmlspecialchars($student['student_number']); ?></span>
                    </div>
                    <span class="tenant-badge"><i class="fas fa-user-edit me-1"></i>Edit Mode</span>
                </div>

                <div class="validation-summary-pro" id="validationSummary">
                    <div class="vs-header">
                        <div class="vs-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="vs-title">Please correct the following before saving</div>
                    </div>
                    <ul id="validationErrorList"></ul>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Update could not be completed</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverErrorBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Success!</div>
                            <div style="font-size:13px;"><?php echo htmlspecialchars($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <form id="studentForm" method="POST" action="/platform/tenant/students/edit.php?id=<?php echo $studentId; ?>" enctype="multipart/form-data" novalidate>

                    <!-- ===================================================
                         SECTION 1 — STUDENT INFORMATION
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">1</span>Student Information</h6>
                            <small class="text-muted">Personal and contact details</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="student_number">
                                        Student Number
                                        <span class="staff-number-badge"><i class="fas fa-lock"></i> Locked</span>
                                    </label>
                                    <input type="text" class="form-control" id="student_number"
                                        value="<?php echo htmlspecialchars($student['student_number']); ?>"
                                        readonly style="background:#f8f9fa;cursor:not-allowed;">
                                    <div class="form-text">Student number cannot be edited after creation.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name"
                                        value="<?php echo v('first_name'); ?>" required autofocus>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="middle_name">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name"
                                        value="<?php echo v('middle_name'); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name"
                                        value="<?php echo v('last_name'); ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="preferred_name">Preferred Name</label>
                                    <input type="text" class="form-control" id="preferred_name" name="preferred_name"
                                        value="<?php echo v('preferred_name'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="profile_photo">Profile Photo</label>
                                    <input type="file" class="form-control" id="profile_photo" name="profile_photo"
                                        accept="image/jpeg,image/png,image/gif,image/webp">
                                    <div class="form-text">Leave blank to keep current photo. Max 5MB.</div>
                                    <?php if (!empty($student['profile_photo_url'])): ?>
                                        <div class="current-photo-preview">
                                            <img src="<?php echo htmlspecialchars($student['profile_photo_url']); ?>"
                                                alt="Current photo"
                                                onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                            <div class="cp-avatar" style="display:none;"><?php echo $initials; ?></div>
                                            <div>
                                                <div style="font-size:12px;font-weight:600;">Current Photo</div>
                                                <div style="font-size:11px;color:#6c757d;">Uploading a new one will replace this.</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="gender">Gender <span class="required">*</span></label>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="">Select...</option>
                                        <option value="Male" <?php echo v('gender') === 'Male'   ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo v('gender') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo v('gender') === 'Other'  ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="date_of_birth">Date of Birth <span class="required">*</span></label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                                        value="<?php echo v('date_of_birth'); ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="place_of_birth">Place of Birth</label>
                                    <input type="text" class="form-control" id="place_of_birth" name="place_of_birth"
                                        value="<?php echo v('place_of_birth'); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="nationality">Nationality</label>
                                    <input type="text" class="form-control" id="nationality" name="nationality"
                                        value="<?php echo v('nationality', 'Ghanaian'); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="religion">Religion</label>
                                    <input type="text" class="form-control" id="religion" name="religion"
                                        value="<?php echo v('religion'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="primary_phone">Primary Phone</label>
                                    <input type="tel" class="form-control" id="primary_phone" name="primary_phone"
                                        value="<?php echo v('primary_phone'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="secondary_phone">Secondary Phone</label>
                                    <input type="tel" class="form-control" id="secondary_phone" name="secondary_phone"
                                        value="<?php echo v('secondary_phone'); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="email">Email</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                        value="<?php echo v('email'); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="address">Residential Address</label>
                                    <input type="text" class="form-control" id="address" name="address"
                                        value="<?php echo v('address'); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="town_city">Town / City</label>
                                    <input type="text" class="form-control" id="town_city" name="town_city"
                                        value="<?php echo v('town_city'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="district">District</label>
                                    <input type="text" class="form-control" id="district" name="district"
                                        value="<?php echo v('district'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="region">Region</label>
                                    <input type="text" class="form-control" id="region" name="region"
                                        value="<?php echo v('region'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 2 — ACADEMIC PLACEMENT
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">2</span>Academic Placement</h6>
                            <small class="text-muted">Year, class, stream and admission details</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="academic_year_id">Academic Year</label>
                                    <select class="form-select" id="academic_year_id" name="academic_year_id">
                                        <option value="">Select...</option>
                                        <?php foreach ($academicYears as $y): ?>
                                            <option value="<?php echo (int)$y['id']; ?>" <?php echo v('academic_year_id') == $y['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($y['year_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="academic_term_id">Academic Term</label>
                                    <select class="form-select" id="academic_term_id" name="academic_term_id">
                                        <option value="">Select...</option>
                                        <?php foreach ($academicTerms as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>" <?php echo v('academic_term_id') == $t['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['term_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="campus_id">Campus</label>
                                    <select class="form-select" id="campus_id" name="campus_id">
                                        <option value="">Select campus...</option>
                                        <?php foreach ($campuses as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo v('campus_id') == $c['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['campus_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="class_id">Academic Level / Class <span class="required">*</span></label>
                                    <select class="form-select" id="class_id" name="class_id" required>
                                        <option value="">Select class...</option>
                                        <?php foreach ($classes as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo v('class_id') == $c['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['class_name']); ?>
                                                (<?php echo htmlspecialchars($c['class_code']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="stream_id">Stream</label>
                                    <select class="form-select" id="stream_id" name="stream_id">
                                        <option value="">Select stream...</option>
                                        <?php foreach ($streams as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>" <?php echo v('stream_id') == $s['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['stream_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="admission_date">Admission Date <span class="required">*</span></label>
                                    <input type="date" class="form-control" id="admission_date" name="admission_date"
                                        value="<?php echo v('admission_date', date('Y-m-d')); ?>" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="admission_type">Admission Type</label>
                                    <select class="form-select" id="admission_type" name="admission_type">
                                        <option value="">Select...</option>
                                        <?php foreach ($admissionTypes as $at): ?>
                                            <option value="<?php echo htmlspecialchars($at['type_name']); ?>"
                                                <?php echo v('admission_type') === $at['type_name'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($at['type_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 3 — PARENT / GUARDIAN
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">3</span>Parent / Guardian</h6>
                            <small class="text-muted">Primary guardian and portal preferences</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="guardian_person_search">Link Existing Person (optional)</label>
                                    <div class="person-autocomplete-wrap" id="gpWrap">
                                        <input type="text" class="form-control" id="guardian_person_search"
                                            placeholder="Search persons by name / email / phone..."
                                            value="" autocomplete="off">
                                        <i class="fas fa-spinner fa-spin search-spinner"></i>
                                        <button type="button" class="clear-search" id="gpClearBtn" aria-label="Clear">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                        <div class="autocomplete-dropdown" id="gpDropdown" role="listbox"></div>
                                    </div>
                                    <input type="hidden" id="guardian_person_id" name="guardian_person_id"
                                        value="<?php echo v('guardian_person_id'); ?>">
                                    <div class="guardian-person-card <?php echo $linkedPerson ? 'show' : ''; ?>" id="gpCard">
                                        <div class="gp-avatar" id="gpAvatar"><?php echo htmlspecialchars($linkedInit); ?></div>
                                        <div style="flex:1;min-width:0;">
                                            <div style="font-weight:600;font-size:13px;" id="gpName">
                                                <?php
                                                if ($linkedPerson) {
                                                    $lpFull = trim(preg_replace(
                                                        '/\s+/',
                                                        ' ',
                                                        ($linkedPerson['first_name'] ?? '') . ' ' . ($linkedPerson['middle_name'] ?? '') . ' ' . ($linkedPerson['last_name'] ?? '')
                                                    ));
                                                    echo htmlspecialchars($lpFull !== '' ? $lpFull : 'Person #' . $linkedPerson['id']);
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </div>
                                            <div style="font-size:11px;color:#6c757d;" id="gpMeta">
                                                <?php if ($linkedPerson): ?>
                                                    <?php
                                                    $parts = [];
                                                    if (!empty($linkedPerson['email'])) $parts[] = $linkedPerson['email'];
                                                    if (!empty($linkedPerson['primary_phone'])) $parts[] = $linkedPerson['primary_phone'];
                                                    echo htmlspecialchars(implode(' · ', $parts) ?: '—');
                                                    ?>
                                                    <?php else: ?>—<?php endif; ?>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearGuardianPerson()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Optional. Leave blank to store only the guardian details below.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="guardian_relationship">Relationship <span class="required">*</span></label>
                                    <select class="form-select" id="guardian_relationship" name="guardian_relationship">
                                        <option value="">Select...</option>
                                        <?php foreach ($relationships as $rel): ?>
                                            <option value="<?php echo htmlspecialchars($rel); ?>"
                                                <?php echo v('guardian_relationship') === $rel ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($rel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="guardian_name">Guardian Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="guardian_name" name="guardian_name"
                                        value="<?php echo v('guardian_name'); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="guardian_phone">Guardian Phone <span class="required">*</span></label>
                                    <input type="tel" class="form-control" id="guardian_phone" name="guardian_phone"
                                        value="<?php echo v('guardian_phone'); ?>" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="guardian_alt_phone">Alternate Phone</label>
                                    <input type="tel" class="form-control" id="guardian_alt_phone" name="guardian_alt_phone"
                                        value="<?php echo v('guardian_alt_phone'); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="guardian_email">Guardian Email</label>
                                    <input type="email" class="form-control" id="guardian_email" name="guardian_email"
                                        value="<?php echo v('guardian_email'); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label" for="guardian_address">Guardian Address</label>
                                    <input type="text" class="form-control" id="guardian_address" name="guardian_address"
                                        value="<?php echo v('guardian_address'); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-2">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="guardian_is_primary"
                                            name="guardian_is_primary" value="1"
                                            <?php echo chk('guardian_is_primary', 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="guardian_is_primary">Primary Guardian</label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="guardian_portal_access"
                                            name="guardian_portal_access" value="1"
                                            <?php echo chk('guardian_portal_access') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="guardian_portal_access">Portal Access</label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="guardian_sms"
                                            name="guardian_sms" value="1"
                                            <?php echo chk('guardian_sms') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="guardian_sms">SMS Notifications</label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="guardian_email_notify"
                                            name="guardian_email_notify" value="1"
                                            <?php echo chk('guardian_email_notify') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="guardian_email_notify">Email Notifications</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 4 — EMERGENCY CONTACT
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">4</span>Emergency Contact</h6>
                            <small class="text-muted">Add as many as needed</small>
                        </div>
                        <div class="card-body-custom">
                            <div id="emergencyContactsContainer">
                                <?php
                                $ecIdx = 0;
                                foreach ($renderEC as $ec):
                                    $ecName   = $ec['name'] ?? ($ec['contact_name'] ?? '');
                                    $ecRel    = $ec['relationship'] ?? '';
                                    $ecPhone  = $ec['primary_phone'] ?? '';
                                    $ecPhone2 = $ec['secondary_phone'] ?? '';
                                    $ecEmail  = $ec['email'] ?? '';
                                    $ecAddr   = $ec['address'] ?? '';
                                    $ecPrim   = $ec['is_primary'] ?? ($ecIdx === 0 ? 1 : 0);
                                ?>
                                    <div class="repeatable-section emergency-contact-section" id="ec_<?php echo $ecIdx; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn"
                                            onclick="removeEmergencyContact(this)"
                                            <?php echo $ecIdx === 0 ? 'style="display:none;"' : ''; ?>>
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label">Name <span class="required">*</span></label>
                                                <input type="text" class="form-control ec-name"
                                                    name="emergency_contacts[<?php echo $ecIdx; ?>][name]"
                                                    value="<?php echo htmlspecialchars($ecName); ?>">
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label">Relationship</label>
                                                <input type="text" class="form-control"
                                                    name="emergency_contacts[<?php echo $ecIdx; ?>][relationship]"
                                                    value="<?php echo htmlspecialchars($ecRel); ?>">
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label">Phone <span class="required">*</span></label>
                                                <input type="tel" class="form-control ec-phone"
                                                    name="emergency_contacts[<?php echo $ecIdx; ?>][primary_phone]"
                                                    value="<?php echo htmlspecialchars($ecPhone); ?>">
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <label class="form-label">Alternate Phone</label>
                                                <input type="tel" class="form-control"
                                                    name="emergency_contacts[<?php echo $ecIdx; ?>][secondary_phone]"
                                                    value="<?php echo htmlspecialchars($ecPhone2); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control"
                                                    name="emergency_contacts[<?php echo $ecIdx; ?>][email]"
                                                    value="<?php echo htmlspecialchars($ecEmail); ?>">
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label">Address</label>
                                                <input type="text" class="form-control"
                                                    name="emergency_contacts[<?php echo $ecIdx; ?>][address]"
                                                    value="<?php echo htmlspecialchars($ecAddr); ?>">
                                            </div>
                                            <div class="col-md-2 mb-2">
                                                <div class="form-check mt-4">
                                                    <input type="checkbox" class="form-check-input"
                                                        id="ec_primary_<?php echo $ecIdx; ?>"
                                                        name="emergency_contacts[<?php echo $ecIdx; ?>][is_primary]"
                                                        value="1" <?php echo $ecPrim ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="ec_primary_<?php echo $ecIdx; ?>">Primary</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php $ecIdx++;
                                endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addEmergencyContact()">
                                <i class="fas fa-plus me-1"></i> Add Emergency Contact
                            </button>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 5 — MEDICAL / WELFARE
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">5</span>Medical / Welfare</h6>
                            <small class="text-muted">Health and wellbeing details</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="blood_group">Blood Group</label>
                                    <select class="form-select" id="blood_group" name="blood_group">
                                        <option value="">Select...</option>
                                        <?php foreach ($bloodGroups as $bg): ?>
                                            <option value="<?php echo $bg; ?>" <?php echo v('blood_group') === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="genotype">Genotype</label>
                                    <select class="form-select" id="genotype" name="genotype">
                                        <option value="">Select...</option>
                                        <?php foreach ($genotypes as $g): ?>
                                            <option value="<?php echo $g; ?>" <?php echo v('genotype') === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="allergies">Allergies</label>
                                    <input type="text" class="form-control" id="allergies" name="allergies"
                                        value="<?php echo v('allergies'); ?>"
                                        placeholder="e.g. Peanuts, penicillin (comma-separated)">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="medical_conditions">Medical Conditions</label>
                                    <textarea class="form-control" id="medical_conditions" name="medical_conditions"
                                        rows="3"><?php echo v('medical_conditions'); ?></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="special_needs">Special Needs</label>
                                    <textarea class="form-control" id="special_needs" name="special_needs"
                                        rows="3"><?php echo v('special_needs'); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 6 — SCHOOL SERVICES
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">6</span>School Services</h6>
                            <small class="text-muted">Services the student will use</small>
                        </div>
                        <div class="card-body-custom">
                            <div class="services-grid">
                                <label class="service-item" for="svc_sports">
                                    <input class="form-check-input" type="checkbox" id="svc_sports"
                                        name="sports" value="1" <?php echo chk('sports') ? 'checked' : ''; ?>>
                                    <i class="fas fa-futbol"></i>
                                    <span>Sports</span>
                                </label>
                                <label class="service-item" for="svc_canteen">
                                    <input class="form-check-input" type="checkbox" id="svc_canteen"
                                        name="canteen" value="1" <?php echo chk('canteen') ? 'checked' : ''; ?>>
                                    <i class="fas fa-utensils"></i>
                                    <span>Canteen</span>
                                </label>
                                <label class="service-item" for="svc_medical">
                                    <input class="form-check-input" type="checkbox" id="svc_medical"
                                        name="medical_service" value="1" <?php echo chk('medical_service') ? 'checked' : ''; ?>>
                                    <i class="fas fa-briefcase-medical"></i>
                                    <span>Medical</span>
                                </label>
                                <label class="service-item" for="svc_transport">
                                    <input class="form-check-input" type="checkbox" id="svc_transport"
                                        name="transport" value="1" <?php echo chk('transport') ? 'checked' : ''; ?>>
                                    <i class="fas fa-bus"></i>
                                    <span>Transport</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 7 — DOCUMENTS
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><span class="section-number">7</span>Documents</h6>
                            <small class="text-muted">Existing documents + new uploads (PDF, JPG, PNG — max 10MB each)</small>
                        </div>
                        <div class="card-body-custom">

                            <?php if (!empty($documents)): ?>
                                <div class="mb-4">
                                    <div style="font-weight:600;font-size:13px;margin-bottom:10px;">
                                        <i class="fas fa-folder-open text-primary me-1"></i>
                                        Existing Documents (<?php echo count($documents); ?>)
                                    </div>
                                    <?php foreach ($documents as $doc): ?>
                                        <?php
                                        $mime = (string)($doc['mime_type'] ?? '');
                                        $icon = strpos($mime, 'pdf') !== false ? 'fa-file-pdf' : 'fa-file-image';
                                        $size = (int)($doc['file_size'] ?? 0);
                                        if ($size >= 1048576) $sizeTxt = round($size / 1048576, 2) . ' MB';
                                        elseif ($size >= 1024) $sizeTxt = round($size / 1024, 1) . ' KB';
                                        else $sizeTxt = $size . ' B';
                                        ?>
                                        <div class="existing-doc">
                                            <div class="ed-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                                            <div class="ed-body">
                                                <div class="ed-title">
                                                    <?php echo htmlspecialchars($doc['title'] ?: ($doc['original_name'] ?: 'Document')); ?>
                                                </div>
                                                <div class="ed-meta">
                                                    <?php if (!empty($doc['document_type'])): ?>
                                                        <strong><?php echo htmlspecialchars($doc['document_type']); ?></strong> ·
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($doc['original_name'] ?? ''); ?>
                                                    · <?php echo htmlspecialchars($sizeTxt); ?>
                                                    <?php if (!empty($doc['created_at'])): ?>
                                                        · <?php echo date('M d, Y', strtotime($doc['created_at'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <?php if (!empty($doc['file_path'])): ?>
                                                    <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank"
                                                        class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-download"></i> Open
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                    id="remove_doc_<?php echo (int)$doc['id']; ?>"
                                                    name="remove_documents[]"
                                                    value="<?php echo (int)$doc['id']; ?>">
                                                <label class="form-check-label text-danger" for="remove_doc_<?php echo (int)$doc['id']; ?>">
                                                    Remove
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="documents">Upload New Documents</label>
                                    <input type="file" class="form-control" id="documents" name="documents[]"
                                        accept=".pdf,.jpg,.jpeg,.png" multiple>
                                    <div class="form-text">Select multiple files to append.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="document_types">Document Type(s)</label>
                                    <input type="text" class="form-control" id="document_types" name="document_types[]"
                                        placeholder="e.g. Birth Certificate">
                                    <div class="form-text">Optional — first type applies to first file.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="document_titles">Document Title(s)</label>
                                    <input type="text" class="form-control" id="document_titles" name="document_titles[]"
                                        placeholder="e.g. Kwame Birth Cert">
                                    <div class="form-text">Optional — first title applies to first file.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================================================
                         SECTION 8 — SAVE
                    ==================================================== -->
                    <div class="card-custom">
                        <div class="card-body-custom">
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                                <button type="submit" name="save_and_continue" value="1" class="btn btn-success">
                                    <i class="fas fa-sync me-2"></i> Save &amp; Continue Editing
                                </button>
                                <a href="/platform/tenant/students/view.php?id=<?php echo $studentId; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const STUDENT_ID = <?php echo (int)$studentId; ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) document.getElementById('sidebar').classList.remove('open');
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }

        // ---------------------------------------------------------------
        // Emergency contacts — repeatable
        // ---------------------------------------------------------------
        function addEmergencyContact() {
            const c = document.getElementById('emergencyContactsContainer');
            const idx = c.querySelectorAll('.emergency-contact-section').length;
            const t = document.createElement('div');
            t.className = 'repeatable-section emergency-contact-section';
            t.id = 'ec_' + idx;
            t.innerHTML = `
                <button type="button" class="btn btn-sm btn-outline-danger remove-btn"
                        onclick="removeEmergencyContact(this)"><i class="fas fa-times"></i></button>
                <div class="row">
                    <div class="col-md-3 mb-2"><label class="form-label">Name <span class="required">*</span></label>
                        <input type="text" class="form-control ec-name" name="emergency_contacts[${idx}][name]"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Relationship</label>
                        <input type="text" class="form-control" name="emergency_contacts[${idx}][relationship]"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Phone <span class="required">*</span></label>
                        <input type="tel" class="form-control ec-phone" name="emergency_contacts[${idx}][primary_phone]"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Alternate Phone</label>
                        <input type="tel" class="form-control" name="emergency_contacts[${idx}][secondary_phone]"></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2"><label class="form-label">Email</label>
                        <input type="email" class="form-control" name="emergency_contacts[${idx}][email]"></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Address</label>
                        <input type="text" class="form-control" name="emergency_contacts[${idx}][address]"></div>
                    <div class="col-md-2 mb-2"><div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input" id="ec_primary_${idx}"
                               name="emergency_contacts[${idx}][is_primary]" value="1">
                        <label class="form-check-label" for="ec_primary_${idx}">Primary</label>
                    </div></div>
                </div>`;
            c.appendChild(t);
            const fr = c.querySelector('.remove-btn');
            if (fr) fr.style.display = '';
        }

        function removeEmergencyContact(btn) {
            const s = btn.closest('.repeatable-section');
            const c = document.getElementById('emergencyContactsContainer');
            if (s && c.querySelectorAll('.emergency-contact-section').length > 1) {
                s.remove();
                const first = c.querySelector('.emergency-contact-section');
                if (first) {
                    const fb = first.querySelector('.remove-btn');
                    if (fb) fb.style.display = 'none';
                }
            }
        }

        // ---------------------------------------------------------------
        // Guardian person autocomplete
        // ---------------------------------------------------------------
        (function() {
            const input = document.getElementById('guardian_person_search');
            const wrap = document.getElementById('gpWrap');
            const dropdown = document.getElementById('gpDropdown');
            const clearBtn = document.getElementById('gpClearBtn');
            const hidden = document.getElementById('guardian_person_id');
            const card = document.getElementById('gpCard');
            const cardAv = document.getElementById('gpAvatar');
            const cardNm = document.getElementById('gpName');
            const cardMt = document.getElementById('gpMeta');
            const nameInput = document.getElementById('guardian_name');
            const emailInput = document.getElementById('guardian_email');
            const phoneInput = document.getElementById('guardian_phone');
            if (!input || !dropdown) return;

            let debounceTimer = null;
            let currentRequestId = 0;
            let activeIndex = -1;
            let currentResults = [];

            function esc(s) {
                return String(s === null || s === undefined ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function updateClear() {
                if (input.value.trim() !== '') wrap.classList.add('has-value');
                else wrap.classList.remove('has-value');
            }

            function hide() {
                dropdown.classList.remove('show');
                activeIndex = -1;
            }

            function show() {
                dropdown.classList.add('show');
            }

            function setActive(i) {
                const items = dropdown.querySelectorAll('.ac-item');
                items.forEach(el => el.classList.remove('active'));
                activeIndex = i;
                if (i >= 0 && items[i]) {
                    items[i].classList.add('active');
                    items[i].scrollIntoView({
                        block: 'nearest'
                    });
                }
            }

            function render(results) {
                currentResults = results;
                activeIndex = -1;
                if (!results.length) {
                    dropdown.innerHTML = `<div class="ac-empty"><i class="fas fa-user-slash"></i>No matching persons found</div>`;
                    show();
                    return;
                }
                let html = '<div class="ac-header">Matching Persons</div>';
                results.forEach((r, i) => {
                    const meta = [];
                    if (r.email) meta.push(`<i class="fas fa-envelope"></i>${esc(r.email)}`);
                    if (r.phone) meta.push(`<i class="fas fa-phone"></i>${esc(r.phone)}`);
                    html += `
                        <div class="ac-item" data-index="${i}" data-id="${r.id}"
                             data-name="${esc(r.name)}" data-email="${esc(r.email)}"
                             data-phone="${esc(r.phone)}" data-initials="${esc(r.initials)}">
                            <div class="ac-avatar-text">${esc(r.initials)}</div>
                            <div class="ac-body">
                                <div class="ac-name">${esc(r.name)}</div>
                                <div class="ac-meta">${meta.join(' &nbsp;·&nbsp; ')}</div>
                            </div>
                        </div>`;
                });
                dropdown.innerHTML = html;
                show();
            }

            function fetchResults(q) {
                const id = ++currentRequestId;
                wrap.classList.add('loading');
                fetch('/platform/tenant/students/edit.php?id=' + STUDENT_ID + '&ajax_search_persons=1&q=' + encodeURIComponent(q), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (id !== currentRequestId) return;
                        wrap.classList.remove('loading');
                        render((data && data.results) ? data.results : []);
                    })
                    .catch(() => {
                        if (id !== currentRequestId) return;
                        wrap.classList.remove('loading');
                        hide();
                    });
            }

            function schedule() {
                const q = input.value.trim();
                updateClear();
                if (debounceTimer) clearTimeout(debounceTimer);
                if (q.length < 1) {
                    hide();
                    return;
                }
                debounceTimer = setTimeout(() => fetchResults(q), 250);
            }

            input.addEventListener('input', schedule);
            input.addEventListener('focus', function() {
                if (input.value.trim().length >= 1 && currentResults.length) show();
            });
            input.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.ac-item');
                const open = dropdown.classList.contains('show');
                if (e.key === 'ArrowDown') {
                    if (!open && input.value.trim().length >= 1) {
                        schedule();
                        e.preventDefault();
                        return;
                    }
                    if (!items.length) return;
                    e.preventDefault();
                    setActive((activeIndex + 1) % items.length);
                } else if (e.key === 'ArrowUp') {
                    if (!open || !items.length) return;
                    e.preventDefault();
                    setActive((activeIndex - 1 + items.length) % items.length);
                } else if (e.key === 'Enter') {
                    if (open && activeIndex >= 0 && items[activeIndex]) {
                        e.preventDefault();
                        selectPerson(items[activeIndex]);
                    }
                } else if (e.key === 'Escape') {
                    hide();
                    input.blur();
                }
            });
            dropdown.addEventListener('click', function(e) {
                const item = e.target.closest('.ac-item');
                if (item) selectPerson(item);
            });
            dropdown.addEventListener('mousedown', e => e.preventDefault());
            if (clearBtn) clearBtn.addEventListener('click', function() {
                input.value = '';
                updateClear();
                hide();
                currentResults = [];
                input.focus();
            });
            document.addEventListener('click', e => {
                if (!wrap.contains(e.target)) hide();
            });

            function selectPerson(item) {
                const id = item.getAttribute('data-id');
                const name = item.getAttribute('data-name');
                const email = item.getAttribute('data-email');
                const phone = item.getAttribute('data-phone');
                const initials = item.getAttribute('data-initials');
                hidden.value = id;
                card.classList.add('show');
                cardAv.textContent = initials || 'PN';
                cardNm.textContent = name || '—';
                cardMt.textContent = [email, phone].filter(Boolean).join(' · ') || '—';
                if (nameInput && !nameInput.value) nameInput.value = name || '';
                if (emailInput && !emailInput.value) emailInput.value = email || '';
                if (phoneInput && !phoneInput.value) phoneInput.value = phone || '';
                input.value = '';
                updateClear();
                hide();
            }
            window.clearGuardianPerson = function() {
                hidden.value = '';
                card.classList.remove('show');
            };
        })();

        // ---------------------------------------------------------------
        // Form validation
        // ---------------------------------------------------------------
        document.getElementById('studentForm').addEventListener('submit', function(e) {
            const errors = [];
            let valid = true;
            let firstErrorField = null;

            document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
            document.getElementById('validationSummary').classList.remove('show');
            document.getElementById('validationErrorList').innerHTML = '';

            this.querySelectorAll('[required]').forEach(field => {
                if (field.type === 'hidden' || field.disabled || field.type === 'file') return;
                if (!field.value || (field.tagName === 'SELECT' && field.value === '')) {
                    field.classList.add('field-error');
                    valid = false;
                    if (!firstErrorField) firstErrorField = field;
                    const label = field.closest('.mb-2, .mb-3')?.querySelector('.form-label');
                    const labelText = label ? label.textContent.replace('*', '').trim() : field.name;
                    if (!errors.includes(labelText + ' is required.')) errors.push(labelText + ' is required.');
                }
            });

            const em = document.getElementById('email');
            if (em && em.value && !em.value.match(/^[\w\.\-]+@[\w\.\-]+\.\w+$/)) {
                em.classList.add('field-error');
                valid = false;
                if (!firstErrorField) firstErrorField = em;
                errors.push('Please enter a valid email address.');
            }

            // Emergency contact: if any row has content, both name and phone are required
            const ecs = document.querySelectorAll('#emergencyContactsContainer .emergency-contact-section');
            ecs.forEach(sec => {
                const n = sec.querySelector('.ec-name');
                const p = sec.querySelector('.ec-phone');
                if (!n || !p) return;
                const hasName = n.value.trim() !== '';
                const hasPhone = p.value.trim() !== '';
                if (hasName !== hasPhone) {
                    if (!hasName) n.classList.add('field-error');
                    if (!hasPhone) p.classList.add('field-error');
                    valid = false;
                    if (!firstErrorField) firstErrorField = hasName ? p : n;
                    errors.push('Emergency contact requires both name and phone.');
                }
            });

            if (!valid || errors.length > 0) {
                e.preventDefault();
                const summary = document.getElementById('validationSummary');
                const list = document.getElementById('validationErrorList');
                list.innerHTML = '';
                [...new Set(errors)].forEach(err => {
                    const li = document.createElement('li');
                    li.textContent = err;
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

            const btn = document.getElementById('submitBtn');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
            }
        });

        document.querySelectorAll('.form-control, .form-select').forEach(el => {
            el.addEventListener('input', function() {
                this.classList.remove('field-error');
            });
            el.addEventListener('change', function() {
                this.classList.remove('field-error');
            });
        });
    </script>
</body>

</html>