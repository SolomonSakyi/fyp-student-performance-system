<?php

/**
 * Tenant Reports — school-side Report hub.
 *
 * @package Student 360
 * @subpackage Platform\Tenant\Reports
 * @version 1.2
 * @filepath public/platform/tenant/reports/index.php
 *
 * v1.2 change (2026-10-07) [JS SYNTAX FIX]:
 *   - Replaced `const el;` with `let el;` inside loadUserInfo(). The
 *     former is invalid JavaScript and breaks the whole script block.
 *     No other line changes.
 *
 * v1.1 change (2026-10-07) [REPORTS]:
 *   - Top-bar carries Report settings and Print class (conditional).
 *   - Roster card header carries Print class beside Bulk release.
 *   - Recent releases table: the ID column links to release.php.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires a tenant session (require_tenant()).
 *   - Lists active enrolments for a class offering, filtered by
 *     academic year, term, and class offering.
 *   - Provides per-student actions: View (link to view.php), Release
 *     (individual, POST).
 *   - Provides a bulk release form covering the whole filtered class.
 *   - Provides a bulk print link to print-class.php.
 *   - Records each release in report_releases and each per-student
 *     outcome in report_release_students.
 *   - Writes notifications rows for each guardian recipient whose
 *     opt-in gate permits the selected channel.
 *   - Writes audit_logs rows for release actions.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not render the report card. That is view.php.
 *   - It does not send email or SMS. It records the intent by writing
 *     notifications rows. Actual delivery is deferred with SMTP and
 *     SMS unblocked separately.
 *   - It does not generate server-side PDF.
 *
 * LOCKED DECISIONS:
 *   - Tenant scoping on every query: tenant_id = ?.
 *   - Soft deletes: deleted_at IS NULL.
 *   - UUIDs: uuidv4() on every INSERT.
 *   - Role gate: require_tenant() only.
 *   - Channels: channels_used varchar(50), comma-separated subset of
 *     inapp,email,sms. Default 'inapp'.
 *   - Recipients: student_guardians pivot rows. Per-student gate flags
 *     read from students.guardian_portal_access / guardian_sms /
 *     guardian_email_notify.
 *   - Notification user_id resolution: match guardians.email against
 *     platform_users.email, tenant-scoped. On no match, skip the row.
 *   - Audit actions: report.released, report.sent.
 */

$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/bootstrap.php';
require_tenant();

$tenantId     = current_tenant_id();
$schoolId     = (int)($_SESSION['school_id'] ?? 0);
$userId       = current_user_id();
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = is_super_admin();

$pageTitle   = 'Reports - Student 360 Platform';
$currentPage = 'reports';

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

function writeAudit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->insert(
            "INSERT INTO audit_logs (user_id, tenant_id, school_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                (int)($_SESSION['school_id'] ?? 0) ?: null,
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

function normaliseChannels(array $in): string
{
    $allowed = ['inapp', 'email', 'sms'];
    $out = [];
    foreach ($allowed as $c) {
        if (in_array($c, $in, true)) $out[] = $c;
    }
    if (empty($out)) return 'inapp';
    return implode(',', $out);
}

function loadClassRoster($db, int $tenantId, int $offeringId): array
{
    if ($offeringId <= 0) return [];
    return $db->fetchAll(
        "SELECT e.id AS enrollment_id, e.student_id, e.roll_number,
                s.student_number,
                s.first_name, s.middle_name, s.last_name, s.preferred_name
           FROM enrollments e
           JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
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
}

function loadRecipients($db, int $tenantId, int $studentId): array
{
    $st = $db->fetchOne(
        "SELECT guardian_portal_access, guardian_sms, guardian_email_notify
           FROM students
          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$studentId, $tenantId]
    );
    $gates = [
        'inapp' => $st ? (int)$st['guardian_portal_access'] === 1 : false,
        'sms'   => $st ? (int)$st['guardian_sms']           === 1 : false,
        'email' => $st ? (int)$st['guardian_email_notify']  === 1 : false,
    ];

    $rows = $db->fetchAll(
        "SELECT sg.guardian_id, sg.relationship, sg.is_primary,
                g.first_name, g.last_name, g.email
           FROM student_guardians sg
           JOIN guardians g ON g.id = sg.guardian_id AND g.deleted_at IS NULL
          WHERE sg.tenant_id = ? AND sg.student_id = ?
            AND sg.deleted_at IS NULL
          ORDER BY sg.is_primary DESC, sg.id ASC",
        [$tenantId, $studentId]
    );

    $out = [];
    foreach ($rows as $r) {
        $r['gates'] = $gates;
        $r['platform_user_id'] = null;
        if (!empty($r['email'])) {
            try {
                $pu = $db->fetchOne(
                    "SELECT id FROM platform_users
                      WHERE email = ? AND tenant_id = ? AND deleted_at IS NULL
                      LIMIT 1",
                    [$r['email'], $tenantId]
                );
                if ($pu) $r['platform_user_id'] = (int)$pu['id'];
            } catch (Exception $e) {
            }
        }
        $out[] = $r;
    }
    return $out;
}

function offeringLabel(array $o): string
{
    $parts = [];
    if (!empty($o['class_name'])) $parts[] = (string)$o['class_name'];
    if (!empty($o['stream_name'])) $parts[] = (string)$o['stream_name'];
    if (!empty($o['year_name'])) $parts[] = (string)$o['year_name'];
    return implode(' · ', $parts);
}

// ============================================
// POST ACTIONS
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $formData = $_POST;

    try {
        // ---------- RELEASE INDIVIDUAL ----------
        if ($action === 'release_individual') {
            $studentId      = (int)($_POST['student_id'] ?? 0);
            $offeringId     = (int)($_POST['class_offering_id'] ?? 0);
            $yearId         = (int)($_POST['academic_year_id'] ?? 0);
            $termId         = (int)($_POST['academic_term_id'] ?? 0);
            $classId        = (int)($_POST['class_id'] ?? 0);
            $reportKind     = (string)($_POST['report_kind'] ?? 'term_report');
            $channelsIn     = is_array($_POST['channels'] ?? null) ? $_POST['channels'] : ['inapp'];
            $channelsUsed   = normaliseChannels($channelsIn);
            $notes          = trim((string)($_POST['notes'] ?? ''));

            if ($studentId <= 0) throw new Exception('Student is required.');
            if ($yearId <= 0 || $termId <= 0 || $classId <= 0) throw new Exception('Year, term and class are required.');
            if (!in_array($reportKind, ['term_report', 'transcript', 'all'], true)) {
                $reportKind = 'term_report';
            }

            $st = $db->fetchOne(
                "SELECT id FROM students
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$studentId, $tenantId]
            );
            if (!$st) throw new Exception('Student not found.');

            $co = null;
            if ($offeringId > 0) {
                $co = $db->fetchOne(
                    "SELECT id, class_id FROM class_offerings
                      WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$offeringId, $tenantId]
                );
                if (!$co) throw new Exception('Class offering not found.');
                if ($classId <= 0) $classId = (int)$co['class_id'];
            }

            $db->beginTransaction();

            $releaseId = (int)$db->insert(
                "INSERT INTO report_releases
                    (uuid, tenant_id, school_id, academic_year_id, academic_term_id,
                     class_id, class_offering_id, report_kind, channels_used,
                     status, total_students, notes, released_by, released_at,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'processing', 1, ?, ?, NOW(), ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $schoolId ?: null,
                    $yearId,
                    $termId,
                    $classId,
                    $offeringId > 0 ? $offeringId : null,
                    $reportKind,
                    $channelsUsed,
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                    $userId ?: null,
                ]
            );

            $releaseStudentId = (int)$db->insert(
                "INSERT INTO report_release_students
                    (uuid, tenant_id, release_id, student_id, status,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())",
                [uuidv4(), $tenantId, $releaseId, $studentId]
            );

            $recipients = loadRecipients($db, $tenantId, $studentId);
            $sent       = 0;
            $skipped    = 0;
            $failed     = 0;
            $recipientsCount = count($recipients);

            foreach ($recipients as $r) {
                foreach (explode(',', $channelsUsed) as $ch) {
                    if (!($r['gates'][$ch] ?? false)) continue;
                    if ($ch !== 'inapp' && empty($r['platform_user_id'])) {
                        $skipped++;
                        continue;
                    }
                    $targetUserId = $r['platform_user_id'];
                    if ($ch === 'inapp' && $targetUserId === null) {
                        $skipped++;
                        continue;
                    }
                    try {
                        $db->insert(
                            "INSERT INTO notifications
                                (uuid, user_id, tenant_id, type, priority, channel,
                                 recipient_role, title, message, link,
                                 related_type, related_id, created_by,
                                 created_at, updated_at)
                             VALUES (?, ?, ?, 'report_release', 'normal', ?,
                                     'guardian', ?, ?, ?, 'report_release', ?, ?, NOW(), NOW())",
                            [
                                null,
                                (int)$targetUserId,
                                $tenantId,
                                $ch,
                                'Term report available',
                                'A term report has been released for your ward.',
                                '/platform/student/reports.php?student_id=' . $studentId
                                    . '&term_id=' . $termId,
                                $releaseId,
                                $userId ?: null,
                            ]
                        );
                        $sent++;
                    } catch (Exception $e) {
                        error_log('report release notification insert failed: ' . $e->getMessage());
                        $failed++;
                    }
                }
            }

            $overallStatus = 'completed';
            if ($sent === 0 && $skipped > 0) $overallStatus = 'skipped';
            elseif ($failed > 0 && $sent > 0)  $overallStatus = 'partial';
            elseif ($failed > 0 && $sent === 0) $overallStatus = 'failed';

            $db->execute(
                "UPDATE report_release_students
                    SET status = ?, skipped_reason = ?, recipients_count = ?,
                        notifications_written = ?, sent_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [
                    $overallStatus === 'completed' ? 'sent'
                        : ($overallStatus === 'partial' ? 'partial'
                            : ($overallStatus === 'skipped' ? 'skipped' : 'failed')),
                    $recipientsCount === 0 ? 'No linked guardians' : null,
                    $recipientsCount,
                    $sent,
                    $releaseStudentId,
                    $tenantId,
                ]
            );

            $db->execute(
                "UPDATE report_releases
                    SET status = ?, total_sent = ?, total_skipped = ?,
                        total_failed = ?, completed_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$overallStatus, $sent, $skipped, $failed, $releaseId, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'report.released',
                'report_releases',
                $releaseId,
                [
                    'student_id'    => $studentId,
                    'year_id'       => $yearId,
                    'term_id'       => $termId,
                    'class_id'      => $classId,
                    'offering_id'   => $offeringId,
                    'report_kind'   => $reportKind,
                    'channels'      => $channelsUsed,
                    'recipients'    => $recipientsCount,
                    'notifications' => $sent,
                    'skipped'       => $skipped,
                    'failed'        => $failed,
                ]
            );

            $db->commit();

            $_SESSION['success'] = sprintf(
                'Report released to %d guardian notification(s). %d skipped, %d failed.',
                $sent,
                $skipped,
                $failed
            );
            header('Location: /platform/tenant/reports/index.php?year_id=' . $yearId
                . '&term_id=' . $termId
                . '&offering_id=' . $offeringId);
            exit;
        }

        // ---------- RELEASE BULK ----------
        if ($action === 'release_bulk') {
            $offeringId   = (int)($_POST['class_offering_id'] ?? 0);
            $yearId       = (int)($_POST['academic_year_id'] ?? 0);
            $termId       = (int)($_POST['academic_term_id'] ?? 0);
            $classId      = (int)($_POST['class_id'] ?? 0);
            $reportKind   = (string)($_POST['report_kind'] ?? 'term_report');
            $channelsIn   = is_array($_POST['channels'] ?? null) ? $_POST['channels'] : ['inapp'];
            $channelsUsed = normaliseChannels($channelsIn);
            $notes        = trim((string)($_POST['notes'] ?? ''));

            if ($offeringId <= 0) throw new Exception('Class offering is required.');
            if ($yearId <= 0 || $termId <= 0) throw new Exception('Year and term are required.');

            $co = $db->fetchOne(
                "SELECT id, class_id FROM class_offerings
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$offeringId, $tenantId]
            );
            if (!$co) throw new Exception('Class offering not found.');
            if ($classId <= 0) $classId = (int)$co['class_id'];

            $roster = loadClassRoster($db, $tenantId, $offeringId);
            if (empty($roster)) throw new Exception('No active enrolments in this offering.');

            $db->beginTransaction();

            $releaseId = (int)$db->insert(
                "INSERT INTO report_releases
                    (uuid, tenant_id, school_id, academic_year_id, academic_term_id,
                     class_id, class_offering_id, report_kind, channels_used,
                     status, total_students, notes, released_by, released_at,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'processing', ?, ?, ?, NOW(), ?, NOW(), NOW())",
                [
                    uuidv4(),
                    $tenantId,
                    $schoolId ?: null,
                    $yearId,
                    $termId,
                    $classId,
                    $offeringId,
                    $reportKind,
                    $channelsUsed,
                    count($roster),
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                    $userId ?: null,
                ]
            );

            $sent = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($roster as $st) {
                $sid = (int)$st['student_id'];
                $releaseStudentId = (int)$db->insert(
                    "INSERT INTO report_release_students
                        (uuid, tenant_id, release_id, student_id, status,
                         created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())",
                    [uuidv4(), $tenantId, $releaseId, $sid]
                );

                $recipients = loadRecipients($db, $tenantId, $sid);
                $studentSent = 0;
                $studentSkipped = 0;
                $studentFailed = 0;

                foreach ($recipients as $r) {
                    foreach (explode(',', $channelsUsed) as $ch) {
                        if (!($r['gates'][$ch] ?? false)) {
                            $studentSkipped++;
                            continue;
                        }
                        if (empty($r['platform_user_id'])) {
                            $studentSkipped++;
                            continue;
                        }
                        try {
                            $db->insert(
                                "INSERT INTO notifications
                                    (uuid, user_id, tenant_id, type, priority, channel,
                                     recipient_role, title, message, link,
                                     related_type, related_id, created_by,
                                     created_at, updated_at)
                                 VALUES (?, ?, ?, 'report_release', 'normal', ?,
                                         'guardian', ?, ?, ?, 'report_release', ?, ?, NOW(), NOW())",
                                [
                                    null,
                                    (int)$r['platform_user_id'],
                                    $tenantId,
                                    $ch,
                                    'Term report available',
                                    'A term report has been released for your ward.',
                                    '/platform/student/reports.php?student_id=' . $sid
                                        . '&term_id=' . $termId,
                                    $releaseId,
                                    $userId ?: null,
                                ]
                            );
                            $studentSent++;
                        } catch (Exception $e) {
                            error_log('bulk report release notification insert failed: ' . $e->getMessage());
                            $studentFailed++;
                        }
                    }
                }

                $stStatus = 'sent';
                if ($studentSent === 0 && $studentSkipped > 0) $stStatus = 'skipped';
                elseif ($studentFailed > 0 && $studentSent > 0) $stStatus = 'partial';
                elseif ($studentFailed > 0 && $studentSent === 0) $stStatus = 'failed';

                $db->execute(
                    "UPDATE report_release_students
                        SET status = ?, recipients_count = ?, notifications_written = ?,
                            skipped_reason = ?, sent_at = NOW(), updated_at = NOW()
                      WHERE id = ? AND tenant_id = ?",
                    [
                        $stStatus,
                        count($recipients),
                        $studentSent,
                        count($recipients) === 0 ? 'No linked guardians' : null,
                        $releaseStudentId,
                        $tenantId,
                    ]
                );

                $sent    += $studentSent;
                $skipped += $studentSkipped;
                $failed  += $studentFailed;
            }

            $overallStatus = 'completed';
            if ($failed > 0 && $sent > 0)      $overallStatus = 'partial';
            elseif ($failed > 0 && $sent === 0) $overallStatus = 'failed';
            elseif ($sent === 0 && $skipped > 0) $overallStatus = 'partial';

            $db->execute(
                "UPDATE report_releases
                    SET status = ?, total_sent = ?, total_skipped = ?,
                        total_failed = ?, completed_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND tenant_id = ?",
                [$overallStatus, $sent, $skipped, $failed, $releaseId, $tenantId]
            );

            writeAudit(
                $db,
                $tenantId,
                $userId,
                'report.released',
                'report_releases',
                $releaseId,
                [
                    'scope'         => 'bulk',
                    'offering_id'   => $offeringId,
                    'year_id'       => $yearId,
                    'term_id'       => $termId,
                    'class_id'      => $classId,
                    'report_kind'   => $reportKind,
                    'channels'      => $channelsUsed,
                    'students'      => count($roster),
                    'notifications' => $sent,
                    'skipped'       => $skipped,
                    'failed'        => $failed,
                ]
            );

            $db->commit();

            $_SESSION['success'] = sprintf(
                'Bulk release complete. %d notification(s) written across %d student(s). %d skipped, %d failed.',
                $sent,
                count($roster),
                $skipped,
                $failed
            );
            header('Location: /platform/tenant/reports/index.php?year_id=' . $yearId
                . '&term_id=' . $termId
                . '&offering_id=' . $offeringId);
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Tenant reports action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/reports/index.php');
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
$years = $db->fetchAll(
    "SELECT id, year_name, is_current FROM academic_years
      WHERE tenant_id = ? AND deleted_at IS NULL
      ORDER BY is_current DESC, start_date DESC, id DESC",
    [$tenantId]
);

$filterYear   = (int)($_GET['year_id'] ?? 0);
$filterTerm   = (int)($_GET['term_id'] ?? 0);
$filterClass  = (int)($_GET['class_id'] ?? 0);
$filterOffer  = (int)($_GET['offering_id'] ?? 0);

if ($filterYear <= 0) {
    foreach ($years as $y) {
        if ((int)$y['is_current'] === 1) {
            $filterYear = (int)$y['id'];
            break;
        }
    }
    if ($filterYear <= 0 && !empty($years)) $filterYear = (int)$years[0]['id'];
}

$terms = [];
if ($filterYear > 0) {
    $terms = $db->fetchAll(
        "SELECT id, term_name, is_current FROM academic_terms
          WHERE tenant_id = ? AND academic_year_id = ? AND deleted_at IS NULL
          ORDER BY is_current DESC, sort_order ASC, id ASC",
        [$tenantId, $filterYear]
    );
}
if ($filterTerm <= 0) {
    foreach ($terms as $t) {
        if ((int)$t['is_current'] === 1) {
            $filterTerm = (int)$t['id'];
            break;
        }
    }
    if ($filterTerm <= 0 && !empty($terms)) $filterTerm = (int)$terms[0]['id'];
}

$offerings = $db->fetchAll(
    "SELECT co.id, co.class_id, co.academic_year_id, co.stream_id,
            c.class_name, c.class_code,
            s.stream_name,
            ay.year_name
       FROM class_offerings co
       JOIN classes c ON c.id = co.class_id AND c.deleted_at IS NULL
       LEFT JOIN streams s ON s.id = co.stream_id AND s.deleted_at IS NULL
       LEFT JOIN academic_years ay ON ay.id = co.academic_year_id
      WHERE co.tenant_id = ?
        AND co.academic_year_id = ?
        AND co.deleted_at IS NULL
      ORDER BY c.class_name ASC, s.stream_name ASC, co.id ASC",
    [$tenantId, $filterYear > 0 ? $filterYear : 0]
);

$selectedOffering = null;
foreach ($offerings as $o) {
    if ((int)$o['id'] === $filterOffer) {
        $selectedOffering = $o;
        break;
    }
}
if (!$selectedOffering && !empty($offerings)) {
    $selectedOffering = $offerings[0];
    $filterOffer = (int)$selectedOffering['id'];
}

$roster = [];
if ($selectedOffering) {
    $roster = loadClassRoster($db, $tenantId, $filterOffer);
}

$history = $db->fetchAll(
    "SELECT rr.id, rr.uuid, rr.report_kind, rr.channels_used, rr.status,
            rr.total_students, rr.total_sent, rr.total_skipped, rr.total_failed,
            rr.released_at, rr.completed_at,
            c.class_name, s.stream_name, ay.year_name, at.term_name
       FROM report_releases rr
       LEFT JOIN classes c ON c.id = rr.class_id
       LEFT JOIN streams s ON s.id = (
            SELECT co2.stream_id FROM class_offerings co2
             WHERE co2.id = rr.class_offering_id LIMIT 1
       )
       LEFT JOIN academic_years ay ON ay.id = rr.academic_year_id
       LEFT JOIN academic_terms at ON at.id = rr.academic_term_id
      WHERE rr.tenant_id = ? AND rr.deleted_at IS NULL
      ORDER BY rr.released_at DESC, rr.id DESC
      LIMIT 30",
    [$tenantId]
);

$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

function fmtRosterName(array $st): string
{
    $pref = trim((string)($st['preferred_name'] ?? ''));
    if ($pref !== '') return $pref;
    $full = trim(
        ($st['first_name'] ?? '') . ' ' .
            ($st['middle_name'] ?? '') . ' ' .
            ($st['last_name'] ?? '')
    );
    $full = preg_replace('/\s+/', ' ', $full);
    return $full !== '' ? $full : ('Student #' . (int)($st['student_id'] ?? 0));
}

function statusPill(string $s): string
{
    $map = [
        'pending'    => 'gray',
        'processing' => 'blue',
        'completed'  => 'green',
        'partial'    => 'orange',
        'failed'     => 'red',
        'sent'       => 'green',
        'skipped'    => 'gray',
    ];
    return $map[$s] ?? 'gray';
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
            align-items: flex-end;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .filters-bar .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 160px;
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
            padding: 4px 10px;
        }

        .card-custom {
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
            font-size: 14px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table.data-table thead th {
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

        table.data-table tbody td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        table.data-table tbody tr:last-child td {
            border-bottom: none;
        }

        table.data-table tbody tr:hover {
            background: #fafbfc;
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

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .channel-chips label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8f9fa;
            border: 1.5px solid #e9ecef;
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }

        .channel-chips label:hover {
            border-color: #4facfe;
        }

        .channel-chips input[type="checkbox"] {
            margin: 0;
        }

        .channel-chips input:checked+span {
            color: #0d6efd;
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
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-file-alt me-2"></i>Reports</h1>
                        <p>View, print and release student reports to guardians</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/reports/settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-cog me-2"></i> Report settings
                        </a>
                        <?php if ($selectedOffering): ?>
                            <a href="/platform/tenant/reports/print-class.php?year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>&offering_id=<?php echo (int)$filterOffer; ?>"
                                target="_blank" rel="noopener"
                                class="btn btn-outline-secondary">
                                <i class="fas fa-print me-2"></i> Print class
                            </a>
                        <?php endif; ?>
                        <a href="/platform/tenant/dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Dashboard
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo h($tenantName); ?></div>
                            <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                <?php echo count($years); ?> year(s) ·
                                <?php echo count($offerings); ?> offering(s) in the selected year
                            </div>
                        </div>
                    </div>
                    <span class="tenant-badge">
                        <i class="fas fa-info-circle me-1"></i>PDF via browser print
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
                        <div class="ib-title">How release works</div>
                        <div class="ib-text">
                            View or print a report card, then Release it to notify linked guardians.
                            Channels follow each student's opt-in flags — <strong>Portal</strong> uses
                            <code>guardian_portal_access</code>, <strong>Email</strong> uses
                            <code>guardian_email_notify</code>, and <strong>SMS</strong> uses
                            <code>guardian_sms</code>. Email and SMS deliver once SMTP and the tenant's
                            SMS configuration are unblocked; both channels record the intent now.
                        </div>
                    </div>
                </div>

                <form method="GET" action="/platform/tenant/reports/index.php" class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt me-1"></i>Academic year</label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $filterYear === (int)$y['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($y['year_name']); ?><?php if ((int)($y['is_current'] ?? 0) === 1): ?> ★<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-clock me-1"></i>Term</label>
                        <select name="term_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $filterTerm === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($t['term_name']); ?><?php if ((int)($t['is_current'] ?? 0) === 1): ?> ★<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="min-width:220px;">
                        <label><i class="fas fa-chalkboard me-1"></i>Class offering</label>
                        <select name="offering_id" class="form-select" onchange="this.form.submit()">
                            <?php if (empty($offerings)): ?>
                                <option value="0">No offerings for this year</option>
                            <?php else: ?>
                                <?php foreach ($offerings as $o): ?>
                                    <option value="<?php echo (int)$o['id']; ?>" <?php echo $filterOffer === (int)$o['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(offeringLabel($o)); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>

                <?php if (!$selectedOffering): ?>
                    <div class="card-custom">
                        <div class="empty-state">
                            <i class="fas fa-door-open"></i>
                            <h5>No class offering selected</h5>
                            <p>Pick a year that has at least one class offering to continue.</p>
                        </div>
                    </div>
                <?php elseif (empty($roster)): ?>
                    <div class="card-custom">
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h5>No active enrolments</h5>
                            <p>This class offering has no active students. Add students under Enrollments first.</p>
                        </div>
                    </div>
                <?php else: ?>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6>
                                <i class="fas fa-users me-2 text-primary"></i>
                                <?php echo h(offeringLabel($selectedOffering)); ?>
                                — <?php echo count($roster); ?> student(s)
                            </h6>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="/platform/tenant/reports/print-class.php?year_id=<?php echo (int)$filterYear; ?>&term_id=<?php echo (int)$filterTerm; ?>&offering_id=<?php echo (int)$filterOffer; ?>"
                                    target="_blank" rel="noopener"
                                    class="btn-outline-secondary">
                                    <i class="fas fa-print me-1"></i> Print class
                                </a>
                                <button type="button" class="btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkReleaseModal">
                                    <i class="fas fa-paper-plane me-1"></i> Bulk release
                                </button>
                            </div>
                        </div>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th style="min-width:150px;">Student #</th>
                                        <th style="min-width:220px;">Name</th>
                                        <th style="width:110px;text-align:center;">Roll #</th>
                                        <th style="text-align:right;min-width:280px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 0;
                                    foreach ($roster as $st):
                                        $i++;
                                        $sid = (int)$st['student_id'];
                                        $viewUrl = '/platform/tenant/reports/view.php'
                                            . '?student_id=' . $sid
                                            . '&year_id=' . $filterYear
                                            . '&term_id=' . $filterTerm
                                            . '&offering_id=' . $filterOffer;
                                    ?>
                                        <tr>
                                            <td><?php echo h((string)$i); ?></td>
                                            <td style="font-family:'Courier New',monospace;font-size:12px;color:#0d6efd;">
                                                <?php echo h((string)$st['student_number']); ?>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;"><?php echo h(fmtRosterName($st)); ?></div>
                                            </td>
                                            <td style="text-align:center;"><?php echo h((string)($st['roll_number'] ?? '—')); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="<?php echo h($viewUrl); ?>"
                                                        target="_blank" rel="noopener"
                                                        class="btn-outline-primary">
                                                        <i class="fas fa-eye me-1"></i> View / Print
                                                    </a>
                                                    <button type="button"
                                                        class="btn-outline-info js-individual-release"
                                                        data-student-id="<?php echo $sid; ?>"
                                                        data-student-name="<?php echo h(fmtRosterName($st)); ?>">
                                                        <i class="fas fa-paper-plane me-1"></i> Release
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-history me-2 text-primary"></i>Recent releases</h6>
                        <span style="font-size:12px;color:#6c757d;"><?php echo count($history); ?> record(s)</span>
                    </div>
                    <?php if (empty($history)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h5>No releases yet</h5>
                            <p>Once a report is released to guardians, the record appears here.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">ID</th>
                                        <th style="min-width:200px;">Class</th>
                                        <th style="min-width:150px;">Year · Term</th>
                                        <th style="min-width:140px;">Channels</th>
                                        <th style="text-align:center;">Status</th>
                                        <th style="text-align:center;">Sent</th>
                                        <th style="text-align:center;">Skipped</th>
                                        <th style="text-align:center;">Failed</th>
                                        <th style="min-width:150px;">Released</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $r): ?>
                                        <tr>
                                            <td style="font-family:'Courier New',monospace;font-size:12px;">
                                                <a href="/platform/tenant/reports/release.php?id=<?php echo (int)$r['id']; ?>"
                                                    style="color:#0d6efd;text-decoration:none;font-weight:600;">
                                                    #<?php echo h((string)$r['id']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;color:#1a1a2e;">
                                                    <?php echo h((string)($r['class_name'] ?? '—')); ?>
                                                    <?php if (!empty($r['stream_name'])): ?>
                                                        · <?php echo h((string)$r['stream_name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size:11px;color:#6c757d;">
                                                    <?php echo h((string)$r['report_kind']); ?>
                                                    · <?php echo (int)$r['total_students']; ?> student(s)
                                                </div>
                                            </td>
                                            <td style="font-size:12px;">
                                                <?php echo h((string)($r['year_name'] ?? '—')); ?>
                                                · <?php echo h((string)($r['term_name'] ?? '—')); ?>
                                            </td>
                                            <td>
                                                <?php foreach (explode(',', (string)$r['channels_used']) as $ch): ?>
                                                    <span class="pill blue" style="margin-right:4px;"><?php echo h($ch); ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="pill <?php echo statusPill((string)$r['status']); ?>">
                                                    <?php echo h(ucfirst((string)$r['status'])); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;font-weight:700;color:#166534;"><?php echo (int)$r['total_sent']; ?></td>
                                            <td style="text-align:center;color:#6c757d;"><?php echo (int)$r['total_skipped']; ?></td>
                                            <td style="text-align:center;color:#991b1b;"><?php echo (int)$r['total_failed']; ?></td>
                                            <td style="font-size:12px;color:#6c757d;font-family:'Courier New',monospace;">
                                                <?php echo h((string)($r['released_at'] ?? '—')); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <!-- BULK RELEASE MODAL -->
    <?php if ($selectedOffering): ?>
        <div class="modal fade" id="bulkReleaseModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <form method="POST" action="/platform/tenant/reports/index.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="release_bulk">
                        <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                        <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                        <input type="hidden" name="class_offering_id" value="<?php echo (int)$filterOffer; ?>">
                        <input type="hidden" name="class_id" value="<?php echo (int)$selectedOffering['class_id']; ?>">

                        <div class="modal-header">
                            <h5>
                                <i class="fas fa-paper-plane text-primary me-2"></i>
                                Bulk release — <?php echo h(offeringLabel($selectedOffering)); ?>
                                <small class="text-muted d-block" style="font-size:12px;font-weight:400;margin-top:2px;">
                                    <?php echo count($roster); ?> active student(s) will be released.
                                </small>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="bulkReportKind">Report kind</label>
                                    <select class="form-select" id="bulkReportKind" name="report_kind">
                                        <option value="term_report" selected>Term report</option>
                                        <option value="transcript">Transcript</option>
                                        <option value="all">All</option>
                                    </select>
                                </div>
                            </div>

                            <label class="form-label">Channels</label>
                            <div class="d-flex flex-wrap gap-2 channel-chips mb-3">
                                <label>
                                    <input type="checkbox" name="channels[]" value="inapp" checked>
                                    <span>Portal</span>
                                </label>
                                <label>
                                    <input type="checkbox" name="channels[]" value="email">
                                    <span>Email</span>
                                </label>
                                <label>
                                    <input type="checkbox" name="channels[]" value="sms">
                                    <span>SMS</span>
                                </label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="bulkNotes">Notes</label>
                                <input type="text" class="form-control" id="bulkNotes" name="notes" maxlength="500" placeholder="Optional">
                            </div>

                            <div class="alert alert-warning small mb-0" style="border-radius:10px;">
                                <i class="fas fa-info-circle me-1"></i>
                                Email and SMS are recorded now and deliver once SMTP and the tenant's SMS configuration are enabled.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" onclick="return confirm('Release reports for <?php echo count($roster); ?> student(s)?');">
                                <i class="fas fa-paper-plane me-1"></i> Release all
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- INDIVIDUAL RELEASE MODAL -->
    <div class="modal fade" id="individualReleaseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="/platform/tenant/reports/index.php" id="individualReleaseForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release_individual">
                    <input type="hidden" name="student_id" id="individualStudentId" value="">
                    <input type="hidden" name="academic_year_id" value="<?php echo (int)$filterYear; ?>">
                    <input type="hidden" name="academic_term_id" value="<?php echo (int)$filterTerm; ?>">
                    <input type="hidden" name="class_offering_id" value="<?php echo (int)$filterOffer; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $selectedOffering ? (int)$selectedOffering['class_id'] : 0; ?>">

                    <div class="modal-header">
                        <h5>
                            <i class="fas fa-paper-plane text-primary me-2"></i>
                            Release report
                            <small class="text-muted d-block" id="individualStudentName" style="font-size:12px;font-weight:400;margin-top:2px;"></small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="indReportKind">Report kind</label>
                            <select class="form-select" id="indReportKind" name="report_kind">
                                <option value="term_report" selected>Term report</option>
                                <option value="transcript">Transcript</option>
                                <option value="all">All</option>
                            </select>
                        </div>

                        <label class="form-label">Channels</label>
                        <div class="d-flex flex-wrap gap-2 channel-chips mb-3">
                            <label>
                                <input type="checkbox" name="channels[]" value="inapp" checked>
                                <span>Portal</span>
                            </label>
                            <label>
                                <input type="checkbox" name="channels[]" value="email">
                                <span>Email</span>
                            </label>
                            <label>
                                <input type="checkbox" name="channels[]" value="sms">
                                <span>SMS</span>
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="indNotes">Notes</label>
                            <input type="text" class="form-control" id="indNotes" name="notes" maxlength="500" placeholder="Optional">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Release
                        </button>
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
            if (window.innerWidth <= 768 && sidebar && toggle) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768 && sidebar) sidebar.classList.remove('open');
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/tenant/logout.php';
        }

        (function() {
            const modalEl = document.getElementById('individualReleaseModal');
            if (!modalEl) return;
            const modal = new bootstrap.Modal(modalEl);
            const studentIdInput = document.getElementById('individualStudentId');
            const studentNameEl = document.getElementById('individualStudentName');

            document.querySelectorAll('.js-individual-release').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    studentIdInput.value = this.getAttribute('data-student-id') || '';
                    studentNameEl.textContent = this.getAttribute('data-student-name') || '';
                    modal.show();
                });
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
                    let el;
                    if ((el = document.getElementById('userName'))) el.textContent = user.first_name || 'Admin';
                    if ((el = document.getElementById('userAvatar'))) el.textContent = (user.first_name || 'A').charAt(0);
                    if ((el = document.getElementById('userRole'))) el.textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>