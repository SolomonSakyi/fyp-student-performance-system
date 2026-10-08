<?php

/**
 * Student Behaviour — per-term conduct score derived from discipline, attendance
 * and a class-teacher checklist, with override + finalisation workflow.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/behaviour.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students behaviour file of the students-surface sweep. Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'students_behaviour' to 'students'
 *       so the partial marks Students active and renders the
 *       student sub-menu on this page — consistent with every
 *       other students file.
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
 *   previous version (1.2). The @package tag remains 'EduTrack'.
 *
 * Session S17e decisions:
 *   DB1A  table: student_behaviour
 *   DB2A  one row per (tenant, student, year, term)
 *   DB3D  inputs: discipline + attendance + checklist + override
 *   DB4A  100 - (minor×1 + moderate×3 + major×10), floor 0
 *   DB5A  -1 per unexcused absence, -0.5 per late, cap 20
 *   DB6A  checklist: punctuality, respect, participation (1..5 each) → 0..20
 *   DB7A  bands: Excellent/Very Good/Good/Fair/Poor; pass ≥ 60
 *   DB8A  draft → finalised
 *   DB9C  auto-regenerate while draft; blocked once finalised
 *   DB10D per-student + per-class + per-year generation
 *   DB11A override score + reason + by + at (replaces computed for grading)
 *
 * Session S17g-2 additions:
 *   P4  on finalise → notify head teachers always; guardian only on fail
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

$pageTitle   = 'Behaviour - Student 360 Platform';
$currentPage = 'students';

// ============================================
// AJAX: LIVE STUDENT SEARCH (read-only, no CSRF)
// ============================================
if (isset($_GET['ajax_search_students'])) {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $like = '%' . $q . '%';

    try {
        $rows = $db->fetchAll(
            "SELECT s.id, s.student_number,
                    s.first_name, s.middle_name, s.last_name, s.preferred_name
             FROM students s
             WHERE s.tenant_id = ?
               AND s.deleted_at IS NULL
               AND s.is_active = 1
               AND (
                    s.student_number LIKE ?
                 OR s.first_name LIKE ?
                 OR s.middle_name LIKE ?
                 OR s.last_name LIKE ?
                 OR s.preferred_name LIKE ?
                 OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                 OR CONCAT(s.first_name, ' ', s.middle_name, ' ', s.last_name) LIKE ?
               )
             ORDER BY s.first_name ASC, s.last_name ASC
             LIMIT 20",
            [$tenantId, $like, $like, $like, $like, $like, $like, $like]
        );
        $items = [];
        foreach ($rows as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name === '') $name = 'Student #' . (int)$r['id'];
            $items[] = [
                'id'    => (int)$r['id'],
                'name'  => $name,
                'num'   => (string)$r['student_number'],
                'label' => $name . ' — ' . (string)$r['student_number'],
            ];
        }
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('behaviour ajax_search error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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

function conductGrade(float $score): array
{
    if ($score >= 90) return ['Excellent', 'green'];
    if ($score >= 80) return ['Very Good', 'teal'];
    if ($score >= 70) return ['Good',      'blue'];
    if ($score >= 60) return ['Fair',      'orange'];
    return                   ['Poor',      'red'];
}
function conductPill(string $grade): string
{
    return [
        'Excellent' => 'green',
        'Very Good' => 'teal',
        'Good'      => 'blue',
        'Fair'      => 'orange',
        'Poor'      => 'red',
    ][$grade] ?? 'gray';
}
function statusPill(string $st): string
{
    return ['draft' => 'orange', 'finalised' => 'green'][$st] ?? 'gray';
}

function computeBehaviour($db, int $tenantId, int $studentId, int $yearId, int $termId): array
{
    $disc = $db->fetchOne(
        "SELECT
            SUM(CASE WHEN severity = 'minor'    THEN 1 ELSE 0 END) AS c_minor,
            SUM(CASE WHEN severity = 'moderate' THEN 1 ELSE 0 END) AS c_moderate,
            SUM(CASE WHEN severity = 'major'    THEN 1 ELSE 0 END) AS c_major
         FROM student_discipline
         WHERE tenant_id = ?
           AND student_id = ?
           AND academic_year_id = ?
           AND academic_term_id = ?
           AND status IN ('reviewed','resolved')
           AND deleted_at IS NULL",
        [$tenantId, $studentId, $yearId, $termId]
    );
    $cMinor    = (int)($disc['c_minor']    ?? 0);
    $cModerate = (int)($disc['c_moderate'] ?? 0);
    $cMajor    = (int)($disc['c_major']    ?? 0);

    $disciplineDeduction = ($cMinor * 1) + ($cModerate * 3) + ($cMajor * 10);
    $disciplineScore     = max(0, 100 - $disciplineDeduction);

    $absences = 0;
    $lates = 0;
    try {
        $att = $db->fetchOne(
            "SELECT
                SUM(CASE WHEN status IN ('absent','unexcused','absent_unexcused') THEN 1 ELSE 0 END) AS c_abs,
                SUM(CASE WHEN status IN ('late','tardy') THEN 1 ELSE 0 END) AS c_late
             FROM student_attendance
             WHERE tenant_id = ?
               AND student_id = ?
               AND academic_term_id = ?
               AND deleted_at IS NULL",
            [$tenantId, $studentId, $termId]
        );
        $absences = (int)($att['c_abs']  ?? 0);
        $lates    = (int)($att['c_late'] ?? 0);
    } catch (Exception $e) {
        $absences = 0;
        $lates = 0;
    }

    $attendanceDeduction = min(20, ($absences * 1) + ($lates * 0.5));

    $rPunct = null;
    $rResp = null;
    $rPart = null;
    $checklistBonus = 0.0;

    $row = $db->fetchOne(
        "SELECT rating_punctuality, rating_respect, rating_participation
         FROM student_behaviour
         WHERE tenant_id = ? AND student_id = ?
           AND academic_year_id = ? AND academic_term_id = ?
           AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId, $studentId, $yearId, $termId]
    );
    if ($row) {
        $rPunct = $row['rating_punctuality'] !== null ? (int)$row['rating_punctuality'] : null;
        $rResp  = $row['rating_respect']     !== null ? (int)$row['rating_respect']     : null;
        $rPart  = $row['rating_participation'] !== null ? (int)$row['rating_participation'] : null;
    }
    if ($rPunct !== null && $rResp !== null && $rPart !== null) {
        $avg = ($rPunct + $rResp + $rPart) / 3.0;
        $checklistBonus = round((($avg - 1) / 4) * 20, 2);
    }

    $finalScore = $disciplineScore - $attendanceDeduction + $checklistBonus;
    if ($finalScore < 0)   $finalScore = 0;
    if ($finalScore > 100) $finalScore = 100;
    $finalScore = round($finalScore, 2);

    list($grade) = conductGrade($finalScore);

    return [
        'incidents_minor'      => $cMinor,
        'incidents_moderate'   => $cModerate,
        'incidents_major'      => $cMajor,
        'discipline_deduction' => round($disciplineDeduction, 2),
        'absence_count'        => $absences,
        'late_count'           => $lates,
        'attendance_deduction' => round($attendanceDeduction, 2),
        'rating_punctuality'   => $rPunct,
        'rating_respect'       => $rResp,
        'rating_participation' => $rPart,
        'checklist_bonus'      => round($checklistBonus, 2),
        'computed_score'       => $finalScore,
        'computed_grade'       => $grade,
    ];
}

function currentOfferingId($db, int $tenantId, int $studentId, int $yearId): ?int
{
    $row = $db->fetchOne(
        "SELECT class_offering_id FROM enrollments
         WHERE tenant_id = ? AND student_id = ? AND academic_year_id = ?
           AND status = 'active' AND deleted_at IS NULL
         ORDER BY id ASC LIMIT 1",
        [$tenantId, $studentId, $yearId]
    );
    return $row ? (int)$row['class_offering_id'] : null;
}

function generateBehaviourRow($db, int $tenantId, int $userId, int $studentId, int $yearId, int $termId): int
{
    $offeringId = currentOfferingId($db, $tenantId, $studentId, $yearId);

    $existing = $db->fetchOne(
        "SELECT * FROM student_behaviour
         WHERE tenant_id = ? AND student_id = ?
           AND academic_year_id = ? AND academic_term_id = ?
           AND deleted_at IS NULL
         ORDER BY id DESC LIMIT 1",
        [$tenantId, $studentId, $yearId, $termId]
    );

    if ($existing && $existing['status'] === 'finalised') {
        return (int)$existing['id'];
    }

    $calc = computeBehaviour($db, $tenantId, $studentId, $yearId, $termId);

    $overrideScore = $existing['override_score'] ?? null;
    $effectiveScore = $overrideScore !== null ? (float)$overrideScore : $calc['computed_score'];
    if ($effectiveScore < 0)   $effectiveScore = 0;
    if ($effectiveScore > 100) $effectiveScore = 100;
    list($effGrade) = conductGrade($effectiveScore);
    $isPass = $effectiveScore >= 60 ? 1 : 0;

    if ($existing) {
        $db->execute(
            "UPDATE student_behaviour
                SET class_offering_id    = ?,
                    incidents_minor      = ?,
                    incidents_moderate   = ?,
                    incidents_major      = ?,
                    discipline_deduction = ?,
                    absence_count        = ?,
                    late_count           = ?,
                    attendance_deduction = ?,
                    rating_punctuality   = ?,
                    rating_respect       = ?,
                    rating_participation = ?,
                    checklist_bonus      = ?,
                    computed_score       = ?,
                    computed_grade       = ?,
                    final_score          = ?,
                    final_grade          = ?,
                    is_pass              = ?,
                    generated_at         = NOW(),
                    updated_at           = NOW()
              WHERE id = ? AND tenant_id = ?",
            [
                $offeringId,
                $calc['incidents_minor'],
                $calc['incidents_moderate'],
                $calc['incidents_major'],
                $calc['discipline_deduction'],
                $calc['absence_count'],
                $calc['late_count'],
                $calc['attendance_deduction'],
                $calc['rating_punctuality'],
                $calc['rating_respect'],
                $calc['rating_participation'],
                $calc['checklist_bonus'],
                $calc['computed_score'],
                $calc['computed_grade'],
                round($effectiveScore, 2),
                $effGrade,
                $isPass,
                (int)$existing['id'],
                $tenantId,
            ]
        );
        return (int)$existing['id'];
    }

    $newId = (int)$db->insert(
        "INSERT INTO student_behaviour
            (uuid, tenant_id, student_id, class_offering_id,
             academic_year_id, academic_term_id,
             incidents_minor, incidents_moderate, incidents_major, discipline_deduction,
             absence_count, late_count, attendance_deduction,
             rating_punctuality, rating_respect, rating_participation, checklist_bonus,
             computed_score, computed_grade,
             override_score, override_reason, overridden_by, overridden_at,
             final_score, final_grade, is_pass,
             status, generated_at, notes, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL,
                 ?, ?, ?, 'draft', NOW(), NULL, ?, NOW(), NOW())",
        [
            uuidv4(),
            $tenantId,
            $studentId,
            $offeringId,
            $yearId,
            $termId,
            $calc['incidents_minor'],
            $calc['incidents_moderate'],
            $calc['incidents_major'],
            $calc['discipline_deduction'],
            $calc['absence_count'],
            $calc['late_count'],
            $calc['attendance_deduction'],
            $calc['rating_punctuality'],
            $calc['rating_respect'],
            $calc['rating_participation'],
            $calc['checklist_bonus'],
            $calc['computed_score'],
            $calc['computed_grade'],
            round($effectiveScore, 2),
            $effGrade,
            $isPass,
            $userId ?: null,
        ]
    );
    return $newId;
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
        // ---------------- GENERATE ONE ----------------
        if ($action === 'generate_one') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $yearId    = (int)($_POST['academic_year_id'] ?? 0);
            $termId    = (int)($_POST['academic_term_id'] ?? 0);

            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($yearId <= 0)    throw new Exception('Academic year is required.');
            if ($termId <= 0)    throw new Exception('Term is required.');

            $stu = $db->fetchOne(
                "SELECT id FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$stu) throw new Exception('Student not found.');

            $db->beginTransaction();
            $rid = generateBehaviourRow($db, $tenantId, $userId, $studentId, $yearId, $termId);
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.generated',
                'student_behaviour',
                $rid,
                ['student_id' => $studentId, 'year_id' => $yearId, 'term_id' => $termId]
            );
            $db->commit();

            $_SESSION['success'] = 'Behaviour row generated.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . $termId);
            exit;
        }

        // ---------------- GENERATE CLASS ----------------
        if ($action === 'generate_class') {
            $classId = (int)($_POST['class_id'] ?? 0);
            $yearId  = (int)($_POST['academic_year_id'] ?? 0);
            $termId  = (int)($_POST['academic_term_id'] ?? 0);

            if ($classId <= 0) throw new Exception('Class is required.');
            if ($yearId  <= 0) throw new Exception('Academic year is required.');
            if ($termId  <= 0) throw new Exception('Term is required.');

            $students = $db->fetchAll(
                "SELECT DISTINCT e.student_id
                 FROM enrollments e
                 JOIN class_offerings co ON e.class_offering_id = co.id
                 WHERE e.tenant_id = ?
                   AND e.academic_year_id = ?
                   AND e.status = 'active'
                   AND e.deleted_at IS NULL
                   AND co.class_id = ?
                   AND co.deleted_at IS NULL",
                [$tenantId, $yearId, $classId]
            );

            $db->beginTransaction();
            $n = 0;
            foreach ($students as $s) {
                generateBehaviourRow($db, $tenantId, $userId, (int)$s['student_id'], $yearId, $termId);
                $n++;
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.generated_class',
                'student_behaviour',
                null,
                ['class_id' => $classId, 'year_id' => $yearId, 'term_id' => $termId, 'count' => $n]
            );
            $db->commit();

            $_SESSION['success'] = 'Behaviour generated for ' . $n . ' student(s).';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . $termId);
            exit;
        }

        // ---------------- GENERATE YEAR ----------------
        if ($action === 'generate_year') {
            $yearId = (int)($_POST['academic_year_id'] ?? 0);
            $termId = (int)($_POST['academic_term_id'] ?? 0);

            if ($yearId <= 0) throw new Exception('Academic year is required.');
            if ($termId <= 0) throw new Exception('Term is required.');

            $students = $db->fetchAll(
                "SELECT DISTINCT e.student_id
                 FROM enrollments e
                 WHERE e.tenant_id = ?
                   AND e.academic_year_id = ?
                   AND e.status = 'active'
                   AND e.deleted_at IS NULL",
                [$tenantId, $yearId]
            );

            $db->beginTransaction();
            $n = 0;
            foreach ($students as $s) {
                generateBehaviourRow($db, $tenantId, $userId, (int)$s['student_id'], $yearId, $termId);
                $n++;
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.generated_year',
                'student_behaviour',
                null,
                ['year_id' => $yearId, 'term_id' => $termId, 'count' => $n]
            );
            $db->commit();

            $_SESSION['success'] = 'Behaviour generated for ' . $n . ' student(s).';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . $termId);
            exit;
        }

        // ---------------- SAVE CHECKLIST ----------------
        if ($action === 'save_checklist') {
            $id      = (int)($_POST['id'] ?? 0);
            $punct   = (int)($_POST['rating_punctuality'] ?? 0);
            $resp    = (int)($_POST['rating_respect'] ?? 0);
            $part    = (int)($_POST['rating_participation'] ?? 0);

            if ($id <= 0) throw new Exception('Behaviour row id is required.');
            foreach (['punctuality' => $punct, 'respect' => $resp, 'participation' => $part] as $k => $v) {
                if ($v < 1 || $v > 5) throw new Exception('Rating for ' . $k . ' must be between 1 and 5.');
            }

            $row = $db->fetchOne(
                "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Behaviour row not found.');
            if ($row['status'] === 'finalised') throw new Exception('Finalised rows cannot be edited.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_behaviour
                    SET rating_punctuality = ?, rating_respect = ?, rating_participation = ?,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$punct, $resp, $part, $id, $tenantId]
            );
            generateBehaviourRow(
                $db,
                $tenantId,
                $userId,
                (int)$row['student_id'],
                (int)$row['academic_year_id'],
                (int)$row['academic_term_id']
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.checklist_saved',
                'student_behaviour',
                $id,
                ['punctuality' => $punct, 'respect' => $resp, 'participation' => $part]
            );
            $db->commit();

            $_SESSION['success'] = 'Checklist saved and score recalculated.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- OVERRIDE ----------------
        if ($action === 'override') {
            $id     = (int)($_POST['id'] ?? 0);
            $score  = trim((string)($_POST['override_score'] ?? ''));
            $reason = trim((string)($_POST['override_reason'] ?? ''));

            if ($id <= 0) throw new Exception('Behaviour row id is required.');
            if ($score === '' || !is_numeric($score)) throw new Exception('Override score is required and must be numeric.');
            $score = (float)$score;
            if ($score < 0 || $score > 100) throw new Exception('Override score must be between 0 and 100.');
            if ($reason === '') throw new Exception('Override reason is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Behaviour row not found.');
            if ($row['status'] === 'finalised') throw new Exception('Finalised rows cannot be overridden.');

            list($effGrade) = conductGrade($score);
            $isPass = $score >= 60 ? 1 : 0;

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_behaviour
                    SET override_score = ?, override_reason = ?, overridden_by = ?, overridden_at = NOW(),
                        final_score = ?, final_grade = ?, is_pass = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$score, $reason, $userId ?: null, round($score, 2), $effGrade, $isPass, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.overridden',
                'student_behaviour',
                $id,
                ['override_score' => $score, 'reason' => $reason]
            );
            $db->commit();

            $_SESSION['success'] = 'Override applied.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- CLEAR OVERRIDE ----------------
        if ($action === 'clear_override') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Behaviour row id is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Behaviour row not found.');
            if ($row['status'] === 'finalised') throw new Exception('Finalised rows cannot be edited.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_behaviour
                    SET override_score = NULL, override_reason = NULL,
                        overridden_by = NULL, overridden_at = NULL,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            generateBehaviourRow(
                $db,
                $tenantId,
                $userId,
                (int)$row['student_id'],
                (int)$row['academic_year_id'],
                (int)$row['academic_term_id']
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.override_cleared',
                'student_behaviour',
                $id,
                []
            );
            $db->commit();

            $_SESSION['success'] = 'Override cleared; score reverted to computed value.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- FINALISE ----------------
        if ($action === 'finalise') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Behaviour row id is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Behaviour row not found.');
            if ($row['status'] === 'finalised') {
                $_SESSION['success'] = 'Row is already finalised.';
                header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
                exit;
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_behaviour
                    SET status = 'finalised', finalised_by = ?, finalised_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$userId ?: null, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.finalised',
                'student_behaviour',
                $id,
                ['score' => $row['final_score'], 'grade' => $row['final_grade']]
            );

            // ---------------------------------------------------------------
            // P4 — Notify head teachers always. Guardian only on FAIL (PR4A).
            // ---------------------------------------------------------------
            try {
                require_once $projectRoot . '/app/helpers/NotificationHelper.php';

                $studentId  = (int)$row['student_id'];
                $yearId     = (int)$row['academic_year_id'];
                $termId     = (int)$row['academic_term_id'];
                $finalScore = (float)$row['final_score'];
                $finalGrade = (string)$row['final_grade'];
                $isPass     = (int)$row['is_pass'] === 1;

                $sn = $db->fetchOne(
                    "SELECT first_name, middle_name, last_name
                       FROM students WHERE id = ? AND tenant_id = ?",
                    [$studentId, $tenantId]
                );
                $stuName = $sn ? trim(($sn['first_name'] ?? '') . ' ' . ($sn['middle_name'] ?? '') . ' ' . ($sn['last_name'] ?? '')) : ('Student #' . $studentId);
                if ($stuName === '') $stuName = 'Student #' . $studentId;

                $termName = '';
                if ($termId > 0) {
                    $tr = $db->fetchOne(
                        "SELECT term_name FROM academic_terms WHERE id = ? AND tenant_id = ?",
                        [$termId, $tenantId]
                    );
                    if ($tr) $termName = $tr['term_name'];
                }

                $staffIds = NotificationHelper::headTeachers($db, $tenantId);
                $guardianIds = [];
                if (!$isPass) {
                    $g = NotificationHelper::guardianForStudent($db, $tenantId, $studentId);
                    if ($g) $guardianIds[] = $g;
                }

                NotificationHelper::notify($db, $tenantId, [
                    'recipients'   => ['staff' => $staffIds, 'guardians' => $guardianIds],
                    'type'         => 'behaviour',
                    'priority'     => $isPass ? 'normal' : 'high',
                    'title'        => 'Conduct finalised — ' . $stuName . ' (' . number_format($finalScore, 1) . ')',
                    'message'      => ($termName !== '' ? $termName . ' ' : '') . 'conduct for ' . $stuName
                        . ': ' . number_format($finalScore, 1) . ' (' . $finalGrade . '). Status: '
                        . ($isPass ? 'Pass' : 'FAIL') . '.',
                    'link'         => '/platform/tenant/students/behaviour.php?term_id=' . $termId . '&student_id=' . $studentId,
                    'related_type' => 'student_behaviour',
                    'related_id'   => $id,
                    'created_by'   => $userId,
                ]);
            } catch (Exception $notifyEx) {
                error_log('P4 notify failed: ' . $notifyEx->getMessage());
            }

            $db->commit();

            $_SESSION['success'] = 'Behaviour row finalised.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- REOPEN ----------------
        if ($action === 'reopen') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Behaviour row id is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Behaviour row not found.');
            if ($row['status'] !== 'finalised') throw new Exception('Only finalised rows can be reopened.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_behaviour
                    SET status = 'draft', finalised_by = NULL, finalised_at = NULL, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.reopened',
                'student_behaviour',
                $id,
                []
            );
            $db->commit();

            $_SESSION['success'] = 'Row reopened for editing.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
            exit;
        }

        // ---------------- DELETE (soft) ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Behaviour row id is required.');

            $row = $db->fetchOne(
                "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Behaviour row not found.');
            if ($row['status'] === 'finalised') throw new Exception('Finalised rows cannot be deleted. Reopen first.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE student_behaviour SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'students.behaviour.deleted',
                'student_behaviour',
                $id,
                ['student_id' => (int)$row['student_id']]
            );
            $db->commit();

            $_SESSION['success'] = 'Behaviour row deleted.';
            header('Location: /platform/tenant/students/behaviour.php?term_id=' . (int)$row['academic_term_id']);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Behaviour action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/students/behaviour.php');
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
    $useForm = true;
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
$labelClass    = $settings['label_class'] ?? 'Class';
$labelTerm     = $settings['label_term'] ?? 'Term';

$filterYear     = (int)($_GET['year_id'] ?? 0);
$filterTerm     = (int)($_GET['term_id'] ?? 0);
$filterClass    = (int)($_GET['class_id'] ?? 0);
$filterStudent  = (int)($_GET['student_id'] ?? 0);
$filterStatus   = trim((string)($_GET['status'] ?? ''));

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
if ($filterYear <= 0 && !empty($years)) $filterYear = (int)$years[0]['id'];
if ($filterTerm <= 0) {
    $ct = currentTerm($db, $tenantId);
    if ($ct) $filterTerm = (int)$ct['id'];
}
if ($filterTerm <= 0 && !empty($terms)) $filterTerm = (int)$terms[0]['id'];

$classes = $db->fetchAll(
    "SELECT c.id, c.class_name, c.class_code,
            al.level_name, al.sort_order AS level_sort
     FROM classes c
     LEFT JOIN academic_levels al ON c.school_id = al.school_id
     WHERE c.tenant_id = ? AND c.deleted_at IS NULL
     ORDER BY al.sort_order ASC, c.class_name ASC",
    [$tenantId]
);

$filterStudentLabel = '';
if ($filterStudent > 0) {
    $fs = $db->fetchOne(
        "SELECT first_name, middle_name, last_name, student_number
         FROM students WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$filterStudent, $tenantId]
    );
    if ($fs) {
        $nm = trim(($fs['first_name'] ?? '') . ' ' . ($fs['middle_name'] ?? '') . ' ' . ($fs['last_name'] ?? ''));
        $filterStudentLabel = $nm . ' — ' . (string)$fs['student_number'];
    }
}

$where = ["b.tenant_id = ?", "b.deleted_at IS NULL"];
$params = [$tenantId];

if ($filterYear > 0) {
    $where[] = "b.academic_year_id = ?";
    $params[] = $filterYear;
}
if ($filterTerm > 0) {
    $where[] = "b.academic_term_id = ?";
    $params[] = $filterTerm;
}
if ($filterStudent > 0) {
    $where[] = "b.student_id = ?";
    $params[] = $filterStudent;
}
if (in_array($filterStatus, ['draft', 'finalised'], true)) {
    $where[] = "b.status = ?";
    $params[] = $filterStatus;
}
if ($filterClass > 0) {
    $where[] = "EXISTS (
        SELECT 1 FROM class_offerings co
        WHERE co.id = b.class_offering_id
          AND co.class_id = ?
          AND co.deleted_at IS NULL
    )";
    $params[] = $filterClass;
}
$whereClause = implode(' AND ', $where);

$rows = $db->fetchAll(
    "SELECT b.*,
            s.student_number, s.first_name, s.middle_name, s.last_name, s.preferred_name,
            c2.class_name, c2.class_code,
            al.level_name,
            ay.year_name, t.term_name
     FROM student_behaviour b
     JOIN students s ON b.student_id = s.id
     LEFT JOIN class_offerings co ON b.class_offering_id = co.id
     LEFT JOIN classes c2 ON co.class_id = c2.id
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN academic_years ay ON b.academic_year_id = ay.id
     LEFT JOIN academic_terms t ON b.academic_term_id = t.id
     WHERE $whereClause
     ORDER BY s.first_name ASC, s.last_name ASC
     LIMIT 500",
    $params
);

$byClass = [];
foreach ($rows as $r) {
    $ckey = $r['class_name'] ?: 'No class';
    if (!isset($byClass[$ckey])) {
        $byClass[$ckey] = ['class_name' => $ckey, 'class_code' => $r['class_code'] ?? '', 'items' => []];
    }
    $byClass[$ckey]['items'][] = $r;
}

$totalRows = count($rows);
$byStatus  = ['draft' => 0, 'finalised' => 0];
$byGrade   = ['Excellent' => 0, 'Very Good' => 0, 'Good' => 0, 'Fair' => 0, 'Poor' => 0];
$passCount = 0;
foreach ($rows as $r) {
    if (isset($byStatus[$r['status']])) $byStatus[$r['status']]++;
    if (isset($byGrade[$r['final_grade']])) $byGrade[$r['final_grade']]++;
    if ((int)$r['is_pass'] === 1) $passCount++;
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$editRow = null;
if (($_GET['action'] ?? '') === 'checklist' && !empty($_GET['id'])) {
    $editRow = $db->fetchOne(
        "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
}
$overrideRow = null;
if (($_GET['action'] ?? '') === 'override' && !empty($_GET['id'])) {
    $overrideRow = $db->fetchOne(
        "SELECT * FROM student_behaviour WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$_GET['id'], $tenantId]
    );
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

        .btn-outline-dark {
            background: transparent;
            border: 2px solid #1a1a2e;
            color: #1a1a2e;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-dark:hover {
            background: #1a1a2e;
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
            max-width: 1300px;
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
            max-width: 1300px;
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
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 150px;
            flex: 1;
        }

        .filters-bar .fg label {
            font-size: 12px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .filters-bar .form-select,
        .filters-bar .form-control {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .student-search-wrap {
            position: relative;
        }

        .student-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1200;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            max-height: 260px;
            overflow-y: auto;
            display: none;
            margin-top: 4px;
        }

        .student-search-results.open {
            display: block;
        }

        .student-search-results .ssr-item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid #f6f2f5;
        }

        .student-search-results .ssr-item:last-child {
            border-bottom: none;
        }

        .student-search-results .ssr-item:hover,
        .student-search-results .ssr-item.active {
            background: #eef6ff;
        }

        .student-search-results .ssr-item .ssr-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .student-search-results .ssr-item .ssr-num {
            font-size: 11px;
            color: #6c757d;
            font-family: 'Courier New', monospace;
        }

        .student-search-results .ssr-empty {
            padding: 12px;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
        }

        .student-search-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
            cursor: pointer;
            border: none;
            background: transparent;
            font-size: 14px;
            padding: 0;
        }

        .student-search-clear:hover {
            color: #dc3545;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .stat-cell {
            background: #fff;
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .stat-cell .stat-num {
            font-weight: 700;
            font-size: 20px;
            color: #1a1a2e;
        }

        .stat-cell .stat-lbl {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .level-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1300px;
            margin-left: auto;
            margin-right: auto;
        }

        .level-section .level-header {
            padding: 14px 24px;
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
            font-size: 17px;
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

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table thead th {
            background: #f8f9fa;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .data-table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafbfc;
        }

        .score-cell {
            font-weight: 700;
            font-size: 16px;
            color: #1a1a2e;
            font-family: 'Courier New', monospace;
        }

        .score-cell small {
            color: #6c757d;
            font-weight: 400;
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
            max-width: 1300px;
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

        textarea.form-control {
            height: auto;
            min-height: 80px;
        }

        .rating-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .rating-row .rating-label {
            flex: 1;
            font-size: 13px;
            font-weight: 500;
        }

        .rating-row .rating-options {
            display: flex;
            gap: 6px;
        }

        .rating-pill {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #e9ecef;
            background: #fff;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.2s;
        }

        .rating-pill:hover {
            border-color: #4facfe;
            color: #4facfe;
        }

        .rating-pill.selected {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            border-color: #4facfe;
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

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .fg {
                width: 100%;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .data-table tbody td {
                padding: 8px 10px;
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
                        <h1><i class="fas fa-star-half-alt me-2"></i>Behaviour</h1>
                        <p>Per-term conduct score derived from discipline, attendance and teacher checklist</p>
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
                                <?php echo (int)$totalRows; ?> row(s) match the current filters ·
                                <?php echo (int)$passCount; ?> passing ·
                                <?php echo (int)$byStatus['finalised']; ?> finalised
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Finalised rows feed promotion
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
                        <div class="ib-title">How behaviour works</div>
                        <div class="ib-text">
                            The conduct score starts at <strong>100</strong> and is reduced by:
                            <strong>discipline</strong> (minor −1, moderate −3, major −10; floor 0),
                            <strong>attendance</strong> (−1 per unexcused absence, −0.5 per late; cap 20).
                            The class-teacher checklist (punctuality, respect, participation 1–5) adds
                            0–20. Score bands: <em>Excellent ≥ 90</em>, <em>Very Good ≥ 80</em>, <em>Good ≥ 70</em>,
                            <em>Fair ≥ 60</em>, <em>Poor &lt; 60</em>. Pass ≥ 60.
                            Rows are <strong>draft</strong> until finalised, and only finalised rows feed
                            the promotion run. Regeneration is blocked once a row is finalised.
                        </div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$totalRows; ?></div>
                        <div class="stat-lbl">Rows</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byStatus['draft']; ?></div>
                        <div class="stat-lbl">Draft</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byStatus['finalised']; ?></div>
                        <div class="stat-lbl">Finalised</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byGrade['Excellent']; ?></div>
                        <div class="stat-lbl">Excellent</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byGrade['Good']; ?></div>
                        <div class="stat-lbl">Good</div>
                    </div>
                    <div class="stat-cell">
                        <div class="stat-num"><?php echo (int)$byGrade['Poor']; ?></div>
                        <div class="stat-lbl">Poor</div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/students/behaviour.php" class="filters-bar" id="behaviourFilterForm">
                    <input type="hidden" name="student_id" id="filterStudentId" value="<?php echo (int)$filterStudent; ?>">

                    <div class="fg">
                        <label><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label><?php echo h($labelTerm); ?></label>
                        <select name="term_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($t['term_name']); ?><?php if ((int)$t['is_current'] === 1) echo ' ★'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label><?php echo h($labelClass); ?></label>
                        <select name="class_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterClass === (int)$c['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($c['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fg">
                        <label>Student</label>
                        <div class="student-search-wrap">
                            <input type="text" class="form-control" id="filterStudentSearch"
                                autocomplete="off" placeholder="Type name or student number…"
                                value="<?php echo h($filterStudentLabel); ?>">
                            <button type="button" class="student-search-clear" id="filterStudentClear"
                                title="Clear" style="<?php echo $filterStudent > 0 ? '' : 'display:none;'; ?>">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="student-search-results" id="filterStudentResults"></div>
                        </div>
                    </div>

                    <div class="fg">
                        <label>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="draft" <?php echo $filterStatus === 'draft'     ? 'selected' : ''; ?>>Draft</option>
                            <option value="finalised" <?php echo $filterStatus === 'finalised' ? 'selected' : ''; ?>>Finalised</option>
                        </select>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Apply</button>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <a href="/platform/tenant/students/behaviour.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                    <div class="fg" style="flex:0 0 auto;">
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#bulkModal">
                            <i class="fas fa-layer-group me-1"></i> Bulk generate
                        </button>
                    </div>
                </form>

                <?php if (empty($byClass)): ?>
                    <div class="level-section">
                        <div class="empty-state">
                            <i class="fas fa-star-half-alt"></i>
                            <h5>No behaviour rows match the current filters</h5>
                            <p>Use <strong>Bulk generate</strong> above, or pick a single student to generate one row.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($byClass as $classGroup): ?>
                        <div class="level-section">
                            <div class="level-header">
                                <div class="level-title">
                                    <i class="fas fa-door-open" style="font-size:18px;color:#4facfe;"></i>
                                    <h5><?php echo h($classGroup['class_name']); ?></h5>
                                    <?php if (!empty($classGroup['class_code'])): ?>
                                        <span class="pill blue"><?php echo h($classGroup['class_code']); ?></span>
                                    <?php endif; ?>
                                    <span class="pill gray"><?php echo count($classGroup['items']); ?> student(s)</span>
                                </div>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th style="text-align:center;width:140px;">Incidents (m/M/M)</th>
                                        <th style="text-align:center;width:110px;">Attendance</th>
                                        <th style="text-align:center;width:110px;">Checklist</th>
                                        <th style="text-align:center;width:110px;">Score</th>
                                        <th style="text-align:center;width:120px;">Grade</th>
                                        <th style="text-align:center;width:100px;">Status</th>
                                        <th style="text-align:right;min-width:260px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classGroup['items'] as $r):
                                        $rid  = (int)$r['id'];
                                        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                        if ($name === '') $name = 'Student #' . (int)$r['student_id'];
                                        $score = (float)$r['final_score'];
                                        $grade = (string)$r['final_grade'];
                                        $isFinalised = $r['status'] === 'finalised';
                                        $hasOverride = $r['override_score'] !== null;
                                    ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;"><?php echo h($name); ?></div>
                                                <div style="font-size:11px;color:#6c757d;font-family:'Courier New',monospace;">
                                                    <?php echo h($r['student_number']); ?>
                                                </div>
                                            </td>
                                            <td style="text-align:center;font-size:12px;">
                                                <span class="pill blue" title="Minor"><?php echo (int)$r['incidents_minor']; ?></span>
                                                <span class="pill orange" title="Moderate"><?php echo (int)$r['incidents_moderate']; ?></span>
                                                <span class="pill red" title="Major"><?php echo (int)$r['incidents_major']; ?></span>
                                            </td>
                                            <td style="text-align:center;font-size:12px;">
                                                <?php if ((float)$r['attendance_deduction'] > 0): ?>
                                                    <span class="pill red">−<?php echo number_format((float)$r['attendance_deduction'], 2); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#adb5bd;">0</span>
                                                <?php endif; ?>
                                                <div style="font-size:10px;color:#6c757d;">
                                                    <?php echo (int)$r['absence_count']; ?> abs · <?php echo (int)$r['late_count']; ?> late
                                                </div>
                                            </td>
                                            <td style="text-align:center;font-size:12px;">
                                                <?php if ((float)$r['checklist_bonus'] > 0): ?>
                                                    <span class="pill green">+<?php echo number_format((float)$r['checklist_bonus'], 2); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#adb5bd;">0</span>
                                                <?php endif; ?>
                                                <div style="font-size:10px;color:#6c757d;">
                                                    <?php
                                                    $rts = [];
                                                    if ($r['rating_punctuality'] !== null)   $rts[] = 'P' . (int)$r['rating_punctuality'];
                                                    if ($r['rating_respect'] !== null)       $rts[] = 'R' . (int)$r['rating_respect'];
                                                    if ($r['rating_participation'] !== null) $rts[] = 'W' . (int)$r['rating_participation'];
                                                    echo h(implode(' ', $rts));
                                                    ?>
                                                </div>
                                            </td>
                                            <td style="text-align:center;">
                                                <div class="score-cell"><?php echo number_format($score, 1); ?></div>
                                                <?php if ($hasOverride): ?>
                                                    <div style="font-size:10px;color:#6c757d;">
                                                        computed <?php echo number_format((float)$r['computed_score'], 1); ?>
                                                        <i class="fas fa-pen-fancy ms-1" title="Overridden"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="pill <?php echo conductPill($grade); ?>">
                                                    <?php echo h($grade); ?>
                                                </span>
                                                <?php if ((int)$r['is_pass'] !== 1): ?>
                                                    <div style="font-size:10px;color:#dc3545;margin-top:2px;">FAIL</div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="pill <?php echo statusPill($r['status']); ?>">
                                                    <?php echo h(ucfirst($r['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                    <?php if (!$isFinalised): ?>
                                                        <a href="/platform/tenant/students/behaviour.php?action=checklist&id=<?php echo $rid; ?>"
                                                            class="btn btn-outline-primary btn-sm" title="Checklist">
                                                            <i class="fas fa-list-check"></i>
                                                        </a>
                                                        <a href="/platform/tenant/students/behaviour.php?action=override&id=<?php echo $rid; ?>"
                                                            class="btn btn-outline-warning btn-sm" title="Override">
                                                            <i class="fas fa-pen-fancy"></i>
                                                        </a>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Regenerate this row from current inputs?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="generate_one">
                                                            <input type="hidden" name="student_id" value="<?php echo (int)$r['student_id']; ?>">
                                                            <input type="hidden" name="academic_year_id" value="<?php echo (int)$r['academic_year_id']; ?>">
                                                            <input type="hidden" name="academic_term_id" value="<?php echo (int)$r['academic_term_id']; ?>">
                                                            <button type="submit" class="btn btn-outline-info btn-sm" title="Regenerate">
                                                                <i class="fas fa-sync"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($hasOverride && !$isFinalised): ?>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Clear the override and revert to computed score?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="clear_override">
                                                            <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Clear override">
                                                                <i class="fas fa-eraser"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (!$isFinalised): ?>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Finalise this row? Regeneration will be blocked.');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="finalise">
                                                            <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Finalise">
                                                                <i class="fas fa-lock"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Reopen this row for editing?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="reopen">
                                                            <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                            <button type="submit" class="btn btn-outline-dark btn-sm" title="Reopen">
                                                                <i class="fas fa-lock-open"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (!$isFinalised): ?>
                                                        <form method="POST" style="display:inline-block;"
                                                            onsubmit="return confirm('Delete this behaviour row?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                                <i class="fas fa-trash"></i>
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
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="modal fade" id="bulkModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5><i class="fas fa-layer-group text-primary me-2"></i>Bulk Generate Behaviour</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:13px;color:#495057;">
                        Generate behaviour rows for many students at once. Existing <strong>draft</strong> rows
                        are regenerated; <strong>finalised</strong> rows are left untouched.
                    </p>

                    <form method="POST" action="/platform/tenant/students/behaviour.php" class="mb-3"
                        onsubmit="return confirm('Generate for every student in the selected class for the selected term?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate_class">
                        <div class="mb-2">
                            <label class="form-label">Per class</label>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <select name="class_id" class="form-select" required>
                                    <option value="">— Pick a class —</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select name="academic_year_id" class="form-select" required>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($y['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select name="academic_term_id" class="form-select" required>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($t['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="fas fa-play me-1"></i> Generate for class
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr>

                    <form method="POST" action="/platform/tenant/students/behaviour.php"
                        onsubmit="return confirm('Generate for every enrolled student in the selected year for the selected term? This can take a moment.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate_year">
                        <div class="mb-2">
                            <label class="form-label">Per year (all classes)</label>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <select name="academic_year_id" class="form-select" required>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($y['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select name="academic_term_id" class="form-select" required>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($t['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                    <i class="fas fa-globe me-1"></i> Generate for entire year
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr>

                    <form method="POST" action="/platform/tenant/students/behaviour.php"
                        onsubmit="return confirm('Generate for the selected student for the selected term?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate_one">
                        <div class="mb-2">
                            <label class="form-label">Per student</label>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <div class="student-search-wrap">
                                    <input type="text" class="form-control" id="bulkStudentSearch"
                                        autocomplete="off" placeholder="Type name or student number…">
                                    <input type="hidden" name="student_id" id="bulkStudentId" value="">
                                    <div class="student-search-results" id="bulkStudentResults"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <select name="academic_year_id" class="form-select" required>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($y['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <select name="academic_term_id" class="form-select" required>
                                    <?php foreach ($terms as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($t['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                    <i class="fas fa-user-check me-1"></i> Generate for student
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($editRow):
        $isFinalised = $editRow['status'] === 'finalised';
        $editStu = $db->fetchOne(
            "SELECT first_name, middle_name, last_name, student_number FROM students
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [(int)$editRow['student_id'], $tenantId]
        );
        $editName = $editStu ? trim(($editStu['first_name'] ?? '') . ' ' . ($editStu['last_name'] ?? '')) : 'Student';
    ?>
        <div class="modal fade show" id="checklistModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/students/behaviour.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_checklist">
                        <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-list-check text-primary me-2"></i>Teacher Checklist — <?php echo h($editName); ?></h5>
                            <a href="/platform/tenant/students/behaviour.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <?php if ($isFinalised): ?>
                                <div class="alert alert-warning" style="font-size:13px;">
                                    This row is finalised. Reopen it first to edit the checklist.
                                </div>
                            <?php endif; ?>

                            <p style="font-size:12px;color:#6c757d;">
                                Rate each item from 1 (poor) to 5 (excellent). The average maps to a bonus of 0–20 points.
                            </p>

                            <?php
                            $rP = $editRow['rating_punctuality'] !== null ? (int)$editRow['rating_punctuality'] : 3;
                            $rR = $editRow['rating_respect']     !== null ? (int)$editRow['rating_respect']     : 3;
                            $rW = $editRow['rating_participation'] !== null ? (int)$editRow['rating_participation'] : 3;
                            $items = [
                                'rating_punctuality'   => ['Punctuality',   $rP],
                                'rating_respect'       => ['Respect',       $rR],
                                'rating_participation' => ['Participation', $rW],
                            ];
                            foreach ($items as $fname => $pair):
                                list($label, $val) = $pair;
                            ?>
                                <div class="rating-row mb-3" data-field="<?php echo h($fname); ?>">
                                    <div class="rating-label"><?php echo h($label); ?></div>
                                    <div class="rating-options">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <div class="rating-pill <?php echo $i === $val ? 'selected' : ''; ?>"
                                                data-value="<?php echo $i; ?>"><?php echo $i; ?></div>
                                        <?php endfor; ?>
                                    </div>
                                    <input type="hidden" name="<?php echo h($fname); ?>" value="<?php echo $val; ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/students/behaviour.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" <?php echo $isFinalised ? 'disabled' : ''; ?>>
                                <i class="fas fa-save me-1"></i> Save Checklist
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($overrideRow):
        $ovStu = $db->fetchOne(
            "SELECT first_name, middle_name, last_name, student_number FROM students
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [(int)$overrideRow['student_id'], $tenantId]
        );
        $ovName = $ovStu ? trim(($ovStu['first_name'] ?? '') . ' ' . ($ovStu['last_name'] ?? '')) : 'Student';
    ?>
        <div class="modal fade show" id="overrideModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/students/behaviour.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="override">
                        <input type="hidden" name="id" value="<?php echo (int)$overrideRow['id']; ?>">
                        <div class="modal-header">
                            <h5><i class="fas fa-pen-fancy text-warning me-2"></i>Override Conduct Score — <?php echo h($ovName); ?></h5>
                            <a href="/platform/tenant/students/behaviour.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <p style="font-size:13px;color:#495057;">
                                Computed score is <strong><?php echo number_format((float)$overrideRow['computed_score'], 2); ?></strong>
                                (<?php echo h($overrideRow['computed_grade']); ?>).
                                The override replaces the score used for grading and promotion.
                            </p>
                            <div class="mb-3">
                                <label class="form-label" for="ov_score">Override score (0–100) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="ov_score"
                                    name="override_score" required
                                    value="<?php echo $overrideRow['override_score'] !== null ? h($overrideRow['override_score']) : h($overrideRow['computed_score']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ov_reason">Reason <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="ov_reason" name="override_reason" required
                                    placeholder="Why is the computed score being overridden?"><?php echo h($overrideRow['override_reason']); ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/students/behaviour.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-warning text-dark">
                                <i class="fas fa-save me-1"></i> Apply Override
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/notifications-bell.js" defer></script>
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
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/logout.php';
        }

        document.querySelectorAll('.rating-row').forEach(row => {
            const hidden = row.querySelector('input[type="hidden"]');
            row.querySelectorAll('.rating-pill').forEach(pill => {
                pill.addEventListener('click', () => {
                    row.querySelectorAll('.rating-pill').forEach(p => p.classList.remove('selected'));
                    pill.classList.add('selected');
                    if (hidden) hidden.value = pill.getAttribute('data-value');
                });
            });
        });

        function escapeHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function makeStudentSearch(opts) {
            const input = document.getElementById(opts.inputId);
            const resultsEl = document.getElementById(opts.resultsId);
            const hiddenInput = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
            if (!input || !resultsEl) return null;

            let lastQuery = '',
                lastItems = [],
                activeIdx = -1,
                debounce = null,
                abortCtrl = null,
                reqToken = 0;

            function close() {
                resultsEl.classList.remove('open');
                resultsEl.innerHTML = '';
                activeIdx = -1;
            }

            function open(items) {
                lastItems = items;
                if (!items.length) {
                    resultsEl.innerHTML = '<div class="ssr-empty">No students found</div>';
                    resultsEl.classList.add('open');
                    activeIdx = -1;
                    return;
                }
                resultsEl.innerHTML = items.map((it, i) =>
                    `<div class="ssr-item" data-idx="${i}" data-id="${it.id}" data-label="${escapeHtml(it.label)}">
                        <div class="ssr-name">${escapeHtml(it.name)}</div>
                        <div class="ssr-num">${escapeHtml(it.num)}</div>
                    </div>`
                ).join('');
                resultsEl.classList.add('open');
                activeIdx = -1;
            }

            function highlight() {
                resultsEl.querySelectorAll('.ssr-item').forEach(el => el.classList.remove('active'));
                if (activeIdx >= 0) {
                    const el = resultsEl.querySelector(`.ssr-item[data-idx="${activeIdx}"]`);
                    if (el) {
                        el.classList.add('active');
                        el.scrollIntoView({
                            block: 'nearest'
                        });
                    }
                }
            }

            function pick(item) {
                input.value = item.label;
                if (hiddenInput) hiddenInput.value = item.id;
                close();
                if (typeof opts.onPick === 'function') opts.onPick(item);
            }

            function fetchResults(q) {
                if (q === lastQuery) return;
                lastQuery = q;
                if (abortCtrl) abortCtrl.abort();
                abortCtrl = new AbortController();
                const myToken = ++reqToken;
                fetch('/platform/tenant/students/behaviour.php?ajax_search_students=1&q=' + encodeURIComponent(q), {
                        signal: abortCtrl.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (myToken !== reqToken) return;
                        if (!data || !data.ok) {
                            close();
                            return;
                        }
                        open(data.items || []);
                    })
                    .catch(err => {
                        if (err && err.name === 'AbortError') return;
                        close();
                    });
            }
            input.addEventListener('input', function() {
                const q = this.value.trim();
                if (hiddenInput) hiddenInput.value = '';
                if (debounce) clearTimeout(debounce);
                if (q.length < 2) {
                    close();
                    return;
                }
                debounce = setTimeout(() => fetchResults(q), 250);
            });
            input.addEventListener('keydown', function(e) {
                if (!resultsEl.classList.contains('open')) return;
                const count = lastItems.length;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % count;
                    highlight();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = (activeIdx - 1 + count) % count;
                    highlight();
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0 && lastItems[activeIdx]) {
                        e.preventDefault();
                        pick(lastItems[activeIdx]);
                    }
                } else if (e.key === 'Escape') {
                    close();
                }
            });
            resultsEl.addEventListener('mousedown', function(e) {
                const item = e.target.closest('.ssr-item');
                if (!item) return;
                e.preventDefault();
                const idx = parseInt(item.getAttribute('data-idx'), 10);
                if (lastItems[idx]) pick(lastItems[idx]);
            });
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !resultsEl.contains(e.target)) close();
            });
            return {
                setValue(label, id) {
                    input.value = label || '';
                    if (hiddenInput) hiddenInput.value = id || '';
                }
            };
        }

        (function() {
            const form = document.getElementById('behaviourFilterForm');
            const hiddenId = document.getElementById('filterStudentId');
            const clearBtn = document.getElementById('filterStudentClear');
            if (!form || !hiddenId) return;
            makeStudentSearch({
                inputId: 'filterStudentSearch',
                resultsId: 'filterStudentResults',
                onPick: function(item) {
                    hiddenId.value = item.id;
                    form.submit();
                }
            });
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    document.getElementById('filterStudentSearch').value = '';
                    hiddenId.value = '0';
                    form.submit();
                });
            }
        })();

        (function() {
            const input = document.getElementById('bulkStudentSearch');
            const hid = document.getElementById('bulkStudentId');
            if (!input || !hid) return;
            makeStudentSearch({
                inputId: 'bulkStudentSearch',
                resultsId: 'bulkStudentResults',
                hiddenId: 'bulkStudentId'
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