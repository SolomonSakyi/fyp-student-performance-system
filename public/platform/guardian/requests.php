<?php

/**
 * Guardian Requests — the guardian's request surface.
 *
 * @package EduTrack
 * @subpackage Platform\Guardian
 * @version 1.1.2
 * @filepath public/platform/guardian/requests.php
 *
 * v1.1.2 change (2026-10-07) [REQUEST NOTIFICATION]:
 *   On the create action, after the guardian_requests INSERT and the
 *   audit_logs write, one in-app notification is fanned out to every
 *   active tenant user via NotificationHelper::notify(). The notify
 *   call is made inside the existing create transaction, after the
 *   audit write and before commit. The helper wraps its own work in
 *   try/catch (NotificationHelper.php v1.1, docblock), so a failed
 *   notification does not roll back the guardian request. The notify
 *   call passes type 'info', priority 'normal', a link to
 *   /platform/tenant/notifications.php?tab=mine, related_type
 *   'guardian_requests', and related_id the new request id. The
 *   tenant-side notifications surface therefore shows the row on the
 *   'My notifications' tab.
 *   A require_once for NotificationHelper.php is added next to the
 *   Permissions.php require at the top of the file. Every other line
 *   is byte-identical to v1.1.1.
 *
 * v1.1.1 change (2026-10-07) [TRANSCRIPT REQUEST]:
 *   One new entry is added to the requestTypes() map:
 *     'transcript' => 'Student transcript request'
 *   positioned between 'record_update' and 'payment'. Nothing else
 *   changes. The request_type column is varchar(50), so the value
 *   is stored as a string. The validator accepts it because it is
 *   a key in the map. The list render labels it "Student transcript
 *   request". Locked decision Q7 (transcript requests deferred) is
 *   superseded for this single item by instruction; the docblock
 *   line for Q7 now reads that the transcript type is offered in
 *   the create form.
 *
 * v1.1 change (2026-10-07) [REQUESTS SURFACE]:
 *   The page is no longer a placeholder. It reads and writes the
 *   guardian_requests table added by migration
 *   2026_10_07_guardian_requests.sql. Two POST actions are added:
 *     - create   CSRF-gated, transaction-wrapped, INSERTs one
 *                guardian_requests row for the logged-in guardian,
 *                with uuidv4() and tenant_id from session. Optional
 *                student_id is verified against this guardian's own
 *                student_guardians rows. Writes one audit_logs row.
 *                [v1.1.2] Also fans out one in-app notification per
 *                active tenant user via NotificationHelper.
 *     - cancel   CSRF-gated, transaction-wrapped, soft-flips one of
 *                the guardian's own rows to status 'cancelled' when
 *                its status is 'open'. Writes one audit_logs row.
 *   The list GET renders the guardian's own requests, most recent
 *   first, with a status pill per row. A "New Request" button opens
 *   the create form at ?action=new.
 *   Every other line of the file is byte-identical to v1.0 outside
 *   the top-level reads, the two POST actions, the create form, and
 *   the list render. The standalone top-bar link set from v1.0 is
 *   preserved. The guardian portal remains a standalone surface
 *   (locked decision Q6): no tenant sidebar partial, no
 *   .nav-subgroup-label CSS rule.
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Guardian-surface sweep. Four changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack' to 'Student 360 Platform'.
 *     - @version confirmed at 1.0 per Decision X-3.
 *     - This v1.0 [SWEEP] docblock paragraph was added above the
 *       existing entries.
 *     - The top-bar action link set is normalized across the four
 *       guardian-portal files: Dashboard, Requests, Notifications,
 *       Profile, Logout, in that order. The current page renders
 *       as plain text rather than a link to itself.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Loads the guardian's own row from guardians (for the hero).
 *   - Loads the guardian's linked students from student_guardians
 *     joined to students (for the optional student dropdown).
 *   - Lists the guardian's own requests from guardian_requests,
 *     most recent first.
 *   - Offers a create form at ?action=new.
 *   - Handles create and cancel POST actions with CSRF and audit.
 *   - [v1.1.2] On create, fans out one in-app notification per
 *     active tenant user via NotificationHelper::notify(). Recipients
 *     come from NotificationHelper::allStaffForTenant(). Type is
 *     'info', priority is 'normal'. The link points at the tenant
 *     notifications page. The helper wraps its own work in try/catch,
 *     so a failed notification does not roll back the request.
 *   - Provides links back to the dashboard, notifications, profile,
 *     and logout.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not list another guardian's requests. Every read and
 *     write is scoped to tenant_id = ? AND guardian_id = ?.
 *   - It does not edit the status of a request. Only create and
 *     cancel are available to the guardian. Status transitions
 *     (in_review, resolved, rejected) are a tenant-admin workflow.
 *   - [v1.1.2] It does not send email or SMS notifications. The
 *     notify call writes in-app rows only, using the helper's
 *     default channel 'inapp'.
 *   - It does not touch campus_requests or school_requests.
 *   - It does not produce or email a transcript PDF. Choosing the
 *     'transcript' type records the request; fulfilment is a
 *     separate milestone.
 *
 * AUTHENTICATION MODEL:
 *   Session keys read (written by login.php v2.4):
 *     $_SESSION['logged_in']   = true
 *     $_SESSION['user_type']   = 'guardian'
 *     $_SESSION['guardian_id'] = the guardians.id
 *     $_SESSION['user_id']     = the platform_users.id
 *     $_SESSION['tenant_id']   = the tenant
 *     $_SESSION['school_id']   = the school
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
 *   Q7   Transcript requests were deferred. [v1.1.1] The
 *        'transcript' type is now offered in the create form. The
 *        underlying fulfilment — producing or delivering the
 *        transcript — remains a separate milestone.
 *
 * DEPENDENCIES:
 *   - app/bootstrap.php
 *   - app/helpers/Permissions.php v1.2
 *   - app/helpers/NotificationHelper.php v1.1  [v1.1.2]
 */

// ============================================
// Bootstrap
// ============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';
require_once $projectRoot . '/app/helpers/Permissions.php';
require_once $projectRoot . '/app/helpers/NotificationHelper.php'; // [v1.1.2]

function h_g(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// The request types the create form offers. The guardian_requests
// table's request_type column is varchar(50), so these values are
// free-form strings, not an enum.
//
// [v1.1.1] 'transcript' added. Student transcript request. The
// fulfilment of a transcript (PDF production, delivery) is a
// separate milestone; this type records the request.
function requestTypes(): array
{
    return [
        'general'       => 'General enquiry',
        'record_update' => 'Student record update',
        'transcript'    => 'Student transcript request',
        'payment'       => 'Fee or payment enquiry',
        'medical'       => 'Medical or health note',
        'other'         => 'Other',
    ];
}

function requestStatusPill(string $status): string
{
    return [
        'open'      => 'blue',
        'in_review' => 'orange',
        'resolved'  => 'green',
        'rejected'  => 'red',
        'cancelled' => 'gray',
    ][$status] ?? 'gray';
}

function requestStatusLabel(string $status): string
{
    return [
        'open'      => 'Open',
        'in_review' => 'In review',
        'resolved'  => 'Resolved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ][$status] ?? ucfirst($status);
}

function timeAgo_g(?string $sqlDate): string
{
    if (!$sqlDate) return '';
    $t = strtotime($sqlDate);
    if (!$t) return (string)$sqlDate;
    $diff = time() - $t;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return date('M j, Y', $t);
}

function uuidv4_g(): string
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

function writeAudit_g($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
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
        error_log('guardian requests audit_logs write failed: ' . $e->getMessage());
    }
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

$db = DatabaseHelper::getInstance();

// ============================================
// Load the guardian row
// ============================================
$guardian = $db->fetchOne(
    "SELECT id, first_name, middle_name, last_name,
            primary_phone, secondary_phone, email, relationship,
            is_active
     FROM guardians
     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
    [$guardianId, $tenantId]
);

if (!$guardian || (int)$guardian['is_active'] !== 1) {
    session_destroy();
    header('Location: /platform/tenant/login.php');
    exit;
}

// ============================================
// POST actions
// ============================================
$errors   = [];
$formData = [];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $formData = $_POST;

    try {
        // ---------- CREATE ----------
        if ($action === 'create') {
            $studentId   = (int)($_POST['student_id'] ?? 0);
            $requestType = trim((string)($_POST['request_type'] ?? ''));
            $subject     = trim((string)($_POST['subject'] ?? ''));
            $body        = trim((string)($_POST['body'] ?? ''));

            $allowedTypes = array_keys(requestTypes());

            if ($subject === '') $errors[] = 'Subject is required.';
            if (mb_strlen($subject) > 255) $errors[] = 'Subject must be 255 characters or less.';
            if ($body === '') $errors[] = 'Please describe your request.';
            if ($requestType === '' || !in_array($requestType, $allowedTypes, true)) {
                $errors[] = 'Please choose a request type.';
            }

            // Optional student — must be one this guardian is linked to.
            if ($studentId > 0) {
                $linkRow = $db->fetchOne(
                    "SELECT sg.id FROM student_guardians sg
                       JOIN students s ON s.id = sg.student_id AND s.deleted_at IS NULL
                      WHERE sg.tenant_id = ? AND sg.guardian_id = ? AND sg.student_id = ?
                        AND sg.deleted_at IS NULL
                      LIMIT 1",
                    [$tenantId, $guardianId, $studentId]
                );
                if (!$linkRow) {
                    $errors[] = 'The selected student is not linked to your account.';
                }
            } else {
                $studentId = 0;
            }

            if (!empty($errors)) {
                throw new Exception(implode(' ', $errors));
            }

            $db->beginTransaction();

            $newId = $db->insert(
                "INSERT INTO guardian_requests
                    (uuid, tenant_id, guardian_id, student_id, request_type,
                     subject, body, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())",
                [
                    uuidv4_g(),
                    $tenantId,
                    $guardianId,
                    $studentId > 0 ? $studentId : null,
                    $requestType,
                    $subject,
                    $body,
                ]
            );

            writeAudit_g(
                $db,
                $tenantId,
                $userId,
                'guardian.request.created',
                'guardian_requests',
                (int)$newId,
                [
                    'guardian_id'  => $guardianId,
                    'student_id'   => $studentId,
                    'request_type' => $requestType,
                    'subject'      => $subject,
                ]
            );

            // [v1.1.2] Fan out one in-app notification per active tenant user.
            // Wrapped in the helper's own try/catch — a failed notification must
            // not roll back the guardian request. See NotificationHelper.php v1.1.
            require_once $projectRoot . '/app/helpers/NotificationHelper.php';
            $staffRecipients = NotificationHelper::allStaffForTenant($db, $tenantId);
            if (!empty($staffRecipients)) {
                $studentName = '';
                if ($studentId > 0) {
                    $sRow = $db->fetchOne(
                        "SELECT first_name, middle_name, last_name, student_number
                           FROM students
                          WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                          LIMIT 1",
                        [$studentId, $tenantId]
                    );
                    if ($sRow) {
                        $studentName = trim(
                            ($sRow['first_name'] ?? '') . ' ' .
                                ($sRow['middle_name'] ?? '') . ' ' .
                                ($sRow['last_name'] ?? '')
                        );
                        $studentName = $studentName !== '' ? preg_replace('/\s+/', ' ', $studentName) : '';
                    }
                }
                $notifyMsg = 'New guardian request: ' . $subject;
                if ($studentName !== '') {
                    $notifyMsg .= ' (about ' . $studentName . ')';
                }
                NotificationHelper::notify($db, $tenantId, [
                    'recipients'   => ['staff' => $staffRecipients],
                    'type'         => 'info',
                    'priority'     => 'normal',
                    'title'        => 'New guardian request',
                    'message'      => $notifyMsg,
                    'link'         => '/platform/tenant/notifications.php?tab=mine',
                    'related_type' => 'guardian_requests',
                    'related_id'   => (int)$newId,
                    'created_by'   => $userId,
                ]);
            }

            $db->commit();
            $_SESSION['success'] = 'Request submitted. The school will review it.';
            header('Location: /platform/guardian/requests.php');
            exit;
        }

        // ---------- CANCEL ----------
        if ($action === 'cancel') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Request not found.');
            }

            $row = $db->fetchOne(
                "SELECT id, status FROM guardian_requests
                  WHERE id = ? AND tenant_id = ? AND guardian_id = ?
                    AND deleted_at IS NULL",
                [$id, $tenantId, $guardianId]
            );

            if (!$row) {
                throw new Exception('Request not found.');
            }
            if ((string)$row['status'] !== 'open') {
                throw new Exception('Only open requests can be cancelled.');
            }

            $db->beginTransaction();
            $db->execute(
                "UPDATE guardian_requests
                    SET status = 'cancelled',
                        updated_at = NOW()
                  WHERE id = ? AND tenant_id = ? AND guardian_id = ?
                    AND deleted_at IS NULL",
                [$id, $tenantId, $guardianId]
            );
            writeAudit_g(
                $db,
                $tenantId,
                $userId,
                'guardian.request.cancelled',
                'guardian_requests',
                $id,
                ['guardian_id' => $guardianId]
            );
            $db->commit();

            $_SESSION['success'] = 'Request cancelled.';
            header('Location: /platform/guardian/requests.php');
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('guardian requests action error: ' . $e->getMessage());
        $_SESSION['errors']    = [$e->getMessage()];
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/guardian/requests.php?action=new');
        exit;
    }
}

// ============================================
// Flash
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
// Load the guardian's linked students (for the create form)
// ============================================
$linkedStudents = $db->fetchAll(
    "SELECT s.id AS student_id, s.student_number,
            s.first_name, s.middle_name, s.last_name,
            c.class_name
       FROM student_guardians sg
       JOIN students s ON s.id = sg.student_id AND s.deleted_at IS NULL
       LEFT JOIN classes c ON c.id = s.class_id AND c.deleted_at IS NULL
      WHERE sg.tenant_id = ? AND sg.guardian_id = ? AND sg.deleted_at IS NULL
      ORDER BY sg.is_primary DESC, s.first_name ASC, s.last_name ASC",
    [$tenantId, $guardianId]
);

// ============================================
// Load the guardian's own requests
// ============================================
$requests = $db->fetchAll(
    "SELECT gr.id, gr.uuid, gr.student_id, gr.request_type, gr.subject, gr.body,
            gr.status, gr.resolved_at, gr.resolution_note,
            gr.created_at, gr.updated_at,
            s.first_name AS student_first_name,
            s.middle_name AS student_middle_name,
            s.last_name AS student_last_name,
            s.student_number AS student_number,
            c.class_name AS student_class_name
       FROM guardian_requests gr
       LEFT JOIN students s ON s.id = gr.student_id AND s.deleted_at IS NULL
       LEFT JOIN classes c ON c.id = s.class_id AND c.deleted_at IS NULL
      WHERE gr.tenant_id = ? AND gr.guardian_id = ? AND gr.deleted_at IS NULL
      ORDER BY gr.created_at DESC, gr.id DESC",
    [$tenantId, $guardianId]
);

// ============================================
// Composed display values
// ============================================
$guardianFullName = trim(
    ($guardian['first_name'] ?? '') . ' ' .
        ($guardian['middle_name'] ?? '') . ' ' .
        ($guardian['last_name'] ?? '')
);
$guardianFullName = $guardianFullName !== '' ? preg_replace('/\s+/', ' ', $guardianFullName) : 'Guardian';

$schoolName = (string)($_SESSION['school_name'] ?? '');
$schoolLogo = (string)($_SESSION['school_logo'] ?? '');

$openForm = ($action === 'new');

$pageTitle = 'Requests - Student 360 Platform';
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

        .top-bar {
            background: #fff;
            padding: 12px 20px;
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
            gap: 12px;
        }

        .top-bar .brand img {
            max-height: 40px;
            max-width: 40px;
        }

        .top-bar .brand .brand-text h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: #1a1a2e;
        }

        .top-bar .brand .brand-text p {
            font-size: 12px;
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
            font-size: 13px;
            text-decoration: none;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
            color: #495057;
        }

        .btn-outline-secondary.is-current {
            background: #e3f0ff;
            border-color: #4facfe;
            color: #0d6efd;
            font-weight: 600;
            cursor: default;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            border-radius: 8px;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary:hover {
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.35);
            transform: translateY(-1px);
        }

        .btn-outline-danger {
            background: transparent;
            border: 1.5px solid #dc3545;
            color: #dc3545;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .content-area {
            padding: 20px 20px 40px;
            max-width: 900px;
            margin: 0 auto;
        }

        .hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .hero .hero-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .hero .hero-body {
            flex: 1;
            min-width: 0;
        }

        .hero .hero-body h2 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 2px;
        }

        .hero .hero-body p {
            font-size: 12px;
            opacity: 0.75;
            margin: 0;
        }

        .hero .hero-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .alert-pro .alert-pro-content {
            flex: 1;
        }

        .alert-pro .alert-pro-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 2px;
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

        .card-custom {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-custom .card-header-custom {
            padding: 14px 20px;
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

        .card-custom .card-body-custom {
            padding: 16px 20px;
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
            border: 1.5px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 40px;
        }

        textarea.form-control {
            height: auto;
            min-height: 120px;
            resize: vertical;
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

        .request-card {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 12px;
        }

        .request-card:last-child {
            margin-bottom: 0;
        }

        .request-card .rc-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .request-card .rc-subject {
            font-weight: 700;
            font-size: 14px;
            color: #1a1a2e;
            word-break: break-word;
        }

        .request-card .rc-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 11px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .request-card .rc-body {
            font-size: 13px;
            color: #495057;
            white-space: pre-wrap;
            word-break: break-word;
            margin-bottom: 10px;
        }

        .request-card .rc-resolution {
            font-size: 12px;
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 10px;
        }

        .request-card .rc-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .pill.blue {
            background: #cce5ff;
            color: #004085;
        }

        .pill.orange {
            background: #ffe8d9;
            color: #c2410c;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.red {
            background: #f8d7da;
            color: #721c24;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.teal {
            background: #d1f2eb;
            color: #0d5c4a;
        }

        .pill.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: #6c757d;
        }

        .empty-state .es-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #eef6ff;
            color: #4facfe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 20px;
        }

        .empty-state h5 {
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
            font-size: 18px;
        }

        .empty-state p {
            font-size: 14px;
            margin: 0 auto 20px;
            max-width: 560px;
            line-height: 1.7;
        }

        @media (max-width: 600px) {
            .content-area {
                padding: 14px 14px 30px;
            }

            .hero {
                padding: 16px 18px;
                gap: 14px;
            }

            .hero .hero-icon {
                width: 46px;
                height: 46px;
                font-size: 20px;
            }

            .hero .hero-body h2 {
                font-size: 17px;
            }

            .hero .hero-actions {
                width: 100%;
            }

            .request-card {
                padding: 14px 16px;
            }

            .request-card .rc-actions {
                justify-content: flex-start;
            }

            .empty-state {
                padding: 40px 18px;
            }

            .empty-state .es-icon {
                width: 64px;
                height: 64px;
                font-size: 28px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="top-bar">
            <div class="brand">
                <?php if ($schoolLogo !== ''): ?>
                    <img src="<?php echo h_g($schoolLogo); ?>" alt="">
                <?php endif; ?>
                <div class="brand-text">
                    <h1><?php echo h_g($schoolName !== '' ? $schoolName : 'Student 360'); ?></h1>
                    <p>Guardian Portal</p>
                </div>
            </div>
            <div class="actions">
                <span class="badge"><i class="fas fa-user-shield me-1"></i> Guardian</span>
                <a href="/platform/guardian/index.php" class="btn-outline-secondary">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <span class="btn-outline-secondary is-current">
                    <i class="fas fa-file-signature me-1"></i> Requests
                </span>
                <a href="/platform/guardian/notifications.php" class="btn-outline-secondary">
                    <i class="fas fa-bell me-1"></i> Notifications
                </a>
                <a href="/platform/guardian/profile.php" class="btn-outline-secondary">
                    <i class="fas fa-user-cog me-1"></i> Profile
                </a>
                <a href="/platform/logout.php" class="btn-outline-secondary">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>

        <div class="content-area">

            <div class="hero">
                <div class="hero-icon"><i class="fas fa-file-signature"></i></div>
                <div class="hero-body">
                    <h2>Requests</h2>
                    <p>Requests you raise with the school on behalf of your linked students</p>
                </div>
                <div class="hero-actions">
                    <?php if (!$openForm): ?>
                        <a href="/platform/guardian/requests.php?action=new" class="btn-primary">
                            <i class="fas fa-plus"></i> New Request
                        </a>
                    <?php else: ?>
                        <a href="/platform/guardian/requests.php" class="btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to list
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert-pro alert-pro-error">
                    <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="alert-pro-content">
                        <div class="alert-pro-title">Could not complete the request</div>
                        <ul style="margin:6px 0 0 18px;font-size:13px;line-height:1.7;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo h_g($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="alert-pro alert-pro-success">
                    <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="alert-pro-content">
                        <div class="alert-pro-title">Success!</div>
                        <div style="font-size:13px;"><?php echo h_g($successMessage); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($openForm): ?>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-plus-circle me-2 text-primary"></i>New Request</h6>
                    </div>
                    <div class="card-body-custom">
                        <form method="POST" action="/platform/guardian/requests.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="reqType">Request type <span class="required">*</span></label>
                                    <select class="form-select" id="reqType" name="request_type" required>
                                        <option value="">— Choose —</option>
                                        <?php foreach (requestTypes() as $key => $label): ?>
                                            <option value="<?php echo h_g($key); ?>"
                                                <?php echo ($useForm && ($formData['request_type'] ?? '') === $key) ? 'selected' : ''; ?>>
                                                <?php echo h_g($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="reqStudent">About a student</label>
                                    <select class="form-select" id="reqStudent" name="student_id">
                                        <option value="0">— Not about a specific student —</option>
                                        <?php foreach ($linkedStudents as $st):
                                            $nm = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                                            $nm = $nm !== '' ? preg_replace('/\s+/', ' ', $nm) : ('Student #' . (int)$st['student_id']);
                                        ?>
                                            <option value="<?php echo (int)$st['student_id']; ?>"
                                                <?php echo ($useForm && (int)($formData['student_id'] ?? 0) === (int)$st['student_id']) ? 'selected' : ''; ?>>
                                                <?php echo h_g($nm); ?>
                                                <?php if (!empty($st['class_name'])): ?> (<?php echo h_g($st['class_name']); ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Optional. Only students linked to your account appear here.</div>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label" for="reqSubject">Subject <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="reqSubject" name="subject"
                                        maxlength="255" required
                                        placeholder="A short summary of your request"
                                        value="<?php echo $useForm ? h_g((string)($formData['subject'] ?? '')) : ''; ?>">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label" for="reqBody">Description <span class="required">*</span></label>
                                    <textarea class="form-control" id="reqBody" name="body"
                                        required placeholder="Describe your request in detail"><?php echo $useForm ? h_g((string)($formData['body'] ?? '')) : ''; ?></textarea>
                                </div>
                            </div>

                            <div style="display:flex;justify-content:flex-end;gap:8px;">
                                <a href="/platform/guardian/requests.php" class="btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-paper-plane"></i> Submit request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-list me-2 text-primary"></i>My Requests</h6>
                        <span style="font-size:12px;color:#6c757d;"><?php echo count($requests); ?> request(s)</span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($requests)): ?>
                            <div class="empty-state">
                                <div class="es-icon"><i class="fas fa-inbox"></i></div>
                                <h5>No requests yet</h5>
                                <p>
                                    You have not raised any requests with the school. When you do,
                                    they appear here so you can track their progress.
                                </p>
                                <a href="/platform/guardian/requests.php?action=new" class="btn-primary">
                                    <i class="fas fa-plus"></i> Raise your first request
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($requests as $r):
                                $status = (string)$r['status'];
                                $statusPill  = requestStatusPill($status);
                                $statusLabel = requestStatusLabel($status);
                                $createdAt   = (string)$r['created_at'];
                                $typeKey     = (string)$r['request_type'];
                                $typeLabel   = requestTypes()[$typeKey] ?? ucfirst($typeKey);

                                $studentNm = '';
                                if (!empty($r['student_id'])) {
                                    $parts = trim(
                                        ($r['student_first_name'] ?? '') . ' ' .
                                            ($r['student_middle_name'] ?? '') . ' ' .
                                            ($r['student_last_name'] ?? '')
                                    );
                                    $studentNm = $parts !== '' ? preg_replace('/\s+/', ' ', $parts) : ('Student #' . (int)$r['student_id']);
                                }
                            ?>
                                <div class="request-card">
                                    <div class="rc-head">
                                        <div class="rc-subject"><?php echo h_g((string)$r['subject']); ?></div>
                                        <span class="pill <?php echo h_g($statusPill); ?>">
                                            <i class="fas fa-circle" style="font-size:6px;"></i> <?php echo h_g($statusLabel); ?>
                                        </span>
                                    </div>
                                    <div class="rc-meta">
                                        <span><i class="fas fa-tag me-1"></i><?php echo h_g($typeLabel); ?></span>
                                        <span><i class="fas fa-clock me-1"></i><?php echo h_g(timeAgo_g($createdAt)); ?></span>
                                        <?php if ($studentNm !== ''): ?>
                                            <span><i class="fas fa-user-graduate me-1"></i><?php echo h_g($studentNm); ?>
                                                <?php if (!empty($r['student_class_name'])): ?> · <?php echo h_g($r['student_class_name']); ?><?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rc-body"><?php echo h_g((string)$r['body']); ?></div>

                                    <?php if (!empty($r['resolution_note'])): ?>
                                        <div class="rc-resolution">
                                            <strong><i class="fas fa-check-circle me-1"></i>Resolution:</strong>
                                            <?php echo h_g((string)$r['resolution_note']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="rc-actions">
                                        <?php if ($status === 'open'): ?>
                                            <form method="POST" action="/platform/guardian/requests.php"
                                                onsubmit="return confirm('Cancel this request?');" style="margin:0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="btn-outline-danger">
                                                    <i class="fas fa-times me-1"></i> Cancel
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>