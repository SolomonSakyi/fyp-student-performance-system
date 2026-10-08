<?php

/**
 * Tenant Report Bulk Print — whole class, one print job.
 *
 * @package Student 360
 * @subpackage Platform\Tenant\Reports
 * @version 1.6.0
 * @filepath public/platform/tenant/reports/print-class.php
 *
 * v1.6.0 change (2026-10-07) [CLASS ANCHOR]:
 *   The applicable promotion_rules row is now looked up by each
 *   student's class_id, not by the class offering's academic level.
 *   The table gained promotion_rules.class_id in migration
 *   2026_10_07_promotion_rules_class_anchor.sql. The rule row
 *   carries only the pass / fail / pending policy; the PROMOTED TO
 *   target continues to come from the class ladder
 *   (classes.next_class_id → classes.class_name), as it has since
 *   v1.5. The academic_level_id and next_academic_level_id columns
 *   remain on the table but are no longer read by this file.
 *   Because the roster may contain students from more than one
 *   class, the rule lookup moves from a single top-level load to a
 *   per-student load inside the roster loop. The B.E.C.E. mode
 *   detection joins on pr.class_id = c.id. The $nextLevelName
 *   variable is retained but is always the empty string.
 *
 * v1.5.2 change (2026-10-07) [FETCHONE FALSE GUARD]:
 *   DatabaseHelper::fetchOne() can return false rather than null.
 *   Loads that feed typed parameters or boolean tests are normalized
 *   with is_array() at the assignment site.
 *
 * v1.5.1 change (2026-10-07) [TERM THREE CHECK]:
 *   The Term Three check tests academic_terms.sort_order === 3.
 *
 * v1.5 change (2026-10-07) [CLASS LADDER]:
 *   PROMOTED TO reads the class ladder inside `classes`.
 *
 * v1.4 change (2026-10-07) [ITEM 2 SUB-MODULES]:
 *   - Same three composed paragraphs as view.php v1.4.
 *   - Same additional read per student: student_behaviour.
 *   - Same helpers.
 *
 * v1.3 change (2026-10-07) [JS SYNTAX FIX]:
 *   - Replaced `const el;` with `let el;`.
 *
 * v1.2 change (2026-10-07) [REPORT LOGO PATH]:
 *   - report.logo_path wins the logo chain.
 *
 * v1.1 change (2026-10-07) [ITEM 2 CLASS/EXAM SPLIT]:
 *   - Class/Exam split reads assessment_components.
 */

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';
require_tenant();
require_once $projectRoot . '/app/helpers/PhraseGenerator.php';

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$currentPage = 'reports';

$db = DatabaseHelper::getInstance();

// ============================================
// Resolve the offering
// ============================================
$filterYear = (int)($_GET['year_id'] ?? 0);
$filterTerm = (int)($_GET['term_id'] ?? 0);
$offeringId = (int)($_GET['offering_id'] ?? 0);

if ($offeringId <= 0) {
    header('Location: /platform/tenant/reports/index.php');
    exit;
}

$offering = $db->fetchOne(
    "SELECT co.id, co.tenant_id, co.school_id, co.class_id, co.stream_id,
            co.academic_year_id,
            c.class_name, c.class_code,
            s.stream_name,
            ay.year_name
       FROM class_offerings co
       LEFT JOIN classes c ON c.id = co.class_id AND c.deleted_at IS NULL
       LEFT JOIN streams s ON s.id = co.stream_id AND s.deleted_at IS NULL
       LEFT JOIN academic_years ay ON ay.id = co.academic_year_id
      WHERE co.id = ? AND co.tenant_id = ? AND co.deleted_at IS NULL
      LIMIT 1",
    [$offeringId, $tenantId]
);

if (!$offering) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Not found</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>Not found</h1><p>The class offering is not available in this tenant.</p><a href="/platform/tenant/reports/index.php">Back to Reports</a></body></html>';
    exit;
}

// ============================================
// Resolve the term
// ============================================
if ($filterYear <= 0) $filterYear = (int)$offering['academic_year_id'];

$selectedTerm = null;
if ($filterTerm > 0) {
    $selectedTerm = $db->fetchOne(
        "SELECT id, term_name, sort_order, academic_year_id,
                vacation_date, next_term_begins
           FROM academic_terms
          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
          LIMIT 1",
        [$filterTerm, $tenantId]
    );
}
if (!$selectedTerm) {
    $selectedTerm = $db->fetchOne(
        "SELECT id, term_name, sort_order, academic_year_id,
                vacation_date, next_term_begins
           FROM academic_terms
          WHERE tenant_id = ? AND academic_year_id = ? AND deleted_at IS NULL
          ORDER BY is_current DESC, sort_order ASC, id ASC
          LIMIT 1",
        [$tenantId, $filterYear]
    );
}
if (!$selectedTerm) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>No term</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>No term available</h1><a href="/platform/tenant/reports/index.php">Back to Reports</a></body></html>';
    exit;
}
$filterTerm = (int)$selectedTerm['id'];

$selectedYear = null;
if ($filterYear > 0) {
    $selectedYear = $db->fetchOne(
        "SELECT id, year_name FROM academic_years
          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1",
        [$filterYear, $tenantId]
    );
}

// ============================================
// Header values: school_settings → schools/tenants
// ============================================
$headerLine1 = '';
$headerLine2 = '';
$headerLine3 = '';
$headerMotto = '';
$schoolName  = '';
$schoolAddr  = '';
$schoolLogo  = '';

if ($schoolId > 0) {
    try {
        $rows = $db->fetchAll(
            "SELECT setting_key, setting_value
               FROM school_settings
              WHERE tenant_id = ? AND school_id = ?
                AND setting_group = 'report'
                AND setting_key IN ('report.header_line_1','report.header_line_2','report.header_line_3','report.motto','report.logo_path')
                AND deleted_at IS NULL",
            [$tenantId, $schoolId]
        );
        if (!is_array($rows)) $rows = [];
        foreach ($rows as $r) {
            $k = (string)$r['setting_key'];
            $v = trim((string)($r['setting_value'] ?? ''));
            if ($k === 'report.header_line_1') $headerLine1 = $v;
            elseif ($k === 'report.header_line_2') $headerLine2 = $v;
            elseif ($k === 'report.header_line_3') $headerLine3 = $v;
            elseif ($k === 'report.motto') $headerMotto = $v;
            elseif ($k === 'report.logo_path' && $v !== '') $schoolLogo = $v;
        }
    } catch (Exception $e) { /* school_settings empty or absent */
    }

    try {
        $sc = $db->fetchOne(
            "SELECT school_name, address, logo_path, head_teacher_name,
                    head_teacher_signature_path
               FROM schools
              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1",
            [$schoolId, $tenantId]
        );
        if ($sc) {
            $schoolName = (string)($sc['school_name'] ?? '');
            $schoolAddr = trim((string)($sc['address'] ?? ''));
            if ($schoolLogo === '' && !empty($sc['logo_path'])) $schoolLogo = (string)$sc['logo_path'];
        }
    } catch (Exception $e) {
    }
}

if ($schoolName === '') {
    try {
        $t = $db->fetchOne(
            "SELECT tenant_name, address, logo FROM tenants
              WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$tenantId]
        );
        if ($t) {
            $schoolName = (string)($t['tenant_name'] ?? '');
            if ($schoolAddr === '') $schoolAddr = trim((string)($t['address'] ?? ''));
            if ($schoolLogo === '' && !empty($t['logo'])) $schoolLogo = (string)$t['logo'];
        }
    } catch (Exception $e) {
    }
}
if ($schoolLogo === '' && $schoolId > 0) {
    $schoolLogo = '/uploads/schools/' . $schoolId . '/logo.png';
}

if ($headerLine1 === '') $headerLine1 = $schoolName;
if ($headerLine2 === '') $headerLine2 = 'P.O. Box —';
if ($headerLine3 === '') $headerLine3 = '—';

$transparentGif = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
$logoSrc = ($schoolLogo !== '') ? $schoolLogo : $transparentGif;

// ============================================
// Head teacher — schools columns first, staff fallback
// ============================================
$headTeacherName      = null;
$headTeacherSignature = null;

if ($schoolId > 0) {
    try {
        $sc = $db->fetchOne(
            "SELECT head_teacher_name, head_teacher_signature_path
               FROM schools
              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1",
            [$schoolId, $tenantId]
        );
        if ($sc) {
            if (!empty($sc['head_teacher_name'])) {
                $n = trim((string)$sc['head_teacher_name']);
                if ($n !== '') $headTeacherName = $n;
            }
            if (!empty($sc['head_teacher_signature_path'])) {
                $headTeacherSignature = (string)$sc['head_teacher_signature_path'];
            }
        }
    } catch (Exception $e) {
    }
}

if ($headTeacherName === null) {
    try {
        $ht = $db->fetchOne(
            "SELECT st.id, p.first_name, p.middle_name, p.last_name
               FROM staff st
               JOIN persons p ON p.id = st.person_id
              WHERE st.tenant_id = ?
                AND st.staff_type_id = 4
                AND st.is_active = 1
                AND st.deleted_at IS NULL
              ORDER BY st.hire_date DESC, st.id DESC
              LIMIT 1",
            [$tenantId]
        );
        if ($ht) {
            $n = trim(($ht['first_name'] ?? '') . ' ' . ($ht['middle_name'] ?? '') . ' ' . ($ht['last_name'] ?? ''));
            $n = preg_replace('/\s+/', ' ', $n);
            if ($n !== '') $headTeacherName = $n;
        }
    } catch (Exception $e) {
    }
}

if ($headTeacherSignature === null) {
    try {
        $ht2 = $db->fetchOne(
            "SELECT st.id FROM staff st
              WHERE st.tenant_id = ? AND st.staff_type_id = 4
                AND st.is_active = 1 AND st.deleted_at IS NULL
              ORDER BY st.hire_date DESC, st.id DESC LIMIT 1",
            [$tenantId]
        );
        if ($ht2) {
            $sig = $db->fetchOne(
                "SELECT signature_data, mime_type FROM staff_signatures
                  WHERE staff_id = ? AND is_primary = 1 AND deleted_at IS NULL
                  ORDER BY id DESC LIMIT 1",
                [(int)$ht2['id']]
            );
            if ($sig && !empty($sig['signature_data'])) {
                $headTeacherSignature = 'data:' . ($sig['mime_type'] ?: 'image/png') . ';base64,' . $sig['signature_data'];
            }
        }
    } catch (Exception $e) {
    }
}

// ============================================
// Class teacher for this term
// ============================================
$classTeacherName = null;
try {
    $ct = $db->fetchOne(
        "SELECT p.first_name, p.middle_name, p.last_name
           FROM teacher_class_assignments tca
           JOIN staff st ON st.id = tca.staff_id
           JOIN persons p ON p.id = st.person_id
          WHERE tca.tenant_id = ?
            AND tca.academic_term_id = ?
            AND tca.role = 'class_teacher'
            AND tca.is_active = 1
            AND tca.deleted_at IS NULL
            AND st.deleted_at IS NULL
          ORDER BY tca.is_primary DESC, tca.id ASC
          LIMIT 1",
        [$tenantId, $filterTerm]
    );
    if ($ct) {
        $n = trim(($ct['first_name'] ?? '') . ' ' . ($ct['middle_name'] ?? '') . ' ' . ($ct['last_name'] ?? ''));
        $n = preg_replace('/\s+/', ' ', $n);
        if ($n !== '') $classTeacherName = $n;
    }
} catch (Exception $e) {
}

// ============================================
// [v1.6.0] The promotion rule is now resolved per student, inside
//          the roster loop, on that student's class_id. The
//          top-level $promotionRule load is retired. B.E.C.E. mode
//          detection joins on pr.class_id = c.id.
// ============================================
$isBeceMode = false;
try {
    $ruleMode = $db->fetchOne(
        "SELECT pr.rule_mode FROM classes c
           LEFT JOIN promotion_rules pr
             ON pr.tenant_id = c.tenant_id
            AND pr.class_id = c.id
            AND pr.status = 'active' AND pr.deleted_at IS NULL
          WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL LIMIT 1",
        [(int)$offering['class_id'], $tenantId]
    );
    if ($ruleMode && (string)$ruleMode['rule_mode'] === 'bece_6') $isBeceMode = true;
} catch (Exception $e) {
}

// ============================================
// Load the roster
// ============================================
$roster = $db->fetchAll(
    "SELECT e.id AS enrollment_id, e.student_id, e.roll_number,
            s.student_number, s.first_name, s.middle_name, s.last_name,
            s.preferred_name, s.date_of_birth, s.gender, s.class_id,
            s.profile_photo_url,
            c.class_name, c.class_code
       FROM enrollments e
       JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
       LEFT JOIN classes c ON c.id = s.class_id AND c.deleted_at IS NULL
      WHERE e.tenant_id = ?
        AND e.class_offering_id = ?
        AND e.status = 'active'
        AND e.deleted_at IS NULL
      ORDER BY
            CASE WHEN e.roll_number IS NULL OR e.roll_number = '' THEN 1 ELSE 0 END,
            CAST(e.roll_number AS UNSIGNED) ASC,
            s.first_name ASC, s.last_name ASC",
    [$tenantId, $offeringId]
);
if (!is_array($roster)) $roster = [];

if (empty($roster)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>No roster</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>No active enrolments</h1><p>This class offering has no active students.</p><a href="/platform/tenant/reports/index.php">Back to Reports</a></body></html>';
    exit;
}

// ============================================
// Class mates for position calculation (once)
// ============================================
$classMates = $db->fetchAll(
    "SELECT s.id, s.student_number FROM students s
      WHERE s.tenant_id = ? AND s.class_id = ? AND s.deleted_at IS NULL",
    [$tenantId, (int)$offering['class_id']]
);
if (!is_array($classMates)) $classMates = [];
$classSize = count($classMates);

// ============================================
// Per-student data build
// ============================================
function fallbackBand(?float $score): array
{
    if ($score === null) return ['letter' => null, 'word' => null, 'point' => null];
    if ($score >= 80) return ['letter' => 'A', 'word' => 'Excellent',   'point' => 1];
    if ($score >= 66) return ['letter' => 'B', 'word' => 'Very Good',   'point' => 2];
    if ($score >= 46) return ['letter' => 'C', 'word' => 'Average',     'point' => 3];
    if ($score >= 36) return ['letter' => 'D', 'word' => 'Pass',        'point' => 4];
    return              ['letter' => 'E', 'word' => 'Low Average', 'point' => 5];
}
function ordinal(int $n): string
{
    $n = $n % 100;
    if ($n >= 11 && $n <= 13) return 'th';
    switch ($n % 10) {
        case 1:
            return 'st';
        case 2:
            return 'nd';
        case 3:
            return 'rd';
        default:
            return 'th';
    }
}
function fmtNum($v, int $dec = 2): string
{
    if ($v === null || $v === '') return '—';
    return number_format((float)$v, $dec);
}
function fmtDate(?string $d): string
{
    if (!$d) return '—';
    try {
        return (new DateTime($d))->format('M j, Y');
    } catch (Exception $e) {
        return (string)$d;
    }
}

function schemeSplitFractions($db, int $tenantId, ?int $schemeId): array
{
    static $cache = [];
    $default = ['class' => 0.60, 'exam' => 0.40];
    if ($schemeId === null || $schemeId <= 0) return $default;
    if (isset($cache[$schemeId])) return $cache[$schemeId];
    try {
        $rows = $db->fetchAll(
            "SELECT component_code, weight
               FROM assessment_components
              WHERE tenant_id = ? AND assessment_scheme_id = ?
                AND is_active = 1 AND deleted_at IS NULL",
            [$tenantId, $schemeId]
        );
        if (!is_array($rows) || empty($rows)) {
            return $cache[$schemeId] = $default;
        }
        $total = 0.0;
        $exam  = 0.0;
        foreach ($rows as $r) {
            $w = (float)$r['weight'];
            $total += $w;
            if (strtoupper((string)$r['component_code']) === 'EXAM') {
                $exam += $w;
            }
        }
        if ($total <= 0.0) {
            return $cache[$schemeId] = $default;
        }
        return $cache[$schemeId] = [
            'class' => 1.0 - ($exam / $total),
            'exam'  => $exam / $total,
        ];
    } catch (Exception $e) {
        return $cache[$schemeId] = $default;
    }
}

function profileParagraph(?string $phrase, ?array $attendance, ?float $termAverage, array $overallBand, ?array $behaviour): string
{
    if ($phrase === null || $phrase === '') return '—';
    $parts = [$phrase];

    if ($attendance && (int)($attendance['days_total'] ?? 0) > 0) {
        $present = (int)($attendance['days_present'] ?? 0);
        $total   = (int)($attendance['days_total'] ?? 0);
        $pct     = $total > 0 ? round(($present / $total) * 100) : 0;
        $parts[] = 'Present ' . $pct . '% of ' . $total . ' days.';
    }

    if ($termAverage !== null) {
        $band = '';
        if (($overallBand['letter'] ?? null) !== null) {
            $band = ' (' . $overallBand['letter'];
            if (($overallBand['word'] ?? null) !== null) {
                $band .= ' / ' . $overallBand['word'];
            }
            $band .= ')';
        }
        $parts[] = 'Term average ' . number_format($termAverage, 1) . $band . '.';
    }

    if ($behaviour && !empty($behaviour['final_grade'])) {
        $parts[] = 'Conduct: ' . (string)$behaviour['final_grade'] . '.';
    }

    return implode(' ', $parts);
}

function remarkParagraph(string $category, ?string $phrase, array $ctx): string
{
    if ($phrase === null || $phrase === '') return '—';
    $parts = [$phrase];

    if ($category === 'conduct') {
        $grade = (string)($ctx['behaviour_grade'] ?? '');
        if ($grade !== '') $parts[] = 'Behaviour grade: ' . $grade . '.';
        if (isset($ctx['inc_minor'], $ctx['inc_moderate'], $ctx['inc_major'])) {
            $parts[] = 'Incidents this term: '
                . (int)$ctx['inc_minor'] . ' minor, '
                . (int)$ctx['inc_moderate'] . ' moderate, '
                . (int)$ctx['inc_major'] . ' major.';
        }
    } elseif ($category === 'attitude') {
        $p = $ctx['rating_punctuality'] ?? null;
        $r = $ctx['rating_respect'] ?? null;
        $t = $ctx['rating_participation'] ?? null;
        $frags = [];
        if ($p !== null) $frags[] = 'Punctuality ' . (int)$p . '/5';
        if ($r !== null) $frags[] = 'Respect ' . (int)$r . '/5';
        if ($t !== null) $frags[] = 'Participation ' . (int)$t . '/5';
        if (!empty($frags)) $parts[] = implode('. ', $frags) . '.';
        if (isset($ctx['late_count'])) {
            $l = (int)$ctx['late_count'];
            $parts[] = $l === 0 ? 'No lates this term.' : ('Lates this term: ' . $l . '.');
        }
    } elseif ($category === 'teacher_remark') {
        if (isset($ctx['term_average']) && $ctx['term_average'] !== null) {
            $parts[] = 'Average: ' . number_format((float)$ctx['term_average'], 1) . '.';
        }
        if (isset($ctx['trend']) && $ctx['trend'] !== null) {
            $t = (float)$ctx['trend'];
            if (abs($t) >= 0.05) {
                $sign = $t > 0 ? '+' : '';
                $parts[] = 'Change from prior term: ' . $sign . number_format($t, 1) . '.';
            }
        }
    } elseif ($category === 'head_teacher_remark') {
        if (isset($ctx['pass_count'], $ctx['subject_count']) && (int)$ctx['subject_count'] > 0) {
            $parts[] = 'Subjects passed: ' . (int)$ctx['pass_count'] . ' of ' . (int)$ctx['subject_count'] . '.';
        }
        $band = $ctx['overall_band'] ?? null;
        if (is_array($band) && ($band['letter'] ?? null) !== null) {
            $s = 'Overall: ' . $band['letter'];
            if (($band['word'] ?? null) !== null) $s .= ' / ' . $band['word'];
            $parts[] = $s . '.';
        }
    }

    return implode(' ', $parts);
}

/**
 * [v1.5] computePromotionOutcome — takes the class ladder target and
 * the level fallback name, and returns ['label' => ..., 'reason' => ...].
 * Under v1.6.0 the level fallback is always null.
 */
function computePromotionOutcome(?array $rule, array $ctx, ?string $ladderLabel, bool $isTerminal, ?string $levelFallback): array
{
    if ($isTerminal) {
        return ['label' => 'COMPLETED / GRADUATED', 'reason' => 'This is the final class in the ladder.'];
    }

    $target = ($ladderLabel !== null && $ladderLabel !== '')
        ? $ladderLabel
        : (($levelFallback !== null && $levelFallback !== '') ? $levelFallback : null);

    $labelPass    = $target !== null ? ('PROMOTED TO ' . strtoupper($target)) : 'PROMOTED';
    $labelNotProm = 'NOT PROMOTED';
    $labelPending = 'PENDING REVIEW';

    if ($target === null) {
        return ['label' => $labelPending, 'reason' => 'Ladder not configured for this class.'];
    }

    if ($rule === null || empty($rule)) {
        return ['label' => '—', 'reason' => 'No active promotion rule for this class.'];
    }

    $att = $ctx['attendance_percent'] ?? null;
    $attMin = $rule['attendance_min_percent'] ?? null;
    $attMode = (string)($rule['attendance_enforcement'] ?? 'none');
    if ($attMode === 'manual' && $attMin !== null) {
        return ['label' => $labelPending, 'reason' => 'Attendance requires manual review.'];
    }
    if ($attMode === 'block' && $attMin !== null && $att !== null && $att < (float)$attMin) {
        return ['label' => $labelNotProm, 'reason' => 'Attendance ' . number_format((float)$att, 1) . '% < ' . number_format((float)$attMin, 1) . '%.'];
    }

    if ((string)($rule['policy_type'] ?? '') === 'manual' || (int)($rule['require_manual_approval'] ?? 0) === 1) {
        return ['label' => $labelPending, 'reason' => 'Manual approval required.'];
    }

    $mode  = (string)($rule['rule_mode'] ?? 'all_subjects');
    $policy = (string)($rule['policy_type'] ?? 'threshold');

    if ($mode === 'bece_6') {
        $agg = $ctx['bece_aggregate'] ?? null;
        $passMax = $rule['aggregate_pass_max'] ?? null;
        if ($agg === null || $passMax === null) {
            return ['label' => $labelPending, 'reason' => 'B.E.C.E. aggregate not computable.'];
        }
        if ((float)$agg <= (float)$passMax) {
            return ['label' => $labelPass, 'reason' => 'Aggregate ' . (string)(int)$agg . ' <= ' . number_format((float)$passMax, 0) . '.'];
        }
        return ['label' => $labelNotProm, 'reason' => 'Aggregate ' . (string)(int)$agg . ' > ' . number_format((float)$passMax, 0) . '.'];
    }

    $needsThreshold = in_array($policy, ['threshold', 'mixed'], true);
    $needsSubjects  = in_array($policy, ['subject_gated', 'mixed'], true);
    $avg = $ctx['term_average'] ?? null;
    $threshold = $rule['threshold_score'] ?? null;

    if ($needsThreshold) {
        if ($avg === null || $threshold === null) {
            return ['label' => $labelPending, 'reason' => 'Average or threshold not available.'];
        }
        if ((float)$avg < (float)$threshold) {
            return ['label' => $labelNotProm, 'reason' => 'Average ' . number_format((float)$avg, 1) . ' < ' . number_format((float)$threshold, 1) . '.'];
        }
    }

    if ($needsSubjects) {
        $required = json_decode((string)($rule['required_subjects'] ?? ''), true);
        if (!is_array($required) || empty($required)) {
            return ['label' => $labelPending, 'reason' => 'Required subjects not configured.'];
        }
        $failed = $ctx['failed_subject_ids'] ?? [];
        $missing = array_values(array_intersect(array_map('intval', $required), array_map('intval', $failed)));
        if (!empty($missing)) {
            return ['label' => $labelNotProm, 'reason' => 'Failed required subject(s): ' . implode(', ', $missing) . '.'];
        }
    }

    if ($needsThreshold && $needsSubjects) {
        $reason = 'Average ' . number_format((float)$avg, 1) . ' >= ' . number_format((float)$threshold, 1) . ' and all required subjects passed.';
    } elseif ($needsThreshold) {
        $reason = 'Average ' . number_format((float)$avg, 1) . ' >= ' . number_format((float)$threshold, 1) . '.';
    } elseif ($needsSubjects) {
        $reason = 'All required subjects passed.';
    } else {
        $reason = '';
    }

    return ['label' => $labelPass, 'reason' => $reason];
}

// Precompute class averages for position (once).
$classAverages = [];
foreach ($classMates as $cm) {
    $cmRows = $db->fetchAll(
        "SELECT r.raw_score, r.final_score FROM results r
          WHERE r.tenant_id = ? AND r.student_id = ? AND r.academic_term_id = ?
            AND r.deleted_at IS NULL
            AND r.status IN ('calculated','published')",
        [$tenantId, (int)$cm['id'], $filterTerm]
    );
    if (!is_array($cmRows)) $cmRows = [];
    $sum = 0.0;
    $n = 0;
    foreach ($cmRows as $cr) {
        $v = $cr['final_score'] !== null
            ? (float)$cr['final_score']
            : ($cr['raw_score'] !== null ? (float)$cr['raw_score'] : null);
        if ($v !== null) {
            $sum += $v;
            $n++;
        }
    }
    $classAverages[] = [
        'student_id'     => (int)$cm['id'],
        'student_number' => (string)($cm['student_number'] ?? ''),
        'average'        => $n > 0 ? ($sum / $n) : null,
    ];
}
usort($classAverages, function ($a, $b) {
    if ($a['average'] === null && $b['average'] === null) return strcmp($a['student_number'], $b['student_number']);
    if ($a['average'] === null) return 1;
    if ($b['average'] === null) return -1;
    if ($a['average'] === $b['average']) return strcmp($a['student_number'], $b['student_number']);
    return $b['average'] <=> $a['average'];
});
$classPositionById = [];
foreach ($classAverages as $i => $a) {
    if ($a['average'] !== null) $classPositionById[$a['student_id']] = $i + 1;
}

// Build the per-student payload.
$reports = [];
foreach ($roster as $st) {
    $sid = (int)$st['student_id'];

    // [v1.6.0] Resolve the promotion rule per student on their class_id.
    $promotionRule = null;
    try {
        $prRow = $db->fetchOne(
            "SELECT * FROM promotion_rules
              WHERE tenant_id = ?
                AND status = 'active' AND deleted_at IS NULL
                AND (class_id = ? OR class_id IS NULL)
                AND (academic_year_id = ? OR academic_year_id IS NULL)
              ORDER BY
                (class_id = ?) DESC,
                (academic_year_id = ?) DESC,
                version DESC, id DESC
              LIMIT 1",
            [
                $tenantId,
                (int)$st['class_id'],
                $filterYear,
                (int)$st['class_id'],
                $filterYear,
            ]
        );
        $promotionRule = is_array($prRow) ? $prRow : null;
    } catch (Exception $e) {
        $promotionRule = null;
    }

    $rows = $db->fetchAll(
        "SELECT r.id, r.subject_id, r.raw_score, r.final_score,
                r.grade_symbol, r.grade_label, r.remark, r.is_pass, r.status,
                r.assessment_scheme_id,
                s.subject_name, s.subject_code
           FROM results r
           LEFT JOIN subjects s ON s.id = r.subject_id AND s.deleted_at IS NULL
          WHERE r.tenant_id = ? AND r.student_id = ? AND r.academic_term_id = ?
            AND r.deleted_at IS NULL
            AND r.status IN ('calculated','published')
          ORDER BY s.subject_name ASC, r.id ASC",
        [$tenantId, $sid, $filterTerm]
    );
    if (!is_array($rows)) $rows = [];

    $subjectCap = 10;
    $truncated  = false;
    $allCount   = count($rows);
    if ($allCount > $subjectCap) {
        $rows = array_slice($rows, 0, $subjectCap);
        $truncated = true;
    }

    $subjectCount = 0;
    $scoreSum = 0.0;
    $scoreCount = 0;
    $passedCount = 0;
    $failedCount = 0;
    $failedSubjectIds = [];
    foreach ($rows as $r) {
        $subjectCount++;
        if ((int)$r['is_pass'] === 1) $passedCount++;
        else {
            $failedCount++;
            $failedSubjectIds[] = (int)$r['subject_id'];
        }
        $sc = $r['final_score'] !== null
            ? (float)$r['final_score']
            : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
        if ($sc !== null) {
            $scoreSum += $sc;
            $scoreCount++;
        }
    }
    $termTotal   = $scoreCount > 0 ? round($scoreSum, 2) : null;
    $termAverage = $scoreCount > 0 ? round($scoreSum / $scoreCount, 2) : null;

    $gradeCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
    foreach ($rows as $r) {
        $sym = strtoupper((string)($r['grade_symbol'] ?? ''));
        if ($sym !== '' && isset($gradeCounts[$sym])) {
            $gradeCounts[$sym]++;
        } else {
            $fb = fallbackBand($r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null));
            if ($fb['letter'] !== null) $gradeCounts[$fb['letter']]++;
        }
    }

    $subjectPositions = [];
    foreach ($rows as $r) {
        $subjectId = (int)$r['subject_id'];
        if ($subjectId <= 0) continue;
        $subjRows = $db->fetchAll(
            "SELECT r.student_id, r.raw_score, r.final_score FROM results r
              WHERE r.tenant_id = ? AND r.subject_id = ? AND r.academic_term_id = ?
                AND r.deleted_at IS NULL
                AND r.status IN ('calculated','published')",
            [$tenantId, $subjectId, $filterTerm]
        );
        if (!is_array($subjRows)) $subjRows = [];
        $scored = [];
        foreach ($subjRows as $sr) {
            $v = $sr['final_score'] !== null
                ? (float)$sr['final_score']
                : ($sr['raw_score'] !== null ? (float)$sr['raw_score'] : null);
            if ($v !== null) $scored[] = ['student_id' => (int)$sr['student_id'], 'score' => $v, 'num' => ''];
        }
        foreach ($scored as &$sref) {
            foreach ($classMates as $cm) {
                if ((int)$cm['id'] === $sref['student_id']) {
                    $sref['num'] = (string)($cm['student_number'] ?? '');
                    break;
                }
            }
        }
        unset($sref);
        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) return strcmp($a['num'], $b['num']);
            return $b['score'] <=> $a['score'];
        });
        foreach ($scored as $i => $s) {
            if ($s['student_id'] === $sid) {
                $subjectPositions[$subjectId] = $i + 1;
                break;
            }
        }
    }

    $attendance = null;
    try {
        $attRow = $db->fetchOne(
            "SELECT days_present, days_absent, days_late, days_excused, days_total, status
               FROM attendance_summaries
              WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 1",
            [$tenantId, $sid, $filterTerm]
        );
        $attendance = is_array($attRow) ? $attRow : null;
    } catch (Exception $e) {
        $attendance = null;
    }

    $behaviour = null;
    try {
        $bhvRow = $db->fetchOne(
            "SELECT final_score, final_grade, incidents_minor, incidents_moderate,
                    incidents_major, late_count, absence_count,
                    rating_punctuality, rating_respect, rating_participation,
                    status
               FROM student_behaviour
              WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
                AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 1",
            [$tenantId, $sid, $filterTerm]
        );
        $behaviour = is_array($bhvRow) ? $bhvRow : null;
    } catch (Exception $e) {
        $behaviour = null;
    }

    $phrases = PhraseGenerator::generate($db, $tenantId, $sid, $filterTerm);
    if (!is_array($phrases)) $phrases = [];

    $trend = null;
    try {
        $prevTerm = $db->fetchOne(
            "SELECT id FROM academic_terms
              WHERE tenant_id = ? AND academic_year_id = ?
                AND sort_order < ?
                AND deleted_at IS NULL
              ORDER BY sort_order DESC LIMIT 1",
            [$tenantId, (int)($selectedTerm['academic_year_id'] ?? 0), (int)($selectedTerm['sort_order'] ?? 0)]
        );
        if ($prevTerm) {
            $prevRows = $db->fetchAll(
                "SELECT r.raw_score, r.final_score FROM results r
                  WHERE r.tenant_id = ? AND r.student_id = ? AND r.academic_term_id = ?
                    AND r.deleted_at IS NULL
                    AND r.status IN ('calculated','published')",
                [$tenantId, $sid, (int)$prevTerm['id']]
            );
            if (!is_array($prevRows)) $prevRows = [];
            $sum = 0.0;
            $n = 0;
            foreach ($prevRows as $pr) {
                $v = $pr['final_score'] !== null
                    ? (float)$pr['final_score']
                    : ($pr['raw_score'] !== null ? (float)$pr['raw_score'] : null);
                if ($v !== null) {
                    $sum += $v;
                    $n++;
                }
            }
            if ($n > 0 && $termAverage !== null) $trend = $termAverage - ($sum / $n);
        }
    } catch (Exception $e) {
    }

    $attendancePercent = null;
    if ($attendance && (int)($attendance['days_total'] ?? 0) > 0) {
        $attendancePercent = round(((int)$attendance['days_present'] / (int)$attendance['days_total']) * 100, 1);
    }

    // [v1.5] Class ladder target — the class the student moves into.
    $nextClassLabel    = null;
    $currentIsTerminal = false;
    try {
        $ladderRow = $db->fetchOne(
            "SELECT c.next_class_id, c.is_terminal, nc.class_name AS next_class_name
               FROM classes c
               LEFT JOIN classes nc ON nc.id = c.next_class_id AND nc.deleted_at IS NULL
              WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL
              LIMIT 1",
            [(int)$st['class_id'], $tenantId]
        );
        if ($ladderRow) {
            $currentIsTerminal = ((int)($ladderRow['is_terminal'] ?? 0)) === 1;
            if (!empty($ladderRow['next_class_name'])) {
                $nextClassLabel = (string)$ladderRow['next_class_name'];
            }
        }
    } catch (Exception $e) {
    }

    // [v1.6.0] Level fallback name is no longer sourced. The variable
    // is retained so the shape of the computePromotionOutcome() call
    // is unchanged. It is always the empty string.
    $nextLevelName = '';

    $overallBand = fallbackBand($termAverage);

    $remarkContext = [
        'behaviour_grade' => $behaviour['final_grade'] ?? null,
        'inc_minor'       => $behaviour['incidents_minor'] ?? null,
        'inc_moderate'    => $behaviour['incidents_moderate'] ?? null,
        'inc_major'       => $behaviour['incidents_major'] ?? null,
        'rating_punctuality'   => $behaviour['rating_punctuality'] ?? null,
        'rating_respect'       => $behaviour['rating_respect'] ?? null,
        'rating_participation' => $behaviour['rating_participation'] ?? null,
        'late_count'      => $behaviour['late_count'] ?? null,
        'term_average'    => $termAverage,
        'trend'           => $trend,
        'pass_count'      => $passedCount,
        'subject_count'   => $subjectCount,
        'overall_band'    => $overallBand,
    ];

    $profileText = profileParagraph($phrases['profile'] ?? null, $attendance, $termAverage, $overallBand, $behaviour);
    $conductText = remarkParagraph('conduct', $phrases['conduct'] ?? null, $remarkContext);
    $attitudeText = remarkParagraph('attitude', $phrases['attitude'] ?? null, $remarkContext);
    $teacherText = remarkParagraph('teacher_remark', $phrases['teacher_remark'] ?? null, $remarkContext);
    $headText = remarkParagraph('head_teacher_remark', $phrases['head_teacher_remark'] ?? null, $remarkContext);

    // [v1.5.1] Term Three check is by sort_order, not by name.
    $termIsThree = ((int)($selectedTerm['sort_order'] ?? 0)) === 3;

    $promoOutcome = ['label' => null, 'reason' => ''];
    if ($termIsThree) {
        $promoOutcome = computePromotionOutcome(
            $promotionRule,
            [
                'attendance_percent' => $attendancePercent,
                'term_average'       => $termAverage,
                'bece_aggregate'     => ($isBeceMode && !empty($rows)) ? (function () use ($rows) {
                    $sum = 0;
                    $n = 0;
                    foreach ($rows as $r) {
                        $fb = fallbackBand($r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null));
                        if ($fb['point'] !== null) {
                            $sum += $fb['point'];
                            $n++;
                        }
                    }
                    return $n > 0 ? $sum : null;
                })() : null,
                'failed_subject_ids' => $failedSubjectIds,
            ],
            $nextClassLabel,
            $currentIsTerminal,
            $nextLevelName !== '' ? $nextLevelName : null
        );
    }

    $fullName = trim(
        ($st['first_name'] ?? '') . ' ' .
            ($st['middle_name'] ?? '') . ' ' .
            ($st['last_name'] ?? '')
    );
    $fullName = $fullName !== '' ? preg_replace('/\s+/', ' ', $fullName) : ('Student #' . $sid);
    $initials = strtoupper(
        substr((string)($st['first_name'] ?? ''), 0, 1) .
            substr((string)($st['last_name'] ?? ''), 0, 1)
    );
    if ($initials === '') $initials = 'ST';

    $age = null;
    if (!empty($st['date_of_birth'])) {
        try {
            $dob = new DateTime((string)$st['date_of_birth']);
            $age = $dob->diff(new DateTime('today'))->y;
        } catch (Exception $e) {
        }
    }

    $beceAggregate = null;
    if ($isBeceMode && !empty($rows)) {
        $sum = 0;
        $n = 0;
        foreach ($rows as $r) {
            $fb = fallbackBand($r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null));
            if ($fb['point'] !== null) {
                $sum += $fb['point'];
                $n++;
            }
        }
        if ($n > 0) $beceAggregate = $sum;
    }

    $qrPayload = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . '/platform/tenant/reports/view.php'
        . '?student_id=' . $sid
        . '&year_id=' . $filterYear
        . '&term_id=' . $filterTerm
        . '&offering_id=' . $offeringId;

    $reports[] = [
        'student'          => $st,
        'student_id'       => $sid,
        'full_name'        => $fullName,
        'initials'         => $initials,
        'age'              => $age,
        'rows'             => $rows,
        'truncated'        => $truncated,
        'subjects_avail'   => $allCount,
        'subject_cap'      => $subjectCap,
        'subject_count'    => $subjectCount,
        'term_total'       => $termTotal,
        'term_average'     => $termAverage,
        'overall_band'     => $overallBand,
        'grade_counts'     => $gradeCounts,
        'subject_positions' => $subjectPositions,
        'class_position'   => $classPositionById[$sid] ?? null,
        'attendance'       => $attendance,
        'phrases'          => $phrases,
        'promoted_to'      => $promoOutcome['label'],
        'promoted_reason'  => $promoOutcome['reason'],
        'term_is_three'    => $termIsThree,
        'bece_aggregate'   => $beceAggregate,
        'qr_payload'       => $qrPayload,
        'profile_text'     => $profileText,
        'conduct_text'     => $conductText,
        'attitude_text'    => $attitudeText,
        'teacher_text'     => $teacherText,
        'head_text'        => $headText,
    ];
}

$termName      = (string)($selectedTerm['term_name'] ?? '');
$yearName      = $selectedYear ? (string)$selectedYear['year_name'] : '';
$vacationDate  = (string)($selectedTerm['vacation_date'] ?? '');
$nextTermB     = (string)($selectedTerm['next_term_begins'] ?? '');

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$pageTitle = 'Print Class - ' . (string)($offering['class_name'] ?? 'Class') . ' - Student 360 Platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            background: #e9ecef;
            font-family: 'Inter', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.35;
            color: #1a1a2e;
        }

        .print-shell {
            max-width: 210mm;
            margin: 0 auto;
            padding: 14px;
        }

        .print-toolbar {
            background: #fff;
            border-radius: 12px;
            padding: 12px 18px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .print-toolbar .pt-info {
            font-size: 13px;
            color: #495057;
        }

        .print-toolbar .pt-info strong {
            color: #1a1a2e;
        }

        .print-toolbar .pt-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
            cursor: pointer;
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
            padding: 6px 14px;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
            color: #495057;
        }

        .report-card {
            position: relative;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            padding: 16px 20px;
            margin-bottom: 20px;
            width: 100%;
            color: #1a2a3a;
            overflow: hidden;
            page-break-after: always;
        }

        .report-card:last-child {
            page-break-after: auto;
        }

        .report-card.has-watermark::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60%;
            max-width: 420px;
            height: 60%;
            max-height: 420px;
            background-image: <?php echo $schoolLogo !== '' ? 'url(' . json_encode($schoolLogo) . ')' : 'none'; ?>;
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.08;
            pointer-events: none;
            z-index: 0;
        }

        .report-card>* {
            position: relative;
            z-index: 1;
        }

        .rc-header {
            display: grid;
            grid-template-columns: 90px 1fr 90px;
            align-items: center;
            gap: 8px;
            border-bottom: 3px solid #1a2a3a;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .rc-header .rc-header-photo {
            width: 90px;
            height: 90px;
            border: 1.5px solid #1a2a3a;
            border-radius: 4px;
            overflow: hidden;
            background: #e9eef2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 26px;
            color: #4a6b8a;
        }

        .rc-header .rc-header-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .rc-header .rc-header-center {
            text-align: center;
        }

        .rc-header .rc-header-center h1 {
            font-size: 15px;
            font-weight: 800;
            margin: 0 0 2px;
            letter-spacing: 0.4px;
            color: #1a2a3a;
            text-transform: uppercase;
        }

        .rc-header .rc-header-center .rc-addr {
            font-size: 10px;
            color: #4a6b8a;
            margin: 1px 0;
            letter-spacing: 0.3px;
        }

        .rc-header .rc-header-center .rc-motto {
            font-size: 9.5px;
            color: #6c757d;
            font-style: italic;
            margin-top: 2px;
        }

        .rc-header .rc-header-center .rc-title-line {
            font-size: 11px;
            font-weight: 800;
            color: #1a2a3a;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .rc-header .rc-header-center .rc-title-line-2 {
            font-size: 10px;
            font-weight: 700;
            color: #1a2a3a;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .rc-header .rc-header-logo {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            min-width: 90px;
            min-height: 90px;
            position: relative;
        }

        .rc-header .rc-header-logo img {
            max-height: 90px;
            max-width: 90px;
            width: auto;
            height: auto;
            display: block;
        }

        .rc-header .rc-header-logo .rc-header-logo-label {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.2px;
            color: #b8c4d0;
            text-transform: uppercase;
            pointer-events: none;
            user-select: none;
            display: none;
        }

        .rc-header .rc-header-logo.rc-header-logo-empty {
            border: 1.5px dashed #b8c4d0;
            border-radius: 4px;
            background: #f5f8fb;
        }

        .rc-header .rc-header-logo.rc-header-logo-empty .rc-header-logo-label {
            display: block;
        }

        .rc-header .rc-header-logo.rc-header-logo-empty img {
            visibility: hidden;
        }

        .rc-row2 {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 8px;
            margin-bottom: 8px;
        }

        .rc-row2-equal {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 8px;
        }

        .rc-identity {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 5px 8px;
            background: #fff;
        }

        .rc-id-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dotted #adb5bd;
            padding: 2px 0;
            font-size: 11px;
            line-height: 1.3;
        }

        .rc-id-row:last-child {
            border-bottom: none;
        }

        .rc-id-row .rc-id-lbl {
            color: #1a2a3a;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
            letter-spacing: 0.4px;
        }

        .rc-id-row .rc-id-val {
            color: #1a2a3a;
            font-weight: 700;
            text-align: right;
            font-size: 11px;
        }

        .rc-att {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 5px 8px;
            background: #fff;
        }

        .rc-att-title {
            font-size: 9.5px;
            font-weight: 800;
            color: #1a2a3a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
            border-bottom: 1px solid #ced4da;
            padding-bottom: 2px;
            margin-bottom: 3px;
        }

        .rc-att-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dotted #adb5bd;
            padding: 2px 0;
            font-size: 11px;
            line-height: 1.3;
        }

        .rc-att-row:last-child {
            border-bottom: none;
        }

        .rc-att-row .rc-att-lbl {
            color: #1a2a3a;
            font-weight: 600;
            font-size: 10px;
        }

        .rc-att-row .rc-att-val {
            color: #1a2a3a;
            font-weight: 700;
        }

        .rc-section-title {
            background: #1a2a3a;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 3px 3px 0 0;
            margin-bottom: 0;
        }

        .rc-block {
            border: 1px solid #ced4da;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
        }

        .rc-block .rc-block-body {
            padding: 5px 8px;
            font-size: 11px;
            color: #1a2a3a;
            line-height: 1.35;
            min-height: 40px;
        }

        .rc-block .rc-block-body.rc-muted {
            color: #adb5bd;
            font-style: italic;
        }

        .rc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1px solid #ced4da;
        }

        .rc-table thead th {
            background: #1a2a3a;
            color: #fff;
            padding: 4px 6px;
            font-weight: 700;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-right: 1px solid #2c3e50;
            text-align: left;
        }

        .rc-table thead th:last-child {
            border-right: none;
        }

        .rc-table tbody td {
            padding: 3px 6px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            font-size: 11px;
            color: #1a2a3a;
        }

        .rc-table tbody td:last-child {
            border-right: none;
        }

        .rc-table tbody tr:last-child td {
            border-bottom: none;
        }

        .rc-summary-table,
        .rc-grade-table,
        .rc-scale,
        .rc-kv,
        .rc-sign-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1px solid #ced4da;
        }

        .rc-summary-table thead th,
        .rc-grade-table thead th,
        .rc-scale thead th {
            background: #1a2a3a;
            color: #fff;
            padding: 3px 6px;
            font-weight: 800;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }

        .rc-summary-table tbody td,
        .rc-grade-table tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            text-align: center;
            font-weight: 700;
            color: #1a2a3a;
        }

        .rc-summary-table tbody td:last-child,
        .rc-grade-table tbody td:last-child {
            border-right: none;
        }

        .rc-summary-table tbody tr:last-child td,
        .rc-grade-table tbody tr:last-child td {
            border-bottom: none;
        }

        .rc-summary-table tbody td .rc-sum-label {
            display: block;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            color: #4a6b8a;
            letter-spacing: 0.4px;
            margin-bottom: 1px;
        }

        .rc-scale tbody td {
            padding: 3px 6px;
            border: 1px solid #dee2e6;
            text-align: center;
            font-weight: 600;
            color: #1a2a3a;
            font-size: 10.5px;
        }

        .rc-kv tbody tr td,
        .rc-sign-table tbody tr td {
            padding: 3px 8px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
            color: #1a2a3a;
        }

        .rc-kv tbody tr:last-child td,
        .rc-sign-table tbody tr:last-child td {
            border-bottom: none;
        }

        .rc-kv tbody tr td.rc-kv-lbl,
        .rc-sign-table tbody tr td.rc-sign-lbl {
            width: 180px;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 9.5px;
            letter-spacing: 0.5px;
            color: #1a2a3a;
            background: #f5f8fb;
            border-right: 1px solid #dee2e6;
        }

        .rc-kv tbody tr td.rc-kv-val,
        .rc-sign-table tbody tr td.rc-sign-val {
            font-size: 11px;
        }

        .rc-kv tbody tr td.rc-kv-val.rc-muted {
            color: #adb5bd;
            font-style: italic;
        }

        .rc-sign-table tbody tr td.rc-sign-val {
            font-weight: 700;
        }

        .rc-sign-table tbody tr td.rc-sign-val img {
            max-height: 30px;
            max-width: 130px;
            display: block;
        }

        .rc-promoted {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 11px;
            text-align: center;
            font-weight: 800;
            color: #1a2a3a;
            background: #f5f8fb;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }

        .rc-promoted .rc-promoted-lbl {
            color: #4a6b8a;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-right: 6px;
        }

        .rc-promoted .rc-promoted-reason {
            display: block;
            font-weight: 500;
            font-size: 9.5px;
            color: #4a6b8a;
            letter-spacing: 0.2px;
            margin-top: 2px;
        }

        .rc-promoted.rc-muted {
            color: #adb5bd;
            font-style: italic;
            background: #fafbfc;
        }

        .rc-promoted.rc-promoted-pass {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .rc-promoted.rc-promoted-fail {
            background: #fff5f5;
            border-color: #fecaca;
            color: #991b1b;
        }

        .rc-promoted.rc-promoted-pending {
            background: #fffbf0;
            border-color: #fde68a;
            color: #92400e;
        }

        .rc-footer {
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px solid #1a2a3a;
            font-size: 9px;
            color: #6c757d;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 8px;
        }

        .rc-footer .rc-footer-qr {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }

        .rc-footer .rc-footer-qr .rc-qr-box {
            width: 60px;
            height: 60px;
            background: #fff;
            padding: 2px;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .rc-footer .rc-footer-qr .rc-qr-box img,
        .rc-footer .rc-footer-qr .rc-qr-box canvas {
            max-width: 100%;
            max-height: 100%;
            display: block;
        }

        .rc-footer .rc-footer-qr .rc-qr-lbl {
            font-size: 8px;
            color: #6c757d;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .rc-empty-note {
            padding: 5px 8px;
            background: #fffbf0;
            border: 1px solid #fde68a;
            border-radius: 4px;
            font-size: 10px;
            color: #92400e;
            text-align: center;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 6mm;
            }

            html,
            body {
                background: #fff !important;
                font-size: 9px !important;
                line-height: 1.2 !important;
            }

            .no-print {
                display: none !important;
            }

            .print-shell {
                padding: 0 !important;
                max-width: 100% !important;
            }

            .report-card {
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 0 0 0 !important;
                max-width: 100% !important;
                page-break-after: always;
            }

            .report-card:last-child {
                page-break-after: auto;
            }

            .rc-section-title {
                background: #1a2a3a !important;
                color: #fff !important;
                font-size: 9px !important;
                padding: 2px 6px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-header {
                grid-template-columns: 76px 1fr 76px;
                gap: 6px;
                border-bottom: 2px solid #1a2a3a !important;
                padding-bottom: 5px !important;
                margin-bottom: 6px !important;
            }

            .rc-header .rc-header-photo {
                width: 76px !important;
                height: 76px !important;
                font-size: 22px !important;
                border-width: 1px !important;
            }

            .rc-header .rc-header-logo {
                min-width: 76px !important;
                min-height: 76px !important;
            }

            .rc-header .rc-header-logo img {
                max-height: 76px !important;
                max-width: 76px !important;
            }

            .rc-header .rc-header-center h1 {
                font-size: 12px !important;
            }

            .rc-header .rc-header-center .rc-addr {
                font-size: 8px !important;
                margin: 0 !important;
            }

            .rc-header .rc-header-center .rc-title-line {
                font-size: 9px !important;
                margin-top: 3px !important;
            }

            .rc-header .rc-header-center .rc-title-line-2 {
                font-size: 8px !important;
            }

            .rc-row2,
            .rc-row2-equal {
                gap: 4px !important;
                margin-bottom: 4px !important;
            }

            .rc-identity,
            .rc-att {
                padding: 3px 5px !important;
            }

            .rc-id-row,
            .rc-att-row {
                padding: 0.5px 0 !important;
                font-size: 9px !important;
                line-height: 1.15 !important;
            }

            .rc-id-row .rc-id-lbl {
                font-size: 8px !important;
            }

            .rc-id-row .rc-id-val {
                font-size: 9px !important;
            }

            .rc-block .rc-block-body {
                padding: 3px 5px !important;
                font-size: 9px !important;
                min-height: 26px !important;
            }

            .rc-table {
                font-size: 9px !important;
            }

            .rc-table thead th {
                background: #1a2a3a !important;
                color: #fff !important;
                padding: 2px 4px !important;
                font-size: 8px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-table tbody td {
                padding: 1.5px 4px !important;
                font-size: 9px !important;
            }

            .rc-summary-table,
            .rc-grade-table,
            .rc-scale,
            .rc-kv,
            .rc-sign-table {
                font-size: 9px !important;
            }

            .rc-summary-table thead th,
            .rc-grade-table thead th,
            .rc-scale thead th {
                background: #1a2a3a !important;
                color: #fff !important;
                padding: 2px 4px !important;
                font-size: 8px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-kv tbody tr td.rc-kv-lbl,
            .rc-sign-table tbody tr td.rc-sign-lbl {
                width: 140px !important;
                font-size: 8px !important;
                background: #f5f8fb !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-sign-table tbody tr td.rc-sign-val img {
                max-height: 22px !important;
                max-width: 100px !important;
            }

            .rc-footer {
                margin-top: 4px !important;
                padding-top: 3px !important;
                font-size: 7.5px !important;
            }

            .rc-footer .rc-footer-qr .rc-qr-box {
                width: 48px !important;
                height: 48px !important;
                border: 1px solid #adb5bd !important;
            }

            .rc-footer .rc-footer-qr .rc-qr-lbl {
                font-size: 6.5px !important;
            }

            .rc-table,
            .rc-summary-table,
            .rc-grade-table,
            .rc-scale,
            .rc-kv,
            .rc-sign-table,
            .rc-row2,
            .rc-row2-equal {
                page-break-inside: avoid;
            }

            .report-card.has-watermark::before {
                opacity: 0.06;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>

    <div class="print-shell">

        <div class="print-toolbar no-print">
            <div class="pt-info">
                <i class="fas fa-file-alt me-1 text-primary"></i>
                Printing
                <strong><?php echo count($reports); ?></strong>
                report card<?php echo count($reports) === 1 ? '' : 's'; ?>
                for
                <strong><?php echo h((string)($offering['class_name'] ?? 'Class')); ?></strong>
                <?php if (!empty($offering['stream_name'])): ?>
                    · <?php echo h((string)$offering['stream_name']); ?>
                <?php endif; ?>
                · <?php echo h($yearName); ?>
                · <?php echo h($termName); ?>
            </div>
            <div class="pt-actions">
                <button type="button" class="btn-primary" onclick="printAll()">
                    <i class="fas fa-print me-1"></i> Print / Save as PDF
                </button>
                <a href="/platform/tenant/reports/index.php?year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>&offering_id=<?php echo (int)$offeringId; ?>"
                    class="btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <?php foreach ($reports as $rep):
            $st           = $rep['student'];
            $rows         = $rep['rows'];
            $overallBand  = $rep['overall_band'];
            $gradeCounts  = $rep['grade_counts'];
            $attendance   = $rep['attendance'];
            $phrases      = $rep['phrases'];
            $classSizeVar = $classSize;
        ?>
            <div class="report-card <?php echo $schoolLogo !== '' ? 'has-watermark' : ''; ?>">

                <div class="rc-header">
                    <div class="rc-header-photo">
                        <?php if (!empty($st['profile_photo_url'])): ?>
                            <img src="<?php echo h((string)$st['profile_photo_url']); ?>" alt="" onerror="this.style.display='none';">
                        <?php else: ?>
                            <?php echo h($rep['initials']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="rc-header-center">
                        <h1><?php echo h($headerLine1 !== '' ? $headerLine1 : 'School'); ?></h1>
                        <p class="rc-addr"><?php echo h($headerLine2); ?></p>
                        <p class="rc-addr"><?php echo h($headerLine3); ?></p>
                        <?php if ($headerMotto !== ''): ?>
                            <div class="rc-motto"><?php echo h($headerMotto); ?></div>
                        <?php endif; ?>
                        <div class="rc-title-line"><?php echo h(($termName !== '' ? strtoupper($termName) : 'TERM') . " STUDENT'S PERFORMANCE REPORT"); ?></div>
                        <div class="rc-title-line-2"><?php echo h($yearName !== '' ? $yearName . ' ACADEMIC YEAR' : 'ACADEMIC YEAR'); ?></div>
                    </div>
                    <div class="rc-header-logo<?php echo $schoolLogo === '' ? ' rc-header-logo-empty' : ''; ?>">
                        <img src="<?php echo h($logoSrc); ?>"
                            alt="School crest"
                            onerror="this.style.visibility='hidden'; this.parentElement.classList.add('rc-header-logo-empty');">
                        <span class="rc-header-logo-label">LOGO</span>
                    </div>
                </div>

                <div class="rc-row2">
                    <div class="rc-identity">
                        <div class="rc-id-row"><span class="rc-id-lbl">Name</span><span class="rc-id-val"><?php echo h($rep['full_name']); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">D.O.B.</span><span class="rc-id-val"><?php echo h(!empty($st['date_of_birth']) ? fmtDate((string)$st['date_of_birth']) : '—'); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Gender</span><span class="rc-id-val"><?php echo h(!empty($st['gender']) ? strtoupper(substr((string)$st['gender'], 0, 1)) : '—'); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Age</span><span class="rc-id-val"><?php echo $rep['age'] !== null ? h((string)$rep['age'] . ' YEARS') : '—'; ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Code</span><span class="rc-id-val"><?php echo h((string)($st['student_number'] ?? '—')); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Class</span><span class="rc-id-val"><?php echo h((string)($st['class_name'] ?? '—')); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Number on Roll</span><span class="rc-id-val"><?php echo h((string)$classSizeVar); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Total Score</span><span class="rc-id-val"><?php echo $isBeceMode && $rep['bece_aggregate'] !== null ? h((string)$rep['bece_aggregate']) : h(fmtNum($rep['term_total'], 0)); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Out of</span><span class="rc-id-val"><?php echo h((string)(count($rows) * 100)); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Grade</span><span class="rc-id-val"><?php echo $overallBand['letter'] !== null ? h($overallBand['letter']) : '—'; ?></span></div>
                    </div>

                    <div class="rc-att">
                        <div class="rc-att-title">Attendance Summary</div>
                        <?php if (!$attendance): ?>
                            <div style="font-size:10px;color:#92400e;text-align:center;padding:4px;">Not verified for this term.</div>
                        <?php else:
                            $tOpen = (int)($attendance['days_total']   ?? 0);
                            $tPres = (int)($attendance['days_present'] ?? 0);
                            $tAbs  = (int)($attendance['days_absent']  ?? 0);
                            $pPres = $tOpen > 0 ? round(($tPres / $tOpen) * 100, 1) : null;
                            $pAbs  = $tOpen > 0 ? round(($tAbs  / $tOpen) * 100, 1) : null;
                        ?>
                            <div class="rc-att-row"><span class="rc-att-lbl">No. of times opened</span><span class="rc-att-val"><?php echo h((string)$tOpen); ?></span></div>
                            <div class="rc-att-row"><span class="rc-att-lbl">No. of times present</span><span class="rc-att-val"><?php echo h((string)$tPres); ?><?php if ($pPres !== null): ?> · <?php echo h((string)$pPres); ?>%<?php endif; ?></span></div>
                            <div class="rc-att-row"><span class="rc-att-lbl">No. of times absent</span><span class="rc-att-val"><?php echo h((string)$tAbs); ?><?php if ($pAbs !== null): ?> · <?php echo h((string)$pAbs); ?>%<?php endif; ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rc-row2-equal">
                    <div class="rc-block">
                        <div class="rc-section-title">Profile</div>
                        <div class="rc-block-body <?php echo ($phrases['profile'] ?? null) === null ? 'rc-muted' : ''; ?>">
                            <?php echo h($rep['profile_text']); ?>
                        </div>
                    </div>
                    <div class="rc-block">
                        <div class="rc-section-title">Position</div>
                        <div class="rc-block-body <?php echo ($rep['class_position'] === null || $classSizeVar === 0) ? 'rc-muted' : ''; ?>">
                            <?php if ($rep['class_position'] !== null && $classSizeVar > 0): ?>
                                <?php echo h((string)$rep['class_position'] . ordinal($rep['class_position'])); ?> out of <?php echo h((string)$classSizeVar); ?> students
                                <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="rc-section-title">Cognitive Domain</div>
                <?php if (empty($rows)): ?>
                    <div class="rc-empty-note"><i class="fas fa-info-circle me-1"></i> No calculated or published results are available for this student in this term.</div>
                <?php else: ?>
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th style="min-width:130px;">Subject</th>
                                <th style="width:80px;text-align:center;">Class Score</th>
                                <th style="width:80px;text-align:center;">Exam Score</th>
                                <th style="width:70px;text-align:center;">Total</th>
                                <th style="width:55px;text-align:center;">Grade</th>
                                <th style="width:70px;text-align:center;">Position</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $tot = $r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
                                $schemeId = isset($r['assessment_scheme_id']) && $r['assessment_scheme_id'] !== null
                                    ? (int)$r['assessment_scheme_id'] : null;
                                $frac = schemeSplitFractions($db, $tenantId, $schemeId);
                                $classScore = $tot !== null ? round($tot * $frac['class'], 2) : null;
                                $examScore  = $tot !== null ? round($tot * $frac['exam'],  2) : null;
                                $symbol     = (string)($r['grade_symbol'] ?? '');
                                $remark     = (string)($r['remark'] ?? '');
                                $subName    = (string)($r['subject_name'] ?? '');
                                if ($subName === '') $subName = 'Subject #' . (int)$r['subject_id'];
                                $pos = isset($rep['subject_positions'][(int)$r['subject_id']]) ? (int)$rep['subject_positions'][(int)$r['subject_id']] : null;
                            ?>
                                <tr>
                                    <td><strong><?php echo h(strtoupper($subName)); ?></strong></td>
                                    <td style="text-align:center;"><?php echo h(fmtNum($classScore, 1)); ?></td>
                                    <td style="text-align:center;"><?php echo h(fmtNum($examScore, 1)); ?></td>
                                    <td style="text-align:center;font-weight:700;"><?php echo h(fmtNum($tot, 1)); ?></td>
                                    <td style="text-align:center;font-weight:700;"><?php echo h($symbol !== '' ? strtoupper($symbol) : '—'); ?></td>
                                    <td style="text-align:center;"><?php echo $pos !== null ? h((string)$pos . ordinal($pos)) : '—'; ?></td>
                                    <td><?php echo h($remark !== '' ? strtoupper($remark) : '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($rep['truncated']): ?>
                        <div style="font-size:10px;color:#6c757d;margin-top:4px;text-align:right;"><i class="fas fa-info-circle me-1"></i> Showing <?php echo h((string)$rep['subject_cap']); ?> of <?php echo h((string)$rep['subjects_avail']); ?> subjects.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <div style="height:8px;"></div>

                <div class="rc-row2-equal">
                    <div>
                        <div class="rc-section-title">Performance Summary</div>
                        <table class="rc-summary-table">
                            <thead>
                                <tr>
                                    <th style="width:33%;">Total<br>Obtained</th>
                                    <th style="width:33%;">Average</th>
                                    <th style="width:34%;">Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="rc-sum-label">Total</span><?php if ($isBeceMode && $rep['bece_aggregate'] !== null): ?><?php echo h((string)$rep['bece_aggregate']); ?> / 54<?php else: ?><?php echo h(fmtNum($rep['term_total'], 0)); ?><?php endif; ?></td>
                                    <td><span class="rc-sum-label">Avg</span><?php echo h(fmtNum($rep['term_average'], 0)); ?></td>
                                    <td><span class="rc-sum-label">Grade</span><?php echo $overallBand['letter'] !== null ? h($overallBand['letter'] . ' / ' . ($overallBand['word'] ?? '')) : '—'; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <div class="rc-section-title">Grade Analysis</div>
                        <table class="rc-grade-table">
                            <thead>
                                <tr>
                                    <th style="width:50%;">Grade</th>
                                    <th style="width:50%;">No.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (['A', 'B', 'C', 'D', 'E'] as $ltr): ?>
                                    <tr>
                                        <td><?php echo h($ltr); ?></td>
                                        <td><?php echo h((string)$gradeCounts[$ltr]); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="2" style="background:#eef3f7;font-size:10px;text-transform:uppercase;letter-spacing:0.4px;color:#4a6b8a;">No. of Subjects: <strong style="color:#1a2a3a;"><?php echo h((string)$rep['subject_count']); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rc-section-title">Grade Scale</div>
                <table class="rc-scale">
                    <thead>
                        <tr>
                            <th>80 – 100<br>(Excellent)</th>
                            <th>66 – 79<br>(Very Good)</th>
                            <th>46 – 65<br>(Average)</th>
                            <th>36 – 45<br>(Pass)</th>
                            <th>0 – 35<br>(Low Average)</th>
                        </tr>
                    </thead>
                </table>

                <div style="height:8px;"></div>

                <?php
                $promoLabel  = $rep['promoted_to'];
                $promoReason = $rep['promoted_reason'];
                $promoClass  = 'rc-muted';
                if ($promoLabel !== null) {
                    if (
                        strpos((string)$promoLabel, 'PROMOTED TO') === 0
                        || $promoLabel === 'PROMOTED'
                        || $promoLabel === 'COMPLETED / GRADUATED'
                    ) {
                        $promoClass = 'rc-promoted-pass';
                    } elseif ($promoLabel === 'NOT PROMOTED') {
                        $promoClass = 'rc-promoted-fail';
                    } elseif ($promoLabel === 'PENDING REVIEW') {
                        $promoClass = 'rc-promoted-pending';
                    }
                }
                ?>
                <div class="rc-promoted <?php echo $promoClass; ?>">
                    <span class="rc-promoted-lbl">Promoted To</span>
                    <?php if ($promoLabel !== null): ?>
                        <?php echo h($promoLabel); ?>
                        <?php if (!empty($promoReason)): ?>
                            <span class="rc-promoted-reason"><?php echo h($promoReason); ?></span>
                        <?php endif; ?>
                    <?php elseif ($rep['term_is_three']): ?>
                        — (no active promotion rule for this class)
                    <?php else: ?>
                        — (determined at the end of Term Three)
                    <?php endif; ?>
                </div>

                <table class="rc-kv">
                    <tbody>
                        <tr>
                            <td class="rc-kv-lbl">Conduct</td>
                            <td class="rc-kv-val <?php echo ($phrases['conduct'] ?? null) === null ? 'rc-muted' : ''; ?>"><?php echo h($rep['conduct_text']); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-kv-lbl">Attitude</td>
                            <td class="rc-kv-val <?php echo ($phrases['attitude'] ?? null) === null ? 'rc-muted' : ''; ?>"><?php echo h($rep['attitude_text']); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-kv-lbl">Teacher's Remark</td>
                            <td class="rc-kv-val <?php echo ($phrases['teacher_remark'] ?? null) === null ? 'rc-muted' : ''; ?>"><?php echo h($rep['teacher_text']); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-kv-lbl">Head Teacher's Remarks</td>
                            <td class="rc-kv-val <?php echo ($phrases['head_teacher_remark'] ?? null) === null ? 'rc-muted' : ''; ?>"><?php echo h($rep['head_text']); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="height:6px;"></div>

                <table class="rc-sign-table">
                    <tbody>
                        <tr>
                            <td class="rc-sign-lbl">Class Teacher's Name</td>
                            <td class="rc-sign-val"><?php echo h($classTeacherName !== null ? $classTeacherName : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Head Teacher's Name</td>
                            <td class="rc-sign-val"><?php echo h($headTeacherName !== null ? $headTeacherName : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Head Teacher's Signature</td>
                            <td class="rc-sign-val">
                                <?php if ($headTeacherSignature !== null): ?><img src="<?php echo h($headTeacherSignature); ?>" alt=""><?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div style="height:6px;"></div>

                <table class="rc-sign-table">
                    <tbody>
                        <tr>
                            <td class="rc-sign-lbl">Year</td>
                            <td class="rc-sign-val"><?php echo h($yearName !== '' ? $yearName : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Term</td>
                            <td class="rc-sign-val"><?php echo h($termName !== '' ? strtoupper($termName) : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Vacation Date</td>
                            <td class="rc-sign-val"><?php echo h($vacationDate !== '' ? strtoupper(fmtDate($vacationDate)) : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Next Term Begins</td>
                            <td class="rc-sign-val"><?php echo h($nextTermB !== '' ? strtoupper(fmtDate($nextTermB)) : '—'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="rc-footer">
                    <div><i class="fas fa-info-circle me-1"></i> This report is generated from the school's records at the moment of printing.</div>
                    <div class="rc-footer-qr">
                        <div class="rc-qr-box js-qr-target" data-payload="<?php echo h($rep['qr_payload']); ?>"></div>
                        <div class="rc-qr-lbl">Scan to verify</div>
                    </div>
                    <div>Printed <?php echo h(date('M j, Y')); ?></div>
                </div>

            </div>
        <?php endforeach; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        function printAll() {
            window.print();
        }

        (function() {
            if (typeof QRCode === 'undefined') return;
            document.querySelectorAll('.js-qr-target').forEach(function(box) {
                var payload = box.getAttribute('data-payload') || '';
                if (!payload) return;
                new QRCode(box, {
                    text: payload,
                    width: 60,
                    height: 60,
                    colorDark: '#1a2a3a',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            });
        })();
    </script>
</body>

</html>