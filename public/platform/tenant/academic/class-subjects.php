<?php

/**
 * Academic Class Subjects — Subjects taught in a specific class offering
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/class-subjects.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic class-subjects file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_class_subjects'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
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
 * Decisions locked in (Session S11):
 *   D1  class_offering_id + subject_id required; is_core, weekly_periods,
 *       is_active optional
 *   D2  unique per (tenant, class_offering_id, subject_id) via FOR UPDATE
 *   D3  no feature gate
 *   D4  no orphans
 *   D5  refuse delete, check results + teacher_class_assignments for
 *       matching (tenant, class_offering_id, subject_id)
 *   D6  offering-centric grouping (year -> level -> offering)
 *   D7  bulk-import-from-curriculum button inside the Manage modal
 *   D8  modal-per-offering with multi-select + per-row weekly_periods
 *   D9  weekly_periods optional
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it (including JS-built forms)
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value
 *   SH6B tenant_id scoping and deleted_at IS NULL on every query
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
$pageTitle    = 'Class Subjects - Student 360 Platform';
$currentPage = 'academic';
// ============================================
// HELPERS
// ============================================
// h() is defined in Security.php; do not redeclare it here.
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
function classSubjectDependencies($db, int $tenantId, int $classOfferingId, int $subjectId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'teacher assignments' => "SELECT COUNT(*) AS c FROM teacher_class_assignments
                                  WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ?
                                    AND deleted_at IS NULL",
        'results'             => "SELECT COUNT(*) AS c FROM results
                                  WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ?
                                    AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $classOfferingId, $subjectId]);
            $c = (int)($row['c'] ?? 0);
            if ($c > 0) {
                $total += $c;
                $labels[] = $c . ' ' . $label;
            }
        } catch (Exception $e) {
            // table not yet present
        }
    }
    return ['total' => $total, 'labels' => $labels];
}
function getOffering($db, int $tenantId, int $offeringId): ?array
{
    if ($offeringId <= 0) return null;
    return $db->fetchOne(
        "SELECT co.id, co.tenant_id, co.class_id, co.academic_level_id, co.academic_year_id,
                co.stream_id, co.status,
                c.class_name, c.class_code,
                al.level_name, al.level_code,
                ay.year_name,
                s.stream_name
         FROM class_offerings co
         LEFT JOIN classes c ON co.class_id = c.id
         LEFT JOIN academic_levels al ON co.academic_level_id = al.id
         LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
         LEFT JOIN streams s ON co.stream_id = s.id
         WHERE co.id = ? AND co.tenant_id = ? AND co.deleted_at IS NULL",
        [$offeringId, $tenantId]
    ) ?: null;
}
// ============================================
// ACTIONS (POST)
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // S20 SH2A — all POSTs must carry the session CSRF token
    $formData = $_POST;
    try {
        // ---------- BULK SAVE ----------
        if ($action === 'bulk_save') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) {
                throw new Exception('Class offering not found.');
            }
            $subjectIds     = is_array($_POST['subject_id']     ?? null) ? $_POST['subject_id']     : [];
            $weeklyPeriods  = is_array($_POST['weekly_periods'] ?? null) ? $_POST['weekly_periods'] : [];
            $isCoreFlags    = is_array($_POST['is_core']        ?? null) ? $_POST['is_core']        : [];
            $incoming = [];
            foreach ($subjectIds as $idx => $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;
                $wp = isset($weeklyPeriods[$idx]) && $weeklyPeriods[$idx] !== ''
                    ? max(0, (int)$weeklyPeriods[$idx]) : null;
                $ic = !empty($isCoreFlags[$idx]) ? 1 : 0;
                $incoming[$sid] = ['weekly_periods' => $wp, 'is_core' => $ic];
            }
            if (empty($incoming)) {
                throw new Exception('Please select at least one subject to add.');
            }
            $idsSql = implode(',', array_fill(0, count($incoming), '?'));
            $placeholders = $idsSql;
            $subjectRows = $db->fetchAll(
                "SELECT id, subject_name, subject_code, academic_level_id
                 FROM subjects
                 WHERE tenant_id = ? AND deleted_at IS NULL AND id IN ($placeholders)",
                array_merge([$tenantId], array_keys($incoming))
            );
            $validSubjectIds = [];
            foreach ($subjectRows as $sr) {
                $validSubjectIds[(int)$sr['id']] = $sr;
            }
            foreach ($incoming as $sid => $_) {
                if (!isset($validSubjectIds[$sid])) {
                    $errors[] = "Subject id $sid not found.";
                }
            }
            if (!empty($errors)) {
                throw new Exception(implode(' ', $errors));
            }
            $db->beginTransaction();
            $addedCount = 0;
            $updatedCount = 0;
            foreach ($incoming as $sid => $meta) {
                $existing = $db->fetchOne(
                    "SELECT id, is_active, weekly_periods, is_core, deleted_at
                     FROM class_subjects
                     WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ?
                     FOR UPDATE",
                    [$tenantId, $offeringId, $sid]
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE class_subjects
                            SET is_core = ?,
                                weekly_periods = ?,
                                is_active = 1,
                                deleted_at = NULL,
                                updated_at = NOW()
                          WHERE id = ? AND tenant_id = ?",
                        [$meta['is_core'], $meta['weekly_periods'], (int)$existing['id'], $tenantId]
                    );
                    $updatedCount++;
                } else {
                    $newId = $db->insert(
                        "INSERT INTO class_subjects
                            (uuid, tenant_id, class_offering_id, subject_id,
                             is_core, weekly_periods, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $offeringId,
                            $sid,
                            $meta['is_core'],
                            $meta['weekly_periods'],
                        ]
                    );
                    $addedCount++;
                }
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_subject.bulk_saved',
                'class_subjects',
                $offeringId,
                [
                    'offering_id' => $offeringId,
                    'added'       => $addedCount,
                    'updated'     => $updatedCount,
                    'subjects'    => array_keys($incoming),
                ]
            );
            $db->commit();
            $_SESSION['success'] = sprintf(
                'Saved. %d added, %d updated.',
                $addedCount,
                $updatedCount
            );
            header('Location: /platform/tenant/academic/class-subjects.php');
            exit;
        }
        // ---------- IMPORT FROM CURRICULUM ----------
        if ($action === 'import_from_curriculum') {
            $offeringId = (int)($_POST['class_offering_id'] ?? 0);
            $offering = getOffering($db, $tenantId, $offeringId);
            if (!$offering) {
                throw new Exception('Class offering not found.');
            }
            $levelId = (int)($offering['academic_level_id'] ?? 0);
            $yearId  = (int)($offering['academic_year_id'] ?? 0);
            if ($levelId <= 0) {
                throw new Exception('This offering has no academic level set.');
            }
            $currRows = $db->fetchAll(
                "SELECT subject_id, is_core
                 FROM curriculum
                 WHERE tenant_id = ?
                   AND academic_level_id = ?
                   AND (academic_year_id IS NULL OR academic_year_id = ?)
                   AND is_active = 1
                   AND deleted_at IS NULL
                 ORDER BY sort_order ASC, id ASC",
                [$tenantId, $levelId, $yearId]
            );
            if (empty($currRows)) {
                throw new Exception('No curriculum entries found for this offering\'s level and year.');
            }
            $db->beginTransaction();
            $addedCount = 0;
            $skippedCount = 0;
            foreach ($currRows as $cr) {
                $sid = (int)$cr['subject_id'];
                if ($sid <= 0) continue;
                $existing = $db->fetchOne(
                    "SELECT id, deleted_at FROM class_subjects
                     WHERE tenant_id = ? AND class_offering_id = ? AND subject_id = ?
                     FOR UPDATE",
                    [$tenantId, $offeringId, $sid]
                );
                if ($existing && empty($existing['deleted_at'])) {
                    $skippedCount++;
                    continue;
                }
                if ($existing) {
                    $db->execute(
                        "UPDATE class_subjects
                            SET is_core = ?,
                                is_active = 1,
                                deleted_at = NULL,
                                updated_at = NOW()
                          WHERE id = ? AND tenant_id = ?",
                        [(int)$cr['is_core'], (int)$existing['id'], $tenantId]
                    );
                    $addedCount++;
                } else {
                    $db->insert(
                        "INSERT INTO class_subjects
                            (uuid, tenant_id, class_offering_id, subject_id,
                             is_core, weekly_periods, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, NULL, 1, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $offeringId,
                            $sid,
                            (int)$cr['is_core'],
                        ]
                    );
                    $addedCount++;
                }
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_subject.imported_from_curriculum',
                'class_subjects',
                $offeringId,
                [
                    'offering_id' => $offeringId,
                    'added'       => $addedCount,
                    'skipped'     => $skippedCount,
                ]
            );
            $db->commit();
            $_SESSION['success'] = sprintf(
                'Imported from curriculum: %d added, %d already present.',
                $addedCount,
                $skippedCount
            );
            header('Location: /platform/tenant/academic/class-subjects.php');
            exit;
        }
        // ---------- UPDATE ----------
        if ($action === 'update') {
            $id         = (int)($_POST['id'] ?? 0);
            $isCore     = !empty($_POST['is_core']) ? 1 : 0;
            $active     = !empty($_POST['is_active']) ? 1 : 0;
            $wpRaw      = $_POST['weekly_periods'] ?? '';
            $weeklyPeriods = ($wpRaw === '' || $wpRaw === null) ? null : max(0, (int)$wpRaw);
            $row = $db->fetchOne(
                "SELECT * FROM class_subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Class subject not found.');
            $db->beginTransaction();
            $db->execute(
                "UPDATE class_subjects
                    SET is_core = ?,
                        weekly_periods = ?,
                        is_active = ?,
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$isCore, $weeklyPeriods, $active, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_subject.updated',
                'class_subjects',
                $id,
                [
                    'is_core'        => $isCore,
                    'weekly_periods' => $weeklyPeriods,
                    'is_active'      => $active,
                ]
            );
            $db->commit();
            $_SESSION['success'] = 'Class subject updated.';
            header('Location: /platform/tenant/academic/class-subjects.php');
            exit;
        }
        // ---------- TOGGLE ACTIVE ----------
        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT * FROM class_subjects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Class subject not found.');
            $newActive = ((int)$row['is_active']) === 1 ? 0 : 1;
            $db->beginTransaction();
            $db->execute(
                "UPDATE class_subjects SET is_active = ?, updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$newActive, $id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_subject.toggled_active',
                'class_subjects',
                $id,
                ['is_active' => $newActive]
            );
            $db->commit();
            $_SESSION['success'] = 'Class subject is now ' . ($newActive ? 'active' : 'inactive') . '.';
            header('Location: /platform/tenant/academic/class-subjects.php');
            exit;
        }
        // ---------- DELETE ----------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetchOne(
                "SELECT cs.*, s.subject_name
                 FROM class_subjects cs
                 LEFT JOIN subjects s ON cs.subject_id = s.id
                 WHERE cs.id = ? AND cs.tenant_id = ? AND cs.deleted_at IS NULL",
                [$id, $tenantId]
            );
            if (!$row) throw new Exception('Class subject not found.');
            $deps = classSubjectDependencies(
                $db,
                $tenantId,
                (int)$row['class_offering_id'],
                (int)$row['subject_id']
            );
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot remove "' . ($row['subject_name'] ?: 'this subject') . '" from this offering. ' .
                        'It is referenced by: ' . implode(', ', $deps['labels']) .
                        '. Remove those references first, or deactivate the class subject instead.'
                );
            }
            $db->beginTransaction();
            $db->execute(
                "UPDATE class_subjects SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.class_subject.deleted',
                'class_subjects',
                $id,
                [
                    'class_offering_id' => (int)$row['class_offering_id'],
                    'subject_id'        => (int)$row['subject_id'],
                ]
            );
            $db->commit();
            $_SESSION['success'] = 'Class subject removed from this offering.';
            header('Location: /platform/tenant/academic/class-subjects.php');
            exit;
        }
        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic class-subjects action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/class-subjects.php');
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
$offerings = $db->fetchAll(
    "SELECT co.id, co.status,
            co.academic_year_id, co.academic_level_id, co.class_id, co.stream_id,
            ay.year_name,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            c.class_name, c.class_code,
            s.stream_name,
            (SELECT COUNT(*) FROM class_subjects cs
             WHERE cs.tenant_id = co.tenant_id
               AND cs.class_offering_id = co.id
               AND cs.is_active = 1
               AND cs.deleted_at IS NULL) AS active_subject_count,
            (SELECT COUNT(*) FROM class_subjects cs
             WHERE cs.tenant_id = co.tenant_id
               AND cs.class_offering_id = co.id
               AND cs.deleted_at IS NULL) AS total_subject_count
     FROM class_offerings co
     LEFT JOIN academic_years ay ON co.academic_year_id = ay.id
     LEFT JOIN academic_levels al ON co.academic_level_id = al.id
     LEFT JOIN classes c ON co.class_id = c.id
     LEFT JOIN streams s ON co.stream_id = s.id
     WHERE co.tenant_id = ? AND co.deleted_at IS NULL
     ORDER BY ay.start_date DESC, al.sort_order ASC, c.class_name ASC, s.stream_name ASC",
    [$tenantId]
);
$byYear = [];
foreach ($offerings as $o) {
    $yId = (int)$o['academic_year_id'];
    if (!isset($byYear[$yId])) {
        $byYear[$yId] = [
            'year_name' => $o['year_name'] ?: '(Unknown year)',
            'levels'    => [],
        ];
    }
    $lId = (int)$o['academic_level_id'];
    if (!isset($byYear[$yId]['levels'][$lId])) {
        $byYear[$yId]['levels'][$lId] = [
            'level_name' => $o['level_name'] ?: '(Unknown level)',
            'level_code' => $o['level_code'] ?? '',
            'items'      => [],
        ];
    }
    $byYear[$yId]['levels'][$lId]['items'][] = $o;
}
$existingByOffering = [];
if (!empty($offerings)) {
    $offeringIds = array_map(function ($o) {
        return (int)$o['id'];
    }, $offerings);
    $in = implode(',', array_fill(0, count($offeringIds), '?'));
    $rows = $db->fetchAll(
        "SELECT cs.id, cs.class_offering_id, cs.subject_id,
                cs.is_core, cs.weekly_periods, cs.is_active,
                s.subject_name, s.subject_code, s.academic_level_id AS subject_level_id
         FROM class_subjects cs
         LEFT JOIN subjects s ON cs.subject_id = s.id
         WHERE cs.tenant_id = ?
           AND cs.class_offering_id IN ($in)
           AND cs.deleted_at IS NULL
         ORDER BY s.subject_name ASC",
        array_merge([$tenantId], $offeringIds)
    );
    foreach ($rows as $r) {
        $oid = (int)$r['class_offering_id'];
        if (!isset($existingByOffering[$oid])) $existingByOffering[$oid] = [];
        $existingByOffering[$oid][] = $r;
    }
}
$allSubjects = $db->fetchAll(
    "SELECT id, subject_name, subject_code, academic_level_id
     FROM subjects
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY subject_name ASC",
    [$tenantId]
);
$subjectsByLevel = [];
foreach ($offerings as $o) {
    $lid = (int)$o['academic_level_id'];
    if (isset($subjectsByLevel[$lid])) continue;
    $subjectsByLevel[$lid] = [];
    foreach ($allSubjects as $s) {
        $sLid = (int)($s['academic_level_id'] ?? 0);
        if ($sLid === 0 || $sLid === $lid) {
            $subjectsByLevel[$lid][] = $s;
        }
    }
}
$totalOfferings = count($offerings);
$totalActiveSubjects = 0;
$offeringsWithSubjects = 0;
foreach ($offerings as $o) {
    $totalActiveSubjects += (int)$o['active_subject_count'];
    if ((int)$o['active_subject_count'] > 0) $offeringsWithSubjects++;
}
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}
// Pre-compute the two JSON maps used by the modal, with safe fallbacks.
$subjectsByLevelJson = json_encode($subjectsByLevel, JSON_UNESCAPED_UNICODE);
if (!is_string($subjectsByLevelJson) || $subjectsByLevelJson === '') {
    $subjectsByLevelJson = '{}';
}
$existingByOfferingJson = json_encode($existingByOffering, JSON_UNESCAPED_UNICODE);
if (!is_string($existingByOfferingJson) || $existingByOfferingJson === '') {
    $existingByOfferingJson = '{}';
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

        .btn-outline-success {
            background: transparent;
            border: 2px solid #28a745;
            color: #28a745;
            border-radius: 10px;
            padding: 6px 14px;
            font-weight: 500;
            font-size: 12px;
        }

        .btn-outline-success:hover {
            background: #28a745;
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
            max-width: 1100px;
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
            max-width: 1100px;
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

        .year-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .year-section .year-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
        }

        .year-section .year-header .year-title {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .year-section .year-header .year-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 18px;
            color: #1a1a2e;
        }

        .level-block {
            border-bottom: 1px solid #f0f2f5;
        }

        .level-block:last-child {
            border-bottom: none;
        }

        .level-block .level-bar {
            padding: 10px 24px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            border-bottom: 1px solid #f0f2f5;
        }

        .level-block .level-bar h6 {
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

        .offering-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .offering-table thead th {
            background: #f8f9fa;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }

        .offering-table tbody td {
            padding: 12px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .offering-table tbody tr:last-child td {
            border-bottom: none;
        }

        .offering-table tbody tr:hover {
            background: #fafbfc;
        }

        .offering-title {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .offering-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .empty-row {
            padding: 24px 20px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
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
            max-width: 1100px;
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

        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1) !important;
            background-color: #fff8f8 !important;
        }

        .modal-lg {
            max-width: 780px;
        }

        .subject-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            max-height: 420px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
        }

        .subject-row {
            display: grid;
            grid-template-columns: 32px 1fr 140px 100px;
            gap: 10px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            transition: background 0.15s;
        }

        .subject-row:hover {
            background: #fff;
        }

        .subject-row.already-present {
            background: #f8f9fa;
            opacity: 0.85;
        }

        .subject-row.already-present .subject-title::after {
            content: ' (already in this offering)';
            color: #6c757d;
            font-style: italic;
            font-weight: 400;
            font-size: 11px;
        }

        .subject-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .subject-title {
            font-size: 13px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .subject-code {
            display: inline-block;
            background: #e3f0ff;
            color: #0d6efd;
            padding: 1px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            margin-left: 6px;
        }

        .subject-row .wp-input,
        .subject-row .core-check {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .subject-row .wp-input input {
            height: 32px;
            padding: 4px 8px;
            font-size: 12px;
        }

        .subject-row .core-check {
            justify-content: flex-start;
            font-size: 12px;
            color: #495057;
        }

        .subject-row .core-check input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .modal-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .modal-toolbar .search-input {
            flex: 1;
            min-width: 180px;
        }

        .modal-toolbar .search-input input {
            height: 36px;
            padding: 6px 12px;
            font-size: 13px;
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

        .existing-panel {
            margin-top: 18px;
            border-top: 1px solid #f0f2f5;
            padding-top: 14px;
        }

        .existing-panel h6 {
            font-weight: 700;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0 0 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .existing-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .existing-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
        }

        .existing-item .info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .existing-item .info .title {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
        }

        .existing-item .info .meta {
            font-size: 11px;
            color: #6c757d;
        }

        .existing-item .actions {
            display: flex;
            gap: 4px;
        }

        .existing-item .actions .btn {
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 6px;
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

            .offering-table {
                font-size: 12px;
            }

            .offering-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .offering-table tbody td {
                padding: 8px 10px;
            }

            .subject-row {
                grid-template-columns: 28px 1fr;
                grid-template-areas: "chk title" ". wp" ". core";
            }

            .subject-row .chk {
                grid-area: chk;
            }

            .subject-row .title-wrap {
                grid-area: title;
            }

            .subject-row .wp-input {
                grid-area: wp;
            }

            .subject-row .core-check {
                grid-area: core;
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
                        <h1><i class="fas fa-clipboard-list me-2"></i>Class Subjects</h1>
                        <p>Subjects actually taught in each class offering</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/academic/class-offerings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-door-open me-2"></i> Manage Offerings
                        </a>
                        <a href="/platform/tenant/academic/settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Settings
                        </a>
                    </div>
                </div>
                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo h((string)$totalOfferings); ?> offering(s) ·
                                <?php echo h((string)$offeringsWithSubjects); ?> with subjects ·
                                <?php echo h((string)$totalActiveSubjects); ?> active subject(s)
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Offering-centric
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
                        <div class="ib-title">Class subjects vs curriculum</div>
                        <div class="ib-text">
                            The <strong>curriculum</strong> says which subjects a level <em>should</em> teach.
                            <strong>Class subjects</strong> say which subjects a <em>specific offering</em> actually teaches.
                            Use <strong>Manage Subjects</strong> on an offering to pick them. You can <em>Import all from curriculum</em>
                            to bootstrap in one click.
                        </div>
                    </div>
                </div>
                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-door-open"></i>
                            <h5>No class offerings yet</h5>
                            <p>Class subjects live inside class offerings. Create an offering first.</p>
                            <a href="/platform/tenant/academic/class-offerings.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Create Offering
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($byYear as $yId => $yearGroup): ?>
                        <div class="year-section">
                            <div class="year-header">
                                <div class="year-title">
                                    <i class="fas fa-calendar-alt" style="font-size:20px;color:#4facfe;"></i>
                                    <h5><?php echo h($yearGroup['year_name']); ?></h5>
                                    <?php
                                    $coCount = 0;
                                    $subCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) {
                                        foreach ($lg['items'] as $oi) {
                                            $coCount++;
                                            $subCount += (int)$oi['active_subject_count'];
                                        }
                                    }
                                    ?>
                                    <span class="pill blue"><i class="fas fa-door-open"></i><?php echo h((string)$coCount); ?> offering(s)</span>
                                    <span class="pill purple"><i class="fas fa-clipboard-list"></i><?php echo h((string)$subCount); ?> subject(s)</span>
                                </div>
                            </div>
                            <?php foreach ($yearGroup['levels'] as $lId => $levelGroup): ?>
                                <div class="level-block">
                                    <div class="level-bar">
                                        <i class="fas fa-layer-group" style="color:#4facfe;"></i>
                                        <h6><?php echo h($levelGroup['level_name']); ?></h6>
                                        <?php if (!empty($levelGroup['level_code'])): ?>
                                            <span class="pill blue"><?php echo h($levelGroup['level_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <table class="offering-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:220px;"><?php echo h($labelClass); ?> Offering</th>
                                                <th style="text-align:center;">Subjects</th>
                                                <th style="text-align:right;min-width:200px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $o):
                                                $oid = (int)$o['id'];
                                                $hasActive = ((int)$o['active_subject_count']) > 0;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="offering-title">
                                                            <?php echo h($o['class_name'] ?: '—'); ?>
                                                            <?php if (!empty($o['class_code'])): ?>
                                                                <span class="pill blue" style="margin-left:6px;"><?php echo h($o['class_code']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($o['stream_name'])): ?>
                                                            <div class="offering-meta">
                                                                <i class="fas fa-code-branch me-1"></i>
                                                                <?php echo h($labelStream); ?>: <?php echo h($o['stream_name']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <?php if ($hasActive): ?>
                                                            <span class="pill purple">
                                                                <i class="fas fa-clipboard-list"></i>
                                                                <?php echo h((string)(int)$o['active_subject_count']); ?>
                                                                <?php if ((int)$o['total_subject_count'] > (int)$o['active_subject_count']): ?>
                                                                    <span style="opacity:0.7;">of <?php echo h((string)(int)$o['total_subject_count']); ?></span>
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="pill gray">
                                                                <i class="fas fa-minus"></i>None
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <button type="button"
                                                                class="btn btn-outline-primary js-manage-subjects"
                                                                data-offering-id="<?php echo $oid; ?>"
                                                                data-offering-label="<?php echo h(($o['class_name'] ?? '') . (($o['stream_name'] ?? '') ? ' / ' . $o['stream_name'] : '') . ' · ' . ($o['year_name'] ?? '') . ' · ' . ($o['level_name'] ?? '')); ?>"
                                                                data-level-id="<?php echo (int)$o['academic_level_id']; ?>">
                                                                <i class="fas fa-tasks"></i> Manage Subjects
                                                            </button>
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
    <div class="modal fade" id="manageSubjectsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" id="bulkSaveForm" action="/platform/tenant/academic/class-subjects.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_save">
                    <input type="hidden" name="class_offering_id" id="modalOfferingId" value="">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-tasks text-primary me-2"></i>
                            Manage Subjects
                            <small class="text-muted d-block" id="modalOfferingLabel" style="font-size:12px;font-weight:400;margin-top:2px;"></small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="modal-toolbar">
                            <div class="search-input">
                                <input type="text" class="form-control" id="subjectSearchInput" placeholder="Search subjects...">
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-success" id="importFromCurriculumBtn" title="Add all curriculum entries for this offering's level and year">
                                    <i class="fas fa-file-import me-1"></i> Import from curriculum
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="checkAllBtn">Check all</button>
                                <button type="button" class="btn btn-outline-secondary" id="uncheckAllBtn">Uncheck all</button>
                            </div>
                        </div>
                        <div class="subject-grid" id="subjectGrid"></div>
                        <div class="existing-panel" id="existingPanel" style="display:none;">
                            <h6><i class="fas fa-clipboard-check text-primary"></i>Already in this offering</h6>
                            <div class="existing-list" id="existingList"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Selected</button>
                    </div>
                </form>
                <form method="POST" id="singleActionForm" action="/platform/tenant/academic/class-subjects.php" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="singleActionName" value="">
                    <input type="hidden" name="id" id="singleActionId" value="">
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var SUBJECTS_BY_LEVEL = <?php echo $subjectsByLevelJson; ?>;
        if (typeof SUBJECTS_BY_LEVEL !== 'object' || SUBJECTS_BY_LEVEL === null) {
            SUBJECTS_BY_LEVEL = {};
        }
        var EXISTING_BY_OFFERING = <?php echo $existingByOfferingJson; ?>;
        if (typeof EXISTING_BY_OFFERING !== 'object' || EXISTING_BY_OFFERING === null) {
            EXISTING_BY_OFFERING = {};
        }

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
        (function() {
            const modalEl = document.getElementById('manageSubjectsModal');
            const modal = new bootstrap.Modal(modalEl);
            const offeringIdInput = document.getElementById('modalOfferingId');
            const offeringLabelEl = document.getElementById('modalOfferingLabel');
            const gridEl = document.getElementById('subjectGrid');
            const existingPanel = document.getElementById('existingPanel');
            const existingListEl = document.getElementById('existingList');
            const searchInput = document.getElementById('subjectSearchInput');
            const checkAllBtn = document.getElementById('checkAllBtn');
            const uncheckAllBtn = document.getElementById('uncheckAllBtn');
            const importBtn = document.getElementById('importFromCurriculumBtn');
            const singleForm = document.getElementById('singleActionForm');
            const singleActionName = document.getElementById('singleActionName');
            const singleActionId = document.getElementById('singleActionId');
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
            let currentOfferingId = 0;
            let currentLevelId = 0;

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function renderGrid() {
                const candidates = SUBJECTS_BY_LEVEL[currentLevelId] || [];
                const existingForOffering = EXISTING_BY_OFFERING[currentOfferingId] || [];
                const existingIds = new Set(existingForOffering.map(function(r) {
                    return parseInt(r.subject_id, 10);
                }));
                gridEl.innerHTML = '';
                if (!candidates.length) {
                    gridEl.innerHTML = '<div class="p-3 text-muted">No subjects are available for this offering\'s level. Add subjects under <strong>Subjects</strong> first.</div>';
                    return;
                }
                candidates.forEach(function(s) {
                    const sid = parseInt(s.id, 10);
                    const present = existingIds.has(sid);
                    const row = document.createElement('div');
                    row.className = 'subject-row' + (present ? ' already-present' : '');
                    row.dataset.subjectId = sid;
                    row.innerHTML = '' +
                        '<div class="chk">' +
                        '<input type="checkbox" name="subject_id[]" value="' + sid + '"' + (present ? ' checked disabled' : '') + '>' +
                        '</div>' +
                        '<div class="title-wrap">' +
                        '<span class="subject-title">' + escapeHtml(s.subject_name) + '</span>' +
                        '<span class="subject-code">' + escapeHtml(s.subject_code || '') + '</span>' +
                        '</div>' +
                        '<div class="wp-input">' +
                        '<input type="number" min="0" max="99" placeholder="Periods" class="form-control form-control-sm wp-input-el" name="weekly_periods[]" value="">' +
                        '</div>' +
                        '<div class="core-check">' +
                        '<input type="checkbox" name="is_core[]" value="1" checked>' +
                        '<label>Core</label>' +
                        '</div>';
                    gridEl.appendChild(row);
                });
                renderExistingPanel();
                applySearch();
            }

            function renderExistingPanel() {
                const rows = EXISTING_BY_OFFERING[currentOfferingId] || [];
                if (!rows.length) {
                    existingPanel.style.display = 'none';
                    existingListEl.innerHTML = '';
                    return;
                }
                existingPanel.style.display = 'block';
                existingListEl.innerHTML = '';
                rows.forEach(function(r) {
                    const wp = (r.weekly_periods !== null && r.weekly_periods !== undefined) ? r.weekly_periods : '—';
                    const core = parseInt(r.is_core, 10) === 1 ? 'Core' : 'Elective';
                    const act = parseInt(r.is_active, 10) === 1;
                    const item = document.createElement('div');
                    item.className = 'existing-item';
                    item.innerHTML = '' +
                        '<div class="info">' +
                        '<div class="title">' +
                        escapeHtml(r.subject_name || 'Subject') +
                        '<span class="subject-code">' + escapeHtml(r.subject_code || '') + '</span>' +
                        '</div>' +
                        '<div class="meta">' +
                        core + ' · Periods: ' + escapeHtml(String(wp)) + ' · ' +
                        (act ? '<span class="pill green"><i class="fas fa-check"></i>Active</span>' : '<span class="pill gray"><i class="fas fa-times"></i>Inactive</span>') +
                        '</div>' +
                        '</div>' +
                        '<div class="actions">' +
                        '<button type="button" class="btn btn-outline-warning js-toggle-existing" data-id="' + parseInt(r.id, 10) + '" title="' + (act ? 'Deactivate' : 'Activate') + '">' +
                        '<i class="fas fa-' + (act ? 'pause' : 'play') + '"></i>' +
                        '</button>' +
                        '<button type="button" class="btn btn-outline-danger js-delete-existing" data-id="' + parseInt(r.id, 10) + '" data-subject="' + escapeHtml(r.subject_name || '') + '" title="Remove">' +
                        '<i class="fas fa-trash"></i>' +
                        '</button>' +
                        '</div>';
                    existingListEl.appendChild(item);
                });
            }

            function applySearch() {
                const q = (searchInput.value || '').trim().toLowerCase();
                gridEl.querySelectorAll('.subject-row').forEach(function(row) {
                    const title = row.querySelector('.subject-title').textContent.toLowerCase();
                    const code = row.querySelector('.subject-code').textContent.toLowerCase();
                    const match = !q || title.indexOf(q) !== -1 || code.indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                });
            }
            document.querySelectorAll('.js-manage-subjects').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    currentOfferingId = parseInt(this.getAttribute('data-offering-id'), 10);
                    currentLevelId = parseInt(this.getAttribute('data-level-id'), 10);
                    offeringIdInput.value = currentOfferingId;
                    offeringLabelEl.textContent = this.getAttribute('data-offering-label') || '';
                    searchInput.value = '';
                    renderGrid();
                    modal.show();
                });
            });
            searchInput.addEventListener('input', applySearch);
            checkAllBtn.addEventListener('click', function() {
                gridEl.querySelectorAll('.subject-row').forEach(function(row) {
                    if (row.style.display === 'none') return;
                    const cb = row.querySelector('input[name="subject_id[]"]');
                    if (cb && !cb.disabled) cb.checked = true;
                });
            });
            uncheckAllBtn.addEventListener('click', function() {
                gridEl.querySelectorAll('.subject-row').forEach(function(row) {
                    if (row.style.display === 'none') return;
                    const cb = row.querySelector('input[name="subject_id[]"]');
                    if (cb && !cb.disabled) cb.checked = false;
                });
            });
            importBtn.addEventListener('click', function() {
                if (!currentOfferingId) return;
                if (!confirm('Import all curriculum subjects for this offering\'s level and year?')) return;
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = '/platform/tenant/academic/class-subjects.php';
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_csrf';
                csrfInput.value = csrfToken;
                f.appendChild(csrfInput);
                const a = document.createElement('input');
                a.type = 'hidden';
                a.name = 'action';
                a.value = 'import_from_curriculum';
                f.appendChild(a);
                const b = document.createElement('input');
                b.type = 'hidden';
                b.name = 'class_offering_id';
                b.value = currentOfferingId;
                f.appendChild(b);
                document.body.appendChild(f);
                f.submit();
            });
            document.addEventListener('click', function(e) {
                const tgl = e.target.closest('.js-toggle-existing');
                if (tgl) {
                    if (!confirm('Toggle active status for this subject?')) return;
                    singleActionName.value = 'toggle_active';
                    singleActionId.value = tgl.getAttribute('data-id');
                    singleForm.submit();
                    return;
                }
                const del = e.target.closest('.js-delete-existing');
                if (del) {
                    const s = del.getAttribute('data-subject') || 'this subject';
                    if (!confirm('Remove "' + s + '" from this offering?')) return;
                    singleActionName.value = 'delete';
                    singleActionId.value = del.getAttribute('data-id');
                    singleForm.submit();
                    return;
                }
            });
            document.getElementById('bulkSaveForm').addEventListener('submit', function(e) {
                const checked = this.querySelectorAll('input[name="subject_id[]"]:checked:not(:disabled)').length;
                const already = this.querySelectorAll('input[name="subject_id[]"]:checked:disabled').length;
                if (checked + already === 0) {
                    e.preventDefault();
                    alert('Please select at least one subject.');
                }
            });
        })();
        const successBox = document.getElementById('serverSuccessBox');
        if (successBox) {
            setTimeout(function() {
                successBox.style.transition = 'opacity 0.3s ease';
                successBox.style.opacity = '0';
                setTimeout(function() {
                    successBox.remove();
                }, 300);
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