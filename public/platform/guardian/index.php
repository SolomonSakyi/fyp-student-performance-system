<?php

/**
 * Guardian Dashboard — the guardian's landing page.
 *
 * @package EduTrack
 * @subpackage Platform\Guardian
 * @version 1.0
 * @filepath public/platform/guardian/index.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Guardian-surface sweep. Four changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack' to 'Student 360 Platform'.
 *     - @version reduced from 1.2 to 1.0 per Decision X-3.
 *     - This v1.0 [SWEEP] docblock paragraph was added above the
 *       existing v1.1 and v1.2 entries.
 *     - The top-bar action link set is normalized across the four
 *       guardian-portal files: Dashboard, Requests, Notifications,
 *       Profile, Logout, in that order. The current page renders
 *       as plain text rather than a link to itself.
 *   Every other line of the file is byte-identical to v1.2.
 *   The guardian portal is a standalone surface (per locked
 *   decision Q6). It does not adopt the tenant sidebar partial
 *   and does not carry the .nav-subgroup-label CSS rule.
 *
 * v1.2 changes (2026-10-04):
 *   - Added a "Requests" link to the top-bar actions, above the
 *     "Notifications" link. Plain link; no inline count. The
 *     dashboard runs no request query.
 *   - No other line of the file changed.
 *
 * v1.1 changes (2026-10-04):
 *   - Added a "Notifications" link to the top-bar actions.
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Loads the guardian row from guardians.
 *   - Lists every student linked to the guardian through
 *     student_guardians.
 *   - Links to /platform/student/index.php?student_id=X.
 *   - Provides top-bar links to Dashboard, Requests, Notifications,
 *     Profile and Logout.
 *
 * AUTHENTICATION MODEL:
 *   Session keys read (written by login.php v2.4):
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
 *   Q7   Transcript requests are deferred.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not edit any data.
 *   - It does not show attendance, discipline, behaviour, or results.
 *   - It does not query the notifications table.
 *   - It does not query any request table.
 *   - It does not touch any table other than guardians, students,
 *     student_guardians, classes.
 *
 * DEPENDENCIES:
 *   - app/bootstrap.php
 *   - app/helpers/Permissions.php v1.2
 */

// ============================================
// Bootstrap
// ============================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/bootstrap.php';
require_once $projectRoot . '/app/helpers/Permissions.php';

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

$guardian = $db->fetchOne(
    "SELECT id, first_name, middle_name, last_name,
            primary_phone, secondary_phone, email, relationship, address,
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

$linkedStudents = $db->fetchAll(
    "SELECT s.id AS student_id,
            s.student_number,
            s.first_name, s.middle_name, s.last_name, s.preferred_name,
            s.gender, s.enrollment_status, s.is_active,
            c.class_name, c.class_code,
            sg.relationship, sg.is_primary, sg.can_pickup
     FROM student_guardians sg
     JOIN students s ON s.id = sg.student_id
     LEFT JOIN classes c ON c.id = s.class_id
     WHERE sg.tenant_id = ?
       AND sg.guardian_id = ?
       AND sg.deleted_at IS NULL
       AND s.deleted_at IS NULL
       AND s.tenant_id = ?
     ORDER BY sg.is_primary DESC, s.first_name ASC, s.last_name ASC",
    [$tenantId, $guardianId, $tenantId]
);

$schoolName = (string)($_SESSION['school_name'] ?? '');
$schoolLogo = (string)($_SESSION['school_logo'] ?? '');
$guardianFullName = trim(
    ($guardian['first_name'] ?? '') . ' ' .
        ($guardian['middle_name'] ?? '') . ' ' .
        ($guardian['last_name'] ?? '')
);
$guardianFullName = preg_replace('/\s+/', ' ', $guardianFullName);
$pageTitle = 'Guardian Dashboard - Student 360 Platform';
function h_g(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
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

        .content-area {
            padding: 20px 20px 40px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .welcome-block {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            color: #fff;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 24px;
        }

        .welcome-block h2 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .welcome-block p {
            margin: 0;
            opacity: 0.7;
            font-size: 13px;
        }

        .welcome-block .meta {
            font-size: 12px;
            opacity: 0.5;
            margin-top: 6px;
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

        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }

        .student-card {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 12px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: all 0.2s;
        }

        .student-card:hover {
            border-color: #4facfe;
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.10);
            transform: translateY(-1px);
        }

        .student-card .sc-head {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-card .sc-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            flex-shrink: 0;
        }

        .student-card .sc-name {
            font-weight: 700;
            font-size: 15px;
            color: #1a1a2e;
        }

        .student-card .sc-num {
            font-size: 12px;
            color: #0d6efd;
            font-family: 'Courier New', monospace;
        }

        .student-card .sc-meta {
            font-size: 12px;
            color: #6c757d;
        }

        .student-card .sc-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
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

        .pill.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .pill.green {
            background: #d4edda;
            color: #155724;
        }

        .pill.gray {
            background: #e9ecef;
            color: #495057;
        }

        .pill.gold {
            background: #fff3cd;
            color: #856404;
        }

        .student-card .sc-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .student-card .sc-actions a {
            flex: 1;
            text-align: center;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            transition: all 0.2s;
        }

        .student-card .sc-actions a:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.35);
            color: #fff;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 44px;
            opacity: 0.3;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 13px;
            margin: 0;
        }

        .contact-row {
            display: flex;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
            font-size: 13px;
        }

        .contact-row:last-child {
            border-bottom: none;
        }

        .contact-row .label {
            width: 140px;
            flex-shrink: 0;
            color: #6c757d;
            font-weight: 500;
        }

        .contact-row .value {
            color: #1a1a2e;
            word-break: break-word;
        }

        @media (max-width: 600px) {
            .content-area {
                padding: 14px 14px 30px;
            }

            .welcome-block {
                padding: 18px 20px;
            }

            .contact-row {
                flex-direction: column;
                gap: 2px;
            }

            .contact-row .label {
                width: auto;
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
                <span class="btn-outline-secondary is-current">
                    <i class="fas fa-home me-1"></i> Dashboard
                </span>
                <a href="/platform/guardian/requests.php" class="btn-outline-secondary">
                    <i class="fas fa-file-signature me-1"></i> Requests
                </a>
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
            <div class="welcome-block">
                <h2>Welcome, <?php echo h_g($guardianFullName !== '' ? $guardianFullName : 'Guardian'); ?></h2>
                <p>You are viewing the student portal through your guardian account. Select a student below to see their records.</p>
                <div class="meta">
                    <i class="fas fa-school me-1"></i> <?php echo h_g($schoolName !== '' ? $schoolName : '—'); ?>
                    &nbsp;·&nbsp;
                    <i class="fas fa-users me-1"></i> <?php echo count($linkedStudents); ?> linked student(s)
                </div>
            </div>
            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-user-graduate me-2 text-primary"></i>Linked Students</h6>
                    <span class="text-muted small"><?php echo count($linkedStudents); ?> student(s)</span>
                </div>
                <div class="card-body-custom">
                    <?php if (empty($linkedStudents)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h5>No linked students</h5>
                            <p>Your account is not linked to any student yet. Contact the school office if this is unexpected.</p>
                        </div>
                    <?php else: ?>
                        <div class="student-grid">
                            <?php foreach ($linkedStudents as $st):
                                $sid = (int)$st['student_id'];
                                $allowed = guardian_can_view_student($sid);
                                if (!$allowed) continue;
                                $nm = trim(($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                                $nm = $nm !== '' ? preg_replace('/\s+/', ' ', $nm) : ('Student #' . $sid);
                                $initials = strtoupper(substr((string)($st['first_name'] ?? ''), 0, 1) . substr((string)($st['last_name'] ?? ''), 0, 1));
                                if ($initials === '') $initials = 'ST';
                                $viewUrl = '/platform/student/index.php?student_id=' . $sid;
                            ?>
                                <div class="student-card">
                                    <div class="sc-head">
                                        <div class="sc-avatar"><?php echo h_g($initials); ?></div>
                                        <div>
                                            <div class="sc-name"><?php echo h_g($nm); ?></div>
                                            <div class="sc-num"><?php echo h_g((string)($st['student_number'] ?? '')); ?></div>
                                        </div>
                                    </div>
                                    <div class="sc-pills">
                                        <?php if (!empty($st['class_name'])): ?>
                                            <span class="pill blue"><i class="fas fa-book"></i><?php echo h_g($st['class_name']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($st['relationship'])): ?>
                                            <span class="pill purple"><i class="fas fa-user-friends"></i><?php echo h_g($st['relationship']); ?></span>
                                        <?php endif; ?>
                                        <?php if ((int)$st['is_primary'] === 1): ?>
                                            <span class="pill gold"><i class="fas fa-star"></i>Primary</span>
                                        <?php endif; ?>
                                        <?php if ((int)$st['can_pickup'] === 1): ?>
                                            <span class="pill green"><i class="fas fa-car"></i>Pickup</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sc-actions">
                                        <a href="<?php echo h_g($viewUrl); ?>">
                                            <i class="fas fa-eye me-1"></i> View records
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-id-card me-2 text-primary"></i>Your Contact Details</h6>
                    <a href="/platform/guardian/profile.php" class="text-primary small" style="text-decoration:none;">
                        <i class="fas fa-edit me-1"></i> Edit
                    </a>
                </div>
                <div class="card-body-custom">
                    <div class="contact-row">
                        <div class="label">Name</div>
                        <div class="value"><?php echo h_g($guardianFullName); ?></div>
                    </div>
                    <?php if (!empty($guardian['relationship'])): ?>
                        <div class="contact-row">
                            <div class="label">Relationship</div>
                            <div class="value"><?php echo h_g($guardian['relationship']); ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="contact-row">
                        <div class="label">Primary phone</div>
                        <div class="value"><?php echo h_g($guardian['primary_phone'] ?? '—'); ?></div>
                    </div>
                    <?php if (!empty($guardian['secondary_phone'])): ?>
                        <div class="contact-row">
                            <div class="label">Alternate phone</div>
                            <div class="value"><?php echo h_g($guardian['secondary_phone']); ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="contact-row">
                        <div class="label">Email</div>
                        <div class="value"><?php echo h_g($guardian['email'] ?? '—'); ?></div>
                    </div>
                    <?php if (!empty($guardian['address'])): ?>
                        <div class="contact-row">
                            <div class="label">Address</div>
                            <div class="value"><?php echo nl2br(h_g($guardian['address'])); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>