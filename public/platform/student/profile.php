<?php

/**
 * Student Profile — read-only per-student profile page reached from
 * the student overview.
 *
 * @package EduTrack
 * @subpackage Platform\Student
 * @version 1.1
 * @filepath public/platform/student/profile.php
 *
 * WHAT THIS PAGE DOES:
 *   - Requires an authenticated guardian session.
 *   - Reads student_id from $_GET.
 *   - Calls guardian_can_view_student($studentId). On false,
 *     responds 404 (not 403).
 *   - Loads the student row, the class row, the level row, the
 *     stream row.
 *   - Loads the guardian's relationship to this student from
 *     student_guardians.
 *   - Renders the student's profile: name, preferred name, number,
 *     date of birth, gender, nationality, religion, enrollment date,
 *     enrollment status, class, level, stream.
 *   - Provides a "Back to overview" link to
 *     /platform/student/index.php?student_id=X and a "Back to
 *     dashboard" link to /platform/guardian/index.php.
 *
 * AUTHENTICATION MODEL:
 *   Session keys read (written by login.php v2.4):
 *     $_SESSION['logged_in']   = true
 *     $_SESSION['user_type']   = 'guardian'
 *     $_SESSION['guardian_id'] = the guardians.id
 *     $_SESSION['tenant_id']   = the tenant
 *     $_SESSION['school_id']   = the school
 *
 * THE 404-NOT-403 RULE:
 *   Same rule as /platform/student/index.php. When
 *   guardian_can_view_student($studentId) returns false, the page
 *   responds 404. A 403 would tell a probing user that the student
 *   exists but is not theirs.
 *
 * WHAT THIS PAGE DOES NOT DO:
 *   - It does not edit anything.
 *   - It does not show attendance, discipline, behaviour, results,
 *     reports, or finance.
 *   - It does not read students.guardian_* flat columns.
 *   - It does not touch any table other than students, classes,
 *     academic_levels, streams, student_guardians.
 *
 * v1.1 changes (2026-10-04):
 *   - Removed `s.uid` from the SELECT. The live students table has
 *     no `uid` column; the unique identifier is `uuid`. The page
 *     never reads the value, so the column is dropped from the
 *     field list rather than renamed. This closes the
 *     SQLSTATE[42S22] error 1054 Unknown column 's.uid' that v1.0
 *     produced. No other line of the file changed.
 *
 * LOCKED DECISIONS (2026-10-03 / 2026-10-04):
 *   Q1   The guardian is the login. This page is the guardian's
 *        read-only view of one student's profile.
 *   Q6   Two portal surfaces: /platform/guardian/ and
 *        /platform/student/.
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
// Load the student with class, level and stream joins
// ============================================
$student = $db->fetchOne(
    "SELECT s.id, s.tenant_id, s.school_id, s.campus_id, s.class_id,
            s.student_number, s.first_name, s.middle_name, s.last_name, s.preferred_name,
            s.date_of_birth, s.gender, s.nationality, s.religion,
            s.primary_phone, s.secondary_phone, s.email,
            s.address, s.town_city, s.district, s.region,
            s.enrollment_date, s.enrollment_status, s.is_active,
            s.stream_id,
            c.class_name, c.class_code,
            al.level_name, al.level_code,
            st.stream_name
     FROM students s
     LEFT JOIN classes c ON c.id = s.class_id AND c.deleted_at IS NULL
     LEFT JOIN academic_levels al ON al.school_id = s.school_id AND al.deleted_at IS NULL
     LEFT JOIN streams st ON st.id = s.stream_id AND st.deleted_at IS NULL
     WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$studentId, $tenantId]
);

if (!$student) {
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

// ============================================
// Load the guardian's relationship to this student
// ============================================
$link = $db->fetchOne(
    "SELECT relationship, is_primary, can_pickup
     FROM student_guardians
     WHERE tenant_id = ? AND guardian_id = ? AND student_id = ?
       AND deleted_at IS NULL
     LIMIT 1",
    [$tenantId, $guardianId, $studentId]
);

$relationship = (string)($link['relationship'] ?? '');
$isPrimary    = $link ? (int)$link['is_primary'] : 0;
$canPickup    = $link ? (int)$link['can_pickup'] : 0;

// ============================================
// Composed display values
// ============================================
$fullName = trim(
    ($student['first_name'] ?? '') . ' ' .
        ($student['middle_name'] ?? '') . ' ' .
        ($student['last_name'] ?? '')
);
$fullName = $fullName !== '' ? preg_replace('/\s+/', ' ', $fullName) : ('Student #' . $studentId);

$initials = strtoupper(
    substr((string)($student['first_name'] ?? ''), 0, 1) .
        substr((string)($student['last_name'] ?? ''), 0, 1)
);
if ($initials === '') $initials = 'ST';

$preferred        = (string)($student['preferred_name'] ?? '');
$studentNumber    = (string)($student['student_number'] ?? '');
$dateOfBirth      = (string)($student['date_of_birth'] ?? '');
$gender           = (string)($student['gender'] ?? '');
$nationality      = (string)($student['nationality'] ?? '');
$religion         = (string)($student['religion'] ?? '');
$primaryPhone     = (string)($student['primary_phone'] ?? '');
$secondaryPhone   = (string)($student['secondary_phone'] ?? '');
$email            = (string)($student['email'] ?? '');
$address          = (string)($student['address'] ?? '');
$townCity         = (string)($student['town_city'] ?? '');
$district         = (string)($student['district'] ?? '');
$region           = (string)($student['region'] ?? '');
$enrollmentStatus = (string)($student['enrollment_status'] ?? '');
$enrollmentDate   = (string)($student['enrollment_date'] ?? '');
$class_name       = (string)($student['class_name'] ?? '');
$class_code       = (string)($student['class_code'] ?? '');
$level_name       = (string)($student['level_name'] ?? '');
$level_code       = (string)($student['level_code'] ?? '');
$stream_name      = (string)($student['stream_name'] ?? '');

$age = null;
if ($dateOfBirth !== '') {
    try {
        $dob = new DateTime($dateOfBirth);
        $now = new DateTime('today');
        $age = $dob->diff($now)->y;
    } catch (Exception $e) {
        $age = null;
    }
}

$schoolName = (string)($_SESSION['school_name'] ?? '');
$schoolLogo = (string)($_SESSION['school_logo'] ?? '');

$pageTitle = 'Student Profile - EduTrack';
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

        .content-area {
            padding: 20px 20px 40px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .profile-hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            color: #fff;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .profile-hero .ph-avatar {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 32px;
            flex-shrink: 0;
        }

        .profile-hero .ph-body {
            flex: 1;
            min-width: 0;
        }

        .profile-hero .ph-body h2 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 2px;
        }

        .profile-hero .ph-body .sn {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            opacity: 0.75;
            margin-bottom: 6px;
        }

        .profile-hero .ph-body .pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .profile-hero .ph-body .pills .pill {
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
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

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .info-cell {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 10px;
            padding: 12px 14px;
        }

        .info-cell .label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .info-cell .value {
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 500;
            margin-top: 2px;
            word-break: break-word;
        }

        .info-cell .value.muted {
            color: #adb5bd;
            font-style: italic;
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
            width: 160px;
            flex-shrink: 0;
            color: #6c757d;
            font-weight: 500;
        }

        .contact-row .value {
            color: #1a1a2e;
            word-break: break-word;
        }

        .link-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }

        .link-card {
            background: #fafbfc;
            border: 1px solid #eef0f3;
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
            text-decoration: none;
            color: #1a1a2e;
            transition: all 0.2s;
        }

        .link-card:hover {
            border-color: #4facfe;
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.10);
            transform: translateY(-1px);
            color: #1a1a2e;
        }

        .link-card .lc-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
        }

        .link-card .lc-icon.blue {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

        .link-card .lc-icon.orange {
            background: linear-gradient(135deg, #fd7e14, #ffb347);
        }

        .link-card .lc-icon.purple {
            background: linear-gradient(135deg, #6f42c1, #a98ee0);
        }

        .link-card .lc-icon.green {
            background: linear-gradient(135deg, #28a745, #6ee78a);
        }

        .link-card .lc-icon.teal {
            background: linear-gradient(135deg, #20c997, #63e6be);
        }

        .link-card .lc-icon.pink {
            background: linear-gradient(135deg, #e83e8c, #f28dbf);
        }

        .link-card .lc-label {
            font-size: 13px;
            font-weight: 600;
        }

        .link-card .lc-desc {
            font-size: 11px;
            color: #6c757d;
        }

        @media (max-width: 600px) {
            .content-area {
                padding: 14px 14px 30px;
            }

            .profile-hero {
                padding: 18px 20px;
                gap: 14px;
            }

            .profile-hero .ph-avatar {
                width: 64px;
                height: 64px;
                font-size: 24px;
            }

            .profile-hero .ph-body h2 {
                font-size: 18px;
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
                    <h1><?php echo h_g($schoolName !== '' ? $schoolName : 'EduTrack'); ?></h1>
                    <p>Student Portal</p>
                </div>
            </div>
            <div class="actions">
                <span class="badge"><i class="fas fa-user-shield me-1"></i> Guardian</span>
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

            <div class="profile-hero">
                <div class="ph-avatar"><?php echo h_g($initials); ?></div>
                <div class="ph-body">
                    <h2><?php echo h_g($fullName); ?></h2>
                    <div class="sn"><i class="fas fa-id-badge me-1"></i><?php echo h_g($studentNumber); ?></div>
                    <div class="pills">
                        <?php if ($class_name !== ''): ?>
                            <span class="pill"><i class="fas fa-book me-1"></i><?php echo h_g($class_name); ?></span>
                        <?php endif; ?>
                        <?php if ($level_name !== ''): ?>
                            <span class="pill"><i class="fas fa-layer-group me-1"></i><?php echo h_g($level_name); ?></span>
                        <?php endif; ?>
                        <?php if ($stream_name !== ''): ?>
                            <span class="pill"><i class="fas fa-code-branch me-1"></i><?php echo h_g($stream_name); ?></span>
                        <?php endif; ?>
                        <?php if ($relationship !== ''): ?>
                            <span class="pill"><i class="fas fa-user-friends me-1"></i><?php echo h_g($relationship); ?></span>
                        <?php endif; ?>
                        <?php if ($isPrimary === 1): ?>
                            <span class="pill"><i class="fas fa-star me-1"></i>Primary</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-user me-2 text-primary"></i>Student Profile</h6>
                </div>
                <div class="card-body-custom">
                    <div class="info-grid">
                        <div class="info-cell">
                            <div class="label">Full Name</div>
                            <div class="value"><?php echo h_g($fullName); ?></div>
                        </div>
                        <?php if ($preferred !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Preferred Name</div>
                                <div class="value"><?php echo h_g($preferred); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="info-cell">
                            <div class="label">Student Number</div>
                            <div class="value"><?php echo h_g($studentNumber); ?></div>
                        </div>
                        <?php if ($dateOfBirth !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Date of Birth</div>
                                <div class="value">
                                    <?php
                                    try {
                                        echo h_g((new DateTime($dateOfBirth))->format('M j, Y'));
                                    } catch (Exception $e) {
                                        echo h_g($dateOfBirth);
                                    }
                                    ?>
                                    <?php if ($age !== null): ?>
                                        <span style="color:#6c757d;font-weight:400;"> · <?php echo h_g((string)$age); ?> years</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($gender !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Gender</div>
                                <div class="value"><?php echo h_g($gender); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($nationality !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Nationality</div>
                                <div class="value"><?php echo h_g($nationality); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($religion !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Religion</div>
                                <div class="value"><?php echo h_g($religion); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="info-cell">
                            <div class="label">Class</div>
                            <div class="value <?php echo $class_name === '' ? 'muted' : ''; ?>">
                                <?php echo h_g($class_name !== '' ? $class_name : 'Not assigned'); ?>
                                <?php if ($class_code !== ''): ?>
                                    <span style="color:#6c757d;font-weight:400;"> · <?php echo h_g($class_code); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-cell">
                            <div class="label">Level</div>
                            <div class="value <?php echo $level_name === '' ? 'muted' : ''; ?>">
                                <?php echo h_g($level_name !== '' ? $level_name : 'Not assigned'); ?>
                                <?php if ($level_code !== ''): ?>
                                    <span style="color:#6c757d;font-weight:400;"> · <?php echo h_g($level_code); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($stream_name !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Stream</div>
                                <div class="value"><?php echo h_g($stream_name); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($enrollmentStatus !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Enrollment Status</div>
                                <div class="value"><?php echo h_g(ucfirst($enrollmentStatus)); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($enrollmentDate !== ''): ?>
                            <div class="info-cell">
                                <div class="label">Enrolled Since</div>
                                <div class="value"><?php
                                                    try {
                                                        echo h_g((new DateTime($enrollmentDate))->format('M j, Y'));
                                                    } catch (Exception $e) {
                                                        echo h_g($enrollmentDate);
                                                    }
                                                    ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($primaryPhone !== '' || $secondaryPhone !== '' || $email !== '' || $address !== '' || $townCity !== '' || $district !== '' || $region !== ''): ?>
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-address-card me-2 text-primary"></i>Contact Details</h6>
                    </div>
                    <div class="card-body-custom">
                        <?php if ($primaryPhone !== ''): ?>
                            <div class="contact-row">
                                <div class="label">Primary phone</div>
                                <div class="value"><?php echo h_g($primaryPhone); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($secondaryPhone !== ''): ?>
                            <div class="contact-row">
                                <div class="label">Alternate phone</div>
                                <div class="value"><?php echo h_g($secondaryPhone); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($email !== ''): ?>
                            <div class="contact-row">
                                <div class="label">Email</div>
                                <div class="value"><?php echo h_g($email); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($address !== '' || $townCity !== '' || $district !== '' || $region !== ''): ?>
                            <div class="contact-row">
                                <div class="label">Address</div>
                                <div class="value">
                                    <?php
                                    $parts = array_filter([$address, $townCity, $district, $region]);
                                    echo h_g(implode(', ', $parts));
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card-custom">
                <div class="card-header-custom">
                    <h6><i class="fas fa-th-large me-2 text-primary"></i>Records</h6>
                </div>
                <div class="card-body-custom">
                    <div class="link-grid">
                        <?php
                        $sid = (int)$student['id'];
                        $links = [
                            ['attendance', 'Attendance',    'fa-calendar-check', 'blue',   'Daily attendance and term summaries'],
                            ['discipline', 'Discipline',    'fa-gavel',          'orange', 'Incidents and their resolution'],
                            ['behaviour',  'Behaviour',     'fa-star-half-alt',  'purple', 'Behaviour rating and conduct'],
                            ['results',    'Results',       'fa-poll',           'green',  'Subject results per term'],
                            ['reports',    'Reports',       'fa-file-pdf',       'teal',   'Report cards and transcripts'],
                        ];
                        foreach ($links as $l):
                            $href = '/platform/student/' . $l[0] . '.php?student_id=' . $sid;
                        ?>
                            <a href="<?php echo h_g($href); ?>" class="link-card">
                                <div class="lc-icon <?php echo h_g($l[3]); ?>">
                                    <i class="fas <?php echo h_g($l[2]); ?>"></i>
                                </div>
                                <div class="lc-label"><?php echo h_g($l[1]); ?></div>
                                <div class="lc-desc"><?php echo h_g($l[4]); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>