<?php

/**
 * Student Biometric Registration — per-term verification flag
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/biometric-registration.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students biometric-registration file of the students-surface
 *   sweep. Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_biometric_registration'
 *       to 'students' so the partial marks Students active and
 *       renders the student sub-menu on this page — consistent
 *       with every other students file.
 *     - A v1.0 [SWEEP] entry was added above this docblock.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block. The .nav-sub, .nav-sub .nav-link, and
 *       .nav-sub .nav-link.active rules, and the two responsive
 *       rules for .nav-sub, were already present in this file and
 *       are preserved unchanged.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.1). The @package tag remains 'EduTrack'.
 *
 * Session S17b decisions:
 *   R1A  status-only — the database stores no biometric template, image or hash
 *   R2C  no separate consent field (no sensitive data held)
 *   R3A  registration may be recorded at any time during the term
 *   R4A  any staff with students-module access may mark it
 *   R5A  one row per (tenant, student, academic_year, academic_term)
 *   R6A  the flag is stored; the notifications module will read it later
 *   R7A  lives in the student module
 *
 * What this page does
 *   Lists students per year/level/offering, shows whether each has a verified
 *   registration for the selected term, and lets a user mark a registration
 *   as verified (or exempted) with a timestamp and method. The physical
 *   biometric capture happens on whatever device the school uses; the app
 *   records only that the capture occurred.
 *
 * Session S20 decisions:
 *   SH2A  per-session CSRF token; all POSTs carry it
 *   SH3A  central Security.php helper, loaded via app/bootstrap.php
 *   SH4A  hardened session started inside bootstrap
 *   SH5C  h() on every echoed value
 *   SH6B  tenant_id scoping and deleted_at IS NULL on every query
 *   SH10A generic error messages; real error logged
 */

// ============================================
// S20 — Bootstrap (session, CSRF, headers, HTTPS, DB)
// ============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';

require_tenant();

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$pageTitle   = 'Biometric Registration - Student 360 Platform';
$currentPage = 'students';

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

// h() is defined in Security.php; do not redeclare it here.

function writeAudit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->insert(
            "INSERT INTO audit_logs (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                $action,
                $resourceType,
                $resourceId,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    } catch (Exception $e) {
        error_log('audit_logs write failed: ' . $e->getMessage());
    }
}

function loadActiveSettings($db, int $tenantId): ?array
{
    $row = $db->fetchOne(
        "SELECT * FROM academic_settings
         WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
         ORDER BY version DESC, id DESC LIMIT 1",
        [$tenantId]
    );
    return $row ?: null;
}

function currentYear($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_years
         WHERE tenant_id = ? AND is_current = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

function currentTerm($db, int $tenantId): ?array
{
    return $db->fetchOne(
        "SELECT * FROM academic_terms
         WHERE tenant_id = ? AND is_current = 1 AND is_active = 1 AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId]
    ) ?: null;
}

// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();          // S20 SH2A — all POSTs must carry the session CSRF token
    $formData = $_POST;

    try {
        // ---------------- MARK VERIFIED ----------------
        if ($action === 'mark') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $yearId    = (int)($_POST['academic_year_id'] ?? 0);
            $termId    = (int)($_POST['academic_term_id'] ?? 0);
            $method    = trim((string)($_POST['method'] ?? 'manual'));
            $notes     = trim((string)($_POST['notes'] ?? ''));

            if (!in_array($method, ['fingerprint', 'face', 'id_card', 'manual'], true)) {
                throw new Exception('Invalid registration method.');
            }
            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($yearId <= 0)    throw new Exception('Academic year is required.');
            if ($termId <= 0)    throw new Exception('Academic term is required.');

            $student = $db->fetchOne(
                "SELECT id, first_name, last_name, student_number FROM students
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$student) throw new Exception('Student not found.');

            $year = $db->fetchOne(
                "SELECT id FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$yearId, $tenantId]
            );
            if (!$year) throw new Exception('Academic year not found.');

            $term = $db->fetchOne(
                "SELECT id FROM academic_terms WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$termId, $tenantId]
            );
            if (!$term) throw new Exception('Academic term not found.');

            $db->beginTransaction();

            $existing = $db->fetchOne(
                "SELECT id, status FROM student_biometric_registrations
                 WHERE tenant_id = ? AND student_id = ?
                   AND academic_year_id = ? AND academic_term_id = ?
                   AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $studentId, $yearId, $termId]
            );

            if ($existing) {
                $db->execute(
                    "UPDATE student_biometric_registrations
                        SET status = 'verified',
                            verified_at = NOW(),
                            verified_by = ?,
                            method = ?,
                            notes = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [
                        $userId ?: null,
                        $method,
                        $notes !== '' ? $notes : null,
                        (int)$existing['id'],
                        $tenantId,
                    ]
                );
                $recId  = (int)$existing['id'];
                $wasNew = false;
            } else {
                $recId = (int)$db->insert(
                    "INSERT INTO student_biometric_registrations
                        (uuid, tenant_id, student_id, academic_year_id, academic_term_id,
                         status, verified_at, verified_by, method, notes,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'verified', NOW(), ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $studentId,
                        $yearId,
                        $termId,
                        $userId ?: null,
                        $method,
                        $notes !== '' ? $notes : null,
                        $userId ?: null,
                    ]
                );
                $wasNew = true;
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_registration.verified',
                'student_biometric_registrations',
                $recId,
                [
                    'student_id' => $studentId,
                    'academic_year_id' => $yearId,
                    'academic_term_id' => $termId,
                    'method' => $method,
                    'was_new_row' => $wasNew ? 1 : 0,
                ]
            );

            $db->commit();
            $name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
            $_SESSION['success'] = 'Registration marked verified for '
                . ($name !== '' ? $name : 'student #' . $studentId) . '.';
            header('Location: /platform/tenant/students/biometric-registration.php'
                . '?year_id=' . $yearId . '&term_id=' . $termId);
            exit;
        }

        // ---------------- MARK EXEMPTED ----------------
        if ($action === 'exempt') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $yearId    = (int)($_POST['academic_year_id'] ?? 0);
            $termId    = (int)($_POST['academic_term_id'] ?? 0);
            $notes     = trim((string)($_POST['notes'] ?? ''));

            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($yearId <= 0)    throw new Exception('Academic year is required.');
            if ($termId <= 0)    throw new Exception('Academic term is required.');
            if ($notes === '')   throw new Exception('A reason is required for exemptions.');

            $student = $db->fetchOne(
                "SELECT id FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$student) throw new Exception('Student not found.');

            $db->beginTransaction();
            $existing = $db->fetchOne(
                "SELECT id FROM student_biometric_registrations
                 WHERE tenant_id = ? AND student_id = ?
                   AND academic_year_id = ? AND academic_term_id = ?
                   AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $studentId, $yearId, $termId]
            );

            if ($existing) {
                $db->execute(
                    "UPDATE student_biometric_registrations
                        SET status = 'exempted',
                            verified_at = NOW(),
                            verified_by = ?,
                            method = 'manual',
                            notes = ?,
                            updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [$userId ?: null, $notes, (int)$existing['id'], $tenantId]
                );
                $recId = (int)$existing['id'];
            } else {
                $recId = (int)$db->insert(
                    "INSERT INTO student_biometric_registrations
                        (uuid, tenant_id, student_id, academic_year_id, academic_term_id,
                         status, verified_at, verified_by, method, notes,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'exempted', NOW(), ?, 'manual', ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $studentId,
                        $yearId,
                        $termId,
                        $userId ?: null,
                        $notes,
                        $userId ?: null,
                    ]
                );
            }

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_registration.exempted',
                'student_biometric_registrations',
                $recId,
                [
                    'student_id' => $studentId,
                    'academic_year_id' => $yearId,
                    'academic_term_id' => $termId,
                    'reason' => $notes,
                ]
            );

            $db->commit();
            $_SESSION['success'] = 'Student marked exempted from biometric registration for this term.';
            header('Location: /platform/tenant/students/biometric-registration.php'
                . '?year_id=' . $yearId . '&term_id=' . $termId);
            exit;
        }

        // ---------------- REVOKE ----------------
        if ($action === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            $rec = $db->fetchOne(
                "SELECT * FROM student_biometric_registrations
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$rec) throw new Exception('Registration not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_biometric_registrations
                    SET status = 'unverified',
                        verified_at = NULL,
                        verified_by = NULL,
                        method = NULL,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.biometric_registration.revoked',
                'student_biometric_registrations',
                $id,
                [
                    'student_id' => (int)$rec['student_id'],
                    'academic_year_id' => (int)$rec['academic_year_id'],
                    'academic_term_id' => (int)$rec['academic_term_id'],
                    'previous_status' => $rec['status'],
                ]
            );
            $db->commit();

            $_SESSION['success'] = 'Registration reset to unverified.';
            header('Location: /platform/tenant/students/biometric-registration.php'
                . '?year_id=' . (int)$rec['academic_year_id']
                . '&term_id='  . (int)$rec['academic_term_id']);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Biometric registration action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/biometric-registration.php');
        exit;
    }
}

// ============================================
// FLASH
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

// ============================================
// LOAD PAGE DATA
// ============================================
$settings      = loadActiveSettings($db, $tenantId);
$labelAcademic = $settings['label_academic_structure'] ?? 'Academic Year';
$labelLevel    = $settings['label_level'] ?? 'Level';
$labelClass    = $settings['label_class'] ?? 'Class';
$labelStream   = $settings['label_stream'] ?? 'Stream';
$labelTerm     = $settings['label_term'] ?? 'Term';

$filterYear = (int)($_GET['year_id'] ?? 0);
$filterTerm = (int)($_GET['term_id'] ?? 0);

$years = $db->fetchAll(
    "SELECT id, year_name, is_current FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);
$terms = $db->fetchAll(
    "SELECT id, term_name, is_current, sort_order FROM academic_terms
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY is_current DESC, sort_order ASC, id ASC",
    [$tenantId]
);

if ($filterYear <= 0) {
    $cy = currentYear($db, $tenantId);
    if ($cy) $filterYear = (int)$cy['id'];
}
if ($filterTerm <= 0) {
    $ct = currentTerm($db, $tenantId);
    if ($ct) $filterTerm = (int)$ct['id'];
}

$studentRows = [];
if ($filterYear > 0) {
    $studentRows = $db->fetchAll(
        "SELECT e.id AS enrollment_id, e.class_offering_id,
                s.id AS student_id,
                s.student_number, s.first_name, s.middle_name, s.last_name, s.preferred_name,
                co.academic_level_id, co.academic_year_id, co.class_id, co.stream_id,
                al.level_name, al.level_code, al.sort_order AS level_sort,
                c.class_name, c.class_code,
                st.stream_name,
                sbr.id AS reg_id, sbr.status AS reg_status,
                sbr.verified_at, sbr.method, sbr.notes AS reg_notes
         FROM enrollments e
         JOIN students s ON e.student_id = s.id
         JOIN class_offerings co ON e.class_offering_id = co.id
         LEFT JOIN academic_levels al ON co.academic_level_id = al.id
         LEFT JOIN classes c ON co.class_id = c.id
         LEFT JOIN streams st ON co.stream_id = st.id
         LEFT JOIN student_biometric_registrations sbr
                ON sbr.tenant_id = e.tenant_id
               AND sbr.student_id = s.id
               AND sbr.academic_year_id = e.academic_year_id
               AND sbr.academic_term_id = ?
               AND sbr.deleted_at IS NULL
         WHERE e.tenant_id = ?
           AND e.academic_year_id = ?
           AND e.status = 'active'
           AND e.deleted_at IS NULL
           AND s.deleted_at IS NULL
         ORDER BY al.sort_order ASC, c.class_name ASC, st.stream_name ASC,
                  CASE WHEN e.roll_number IS NULL OR e.roll_number = '' THEN 1 ELSE 0 END,
                  CAST(e.roll_number AS UNSIGNED) ASC,
                  s.first_name ASC",
        [$filterTerm, $tenantId, $filterYear]
    );
}

$byLevel = [];
foreach ($studentRows as $r) {
    $lKey = $r['academic_level_id'] !== null ? (int)$r['academic_level_id'] : 0;
    $lName = $r['level_name'] ?: '(Unknown level)';
    $lCode = $r['level_code'] ?? '';
    if (!isset($byLevel[$lKey])) {
        $byLevel[$lKey] = ['level_name' => $lName, 'level_code' => $lCode, 'offerings' => []];
    }
    $oKey = (int)$r['class_offering_id'];
    if (!isset($byLevel[$lKey]['offerings'][$oKey])) {
        $byLevel[$lKey]['offerings'][$oKey] = [
            'class_name'  => $r['class_name'] ?: '—',
            'class_code'  => $r['class_code'] ?? '',
            'stream_name' => $r['stream_name'] ?? '',
            'items'       => [],
        ];
    }
    $byLevel[$lKey]['offerings'][$oKey]['items'][] = $r;
}

$totalStudents = count($studentRows);
$verifiedCount = 0;
$exemptCount = 0;
$unverifiedCount = 0;
foreach ($studentRows as $r) {
    if ($r['reg_status'] === 'verified')      $verifiedCount++;
    elseif ($r['reg_status'] === 'exempted')  $exemptCount++;
    else                                      $unverifiedCount++;
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
    <title><?php echo h($pageTitle); ?></title>
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

        .btn-outline-primary {
            background: transparent;
            border: 2px solid #4facfe;
            color: #4facfe;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-primary:hover {
            background: #4facfe;
            color: #fff;
        }

        .btn-outline-success {
            background: transparent;
            border: 2px solid #16a34a;
            color: #166534;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-success:hover {
            background: #16a34a;
            color: #fff;
        }

        .btn-outline-warning {
            background: transparent;
            border: 2px solid #ffc107;
            color: #856404;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-warning:hover {
            background: #ffc107;
            color: #1a1a2e;
        }

        .btn-outline-danger {
            background: transparent;
            border: 2px solid #dc3545;
            color: #dc3545;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .btn-outline-info {
            background: transparent;
            border: 2px solid #0891b2;
            color: #0e7490;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-info:hover {
            background: #0891b2;
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
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
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

        .info-banner {
            background: linear-gradient(135deg, #eef6ff 0%, #e3f0ff 100%);
            border: 2px solid #4facfe;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 24px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .info-banner .ib-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(79, 172, 254, 0.15);
            color: #0d6efd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .info-banner .ib-body {
            flex: 1;
        }

        .info-banner .ib-title {
            font-weight: 700;
            font-size: 14px;
            color: #0d6efd;
            margin-bottom: 2px;
        }

        .info-banner .ib-text {
            font-size: 13px;
            color: #495057;
        }

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 180px;
        }

        .filters-bar .filter-group label {
            font-weight: 500;
            font-size: 12px;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .filters-bar .form-select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .level-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .level-section .level-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .level-section .level-header .level-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .level-section .level-header .level-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 18px;
            color: #1a1a2e;
        }

        .offering-block {
            border-bottom: 1px solid #f0f2f5;
        }

        .offering-block:last-child {
            border-bottom: none;
        }

        .offering-block .offering-bar {
            padding: 10px 24px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            border-bottom: 1px solid #f0f2f5;
        }

        .offering-block .offering-bar h6 {
            font-weight: 600;
            margin: 0;
            font-size: 14px;
            color: #1a1a2e;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .reg-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .reg-table thead th {
            background: #f8f9fa;
            padding: 10px 18px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .reg-table tbody td {
            padding: 10px 18px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .reg-table tbody tr:last-child td {
            border-bottom: none;
        }

        .reg-table tbody tr:hover {
            background: #fafbfc;
        }

        .student-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 13px;
        }

        .student-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
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
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
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

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .stat-cell {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
        }

        .stat-cell .stat-num {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
        }

        .stat-cell .stat-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-cell.ok {
            background: #ecfdf5;
        }

        .stat-cell.bad {
            background: #fef2f2;
        }

        .stat-cell.neu {
            background: #fffbeb;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 20px 24px;
        }

        .modal-header h5 {
            font-weight: 700;
            font-size: 17px;
            color: #1a1a2e;
            margin: 0;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 16px 24px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
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

            .reg-table {
                font-size: 12px;
            }

            .reg-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .reg-table tbody td {
                padding: 8px 10px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
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
                        <h1><i class="fas fa-fingerprint me-2"></i>Biometric Registration</h1>
                        <p>Per-term student verification — mark registrations as verified, exempted, or unverified</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Students
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo (int)$totalStudents; ?> enrolled student(s) shown ·
                                <?php echo (int)$verifiedCount; ?> verified ·
                                <?php echo (int)$exemptCount; ?> exempted ·
                                <?php echo (int)$unverifiedCount; ?> unverified
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Status-only record
                    </span>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Could not complete the request</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo h($error); ?></li>
                                <?php endforeach; ?>
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
                            <div style="font-size:13px;"><?php echo h($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <div class="info-banner">
                    <div class="ib-icon"><i class="fas fa-info-circle"></i></div>
                    <div class="ib-body">
                        <div class="ib-title">About this page</div>
                        <div class="ib-text">
                            Every student is expected to complete a biometric registration once per
                            <?php echo h(strtolower($labelTerm)); ?>. The physical capture — fingerprint, face or ID card —
                            happens on whatever device your school uses. This page records <strong>only</strong> that the
                            capture occurred, its method, and its timestamp. No biometric template or image is stored
                            in the database. Unverified students will be flagged by the notifications module in a later
                            session.
                        </div>
                    </div>
                </div>

                <!-- Filter bar -->
                <form method="GET" action="/platform/tenant/students/biometric-registration.php" class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-filter me-1"></i><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?>
                                    <?php if ((int)$y['is_current'] === 1): ?> ★<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><?php echo h($labelTerm); ?></label>
                        <select name="term_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($t['term_name']); ?>
                                    <?php if ((int)$t['is_current'] === 1): ?> ★<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/students/biometric-registration.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Reset to current
                        </a>
                    </div>
                </form>

                <?php if ($filterYear <= 0 || $filterTerm <= 0): ?>
                    <div class="level-section">
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h5>Select an academic year and term</h5>
                            <p>Registration is scoped to a year and term. Pick both above to continue.</p>
                        </div>
                    </div>
                <?php elseif (empty($byLevel)): ?>
                    <div class="level-section">
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h5>No active enrollments for this year</h5>
                            <p>Enroll students in a class offering before you can record their registration.</p>
                            <a href="/platform/tenant/academic/enrollments.php" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-user-plus me-1"></i> Go to Enrollments
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($byLevel as $lKey => $levelGroup): ?>
                        <div class="level-section">
                            <div class="level-header">
                                <div class="level-title">
                                    <i class="fas fa-layer-group" style="font-size:20px;color:#4facfe;"></i>
                                    <h5><?php echo h($levelGroup['level_name']); ?></h5>
                                    <?php if (!empty($levelGroup['level_code'])): ?>
                                        <span class="pill blue"><?php echo h($levelGroup['level_code']); ?></span>
                                    <?php endif; ?>
                                    <?php
                                    $lTotal = 0;
                                    $lVerified = 0;
                                    foreach ($levelGroup['offerings'] as $og) {
                                        foreach ($og['items'] as $it) {
                                            $lTotal++;
                                            if ($it['reg_status'] === 'verified' || $it['reg_status'] === 'exempted') $lVerified++;
                                        }
                                    }
                                    ?>
                                    <span class="pill <?php echo $lVerified === $lTotal ? 'green' : 'orange'; ?>">
                                        <?php echo (int)$lVerified; ?> / <?php echo (int)$lTotal; ?> verified
                                    </span>
                                </div>
                            </div>

                            <?php foreach ($levelGroup['offerings'] as $oKey => $og): ?>
                                <div class="offering-block">
                                    <div class="offering-bar">
                                        <i class="fas fa-door-open" style="color:#4facfe;"></i>
                                        <h6><?php echo h($og['class_name']); ?></h6>
                                        <?php if (!empty($og['class_code'])): ?>
                                            <span class="pill blue"><?php echo h($og['class_code']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($og['stream_name'])): ?>
                                            <span class="pill purple">
                                                <i class="fas fa-code-branch"></i><?php echo h($og['stream_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="pill gray">
                                            <i class="fas fa-user-graduate"></i><?php echo count($og['items']); ?>
                                        </span>
                                    </div>
                                    <table class="reg-table">
                                        <thead>
                                            <tr>
                                                <th style="width:60px;">#</th>
                                                <th>Student</th>
                                                <th style="width:130px;">Admission #</th>
                                                <th style="width:130px;">Status</th>
                                                <th style="width:180px;">Method</th>
                                                <th style="width:170px;">Verified at</th>
                                                <th style="text-align:right;min-width:240px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 0;
                                            foreach ($og['items'] as $s):
                                                $i++;
                                                $name = trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                                                if ($name === '') $name = 'Student #' . (int)$s['student_id'];

                                                $status = $s['reg_status'] ?? 'unverified';
                                                $statusPill = [
                                                    'verified'   => 'green',
                                                    'exempted'   => 'teal',
                                                    'unverified' => 'gray',
                                                ][$status] ?? 'gray';
                                                $statusLabel = ucfirst($status);

                                                $methodLabel = '';
                                                if (!empty($s['method'])) {
                                                    $methodLabel = [
                                                        'fingerprint' => 'Fingerprint',
                                                        'face'        => 'Face',
                                                        'id_card'     => 'ID Card',
                                                        'manual'      => 'Manual',
                                                    ][$s['method']] ?? $s['method'];
                                                }
                                            ?>
                                                <tr>
                                                    <td><?php echo $i; ?></td>
                                                    <td>
                                                        <div class="student-name"><?php echo h($name); ?></div>
                                                        <?php if (!empty($s['preferred_name'])): ?>
                                                            <div class="student-meta">Prefers: <?php echo h($s['preferred_name']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;">
                                                            <?php echo h($s['student_number']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="pill <?php echo $statusPill; ?>">
                                                            <?php echo h($statusLabel); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $methodLabel !== '' ? h($methodLabel) : '—'; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo !empty($s['verified_at']) ? h($s['verified_at']) : '—'; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <?php if ($status !== 'verified'): ?>
                                                                <button type="button"
                                                                    class="btn btn-outline-success js-verify"
                                                                    data-student-id="<?php echo (int)$s['student_id']; ?>"
                                                                    data-student-name="<?php echo h($name); ?>">
                                                                    <i class="fas fa-check"></i> Verify
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($status !== 'exempted'): ?>
                                                                <button type="button"
                                                                    class="btn btn-outline-warning js-exempt"
                                                                    data-student-id="<?php echo (int)$s['student_id']; ?>"
                                                                    data-student-name="<?php echo h($name); ?>">
                                                                    <i class="fas fa-shield-alt"></i> Exempt
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if (!empty($s['reg_id']) && $status !== 'unverified'): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Reset this registration to unverified?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="revoke">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$s['reg_id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-danger">
                                                                        <i class="fas fa-undo"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- VERIFY MODAL -->
    <div class="modal fade" id="verifyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/biometric-registration.php" id="verifyForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark">
                    <input type="hidden" name="student_id" id="verifyStudentId" value="">
                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-fingerprint text-primary me-2"></i>Mark Registration Verified</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:13px;color:#495057;margin-bottom:16px;">
                            Confirming that <strong id="verifyStudentName">this student</strong> has completed their
                            biometric registration for this term.
                        </p>
                        <div class="mb-3">
                            <label class="form-label" for="verifyMethod">Capture method</label>
                            <select class="form-select" id="verifyMethod" name="method" required>
                                <option value="fingerprint" selected>Fingerprint</option>
                                <option value="face">Face</option>
                                <option value="id_card">ID Card</option>
                                <option value="manual">Manual / Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="verifyNotes">Notes (optional)</label>
                            <input type="text" class="form-control" id="verifyNotes" name="notes" maxlength="500"
                                placeholder="Device name, registrar, or reference">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i> Mark Verified</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EXEMPT MODAL -->
    <div class="modal fade" id="exemptModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/students/biometric-registration.php" id="exemptForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="exempt">
                    <input type="hidden" name="student_id" id="exemptStudentId" value="">
                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                    <div class="modal-header">
                        <h5><i class="fas fa-shield-alt text-warning me-2"></i>Exempt from Biometric Registration</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:13px;color:#495057;margin-bottom:16px;">
                            Exempting <strong id="exemptStudentName">this student</strong> from the biometric
                            requirement for this term. This is recorded as a deliberate decision and audited.
                        </p>
                        <div class="mb-3">
                            <label class="form-label" for="exemptNotes">Reason <span style="color:#dc3545;">*</span></label>
                            <input type="text" class="form-control" id="exemptNotes" name="notes" maxlength="500"
                                placeholder="e.g. Medical condition — see file" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-shield-alt me-1"></i> Mark Exempted</button>
                    </div>
                </form>
            </div>
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

        // Verify modal
        (function() {
            const modalEl = document.getElementById('verifyModal');
            const modal = new bootstrap.Modal(modalEl);
            document.querySelectorAll('.js-verify').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sid = this.getAttribute('data-student-id');
                    const name = this.getAttribute('data-student-name') || 'this student';
                    document.getElementById('verifyStudentId').value = sid;
                    document.getElementById('verifyStudentName').textContent = name;
                    document.getElementById('verifyNotes').value = '';
                    document.getElementById('verifyMethod').value = 'fingerprint';
                    modal.show();
                });
            });
        })();

        // Exempt modal
        (function() {
            const modalEl = document.getElementById('exemptModal');
            const modal = new bootstrap.Modal(modalEl);
            document.querySelectorAll('.js-exempt').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sid = this.getAttribute('data-student-id');
                    const name = this.getAttribute('data-student-name') || 'this student';
                    document.getElementById('exemptStudentId').value = sid;
                    document.getElementById('exemptStudentName').textContent = name;
                    document.getElementById('exemptNotes').value = '';
                    modal.show();
                });
            });
        })();

        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(() => {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(() => successBox.remove(), 300);
            }, 6000);
        }

        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>