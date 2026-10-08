<?php

/**
 * Academic Promotion Rules — End-of-year progression rules per level/year
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Academic
 * @version 1.0
 * @filepath public/platform/tenant/academic/promotion-rules.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Academic promotion-rules file of the academic-surface sweep.
 *   Five changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - $currentPage changed from 'academic_promotion_rules'
 *       to 'academic' so the partial marks Academic active and
 *       renders the academic sub-menu on this page — consistent
 *       with every other academic file.
 *     - A v1.0 [SWEEP] entry was added above this docblock, and
 *       @version was reduced from 1.3 to 1.0 per Decision X-3.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php.
 *     - The CSS rule .nav-subgroup-label is added to this file's
 *       <style> block. The .nav-sub, .nav-sub .nav-link, and
 *       .nav-sub .nav-link.active rules, and the two responsive
 *       rules for .nav-sub, were already present in this file and
 *       are preserved unchanged.
 *   Every other line of the file is byte-identical to the
 *   previous version (1.3). The @package tag remains 'EduTrack'.
 *
 * v1.3  (S20: bootstrap + CSRF on all POST handlers + JSON hardening)
 *
 * Decisions locked in (Session S16):
 *   D1  rule_name + policy_type + status required; level/year/threshold_score/
 *       required_subjects/require_manual_approval/dates/description optional
 *   D2  unique per (tenant, rule_name, version) for non-deleted rows
 *   D3  no feature gate; settings defaults only pre-fill
 *   D4  snapshot on activate
 *   D5  allow tenant-wide rules + level-scoped; optional year scoping
 *   D6  refuse activation for policy incoherence; force manual for policy=manual
 *   D7  allow free soft-delete, audited
 *   D8  rule-first flat list grouped by year → level
 *   D9  multi-select subject picker modal, stores JSON
 *   D10 lifecycle actions: activate / archive / duplicate
 *   D11 validate effective_to >= effective_from
 *   D12 selecting policy_type=manual auto-checks and disables manual flag
 *
 * B.E.C.E. extension (Session S16):
 *   D13 (13A) new columns on promotion_rules via migration
 *   D14 (14C) support both all_subjects and bece_6 modes
 *   D15 (15A) core subjects picked per rule, pre-filled with the 4 you named
 *   D16 (16A) electives = enrolled subjects not in core_subject_ids
 *   D17 (17A+17B) aggregate = sum of 6 B.E.C.E. grades; raw score stored too
 *   D18 (18A) tie-break by subject_id ascending
 *   D19 (19A) Primary aggregate = average of all subject scores
 *   D20 (20A) Primary alphabetic = symbol of overall average, pass from scale
 *   D21 (21A+21C) mode-driven field visibility + core picker pre-fill
 *   D22 (22A) layer rule_mode on top of existing policy_type; do not break S16
 *   D23 S16 = definition only; S17 will run the rule and store results
 *
 * Fixes (post-S16 extension):
 *   F1  In bece_6 mode, threshold_score and required_subjects are no longer
 *       required: the decision uses aggregate_pass_max and core_subject_ids.
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

$pageTitle   = 'Promotion Rules - Student 360 Platform';
$currentPage = 'academic';

$db = DatabaseHelper::getInstance();

// ============================================
// HARD-CODED B.E.C.E. CORE SUBJECT NAMES (D15 / D21C)
// ============================================
const BECE_CORE_SUBJECT_NAMES = [
    'Mathematics',
    'English Language',
    'Integrated Science',
    'Social Studies',
];

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

function getRule($db, int $tenantId, int $ruleId): ?array
{
    if ($ruleId <= 0) return null;
    return $db->fetchOne(
        "SELECT r.*,
                al.level_name, al.level_code,
                ay.year_name
         FROM promotion_rules r
         LEFT JOIN academic_levels al ON r.academic_level_id = al.id
         LEFT JOIN academic_years ay ON r.academic_year_id = ay.id
         WHERE r.id = ? AND r.tenant_id = ? AND r.deleted_at IS NULL",
        [$ruleId, $tenantId]
    ) ?: null;
}

function decodeSubjectIds(?string $raw): array
{
    if ($raw === null || trim($raw) === '') return [];
    $raw = trim($raw);
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_unique(array_filter(array_map('intval', $decoded))));
        }
    }
    $parts = preg_split('/\s*,\s*/', $raw);
    return array_values(array_unique(array_filter(array_map('intval', $parts))));
}

function encodeSubjectIds(array $ids): ?string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return null;
    return json_encode($ids, JSON_UNESCAPED_UNICODE);
}

function verifySubjects($db, int $tenantId, array $ids): void
{
    if (empty($ids)) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT id FROM subjects
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND id IN ($placeholders)",
        array_merge([$tenantId], $ids)
    );
    $valid = array_map(fn($r) => (int)$r['id'], $rows);
    $invalid = array_values(array_diff($ids, $valid));
    if (!empty($invalid)) {
        throw new Exception('One or more selected subjects do not belong to this tenant: ' . implode(', ', $invalid) . '.');
    }
}

function validatePolicy(array $fields): array
{
    $type = $fields['policy_type'];
    if (!in_array($type, ['threshold', 'subject_gated', 'manual', 'mixed'], true)) {
        throw new Exception('Invalid policy type.');
    }

    $mode = $fields['rule_mode'] ?? 'all_subjects';
    if (!in_array($mode, ['all_subjects', 'bece_6'], true)) {
        throw new Exception('Invalid rule mode.');
    }
    $fields['rule_mode'] = $mode;

    $style = $fields['grading_style'] ?? 'numeric';
    if (!in_array($style, ['numeric', 'alphabetic'], true)) {
        throw new Exception('Invalid grading style.');
    }
    $fields['grading_style'] = $style;

    $needsThreshold = in_array($type, ['threshold', 'mixed'], true);
    $needsSubjects  = in_array($type, ['subject_gated', 'mixed'], true);

    if ($mode === 'bece_6') {
        $needsThreshold = false;
        $needsSubjects  = false;
    }

    if ($needsThreshold) {
        $t = $fields['threshold_score'];
        if ($t === null || !is_numeric($t)) {
            throw new Exception('Threshold score is required for policy type "' . $type . '".');
        }
        $t = (float)$t;
        if ($t <= 0 || $t > 100) {
            throw new Exception('Threshold score must be greater than 0 and at most 100.');
        }
        $fields['threshold_score'] = $t;
    } else {
        $fields['threshold_score'] = null;
    }

    if ($needsSubjects) {
        if (empty($fields['required_subjects'])) {
            throw new Exception('Required subjects are required for policy type "' . $type . '".');
        }
    } else {
        $fields['required_subjects'] = [];
    }

    if ($type === 'manual') {
        $fields['require_manual'] = 1;
    } else {
        $fields['require_manual'] = (int)($fields['require_manual'] ?? 0) ? 1 : 0;
    }

    if ($mode === 'all_subjects') {
        $fields['core_subject_ids']    = [];
        $fields['elective_pick_count'] = null;
        $fields['aggregate_min']       = null;
        $fields['aggregate_max']       = null;
        $fields['aggregate_pass_max']  = null;
    } else {
        if (empty($fields['core_subject_ids'])) {
            throw new Exception('B.E.C.E. mode requires the four core subjects to be selected.');
        }
        $core = array_values(array_unique(array_filter(array_map('intval', $fields['core_subject_ids']))));
        if (count($core) !== 4) {
            throw new Exception('B.E.C.E. mode requires exactly four core subjects; ' . count($core) . ' provided.');
        }
        $fields['core_subject_ids'] = $core;

        $pick = $fields['elective_pick_count'];
        if ($pick === null || !is_numeric($pick)) {
            $pick = 2;
        }
        $pick = (int)$pick;
        if ($pick < 1 || $pick > 6) {
            throw new Exception('Electives to pick must be between 1 and 6.');
        }
        $fields['elective_pick_count'] = $pick;

        $mn = $fields['aggregate_min'];
        $mx = $fields['aggregate_max'];
        $pm = $fields['aggregate_pass_max'];

        if ($mn === null || !is_numeric($mn)) {
            throw new Exception('Aggregate min is required for B.E.C.E. mode.');
        }
        if ($mx === null || !is_numeric($mx)) {
            throw new Exception('Aggregate max is required for B.E.C.E. mode.');
        }
        if ($pm === null || !is_numeric($pm)) {
            throw new Exception('Aggregate pass-max is required for B.E.C.E. mode.');
        }
        $mn = (float)$mn;
        $mx = (float)$mx;
        $pm = (float)$pm;
        if ($mn >= $mx) {
            throw new Exception('Aggregate min must be smaller than aggregate max.');
        }
        if ($pm < $mn || $pm > $mx) {
            throw new Exception('Aggregate pass-max must lie between aggregate min and max.');
        }
        $fields['aggregate_min']      = $mn;
        $fields['aggregate_max']      = $mx;
        $fields['aggregate_pass_max'] = $pm;
    }

    return $fields;
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
            $ruleName    = trim((string)($_POST['rule_name'] ?? ''));
            $policyType  = trim((string)($_POST['policy_type'] ?? 'threshold'));
            $ruleMode    = trim((string)($_POST['rule_mode'] ?? 'all_subjects'));
            $gradingStyle = trim((string)($_POST['grading_style'] ?? 'numeric'));
            $levelId     = (int)($_POST['academic_level_id'] ?? 0);
            $yearId      = (int)($_POST['academic_year_id'] ?? 0);
            $threshold   = ($_POST['threshold_score'] ?? '') === '' ? null : (float)$_POST['threshold_score'];
            $requireM    = !empty($_POST['require_manual_approval']) ? 1 : 0;
            $effFrom     = trim((string)($_POST['effective_from'] ?? ''));
            $effTo       = trim((string)($_POST['effective_to'] ?? ''));
            $desc        = trim((string)($_POST['description'] ?? ''));
            $status      = trim((string)($_POST['status'] ?? 'draft'));

            $subjectIds  = is_array($_POST['subject_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['subject_ids']))))
                : [];
            $coreIds     = is_array($_POST['core_subject_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['core_subject_ids']))))
                : [];
            $electivePick = ($_POST['elective_pick_count'] ?? '') === '' ? null : (int)$_POST['elective_pick_count'];
            $aggMin      = ($_POST['aggregate_min'] ?? '') === '' ? null : (float)$_POST['aggregate_min'];
            $aggMax      = ($_POST['aggregate_max'] ?? '') === '' ? null : (float)$_POST['aggregate_max'];
            $aggPassMax  = ($_POST['aggregate_pass_max'] ?? '') === '' ? null : (float)$_POST['aggregate_pass_max'];

            if ($ruleName === '') $errors[] = 'Rule name is required.';
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

            $fields = validatePolicy([
                'policy_type'        => $policyType,
                'threshold_score'    => $threshold,
                'required_subjects'  => $subjectIds,
                'require_manual'     => $requireM,
                'rule_mode'          => $ruleMode,
                'grading_style'      => $gradingStyle,
                'core_subject_ids'   => $coreIds,
                'elective_pick_count' => $electivePick,
                'aggregate_min'      => $aggMin,
                'aggregate_max'      => $aggMax,
                'aggregate_pass_max' => $aggPassMax,
            ]);

            if (!empty($fields['required_subjects'])) {
                verifySubjects($db, $tenantId, $fields['required_subjects']);
            }
            if (!empty($fields['core_subject_ids'])) {
                verifySubjects($db, $tenantId, $fields['core_subject_ids']);
            }

            $db->beginTransaction();

            $dup = $db->fetchOne(
                "SELECT id FROM promotion_rules
                 WHERE tenant_id = ? AND rule_name = ? AND version = 1 AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $ruleName]
            );
            if ($dup) throw new Exception('A promotion rule with this name already exists (version 1).');

            $newId = $db->insert(
                "INSERT INTO promotion_rules
                    (uuid, tenant_id, rule_name, academic_level_id, academic_year_id,
                     policy_type, rule_mode, grading_style,
                     threshold_score, required_subjects,
                     core_subject_ids, elective_pick_count,
                     aggregate_min, aggregate_max, aggregate_pass_max,
                     require_manual_approval,
                     version, status, effective_from, effective_to, description,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $ruleName,
                    $levelId > 0 ? $levelId : null,
                    $yearId  > 0 ? $yearId  : null,
                    $fields['policy_type'],
                    $fields['rule_mode'],
                    $fields['grading_style'],
                    $fields['threshold_score'],
                    encodeSubjectIds($fields['required_subjects']),
                    encodeSubjectIds($fields['core_subject_ids']),
                    $fields['elective_pick_count'],
                    $fields['aggregate_min'],
                    $fields['aggregate_max'],
                    $fields['aggregate_pass_max'],
                    $fields['require_manual'],
                    $status,
                    $effFrom !== '' ? $effFrom : null,
                    $effTo   !== '' ? $effTo   : null,
                    $desc !== '' ? $desc : null,
                    $userId ?: null,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.promotion_rule.created',
                'promotion_rules',
                (int)$newId,
                [
                    'name'         => $ruleName,
                    'policy_type'  => $fields['policy_type'],
                    'rule_mode'    => $fields['rule_mode'],
                    'grading_style' => $fields['grading_style'],
                    'status'       => $status,
                    'level_id'     => $levelId,
                    'year_id'      => $yearId,
                    'threshold'    => $fields['threshold_score'],
                    'subjects'     => $fields['required_subjects'],
                    'core_subjects' => $fields['core_subject_ids'],
                    'elective_pick' => $fields['elective_pick_count'],
                    'aggregate'    => [
                        'min'      => $fields['aggregate_min'],
                        'max'      => $fields['aggregate_max'],
                        'pass_max' => $fields['aggregate_pass_max'],
                    ],
                    'manual'       => $fields['require_manual'],
                ]
            );

            $db->commit();
            $_SESSION['success'] = 'Promotion rule created.';
            header('Location: /platform/tenant/academic/promotion-rules.php');
            exit;
        }

        // ---------------- UPDATE ----------------
        if ($action === 'update') {
            $id          = (int)($_POST['id'] ?? 0);
            $ruleName    = trim((string)($_POST['rule_name'] ?? ''));
            $policyType  = trim((string)($_POST['policy_type'] ?? 'threshold'));
            $ruleMode    = trim((string)($_POST['rule_mode'] ?? 'all_subjects'));
            $gradingStyle = trim((string)($_POST['grading_style'] ?? 'numeric'));
            $levelId     = (int)($_POST['academic_level_id'] ?? 0);
            $yearId      = (int)($_POST['academic_year_id'] ?? 0);
            $threshold   = ($_POST['threshold_score'] ?? '') === '' ? null : (float)$_POST['threshold_score'];
            $requireM    = !empty($_POST['require_manual_approval']) ? 1 : 0;
            $effFrom     = trim((string)($_POST['effective_from'] ?? ''));
            $effTo       = trim((string)($_POST['effective_to'] ?? ''));
            $desc        = trim((string)($_POST['description'] ?? ''));

            $subjectIds  = is_array($_POST['subject_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['subject_ids']))))
                : [];
            $coreIds     = is_array($_POST['core_subject_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['core_subject_ids']))))
                : [];
            $electivePick = ($_POST['elective_pick_count'] ?? '') === '' ? null : (int)$_POST['elective_pick_count'];
            $aggMin      = ($_POST['aggregate_min'] ?? '') === '' ? null : (float)$_POST['aggregate_min'];
            $aggMax      = ($_POST['aggregate_max'] ?? '') === '' ? null : (float)$_POST['aggregate_max'];
            $aggPassMax  = ($_POST['aggregate_pass_max'] ?? '') === '' ? null : (float)$_POST['aggregate_pass_max'];

            $rule = getRule($db, $tenantId, $id);
            if (!$rule) throw new Exception('Promotion rule not found.');

            if ($ruleName === '') $errors[] = 'Rule name is required.';
            if ($effFrom !== '' && $effTo !== '' && strtotime($effTo) < strtotime($effFrom)) {
                $errors[] = 'Effective-to date must be on or after effective-from.';
            }
            if (!empty($errors)) throw new Exception(implode(' ', $errors));

            $fields = validatePolicy([
                'policy_type'        => $policyType,
                'threshold_score'    => $threshold,
                'required_subjects'  => $subjectIds,
                'require_manual'     => $requireM,
                'rule_mode'          => $ruleMode,
                'grading_style'      => $gradingStyle,
                'core_subject_ids'   => $coreIds,
                'elective_pick_count' => $electivePick,
                'aggregate_min'      => $aggMin,
                'aggregate_max'      => $aggMax,
                'aggregate_pass_max' => $aggPassMax,
            ]);

            if (!empty($fields['required_subjects'])) {
                verifySubjects($db, $tenantId, $fields['required_subjects']);
            }
            if (!empty($fields['core_subject_ids'])) {
                verifySubjects($db, $tenantId, $fields['core_subject_ids']);
            }

            $db->beginTransaction();

            if ($rule['status'] === 'draft') {
                $dup = $db->fetchOne(
                    "SELECT id FROM promotion_rules
                     WHERE tenant_id = ? AND rule_name = ? AND version = ?
                       AND id != ? AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $ruleName, (int)$rule['version'], $id]
                );
                if ($dup) throw new Exception('Another rule already uses this name at this version.');

                $db->execute(
                    "UPDATE promotion_rules
                        SET rule_name = ?,
                            academic_level_id = ?, academic_year_id = ?,
                            policy_type = ?, rule_mode = ?, grading_style = ?,
                            threshold_score = ?, required_subjects = ?,
                            core_subject_ids = ?, elective_pick_count = ?,
                            aggregate_min = ?, aggregate_max = ?, aggregate_pass_max = ?,
                            require_manual_approval = ?,
                            effective_from = ?, effective_to = ?,
                            description = ?, updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [
                        $ruleName,
                        $levelId > 0 ? $levelId : null,
                        $yearId  > 0 ? $yearId  : null,
                        $fields['policy_type'],
                        $fields['rule_mode'],
                        $fields['grading_style'],
                        $fields['threshold_score'],
                        encodeSubjectIds($fields['required_subjects']),
                        encodeSubjectIds($fields['core_subject_ids']),
                        $fields['elective_pick_count'],
                        $fields['aggregate_min'],
                        $fields['aggregate_max'],
                        $fields['aggregate_pass_max'],
                        $fields['require_manual'],
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
                    'academic.promotion_rule.updated',
                    'promotion_rules',
                    $id,
                    [
                        'name' => $ruleName,
                        'version' => (int)$rule['version'],
                        'rule_mode' => $fields['rule_mode']
                    ]
                );
                $_SESSION['success'] = 'Draft promotion rule updated.';
            } else {
                $newVersion = (int)$rule['version'] + 1;

                $dup = $db->fetchOne(
                    "SELECT id FROM promotion_rules
                     WHERE tenant_id = ? AND rule_name = ? AND version = ?
                       AND deleted_at IS NULL
                     FOR UPDATE",
                    [$tenantId, $ruleName, $newVersion]
                );
                if ($dup) throw new Exception('A draft for version ' . $newVersion . ' already exists.');

                $db->execute(
                    "UPDATE promotion_rules
                        SET status = 'archived', updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$id, $tenantId]
                );

                $newId = $db->insert(
                    "INSERT INTO promotion_rules
                        (uuid, tenant_id, rule_name, academic_level_id, academic_year_id,
                         policy_type, rule_mode, grading_style,
                         threshold_score, required_subjects,
                         core_subject_ids, elective_pick_count,
                         aggregate_min, aggregate_max, aggregate_pass_max,
                         require_manual_approval,
                         version, status, effective_from, effective_to, description,
                         created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, NOW(), NOW())",
                    [
                        uuidv4(),
                        $tenantId,
                        $ruleName,
                        $levelId > 0 ? $levelId : null,
                        $yearId  > 0 ? $yearId  : null,
                        $fields['policy_type'],
                        $fields['rule_mode'],
                        $fields['grading_style'],
                        $fields['threshold_score'],
                        encodeSubjectIds($fields['required_subjects']),
                        encodeSubjectIds($fields['core_subject_ids']),
                        $fields['elective_pick_count'],
                        $fields['aggregate_min'],
                        $fields['aggregate_max'],
                        $fields['aggregate_pass_max'],
                        $fields['require_manual'],
                        $newVersion,
                        $effFrom !== '' ? $effFrom : null,
                        $effTo   !== '' ? $effTo   : null,
                        $desc !== '' ? $desc : null,
                        $userId ?: null,
                    ]
                );

                writeAudit(
                    $db,
                    $tenantId,
                    $userId,
                    'academic.promotion_rule.snapshotted',
                    'promotion_rules',
                    (int)$newId,
                    [
                        'parent_id' => $id,
                        'name' => $ruleName,
                        'archived_version' => (int)$rule['version'],
                        'new_version' => $newVersion,
                        'rule_mode' => $fields['rule_mode']
                    ]
                );
                $_SESSION['success'] = 'Active rule archived; new draft version ' . $newVersion . ' created.';
            }

            $db->commit();
            header('Location: /platform/tenant/academic/promotion-rules.php');
            exit;
        }

        // ---------------- ACTIVATE ----------------
        if ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            $rule = getRule($db, $tenantId, $id);
            if (!$rule) throw new Exception('Promotion rule not found.');
            if ($rule['status'] === 'active') {
                $_SESSION['success'] = 'Promotion rule is already active.';
                header('Location: /platform/tenant/academic/promotion-rules.php');
                exit;
            }

            $db->beginTransaction();

            $storedSubjects = decodeSubjectIds($rule['required_subjects']);
            $storedCore     = decodeSubjectIds($rule['core_subject_ids']);

            $fields = validatePolicy([
                'policy_type'        => $rule['policy_type'],
                'threshold_score'    => $rule['threshold_score'],
                'required_subjects'  => $storedSubjects,
                'require_manual'     => (int)$rule['require_manual_approval'],
                'rule_mode'          => $rule['rule_mode'] ?? 'all_subjects',
                'grading_style'      => $rule['grading_style'] ?? 'numeric',
                'core_subject_ids'   => $storedCore,
                'elective_pick_count' => $rule['elective_pick_count'],
                'aggregate_min'      => $rule['aggregate_min'],
                'aggregate_max'      => $rule['aggregate_max'],
                'aggregate_pass_max' => $rule['aggregate_pass_max'],
            ]);

            if (!empty($fields['required_subjects'])) {
                verifySubjects($db, $tenantId, $fields['required_subjects']);
            }
            if (!empty($fields['core_subject_ids'])) {
                verifySubjects($db, $tenantId, $fields['core_subject_ids']);
            }

            if ($fields['require_manual'] !== (int)$rule['require_manual_approval']) {
                $db->execute(
                    "UPDATE promotion_rules
                        SET require_manual_approval = ?, updated_at = NOW()
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$fields['require_manual'], $id, $tenantId]
                );
            }

            $db->execute(
                "UPDATE promotion_rules
                    SET status = 'active', updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.promotion_rule.activated',
                'promotion_rules',
                $id,
                [
                    'name' => $rule['rule_name'],
                    'version' => (int)$rule['version'],
                    'rule_mode' => $fields['rule_mode'],
                    'coerced_manual' => ($fields['require_manual'] !== (int)$rule['require_manual_approval']) ? 1 : 0
                ]
            );

            $db->commit();
            $_SESSION['success'] = 'Promotion rule activated.';
            header('Location: /platform/tenant/academic/promotion-rules.php');
            exit;
        }

        // ---------------- ARCHIVE ----------------
        if ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            $rule = getRule($db, $tenantId, $id);
            if (!$rule) throw new Exception('Promotion rule not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE promotion_rules
                    SET status = 'archived', updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.promotion_rule.archived',
                'promotion_rules',
                $id,
                ['name' => $rule['rule_name'], 'version' => (int)$rule['version']]
            );
            $db->commit();

            $_SESSION['success'] = 'Promotion rule archived.';
            header('Location: /platform/tenant/academic/promotion-rules.php');
            exit;
        }

        // ---------------- DUPLICATE ----------------
        if ($action === 'duplicate') {
            $id = (int)($_POST['id'] ?? 0);
            $rule = getRule($db, $tenantId, $id);
            if (!$rule) throw new Exception('Promotion rule not found.');

            $db->beginTransaction();

            $nextRow = $db->fetchOne(
                "SELECT COALESCE(MAX(version), 0) + 1 AS v
                 FROM promotion_rules
                 WHERE tenant_id = ? AND rule_name = ? AND deleted_at IS NULL
                 FOR UPDATE",
                [$tenantId, $rule['rule_name']]
            );
            $newVersion = (int)($nextRow['v'] ?? 1);
            if ($newVersion < 1) $newVersion = 1;

            $newId = $db->insert(
                "INSERT INTO promotion_rules
                    (uuid, tenant_id, rule_name, academic_level_id, academic_year_id,
                     policy_type, rule_mode, grading_style,
                     threshold_score, required_subjects,
                     core_subject_ids, elective_pick_count,
                     aggregate_min, aggregate_max, aggregate_pass_max,
                     require_manual_approval,
                     version, status, effective_from, effective_to, description,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $rule['rule_name'],
                    $rule['academic_level_id'],
                    $rule['academic_year_id'],
                    $rule['policy_type'],
                    $rule['rule_mode'] ?? 'all_subjects',
                    $rule['grading_style'] ?? 'numeric',
                    $rule['threshold_score'],
                    $rule['required_subjects'],
                    $rule['core_subject_ids'],
                    $rule['elective_pick_count'],
                    $rule['aggregate_min'],
                    $rule['aggregate_max'],
                    $rule['aggregate_pass_max'],
                    (int)$rule['require_manual_approval'],
                    $newVersion,
                    $rule['effective_from'],
                    $rule['effective_to'],
                    $rule['description'],
                    $userId ?: null,
                ]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.promotion_rule.duplicated',
                'promotion_rules',
                (int)$newId,
                ['source_id' => $id, 'name' => $rule['rule_name'], 'new_version' => $newVersion]
            );
            $db->commit();

            $_SESSION['success'] = 'Promotion rule duplicated as draft version ' . $newVersion . '.';
            header('Location: /platform/tenant/academic/promotion-rules.php');
            exit;
        }

        // ---------------- DELETE ----------------
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $rule = getRule($db, $tenantId, $id);
            if (!$rule) throw new Exception('Promotion rule not found.');

            $db->beginTransaction();
            $db->execute(
                "UPDATE promotion_rules
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$id, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'academic.promotion_rule.deleted',
                'promotion_rules',
                $id,
                ['name' => $rule['rule_name'], 'version' => (int)$rule['version']]
            );
            $db->commit();

            $_SESSION['success'] = 'Promotion rule deleted.';
            header('Location: /platform/tenant/academic/promotion-rules.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Academic promotion rules action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/academic/promotion-rules.php');
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
$defaultThreshold   = $settings['default_promotion_threshold'] ?? null;
$defaultManualApproval = (int)($settings['default_require_manual_approval'] ?? 0);

$filterYear       = (int)($_GET['year_id'] ?? 0);
$filterLevel      = (int)($_GET['level_id'] ?? 0);
$filterStatus     = trim((string)($_GET['status'] ?? ''));
$filterPolicyType = trim((string)($_GET['policy_type'] ?? ''));
$filterRuleMode   = trim((string)($_GET['rule_mode'] ?? ''));

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

$allSubjects = $db->fetchAll(
    "SELECT s.id, s.subject_name, s.subject_code, s.academic_level_id,
            al.level_name
     FROM subjects s
     LEFT JOIN academic_levels al ON s.academic_level_id = al.id
     WHERE s.tenant_id = ? AND s.deleted_at IS NULL
     ORDER BY al.sort_order ASC, s.subject_name ASC",
    [$tenantId]
);
$subjectsById = [];
foreach ($allSubjects as $sub) {
    $subjectsById[(int)$sub['id']] = $sub;
}

$becePrefillIds = [];
foreach (BECE_CORE_SUBJECT_NAMES as $coreName) {
    foreach ($allSubjects as $sub) {
        if (strcasecmp(trim($sub['subject_name']), $coreName) === 0) {
            $becePrefillIds[] = (int)$sub['id'];
            break;
        }
    }
}
$becePrefillIds = array_values(array_unique($becePrefillIds));

$where = ["r.tenant_id = ?", "r.deleted_at IS NULL"];
$params = [$tenantId];
if ($filterYear > 0) {
    $where[] = "(r.academic_year_id = ? OR r.academic_year_id IS NULL)";
    $params[] = $filterYear;
}
if ($filterLevel > 0) {
    $where[] = "(r.academic_level_id = ? OR r.academic_level_id IS NULL)";
    $params[] = $filterLevel;
}
if (in_array($filterStatus, ['draft', 'active', 'archived'], true)) {
    $where[] = "r.status = ?";
    $params[] = $filterStatus;
}
if (in_array($filterPolicyType, ['threshold', 'subject_gated', 'manual', 'mixed'], true)) {
    $where[] = "r.policy_type = ?";
    $params[] = $filterPolicyType;
}
if (in_array($filterRuleMode, ['all_subjects', 'bece_6'], true)) {
    $where[] = "r.rule_mode = ?";
    $params[] = $filterRuleMode;
}
$whereClause = implode(' AND ', $where);

$rules = $db->fetchAll(
    "SELECT r.*,
            al.level_name, al.level_code, al.sort_order AS level_sort,
            ay.year_name, ay.start_date AS year_start
     FROM promotion_rules r
     LEFT JOIN academic_levels al ON r.academic_level_id = al.id
     LEFT JOIN academic_years  ay ON r.academic_year_id = ay.id
     WHERE $whereClause
     ORDER BY
        CASE r.status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
        r.rule_name ASC, r.version DESC",
    $params
);

$ruleSubjects = [];
$ruleCoreSubjects = [];
foreach ($rules as $r) {
    $ruleSubjects[(int)$r['id']]     = decodeSubjectIds($r['required_subjects']);
    $ruleCoreSubjects[(int)$r['id']] = decodeSubjectIds($r['core_subject_ids']);
}

$byYear = [];
foreach ($rules as $r) {
    $yKey = $r['academic_year_id'] !== null ? (int)$r['academic_year_id'] : 0;
    $yName = $r['year_name'] ?: '(All years)';
    if (!isset($byYear[$yKey])) {
        $byYear[$yKey] = ['year_name' => $yName, 'levels' => []];
    }
    $lKey = $r['academic_level_id'] !== null ? (int)$r['academic_level_id'] : 0;
    $lName = $r['level_name'] ?: '(All levels)';
    $lCode = $r['level_code'] ?? '';
    if (!isset($byYear[$yKey]['levels'][$lKey])) {
        $byYear[$yKey]['levels'][$lKey] = [
            'level_name' => $lName,
            'level_code' => $lCode,
            'items' => [],
        ];
    }
    $byYear[$yKey]['levels'][$lKey]['items'][] = $r;
}

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$totalRules  = count($rules);
$activeRules = 0;
$draftRules  = 0;
foreach ($rules as $r) {
    if ($r['status'] === 'active') $activeRules++;
    elseif ($r['status'] === 'draft') $draftRules++;
}

function policyLabel(string $type): string
{
    return [
        'threshold'     => 'Threshold',
        'subject_gated' => 'Subject-gated',
        'manual'        => 'Manual',
        'mixed'         => 'Mixed',
    ][$type] ?? $type;
}
function policyPillClass(string $type): string
{
    return [
        'threshold'     => 'blue',
        'subject_gated' => 'purple',
        'manual'        => 'orange',
        'mixed'         => 'teal',
    ][$type] ?? 'gray';
}
function ruleModeLabel(string $mode): string
{
    return [
        'all_subjects' => 'All subjects',
        'bece_6'       => 'B.E.C.E. (4 core + 2 electives)',
    ][$mode] ?? $mode;
}
function ruleModePillClass(string $mode): string
{
    return [
        'all_subjects' => 'gray',
        'bece_6'       => 'purple',
    ][$mode] ?? 'gray';
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
            min-width: 140px;
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
            max-width: 1200px;
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

        .rule-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .rule-table thead th {
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

        .rule-table tbody td {
            padding: 12px 18px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .rule-table tbody tr:last-child td {
            border-bottom: none;
        }

        .rule-table tbody tr:hover {
            background: #fafbfc;
        }

        .rule-title {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .rule-meta {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .subject-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .subject-chip {
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

        .subject-chip.core {
            background: #fef3c7;
            color: #92400e;
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

        .form-control:disabled,
        .form-select:disabled {
            background: #f8f9fa;
            color: #6c757d;
        }

        .modal-lg {
            max-width: 960px;
        }

        .subject-grid {
            display: flex;
            flex-direction: column;
            gap: 4px;
            max-height: 420px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 8px;
            background: #fafbfc;
        }

        .subject-row {
            display: grid;
            grid-template-columns: 28px 1fr 100px;
            gap: 10px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #eef0f3;
        }

        .subject-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .subject-row .subject-name {
            font-size: 13px;
            font-weight: 500;
            color: #1a1a2e;
        }

        .subject-row .subject-meta {
            font-size: 11px;
            color: #6c757d;
        }

        .subject-row .subject-code {
            font-size: 11px;
            color: #0d6efd;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            background: #e3f0ff;
            padding: 1px 8px;
            border-radius: 6px;
            display: inline-block;
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

        .policy-hint {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
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

            .rule-table {
                font-size: 12px;
            }

            .rule-table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .rule-table tbody td {
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
                        <h1><i class="fas fa-arrow-up-right-dots me-2"></i>Promotion Rules</h1>
                        <p>End-of-year progression rules per level and year</p>
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
                                <?php echo h((string)$totalRules); ?> rule(s) ·
                                <?php echo h((string)$activeRules); ?> active ·
                                <?php echo h((string)$draftRules); ?> draft
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>B.E.C.E. supported
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
                        <div class="ib-title">Two rule modes: All Subjects and B.E.C.E. six-subject</div>
                        <div class="ib-text">
                            <strong>All subjects</strong> is used by Primary schools: every subject the student
                            studies counts toward the aggregate, which is the average of their final scores.
                            <strong>B.E.C.E.</strong> is used by JHS: four named core subjects count automatically
                            (Mathematics, English Language, Integrated Science, Social Studies), and the system
                            adds the student's best two electives by score. The aggregate is the sum of the six
                            B.E.C.E. grades (1–9), typically ranging 6 to 54.
                        </div>
                    </div>
                </div>

                <!-- Filter bar -->
                <form method="GET" action="/platform/tenant/academic/promotion-rules.php" class="filters-bar">
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
                        <label>Mode</label>
                        <select name="rule_mode" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="all_subjects" <?php echo $filterRuleMode === 'all_subjects' ? 'selected' : ''; ?>>All subjects</option>
                            <option value="bece_6" <?php echo $filterRuleMode === 'bece_6'       ? 'selected' : ''; ?>>B.E.C.E.</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Policy</label>
                        <select name="policy_type" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach (['threshold', 'subject_gated', 'manual', 'mixed'] as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo $filterPolicyType === $p ? 'selected' : ''; ?>>
                                    <?php echo h(policyLabel($p)); ?>
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
                        <a href="/platform/tenant/academic/promotion-rules.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <a href="/platform/tenant/academic/promotion-rules.php?action=new" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i> New Rule
                        </a>
                    </div>
                </form>

                <!-- ================================================
                     GROUPED BY YEAR -> LEVEL -> RULE
                ================================================= -->
                <?php if (empty($byYear)): ?>
                    <div class="year-section">
                        <div class="empty-state">
                            <i class="fas fa-arrow-up-right-dots"></i>
                            <h5>No promotion rules yet</h5>
                            <p>Create a rule to define how students progress at the end of a level.</p>
                            <a href="/platform/tenant/academic/promotion-rules.php?action=new" class="btn btn-primary btn-sm mt-2">
                                <i class="fas fa-plus me-1"></i> Create Promotion Rule
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
                                    $ruleCount = 0;
                                    foreach ($yearGroup['levels'] as $lg) {
                                        $ruleCount += count($lg['items']);
                                    }
                                    ?>
                                    <span class="pill blue"><i class="fas fa-arrow-up-right-dots"></i><?php echo h((string)$ruleCount); ?> rule(s)</span>
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
                                    <table class="rule-table">
                                        <thead>
                                            <tr>
                                                <th style="min-width:200px;">Rule</th>
                                                <th style="text-align:center;">Version</th>
                                                <th style="text-align:center;">Status</th>
                                                <th style="text-align:center;">Mode</th>
                                                <th style="text-align:center;">Policy</th>
                                                <th style="min-width:240px;">Details</th>
                                                <th style="text-align:right;min-width:340px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($levelGroup['items'] as $r):
                                                $rid       = (int)$r['id'];
                                                $version   = (int)$r['version'];
                                                $status    = $r['status'];
                                                $policy    = $r['policy_type'];
                                                $mode      = $r['rule_mode'] ?? 'all_subjects';
                                                $style     = $r['grading_style'] ?? 'numeric';
                                                $statusPill = ['draft' => 'gray', 'active' => 'green', 'archived' => 'orange'][$status] ?? 'gray';
                                                $subjIds   = $ruleSubjects[$rid] ?? [];
                                                $coreIds   = $ruleCoreSubjects[$rid] ?? [];
                                                $subjects  = array_values(array_filter(array_map(fn($id) => $subjectsById[$id] ?? null, $subjIds)));
                                                $coreSubj  = array_values(array_filter(array_map(fn($id) => $subjectsById[$id] ?? null, $coreIds)));
                                                $reqManual = (int)$r['require_manual_approval'] === 1;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="rule-title"><?php echo h($r['rule_name']); ?></div>
                                                        <?php if (!empty($r['effective_from']) || !empty($r['effective_to'])): ?>
                                                            <div class="rule-meta">
                                                                <i class="fas fa-calendar me-1"></i>
                                                                <?php echo h($r['effective_from'] ?: '—'); ?>
                                                                →
                                                                <?php echo h($r['effective_to'] ?: '—'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill teal">v<?php echo h((string)$version); ?></span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo $statusPill; ?>"><?php echo h(ucfirst($status)); ?></span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo ruleModePillClass($mode); ?>" title="<?php echo h(ruleModeLabel($mode)); ?>">
                                                            <?php echo $mode === 'bece_6' ? 'B.E.C.E.' : 'All subjects'; ?>
                                                        </span>
                                                        <?php if ($style === 'alphabetic'): ?>
                                                            <div style="margin-top:4px;">
                                                                <span class="pill orange" title="Alphabetic grading">A–F</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="pill <?php echo policyPillClass($policy); ?>">
                                                            <?php echo h(policyLabel($policy)); ?>
                                                        </span>
                                                        <?php if ($reqManual): ?>
                                                            <div style="margin-top:4px;">
                                                                <span class="pill orange" title="Requires manual approval">
                                                                    <i class="fas fa-user-check"></i> Manual
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($mode === 'bece_6'): ?>
                                                            <?php if (!empty($coreSubj)): ?>
                                                                <div style="font-size:11px;color:#6c757d;margin-bottom:2px;">
                                                                    Core:
                                                                </div>
                                                                <div class="subject-chips">
                                                                    <?php foreach ($coreSubj as $sub): ?>
                                                                        <span class="subject-chip core"><?php echo h($sub['subject_name']); ?></span>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div style="font-size:11px;color:#1a1a2e;margin-top:6px;">
                                                                + Best <strong><?php echo h((string)(int)$r['elective_pick_count']); ?></strong> electives
                                                            </div>
                                                            <?php if ($r['aggregate_min'] !== null && $r['aggregate_max'] !== null): ?>
                                                                <div style="font-size:11px;color:#6c757d;margin-top:2px;">
                                                                    Aggregate:
                                                                    <?php echo number_format((float)$r['aggregate_min'], 0); ?>
                                                                    –
                                                                    <?php echo number_format((float)$r['aggregate_max'], 0); ?>
                                                                    · pass ≤
                                                                    <strong><?php echo number_format((float)$r['aggregate_pass_max'], 0); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php if (in_array($policy, ['threshold', 'mixed'], true) && $r['threshold_score'] !== null): ?>
                                                                <div style="font-size:12px;color:#1a1a2e;">
                                                                    Threshold:
                                                                    <strong><?php echo number_format((float)$r['threshold_score'], 2); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (in_array($policy, ['subject_gated', 'mixed'], true)): ?>
                                                                <?php if (empty($subjects)): ?>
                                                                    <span class="pill red"><i class="fas fa-exclamation-triangle"></i> No subjects</span>
                                                                <?php else: ?>
                                                                    <div class="subject-chips">
                                                                        <?php foreach ($subjects as $sub): ?>
                                                                            <span class="subject-chip"><?php echo h($sub['subject_name']); ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            <?php if ($policy === 'manual'): ?>
                                                                <div style="font-size:12px;color:#6c757d;">
                                                                    All students reviewed by hand.
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            <a href="/platform/tenant/academic/promotion-rules.php?action=edit&id=<?php echo $rid; ?>"
                                                                class="btn btn-outline-secondary">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </a>

                                                            <?php if ($status !== 'active'): ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Activate this promotion rule?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="activate">
                                                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                                    <button type="submit" class="btn btn-outline-success">
                                                                        <i class="fas fa-check"></i> Activate
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" style="display:inline-block;"
                                                                    onsubmit="return confirm('Archive this active promotion rule?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="archive">
                                                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                                    <button type="submit" class="btn btn-outline-warning">
                                                                        <i class="fas fa-box-archive"></i> Archive
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Duplicate as a new draft version?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="duplicate">
                                                                <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                                <button type="submit" class="btn btn-outline-secondary">
                                                                    <i class="fas fa-copy"></i>
                                                                </button>
                                                            </form>

                                                            <form method="POST" style="display:inline-block;"
                                                                onsubmit="return confirm('Delete this promotion rule?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo $rid; ?>">
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

    <?php
    $subjectsGrouped = [];
    foreach ($allSubjects as $sub) {
        $key = $sub['academic_level_id'] !== null ? (int)$sub['academic_level_id'] : 0;
        $lbl = $sub['level_name'] ?: '(All levels)';
        if (!isset($subjectsGrouped[$key])) {
            $subjectsGrouped[$key] = ['label' => $lbl, 'items' => []];
        }
        $subjectsGrouped[$key]['items'][] = $sub;
    }
    ?>

    <!-- ================================================
         CREATE MODAL ( ?action=new )
    ================================================= -->
    <?php $showNew = (isset($_GET['action']) && $_GET['action'] === 'new'); ?>
    <div class="modal fade<?php echo $showNew ? ' show' : ''; ?>" id="newRuleModal" tabindex="-1"
        style="<?php echo $showNew ? 'display:block;background:rgba(0,0,0,0.4);' : ''; ?>">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/academic/promotion-rules.php" id="newRuleForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5><i class="fas fa-plus-circle text-primary me-2"></i>New Promotion Rule</h5>
                        <a href="/platform/tenant/academic/promotion-rules.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label" for="newName">Rule name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="newName" name="rule_name" maxlength="100" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="newStatus">Status <span class="required">*</span></label>
                                <select class="form-select" id="newStatus" name="status" required>
                                    <option value="draft" selected>Draft</option>
                                    <option value="active">Active</option>
                                </select>
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
                                <label class="form-label" for="newRuleMode">Rule mode <span class="required">*</span></label>
                                <select class="form-select" id="newRuleMode" name="rule_mode" required>
                                    <option value="all_subjects" selected>All subjects (Primary)</option>
                                    <option value="bece_6">B.E.C.E. (4 core + 2 electives, JHS)</option>
                                </select>
                                <div class="policy-hint">
                                    All subjects: average of every subject the student studies.
                                    B.E.C.E.: four core subjects + best two electives; aggregate from grades.
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="newGradingStyle">Grading style <span class="required">*</span></label>
                                <select class="form-select" id="newGradingStyle" name="grading_style" required>
                                    <option value="numeric" selected>Numeric</option>
                                    <option value="alphabetic">Alphabetic (A–F)</option>
                                </select>
                            </div>

                            <div class="col-12" id="newAllSubjectsBlock">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="newPolicyAll">Policy type <span class="required">*</span></label>
                                        <select class="form-select" id="newPolicyAll" name="policy_type">
                                            <option value="threshold" selected>Threshold (average minimum)</option>
                                            <option value="manual">Manual (human-reviewed)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3" id="newThresholdWrap">
                                        <label class="form-label" for="newThreshold">Average threshold</label>
                                        <input type="number" step="0.01" min="0" max="100" class="form-control"
                                            id="newThreshold" name="threshold_score"
                                            value="<?php echo $defaultThreshold !== null ? h(number_format((float)$defaultThreshold, 2, '.', '')) : ''; ?>"
                                            placeholder="e.g. 50.00">
                                        <div class="policy-hint">Required for threshold. Must be 0 &lt; x ≤ 100.</div>
                                    </div>
                                    <div class="col-md-6 mb-3 d-flex align-items-end" id="newManualWrap">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="newManualApproval" name="require_manual_approval" value="1"
                                                <?php echo $defaultManualApproval ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="newManualApproval">
                                                Require manual approval before promoting
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12" id="newBeceBlock" style="display:none;">
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">
                                            Core subjects
                                            <span class="required">*</span>
                                        </label>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="policy-hint" style="margin:0;">
                                                Exactly four. Pre-filled with Mathematics, English Language,
                                                Integrated Science and Social Studies when those names exist.
                                            </span>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="newPrefillCoreBtn">
                                                    <i class="fas fa-wand-magic-sparkles me-1"></i> Pre-fill B.E.C.E. cores
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="newPickCoreBtn">
                                                    <i class="fas fa-list me-1"></i> Pick cores
                                                </button>
                                            </div>
                                        </div>
                                        <div id="newCorePreview" class="subject-chips"></div>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="newElectivePickCount">Electives to pick <span class="required">*</span></label>
                                        <input type="number" class="form-control" id="newElectivePickCount" name="elective_pick_count" value="2" min="1" max="6">
                                        <div class="policy-hint">The best N electives by score are added to the four cores.</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="newAggMin">Aggregate min <span class="required">*</span></label>
                                        <input type="number" step="0.01" class="form-control" id="newAggMin" name="aggregate_min" value="6.00">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="newAggMax">Aggregate max <span class="required">*</span></label>
                                        <input type="number" step="0.01" class="form-control" id="newAggMax" name="aggregate_max" value="54.00">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="newAggPassMax">Aggregate pass-max <span class="required">*</span></label>
                                        <input type="number" step="0.01" class="form-control" id="newAggPassMax" name="aggregate_pass_max" value="30.00">
                                        <div class="policy-hint">Aggregate ≤ this counts as a pass.</div>
                                    </div>
                                </div>
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
                    </div>
                    <div class="modal-footer">
                        <a href="/platform/tenant/academic/promotion-rules.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Create Rule</button>
                    </div>
                    <div id="newSubjectsHidden"></div>
                    <div id="newCoreSubjectsHidden"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- ================================================
         EDIT MODAL ( ?action=edit&id=X )
    ================================================= -->
    <?php
    $editRule = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $editRule = getRule($db, $tenantId, (int)$_GET['id']);
    }
    ?>
    <?php if ($editRule):
        $editSubjects = decodeSubjectIds($editRule['required_subjects']);
        $editCore     = decodeSubjectIds($editRule['core_subject_ids']);
    ?>
        <div class="modal fade show" id="editRuleModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.4);">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/academic/promotion-rules.php" id="editRuleForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int)$editRule['id']; ?>">
                        <div class="modal-header">
                            <h5>
                                <i class="fas fa-edit text-primary me-2"></i>
                                Edit Promotion Rule
                                <?php if ($editRule['status'] === 'active'): ?>
                                    <small class="text-muted d-block" style="font-size:12px;font-weight:400;margin-top:2px;">
                                        This rule is active. Saving creates a new draft version (v<?php echo (int)$editRule['version'] + 1; ?>);
                                        the current version will be archived.
                                    </small>
                                <?php endif; ?>
                            </h5>
                            <a href="/platform/tenant/academic/promotion-rules.php" class="btn-close"></a>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="editName">Rule name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="editName" name="rule_name"
                                        value="<?php echo h($editRule['rule_name']); ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="editStatus">Status</label>
                                    <input type="text" class="form-control" id="editStatus"
                                        value="<?php echo h(ucfirst($editRule['status'])); ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editLevel"><?php echo h($labelLevel); ?></label>
                                    <select class="form-select" id="editLevel" name="academic_level_id">
                                        <option value="0">(All levels — tenant-wide)</option>
                                        <?php foreach ($levels as $lv): ?>
                                            <option value="<?php echo (int)$lv['id']; ?>"
                                                <?php echo (int)$editRule['academic_level_id'] === (int)$lv['id'] ? 'selected' : ''; ?>>
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
                                                <?php echo (int)$editRule['academic_year_id'] === (int)$y['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($y['year_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editRuleMode">Rule mode <span class="required">*</span></label>
                                    <select class="form-select" id="editRuleMode" name="rule_mode" required>
                                        <option value="all_subjects" <?php echo ($editRule['rule_mode'] ?? 'all_subjects') === 'all_subjects' ? 'selected' : ''; ?>>All subjects (Primary)</option>
                                        <option value="bece_6" <?php echo ($editRule['rule_mode'] ?? 'all_subjects') === 'bece_6' ? 'selected' : ''; ?>>B.E.C.E. (4 core + 2 electives, JHS)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editGradingStyle">Grading style <span class="required">*</span></label>
                                    <select class="form-select" id="editGradingStyle" name="grading_style" required>
                                        <option value="numeric" <?php echo ($editRule['grading_style'] ?? 'numeric') === 'numeric' ? 'selected' : ''; ?>>Numeric</option>
                                        <option value="alphabetic" <?php echo ($editRule['grading_style'] ?? 'numeric') === 'alphabetic' ? 'selected' : ''; ?>>Alphabetic (A–F)</option>
                                    </select>
                                </div>

                                <div class="col-12" id="editAllSubjectsBlock">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="editPolicyAll">Policy type <span class="required">*</span></label>
                                            <select class="form-select" id="editPolicyAll" name="policy_type">
                                                <?php foreach (['threshold', 'manual'] as $p): ?>
                                                    <option value="<?php echo $p; ?>" <?php echo $editRule['policy_type'] === $p ? 'selected' : ''; ?>>
                                                        <?php echo h(policyLabel($p)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3" id="editThresholdWrap">
                                            <label class="form-label" for="editThreshold">Average threshold</label>
                                            <input type="number" step="0.01" min="0" max="100" class="form-control"
                                                id="editThreshold" name="threshold_score"
                                                value="<?php echo $editRule['threshold_score'] !== null ? h(number_format((float)$editRule['threshold_score'], 2, '.', '')) : ''; ?>">
                                        </div>
                                        <div class="col-md-6 mb-3 d-flex align-items-end" id="editManualWrap">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="editManualApproval" name="require_manual_approval" value="1"
                                                    <?php echo (int)$editRule['require_manual_approval'] ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="editManualApproval">
                                                    Require manual approval before promoting
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12" id="editBeceBlock" style="display:none;">
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <label class="form-label">
                                                Core subjects
                                                <span class="required">*</span>
                                            </label>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="policy-hint" style="margin:0;">Exactly four.</span>
                                                <div class="d-flex gap-2">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="editPrefillCoreBtn">
                                                        <i class="fas fa-wand-magic-sparkles me-1"></i> Pre-fill B.E.C.E. cores
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="editPickCoreBtn">
                                                        <i class="fas fa-list me-1"></i> Pick cores
                                                    </button>
                                                </div>
                                            </div>
                                            <div id="editCorePreview" class="subject-chips">
                                                <?php foreach ($editCore as $sid):
                                                    $sub = $subjectsById[$sid] ?? null;
                                                    if (!$sub) continue;
                                                ?>
                                                    <span class="subject-chip core"><?php echo h($sub['subject_name']); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="editElectivePickCount">Electives to pick</label>
                                            <input type="number" class="form-control" id="editElectivePickCount"
                                                name="elective_pick_count"
                                                value="<?php echo (int)($editRule['elective_pick_count'] ?? 2); ?>" min="1" max="6">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="editAggMin">Aggregate min</label>
                                            <input type="number" step="0.01" class="form-control" id="editAggMin"
                                                name="aggregate_min"
                                                value="<?php echo $editRule['aggregate_min'] !== null ? h(number_format((float)$editRule['aggregate_min'], 2, '.', '')) : '6.00'; ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="editAggMax">Aggregate max</label>
                                            <input type="number" step="0.01" class="form-control" id="editAggMax"
                                                name="aggregate_max"
                                                value="<?php echo $editRule['aggregate_max'] !== null ? h(number_format((float)$editRule['aggregate_max'], 2, '.', '')) : '54.00'; ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label" for="editAggPassMax">Aggregate pass-max</label>
                                            <input type="number" step="0.01" class="form-control" id="editAggPassMax"
                                                name="aggregate_pass_max"
                                                value="<?php echo $editRule['aggregate_pass_max'] !== null ? h(number_format((float)$editRule['aggregate_pass_max'], 2, '.', '')) : '30.00'; ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editFrom">Effective from</label>
                                    <input type="date" class="form-control" id="editFrom" name="effective_from"
                                        value="<?php echo h($editRule['effective_from']); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="editTo">Effective to</label>
                                    <input type="date" class="form-control" id="editTo" name="effective_to"
                                        value="<?php echo h($editRule['effective_to']); ?>">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label" for="editDesc">Description</label>
                                    <input type="text" class="form-control" id="editDesc" name="description"
                                        value="<?php echo h($editRule['description']); ?>" maxlength="500">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="/platform/tenant/academic/promotion-rules.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i> Save</button>
                        </div>
                        <div id="editSubjectsHidden"></div>
                        <div id="editCoreSubjectsHidden"></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- SUBJECT PICKER MODAL -->
    <div class="modal fade" id="subjectPickerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="pickerTitle"><i class="fas fa-list text-primary me-2"></i>Pick Subjects</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="form-text m-0" id="pickerHint">Only subjects belonging to this tenant are listed.</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="pickAllBtn">Select all</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="pickNoneBtn">Clear</button>
                        </div>
                    </div>
                    <div class="subject-grid" id="pickerGrid">
                        <?php foreach ($subjectsGrouped as $groupKey => $group): ?>
                            <div style="grid-column: 1 / -1; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d; padding:4px 6px;">
                                <?php echo h($group['label']); ?>
                            </div>
                            <?php foreach ($group['items'] as $sub): ?>
                                <label class="subject-row">
                                    <input type="checkbox" class="picker-subject-cb" value="<?php echo (int)$sub['id']; ?>">
                                    <span>
                                        <span class="subject-name"><?php echo h($sub['subject_name']); ?></span>
                                    </span>
                                    <span><span class="subject-code"><?php echo h($sub['subject_code']); ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="pickerConfirmBtn">Use Selected</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const SUBJECTS_BY_ID = <?php
                                $out = [];
                                foreach ($subjectsById as $sid => $s) {
                                    $out[$sid] = ['id' => (int)$s['id'], 'name' => $s['subject_name'], 'code' => $s['subject_code']];
                                }
                                echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                ?>;

        const BECE_PREFILL_IDS = <?php echo json_encode($becePrefillIds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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

        function applyFormVisibility(root) {
            const modeSel = root.querySelector('select[name="rule_mode"]');
            const policySel = root.querySelector('select[name="policy_type"]');

            if (modeSel) {
                const mode = modeSel.value;
                const isAll = mode === 'all_subjects';
                const isBece = mode === 'bece_6';
                const allBlock = root.querySelector('[id$="AllSubjectsBlock"]');
                const beceBlock = root.querySelector('[id$="BeceBlock"]');
                if (allBlock) allBlock.style.display = isAll ? '' : 'none';
                if (beceBlock) beceBlock.style.display = isBece ? '' : 'none';
            }

            if (policySel) {
                const policy = policySel.value;
                const needsThreshold = (policy === 'threshold' || policy === 'mixed');
                const isManual = (policy === 'manual');
                const tWrap = root.querySelector('[id$="ThresholdWrap"]');
                if (tWrap) tWrap.style.display = needsThreshold ? '' : 'none';
                const mCb = root.querySelector('input[name="require_manual_approval"]');
                if (mCb) {
                    if (isManual) {
                        mCb.checked = true;
                        mCb.disabled = true;
                    } else {
                        mCb.disabled = false;
                    }
                }
            }
        }
        ['newRuleForm', 'editRuleForm'].forEach(fid => {
            const f = document.getElementById(fid);
            if (!f) return;
            const modeSel = f.querySelector('select[name="rule_mode"]');
            const policySel = f.querySelector('select[name="policy_type"]');
            if (modeSel) modeSel.addEventListener('change', () => applyFormVisibility(f));
            if (policySel) policySel.addEventListener('change', () => applyFormVisibility(f));
            applyFormVisibility(f);
        });

        (function() {
            const pickerEl = document.getElementById('subjectPickerModal');
            const picker = new bootstrap.Modal(pickerEl);
            const gridEl = document.getElementById('pickerGrid');
            const confirmBtn = document.getElementById('pickerConfirmBtn');
            const pickAllBtn = document.getElementById('pickAllBtn');
            const pickNoneBtn = document.getElementById('pickNoneBtn');
            const pickerTitle = document.getElementById('pickerTitle');
            const pickerHint = document.getElementById('pickerHint');
            let context = null;

            function setChecks(ids) {
                const set = new Set(ids.map(String));
                gridEl.querySelectorAll('.picker-subject-cb').forEach(cb => {
                    cb.checked = set.has(String(cb.value));
                });
            }

            function getCheckedIds() {
                return Array.from(gridEl.querySelectorAll('.picker-subject-cb:checked')).map(cb => parseInt(cb.value, 10));
            }

            function renderPreview(target, ids, opts) {
                opts = opts || {};
                const prev = document.getElementById(target);
                if (!prev) return;
                prev.innerHTML = '';
                ids.forEach(id => {
                    const s = SUBJECTS_BY_ID[id];
                    if (!s) return;
                    const chip = document.createElement('span');
                    chip.className = 'subject-chip' + (opts.core ? ' core' : '');
                    chip.textContent = s.name;
                    prev.appendChild(chip);
                });
                if (!ids.length) {
                    prev.innerHTML = '<span class="text-muted" style="font-size:12px;">No subjects selected.</span>';
                }
            }

            function writeHidden(target, ids) {
                const wrap = document.getElementById(target);
                if (!wrap) return;
                wrap.innerHTML = '';
                ids.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = context.inputName || 'subject_ids[]';
                    inp.value = id;
                    wrap.appendChild(inp);
                });
            }

            function openPicker(ctx) {
                context = ctx;
                pickerTitle.innerHTML = '<i class="fas fa-list text-primary me-2"></i>' + (ctx.title || 'Pick Subjects');
                pickerHint.textContent = ctx.hint || 'Only subjects belonging to this tenant are listed.';
                setChecks(ctx.checkedIds || []);
                picker.show();
            }
            pickAllBtn.addEventListener('click', function() {
                gridEl.querySelectorAll('.picker-subject-cb').forEach(cb => cb.checked = true);
            });
            pickNoneBtn.addEventListener('click', function() {
                gridEl.querySelectorAll('.picker-subject-cb').forEach(cb => cb.checked = false);
            });
            confirmBtn.addEventListener('click', function() {
                if (!context) {
                    picker.hide();
                    return;
                }
                const ids = getCheckedIds();
                if (context.coreLimit && ids.length !== context.coreLimit) {
                    alert('Please select exactly ' + context.coreLimit + ' subjects for this list.');
                    return;
                }
                renderPreview(context.previewId, ids, {
                    core: !!context.coreLimit
                });
                writeHidden(context.hiddenId, ids);
                picker.hide();
            });
            window.__openSubjectPicker = openPicker;
        })();

        function prefillCores(formPrefix) {
            const previewId = formPrefix + 'CorePreview';
            const hiddenId = formPrefix + 'CoreSubjectsHidden';
            const ids = (BECE_PREFILL_IDS || []).slice(0, 4);
            const prev = document.getElementById(previewId);
            prev.innerHTML = '';
            ids.forEach(id => {
                const s = SUBJECTS_BY_ID[id];
                if (!s) return;
                const chip = document.createElement('span');
                chip.className = 'subject-chip core';
                chip.textContent = s.name;
                prev.appendChild(chip);
            });
            if (!ids.length) {
                prev.innerHTML = '<span class="text-muted" style="font-size:12px;">No matching B.E.C.E. core names were found in your subject list.</span>';
            }
            const wrap = document.getElementById(hiddenId);
            wrap.innerHTML = '';
            ids.forEach(id => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'core_subject_ids[]';
                inp.value = id;
                wrap.appendChild(inp);
            });
        }

        (function() {
            const btn = document.getElementById('newPickCoreBtn');
            if (btn) {
                btn.addEventListener('click', function() {
                    const existing = Array.from(document.querySelectorAll('#newCorePreview .subject-chip'))
                        .map(c => c.textContent.trim());
                    const ids = existing.map(name => {
                        for (const k in SUBJECTS_BY_ID) {
                            if (SUBJECTS_BY_ID[k].name === name) return parseInt(k, 10);
                        }
                        return null;
                    }).filter(x => x !== null);
                    window.__openSubjectPicker({
                        title: 'Pick exactly 4 Core Subjects',
                        hint: 'Select exactly four subjects. These will always be counted for B.E.C.E.',
                        previewId: 'newCorePreview',
                        hiddenId: 'newCoreSubjectsHidden',
                        inputName: 'core_subject_ids[]',
                        coreLimit: 4,
                        checkedIds: ids,
                    });
                });
            }
            const prefillBtn = document.getElementById('newPrefillCoreBtn');
            if (prefillBtn) {
                prefillBtn.addEventListener('click', function() {
                    prefillCores('new');
                });
            }
        })();

        (function() {
            const btn = document.getElementById('editPickCoreBtn');
            if (btn) {
                btn.addEventListener('click', function() {
                    const existing = Array.from(document.querySelectorAll('#editCorePreview .subject-chip'))
                        .map(c => c.textContent.trim());
                    const ids = existing.map(name => {
                        for (const k in SUBJECTS_BY_ID) {
                            if (SUBJECTS_BY_ID[k].name === name) return parseInt(k, 10);
                        }
                        return null;
                    }).filter(x => x !== null);
                    window.__openSubjectPicker({
                        title: 'Pick exactly 4 Core Subjects',
                        hint: 'Select exactly four subjects. These will always be counted for B.E.C.E.',
                        previewId: 'editCorePreview',
                        hiddenId: 'editCoreSubjectsHidden',
                        inputName: 'core_subject_ids[]',
                        coreLimit: 4,
                        checkedIds: ids,
                    });
                });
            }
            const prefillBtn = document.getElementById('editPrefillCoreBtn');
            if (prefillBtn) {
                prefillBtn.addEventListener('click', function() {
                    prefillCores('edit');
                });
            }
            const existing = Array.from(document.querySelectorAll('#editCorePreview .subject-chip'))
                .map(c => c.textContent.trim());
            const ids = existing.map(name => {
                for (const k in SUBJECTS_BY_ID) {
                    if (SUBJECTS_BY_ID[k].name === name) return parseInt(k, 10);
                }
                return null;
            }).filter(x => x !== null);
            const wrap = document.getElementById('editCoreSubjectsHidden');
            if (wrap) {
                wrap.innerHTML = '';
                ids.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'core_subject_ids[]';
                    inp.value = id;
                    wrap.appendChild(inp);
                });
            }
        })();

        <?php if ($showNew): ?>
            new bootstrap.Modal(document.getElementById('newRuleModal')).show();
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