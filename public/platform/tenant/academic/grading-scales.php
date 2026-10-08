<?php

/**
 * Academic Grading Scales — Score-to-grade bands per level/year
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/grading-scales.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic grading-scales file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_grading_scales'
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
 * Decisions locked in (Session S15):
 *   D1  scale_name + score_min + score_max + status required;
 *       level/year/code/dates/description optional;
 *       bands require grade_symbol + score_from + score_to;
 *       is_pass defaults 1; label/remark/sort_order optional
 *   D2  unique per (tenant, scale_name, version) and (tenant, scale_code,
 *       version) for non-deleted scales; unique per (tenant, grading_scale_id,
 *       grade_symbol) for active bands; all via FOR UPDATE
 *   D3  always available; info banner if enable_multiple_grading_scales = 0
 *   D4  snapshot on activate — editing an active scale creates a new draft
 *       version, old one archived, bands copied over
 *   D5  allow tenant-wide scales (level NULL) plus level-scoped scales
 *   D6  enforce full coverage of [score_min, score_max] with no gaps and no
 *       overlaps before activating
 *   D7  refuse delete of scale or band if results reference them
 *   D8  scale-first flat list grouped by year → level, bands inline
 *   D9  modal per scale to manage bands, live coverage indicator
 *   D10 lifecycle actions: activate / archive / duplicate
 *   D11 validate effective_to >= effective_from when both set
 *   D12 create with no bands; admin adds bands before activation
 *
 * Fixes (post-S15 build):
 *   F1  Coverage analyzer now allows a 0.01 gap between consecutive bands
 *       (previous.to + 0.01 == next.from). Both the JS live indicator and
 *       the PHP activation gate use the same 0.015 tolerance.
 *
 * Session S20 decisions applied:
 *   SH2A per-session CSRF token; all POSTs carry it
 *   SH3A central Security.php helper, loaded via app/bootstrap.php
 *   SH4A hardened session started inside bootstrap
 *   SH5C h() on every echoed value; json_encode with HEX flags for inline JS
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
$pageTitle    = 'Grading Scales - Student 360 Platform';
$currentPage = 'academic';
$db = DatabaseHelper::getInstance();
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
function getScale($db, int $tenantId, int $scaleId): ?array
{
    if ($scaleId <= 0) return null;
    return $db->fetchOne(
        "SELECT s.*,
                al.level_name, al.level_code,
                ay.year_name
         FROM grading_scales s
         LEFT JOIN academic_levels al ON s.academic_level_id = al.id
         LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
         WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
        [$scaleId, $tenantId]
    ) ?: null;
}
function analyzeCoverage(array $bands, float $scoreMin, float $scoreMax): array
{
    $active = array_values(array_filter($bands, fn($b) => (int)$b['is_active'] === 1));
    usort($active, function ($a, $b) {
        $cmp = ((float)$a['score_from']) <=> ((float)$b['score_from']);
        if ($cmp !== 0) return $cmp;
        return ((float)$a['score_to']) <=> ((float)$b['score_to']);
    });
    $gaps = [];
    $overlaps = [];
    $missingLow = true;
    $missingHigh = true;
    if (!empty($active)) {
        if (abs((float)$active[0]['score_from'] - $scoreMin) < 0.005) {
            $missingLow = false;
        }
        $lastIdx = count($active) - 1;
        if (abs((float)$active[$lastIdx]['score_to'] - $scoreMax) < 0.005) {
            $missingHigh = false;
        }
    }
    $n = count($active);
    for ($i = 0; $i < $n - 1; $i++) {
        $a = $active[$i];
        $b = $active[$i + 1];
        $aTo   = (float)$a['score_to'];
        $bFrom = (float)$b['score_from'];
        if ($bFrom > $aTo + 0.015) {
            $gaps[] = [round($aTo + 0.01, 2), round($bFrom - 0.01, 2)];
        } elseif ($bFrom < $aTo - 0.005) {
            $overlaps[] = [round($bFrom, 2), round($aTo, 2), (string)$a['grade_symbol'], (string)$b['grade_symbol']];
        }
    }
    if ($missingLow && !empty($active)) {
        $gaps[] = [round($scoreMin, 2), round((float)$active[0]['score_from'] - 0.01, 2)];
    } elseif ($missingLow && empty($active)) {
        $gaps[] = [round($scoreMin, 2), round($scoreMax, 2)];
    }
    if ($missingHigh && !empty($active)) {
        $gaps[] = [round((float)$active[$n - 1]['score_to'] + 0.01, 2), round($scoreMax, 2)];
    }
    usort($gaps, fn($a, $b) => $a[0] <=> $b[0]);
    $covered = (empty($gaps) && empty($overlaps) && $n > 0);
    return [
        'gaps'         => $gaps,
        'overlaps'     => $overlaps,
        'covered'      => $covered,
        'totalActive'  => $n,
        'missingEdges' => ['low' => $missingLow, 'high' => $missingHigh],
    ];
}
function scaleDependencies($db, int $tenantId, int $scaleId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'results' => "SELECT COUNT(*) AS c FROM results
                      WHERE tenant_id = ? AND grading_scale_id = ? AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $scaleId]);
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
function bandDependencies($db, int $tenantId, int $ruleId): array
{
    $total = 0;
    $labels = [];
    $checks = [
        'results' => "SELECT COUNT(*) AS c FROM results
                      WHERE tenant_id = ? AND grade_rule_id = ? AND deleted_at IS NULL",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $row = $db->fetchOne($sql, [$tenantId, $ruleId]);
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
function normalizeBands(
    array $symbols,
    array $labels,
    array $froms,
    array $tos,
    array $remarks,
    array $passes,
    array $sortOrders,
    float $scoreMin,
    float $scoreMax
): array {
    $rows = [];
    $count = max(count($symbols), count($froms), count($tos));
    $seenSymbols = [];
    for ($i = 0; $i < $count; $i++) {
        $sym = trim((string)($symbols[$i] ?? ''));
        $lab = trim((string)($labels[$i] ?? ''));
        $f   = $froms[$i] ?? null;
        $t   = $tos[$i] ?? null;
        $rem = trim((string)($remarks[$i] ?? ''));
        $pass = isset($passes[$i]) ? 1 : 0;
        $so   = (int)($sortOrders[$i] ?? ($i * 10));
        if ($sym === '' && ($f === null || $f === '') && ($t === null || $t === '')) {
            continue;
        }
        if ($sym === '') throw new Exception('Every band needs a grade symbol.');
        if (mb_strlen($sym) > 10) throw new Exception('Grade symbol "' . $sym . '" is too long (max 10).');
        if ($f === null || $f === '' || !is_numeric($f)) {
            throw new Exception('Band "' . $sym . '" needs a numeric From value.');
        }
        if ($t === null || $t === '' || !is_numeric($t)) {
            throw new Exception('Band "' . $sym . '" needs a numeric To value.');
        }
        $f = (float)$f;
        $t = (float)$t;
        if ($f > $t) {
            throw new Exception('Band "' . $sym . '": From (' . $f . ') cannot be greater than To (' . $t . ').');
        }
        if ($f < $scoreMin - 0.005 || $t > $scoreMax + 0.005) {
            throw new Exception('Band "' . $sym . '": scores must lie within [' .
                number_format($scoreMin, 2) . ', ' . number_format($scoreMax, 2) . '].');
        }
        if (isset($seenSymbols[$sym])) {
            throw new Exception('Duplicate grade symbol "' . $sym . '".');
        }
        $seenSymbols[$sym] = true;
        $rows[] = [
            'grade_symbol' => $sym,
            'grade_label'  => $lab !== '' ? $lab : null,
            'score_from'   => $f,
            'score_to'     => $t,
            'remark'       => $rem !== '' ? $rem : null,
            'is_pass'      => $pass,
            'sort_order'   => $so,
        ];
    }
    usort($rows, function ($a, $b) {
        $cmp = $a['score_from'] <=> $b['score_from'];
        if ($cmp !== 0) return $cmp;
        return $a['score_to'] <=> $b['score_to'];
    });
    return $rows;
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
            $scaleName = trim((string)($_POST['scale_name'] ?? ''));
            $scaleCode = trim((string)($_POST['scale_code'] ?? ''));
            $levelId   = (int)($_POST['academic_level_id'] ?? 0);
            $yearId    = (int)($_POST['academic_year_id'] ?? 0);
            $scoreMin  = (float)($_POST['score_min'] ?? 0.00);
            $scoreMax  = (float)($_POST['score_max'] ?? 100.00);
            $effFrom   = trim((string)($_POST['effective_from'] ?? ''));
            $effTo     = trim((string)($_POST['effective_to'] ?? ''));
            $desc      = trim((string)($_POST['description'] ?? ''));
            $status    = trim((string)($_POST['status'] ?? 'draft'));
            if ($scaleName === '') $errors[] = 'Scale name is required.';
            if (!in_array($status, ['draft', 'active'], true)) $errors[] = 'Invalid status.';
            if ($scoreMin >= $scoreMax) $errors[] = 'Score max must be greater than score min.';
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
                "SELECT id FROM grading_scales
                 WHERE tenant_id = ? AND scale_name = ? AND version = 1 AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $scaleName]
            );
            if ($dup) throw new Exception('A grading scale with this name already exists (version 1).');
            if ($scaleCode !== '') {
                $dupCode = $db->fetchOne(
                    "SELECT id FROM grading_scales
                     WHERE tenant_id = ? AND scale_code = ? AND version = 1 AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $scaleCode]
                );
                if ($dupCode) throw new Exception('A grading scale with this code already exists (version 1).');
            }
            $newId = $db->insert(
                "INSERT INTO grading_scales
                    (uuid, tenant_id, scale_name, scale_code,
                     academic_level_id, academic_year_id, version, status,
                     effective_from, effective_to, score_min, score_max, description,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $scaleName,
                    $scaleCode !== '' ? $scaleCode : null,
                    $levelId > 0 ? $levelId : null,
                    $yearId > 0 ? $yearId : null,
                    $status,
                    $effFrom !== '' ? $effFrom : null,
                    $effTo   !== '' ? $effTo   : null,
                    $scoreMin,
                    $scoreMax,
                    $desc !== '' ? $desc : null,
                    $userId ?: null,
                ]
            );
            if ($status === 'active') {
                throw new Exception(
                    'Cannot create as active: a grading scale must have bands covering its range. ' .
                        'Create it as draft, add bands, then activate.'
                );
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.grading_scale.created',
                'grading_scales',
                (int)$newId,
                [
                    'name' => $scaleName,
                    'version' => 1,
                    'status' => 'draft',
                    'level_id' => $levelId,
                    'year_id' => $yearId,
                    'score_min' => $scoreMin,
                    'score_max' => $scoreMax
                ]
            );
            $db->commit();
            $_SESSION['success'] = 'Grading scale created as draft. Add bands before activating.';
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        // ---------------- UPDATE (draft in place; active -> snapshot D4-B) ----------------
        if ($action === 'update') {
            $id        = (int)($_POST['id'] ?? 0);
            $scaleName = trim((string)($_POST['scale_name'] ?? ''));
            $scaleCode = trim((string)($_POST['scale_code'] ?? ''));
            $levelId   = (int)($_POST['academic_level_id'] ?? 0);
            $yearId    = (int)($_POST['academic_year_id'] ?? 0);
            $scoreMin  = (float)($_POST['score_min'] ?? 0.00);
            $scoreMax  = (float)($_POST['score_max'] ?? 100.00);
            $effFrom   = trim((string)($_POST['effective_from'] ?? ''));
            $effTo     = trim((string)($_POST['effective_to'] ?? ''));
            $desc      = trim((string)($_POST['description'] ?? ''));
            $scale = getScale($db, $tenantId, $id);
            if (!$scale) throw new Exception('Grading scale not found.');
            if ($scaleName === '') $errors[] = 'Scale name is required.';
            if ($scoreMin >= $scoreMax) $errors[] = 'Score max must be greater than score min.';
            if ($effFrom !== '' && $effTo !== '' && strtotime($effTo) < strtotime($effFrom)) {
                $errors[] = 'Effective-to date must be on or after effective-from.';
            }
            if (!empty($errors)) throw new Exception(implode(' ', $errors));
            $db->beginTransaction();
            if ($scale['status'] === 'draft') {
                $dup = $db->fetchOne(
                    "SELECT id FROM grading_scales
                     WHERE tenant_id = ? AND scale_name = ? AND version = ?
                       AND id != ? AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $scaleName, (int)$scale['version'], $id]
                );
                if ($dup) throw new Exception('Another scale already uses this name at this version.');
                if ($scaleCode !== '') {
                    $dupCode = $db->fetchOne(
                        "SELECT id FROM grading_scales
                         WHERE tenant_id = ? AND scale_code = ? AND version = ?
                           AND id != ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $scaleCode, (int)$scale['version'], $id]
                    );
                    if ($dupCode) throw new Exception('Another scale already uses this code at this version.');
                }
                $db->execute(
                    "UPDATE grading_scales
                        SET scale_name = ?, scale_code = ?,
                            academic_level_id = ?, academic_year_id = ?,
                            score_min = ?, score_max = ?,
                            effective_from = ?, effective_to = ?,
                            description = ?, updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [
                        $scaleName,
                        $scaleCode !== '' ? $scaleCode : null,
                        $levelId > 0 ? $levelId : null,
                        $yearId > 0 ? $yearId : null,
                        $scoreMin,
                        $scoreMax,
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
                    'academic.grading_scale.updated',
                    'grading_scales',
                    $id,
                    ['name' => $scaleName, 'version' => (int)$scale['version']]
                );
                $_SESSION['success'] = 'Draft grading scale updated.';
            } else {
                $newVersion = (int)$scale['version'] + 1;
                $dup = $db->fetchOne(
                    "SELECT id FROM grading_scales
                     WHERE tenant_id = ? AND scale_name = ? AND version = ?
                       AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $scaleName, $newVersion]
                );
                if ($dup) throw new Exception('A draft for version ' . $newVersion . ' already exists.');
                if ($scaleCode !== '') {
                    $dupCode = $db->fetchOne(
                        "SELECT id FROM grading_scales
                         WHERE tenant_id = ? AND scale_code = ? AND version = ?
                           AND deleted_at IS NULL
                         FOR UPDATE",
                        [$tenantId, $scaleCode, $newVersion]
                    );
                    if ($dupCode) throw new Exception('A draft using this code at version ' . $newVersion . ' already exists.');
                }
                $db->execute(
                    "UPDATE grading_scales
                        SET status = 'archived', updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$id, $tenantId]
                );
                $newId = $db->insert(
                    "INSERT INTO grading_scales
                        (uuid, tenant_id, scale_name, scale_code,
                         academic_level_id, academic_year_id, version, status,
                         effective_from, effective_to, score_min, score_max, description,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $scaleName,
                        $scaleCode !== '' ? $scaleCode : null,
                        $levelId > 0 ? $levelId : null,
                        $yearId > 0 ? $yearId : null,
                        $newVersion,
                        $effFrom !== '' ? $effFrom : null,
                        $effTo   !== '' ? $effTo   : null,
                        $scoreMin,
                        $scoreMax,
                        $desc !== '' ? $desc : null,
                        $userId ?: null,
                    ]
                );
                $oldBands = $db->fetchAll(
                    "SELECT grade_symbol, grade_label, score_from, score_to, remark, is_pass, sort_order, is_active
                     FROM grade_rules
                     WHERE tenant_id = ? AND grading_scale_id = ?
                       AND deleted_at IS NULL
                     ORDER BY sort_order ASC, id ASC",
                    [$tenantId, $id]
                );
                foreach ($oldBands as $b) {
                    $db->insert(
                        "INSERT INTO grade_rules
                            (uuid, tenant_id, grading_scale_id, grade_symbol, grade_label,
                             score_from, score_to, remark, is_pass, sort_order, is_active,
                             created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [
                            uuidv4(),
                            $tenantId,
                            $newId,
                            $b['grade_symbol'],
                            $b['grade_label'],
                            $b['score_from'],
                            $b['score_to'],
                            $b['remark'],
                            $b['is_pass'],
                            $b['sort_order'],
                            $b['is_active'],
                        ]
                    );
                }
                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.grading_scale.snapshotted',
                    'grading_scales',
                    (int)$newId,
                    [
                        'parent_id' => $id,
                        'name' => $scaleName,
                        'archived_version' => (int)$scale['version'],
                        'new_version' => $newVersion
                    ]
                );
                $_SESSION['success'] = 'Active scale archived; new draft version ' . $newVersion . ' created.';
            }
            $db->commit();
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        // ---------------- ACTIVATE (D6) ----------------
        if ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            $scale = getScale($db, $tenantId, $id);
            if (!$scale) throw new Exception('Grading scale not found.');
            if ($scale['status'] === 'active') {
                $_SESSION['success'] = 'Grading scale is already active.';
                header('Location: /platform/tenant/academic/grading-scales.php');
                exit;
            }
            $db->beginTransaction();
            $bands = $db->fetchAll(
                "SELECT grade_symbol, score_from, score_to, is_active
                 FROM grade_rules
                 WHERE tenant_id = ? AND grading_scale_id = ? AND deleted_at IS NULL",
                [$tenantId, $id]
            );
            $cov = analyzeCoverage($bands, (float)$scale['score_min'], (float)$scale['score_max']);
            if (!$cov['covered']) {
                $msgs = [];
                if (!empty($cov['gaps'])) {
                    foreach ($cov['gaps'] as $g) {
                        $msgs[] = 'gap ' . number_format($g[0], 2) . '–' . number_format($g[1], 2);
                    }
                }
                if (!empty($cov['overlaps'])) {
                    foreach ($cov['overlaps'] as $o) {
                        $msgs[] = 'overlap ' . number_format($o[0], 2) . '–' . number_format($o[1], 2) .
                            ' between "' . $o[2] . '" and "' . $o[3] . '"';
                    }
                }
                if (empty($msgs)) $msgs[] = 'no bands defined';
                throw new Exception(
                    'Cannot activate: bands do not fully cover [' .
                        number_format((float)$scale['score_min'], 2) . ', ' .
                        number_format((float)$scale['score_max'], 2) . ']. Found: ' . implode('; ', $msgs) . '.'
                );
            }
            $db->execute(
                "UPDATE grading_scales
                    SET status = 'active', updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.grading_scale.activated',
                'grading_scales',
                $id,
                [
                    'name' => $scale['scale_name'],
                    'version' => (int)$scale['version'],
                    'bands' => (int)$cov['totalActive']
                ]
            );
            $db->commit();
            $_SESSION['success'] = 'Grading scale activated.';
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        // ---------------- ARCHIVE ----------------
        if ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            $scale = getScale($db, $tenantId, $id);
            if (!$scale) throw new Exception('Grading scale not found.');
            $db->beginTransaction();
            $db->execute(
                "UPDATE grading_scales
                    SET status = 'archived', updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.grading_scale.archived',
                'grading_scales',
                $id,
                ['name' => $scale['scale_name'], 'version' => (int)$scale['version']]
            );
            $db->commit();
            $_SESSION['success'] = 'Grading scale archived.';
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        // ---------------- DUPLICATE (D10) ----------------
        if ($action === 'duplicate') {
            $id = (int)($_POST['id'] ?? 0);
            $scale = getScale($db, $tenantId, $id);
            if (!$scale) throw new Exception('Grading scale not found.');
            $db->beginTransaction();
            $nextRow = $db->fetchOne(
                "SELECT COALESCE(MAX(version), 0) + 1 AS v
                 FROM grading_scales
                 WHERE tenant_id = ? AND scale_name = ? AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $scale['scale_name']]
            );
            $newVersion = (int)($nextRow['v'] ?? 1);
            if ($newVersion < 1) $newVersion = 1;
            $newId = $db->insert(
                "INSERT INTO grading_scales
                    (uuid, tenant_id, scale_name, scale_code,
                     academic_level_id, academic_year_id, version, status,
                     effective_from, effective_to, score_min, score_max, description,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $scale['scale_name'],
                    $scale['scale_code'],
                    $scale['academic_level_id'],
                    $scale['academic_year_id'],
                    $newVersion,
                    $scale['effective_from'],
                    $scale['effective_to'],
                    $scale['score_min'],
                    $scale['score_max'],
                    $scale['description'],
                    $userId ?: null,
                ]
            );
            $oldBands = $db->fetchAll(
                "SELECT grade_symbol, grade_label, score_from, score_to, remark, is_pass, sort_order, is_active
                 FROM grade_rules
                 WHERE tenant_id = ? AND grading_scale_id = ?
                   AND deleted_at IS NULL
                 ORDER BY sort_order ASC, id ASC",
                [$tenantId, $id]
            );
            foreach ($oldBands as $b) {
                $db->insert(
                    "INSERT INTO grade_rules
                        (uuid, tenant_id, grading_scale_id, grade_symbol, grade_label,
                         score_from, score_to, remark, is_pass, sort_order, is_active,
                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $newId,
                        $b['grade_symbol'],
                        $b['grade_label'],
                        $b['score_from'],
                        $b['score_to'],
                        $b['remark'],
                        $b['is_pass'],
                        $b['sort_order'],
                        $b['is_active'],
                    ]
                );
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.grading_scale.duplicated',
                'grading_scales',
                (int)$newId,
                ['source_id' => $id, 'name' => $scale['scale_name'], 'new_version' => $newVersion]
            );
            $db->commit();
            $_SESSION['success'] = 'Grading scale duplicated as draft version ' . $newVersion . '.';
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        // ---------------- DELETE (D7) ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $scale = getScale($db, $tenantId, $id);
            if (!$scale) throw new Exception('Grading scale not found.');
            $deps = scaleDependencies($db, $tenantId, $id);
            if ($deps['total'] > 0) {
                throw new Exception(
                    'Cannot delete this grading scale. It is referenced by: ' .
                        implode(', ', $deps['labels']) .
                        '. Archive it instead, or remove those references first.'
                );
            }
            $db->beginTransaction();
            $bandRefs = $db->fetchOne(
                "SELECT COUNT(*) AS c FROM results r
                 JOIN grade_rules g ON r.grade_rule_id = g.id
                 WHERE r.tenant_id = ? AND g.grading_scale_id = ? AND r.deleted_at IS NULL",
                [$tenantId, $id]
            );
            if ((int)($bandRefs['c'] ?? 0) > 0) {
                throw new Exception(
                    'Cannot delete: some bands of this scale are referenced by results.'
                );
            }
            $db->execute(
                "UPDATE grade_rules
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = ? AND grading_scale_id = ? AND deleted_at IS NULL",
                [$tenantId, $id]
            );
            $db->execute(
                "UPDATE grading_scales
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.grading_scale.deleted',
                'grading_scales',
                $id,
                ['name' => $scale['scale_name'], 'version' => (int)$scale['version']]
            );
            $db->commit();
            $_SESSION['success'] = 'Grading scale deleted.';
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        // ---------------- SAVE BANDS (D9) ----------------
        if ($action === 'save_bands') {
            $scaleId = (int)($_POST['grading_scale_id'] ?? 0);
            $scale = getScale($db, $tenantId, $scaleId);
            if (!$scale) throw new Exception('Grading scale not found.');
            $symbols = is_array($_POST['grade_symbol'] ?? null) ? $_POST['grade_symbol'] : [];
            $labels  = is_array($_POST['grade_label'] ?? null) ? $_POST['grade_label'] : [];
            $froms   = is_array($_POST['score_from'] ?? null) ? $_POST['score_from'] : [];
            $tos     = is_array($_POST['score_to'] ?? null) ? $_POST['score_to'] : [];
            $remarks = is_array($_POST['remark'] ?? null) ? $_POST['remark'] : [];
            $passes  = is_array($_POST['is_pass'] ?? null) ? $_POST['is_pass'] : [];
            $sorts   = is_array($_POST['band_sort_order'] ?? null) ? $_POST['band_sort_order'] : [];
            $normalized = normalizeBands(
                $symbols,
                $labels,
                $froms,
                $tos,
                $remarks,
                $passes,
                $sorts,
                (float)$scale['score_min'],
                (float)$scale['score_max']
            );
            $db->beginTransaction();
            $db->execute(
                "UPDATE grade_rules
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = ? AND grading_scale_id = ? AND deleted_at IS NULL",
                [$tenantId, $scaleId]
            );
            $inserted = 0;
            foreach ($normalized as $b) {
                $db->insert(
                    "INSERT INTO grade_rules
                        (uuid, tenant_id, grading_scale_id, grade_symbol, grade_label,
                         score_from, score_to, remark, is_pass, sort_order, is_active,
                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $scaleId,
                        $b['grade_symbol'],
                        $b['grade_label'],
                        $b['score_from'],
                        $b['score_to'],
                        $b['remark'],
                        $b['is_pass'],
                        $b['sort_order'],
                    ]
                );
                $inserted++;
            }
            if ($scale['status'] === 'active') {
                $bandsNow = $db->fetchAll(
                    "SELECT grade_symbol, score_from, score_to, is_active
                     FROM grade_rules
                     WHERE tenant_id = ? AND grading_scale_id = ? AND deleted_at IS NULL",
                    [$tenantId, $scaleId]
                );
                $cov = analyzeCoverage($bandsNow, (float)$scale['score_min'], (float)$scale['score_max']);
                if (!$cov['covered']) {
                    throw new Exception(
                        'Cannot save bands on an active scale unless they fully cover the range. ' .
                            'Edit the scale first to snapshot it into a new draft.'
                    );
                }
            }
            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.grading_scale.bands_saved',
                'grading_scales',
                $scaleId,
                ['bands' => $inserted]
            );
            $db->commit();
            $_SESSION['success'] = 'Bands saved (' . $inserted . ').';
            header('Location: /platform/tenant/academic/grading-scales.php');
            exit;
        }
        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic grading scales action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/grading-scales.php');
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
$enableMultipleScales = (int)($settings['enable_multiple_grading_scales'] ?? 1);
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
$scales = $db->fetchAll(
    "SELECT s.*,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            ay.year_name, ay.start_date AS year_start,
            (SELECT COUNT(*) FROM grade_rules g
              WHERE g.tenant_id = s.tenant_id
                AND g.grading_scale_id = s.id
                AND g.deleted_at IS NULL) AS band_count
     FROM grading_scales s
     LEFT JOIN academic_levels al ON s.academic_level_id = al.id
     LEFT JOIN academic_years  ay ON s.academic_year_id = ay.id
     WHERE $whereClause
     ORDER BY
        CASE s.status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
        s.scale_name ASC, s.version DESC",
    $params
);
$bandsByScale = [];
if (!empty($scales)) {
    $ids = array_map(fn($s) => (int)$s['id'], $scales);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT * FROM grade_rules
         WHERE tenant_id = ? AND grading_scale_id IN ($in) AND deleted_at IS NULL
         ORDER BY sort_order ASC, score_from ASC, id ASC",
        array_merge([$tenantId], $ids)
    );
    foreach ($rows as $r) {
        $sid = (int)$r['grading_scale_id'];
        if (!isset($bandsByScale[$sid])) $bandsByScale[$sid] = [];
        $bandsByScale[$sid][] = $r;
    }
}
$coverageByScale = [];
foreach ($scales as $s) {
    $sid = (int)$s['id'];
    $coverageByScale[$sid] = analyzeCoverage(
        $bandsByScale[$sid] ?? [],
        (float)$s['score_min'],
        (float)$s['score_max']
    );
}
$byYear = [];
foreach ($scales as $s) {
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
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}
$totalScales  = count($scales);
$activeScales = 0;
$draftScales  = 0;
foreach ($scales as $s) {
    if ($s['status'] === 'active') $activeScales++;
    elseif ($s['status'] === 'draft') $draftScales++;
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

        .info-banner.warn {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: #fbbf24;
        }

        .info-banner.warn .ib-icon {
            background: rgba(251, 191, 36, 0.18);
            color: #b45309;
        }

        .info-banner.warn .ib-title {
            color: #92400e;
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

        .scale-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .scale-table thead th {
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

        .scale-table tbody td {
            padding: 12px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .scale-table tbody tr:last-child td {
            border-bottom: none;
        }

        .scale-table tbody tr:hover {
            background: #fafbfc;
        }

        .scale-title {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .scale-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .band-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .band-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #eef2ff;
            color: #3730a3;
        }

        .band-chip.pass {
            background: #ecfdf5;
            color: #065f46;
        }

        .band-chip.fail {
            background: #fef2f2;
            color: #991b1b;
        }

        .coverage-track {
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

        .coverage-fill {
            height: 100%;
            transition: width 0.3s;
        }

        .coverage-ok {
            background: linear-gradient(90deg, #22c55e, #16a34a);
        }

        .coverage-under {
            background: linear-gradient(90deg, #fbbf24, #d97706);
        }

        .coverage-over {
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
            max-width: 960px;
        }

        .bands-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
        }

        .band-row {
            display: grid;
            grid-template-columns: 90px 1.4fr 90px 90px 1.4fr 70px 70px 34px;
            gap: 8px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #eef0f3;
        }

        .band-row .form-control {
            height: 34px;
            padding: 4px 8px;
            font-size: 12px;
        }

        .band-row .pass-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #495057;
        }

        .band-row .pass-toggle input {
            width: 16px;
            height: 16px;
        }

        .band-row .row-remove {
            background: transparent;
            border: none;
            color: #dc3545;
            font-size: 14px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .band-row .row-remove:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        .coverage-summary {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            margin-top: 12px;
            font-size: 12px;
        }

        .coverage-summary.ok {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .coverage-summary.under {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .coverage-summary.over {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .coverage-summary ul {
            margin: 4px 0 0 20px;
            padding: 0;
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

            .scale-table {
                font-size: 12px;
            }

            .scale-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .scale-table tbody td {
                padding: 8px 10px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
            }

            .band-row {
                grid-template-columns: 1fr 1fr;
            }

            .band-row .row-remove {
                grid-column: span 2;
                justify-self: end;
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
                        <h1><i class="fas fa-layer-group me-2"></i>Grading Scales</h1>
                        <p>Score-to-grade bands per level and year</p>
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
                                <?php echo h((string)$totalScales); ?> scale(s) ·
                                <?php echo h((string)$activeScales); ?> active ·
                                <?php echo h((string)$draftScales); ?> draft
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>Versioned
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
                        <div class="ib-title">Scales define what a score means</div>
                        <div class="ib-text">
                            A scale belongs to a level (or is tenant-wide when the level is left blank) and optionally to a year.
                            It maps each score range to a grade symbol and a pass/fail flag.
                            For the scale to activate, its bands must cover the entire
                            <strong>[score_min, score_max]</strong> range with <strong>no gaps and no overlaps</strong>.
                            Editing an active scale creates a new draft version; the previous version is archived.
                        </div>
                    </div>
                </div>
                <?php if (!$enableMultipleScales): ?>
                    <div class="info-banner warn">
                        <div class="ib-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="ib-body">
                            <div class="ib-title">Multiple grading scales is disabled</div>
                            <div class="ib-text">
                                Your academic settings have <strong>enable_multiple_grading_scales = 0</strong>.
                                You can still create and edit scales here, but downstream modules
                                (results, promotion) will only use one scale. Change this in
                                <a href="/platform/tenant/academic/settings.php">Academic Settings</a> if you need multiple active scales.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <!-- Filter bar -->
                <form method="GET" action="/platform/tenant/academic/grading-scales.php" class="filters-bar">
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
                        <a href="/platform/tenant/academic/grading-scales.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/grading-scales.php?action=new" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i> New Scale
                        </a>
                    </div>
                </form>
                <!-- ================================================
                     GROUPED BY YEAR -> LEVEL -> SCALE
                ================================================= -->
                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-layer-group"></i>
                            <h5>No grading scales yet</h5>
                            <p>Create a scale to define how scores become grades for a level.</p>
                            <a href="/platform/tenant/academic/grading-scales.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Create Grading Scale
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
                                    $scaleCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) {
                                        $scaleCount += count($lg['items']);
                                    }
                                    ?>
                                    <span class="pill blue"><i class="fas fa-layer-group"></i><?php echo h((string)$scaleCount); ?> scale(s)</span>
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
                                    <table class="scale-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:200px;">Scale</th>
                                                <th style="text-align:center;">Version</th>
                                                <th style="text-align:center;">Status</th>
                                                <th style="min-width:220px;">Bands</th>
                                                <th style="min-width:170px;">Coverage</th>
                                                <th style="text-align:right;min-width:320px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $s):
                                                $sid       = (int)$s['id'];
                                                $version   = (int)$s['version'];
                                                $status    = $s['status'];
                                                $cov       = $coverageByScale[$sid] ?? ['covered' => false, 'gaps' => [], 'overlaps' => [], 'totalActive' => 0];
                                                $statusPill = ['draft' => 'gray', 'active' => 'green', 'archived' => 'orange'][$status] ?? 'gray';
                                                $activeBands = array_filter($bandsByScale[$sid] ?? [], fn($b) => (int)$b['is_active'] === 1);
                                                usort($activeBands, fn($a, $b) => (float)$a['score_from'] <=> (float)$b['score_from']);
                                                $covState = 'ok';
                                                if (!$cov['covered']) {
                                                    if (!empty($cov['overlaps'])) $covState = 'over';
                                                    else $covState = 'under';
                                                }
                                                $rangeSpan = max(0.01, (float)$s['score_max'] - (float)$s['score_min']);
                                                $coveredRange = 0;
                                                foreach ($activeBands as $b) {
                                                    $coveredRange += max(0, (float)$b['score_to'] - (float)$b['score_from']);
                                                }
                                                $fillPct = min(100, max(0, ($coveredRange / $rangeSpan) * 100));
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="scale-title">
                                                            <?php echo h($s['scale_name']); ?>
                                                            <?php if (!empty($s['scale_code'])): ?>
                                                                <span class="pill blue" style="margin-left:6px;"><?php echo h($s['scale_code']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="scale-meta">
                                                            Range: <?php echo number_format((float)$s['score_min'], 2); ?>
                                                            – <?php echo number_format((float)$s['score_max'], 2); ?>
                                                            <?php if (!empty($s['effective_from']) || !empty($s['effective_to'])): ?>
                                                                · <?php echo h($s['effective_from'] ?: '—'); ?>
                                                                → <?php echo h($s['effective_to'] ?: '—'); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill teal">v<?php echo h((string)$version); ?></span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo $statusPill; ?>"><?php echo h(ucfirst($status)); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if (empty($activeBands)): ?>
                                                            <span class="pill gray">No bands</span>
                                                        <?php else: ?>
                                                            <div class="band-chips">
                                                                <?php foreach ($activeBands as $b): ?>
                                                                    <span class="band-chip <?php echo (int)$b['is_pass'] === 1 ? 'pass' : 'fail'; ?>"
                                                                        title="<?php echo h($b['grade_label'] ?: ''); ?> <?php echo (int)$b['is_pass'] === 1 ? 'Pass' : 'Fail'; ?>">
                                                                        <?php echo h($b['grade_symbol']); ?>
                                                                        <span style="font-weight:400;">
                                                                            <?php echo number_format((float)$b['score_from'], 0); ?>–<?php echo number_format((float)$b['score_to'], 0); ?>
                                                                        </span>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="coverage-track">
                                                            <div class="coverage-fill coverage-<?php echo $covState; ?>" style="width: <?php echo number_format($fillPct, 2); ?>%;"></div>
                                                        </div>
                                                        <?php if ($cov['covered']): ?>
                                                            <span class="pill green" style="margin-left:6px;">
                                                                <i class="fas fa-check"></i> Covered
                                                            </span>
                                                        <?php elseif (!empty($cov['overlaps'])): ?>
                                                            <span class="pill red" style="margin-left:6px;">
                                                                <i class="fas fa-exclamation-triangle"></i> Overlaps
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="pill orange" style="margin-left:6px;">
                                                                <i class="fas fa-exclamation-circle"></i> Gaps
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <button type="button"
                                                                class="btn btn-outline-primary js-manage-bands"
                                                                data-scale-id="<?php echo $sid; ?>"
                                                                data-scale-label="<?php echo h($s['scale_name'] . ' v' . $version); ?>"
                                                                data-score-min="<?php echo h((string)(float)$s['score_min']); ?>"
                                                                data-score-max="<?php echo h((string)(float)$s['score_max']); ?>">
                                                                <i class="fas fa-list-ol"></i> Bands
                                                            </button>
                                                            <a href="/platform/tenant/academic/grading-scales.php?action=edit&id=<?php echo $sid; ?>"
                                                                class="btn btn-outline-secondary">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </a>
                                                            <?php if ($status !== 'active'): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Activate this grading scale?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="activate">
                                                                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                                                    <button type="submit" class="btn btn-outline-success">
                                                                        <i class="fas fa-check"></i> Activate
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Archive this active grading scale?');">
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
                                                                onsubmit="return confirm('Delete this scale and its bands?');">
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
    <!-- ================================================
         CREATE MODAL ( ?action=new ) — no bands; D12
    ================================================= -->
    <?php $showNew = (isset($_GET['action']) && $_GET['action'] === 'new'); ?>
    <div class="modal fade<?php echo $showNew ? ' show' : ''; ?>" id="newScaleModal" tabindex="-1"
        style="<?php echo $showNew ? 'display:block;background:rgba(0,0,0,0.4);' : ''; ?>">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/grading-scales.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-plus-circle text-primary me-2"></i>New Grading Scale
                            <small class="text-muted d-block" style="font-size:12px;font-weight:400;margin-top:2px;">
                                The scale is created as a <strong>draft with no bands</strong>.
                                Add bands via the "Bands" button, then activate it once the range is fully covered.
                            </small>
                        </h5>
                        <a href="/platform/tenant/academic/grading-scales.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label" for="newName">Scale name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="newName" name="scale_name" maxlength="100" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="newCode">Code</label>
                                <input type="text" class="form-control" id="newCode" name="scale_code" maxlength="30" placeholder="Optional">
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
                            <div class="col-md-3 mb-3">
                                <label class="form-label" for="newMin">Score min <span class="required">*</span></label>
                                <input type="number" step="0.01" class="form-control" id="newMin" name="score_min" value="0.00" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label" for="newMax">Score max <span class="required">*</span></label>
                                <input type="number" step="0.01" class="form-control" id="newMax" name="score_max" value="100.00" required>
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
                            <div class="col-md-12">
                                <input type="hidden" name="status" value="draft">
                                <div class="form-text">
                                    Scales are always created as draft. Once bands fully cover the range, activate the scale.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/grading-scales.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Create Scale</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- ================================================
         EDIT MODAL ( ?action=edit&id=X )
    ================================================= -->
    <?php
    $editScale = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $editScale = getScale($db, $tenantId, (int)$_GET['id']);
    }
    ?>
    <?php if ($editScale): ?>
        <div class="modal fade show" id="editScaleModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/grading-scales.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editScale['id']; ?>">
                        <div class="modal-header">
                            <h5>
                                <i class="fas fa-edit text-primary me-2"></i>
                                Edit Grading Scale
                                <?php if ($editScale['status'] === 'active'): ?>
                                    <small class="text-muted d-block" style="font-size:12px;font-weight:400;margin-top:2px;">
                                        This scale is active. Saving creates a new draft version (v<?php echo (int)$editScale['version'] + 1; ?>);
                                        the current version will be archived, and its bands copied over.
                                    </small>
                                <?php endif; ?>
                            </h5>
                            <a href="/platform/tenant/academic/grading-scales.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="editName">Scale name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="editName" name="scale_name"
                                        value="<?php echo h($editScale['scale_name']); ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="editCode">Code</label>
                                    <input type="text" class="form-control" id="editCode" name="scale_code"
                                        value="<?php echo h($editScale['scale_code']); ?>" maxlength="30">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editLevel"><?php echo h($labelLevel); ?></label>
                                    <select class="form-select" id="editLevel" name="academic_level_id">
                                        <option value="0">(All levels — tenant-wide)</option>
                                        <?php foreach ($levels as $lv): ?>
                                            <option value="<?php echo (int)$lv['id']; ?>"
                                                <?php echo (int)$editScale['academic_level_id'] === (int)$lv['id'] ? 'selected' : ''; ?>>
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
                                                <?php echo (int)$editScale['academic_year_id'] === (int)$y['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($y['year_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editMin">Score min <span class="required">*</span></label>
                                    <input type="number" step="0.01" class="form-control" id="editMin" name="score_min"
                                        value="<?php echo h(number_format((float)$editScale['score_min'], 2, '.', '')); ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editMax">Score max <span class="required">*</span></label>
                                    <input type="number" step="0.01" class="form-control" id="editMax" name="score_max"
                                        value="<?php echo h(number_format((float)$editScale['score_max'], 2, '.', '')); ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editStatus">Status</label>
                                    <input type="text" class="form-control" id="editStatus"
                                        value="<?php echo h(ucfirst($editScale['status'])); ?>" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editFrom">Effective from</label>
                                    <input type="date" class="form-control" id="editFrom" name="effective_from"
                                        value="<?php echo h($editScale['effective_from']); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editTo">Effective to</label>
                                    <input type="date" class="form-control" id="editTo" name="effective_to"
                                        value="<?php echo h($editScale['effective_to']); ?>">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label" for="editDesc">Description</label>
                                    <input type="text" class="form-control" id="editDesc" name="description"
                                        value="<?php echo h($editScale['description']); ?>" maxlength="500">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/grading-scales.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <!-- ================================================
         MANAGE BANDS MODAL (D9)
    ================================================= -->
    <div class="modal fade" id="manageBandsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" id="bandsForm" action="/platform/tenant/academic/grading-scales.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_bands">
                    <input type="hidden" name="grading_scale_id" id="modalScaleId" value="">
                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-list-ol text-primary me-2"></i>
                            Manage Bands
                            <small class="text-muted d-block" id="modalScaleLabel" style="font-size:12px;font-weight:400;margin-top:2px;"></small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="form-text m-0">Blank rows are ignored on save. Bands must cover the full range with no gaps and no overlaps.</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="addBandRow">
                                <i class="fas fa-plus me-1"></i> Add band
                            </button>
                        </div>
                        <div class="bands-grid" id="bandsGrid">
                            <!-- populated by JS -->
                        </div>
                        <div class="coverage-summary" id="bandsCoverageSummary">
                            <i class="fas fa-chart-simple"></i>
                            <div>
                                <div id="coverageText">Add bands to see coverage.</div>
                                <ul id="coverageDetailList" style="display:none;"></ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save Bands</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const BANDS_BY_SCALE = <?php echo json_encode($bandsByScale, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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
        // ============================================================
        // Bands modal
        // ============================================================
        (function() {
            const modalEl = document.getElementById('manageBandsModal');
            const modal = new bootstrap.Modal(modalEl);
            const gridEl = document.getElementById('bandsGrid');
            const scaleIdIn = document.getElementById('modalScaleId');
            const scaleLbl = document.getElementById('modalScaleLabel');
            const coverText = document.getElementById('coverageText');
            const coverDetail = document.getElementById('coverageDetailList');
            const coverBox = document.getElementById('bandsCoverageSummary');
            const form = document.getElementById('bandsForm');
            let currentMin = 0;
            let currentMax = 100;

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function buildRow(b) {
                b = b || {};
                const from = b.score_from != null ? Number(b.score_from).toFixed(2) : '';
                const to = b.score_to != null ? Number(b.score_to).toFixed(2) : '';
                const sym = b.grade_symbol || '';
                const lab = b.grade_label || '';
                const rem = b.remark || '';
                const pass = b.is_pass != null ? (Number(b.is_pass) === 1) : true;
                const so = b.sort_order != null ? parseInt(b.sort_order, 10) : '';
                return `
                    <div class="band-row">
                        <input type="text" class="form-control" name="grade_symbol[]" placeholder="A1" maxlength="10" value="${escapeHtml(sym)}">
                        <input type="text" class="form-control" name="grade_label[]" placeholder="Excellent" maxlength="50" value="${escapeHtml(lab)}">
                        <input type="number" step="0.01" class="form-control" name="score_from[]" placeholder="From" value="${escapeHtml(from)}">
                        <input type="number" step="0.01" class="form-control" name="score_to[]" placeholder="To" value="${escapeHtml(to)}">
                        <input type="text" class="form-control" name="remark[]" placeholder="Remark" maxlength="255" value="${escapeHtml(rem)}">
                        <input type="number" step="1" class="form-control" name="band_sort_order[]" placeholder="Sort" value="${escapeHtml(so)}">
                        <label class="pass-toggle"><input type="checkbox" name="is_pass[]" value="1" ${pass ? 'checked' : ''}> Pass</label>
                        <button type="button" class="row-remove" title="Remove row"><i class="fas fa-times"></i></button>
                    </div>
                `;
            }

            function analyzeCoverageFromRows() {
                const rows = [];
                gridEl.querySelectorAll('.band-row').forEach(row => {
                    const sym = (row.querySelector('input[name="grade_symbol[]"]').value || '').trim();
                    const f = parseFloat(row.querySelector('input[name="score_from[]"]').value);
                    const t = parseFloat(row.querySelector('input[name="score_to[]"]').value);
                    if (!sym && (isNaN(f) && isNaN(t))) return;
                    if (sym && !isNaN(f) && !isNaN(t) && f <= t) {
                        rows.push({
                            symbol: sym,
                            from: f,
                            to: t
                        });
                    }
                });
                rows.sort((a, b) => a.from - b.from);
                const gaps = [];
                const overlaps = [];
                if (rows.length === 0) {
                    gaps.push([currentMin, currentMax]);
                } else {
                    if (Math.abs(rows[0].from - currentMin) > 0.005) {
                        gaps.push([currentMin, rows[0].from - 0.01]);
                    }
                    for (let i = 0; i < rows.length - 1; i++) {
                        const a = rows[i],
                            b = rows[i + 1];
                        if (b.from > a.to + 0.015) {
                            gaps.push([a.to + 0.01, b.from - 0.01]);
                        } else if (b.from < a.to - 0.005) {
                            overlaps.push([b.from, a.to, a.symbol, b.symbol]);
                        }
                    }
                    const last = rows[rows.length - 1];
                    if (Math.abs(last.to - currentMax) > 0.005) {
                        gaps.push([last.to + 0.01, currentMax]);
                    }
                }
                const covered = rows.length > 0 && gaps.length === 0 && overlaps.length === 0;
                return {
                    rows,
                    gaps,
                    overlaps,
                    covered
                };
            }

            function recomputeCoverage() {
                const r = analyzeCoverageFromRows();
                coverBox.classList.remove('ok', 'under', 'over');
                coverDetail.innerHTML = '';
                coverDetail.style.display = 'none';
                if (r.covered) {
                    coverBox.classList.add('ok');
                    coverText.textContent = 'Coverage OK: ' + r.rows.length + ' band(s) fully cover [' +
                        currentMin.toFixed(2) + ', ' + currentMax.toFixed(2) + '].';
                } else if (r.overlaps.length) {
                    coverBox.classList.add('over');
                    coverText.textContent = 'Overlapping bands — adjust so no two bands share a score.';
                    coverDetail.style.display = 'block';
                    r.overlaps.forEach(o => {
                        const li = document.createElement('li');
                        li.textContent = 'Overlap ' + o[0].toFixed(2) + '–' + o[1].toFixed(2) + ' between "' + o[2] + '" and "' + o[3] + '"';
                        coverDetail.appendChild(li);
                    });
                } else {
                    coverBox.classList.add('under');
                    coverText.textContent = 'Gaps in coverage — every score must fall in a band.';
                    coverDetail.style.display = 'block';
                    r.gaps.forEach(g => {
                        const li = document.createElement('li');
                        li.textContent = 'Gap ' + g[0].toFixed(2) + '–' + g[1].toFixed(2);
                        coverDetail.appendChild(li);
                    });
                }
            }
            document.querySelectorAll('.js-manage-bands').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sid = parseInt(this.getAttribute('data-scale-id'), 10);
                    currentMin = parseFloat(this.getAttribute('data-score-min')) || 0;
                    currentMax = parseFloat(this.getAttribute('data-score-max')) || 100;
                    scaleIdIn.value = sid;
                    scaleLbl.textContent = (this.getAttribute('data-scale-label') || '') +
                        '  ·  [' + currentMin.toFixed(2) + ', ' + currentMax.toFixed(2) + ']';
                    gridEl.innerHTML = '';
                    const bands = BANDS_BY_SCALE[sid] || [];
                    if (bands.length) {
                        bands.forEach(b => gridEl.insertAdjacentHTML('beforeend', buildRow(b)));
                    } else {
                        gridEl.insertAdjacentHTML('beforeend', buildRow({}));
                    }
                    recomputeCoverage();
                    modal.show();
                });
            });
            document.getElementById('addBandRow').addEventListener('click', function() {
                gridEl.insertAdjacentHTML('beforeend', buildRow({}));
                recomputeCoverage();
            });
            gridEl.addEventListener('input', recomputeCoverage);
            gridEl.addEventListener('change', recomputeCoverage);
            gridEl.addEventListener('click', function(e) {
                const btn = e.target.closest('.row-remove');
                if (!btn) return;
                btn.closest('.band-row').remove();
                recomputeCoverage();
            });
            form.addEventListener('submit', function(e) {
                const r = analyzeCoverageFromRows();
                if (!r.covered) {
                    e.preventDefault();
                    alert('Bands must fully cover [' + currentMin.toFixed(2) + ', ' + currentMax.toFixed(2) +
                        '] with no gaps and no overlaps.');
                }
            });
        })();
        // Auto-open create modal
        <?php if ($showNew): ?>
            new bootstrap.Modal(document.getElementById('newScaleModal')).show();
        <?php endif; ?>
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