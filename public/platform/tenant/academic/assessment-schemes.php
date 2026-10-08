<?php

/**
 * Academic Assessment Schemes — Fixed-weight components per level/year
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/assessment-schemes.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic assessment-schemes file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_assessment_schemes'
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
 *   previous version (1.2). The @package tag remains 'EduTrack'.
 *
 * Decisions locked in (Session S14):
 *   D1  scheme_name + total_weight + status required; level/year/code/dates/
 *       description optional; components require component_name + weight;
 *       max_score defaults 100.00
 *   D2  unique per (tenant, scheme_name, version) and (tenant, scheme_code,
 *       version) for non-deleted rows via FOR UPDATE
 *   D3  no feature gate
 *   D4  snapshot on activate — editing an active scheme creates a new draft
 *       version, old one archived
 *   D5  allow tenant-wide schemes (level NULL) plus level-scoped schemes
 *   D6  enforce total_weight = sum(active component weights) before activating
 *   D7  refuse delete if results reference the scheme
 *   D8  scheme-first flat list grouped by year → level, components inline
 *   D9  modal per scheme to manage components (weights only, components fixed)
 *   D10 explicit lifecycle actions: activate / archive / duplicate
 *   D11 validate effective_to >= effective_from when both set
 *
 * Addendum (locked after initial S14 build):
 *   C1  The set of components is FIXED to five:
 *         Class Exercise, Class Test, Mid-Term, Project Assignment, Exam
 *   C2  total_weight is fixed at 100.00
 *   C3  Component names/codes are not editable; only weights (and max_score)
 *   C4  Newly created schemes auto-seed the five components
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it
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
$pageTitle    = 'Assessment Schemes - Student 360 Platform';
$currentPage = 'academic';
// ============================================
// HARD-CODED COMPONENTS (C1, C2, C3)
// ============================================
const ASSESSMENT_COMPONENTS = [
    ['code' => 'CLASS_EX',  'name' => 'Class Exercise',      'default_w' => 10.00, 'default_max' => 100.00],
    ['code' => 'CLASS_TEST', 'name' => 'Class Test',          'default_w' => 10.00, 'default_max' => 100.00],
    ['code' => 'MIDTERM',   'name' => 'Mid-Term',            'default_w' => 20.00, 'default_max' => 100.00],
    ['code' => 'PROJECT',   'name' => 'Project Assignment',  'default_w' => 20.00, 'default_max' => 100.00],
    ['code' => 'EXAM',      'name' => 'Exam',                'default_w' => 40.00, 'default_max' => 100.00],
];
const ASSESSMENT_TOTAL_WEIGHT = 100.00;
function canonicalComponentCodes(): array
{
    return array_map(function ($c) {
        return $c['code'];
    }, ASSESSMENT_COMPONENTS);
}
function canonicalComponent(string $code): ?array
{
    foreach (ASSESSMENT_COMPONENTS as $c) {
        if ($c['code'] === $code) return $c;
    }
    return null;
}
function seedCanonicalComponents($db, int $tenantId, int $schemeId, array $weightsByCode = []): void
{
    $i = 0;
    foreach (ASSESSMENT_COMPONENTS as $c) {
        $w = isset($weightsByCode[$c['code']]) ? (float)$weightsByCode[$c['code']] : $c['default_w'];
        if ($w < 0) $w = 0;
        $db->insert(
            "INSERT INTO assessment_components
                (uuid, tenant_id, assessment_scheme_id, component_name, component_code,
                 weight, max_score, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
            [
                uuidv4(),
                $tenantId,
                $schemeId,
                $c['name'],
                $c['code'],
                $w,
                $c['default_max'],
                ($i + 1) * 10,
            ]
        );
        $i++;
    }
}
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
function getScheme($db, int $tenantId, int $schemeId): ?array
{
    if ($schemeId <= 0) return null;
    return $db->fetchOne(
        "SELECT s.*,
                al.level_name, al.level_code,
                ay.year_name
         FROM assessment_schemes s
         LEFT JOIN academic_levels al ON s.academic_level_id = al.id
         LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
         WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
        [$schemeId, $tenantId]
    ) ?: null;
}
function schemeActiveWeightSum($db, int $tenantId, int $schemeId): float
{
    $row = $db->fetchOne(
        "SELECT COALESCE(SUM(weight), 0) AS w
         FROM assessment_components
         WHERE tenant_id = ? AND assessment_scheme_id = ?
           AND is_active = 1 AND deleted_at IS NULL",
        [$tenantId, $schemeId]
    );
    return (float)($row['w'] ?? 0);
}
function schemeDependencies($db, int $tenantId, int $schemeId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'results' => "SELECT COUNT(*) AS c FROM results
                      WHERE tenant_id = ? AND assessment_scheme_id = ? AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $schemeId]);
            $c = (int)($row['c'] ?? 0);
            if ($c > 0) {
                $total += $c;
                $labels[] = $c . ' ' . $label;
            }
        } catch (Exception $e) {
        }
    }
    return ['total' => $total, 'labels' => $labels];
}
function normalizeCanonicalWeights(array $weightsByCode, array $maxScoresByCode): array
{
    $out = [];
    foreach (ASSESSMENT_COMPONENTS as $c) {
        $code = $c['code'];
        $w = $weightsByCode[$code] ?? null;
        if ($w === null || $w === '' || !is_numeric($w)) {
            throw new Exception('Weight for "' . $c['name'] . '" must be a number.');
        }
        $w = (float)$w;
        if ($w < 0 || $w > 100) {
            throw new Exception('Weight for "' . $c['name'] . '" must be between 0 and 100.');
        }
        $m = $maxScoresByCode[$code] ?? null;
        $m = ($m === null || $m === '') ? 100.00 : (float)$m;
        if ($m <= 0) {
            throw new Exception('Max score for "' . $c['name'] . '" must be greater than zero.');
        }
        $out[$code] = ['weight' => $w, 'max_score' => $m];
    }
    $sum = 0.0;
    foreach ($out as $v) $sum += $v['weight'];
    if (abs($sum - ASSESSMENT_TOTAL_WEIGHT) > 0.01) {
        throw new Exception(
            'The five component weights must total exactly ' . number_format(ASSESSMENT_TOTAL_WEIGHT, 2) .
                '. They currently total ' . number_format($sum, 2) . '.'
        );
    }
    return $out;
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
        // ---------------- CREATE ----------------
        if ($action === 'create') {
            $schemeName = trim((string)($_POST['scheme_name'] ?? ''));
            $schemeCode = trim((string)($_POST['scheme_code'] ?? ''));
            $levelId    = (int)($_POST['academic_level_id'] ?? 0);
            $yearId     = (int)($_POST['academic_year_id'] ?? 0);
            $effFrom    = trim((string)($_POST['effective_from'] ?? ''));
            $effTo      = trim((string)($_POST['effective_to'] ?? ''));
            $desc       = trim((string)($_POST['description'] ?? ''));
            $status     = trim((string)($_POST['status'] ?? 'draft'));
            if ($schemeName === '') $errors[] = 'Scheme name is required.';
            if (!in_array($status, ['draft', 'active'], true)) $errors[] = 'Invalid status.';
            if ($effFrom !== '' && $effTo !== '' && strtotime($effTo) < strtotime($effFrom)) {
                $errors[] = 'Effective-to date must be on or after effective-from.';
            }
            if ($levelId > 0) {
                $lv = $db->fetchOne(
                    "SELECT id FROM academic_levels WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$levelId, $tenantId]
                );
                if (!$lv) $errors[] = 'Selected academic level not found.';
            } else {
                $levelId = 0;
            }
            if ($yearId > 0) {
                $yr = $db->fetchOne(
                    "SELECT id FROM academic_years WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$yearId, $tenantId]
                );
                if (!$yr) $errors[] = 'Selected academic year not found.';
            } else {
                $yearId = 0;
            }
            if (!empty($errors)) throw new Exception(implode(' ', $errors));
            $db->beginTransaction();
            $dup = $db->fetchOne(
                "SELECT id FROM assessment_schemes
                 WHERE tenant_id = ? AND scheme_name = ? AND version = 1 AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $schemeName]
            );
            if ($dup) throw new Exception('A scheme with this name already exists (version 1).');
            if ($schemeCode !== '') {
                $dupCode = $db->fetchOne(
                    "SELECT id FROM assessment_schemes
                     WHERE tenant_id = ? AND scheme_code = ? AND version = 1 AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $schemeCode]
                );
                if ($dupCode) throw new Exception('A scheme with this code already exists (version 1).');
            }
            $newId = $db->insert(
                "INSERT INTO assessment_schemes
                    (uuid, tenant_id, scheme_name, scheme_code,
                     academic_level_id, academic_year_id, version, status,
                     effective_from, effective_to, total_weight, description,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $schemeName,
                    $schemeCode !== '' ? $schemeCode : null,
                    $levelId > 0 ? $levelId : null,
                    $yearId > 0 ? $yearId : null,
                    $status,
                    $effFrom !== '' ? $effFrom : null,
                    $effTo   !== '' ? $effTo   : null,
                    ASSESSMENT_TOTAL_WEIGHT,
                    $desc !== '' ? $desc : null,
                    $userId ?: null,
                ]
            );
            seedCanonicalComponents($db, $tenantId, (int)$newId);
            if ($status === 'active') {
                $sum = schemeActiveWeightSum($db, $tenantId, (int)$newId);
                if (abs($sum - ASSESSMENT_TOTAL_WEIGHT) > 0.01) {
                    throw new Exception(
                        'Cannot create as active: components sum to ' . number_format($sum, 2) .
                            ' instead of ' . number_format(ASSESSMENT_TOTAL_WEIGHT, 2) . '.'
                    );
                }
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.assessment_scheme.created',
                'assessment_schemes',
                (int)$newId,
                [
                    'name' => $schemeName,
                    'version' => 1,
                    'status' => $status,
                    'level_id' => $levelId,
                    'year_id' => $yearId,
                    'components' => count(ASSESSMENT_COMPONENTS)
                ]
            );
            $db->commit();
            $_SESSION['success'] = 'Assessment scheme created with the five standard components.';
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        // ---------------- UPDATE ----------------
        if ($action === 'update') {
            $id         = (int)($_POST['id'] ?? 0);
            $schemeName = trim((string)($_POST['scheme_name'] ?? ''));
            $schemeCode = trim((string)($_POST['scheme_code'] ?? ''));
            $levelId    = (int)($_POST['academic_level_id'] ?? 0);
            $yearId     = (int)($_POST['academic_year_id'] ?? 0);
            $effFrom    = trim((string)($_POST['effective_from'] ?? ''));
            $effTo      = trim((string)($_POST['effective_to'] ?? ''));
            $desc       = trim((string)($_POST['description'] ?? ''));
            $scheme = getScheme($db, $tenantId, $id);
            if (!$scheme) throw new Exception('Scheme not found.');
            if ($schemeName === '') $errors[] = 'Scheme name is required.';
            if ($effFrom !== '' && $effTo !== '' && strtotime($effTo) < strtotime($effFrom)) {
                $errors[] = 'Effective-to date must be on or after effective-from.';
            }
            if (!empty($errors)) throw new Exception(implode(' ', $errors));
            $db->beginTransaction();
            if ($scheme['status'] === 'draft') {
                $dup = $db->fetchOne(
                    "SELECT id FROM assessment_schemes
                     WHERE tenant_id = ? AND scheme_name = ? AND version = ?
                       AND id != ? AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $schemeName, (int)$scheme['version'], $id]
                );
                if ($dup) throw new Exception('Another scheme already uses this name at this version.');
                if ($schemeCode !== '') {
                    $dupCode = $db->fetchOne(
                        "SELECT id FROM assessment_schemes
                         WHERE tenant_id = ? AND scheme_code = ? AND version = ?
                           AND id != ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $schemeCode, (int)$scheme['version'], $id]
                    );
                    if ($dupCode) throw new Exception('Another scheme already uses this code at this version.');
                }
                $db->execute(
                    "UPDATE assessment_schemes
                        SET scheme_name = ?, scheme_code = ?,
                            academic_level_id = ?, academic_year_id = ?,
                            total_weight = ?,
                            effective_from = ?, effective_to = ?,
                            description = ?, updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [
                        $schemeName,
                        $schemeCode !== '' ? $schemeCode : null,
                        $levelId > 0 ? $levelId : null,
                        $yearId > 0 ? $yearId : null,
                        ASSESSMENT_TOTAL_WEIGHT,
                        $effFrom !== '' ? $effFrom : null,
                        $effTo   !== '' ? $effTo   : null,
                        $desc !== '' ? $desc : null,
                        $id,
                        $tenantId,
                    ]
                );
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.assessment_scheme.updated',
                    'assessment_schemes',
                    $id,
                    ['name' => $schemeName, 'version' => (int)$scheme['version']]
                );
                $_SESSION['success'] = 'Draft scheme updated.';
            } else {
                $newVersion = (int)$scheme['version'] + 1;
                $dup = $db->fetchOne(
                    "SELECT id FROM assessment_schemes
                     WHERE tenant_id = ? AND scheme_name = ? AND version = ?
                       AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $schemeName, $newVersion]
                );
                if ($dup) throw new Exception('A draft for version ' . $newVersion . ' already exists.');
                if ($schemeCode !== '') {
                    $dupCode = $db->fetchOne(
                        "SELECT id FROM assessment_schemes
                         WHERE tenant_id = ? AND scheme_code = ? AND version = ?
                           AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $schemeCode, $newVersion]
                    );
                    if ($dupCode) throw new Exception('A draft using this code at version ' . $newVersion . ' already exists.');
                }
                $db->execute(
                    "UPDATE assessment_schemes
                        SET status = 'archived', updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$id, $tenantId]
                );
                $newId = $db->insert(
                    "INSERT INTO assessment_schemes
                        (uuid, tenant_id, scheme_name, scheme_code,
                         academic_level_id, academic_year_id, version, status,
                         effective_from, effective_to, total_weight, description,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $schemeName,
                        $schemeCode !== '' ? $schemeCode : null,
                        $levelId > 0 ? $levelId : null,
                        $yearId > 0 ? $yearId : null,
                        $newVersion,
                        $effFrom !== '' ? $effFrom : null,
                        $effTo   !== '' ? $effTo   : null,
                        ASSESSMENT_TOTAL_WEIGHT,
                        $desc !== '' ? $desc : null,
                        $userId ?: null,
                    ]
                );
                $oldComponents = $db->fetchAll(
                    "SELECT component_name, component_code, weight, max_score, sort_order, is_active
                     FROM assessment_components
                     WHERE tenant_id = ? AND assessment_scheme_id = ?
                       AND deleted_at IS NULL
                     ORDER BY sort_order ASC",
                    [$tenantId, $id]
                );
                foreach ($oldComponents as $c) {
                    $db->insert(
                        "INSERT INTO assessment_components
                            (uuid, tenant_id, assessment_scheme_id, component_name, component_code,
                             weight, max_score, sort_order, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $newId,
                            $c['component_name'],
                            $c['component_code'],
                            $c['weight'],
                            $c['max_score'],
                            $c['sort_order'],
                            $c['is_active'],
                        ]
                    );
                }
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.assessment_scheme.snapshotted',
                    'assessment_schemes',
                    (int)$newId,
                    [
                        'parent_id' => $id,
                        'name' => $schemeName,
                        'archived_version' => (int)$scheme['version'],
                        'new_version' => $newVersion
                    ]
                );
                $_SESSION['success'] = 'Active scheme archived; new draft version ' . $newVersion . ' created.';
            }
            $db->commit();
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        // ---------------- ACTIVATE ----------------
        if ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            $scheme = getScheme($db, $tenantId, $id);
            if (!$scheme) throw new Exception('Scheme not found.');
            if ($scheme['status'] === 'active') {
                $_SESSION['success'] = 'Scheme is already active.';
                header('Location: /platform/tenant/academic/assessment-schemes.php');
                exit;
            }
            $db->beginTransaction();
            $sum = schemeActiveWeightSum($db, $tenantId, $id);
            if (abs($sum - ASSESSMENT_TOTAL_WEIGHT) > 0.01) {
                throw new Exception(
                    'Cannot activate: components sum to ' . number_format($sum, 2) .
                        ' but the total must be ' . number_format(ASSESSMENT_TOTAL_WEIGHT, 2) .
                        '. Adjust the component weights first.'
                );
            }
            $db->execute(
                "UPDATE assessment_schemes
                    SET status = 'active', updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.assessment_scheme.activated',
                'assessment_schemes',
                $id,
                ['name' => $scheme['scheme_name'], 'version' => (int)$scheme['version']]
            );
            $db->commit();
            $_SESSION['success'] = 'Scheme activated.';
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        // ---------------- ARCHIVE ----------------
        if ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            $scheme = getScheme($db, $tenantId, $id);
            if (!$scheme) throw new Exception('Scheme not found.');
            $db->beginTransaction();
            $db->execute(
                "UPDATE assessment_schemes
                    SET status = 'archived', updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.assessment_scheme.archived',
                'assessment_schemes',
                $id,
                ['name' => $scheme['scheme_name'], 'version' => (int)$scheme['version']]
            );
            $db->commit();
            $_SESSION['success'] = 'Scheme archived.';
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        // ---------------- DUPLICATE ----------------
        if ($action === 'duplicate') {
            $id = (int)($_POST['id'] ?? 0);
            $scheme = getScheme($db, $tenantId, $id);
            if (!$scheme) throw new Exception('Scheme not found.');
            $db->beginTransaction();
            $nextRow = $db->fetchOne(
                "SELECT COALESCE(MAX(version), 0) + 1 AS v
                 FROM assessment_schemes
                 WHERE tenant_id = ? AND scheme_name = ? AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $scheme['scheme_name']]
            );
            $newVersion = (int)($nextRow['v'] ?? 1);
            if ($newVersion < 1) $newVersion = 1;
            $newId = $db->insert(
                "INSERT INTO assessment_schemes
                    (uuid, tenant_id, scheme_name, scheme_code,
                     academic_level_id, academic_year_id, version, status,
                     effective_from, effective_to, total_weight, description,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $scheme['scheme_name'],
                    $scheme['scheme_code'],
                    $scheme['academic_level_id'],
                    $scheme['academic_year_id'],
                    $newVersion,
                    $scheme['effective_from'],
                    $scheme['effective_to'],
                    ASSESSMENT_TOTAL_WEIGHT,
                    $scheme['description'],
                    $userId ?: null,
                ]
            );
            $oldComponents = $db->fetchAll(
                "SELECT component_name, component_code, weight, max_score, sort_order, is_active
                 FROM assessment_components
                 WHERE tenant_id = ? AND assessment_scheme_id = ?
                   AND deleted_at IS NULL
                 ORDER BY sort_order ASC",
                [$tenantId, $id]
            );
            foreach ($oldComponents as $c) {
                $db->insert(
                    "INSERT INTO assessment_components
                        (uuid, tenant_id, assessment_scheme_id, component_name, component_code,
                         weight, max_score, sort_order, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $newId,
                        $c['component_name'],
                        $c['component_code'],
                        $c['weight'],
                        $c['max_score'],
                        $c['sort_order'],
                        $c['is_active'],
                    ]
                );
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.assessment_scheme.duplicated',
                'assessment_schemes',
                (int)$newId,
                ['source_id' => $id, 'name' => $scheme['scheme_name'], 'new_version' => $newVersion]
            );
            $db->commit();
            $_SESSION['success'] = 'Scheme duplicated as draft version ' . $newVersion . '.';
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        // ---------------- DELETE ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $scheme = getScheme($db, $tenantId, $id);
            if (!$scheme) throw new Exception('Scheme not found.');
            $deps = schemeDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete this scheme. It is referenced by: ' .
                        implode(', ', $deps['labels']) .
                        '. Archive it instead, or remove those references first.'
                );
            }
            $db->beginTransaction();
            $db->execute(
                "UPDATE assessment_components
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = ? AND assessment_scheme_id = ? AND deleted_at IS NULL",
                [$tenantId, $id]
            );
            $db->execute(
                "UPDATE assessment_schemes
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.assessment_scheme.deleted',
                'assessment_schemes',
                $id,
                ['name' => $scheme['scheme_name'], 'version' => (int)$scheme['version']]
            );
            $db->commit();
            $_SESSION['success'] = 'Scheme deleted.';
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        // ---------------- SAVE COMPONENT WEIGHTS (C3) ----------------
        if ($action === 'save_components') {
            $schemeId = (int)($_POST['assessment_scheme_id'] ?? 0);
            $scheme = getScheme($db, $tenantId, $schemeId);
            if (!$scheme) throw new Exception('Scheme not found.');
            $weightsByCode = is_array($_POST['weight_by_code'] ?? null) ? $_POST['weight_by_code'] : [];
            $maxByCode     = is_array($_POST['max_score_by_code'] ?? null) ? $_POST['max_score_by_code'] : [];
            $normalized = normalizeCanonicalWeights($weightsByCode, $maxByCode);
            $db->beginTransaction();
            $existing = $db->fetchAll(
                "SELECT id, component_code FROM assessment_components
                 WHERE tenant_id = ? AND assessment_scheme_id = ? AND deleted_at IS NULL",
                [$tenantId, $schemeId]
            );
            $byCode = [];
            foreach ($existing as $e) {
                $byCode[(string)$e['component_code']] = (int)$e['id'];
            }
            foreach (ASSESSMENT_COMPONENTS as $c) {
                $code = $c['code'];
                $w    = $normalized[$code]['weight'];
                $m    = $normalized[$code]['max_score'];
                if (isset($byCode[$code])) {
                    $db->execute(
                        "UPDATE assessment_components
                            SET weight = ?, max_score = ?, updated_at = NOW()
                          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                        [$w, $m, $byCode[$code], $tenantId]
                    );
                } else {
                    $db->insert(
                        "INSERT INTO assessment_components
                            (uuid, tenant_id, assessment_scheme_id, component_name, component_code,
                             weight, max_score, sort_order, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $schemeId,
                            $c['name'],
                            $code,
                            $w,
                            $m,
                            ($c['default_w']),
                        ]
                    );
                }
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.assessment_scheme.components_saved',
                'assessment_schemes',
                $schemeId,
                ['weights' => array_map(function ($v) {
                    return $v['weight'];
                }, $normalized)]
            );
            $db->commit();
            $_SESSION['success'] = 'Component weights saved.';
            header('Location: /platform/tenant/academic/assessment-schemes.php');
            exit;
        }
        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic assessment schemes action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/assessment-schemes.php');
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
$filterYear   = (int)($_GET['year_id'] ?? 0);
$filterLevel  = (int)($_GET['level_id'] ?? 0);
$filterStatus = trim((string)($_GET['status'] ?? ''));
$years = $db->fetchAll(
    "SELECT id, year_name FROM academic_years
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY start_date DESC, id DESC",
    [$tenantId]
);
$levels = $db->fetchAll(
    "SELECT id, level_name, level_code FROM academic_levels
     WHERE tenant_id = ? AND deleted_at IS NULL
     ORDER BY sort_order ASC, level_name ASC",
    [$tenantId]
);
$where = ["s.tenant_id = ?", "s.deleted_at IS NULL"];
$params = [$tenantId];
if ($filterYear > 0) {
    $where[] = "(s.academic_year_id = ? OR s.academic_year_id IS NULL)";
    $params[] = $filterYear;
}
if ($filterLevel > 0) {
    $where[] = "(s.academic_level_id = ? OR s.academic_level_id IS NULL)";
    $params[] = $filterLevel;
}
if (in_array($filterStatus, ['draft', 'active', 'archived'], true)) {
    $where[] = "s.status = ?";
    $params[] = $filterStatus;
}
$whereClause = implode(' AND ', $where);
$schemes = $db->fetchAll(
    "SELECT s.*,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            ay.year_name, ay.start_date AS year_start,
            (SELECT COUNT(*) FROM assessment_components c
              WHERE c.tenant_id = s.tenant_id
                AND c.assessment_scheme_id = s.id
                AND c.deleted_at IS NULL) AS component_count,
            (SELECT COALESCE(SUM(c.weight), 0) FROM assessment_components c
              WHERE c.tenant_id = s.tenant_id
                AND c.assessment_scheme_id = s.id
                AND c.is_active = 1
                AND c.deleted_at IS NULL) AS active_weight_sum
     FROM assessment_schemes s
     LEFT JOIN academic_levels al ON s.academic_level_id = al.id
     LEFT JOIN academic_years  ay ON s.academic_year_id = ay.id
     WHERE $whereClause
     ORDER BY
        CASE s.status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
        s.scheme_name ASC, s.version DESC",
    $params
);
$byYear = [];
foreach ($schemes as $s) {
    $yKey = $s['academic_year_id'] !== null ? (int)$s['academic_year_id'] : 0;
    $yName = $s['year_name'] ?: '(All years)';
    if (!isset($byYear[$yKey])) {
        $byYear[$yKey] = ['year_name' => $yName, 'levels' => []];
    }
    $lKey = $s['academic_level_id'] !== null ? (int)$s['academic_level_id'] : 0;
    $lName = $s['level_name'] ?: '(All levels)';
    $lCode = $s['level_code'] ?? '';
    if (!isset($byYear[$yKey]['levels'][$lKey])) {
        $byYear[$yKey]['levels'][$lKey] = [
            'level_name' => $lName,
            'level_code' => $lCode,
            'items' => [],
        ];
    }
    $byYear[$yKey]['levels'][$lKey]['items'][] = $s;
}
$componentsByScheme = [];
if (!empty($schemes)) {
    $ids = array_map(function ($s) {
        return (int)$s['id'];
    }, $schemes);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT * FROM assessment_components
         WHERE tenant_id = ? AND assessment_scheme_id IN ($in) AND deleted_at IS NULL
         ORDER BY sort_order ASC, id ASC",
        array_merge([$tenantId], $ids)
    );
    foreach ($rows as $r) {
        $sid = (int)$r['assessment_scheme_id'];
        if (!isset($componentsByScheme[$sid])) $componentsByScheme[$sid] = [];
        $componentsByScheme[$sid][(string)$r['component_code']] = [
            'id'            => (int)$r['id'],
            'component_name' => $r['component_name'],
            'component_code' => $r['component_code'],
            'weight'        => (float)$r['weight'],
            'max_score'     => (float)$r['max_score'],
            'is_active'     => (int)$r['is_active'],
        ];
    }
}
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}
$totalSchemes  = count($schemes);
$activeSchemes = 0;
$draftSchemes  = 0;
foreach ($schemes as $s) {
    if ($s['status'] === 'active') $activeSchemes++;
    elseif ($s['status'] === 'draft') $draftSchemes++;
}
// Pre-compute the two JSON maps used by the page, with safe fallbacks.
$hardcodedComponentsJson = json_encode(array_map(
    function ($c) {
        return [
            'code' => $c['code'],
            'name' => $c['name'],
            'default_w' => $c['default_w'],
            'default_max' => $c['default_max'],
        ];
    },
    ASSESSMENT_COMPONENTS
), JSON_UNESCAPED_UNICODE);
if (!is_string($hardcodedComponentsJson) || $hardcodedComponentsJson === '') {
    $hardcodedComponentsJson = '[]';
}
$componentsBySchemeJson = json_encode($componentsByScheme, JSON_UNESCAPED_UNICODE);
if (!is_string($componentsBySchemeJson) || $componentsBySchemeJson === '') {
    $componentsBySchemeJson = '{}';
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
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 150px;
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

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .scheme-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .scheme-table thead th {
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

        .scheme-table tbody td {
            padding: 12px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .scheme-table tbody tr:last-child td {
            border-bottom: none;
        }

        .scheme-table tbody tr:hover {
            background: #fafbfc;
        }

        .scheme-title {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .scheme-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .weight-track {
            width: 100%;
            max-width: 160px;
            height: 6px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
            margin-right: 6px;
        }

        .weight-fill {
            height: 100%;
            transition: width 0.3s;
        }

        .weight-ok {
            background: linear-gradient(90deg, #22c55e, #16a34a);
        }

        .weight-under {
            background: linear-gradient(90deg, #fbbf24, #d97706);
        }

        .weight-over {
            background: linear-gradient(90deg, #ef4444, #dc2626);
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

        .form-label .required {
            color: #dc3545;
            margin-left: 2px;
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

        .form-control[readonly] {
            background: #f8f9fa;
            color: #6c757d;
        }

        .modal-lg {
            max-width: 860px;
        }

        .component-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
        }

        .component-row {
            display: grid;
            grid-template-columns: 1.4fr 1.2fr 1fr 1fr;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #eef0f3;
        }

        .component-row .component-name {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
        }

        .component-row .component-code {
            font-size: 11px;
            color: #6c757d;
            font-family: 'Courier New', monospace;
        }

        .component-row .form-control {
            height: 34px;
            padding: 4px 8px;
            font-size: 12px;
        }

        .weight-summary {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            margin-top: 12px;
        }

        .weight-summary .ws-total {
            font-weight: 700;
            font-size: 15px;
        }

        .weight-summary.ok {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .weight-summary.under {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .weight-summary.over {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
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

            .scheme-table {
                font-size: 12px;
            }

            .scheme-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .scheme-table tbody td {
                padding: 8px 10px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
            }

            .component-row {
                grid-template-columns: 1fr 1fr;
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
                        <h1><i class="fas fa-clipboard-check me-2"></i>Assessment Schemes</h1>
                        <p>Fixed-weight components that define how subjects are graded, per level and year</p>
                    </div>
                    <div class="header-actions">
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
                                <?php echo h((string)$totalSchemes); ?> scheme(s) ·
                                <?php echo h((string)$activeSchemes); ?> active ·
                                <?php echo h((string)$draftSchemes); ?> draft
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Five standard components
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
                        <div class="ib-title">Every scheme uses the same five components</div>
                        <div class="ib-text">
                            Each scheme is graded against a fixed set —
                            <strong>Class Exercise</strong>, <strong>Class Test</strong>, <strong>Mid-Term</strong>,
                            <strong>Project Assignment</strong>, and <strong>Exam</strong>.
                            You only edit the <strong>weight</strong> (and optionally the max score) of each.
                            The weights must sum to exactly <strong>100.00</strong> before the scheme can be activated.
                            Editing an active scheme creates a new draft version and archives the old one.
                        </div>
                    </div>
                </div>
                <form method="GET" action="/platform/tenant/academic/assessment-schemes.php" class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-filter me-1"></i><?php echo h($labelAcademic); ?></label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><?php echo h($labelLevel); ?></label>
                        <select name="level_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($levels as $lv): ?>
                                <option value="<?php echo (int)$lv['id']; ?>" <?php echo $filterLevel === (int)$lv['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($lv['level_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach (['draft', 'active', 'archived'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/assessment-schemes.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/assessment-schemes.php?action=new" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i> New Scheme
                        </a>
                    </div>
                </form>
                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-clipboard-check"></i>
                            <h5>No assessment schemes yet</h5>
                            <p>Create a scheme to define how subjects are graded for a level.</p>
                            <a href="/platform/tenant/academic/assessment-schemes.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Create Scheme
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($byYear as $yKey => $yearGroup): ?>
                        <div class="year-section">
                            <div class="year-header">
                                <div class="year-title">
                                    <i class="fas fa-calendar-alt" style="font-size:20px;color:#4facfe;"></i>
                                    <h5><?php echo h($yearGroup['year_name']); ?></h5>
                                    <?php
                                    $schemeCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) {
                                        $schemeCount += count($lg['items']);
                                    }
                                    ?>
                                    <span class="pill blue"><i class="fas fa-clipboard-check"></i><?php echo h((string)$schemeCount); ?> scheme(s)</span>
                                </div>
                            </div>
                            <?php foreach ($yearGroup['levels'] as $lKey => $levelGroup): ?>
                                <div class="level-block">
                                    <div class="level-bar">
                                        <i class="fas fa-layer-group" style="color:#4facfe;"></i>
                                        <h6><?php echo h($levelGroup['level_name']); ?></h6>
                                        <?php if (!empty($levelGroup['level_code'])): ?>
                                            <span class="pill blue"><?php echo h($levelGroup['level_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <table class="scheme-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:220px;">Scheme</th>
                                                <th style="text-align:center;">Version</th>
                                                <th style="text-align:center;">Status</th>
                                                <th style="min-width:200px;">Component weights</th>
                                                <th style="text-align:right;min-width:320px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $s):
                                                $sid        = (int)$s['id'];
                                                $version    = (int)$s['version'];
                                                $status     = $s['status'];
                                                $activeSum  = (float)$s['active_weight_sum'];
                                                $diff = $activeSum - ASSESSMENT_TOTAL_WEIGHT;
                                                $weightState = 'ok';
                                                if (abs($diff) > 0.01) {
                                                    $weightState = $diff < 0 ? 'under' : 'over';
                                                }
                                                $fillPct = min(100, max(0, ($activeSum / ASSESSMENT_TOTAL_WEIGHT) * 100));
                                                $statusPill = ['draft' => 'gray', 'active' => 'green', 'archived' => 'orange'][$status] ?? 'gray';
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="scheme-title">
                                                            <?php echo h($s['scheme_name']); ?>
                                                            <?php if (!empty($s['scheme_code'])): ?>
                                                                <span class="pill blue" style="margin-left:6px;"><?php echo h($s['scheme_code']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($s['effective_from']) || !empty($s['effective_to'])): ?>
                                                            <div class="scheme-meta">
                                                                <i class="fas fa-calendar me-1"></i>
                                                                <?php echo h($s['effective_from'] ?: '—'); ?>
                                                                →
                                                                <?php echo h($s['effective_to'] ?: '—'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill teal">v<?php echo h((string)$version); ?></span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo $statusPill; ?>"><?php echo h(ucfirst($status)); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="weight-track">
                                                            <div class="weight-fill weight-<?php echo $weightState; ?>" style="width: <?php echo number_format($fillPct, 2); ?>%;"></div>
                                                        </div>
                                                        <span style="font-size:12px;font-weight:600;color:#1a1a2e;">
                                                            <?php echo h(number_format($activeSum, 2)); ?> / 100.00
                                                        </span>
                                                        <?php if ($weightState !== 'ok'): ?>
                                                            <span class="pill <?php echo $weightState === 'under' ? 'orange' : 'red'; ?>" style="margin-left:6px;">
                                                                <?php echo $weightState === 'under' ? 'Under' : 'Over'; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <button type="button"
                                                                class="btn btn-outline-primary js-manage-components"
                                                                data-scheme-id="<?php echo $sid; ?>"
                                                                data-scheme-label="<?php echo h($s['scheme_name'] . ' v' . $version); ?>">
                                                                <i class="fas fa-sliders-h"></i> Weights
                                                            </button>
                                                            <a href="/platform/tenant/academic/assessment-schemes.php?action=edit&id=<?php echo $sid; ?>"
                                                                class="btn btn-outline-secondary">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </a>
                                                            <?php if ($status !== 'active'): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Activate this scheme?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="activate">
                                                                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                                                    <button type="submit" class="btn btn-outline-success">
                                                                        <i class="fas fa-check"></i> Activate
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Archive this active scheme?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="archive">
                                                                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                                                    <button type="submit" class="btn btn-outline-warning">
                                                                        <i class="fas fa-box-archive"></i> Archive
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Duplicate as a new draft version?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="duplicate">
                                                                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                                                <button type="submit" class="btn btn-outline-secondary">
                                                                    <i class="fas fa-copy"></i>
                                                                </button>
                                                            </form>
                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Delete this scheme and its components?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                                                <button type="submit" class="btn btn-outline-danger">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
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
    <?php $showNew = (isset($_GET['action']) && $_GET['action'] === 'new'); ?>
    <div class="modal fade<?php echo $showNew ? ' show' : ''; ?>" id="newSchemeModal" tabindex="-1"
        style="<?php echo $showNew ? 'display:block;background:rgba(0,0,0,0.4);' : ''; ?>">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/assessment-schemes.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-plus-circle text-primary me-2"></i>New Assessment Scheme
                            <small class="text-muted d-block" style="font-size:12px;font-weight:400;margin-top:2px;">
                                The five standard components (Class Exercise, Class Test, Mid-Term, Project Assignment, Exam)
                                are created automatically with default weights that sum to 100.00. Adjust them afterwards
                                with the "Weights" button on the scheme row.
                            </small>
                        </h5>
                        <a href="/platform/tenant/academic/assessment-schemes.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label" for="newName">Scheme name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="newName" name="scheme_name" maxlength="100" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="newCode">Code</label>
                                <input type="text" class="form-control" id="newCode" name="scheme_code" maxlength="30" placeholder="Optional">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="newLevel"><?php echo h($labelLevel); ?></label>
                                <select class="form-select" id="newLevel" name="academic_level_id">
                                    <option value="0">(All levels — tenant-wide)</option>
                                    <?php foreach ($levels as $lv): ?>
                                        <option value="<?php echo (int)$lv['id']; ?>"><?php echo h($lv['level_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="newYear"><?php echo h($labelAcademic); ?></label>
                                <select class="form-select" id="newYear" name="academic_year_id">
                                    <option value="0">(All years)</option>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo (int)$y['id']; ?>"><?php echo h($y['year_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="newStatus">Status <span class="required">*</span></label>
                                <select class="form-select" id="newStatus" name="status" required>
                                    <option value="draft" selected>Draft</option>
                                    <option value="active">Active</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label" for="newFrom">Effective from</label>
                                <input type="date" class="form-control" id="newFrom" name="effective_from">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label" for="newTo">Effective to</label>
                                <input type="date" class="form-control" id="newTo" name="effective_to">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label" for="newDesc">Description</label>
                                <input type="text" class="form-control" id="newDesc" name="description" maxlength="500" placeholder="Optional">
                            </div>
                        </div>
                        <hr>
                        <div class="form-label">Components that will be created</div>
                        <div class="component-grid">
                            <?php foreach (ASSESSMENT_COMPONENTS as $c): ?>
                                <div class="component-row">
                                    <div>
                                        <div class="component-name"><?php echo h($c['name']); ?></div>
                                        <div class="component-code"><?php echo h($c['code']); ?></div>
                                    </div>
                                    <div class="text-muted" style="font-size:12px;">Default weight</div>
                                    <div style="font-weight:600;"><?php echo h(number_format($c['default_w'], 2)); ?>%</div>
                                    <div class="text-muted" style="font-size:12px;">Max <?php echo h(number_format($c['default_max'], 2)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="weight-summary ok" style="margin-top:12px;">
                            <i class="fas fa-scale-balanced"></i>
                            <span>Default total: <span class="ws-total">
                                    <?php echo h(number_format(array_sum(array_column(ASSESSMENT_COMPONENTS, 'default_w')), 2)); ?>
                                </span> of 100.00</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/assessment-schemes.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Create Scheme</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    $editScheme = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $editScheme = getScheme($db, $tenantId, (int)$_GET['id']);
    }
    ?>
    <?php if ($editScheme): ?>
        <div class="modal fade show" id="editSchemeModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/assessment-schemes.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editScheme['id']; ?>">
                        <div class="modal-header">
                            <h5>
                                <i class="fas fa-edit text-primary me-2"></i>
                                Edit Scheme
                                <?php if ($editScheme['status'] === 'active'): ?>
                                    <small class="text-muted d-block" style="font-size:12px;font-weight:400;margin-top:2px;">
                                        This scheme is active. Saving creates a new draft version (v<?php echo (int)$editScheme['version'] + 1; ?>);
                                        the current version will be archived, and its current component weights copied to the new draft.
                                    </small>
                                <?php endif; ?>
                            </h5>
                            <a href="/platform/tenant/academic/assessment-schemes.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="editName">Scheme name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="editName" name="scheme_name"
                                        value="<?php echo h($editScheme['scheme_name']); ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="editCode">Code</label>
                                    <input type="text" class="form-control" id="editCode" name="scheme_code"
                                        value="<?php echo h($editScheme['scheme_code']); ?>" maxlength="30">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editLevel"><?php echo h($labelLevel); ?></label>
                                    <select class="form-select" id="editLevel" name="academic_level_id">
                                        <option value="0">(All levels — tenant-wide)</option>
                                        <?php foreach ($levels as $lv): ?>
                                            <option value="<?php echo (int)$lv['id']; ?>"
                                                <?php echo (int)$editScheme['academic_level_id'] === (int)$lv['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($lv['level_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editYear"><?php echo h($labelAcademic); ?></label>
                                    <select class="form-select" id="editYear" name="academic_year_id">
                                        <option value="0">(All years)</option>
                                        <?php foreach ($years as $y): ?>
                                            <option value="<?php echo (int)$y['id']; ?>"
                                                <?php echo (int)$editScheme['academic_year_id'] === (int)$y['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($y['year_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editTotal">Total weight</label>
                                    <input type="text" class="form-control" id="editTotal"
                                        value="<?php echo h(number_format(ASSESSMENT_TOTAL_WEIGHT, 2)); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editStatus">Status</label>
                                    <input type="text" class="form-control" id="editStatus"
                                        value="<?php echo h(ucfirst($editScheme['status'])); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editFrom">Effective from</label>
                                    <input type="date" class="form-control" id="editFrom" name="effective_from"
                                        value="<?php echo h($editScheme['effective_from']); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editTo">Effective to</label>
                                    <input type="date" class="form-control" id="editTo" name="effective_to"
                                        value="<?php echo h($editScheme['effective_to']); ?>">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label" for="editDesc">Description</label>
                                    <input type="text" class="form-control" id="editDesc" name="description"
                                        value="<?php echo h($editScheme['description']); ?>" maxlength="500">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/assessment-schemes.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="modal fade" id="manageComponentsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" id="componentsForm" action="/platform/tenant/academic/assessment-schemes.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_components">
                    <input type="hidden" name="assessment_scheme_id" id="modalSchemeId" value="">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-sliders-h text-primary me-2"></i>
                            Component Weights
                            <small class="text-muted d-block" id="modalSchemeLabel" style="font-size:12px;font-weight:400;margin-top:2px;"></small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="component-grid">
                            <?php foreach (ASSESSMENT_COMPONENTS as $c):
                                $code = $c['code'];
                            ?>
                                <div class="component-row">
                                    <div>
                                        <div class="component-name"><?php echo h($c['name']); ?></div>
                                        <div class="component-code"><?php echo h($code); ?></div>
                                    </div>
                                    <div>
                                        <label class="form-label" style="font-size:11px;">Weight %</label>
                                        <input type="number" class="form-control"
                                            name="weight_by_code[<?php echo h($code); ?>]"
                                            data-code="<?php echo h($code); ?>"
                                            step="0.01" min="0" max="100" required
                                            value="<?php echo h(number_format($c['default_w'], 2, '.', '')); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" style="font-size:11px;">Max score</label>
                                        <input type="number" class="form-control"
                                            name="max_score_by_code[<?php echo h($code); ?>]"
                                            step="0.01" min="0.01" required
                                            value="<?php echo h(number_format($c['default_max'], 2, '.', '')); ?>">
                                    </div>
                                    <div class="text-muted" style="font-size:11px;">
                                        Must total 100.00 across all five.
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="weight-summary" id="modalWeightSummary">
                            <i class="fas fa-scale-balanced"></i>
                            <span>Components sum: <span class="ws-total" id="modalWeightSum">0.00</span> of 100.00</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Weights</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var HARDCODED_COMPONENTS = <?php echo $hardcodedComponentsJson; ?>;
        if (!Array.isArray(HARDCODED_COMPONENTS)) {
            HARDCODED_COMPONENTS = [];
        }
        var COMPONENTS_BY_SCHEME = <?php echo $componentsBySchemeJson; ?>;
        if (typeof COMPONENTS_BY_SCHEME !== 'object' || COMPONENTS_BY_SCHEME === null) {
            COMPONENTS_BY_SCHEME = {};
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
            const modalEl = document.getElementById('manageComponentsModal');
            const modal = new bootstrap.Modal(modalEl);
            const schemeIdIn = document.getElementById('modalSchemeId');
            const schemeLbl = document.getElementById('modalSchemeLabel');
            const sumEl = document.getElementById('modalWeightSum');
            const summaryBox = document.getElementById('modalWeightSummary');
            const form = document.getElementById('componentsForm');

            function recompute() {
                let sum = 0;
                form.querySelectorAll('input[name^="weight_by_code"]').forEach(function(inp) {
                    const v = parseFloat(inp.value);
                    if (!isNaN(v)) sum += v;
                });
                sumEl.textContent = sum.toFixed(2);
                summaryBox.classList.remove('ok', 'under', 'over');
                if (Math.abs(sum - 100.0) < 0.01) summaryBox.classList.add('ok');
                else if (sum < 100.0) summaryBox.classList.add('under');
                else summaryBox.classList.add('over');
            }
            document.querySelectorAll('.js-manage-components').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const sid = parseInt(this.getAttribute('data-scheme-id'), 10);
                    const label = this.getAttribute('data-scheme-label') || '';
                    schemeIdIn.value = sid;
                    schemeLbl.textContent = label;
                    const stored = COMPONENTS_BY_SCHEME[sid] || {};
                    form.querySelectorAll('input[name^="weight_by_code"]').forEach(function(inp) {
                        const code = inp.getAttribute('data-code');
                        if (stored[code] && stored[code].weight != null) {
                            inp.value = Number(stored[code].weight).toFixed(2);
                        } else {
                            const def = HARDCODED_COMPONENTS.find(function(c) {
                                return c.code === code;
                            });
                            inp.value = def ? Number(def.default_w).toFixed(2) : '0.00';
                        }
                    });
                    form.querySelectorAll('input[name^="max_score_by_code"]').forEach(function(inp) {
                        const row = inp.closest('.component-row');
                        const wInp = row ? row.querySelector('input[name^="weight_by_code"]') : null;
                        const c = wInp ? wInp.getAttribute('data-code') : null;
                        if (c && stored[c] && stored[c].max_score != null) {
                            inp.value = Number(stored[c].max_score).toFixed(2);
                        } else {
                            const def = HARDCODED_COMPONENTS.find(function(x) {
                                return x.code === c;
                            });
                            inp.value = def ? Number(def.default_max).toFixed(2) : '100.00';
                        }
                    });
                    recompute();
                    modal.show();
                });
            });
            form.addEventListener('input', recompute);
            form.addEventListener('submit', function(e) {
                let sum = 0;
                form.querySelectorAll('input[name^="weight_by_code"]').forEach(function(inp) {
                    const v = parseFloat(inp.value);
                    if (!isNaN(v)) sum += v;
                });
                if (Math.abs(sum - 100.0) > 0.01) {
                    e.preventDefault();
                    alert('The five weights must total exactly 100.00. They currently total ' + sum.toFixed(2) + '.');
                }
            });
        })();
        <?php if ($showNew): ?>
            new bootstrap.Modal(document.getElementById('newSchemeModal')).show();
        <?php endif; ?>
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