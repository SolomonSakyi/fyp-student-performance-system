<?php

/**
 * Register Tenant - Create new tenant with existing subscription plans
 * 
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 2.2
 * @filepath public/platform/tenants/register.php
 *
 * v2.2 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF]:
 *   Platform-tenants sweep, X-1 in full, plus sidebar
 *   reconciliation, plus the CSRF gate on the create POST, plus
 *   two repairs.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 9 items across
 *   4 groups (Main: Dashboard, Tenants, Users; Institution: Schools,
 *   Campuses; Management: Subscriptions; System: Audit Logs,
 *   Monitoring, Settings), brand heading "EduTrack", width 260px —
 *   is replaced by a single require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   including an Approvals item in the Main group, brand heading
 *   "Student 360", plus the mobile toggle, the sidebar footer, and
 *   the toggleSidebar() JS.
 *
 *   $currentPage stays 'tenants' so the Tenants item in the
 *   canonical partial is marked active on this page.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS.
 *
 *   The inline toggleSidebar() function, the inline click-outside
 *   handler, and the inline resize handler are removed, because the
 *   partial provides them. The page's logout(), loadUserInfo(),
 *   the two password toggles, the plan-details display, the
 *   tenant-code preview, and the field-validation JS are kept —
 *   they are page-scoped and do not conflict with the partial.
 *
 *   CSRF gate. verify_csrf() is called at the top of the POST
 *   handling, and a hidden csrf_token input is added inside the
 *   registerForm. Prior to this change the create POST wrote up to
 *   six rows across five tables — including a platform_users row
 *   with is_tenant_admin = 1 — with no CSRF check. The verify_csrf()
 *   call matches the project convention used by the guardian
 *   requests.php v1.1.2, the tenant notifications.php v2.6, and
 *   public/platform/approvals/approve.php v1.0.
 *
 *   Repairs:
 *     - $protocol and $host removed. They were computed on lines
 *       40-41 but read by no line in the current file body.
 *     - Success-message key standardised. The handler previously
 *       wrote $_SESSION['success_message'] on success; the list
 *       page public/platform/tenants/index.php reads
 *       $_SESSION['success']. The mismatch meant the success
 *       message was written and never displayed. The key is now
 *       'success', matching the reader.
 *
 *   Open items on the record (NOT changed by this sweep):
 *     - The client-side "auto-generated tenant code" preview is
 *       cosmetic. The JS writes a random 3-digit suffix into the
 *       hidden tenant_code input, but the POST handler ignores
 *       $_POST['tenant_code'] entirely and calls
 *       generateTenantCode() against the DB. The code shown in the
 *       form is not the code the server uses.
 *     - The subscription_plans auto-seed (four default rows inserted
 *       when the table is empty) runs outside the create
 *       transaction. It is on the record; whether it should remain
 *       is a product decision.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 *
 * v2.1 changes [SCHOOL-IDENTITY]:
 *  - New optional "Primary Domain" field in Basic Information.
 *  - On tenant creation, insert one schools row (name = tenant_name,
 *    code = tenant_code) inside the same transaction.
 *  - On tenant creation, insert one tenant_domains row pointing at
 *    that school. If a custom domain was entered, insert two rows:
 *    the custom domain (is_verified = 0) and a derived subdomain
 *    (is_verified = 1) as a login fallback. If the field is blank,
 *    insert only the derived subdomain.
 *  - Success message now names the school and the primary domain.
 *  - Every other query, field, validation and redirect is unchanged
 *    from v2.0.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK - Super Admin Only
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Register Tenant - Student 360 Platform';
$currentPage = 'tenants';

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 3);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// UUID V4 GENERATOR
// =============================================
function generateUUID(): string
{
    try {
        $data = random_bytes(16);
    } catch (Throwable $e) {
        $data = '';
        for ($i = 0; $i < 16; $i++) {
            $data .= chr(mt_rand(0, 255));
        }
    }

    $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(substr($data, 0, 4)),
        bin2hex(substr($data, 4, 2)),
        bin2hex(substr($data, 6, 2)),
        bin2hex(substr($data, 8, 2)),
        bin2hex(substr($data, 10, 6))
    );
}

// =============================================
// UNIQUE TENANT UUID GENERATOR
// =============================================
function generateUniqueTenantUUID($db): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $uuid = generateUUID();

        $exists = $db->getValue(
            "SELECT COUNT(*) FROM tenants WHERE uuid = ?",
            [$uuid]
        );

        if ((int)$exists === 0) {
            return $uuid;
        }
    }

    throw new RuntimeException('Unable to generate a unique tenant UUID. Please try again.');
}

// =============================================
// TENANT CODE GENERATOR
// =============================================
function generateTenantCode($tenantName, $db)
{
    $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $tenantName), 0, 3));

    while (strlen($prefix) < 3) {
        $prefix .= 'X';
    }

    $maxCode = $db->getValue(
        "SELECT MAX(tenant_code) FROM tenants WHERE tenant_code LIKE ? AND (deleted_at IS NULL OR deleted_at = '')",
        [$prefix . '-%']
    );

    if ($maxCode) {
        $parts = explode('-', $maxCode);
        $lastNumber = isset($parts[1]) ? (int)$parts[1] : 0;
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . '-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
}

// =============================================
// [SCHOOL-IDENTITY] DOMAIN NORMALISER
// =============================================
// Accepts whatever the platform admin typed and reduces it to a
// bare hostname: no scheme, no path, no port, lowercased.
// Returns an empty string when the input is blank.
// =============================================
function normaliseDomainInput(string $raw): string
{
    $d = trim($raw);
    if ($d === '') return '';
    $d = preg_replace('#^https?://#i', '', $d);
    if (($pos = strpos($d, '/')) !== false) {
        $d = substr($d, 0, $pos);
    }
    if (($pos = strpos($d, ':')) !== false) {
        $d = substr($d, 0, $pos);
    }
    return strtolower($d);
}

// =============================================
// [SCHOOL-IDENTITY] DERIVED SUBDOMAIN
// =============================================
// Mirrors DomainManagement::registerSubdomain(). Produces the
// fallback URL a freshly registered tenant can log in at before
// any custom domain is verified.
// =============================================
function deriveSubdomain(string $tenantCode): string
{
    return strtolower(str_replace('-', '', $tenantCode)) . '.edutrack.local';
}

// =============================================
// GET EXISTING SUBSCRIPTION PLANS
// =============================================
$subscriptionPlans = $db->fetchAll(
    "SELECT id, plan_name, plan_code, price, currency, 
            max_students, max_staff, max_campuses, max_schools,
            max_storage_mb, max_api_calls, max_ai_requests,
            sms_balance, email_balance, billing_cycle, description
     FROM subscription_plans 
     WHERE is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')
     ORDER BY price ASC"
);

// If no plans exist, create default ones
if (empty($subscriptionPlans)) {
    $db->execute(
        "INSERT INTO subscription_plans (plan_name, plan_code, description, price, currency, 
         max_students, max_staff, max_campuses, max_schools, max_storage_mb,
         sms_balance, email_balance, is_active, billing_cycle, status, created_at, updated_at)
         VALUES 
         ('Free', 'FREE', 'For small schools getting started', 0, 'GHS', 50, 10, 1, 1, 100, 10, 10, 1, 'monthly', 'active', NOW(), NOW()),
         ('Basic', 'BASIC', 'For growing schools', 29.99, 'GHS', 200, 30, 3, 2, 500, 50, 50, 1, 'monthly', 'active', NOW(), NOW()),
         ('Professional', 'PRO', 'For established schools', 49.99, 'GHS', 500, 100, 10, 5, 2000, 200, 200, 1, 'monthly', 'active', NOW(), NOW()),
         ('Enterprise', 'ENT', 'For large institutions', 99.99, 'GHS', 99999, 99999, 99999, 99999, 10000, 1000, 1000, 1, 'monthly', 'active', NOW(), NOW())"
    );

    $subscriptionPlans = $db->fetchAll(
        "SELECT id, plan_name, plan_code, price, currency, 
                max_students, max_staff, max_campuses, max_schools,
                max_storage_mb, max_api_calls, max_ai_requests,
                sms_balance, email_balance, billing_cycle, description
         FROM subscription_plans 
         WHERE is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')
         ORDER BY price ASC"
    );
}

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$errors = [];
$formData = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // =============================================
    // CSRF GATE
    // =============================================
    if (function_exists('verify_csrf')) {
        verify_csrf();
    } elseif (class_exists('Security')) {
        Security::verifyCsrf();
    }

    $formData = $_POST;

    // Validate required fields
    $required = [
        'tenant_name' => 'Tenant name',
        'tenant_email' => 'Tenant email',
        'admin_first_name' => 'Admin first name',
        'admin_last_name' => 'Admin last name',
        'admin_username' => 'Admin username',
        'admin_password' => 'Admin password',
        'admin_password_confirm' => 'Password confirmation',
        'subscription_plan_id' => 'Subscription plan'
    ];

    foreach ($required as $field => $label) {
        if (!isset($formData[$field]) || trim((string)$formData[$field]) === '') {
            $errors[] = "$label is required";
        }
    }

    // Validate email format
    if (!empty($formData['tenant_email']) && !filter_var($formData['tenant_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid tenant email address';
    }

    if (!empty($formData['admin_email']) && !filter_var($formData['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid admin email address';
    }

    // Validate tenant name length
    if (!empty($formData['tenant_name']) && mb_strlen(trim($formData['tenant_name'])) > 100) {
        $errors[] = 'Tenant name cannot exceed 100 characters.';
    }

    // Check if tenant email already exists
    if (!empty($formData['tenant_email'])) {
        $existingEmail = $db->getValue(
            "SELECT COUNT(*) FROM tenants WHERE email = ? AND (deleted_at IS NULL OR deleted_at = '')",
            [trim($formData['tenant_email'])]
        );
        if ((int)$existingEmail > 0) {
            $errors[] = 'Tenant email already exists. Please use a different email.';
        }
    }

    // Check if admin username already exists
    if (!empty($formData['admin_username'])) {
        $existing = $db->getValue(
            "SELECT COUNT(*) FROM platform_users WHERE username = ? AND (deleted_at IS NULL OR deleted_at = '')",
            [trim($formData['admin_username'])]
        );
        if ((int)$existing > 0) {
            $errors[] = 'Admin username already exists. Please choose a different one.';
        }
    }

    // Check if admin email already exists
    $effectiveAdminEmail = !empty($formData['admin_email'])
        ? trim($formData['admin_email'])
        : trim($formData['tenant_email'] ?? '');

    if (!empty($effectiveAdminEmail)) {
        $existing = $db->getValue(
            "SELECT COUNT(*) FROM platform_users WHERE email = ? AND (deleted_at IS NULL OR deleted_at = '')",
            [$effectiveAdminEmail]
        );
        if ((int)$existing > 0) {
            $errors[] = 'Admin email already exists. Please use a different one.';
        }
    }

    // Password confirmation check
    $adminPassword = (string)($formData['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($formData['admin_password_confirm'] ?? '');

    if ($adminPassword !== '') {
        if (strlen($adminPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }
    }

    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Validate selected subscription plan exists and is active
    $selectedPlanId = isset($formData['subscription_plan_id'])
        ? (int)$formData['subscription_plan_id']
        : 0;

    if ($selectedPlanId > 0) {
        $planExists = $db->getValue(
            "SELECT COUNT(*) FROM subscription_plans
             WHERE id = ? AND is_active = 1
             AND (deleted_at IS NULL OR deleted_at = '')",
            [$selectedPlanId]
        );

        if ((int)$planExists === 0) {
            $errors[] = 'The selected subscription plan is not available.';
        }
    }

    if (empty($errors)) {
        $transactionStarted = false;

        try {
            $db->beginTransaction();
            $transactionStarted = true;

            // =============================================
            // GENERATE TENANT CODE
            // =============================================
            $tenantCode = generateTenantCode(
                trim($formData['tenant_name']),
                $db
            );

            // =============================================
            // GENERATE UNIQUE TENANT UUID
            // =============================================
            $tenantUuid = generateUniqueTenantUUID($db);

            // =============================================
            // CREATE TENANT
            // =============================================
            $db->execute(
                "INSERT INTO tenants (
                    uuid,
                    tenant_name,
                    tenant_code,
                    email,
                    phone,
                    address,
                    subscription_plan_id,
                    status,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $tenantUuid,
                    trim($formData['tenant_name']),
                    $tenantCode,
                    trim($formData['tenant_email']),
                    trim($formData['tenant_phone'] ?? ''),
                    trim($formData['address'] ?? ''),
                    $selectedPlanId,
                    'active'
                ]
            );

            // =============================================
            // GET THE NEW TENANT ID
            // =============================================
            $tenantId = (int)$db->getValue("SELECT LAST_INSERT_ID()");

            if ($tenantId <= 0) {
                throw new RuntimeException(
                    'Tenant was inserted, but the new tenant ID could not be retrieved.'
                );
            }

            // =============================================
            // VERIFY THE UUID WAS STORED CORRECTLY
            // =============================================
            $storedTenantUuid = $db->getValue(
                "SELECT uuid FROM tenants WHERE id = ? LIMIT 1",
                [$tenantId]
            );

            if ($storedTenantUuid !== $tenantUuid) {
                throw new RuntimeException(
                    'Tenant UUID verification failed. The tenant creation was cancelled.'
                );
            }

            // =============================================
            // [SCHOOL-IDENTITY] CREATE THE SCHOOL ROW
            // =============================================
            // One school per tenant. school_name defaults to the
            // tenant name; the tenant admin may rename it later via
            // the school settings page. school_code mirrors the
            // tenant_code so the two stay in step.
            // =============================================
            $schoolUuid = generateUUID();
            $db->execute(
                "INSERT INTO schools (
                    uuid,
                    tenant_id,
                    school_name,
                    school_code,
                    status,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())",
                [
                    $schoolUuid,
                    $tenantId,
                    trim($formData['tenant_name']),
                    $tenantCode
                ]
            );

            $schoolId = (int)$db->getValue("SELECT LAST_INSERT_ID()");

            if ($schoolId <= 0) {
                throw new RuntimeException(
                    'School row was inserted, but the new school ID could not be retrieved.'
                );
            }

            // =============================================
            // [SCHOOL-IDENTITY] CREATE DOMAIN ROW(S)
            // =============================================
            // A derived subdomain is always created so the tenant
            // admin can log in immediately. If the platform admin
            // entered a custom domain, that is added in addition,
            // with is_verified = 0 — it will not resolve until a
            // verification step is implemented.
            // =============================================
            $requestedDomain = normaliseDomainInput((string)($formData['domain_name'] ?? ''));
            $derivedSubdomain = deriveSubdomain($tenantCode);

            if ($requestedDomain === '') {
                // Blank field: only the derived subdomain is created,
                // and it is the primary.
                $db->execute(
                    "INSERT INTO tenant_domains (
                        tenant_id,
                        school_id,
                        domain_name,
                        is_primary,
                        is_custom,
                        is_verified,
                        is_active,
                        created_at
                    ) VALUES (?, ?, ?, 1, 0, 1, 1, NOW())",
                    [$tenantId, $schoolId, $derivedSubdomain]
                );
            } else {
                // Custom domain entered: create the custom row
                // (primary, unverified) and the derived subdomain
                // (secondary, verified, login fallback).
                $db->execute(
                    "INSERT INTO tenant_domains (
                        tenant_id,
                        school_id,
                        domain_name,
                        is_primary,
                        is_custom,
                        is_verified,
                        is_active,
                        created_at
                    ) VALUES (?, ?, ?, 1, 1, 0, 1, NOW())",
                    [$tenantId, $schoolId, $requestedDomain]
                );

                $db->execute(
                    "INSERT INTO tenant_domains (
                        tenant_id,
                        school_id,
                        domain_name,
                        is_primary,
                        is_custom,
                        is_verified,
                        is_active,
                        created_at
                    ) VALUES (?, ?, ?, 0, 0, 1, 1, NOW())",
                    [$tenantId, $schoolId, $derivedSubdomain]
                );
            }

            // =============================================
            // CREATE TENANT SUBSCRIPTION
            // =============================================
            $db->execute(
                "INSERT INTO tenant_subscriptions (
                    tenant_id,
                    plan_id,
                    status,
                    started_at,
                    created_at
                ) VALUES (?, ?, 'active', NOW(), NOW())",
                [$tenantId, $selectedPlanId]
            );

            // =============================================
            // CREATE TENANT ADMIN USER
            // =============================================
            $hashedPassword = password_hash(
                $adminPassword,
                PASSWORD_DEFAULT
            );

            if ($hashedPassword === false) {
                throw new RuntimeException('Unable to securely hash the administrator password.');
            }

            $db->execute(
                "INSERT INTO platform_users (
                    tenant_id,
                    username,
                    email,
                    password_hash,
                    first_name,
                    last_name,
                    phone,
                    is_super_admin,
                    is_tenant_admin,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $tenantId,
                    trim($formData['admin_username']),
                    $effectiveAdminEmail,
                    $hashedPassword,
                    trim($formData['admin_first_name']),
                    trim($formData['admin_last_name']),
                    trim($formData['admin_phone'] ?? ''),
                    0,
                    1,
                    1
                ]
            );

            // =============================================
            // COMMIT ALL TENANT CREATION OPERATIONS
            // =============================================
            $db->commit();

            // =============================================
            // [SCHOOL-IDENTITY] SUCCESS MESSAGE
            // =============================================
            $primaryDomainForMessage = $requestedDomain !== '' ? $requestedDomain : $derivedSubdomain;
            $_SESSION['success'] =
                'Tenant created successfully! Code: ' . $tenantCode .
                '. School and domain created. Primary domain: ' . $primaryDomainForMessage . '.';

            header('Location: /platform/tenants/index.php');
            exit;
        } catch (Throwable $e) {
            try {
                if ($transactionStarted) {
                    $db->rollBack();
                }
            } catch (Throwable $rollbackException) {
                error_log('Tenant creation rollback error: ' . $rollbackException->getMessage());
            }

            error_log('Create tenant error: ' . $e->getMessage());

            if (
                strpos($e->getMessage(), 'Duplicate entry') !== false &&
                strpos($e->getMessage(), "for key 'uuid'") !== false
            ) {
                $errors[] = 'A tenant UUID collision occurred. Please submit the form again.';
            } elseif (
                strpos($e->getMessage(), 'Duplicate entry') !== false &&
                strpos($e->getMessage(), "uk_domain_name") !== false
            ) {
                $errors[] = 'The domain you entered is already registered. Please use a different domain.';
            } else {
                $errors[] = 'Error creating tenant: ' . $e->getMessage();
            }
        }
    }
}

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

// Variables read by the platform sidebar partial
$pendingApprovals = 0;

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// CSRF token for the create form
$csrfToken = '';
if (function_exists('csrf_token')) {
    $csrfToken = csrf_token();
} elseif (class_exists('Security') && method_exists('Security', 'csrfToken')) {
    $csrfToken = Security::csrfToken();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ================================================ */
        /* GLOBAL RESET */
        /* ================================================ */
        * {
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

        /* ================================================ */
        /* SIDEBAR */
        /* ================================================ */
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
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
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
            padding: 0 0 20px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
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
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 14px;
        }

        .top-bar .header-actions .btn i {
            margin-right: 6px;
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

        /* ================================================ */
        /* FORM CARD */
        /* ================================================ */
        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 24px 24px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f2f5;
        }

        .section-title i {
            color: #4facfe;
            margin-right: 8px;
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
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            max-width: 100%;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 44px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control.is-valid,
        .form-select.is-valid {
            border-color: #28a745;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2328a745' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 40px;
        }

        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23dc3545' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'%3E%3C/circle%3E%3Cline x1='15' y1='9' x2='9' y2='15'%3E%3C/line%3E%3Cline x1='9' y1='9' x2='15' y2='15'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 40px;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .invalid-feedback {
            display: block;
            font-size: 12px;
            color: #dc3545;
            margin-top: 4px;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .mb-2 {
            margin-bottom: 10px;
        }

        .mb-3 {
            margin-bottom: 16px;
        }

        hr {
            border: none;
            border-top: 1px solid #f0f2f5;
            margin: 20px 0;
        }

        /* ================================================ */
        /* PASSWORD WRAPPER */
        /* ================================================ */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.2s;
            z-index: 5;
        }

        .password-toggle:hover {
            color: #4facfe;
        }

        /* ================================================ */
        /* ALERT */
        /* ================================================ */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.3s ease;
            animation: slideDown 0.4s ease;
            margin-bottom: 20px;
        }

        .alert-custom .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            width: 28px;
            text-align: center;
        }

        .alert-custom .alert-content {
            flex: 1;
        }

        .alert-custom .alert-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .alert-custom .alert-message ul {
            margin: 4px 0 0 0;
            padding-left: 18px;
        }

        .alert-custom .btn-close-custom {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 18px;
            transition: opacity 0.2s;
            flex-shrink: 0;
            margin-top: -2px;
        }

        .alert-custom .btn-close-custom:hover {
            opacity: 1;
        }

        .alert-custom.alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
        }

        .alert-custom.alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ================================================ */
        /* PLAN DETAILS */
        /* ================================================ */
        .plan-details-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 16px 20px;
            border: 1px solid #e9ecef;
            margin-top: 8px;
        }

        .plan-details-box .plan-feature {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 13px;
        }

        .plan-details-box .plan-feature .label {
            color: #6c757d;
        }

        .plan-details-box .plan-feature .value {
            font-weight: 600;
            color: #1a1a2e;
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
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

            .sidebar .sidebar-footer .user-info span {
                display: none;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: center;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: none;
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

            .sidebar .sidebar-footer .user-info span {
                display: inline;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: flex-start;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: inline;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 20px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 16px 16px;
            }

            .form-control,
            .form-select {
                font-size: 14px;
                padding: 8px 14px;
                height: 44px;
            }

            .form-label {
                font-size: 13px;
            }

            [class*="col-"] {
                padding-left: 6px;
                padding-right: 6px;
            }

            .row {
                margin: 0 -6px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 10px 12px 20px;
                padding-top: 65px;
            }

            .top-bar .page-title h1 {
                font-size: 18px;
            }

            .top-bar .page-title p {
                font-size: 11px;
            }

            .top-bar .header-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .card-custom .card-body-custom {
                padding: 12px 12px;
            }

            .form-control,
            .form-select {
                font-size: 13px;
                padding: 6px 12px;
                height: 40px;
            }

            .btn {
                font-size: 12px;
                padding: 6px 14px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php require_once $projectRoot . '/app/views/partials/platform-sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-plus me-2"></i>Register Tenant</h1>
                        <p>Create a new tenant organization with all associated information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenants/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Tenants
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if (!empty($errors)): ?>
                        <div class="alert-custom alert-danger">
                            <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Please fix the following errors:</div>
                                <div class="alert-message">
                                    <ul>
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Register Form -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-building me-2 text-primary"></i>Tenant Registration Form</h6>
                        <span class="text-muted small">Fields marked with <span class="text-danger">*</span> are required</span>
                    </div>
                    <div class="card-body-custom">
                        <form id="registerForm" method="POST" action="" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <!-- ============================================ -->
                            <!-- SECTION 1: BASIC INFORMATION -->
                            <!-- ============================================ -->
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i> Basic Information
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="tenantName">Tenant Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="tenantName" name="tenant_name"
                                            placeholder="Enter tenant name" required
                                            value="<?php echo htmlspecialchars($formData['tenant_name'] ?? ''); ?>">
                                        <div class="form-text">Tenant code will be auto-generated from this name</div>
                                        <div class="invalid-feedback">Please enter a tenant name</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label">Tenant Code</label>
                                        <div class="form-control" style="background:#f8f9fa; color:#6c757d; cursor:not-allowed;" readonly>
                                            <span id="generatedCodePreview">Auto-generated</span>
                                        </div>
                                        <div class="form-text">Automatically generated from the tenant name</div>
                                        <input type="hidden" id="tenantCode" name="tenant_code">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="tenantEmail">Email Address <span class="required">*</span></label>
                                        <input type="email" class="form-control" id="tenantEmail" name="tenant_email"
                                            placeholder="tenant@example.com" required
                                            value="<?php echo htmlspecialchars($formData['tenant_email'] ?? ''); ?>">
                                        <div class="invalid-feedback">Please enter a valid email address</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="tenantPhone">Phone Number</label>
                                        <input type="tel" class="form-control" id="tenantPhone" name="tenant_phone"
                                            placeholder="+233 XX XXX XXXX"
                                            value="<?php echo htmlspecialchars($formData['tenant_phone'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="address">Address</label>
                                        <input type="text" class="form-control" id="address" name="address"
                                            placeholder="Street address"
                                            value="<?php echo htmlspecialchars($formData['address'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- [SCHOOL-IDENTITY] Domain field -->
                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="domainName">Primary Domain (optional)</label>
                                        <input type="text" class="form-control" id="domainName" name="domain_name"
                                            placeholder="edutrack.local"
                                            value="<?php echo htmlspecialchars($formData['domain_name'] ?? ''); ?>">
                                        <div class="form-text">Leave blank to use a system-generated subdomain. Enter a custom domain to use your own URL.</div>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- SECTION 2: SUBSCRIPTION PLAN -->
                            <!-- ============================================ -->
                            <div class="section-title">
                                <i class="fas fa-crown"></i> Subscription Plan
                            </div>

                            <?php if (empty($subscriptionPlans)): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    No subscription plans available.
                                    <a href="/platform/subscriptions/create.php" class="alert-link">Create a plan first</a>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label" for="subscriptionPlan">Select Plan <span class="required">*</span></label>
                                            <select class="form-select" id="subscriptionPlan" name="subscription_plan_id" required>
                                                <option value="">-- Select a Plan --</option>
                                                <?php foreach ($subscriptionPlans as $plan): ?>
                                                    <option value="<?php echo $plan['id']; ?>"
                                                        data-plan-name="<?php echo htmlspecialchars($plan['plan_name']); ?>"
                                                        data-plan-price="<?php echo $plan['price']; ?>"
                                                        data-plan-currency="<?php echo $plan['currency']; ?>"
                                                        data-plan-billing="<?php echo $plan['billing_cycle']; ?>"
                                                        data-max-students="<?php echo $plan['max_students']; ?>"
                                                        data-max-staff="<?php echo $plan['max_staff']; ?>"
                                                        data-max-campuses="<?php echo $plan['max_campuses']; ?>"
                                                        data-max-schools="<?php echo $plan['max_schools']; ?>"
                                                        data-max-storage="<?php echo $plan['max_storage_mb']; ?>"
                                                        data-sms-balance="<?php echo $plan['sms_balance']; ?>"
                                                        data-email-balance="<?php echo $plan['email_balance']; ?>"
                                                        <?php echo (isset($formData['subscription_plan_id']) && $formData['subscription_plan_id'] == $plan['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($plan['plan_name']); ?>
                                                        (<?php echo $plan['price'] == 0 ? 'Free' : $plan['currency'] . ' ' . number_format($plan['price'], 2); ?>)
                                                        - <?php echo ucfirst($plan['billing_cycle'] ?? 'monthly'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a subscription plan</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="mb-2">
                                            <label class="form-label" for="paymentMethod">Payment Method</label>
                                            <select class="form-select" id="paymentMethod" name="payment_method">
                                                <option value="">Select Payment Method</option>
                                                <option value="bank_transfer" <?php echo (isset($formData['payment_method']) && $formData['payment_method'] == 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                                <option value="mobile_money" <?php echo (isset($formData['payment_method']) && $formData['payment_method'] == 'mobile_money') ? 'selected' : ''; ?>>Mobile Money</option>
                                                <option value="card" <?php echo (isset($formData['payment_method']) && $formData['payment_method'] == 'card') ? 'selected' : ''; ?>>Credit/Debit Card</option>
                                                <option value="invoice" <?php echo (isset($formData['payment_method']) && $formData['payment_method'] == 'invoice') ? 'selected' : ''; ?>>Invoice</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Plan Details -->
                                <div id="planDetails" style="display: none;">
                                    <div class="plan-details-box">
                                        <div class="row">
                                            <div class="col-md-6 col-12">
                                                <div class="plan-feature">
                                                    <span class="label">Plan Name</span>
                                                    <span class="value" id="planName">-</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <span class="label">Price</span>
                                                    <span class="value" id="planPrice">-</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <span class="label">Billing Cycle</span>
                                                    <span class="value" id="planBilling">-</span>
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-12">
                                                <div class="plan-feature">
                                                    <span class="label">Max Schools</span>
                                                    <span class="value" id="planSchools">-</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <span class="label">Max Staff</span>
                                                    <span class="value" id="planStaff">-</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <span class="label">Max Students</span>
                                                    <span class="value" id="planStudents">-</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <span class="label">Storage</span>
                                                    <span class="value" id="planStorage">-</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <hr>

                            <!-- ============================================ -->
                            <!-- SECTION 3: ADMINISTRATOR USER -->
                            <!-- ============================================ -->
                            <div class="section-title">
                                <i class="fas fa-user-shield"></i> Administrator User
                            </div>
                            <div class="row">
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminFirstName">First Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="adminFirstName" name="admin_first_name"
                                            placeholder="First name" required
                                            value="<?php echo htmlspecialchars($formData['admin_first_name'] ?? ''); ?>">
                                        <div class="invalid-feedback">First name is required</div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminLastName">Last Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="adminLastName" name="admin_last_name"
                                            placeholder="Last name" required
                                            value="<?php echo htmlspecialchars($formData['admin_last_name'] ?? ''); ?>">
                                        <div class="invalid-feedback">Last name is required</div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminUsername">Username <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="adminUsername" name="admin_username"
                                            placeholder="Choose a username" required
                                            value="<?php echo htmlspecialchars($formData['admin_username'] ?? ''); ?>">
                                        <div class="form-text">Must be unique across the platform</div>
                                        <div class="invalid-feedback">Username is required</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminEmail">Email Address</label>
                                        <input type="email" class="form-control" id="adminEmail" name="admin_email"
                                            placeholder="admin@example.com"
                                            value="<?php echo htmlspecialchars($formData['admin_email'] ?? ''); ?>">
                                        <div class="form-text">If left blank, tenant email will be used</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminPhone">Phone Number</label>
                                        <input type="tel" class="form-control" id="adminPhone" name="admin_phone"
                                            placeholder="+233 XX XXX XXXX"
                                            value="<?php echo htmlspecialchars($formData['admin_phone'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminPassword">Password <span class="required">*</span></label>
                                        <div class="password-wrapper">
                                            <input type="password" class="form-control" id="adminPassword" name="admin_password"
                                                placeholder="Minimum 8 characters" required minlength="8">
                                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">Password must be at least 8 characters</div>
                                        <div class="invalid-feedback">Password is required</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="adminPasswordConfirm">Confirm Password <span class="required">*</span></label>
                                        <div class="password-wrapper">
                                            <input type="password" class="form-control" id="adminPasswordConfirm" name="admin_password_confirm"
                                                placeholder="Confirm password" required minlength="8">
                                            <button type="button" class="password-toggle" id="togglePasswordConfirm" aria-label="Toggle password visibility">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="invalid-feedback">Please confirm your password</div>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- FORM ACTIONS -->
                            <!-- ============================================ -->
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <a href="/platform/tenants/index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Create Tenant
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

        // ================================================
        // LOAD USER INFO
        // ================================================
        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        // ================================================
        // PASSWORD TOGGLE
        // ================================================
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('adminPassword');
            const icon = this.querySelector('i');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        document.getElementById('togglePasswordConfirm').addEventListener('click', function() {
            const passwordInput = document.getElementById('adminPasswordConfirm');
            const icon = this.querySelector('i');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // ================================================
        // PLAN DETAILS DISPLAY
        // ================================================
        document.getElementById('subscriptionPlan').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const detailsDiv = document.getElementById('planDetails');

            if (this.value === '') {
                detailsDiv.style.display = 'none';
                return;
            }

            const planName = selectedOption.dataset.planName || 'N/A';
            const planPrice = selectedOption.dataset.planPrice || 0;
            const planCurrency = selectedOption.dataset.planCurrency || 'GHS';
            const planBilling = selectedOption.dataset.planBilling || 'monthly';
            const maxSchools = selectedOption.dataset.maxSchools || 0;
            const maxStaff = selectedOption.dataset.maxStaff || 0;
            const maxStudents = selectedOption.dataset.maxStudents || 0;
            const maxStorage = selectedOption.dataset.maxStorage || 0;

            document.getElementById('planName').textContent = planName;
            document.getElementById('planPrice').textContent = planPrice == 0 ? 'Free' : planCurrency + ' ' + parseFloat(
                planPrice).toFixed(2);
            document.getElementById('planBilling').textContent = planBilling.charAt(0).toUpperCase() + planBilling.slice(1);
            document.getElementById('planSchools').textContent = maxSchools > 0 ? maxSchools : '∞';
            document.getElementById('planStaff').textContent = maxStaff > 0 ? maxStaff : '∞';
            document.getElementById('planStudents').textContent = maxStudents > 0 ? maxStudents : '∞';
            document.getElementById('planStorage').textContent = maxStorage > 0 ? maxStorage + ' MB' : '∞';

            detailsDiv.style.display = 'block';
        });

        // ================================================
        // AUTO-GENERATE TENANT CODE FROM NAME
        // ================================================
        document.getElementById('tenantName').addEventListener('input', function() {
            const name = this.value.trim();
            const previewElement = document.getElementById('generatedCodePreview');

            if (name.length >= 3) {
                let prefix = name.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase();
                while (prefix.length < 3) {
                    prefix += 'X';
                }
                const randomNum = String(Math.floor(Math.random() * 900) + 100);
                const generatedCode = prefix + '-' + randomNum;
                previewElement.textContent = generatedCode;
                document.getElementById('tenantCode').value = generatedCode;
            } else {
                previewElement.textContent = 'Type at least 3 letters';
                document.getElementById('tenantCode').value = '';
            }
        });

        // ================================================
        // FORM VALIDATION
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            const form = document.getElementById('registerForm');
            const submitBtn = document.getElementById('submitBtn');

            const requiredInputs = form.querySelectorAll('input[required], select[required]');
            requiredInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    validateField(this);
                });

                if (input.tagName === 'INPUT') {
                    input.addEventListener('input', function() {
                        if (this.value.trim().length > 0) {
                            this.classList.remove('is-invalid');
                            this.classList.add('is-valid');
                        } else {
                            this.classList.remove('is-valid');
                            this.classList.add('is-invalid');
                        }
                    });
                }
            });

            const passwordInput = document.getElementById('adminPassword');
            const confirmInput = document.getElementById('adminPasswordConfirm');

            if (passwordInput && confirmInput) {
                confirmInput.addEventListener('input', function() {
                    if (this.value === passwordInput.value && this.value.length >= 8) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                });

                passwordInput.addEventListener('input', function() {
                    if (this.value.length >= 8) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                });
            }

            function validateField(input) {
                const value = input.value.trim();
                const isRequired = input.hasAttribute('required');

                if (isRequired && value === '') {
                    input.classList.remove('is-valid');
                    input.classList.add('is-invalid');
                    return false;
                }

                input.classList.remove('is-invalid');
                input.classList.add('is-valid');
                return true;
            }

            form.addEventListener('submit', function(e) {
                let isValid = true;
                const requiredInputs = form.querySelectorAll('input[required], select[required]');

                requiredInputs.forEach(input => {
                    if (!validateField(input)) {
                        isValid = false;
                    }
                });

                if (passwordInput && confirmInput) {
                    if (passwordInput.value !== confirmInput.value || passwordInput.value.length < 8) {
                        confirmInput.classList.remove('is-valid');
                        confirmInput.classList.add('is-invalid');
                        isValid = false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    const firstInvalid = form.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                        firstInvalid.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Creating Tenant...';
            });
        });
    </script>
</body>

</html>