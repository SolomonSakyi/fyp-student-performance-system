<?php

/**
 * Student Reports — printable per-term report card for a guardian.
 *
 * @package EduTrack
 * @subpackage Platform\Student
 * @version 2.8
 * @filepath public/platform/student/reports.php
 *
 * v2.8 change (2026-10-07) [CLASS-ANCHOR PROMOTION]:
 *   The Promoted To block is brought into line with the school-side
 *   report card (public/platform/tenant/reports/view.php v1.6.0 and
 *   print-class.php v1.6.0). Four changes:
 *     - The Term Three check now tests academic_terms.sort_order
 *       === 3 instead of the substring "three" in the term name.
 *     - The applicable promotion_rules row is now looked up by the
 *       student's class_id, not by the class's academic_level_id.
 *     - The PROMOTED TO target now comes from the class ladder
 *       (classes.next_class_id → classes.class_name). A class whose
 *       is_terminal = 1 renders COMPLETED / GRADUATED.
 *     - The label set now matches the school-side file:
 *       COMPLETED / GRADUATED, PROMOTED TO <next class_name>,
 *       NOT PROMOTED, PENDING REVIEW, or — (no active promotion
 *       rule). Each carries a reason line. Each renders with one of
 *       the four CSS classes rc-promoted-pass, rc-promoted-fail,
 *       rc-promoted-pending, rc-muted.
 *   A page-local helper computePromotionOutcome_g() is added,
 *   ported from the school-side file, to keep the two report-card
 *   surfaces in lockstep. Every other line of the file is
 *   byte-identical to v2.7.
 *
 * v2.7 change (2026-10-07) [STUDENT-YEAR DEFAULT]:
 *   The default academic year and term now resolve from the
 *   student's own students.academic_year_id and
 *   students.academic_term_id columns, instead of from the
 *   calendar's is_current pointer. The is_current DESC loop is
 *   retained as a fallback and runs only when the student's own
 *   columns are null or zero.
 *
 * WHAT CHANGED FROM v2.5:
 * - The header logo slot now always renders a visible placeholder — a
 *   dashed border and a "LOGO" caption — when $schoolLogo is empty OR
 *   when the image URL 404s. The <img> tag is always emitted; when
 *   $schoolLogo is empty it uses a 1×1 transparent GIF data URI so
 *   it never 404s on its own. The onerror handler adds the class
 *   rc-header-logo-empty to the parent slot, which reveals the dashed
 *   placeholder behind the hidden image.
 * - This makes the intended logo position visible on every render, so
 *   that once a real PNG is placed at the DB-stored path, the logo
 *   appears in the same slot.
 * - Everything else is byte-identical to v2.5.
 *
 * WHAT CHANGED FROM v2.4:
 * - Removed the temporary diagnostic panel.
 * - School logo read is DB-backed (schools.logo_path → tenants.logo
 *   → /uploads/schools/{school_id}/logo.png).
 *
 * WHAT CHANGED FROM v2.3:
 * - Added a temporary top-of-file diagnostic panel (removed in v2.5).
 *
 * WHAT THIS PAGE DOES:
 * - Requires an authenticated guardian session.
 * - Reads student_id from $_GET.
 * - Calls guardian_can_view_student($studentId). On false, 404.
 * - Reads optional year_id and term_id from $_GET.
 * - Loads all report-card data for one term.
 * - Renders the report card in a print-optimised A4 layout.
 * - Print and Save-as-PDF buttons call window.print().
 * - Client-side QR code in the footer (qrcodejs@1.0.0 from jsdelivr).
 *   Payload = the report card's own URL.
 *
 * WHAT THIS PAGE DOES NOT DO:
 * - No edits. No POST, no CSRF, no audit.
 * - No server-side PDF (Path B deferred).
 * - No reads of students.guardian_* flat columns.
 *
 * FALLBACKS IN USE (stated in code, not assumed):
 * - NUMBER ON ROLL: count of students in the same class.
 * - GRADE / TOTAL GRADE / OVERALL GRADE: fallback band table
 *     80–100 A / Excellent, 66–79 B / Very Good, 46–65 C / Average,
 *     36–45 D / Pass, 0–35 E / Low Average.
 * - GRADE SCALE line: static.
 * - J.H.S. bece_6 aggregate: sum of fallback grade points A=1..E=5.
 * - Subjects > 10: truncate at 10 by subject_name asc, with a note.
 * - Student photo: initials avatar when profile_photo_url is NULL.
 * - School logo: DB-backed chain (schools.logo_path → tenants.logo →
 *   /uploads/schools/{school_id}/logo.png). If all three are empty or
 *   404, the header slot shows a dashed "LOGO" placeholder and the
 *   watermark is skipped.
 *
 * LOCKED DECISIONS (unchanged):
 * - Term Three only for PROMOTED TO. [v2.8] Detected by
 *   academic_terms.sort_order === 3.
 * - Subjects cap at 10.
 * - Class/Exam split uses the scheme's actual weights (60/40 today).
 * - Position: average desc, ties by student number asc.
 * - Missing fields render "—".
 * - Head teacher identified by staff.staff_type_id = 4.
 * - Signature from staff_signatures.signature_data where is_primary = 1.
 * - Phrases via PhraseGenerator (deterministic band-based).
 * - QR payload: the report card's own URL.
 * - Watermark: the school logo at 8% opacity, centered (skipped when
 *   $schoolLogo is empty).
 * - QR library: client-side qrcodejs from jsdelivr.
 * - (P-1) One-page fit on print: compacted density. No clipping.
 * - [v2.8] Promotion target: classes.next_class_id /
 *   classes.is_terminal. Rule anchor: promotion_rules.class_id.
 *
 * AUTHENTICATION MODEL:
 * Session keys read (written by login.php v2.4):
 *   $_SESSION['logged_in']   = true
 *   $_SESSION['user_type']   = 'guardian'
 *   $_SESSION['guardian_id'] = the guardians.id
 *   $_SESSION['user_id']     = the platform_users.id
 *   $_SESSION['tenant_id']   = the tenant
 *   $_SESSION['school_id']   = the school
 *   $_SESSION['school_name'] = the school name
 *
 * DEPENDENCIES:
 * - app/bootstrap.php
 * - app/helpers/Permissions.php v1.2
 * - app/helpers/PhraseGenerator.php v1.0
 * - qrcodejs@1.0.0 from jsdelivr (client-side, no composer)
 */
// ============================================
// Bootstrap
// ============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';
require_once $projectRoot . '/app/helpers/Permissions.php';
require_once $projectRoot . '/app/helpers/PhraseGenerator.php';

function h_g(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ============================================
// Guardian authentication
// ============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}
if (!is_guardian()) {
    if (function_exists('is_super_admin') && is_super_admin()) {
        header('Location: /platform/index.php');
        exit;
    }
    header('Location: /platform/tenant/dashboard.php');
    exit;
}
$guardianId = (int)($_SESSION['guardian_id'] ?? 0);
$userId     = (int)($_SESSION['user_id']     ?? 0);
$tenantId   = (int)($_SESSION['tenant_id']   ?? 0);
$schoolId   = (int)($_SESSION['school_id']   ?? 0);
if ($guardianId <= 0 || $tenantId <= 0) {
    session_destroy();
    header('Location: /platform/tenant/login.php');
    exit;
}

// ============================================
// student_id
// ============================================
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($studentId <= 0) {
    header('Location: /platform/guardian/index.php');
    exit;
}

// ============================================
// Access check — 404 on failure (not 403)
// ============================================
$allowed = guardian_can_view_student($studentId);
if (!$allowed) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Not found</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                font-family: system-ui, sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .nf-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                max-width: 480px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            }

            .nf-card i {
                font-size: 48px;
                color: #dc3545;
                margin-bottom: 16px;
                display: block;
            }

            .nf-card h1 {
                font-size: 22px;
                font-weight: 700;
                color: #1a1a2e;
                margin-bottom: 8px;
            }

            .nf-card p {
                color: #6c757d;
                font-size: 14px;
                margin: 0 0 16px;
            }

            .nf-card a {
                color: #0d6efd;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>

    <body>
        <div class="nf-card">
            <i class="fas fa-user-slash"></i>
            <h1>Not found</h1>
            <p>The record you are looking for is not available.</p>
            <a href="/platform/guardian/index.php"><i class="fas fa-arrow-left me-1"></i> Back to dashboard</a>
        </div>
    </body>

    </html>
<?php
    exit;
}

$db = DatabaseHelper::getInstance();

// ============================================
// Load the student with class join
// ============================================
$student = $db->fetchOne(
    "SELECT s.id, s.tenant_id, s.student_number,
            s.first_name, s.middle_name, s.last_name, s.preferred_name,
            s.date_of_birth, s.gender, s.class_id, s.profile_photo_url,
            s.academic_year_id, s.academic_term_id,
            c.class_name, c.class_code
       FROM students s
       LEFT JOIN classes c ON c.id = s.class_id AND c.deleted_at IS NULL
      WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$studentId, $tenantId]
);
if (!$student) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Not found</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>Not found</h1><p>The student record is not available.</p><a href="/platform/guardian/index.php">Back to dashboard</a></body></html>';
    exit;
}

// ============================================
// Resolve the term
// ============================================
$years = $db->fetchAll(
    "SELECT id, year_name, is_current
       FROM academic_years
      WHERE tenant_id = ? AND deleted_at IS NULL
      ORDER BY is_current DESC, id DESC",
    [$tenantId]
);

$filterYear = (int)($_GET['year_id'] ?? 0);
$filterTerm = (int)($_GET['term_id'] ?? 0);

// Default year: student's own first, then calendar current, then first row.
if ($filterYear <= 0) {
    $studentYear = (int)($student['academic_year_id'] ?? 0);
    if ($studentYear > 0) {
        $filterYear = $studentYear;
    } else {
        foreach ($years as $y) {
            if ((int)($y['is_current'] ?? 0) === 1) {
                $filterYear = (int)$y['id'];
                break;
            }
        }
        if ($filterYear <= 0 && !empty($years)) {
            $filterYear = (int)$years[0]['id'];
        }
    }
}

$terms = [];
if ($filterYear > 0) {
    $terms = $db->fetchAll(
        "SELECT id, term_name, is_current, sort_order, academic_year_id,
                vacation_date, next_term_begins
           FROM academic_terms
          WHERE tenant_id = ? AND deleted_at IS NULL
            AND academic_year_id = ?
          ORDER BY is_current DESC, sort_order ASC, id ASC",
        [$tenantId, $filterYear]
    );
}

// Default term: student's own first, then current-of-year, then first row.
if ($filterTerm <= 0) {
    $studentTerm = (int)($student['academic_term_id'] ?? 0);
    $studentTermInYear = false;
    if ($studentTerm > 0) {
        foreach ($terms as $t) {
            if ((int)$t['id'] === $studentTerm) {
                $studentTermInYear = true;
                break;
            }
        }
    }
    if ($studentTerm > 0 && $studentTermInYear) {
        $filterTerm = $studentTerm;
    } else {
        foreach ($terms as $t) {
            if ((int)($t['is_current'] ?? 0) === 1) {
                $filterTerm = (int)$t['id'];
                break;
            }
        }
        if ($filterTerm <= 0 && !empty($terms)) {
            $filterTerm = (int)$terms[0]['id'];
        }
    }
}

$selectedTerm = null;
foreach ($terms as $t) {
    if ((int)$t['id'] === $filterTerm) {
        $selectedTerm = $t;
        break;
    }
}

$schoolName = (string)($_SESSION['school_name'] ?? '');

// ============================================
// School logo read — DB-backed
// ============================================
$schoolLogo = '';

// 1. schools.logo_path
if ($schoolLogo === '' && $schoolId > 0) {
    try {
        $row = $db->fetchOne(
            "SELECT logo_path FROM schools
              WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
              LIMIT 1",
            [$schoolId, $tenantId]
        );
        if ($row && !empty($row['logo_path'])) {
            $schoolLogo = (string)$row['logo_path'];
        }
    } catch (Exception $e) {
    }
}

// 2. tenants.logo
if ($schoolLogo === '') {
    try {
        $row = $db->fetchOne(
            "SELECT logo FROM tenants
              WHERE id = ? AND deleted_at IS NULL
              LIMIT 1",
            [$tenantId]
        );
        if ($row && !empty($row['logo'])) {
            $schoolLogo = (string)$row['logo'];
        }
    } catch (Exception $e) {
    }
}

// 3. Conventional path
if ($schoolLogo === '' && $schoolId > 0) {
    $schoolLogo = '/uploads/schools/' . $schoolId . '/logo.png';
}

$transparentGif = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
$logoSrc = ($schoolLogo !== '') ? $schoolLogo : $transparentGif;

$pageTitle  = 'Terminal Report Card - ' . (($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));

if (!$selectedTerm) {
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo h_g($pageTitle); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                font-family: system-ui, sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .nf-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                max-width: 520px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            }

            .nf-card i {
                font-size: 48px;
                color: #4facfe;
                margin-bottom: 16px;
                display: block;
            }

            .nf-card h1 {
                font-size: 22px;
                font-weight: 700;
                color: #1a1a2e;
                margin-bottom: 8px;
            }

            .nf-card p {
                color: #6c757d;
                font-size: 14px;
                margin: 0 0 16px;
            }

            .nf-card a {
                color: #0d6efd;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>

    <body>
        <div class="nf-card">
            <i class="fas fa-calendar-times"></i>
            <h1>No term is available</h1>
            <p>There is no active academic term to report on for this student. Please try again later.</p>
            <a href="/platform/student/index.php?student_id=<?php echo (int)$studentId; ?>"><i class="fas fa-arrow-left me-1"></i> Back to overview</a>
        </div>
    </body>

    </html>
<?php
    exit;
}

$selectedYear = null;
foreach ($years as $y) {
    if ((int)$y['id'] === $filterYear) {
        $selectedYear = $y;
        break;
    }
}

// ============================================
// Load the per-subject results for the term
// ============================================
$allRows = $db->fetchAll(
    "SELECT r.id, r.subject_id, r.raw_score, r.final_score,
            r.grade_symbol, r.grade_label, r.remark, r.is_pass, r.status,
            s.subject_name, s.subject_code
       FROM results r
       LEFT JOIN subjects s ON s.id = r.subject_id AND s.deleted_at IS NULL
      WHERE r.tenant_id = ?
        AND r.student_id = ?
        AND r.academic_term_id = ?
        AND r.deleted_at IS NULL
        AND r.status IN ('calculated', 'published')
      ORDER BY s.subject_name ASC, r.id ASC",
    [$tenantId, $studentId, $filterTerm]
);

$subjectCap = 10;
$truncated = false;
$rows = $allRows;
if (count($rows) > $subjectCap) {
    $rows = array_slice($rows, 0, $subjectCap);
    $truncated = true;
}
$totalSubjectsAvailable = count($allRows);

$subjectCount = 0;
$passedCount = 0;
$failedCount = 0;
$scoreSum = 0.0;
$scoreCount = 0;
foreach ($rows as $r) {
    $subjectCount++;
    if ((int)$r['is_pass'] === 1) {
        $passedCount++;
    } else {
        $failedCount++;
    }
    $score = $r['final_score'] !== null
        ? (float)$r['final_score']
        : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
    if ($score !== null) {
        $scoreSum += $score;
        $scoreCount++;
    }
}
$termTotal   = $scoreCount > 0 ? round($scoreSum, 2) : null;
$termAverage = $scoreCount > 0 ? round($scoreSum / $scoreCount, 2) : null;

$classWeight = 60.0;
$examWeight  = 40.0;

$attendance = null;
try {
    $attendance = $db->fetchOne(
        "SELECT days_present, days_absent, days_late, days_excused, days_total, status
           FROM attendance_summaries
          WHERE tenant_id = ? AND student_id = ? AND academic_term_id = ?
            AND deleted_at IS NULL
          ORDER BY id DESC LIMIT 1",
        [$tenantId, $studentId, $filterTerm]
    );
} catch (Exception $e) {
    $attendance = null;
}

$phrases = PhraseGenerator::generate($db, $tenantId, $studentId, $filterTerm);

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
        $classTeacherName = trim(($ct['first_name'] ?? '') . ' ' . ($ct['middle_name'] ?? '') . ' ' . ($ct['last_name'] ?? ''));
        $classTeacherName = $classTeacherName !== '' ? preg_replace('/\s+/', ' ', $classTeacherName) : null;
    }
} catch (Exception $e) {
    $classTeacherName = null;
}

$headTeacherName = null;
$headTeacherSignature = null;
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
        $headTeacherName = trim(($ht['first_name'] ?? '') . ' ' . ($ht['middle_name'] ?? '') . ' ' . ($ht['last_name'] ?? ''));
        $headTeacherName = $headTeacherName !== '' ? preg_replace('/\s+/', ' ', $headTeacherName) : null;

        $sig = $db->fetchOne(
            "SELECT signature_data, mime_type
               FROM staff_signatures
              WHERE staff_id = ? AND is_primary = 1 AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 1",
            [(int)$ht['id']]
        );
        if ($sig && !empty($sig['signature_data'])) {
            $headTeacherSignature = 'data:' . ($sig['mime_type'] ?: 'image/png') . ';base64,' . $sig['signature_data'];
        }
    }
} catch (Exception $e) {
}

// ============================================
// [v2.8] Promotion outcome — class-anchor shape, mirroring the
//        school-side report card (view.php v1.6.0 / print-class.php
//        v1.6.0). The rule is looked up by the student's class_id.
//        The target comes from the class ladder. Terminal classes
//        render COMPLETED / GRADUATED. Every label carries a reason.
// ============================================
function computePromotionOutcome_g(?array $rule, array $ctx, ?string $ladderLabel, bool $isTerminal, ?string $levelFallback): array
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
    $att    = $ctx['attendance_percent'] ?? null;
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
    $mode   = (string)($rule['rule_mode'] ?? 'all_subjects');
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

// [v2.8] Term Three check by sort_order, not by name.
$termIsThree = ((int)($selectedTerm['sort_order'] ?? 0)) === 3;

// [v2.8] Class ladder read for the student's class.
$nextClassLabel    = null;
$currentIsTerminal = false;
try {
    $ladderRow = $db->fetchOne(
        "SELECT c.next_class_id, c.is_terminal, nc.class_name AS next_class_name
           FROM classes c
           LEFT JOIN classes nc ON nc.id = c.next_class_id AND nc.deleted_at IS NULL
          WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL
          LIMIT 1",
        [(int)$student['class_id'], $tenantId]
    );
    if ($ladderRow) {
        $currentIsTerminal = ((int)($ladderRow['is_terminal'] ?? 0)) === 1;
        if (!empty($ladderRow['next_class_name'])) {
            $nextClassLabel = (string)$ladderRow['next_class_name'];
        }
    }
} catch (Exception $e) {
}

// [v2.8] Rule lookup by class_id, class-anchor shape.
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
            (int)$student['class_id'],
            $filterYear,
            (int)$student['class_id'],
            $filterYear,
        ]
    );
    $promotionRule = is_array($prRow) ? $prRow : null;
} catch (Exception $e) {
    $promotionRule = null;
}

// [v2.8] B.E.C.E. mode detection — moved before use.
$isBeceMode = false;
try {
    $ruleMode = $db->fetchOne(
        "SELECT pr.rule_mode FROM classes c
           LEFT JOIN promotion_rules pr
             ON pr.tenant_id = c.tenant_id
            AND pr.class_id = c.id
            AND pr.status = 'active' AND pr.deleted_at IS NULL
          WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL LIMIT 1",
        [(int)$student['class_id'], $tenantId]
    );
    if ($ruleMode && (string)$ruleMode['rule_mode'] === 'bece_6') $isBeceMode = true;
} catch (Exception $e) {
}

// [v2.8] Compute the promotion outcome for Term Three.
$promoOutcome = ['label' => null, 'reason' => ''];
$promotedTo   = null;
if ($termIsThree) {
    $attendancePercent = null;
    if ($attendance && (int)($attendance['days_total'] ?? 0) > 0) {
        $attendancePercent = round(((int)$attendance['days_present'] / (int)$attendance['days_total']) * 100, 1);
    }
    $failedSubjectIds = [];
    foreach ($rows as $r) {
        if ((int)$r['is_pass'] !== 1) {
            $failedSubjectIds[] = (int)$r['subject_id'];
        }
    }
    $promoOutcome = computePromotionOutcome_g(
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
        null
    );
    $promotedTo = $promoOutcome['label'];
}

$classPosition = null;
$classSize = null;
$subjectPositions = [];

try {
    $classMates = $db->fetchAll(
        "SELECT s.id, s.student_number FROM students s
          WHERE s.tenant_id = ? AND s.class_id = ? AND s.deleted_at IS NULL",
        [$tenantId, (int)$student['class_id']]
    );
    $classSize = count($classMates);

    $averages = [];
    foreach ($classMates as $cm) {
        $cmRows = $db->fetchAll(
            "SELECT r.raw_score, r.final_score FROM results r
              WHERE r.tenant_id = ? AND r.student_id = ? AND r.academic_term_id = ?
                AND r.deleted_at IS NULL
                AND r.status IN ('calculated','published')",
            [$tenantId, (int)$cm['id'], $filterTerm]
        );
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
        $averages[] = [
            'student_id' => (int)$cm['id'],
            'student_number' => (string)($cm['student_number'] ?? ''),
            'average' => $n > 0 ? ($sum / $n) : null,
        ];
    }

    usort($averages, function ($a, $b) {
        if ($a['average'] === null && $b['average'] === null) return strcmp($a['student_number'], $b['student_number']);
        if ($a['average'] === null) return 1;
        if ($b['average'] === null) return -1;
        if ($a['average'] === $b['average']) return strcmp($a['student_number'], $b['student_number']);
        return $b['average'] <=> $a['average'];
    });

    foreach ($averages as $i => $a) {
        if ($a['student_id'] === $studentId && $a['average'] !== null) {
            $classPosition = $i + 1;
            break;
        }
    }

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
        $scored = [];
        foreach ($subjRows as $sr) {
            $v = $sr['final_score'] !== null
                ? (float)$sr['final_score']
                : ($sr['raw_score'] !== null ? (float)$sr['raw_score'] : null);
            if ($v !== null) {
                $scored[] = ['student_id' => (int)$sr['student_id'], 'score' => $v, 'num' => ''];
            }
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
            if ($s['student_id'] === $studentId) {
                $subjectPositions[$subjectId] = $i + 1;
                break;
            }
        }
    }
} catch (Exception $e) {
}

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

$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
        ($student['middle_name'] ?? '') . ' ' .
        ($student['last_name'] ?? '')
);
$fullName = $fullName !== '' ? preg_replace('/\s+/', ' ', $fullName) : ('Student #' . $studentId);
$studentNumber = (string)($student['student_number'] ?? '');
$dob        = (string)($student['date_of_birth'] ?? '');
$gender     = (string)($student['gender'] ?? '');
$className  = (string)($student['class_name'] ?? '');
$classCode  = (string)($student['class_code'] ?? '');
$termName   = (string)($selectedTerm['term_name'] ?? '');
$yearName   = $selectedYear ? (string)$selectedYear['year_name'] : '';
$vacationDate   = (string)($selectedTerm['vacation_date'] ?? '');
$nextTermBegins = (string)($selectedTerm['next_term_begins'] ?? '');
$photoUrl   = (string)($student['profile_photo_url'] ?? '');

$age = null;
if ($dob !== '') {
    try {
        $dobObj = new DateTime($dob);
        $nowObj = new DateTime('today');
        $age = $dobObj->diff($nowObj)->y;
    } catch (Exception $e) {
        $age = null;
    }
}

$initials = strtoupper(
    substr((string)($student['first_name'] ?? ''), 0, 1) .
        substr((string)($student['last_name']  ?? ''), 0, 1)
);
if ($initials === '') $initials = 'ST';

$overallBand = fallbackBand($termAverage);

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
    . ($_SERVER['REQUEST_URI'] ?? '/platform/student/reports.php');

$pageTitle = 'Terminal Report Card - ' . ($fullName !== '' ? $fullName : 'Student');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo h_g($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($schoolLogo !== ''): ?>
        <link rel="icon" href="<?php echo h_g($schoolLogo); ?>">
    <?php endif; ?>
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
            font-family: 'Inter', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.35;
            color: #1a1a2e;
        }

        .container-fluid {
            padding: 0;
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            background: #fff;
            padding: 10px 18px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-bar .brand img {
            max-height: 36px;
            max-width: 36px;
        }

        .top-bar .brand .brand-text h1 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        .top-bar .brand .brand-text p {
            font-size: 11px;
            margin: 0;
            color: #6c757d;
        }

        .top-bar .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .top-bar .actions .badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1.5px solid #e9ecef;
            color: #6c757d;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px;
            text-decoration: none;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
            color: #495057;
        }

        .btn-outline-primary {
            background: transparent;
            border: 1.5px solid #4facfe;
            color: #0d6efd;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px;
            text-decoration: none;
        }

        .btn-outline-primary:hover {
            background: #eef6ff;
        }

        .content-area {
            padding: 14px;
            max-width: 900px;
            margin: 0 auto;
        }

        .filter-strip {
            background: #fff;
            border-radius: 12px;
            padding: 10px 16px;
            margin-bottom: 14px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filter-strip .fs-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
            flex: 1;
            min-width: 160px;
        }

        .filter-strip .fs-group label {
            font-size: 11px;
            font-weight: 600;
            color: #1a1a2e;
        }

        .filter-strip .fs-group select {
            height: 34px;
            font-size: 12px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
            padding: 4px 10px;
            background: #fff;
            color: #1a1a2e;
        }

        .filter-strip .fs-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 0 0 auto;
        }

        .report-card {
            position: relative;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            padding: 16px 20px;
            margin-bottom: 14px;
            width: 100%;
            max-width: 210mm;
            margin-left: auto;
            margin-right: auto;
            color: #1a2a3a;
            overflow: hidden;
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

        .rc-table tfoot td {
            padding: 4px 6px;
            background: #eef3f7;
            font-weight: 800;
            border-top: 2px solid #1a2a3a;
            font-size: 11px;
        }

        .rc-summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1px solid #ced4da;
        }

        .rc-summary-table thead th {
            background: #1a2a3a;
            color: #fff;
            padding: 3px 6px;
            font-weight: 800;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }

        .rc-summary-table tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            text-align: center;
            font-weight: 700;
            color: #1a2a3a;
        }

        .rc-summary-table tbody td:last-child {
            border-right: none;
        }

        .rc-summary-table tbody tr:last-child td {
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

        .rc-grade-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1px solid #ced4da;
        }

        .rc-grade-table thead th {
            background: #1a2a3a;
            color: #fff;
            padding: 3px 6px;
            font-weight: 800;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }

        .rc-grade-table tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            text-align: center;
            font-weight: 700;
            color: #1a2a3a;
        }

        .rc-grade-table tbody td:last-child {
            border-right: none;
        }

        .rc-grade-table tbody tr:last-child td {
            border-bottom: none;
        }

        .rc-scale {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1px solid #ced4da;
        }

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

        .rc-scale tbody td {
            padding: 3px 6px;
            border: 1px solid #dee2e6;
            text-align: center;
            font-weight: 600;
            color: #1a2a3a;
            font-size: 10.5px;
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

        .rc-kv,
        .rc-sign-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            border: 1px solid #ced4da;
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

        .rc-footer .rc-footer-qr #qr {
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

        .rc-footer .rc-footer-qr #qr img,
        .rc-footer .rc-footer-qr #qr canvas {
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

            .top-bar,
            .filter-strip,
            .no-print {
                display: none !important;
            }

            .content-area {
                padding: 0 !important;
                max-width: 100% !important;
            }

            .report-card {
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }

            .rc-section-title {
                background: #1a2a3a !important;
                color: #fff !important;
                font-size: 9px !important;
                padding: 2px 6px !important;
                letter-spacing: 0.9px !important;
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

            .rc-header .rc-header-logo.rc-header-logo-empty {
                border: 1px dashed #b8c4d0 !important;
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

            .rc-identity {
                padding: 3px 5px !important;
            }

            .rc-id-row {
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

            .rc-att {
                padding: 3px 5px !important;
            }

            .rc-att-title {
                font-size: 8px !important;
                padding-bottom: 1px !important;
                margin-bottom: 1px !important;
            }

            .rc-att-row {
                padding: 0.5px 0 !important;
                font-size: 9px !important;
                line-height: 1.15 !important;
            }

            .rc-att-row .rc-att-lbl {
                font-size: 8.5px !important;
            }

            .rc-att-row .rc-att-val {
                font-size: 9px !important;
            }

            .rc-block .rc-block-body {
                padding: 3px 5px !important;
                font-size: 9px !important;
                line-height: 1.2 !important;
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

            .rc-table tfoot td {
                padding: 2px 4px !important;
                font-size: 9px !important;
            }

            .rc-summary-table {
                font-size: 9px !important;
            }

            .rc-summary-table thead th {
                background: #1a2a3a !important;
                color: #fff !important;
                padding: 2px 4px !important;
                font-size: 8px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-summary-table tbody td {
                padding: 2px 4px !important;
                font-size: 9px !important;
            }

            .rc-summary-table tbody td .rc-sum-label {
                font-size: 8px !important;
            }

            .rc-grade-table {
                font-size: 9px !important;
            }

            .rc-grade-table thead th {
                background: #1a2a3a !important;
                color: #fff !important;
                padding: 2px 4px !important;
                font-size: 8px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-grade-table tbody td {
                padding: 1.5px 4px !important;
                font-size: 9px !important;
            }

            .rc-scale {
                font-size: 9px !important;
            }

            .rc-scale thead th {
                background: #1a2a3a !important;
                color: #fff !important;
                padding: 2px 4px !important;
                font-size: 8px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-scale tbody td {
                padding: 2px 4px !important;
                font-size: 9px !important;
            }

            .rc-promoted {
                padding: 2px 6px !important;
                font-size: 9px !important;
                margin-bottom: 4px !important;
            }

            .rc-promoted .rc-promoted-lbl {
                font-size: 8px !important;
            }

            .rc-kv tbody tr td,
            .rc-sign-table tbody tr td {
                padding: 1.5px 5px !important;
                font-size: 9px !important;
            }

            .rc-kv tbody tr td.rc-kv-lbl,
            .rc-sign-table tbody tr td.rc-sign-lbl {
                width: 140px !important;
                font-size: 8px !important;
                padding: 1.5px 5px !important;
                background: #f5f8fb !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rc-kv tbody tr td.rc-kv-val,
            .rc-sign-table tbody tr td.rc-sign-val {
                font-size: 9px !important;
            }

            .rc-sign-table tbody tr td.rc-sign-val img {
                max-height: 22px !important;
                max-width: 100px !important;
            }

            .rc-footer {
                margin-top: 4px !important;
                padding-top: 3px !important;
                font-size: 7.5px !important;
                gap: 4px !important;
            }

            .rc-footer .rc-footer-qr #qr {
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
            .rc-sign-table {
                page-break-inside: avoid;
            }

            .rc-row2,
            .rc-row2-equal {
                page-break-inside: avoid;
            }

            .report-card>div[style*="height:8px"],
            .report-card>div[style*="height:6px"] {
                height: 2px !important;
            }

            .report-card.has-watermark::before {
                opacity: 0.06;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media (max-width: 700px) {
            .content-area {
                padding: 10px;
            }

            .report-card {
                padding: 12px;
            }

            .rc-header {
                grid-template-columns: 70px 1fr 70px;
            }

            .rc-header .rc-header-photo {
                width: 70px;
                height: 70px;
                font-size: 22px;
            }

            .rc-header .rc-header-logo {
                min-width: 70px;
                min-height: 70px;
            }

            .rc-header .rc-header-logo img {
                max-height: 70px;
                max-width: 70px;
            }

            .rc-header .rc-header-center h1 {
                font-size: 13px;
            }

            .rc-row2,
            .rc-row2-equal {
                grid-template-columns: 1fr;
            }

            .rc-kv tbody tr td.rc-kv-lbl,
            .rc-sign-table tbody tr td.rc-sign-lbl {
                width: 130px;
                font-size: 9px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="top-bar no-print">
            <div class="brand">
                <?php if ($schoolLogo !== ''): ?>
                    <img src="<?php echo h_g($schoolLogo); ?>" alt="" onerror="this.style.display='none'">
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo h_g($schoolName !== '' ? $schoolName : 'Student 360'); ?></h1>
                    <p>Student Portal</p>
                </div>
            </div>
            <div class="actions">
                <span class="badge"><i class="fas fa-user-shield me-1"></i> Guardian</span>
                <button type="button" class="btn-outline-primary" onclick="printReport()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <button type="button" class="btn-outline-primary" onclick="printReport()">
                    <i class="fas fa-file-pdf me-1"></i> Save as PDF
                </button>
                <a href="/platform/student/index.php?student_id=<?php echo (int)$studentId; ?>" class="btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Overview
                </a>
                <a href="/platform/guardian/index.php" class="btn-outline-secondary">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <a href="/platform/logout.php" class="btn-outline-secondary">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>

        <div class="content-area">
            <form method="GET" action="/platform/student/reports.php" class="filter-strip no-print">
                <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
                <div class="fs-group">
                    <label for="filterYear"><i class="fas fa-calendar-alt me-1"></i>Academic year</label>
                    <select name="year_id" id="filterYear" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                <?php echo h_g($y['year_name']); ?><?php if ((int)($y['is_current'] ?? 0) === 1): ?> ★<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fs-group">
                    <label for="filterTerm"><i class="fas fa-clock me-1"></i>Term</label>
                    <select name="term_id" id="filterTerm" onchange="this.form.submit()">
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                <?php echo h_g($t['term_name']); ?><?php if ((int)($t['is_current'] ?? 0) === 1): ?> ★<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fs-actions">
                    <a href="/platform/student/results.php?student_id=<?php echo (int)$studentId; ?>" class="btn-outline-secondary">
                        <i class="fas fa-poll me-1"></i> Open results
                    </a>
                </div>
            </form>

            <div class="report-card <?php echo $schoolLogo !== '' ? 'has-watermark' : ''; ?>">

                <div class="rc-header">
                    <div class="rc-header-photo">
                        <?php if ($photoUrl !== ''): ?>
                            <img src="<?php echo h_g($photoUrl); ?>" alt="Student photo" onerror="this.style.display='none';">
                        <?php else: ?>
                            <?php echo h_g($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="rc-header-center">
                        <h1><?php echo h_g($schoolName !== '' ? $schoolName : 'Student 360'); ?></h1>
                        <p class="rc-addr"><?php echo h_g('P.O. Box —'); ?></p>
                        <p class="rc-addr"><?php echo h_g('—'); ?></p>
                        <div class="rc-title-line"><?php echo h_g(($termName !== '' ? strtoupper($termName) : 'TERM') . ' STUDENT\'S PERFORMANCE REPORT'); ?></div>
                        <div class="rc-title-line-2"><?php echo h_g($yearName !== '' ? $yearName . ' ACADEMIC YEAR' : 'ACADEMIC YEAR'); ?></div>
                    </div>
                    <div class="rc-header-logo<?php echo $schoolLogo === '' ? ' rc-header-logo-empty' : ''; ?>">
                        <img src="<?php echo h_g($logoSrc); ?>"
                            alt="School crest"
                            onerror="this.style.visibility='hidden'; this.parentElement.classList.add('rc-header-logo-empty');">
                        <span class="rc-header-logo-label">LOGO</span>
                    </div>
                </div>

                <div class="rc-row2">
                    <div class="rc-identity">
                        <div class="rc-id-row"><span class="rc-id-lbl">Name</span><span class="rc-id-val"><?php echo h_g($fullName); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">D.O.B.</span><span class="rc-id-val"><?php echo h_g($dob !== '' ? fmtDate($dob) : '—'); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Gender</span><span class="rc-id-val"><?php echo h_g($gender !== '' ? strtoupper(substr($gender, 0, 1)) : '—'); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Age</span><span class="rc-id-val"><?php echo $age !== null ? h_g((string)$age . ' YEARS') : '—'; ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Code</span><span class="rc-id-val"><?php echo h_g($studentNumber !== '' ? $studentNumber : '—'); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Class</span><span class="rc-id-val"><?php echo h_g($className !== '' ? $className : '—'); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Number on Roll</span><span class="rc-id-val"><?php echo $classSize !== null ? h_g((string)$classSize) : '—'; ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Total Score</span><span class="rc-id-val"><?php echo $isBeceMode && $beceAggregate !== null ? h_g((string)$beceAggregate) : h_g(fmtNum($termTotal, 0)); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Out of</span><span class="rc-id-val"><?php echo h_g((string)($scoreCount * 100)); ?></span></div>
                        <div class="rc-id-row"><span class="rc-id-lbl">Grade</span><span class="rc-id-val"><?php echo $overallBand['letter'] !== null ? h_g($overallBand['letter']) : '—'; ?></span></div>
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
                            <div class="rc-att-row"><span class="rc-att-lbl">No. of times opened</span><span class="rc-att-val"><?php echo h_g((string)$tOpen); ?></span></div>
                            <div class="rc-att-row"><span class="rc-att-lbl">No. of times present</span><span class="rc-att-val"><?php echo h_g((string)$tPres); ?><?php if ($pPres !== null): ?> · <?php echo h_g((string)$pPres); ?>%<?php endif; ?></span></div>
                            <div class="rc-att-row"><span class="rc-att-lbl">No. of times absent</span><span class="rc-att-val"><?php echo h_g((string)$tAbs); ?><?php if ($pAbs !== null): ?> · <?php echo h_g((string)$pAbs); ?>%<?php endif; ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rc-row2-equal">
                    <div class="rc-block">
                        <div class="rc-section-title">Profile</div>
                        <div class="rc-block-body <?php echo $phrases['profile'] === null ? 'rc-muted' : ''; ?>">
                            <?php echo $phrases['profile'] !== null ? h_g($phrases['profile']) : '—'; ?>
                        </div>
                    </div>
                    <div class="rc-block">
                        <div class="rc-section-title">Position</div>
                        <div class="rc-block-body <?php echo ($classPosition === null || $classSize === null) ? 'rc-muted' : ''; ?>">
                            <?php if ($classPosition !== null && $classSize !== null): ?>
                                <?php echo h_g((string)$classPosition . ordinal($classPosition)); ?> out of <?php echo h_g((string)$classSize); ?> students
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
                                $total = $r['final_score'] !== null ? (float)$r['final_score'] : ($r['raw_score'] !== null ? (float)$r['raw_score'] : null);
                                $classScore = $total !== null ? round($total * ($classWeight / 100.0), 2) : null;
                                $examScore  = $total !== null ? round($total * ($examWeight  / 100.0), 2) : null;
                                $symbol     = (string)($r['grade_symbol'] ?? '');
                                $remark     = (string)($r['remark'] ?? '');
                                $subName    = (string)($r['subject_name'] ?? '');
                                if ($subName === '') $subName = 'Subject #' . (int)$r['subject_id'];
                                $pos = isset($subjectPositions[(int)$r['subject_id']]) ? (int)$subjectPositions[(int)$r['subject_id']] : null;
                            ?>
                                <tr>
                                    <td><strong><?php echo h_g(strtoupper($subName)); ?></strong></td>
                                    <td style="text-align:center;"><?php echo h_g(fmtNum($classScore, 1)); ?></td>
                                    <td style="text-align:center;"><?php echo h_g(fmtNum($examScore, 1)); ?></td>
                                    <td style="text-align:center;font-weight:700;"><?php echo h_g(fmtNum($total, 1)); ?></td>
                                    <td style="text-align:center;font-weight:700;"><?php echo h_g($symbol !== '' ? strtoupper($symbol) : '—'); ?></td>
                                    <td style="text-align:center;"><?php echo $pos !== null ? h_g((string)$pos . ordinal($pos)) : '—'; ?></td>
                                    <td><?php echo h_g($remark !== '' ? strtoupper($remark) : '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($truncated): ?>
                        <div style="font-size:10px;color:#6c757d;margin-top:4px;text-align:right;"><i class="fas fa-info-circle me-1"></i> Showing <?php echo h_g((string)$subjectCap); ?> of <?php echo h_g((string)$totalSubjectsAvailable); ?> subjects.</div>
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
                                    <td><span class="rc-sum-label">Total</span><?php if ($isBeceMode && $beceAggregate !== null): ?><?php echo h_g((string)$beceAggregate); ?> / 54<?php else: ?><?php echo h_g(fmtNum($termTotal, 0)); ?><?php endif; ?></td>
                                    <td><span class="rc-sum-label">Avg</span><?php echo h_g(fmtNum($termAverage, 0)); ?></td>
                                    <td><span class="rc-sum-label">Grade</span><?php echo $overallBand['letter'] !== null ? h_g($overallBand['letter'] . ' / ' . ($overallBand['word'] ?? '')) : '—'; ?></td>
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
                                        <td><?php echo h_g($ltr); ?></td>
                                        <td><?php echo h_g((string)$gradeCounts[$ltr]); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="2" style="background:#eef3f7;font-size:10px;text-transform:uppercase;letter-spacing:0.4px;color:#4a6b8a;">No. of Subjects: <strong style="color:#1a2a3a;"><?php echo h_g((string)$subjectCount); ?></strong></td>
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
                $promoLabel  = $promoOutcome['label'];
                $promoReason = $promoOutcome['reason'];
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
                        <?php echo h_g($promoLabel); ?>
                        <?php if (!empty($promoReason)): ?>
                            <span class="rc-promoted-reason"><?php echo h_g($promoReason); ?></span>
                        <?php endif; ?>
                    <?php elseif ($termIsThree): ?>
                        — (no active promotion rule for this class)
                    <?php else: ?>
                        — (determined at the end of Term Three)
                    <?php endif; ?>
                </div>

                <table class="rc-kv">
                    <tbody>
                        <tr>
                            <td class="rc-kv-lbl">Conduct</td>
                            <td class="rc-kv-val <?php echo $phrases['conduct'] === null ? 'rc-muted' : ''; ?>"><?php echo $phrases['conduct'] !== null ? h_g($phrases['conduct']) : '—'; ?></td>
                        </tr>
                        <tr>
                            <td class="rc-kv-lbl">Attitude</td>
                            <td class="rc-kv-val <?php echo $phrases['attitude'] === null ? 'rc-muted' : ''; ?>"><?php echo $phrases['attitude'] !== null ? h_g($phrases['attitude']) : '—'; ?></td>
                        </tr>
                        <tr>
                            <td class="rc-kv-lbl">Teacher's Remark</td>
                            <td class="rc-kv-val <?php echo $phrases['teacher_remark'] === null ? 'rc-muted' : ''; ?>"><?php echo $phrases['teacher_remark'] !== null ? h_g($phrases['teacher_remark']) : '—'; ?></td>
                        </tr>
                        <tr>
                            <td class="rc-kv-lbl">Head Teacher's Remarks</td>
                            <td class="rc-kv-val <?php echo $phrases['head_teacher_remark'] === null ? 'rc-muted' : ''; ?>"><?php echo $phrases['head_teacher_remark'] !== null ? h_g($phrases['head_teacher_remark']) : '—'; ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="height:6px;"></div>

                <table class="rc-sign-table">
                    <tbody>
                        <tr>
                            <td class="rc-sign-lbl">Class Teacher's Name</td>
                            <td class="rc-sign-val"><?php echo h_g($classTeacherName !== null ? $classTeacherName : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Head Teacher's Name</td>
                            <td class="rc-sign-val"><?php echo h_g($headTeacherName !== null ? $headTeacherName : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Head Teacher's Signature</td>
                            <td class="rc-sign-val"><?php if ($headTeacherSignature !== null): ?><img src="<?php echo h_g($headTeacherSignature); ?>" alt="Head teacher signature"><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="height:6px;"></div>

                <table class="rc-sign-table">
                    <tbody>
                        <tr>
                            <td class="rc-sign-lbl">Year</td>
                            <td class="rc-sign-val"><?php echo h_g($yearName !== '' ? $yearName : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Term</td>
                            <td class="rc-sign-val"><?php echo h_g($termName !== '' ? strtoupper($termName) : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Vacation Date</td>
                            <td class="rc-sign-val"><?php echo h_g($vacationDate !== '' ? strtoupper(fmtDate($vacationDate)) : '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="rc-sign-lbl">Next Term Begins</td>
                            <td class="rc-sign-val"><?php echo h_g($nextTermBegins !== '' ? strtoupper(fmtDate($nextTermBegins)) : '—'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="rc-footer">
                    <div><i class="fas fa-info-circle me-1"></i> This report is generated from the school's records at the moment of printing.</div>
                    <div class="rc-footer-qr">
                        <div id="qr" aria-label="Report card QR code"></div>
                        <div class="rc-qr-lbl">Scan to verify</div>
                    </div>
                    <div>Printed <?php echo h_g(date('M j, Y')); ?></div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        function printReport() {
            window.print();
        }
        (function() {
            var el = document.getElementById('qr');
            if (!el || typeof QRCode === 'undefined') return;
            new QRCode(el, {
                text: <?php echo json_encode($qrPayload); ?>,
                width: 60,
                height: 60,
                colorDark: '#1a2a3a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        })();
    </script>
</body>

</html>