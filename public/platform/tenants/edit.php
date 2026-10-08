<?php

/**
 * Edit Tenant - Edit tenant details with all fields
 * 
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 2.1
 * @filepath public/platform/tenants/edit.php
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF + AUTH]:
 *   Platform-tenants sweep, X-1 in full, plus sidebar
 *   reconciliation, plus the super-admin auth guard, plus the CSRF
 *   gate on the edit POST, plus four repairs, plus two structural
 *   markup corrections.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   AUTH: the is_super_admin guard is added immediately after the
 *   logged_in guard. Prior to this change, this file — like
 *   tenants/view.php before its sweep — carried only the logged_in
 *   guard. Any authenticated user who knew a tenant id could view
 *   the edit form and POST updates to it. The tenant id is a small
 *   enumerable integer passed in the URL. The added guard matches
 *   tenants/index.php, tenants/register.php, and tenants/view.php.
 *
 *   CSRF gate. verify_csrf() is called at the top of the POST
 *   handling, and a hidden csrf_token input is added inside the
 *   edit form. Prior to this change the edit POST wrote 26 columns
 *   to the tenants table with no CSRF check. The verify_csrf()
 *   call matches the project convention used by the guardian
 *   requests.php v1.1.2, the tenant notifications.php v2.6,
 *   public/platform/approvals/approve.php v1.0, and the two other
 *   swept tenants/ files.
 *
 *   Sidebar: the inline sidebar this page carried — 9 items across
 *   3 groups (Main: Dashboard, Tenants, Users; Institution: Schools,
 *   Campuses; System: Audit Logs, Monitoring, Settings), brand
 *   heading "EduTrack", width 260px — is replaced by a single
 *   require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   including an Approvals item in the Main group, brand heading
 *   "Student 360", plus the mobile toggle, the sidebar footer, and
 *   the toggleSidebar() JS.
 *
 *   $currentPage stays 'tenants' so the Tenants item in the
 *   canonical partial is marked active on this page.
 *   $pendingApprovals = 0 is set before the partial.
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
 *   autoPopulateLimits(), showAlert(), validateField(), and the two
 *   DOMContentLoaded handlers are kept — they are page-scoped and do
 *   not conflict with the partial.
 *
 *   Repairs:
 *     - logout() redirect standardised from the dynamic
 *       '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php'
 *       form to the relative '/platform/login.php' form. The now-
 *       unused $protocol and $host variables are removed.
 *     - $userFirstName = $_SESSION['first_name'] ?? 'Super'; added,
 *       and $userAvatar derivation changed from
 *       substr($currentUser, 0, 1) to
 *       strtoupper(substr($userFirstName, 0, 1)), matching the other
 *       swept platform pages.
 *     - $pendingApprovals = 0 set before the partial require.
 *     - Two structural markup corrections in the Subscription &
 *       Limits section:
 *         (a) The maxCampuses input carried a malformed min
 *             attribute (min="0>) and a broken surrounding div.
 *             The attribute is now min="0" and the surrounding
 *             div and the following .form-text div are closed.
 *         (b) The row containing maxCampuses / maxSchools had a
 *             stray closing div that pulled maxSchools out of its
 *             row. The two fields are now siblings inside one
 *             .row, each in its own col-md-3.
 *       The corrections make the markup well-formed. No attempt is
 *       made to reconstruct an intended layout that differs from
 *       the closest well-formed interpretation of the pasted
 *       markup.
 *
 *   Open items on the record (NOT changed by this sweep):
 *     - $successMessage = ''; is declared at the top of the POST
 *       handling and never assigned. Dead variable.
 *     - The UPDATE tenants SET … statement is a single write and is
 *       not wrapped in a transaction. A transaction is not strictly
 *       required for a single write; the other swept multi-write
 *       handlers do use one.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Super Admin check - matches tenants/index.php, register.php, view.php
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// Get tenant ID from URL
$tenantId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no tenant ID, redirect to tenants list
if (!$tenantId) {
    header('Location: /platform/tenants/index.php');
    exit;
}

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
// GET TENANT DETAILS
// =============================================
$tenant = $db->fetchOne(
    "SELECT * FROM tenants WHERE id = ? AND deleted_at IS NULL",
    [$tenantId]
);

// If tenant not found, redirect
if (!$tenant) {
    header('Location: /platform/tenants/index.php?error=tenant_not_found');
    exit;
}

// =============================================
// GET SUBSCRIPTION PLANS
// =============================================
$subscriptionPlans = $db->fetchAll(
    "SELECT id, plan_name, plan_code, description, 
            max_students, max_staff, max_campuses, max_schools,
            max_storage_mb, max_api_calls, max_ai_requests,
            sms_balance, email_balance,
            price, currency, billing_cycle, is_active, is_trial, trial_days 
     FROM subscription_plans 
     WHERE (deleted_at IS NULL OR deleted_at = '')
     AND is_active = 1
     ORDER BY price ASC"
);

// =============================================
// COUNTRY LIST
// =============================================
$countries = [
    1 => 'Ghana',
    2 => 'Nigeria',
    3 => 'Kenya',
    4 => 'South Africa',
    5 => 'United Kingdom',
    6 => 'United States',
    7 => 'Canada',
    8 => 'Australia'
];

// =============================================
// LANGUAGES
// =============================================
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'es' => 'Spanish',
    'pt' => 'Portuguese',
    'ar' => 'Arabic',
    'zh' => 'Chinese',
    'hi' => 'Hindi',
    'sw' => 'Swahili',
    'ha' => 'Hausa',
    'yo' => 'Yoruba',
    'ig' => 'Igbo',
    'tw' => 'Twi',
    'ga' => 'Ga',
    'ee' => 'Ewe'
];

// =============================================
// TIMEZONES
// =============================================
$timezones = [
    'UTC' => 'UTC',
    'Africa/Accra' => 'Africa/Accra (GMT+0)',
    'Africa/Lagos' => 'Africa/Lagos (GMT+1)',
    'Africa/Johannesburg' => 'Africa/Johannesburg (GMT+2)',
    'Africa/Nairobi' => 'Africa/Nairobi (GMT+3)',
    'Africa/Cairo' => 'Africa/Cairo (GMT+2)',
    'Europe/London' => 'Europe/London (GMT+0)',
    'Europe/Paris' => 'Europe/Paris (GMT+1)',
    'Europe/Berlin' => 'Europe/Berlin (GMT+1)',
    'America/New_York' => 'America/New_York (GMT-5)',
    'America/Chicago' => 'America/Chicago (GMT-6)',
    'America/Denver' => 'America/Denver (GMT-7)',
    'America/Los_Angeles' => 'America/Los_Angeles (GMT-8)',
    'Asia/Dubai' => 'Asia/Dubai (GMT+4)',
    'Asia/Kolkata' => 'Asia/Kolkata (GMT+5:30)',
    'Asia/Singapore' => 'Asia/Singapore (GMT+8)',
    'Asia/Tokyo' => 'Asia/Tokyo (GMT+9)',
    'Australia/Sydney' => 'Australia/Sydney (GMT+11)'
];

// =============================================
// CURRENCIES
// =============================================
$currencies = [
    'GHS' => 'GHS - Ghana Cedi',
    'NGN' => 'NGN - Nigerian Naira',
    'KES' => 'KES - Kenyan Shilling',
    'ZAR' => 'ZAR - South African Rand',
    'USD' => 'USD - US Dollar',
    'EUR' => 'EUR - Euro',
    'GBP' => 'GBP - British Pound',
    'CAD' => 'CAD - Canadian Dollar',
    'AUD' => 'AUD - Australian Dollar',
    'INR' => 'INR - Indian Rupee',
    'JPY' => 'JPY - Japanese Yen',
    'CNY' => 'CNY - Chinese Yuan'
];

// =============================================
// DATE FORMATS
// =============================================
$dateFormats = [
    'Y-m-d' => 'YYYY-MM-DD (2024-01-15)',
    'd/m/Y' => 'DD/MM/YYYY (15/01/2024)',
    'm/d/Y' => 'MM/DD/YYYY (01/15/2024)',
    'd M Y' => 'DD Mon YYYY (15 Jan 2024)',
    'M d, Y' => 'Mon DD, YYYY (Jan 15, 2024)'
];

// =============================================
// STATUSES
// =============================================
$statuses = [
    'active' => 'Active',
    'pending' => 'Pending',
    'suspended' => 'Suspended',
    'inactive' => 'Inactive'
];

// =============================================
// LICENSE STATUSES
// =============================================
$licenseStatuses = [
    'ACTIVE' => 'Active',
    'TRIAL' => 'Trial',
    'EXPIRED' => 'Expired',
    'SUSPENDED' => 'Suspended',
    'CANCELLED' => 'Cancelled'
];

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$errors = [];
$formData = [];
$successMessage = '';

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
    if (empty($formData['tenant_name'])) {
        $errors[] = 'Tenant name is required';
    }
    if (empty($formData['tenant_code'])) {
        $errors[] = 'Tenant code is required';
    }

    // Validate tenant code format
    if (!empty($formData['tenant_code']) && !preg_match('/^[A-Z0-9\-]{2,20}$/', $formData['tenant_code'])) {
        $errors[] = 'Tenant code must be 2-20 characters, uppercase letters, numbers, and hyphens only';
    }

    if (empty($errors)) {
        try {
            // Prepare data for update
            $updateData = [
                'tenant_name' => trim($formData['tenant_name']),
                'tenant_code' => trim($formData['tenant_code']),
                'legal_name' => trim($formData['legal_name'] ?? ''),
                'institution_type' => $formData['institution_type'] ?? 'school',
                'email' => trim($formData['email'] ?? ''),
                'phone' => trim($formData['phone'] ?? ''),
                'website' => trim($formData['website'] ?? ''),
                'postal_address' => trim($formData['postal_address'] ?? ''),
                'city' => trim($formData['city'] ?? ''),
                'region' => trim($formData['region'] ?? ''),
                'country_id' => !empty($formData['country_id']) ? (int)$formData['country_id'] : null,
                'language' => $formData['language'] ?? 'en',
                'timezone' => $formData['timezone'] ?? 'UTC',
                'currency' => $formData['currency'] ?? 'GHS',
                'date_format' => $formData['date_format'] ?? 'Y-m-d',
                'description' => trim($formData['description'] ?? ''),
                'status' => $formData['status'] ?? 'active',
                'license_status' => $formData['license_status'] ?? 'ACTIVE',
                'license_expires_at' => !empty($formData['license_expires_at']) ? $formData['license_expires_at'] : null,
                'subscription_plan_id' => !empty($formData['subscription_plan_id']) ? (int)$formData['subscription_plan_id'] : null,
                'max_students' => !empty($formData['max_students']) ? (int)$formData['max_students'] : null,
                'max_staff' => !empty($formData['max_staff']) ? (int)$formData['max_staff'] : null,
                'max_campuses' => !empty($formData['max_campuses']) ? (int)$formData['max_campuses'] : null,
                'max_schools' => !empty($formData['max_schools']) ? (int)$formData['max_schools'] : null,
                'max_storage_mb' => !empty($formData['max_storage_mb']) ? (int)$formData['max_storage_mb'] : null,
                'max_api_calls' => !empty($formData['max_api_calls']) ? (int)$formData['max_api_calls'] : null,
                'max_ai_requests' => !empty($formData['max_ai_requests']) ? (int)$formData['max_ai_requests'] : null,
                'sms_balance' => !empty($formData['sms_balance']) ? (int)$formData['sms_balance'] : 0,
                'email_balance' => !empty($formData['email_balance']) ? (int)$formData['email_balance'] : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $updateFields = [];
            $params = [];
            foreach ($updateData as $key => $value) {
                $updateFields[] = "$key = ?";
                $params[] = $value;
            }
            $params[] = $tenantId;
            $sql = "UPDATE tenants SET " . implode(', ', $updateFields) . " WHERE id = ? AND deleted_at IS NULL";
            $result = $db->execute($sql, $params);

            if ($result) {
                $_SESSION['success'] = 'Tenant updated successfully!';
                header('Location: /platform/tenants/view.php?id=' . $tenantId . '&updated=1');
                exit;
            } else {
                $errors[] = 'Failed to update tenant';
            }
        } catch (Exception $e) {
            error_log('Update tenant error: ' . $e->getMessage());
            $errors[] = 'Error updating tenant: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $formData;
        header('Location: /platform/tenants/edit.php?id=' . $tenantId);
        exit;
    }
}

// Get form data from session if validation failed
$formData = $_SESSION['form_data'] ?? [];
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['form_data'], $_SESSION['errors']);

// Merge tenant data with form data for display
$displayData = array_merge($tenant, $formData);

// Determine current subscription plan
$currentPlanId = $displayData['subscription_plan_id'] ?? null;

$pageTitle = 'Edit Tenant - Student 360 Platform';
$currentPage = 'tenants';

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

// Variables read by the platform sidebar partial
$pendingApprovals = 0;

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// CSRF token for the edit form
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
            transition: all 0.3s;
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
            padding: 20px 24px;
        }

        /* ================================================ */
        /* FORM STYLES */
        /* ================================================ */
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

        .form-control.is-invalid:focus,
        .form-select.is-invalid:focus {
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.15);
        }

        .invalid-feedback {
            display: block;
            font-size: 12px;
            color: #dc3545;
            margin-top: 4px;
        }

        .invalid-feedback i {
            margin-right: 4px;
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

        .mt-3 {
            margin-top: 16px;
        }

        .gap-2 {
            gap: 8px;
        }

        .d-flex {
            display: flex;
        }

        .flex-wrap {
            flex-wrap: wrap;
        }

        .justify-content-end {
            justify-content: flex-end;
        }

        hr {
            border: none;
            border-top: 1px solid #f0f2f5;
            margin: 16px 0;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.active {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.suspended {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        /* ================================================ */
        /* ALERT BOX */
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

        .alert-custom .alert-message {
            font-size: 13px;
            opacity: 0.9;
        }

        .alert-custom .alert-message ul {
            margin: 4px 0 0 0;
            padding-left: 18px;
        }

        .alert-custom .alert-message ul li {
            padding: 1px 0;
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

        .alert-custom.alert-danger .alert-icon {
            color: #dc3545;
        }

        .alert-custom.alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        .alert-custom.alert-success .alert-icon {
            color: #28a745;
        }

        .alert-custom.alert-warning {
            background: #fffbeb;
            color: #92400e;
            border-left: 4px solid #ffc107;
        }

        .alert-custom.alert-warning .alert-icon {
            color: #ffc107;
        }

        .alert-custom.alert-info {
            background: #eff6ff;
            color: #1e40af;
            border-left: 4px solid #4facfe;
        }

        .alert-custom.alert-info .alert-icon {
            color: #4facfe;
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
        /* PLAN SELECTION */
        /* ================================================ */
        .plan-select-wrapper {
            position: relative;
        }

        .plan-select-wrapper .form-select {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236c757d' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
            cursor: pointer;
        }

        .plan-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 12px;
            border: 1px solid #e9ecef;
            display: block;
            transition: all 0.3s ease;
        }

        .plan-details .plan-features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 8px;
            margin-top: 8px;
        }

        .plan-details .plan-features-grid .feature-item {
            font-size: 13px;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .plan-details .plan-features-grid .feature-item i {
            color: #28a745;
            font-size: 14px;
            width: 18px;
        }

        .plan-details .plan-price-display {
            font-weight: 700;
            font-size: 20px;
            color: #4facfe;
        }

        .plan-details .plan-price-display .currency {
            font-size: 14px;
            font-weight: 500;
            color: #6c757d;
        }

        .plan-details .plan-price-display .cycle {
            font-size: 13px;
            font-weight: 400;
            color: #6c757d;
        }

        .plan-details .plan-badge {
            display: inline-block;
            font-size: 10px;
            padding: 2px 12px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }

        .plan-details .plan-badge.free {
            background: #28a745;
            color: #fff;
        }

        .plan-details .plan-badge.trial {
            background: #ff6b6b;
            color: #fff;
        }

        .plan-details .plan-badge.popular {
            background: #4facfe;
            color: #fff;
        }

        .plan-details .plan-description-text {
            font-size: 13px;
            color: #6c757d;
            margin-bottom: 6px;
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
                font-size: 22px;
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
                padding: 14px 16px;
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

            .plan-details .plan-features-grid {
                grid-template-columns: 1fr 1fr;
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
                padding: 10px 12px;
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

            .plan-details .plan-features-grid {
                grid-template-columns: 1fr;
            }

            .plan-details .plan-price-display {
                font-size: 18px;
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
                        <h1><i class="fas fa-edit me-2"></i>Edit Tenant</h1>
                        <p>Update tenant information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenants/view.php?id=<?php echo $tenantId; ?>" class="btn btn-outline-info">
                            <i class="fas fa-eye me-2"></i> View
                        </a>
                        <a href="/platform/tenants/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
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

                <!-- Edit Form -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-building me-2 text-primary"></i>Tenant Information</h6>
                        <span class="badge-status <?php echo $tenant['status'] ?? 'active'; ?>">
                            <?php echo ucfirst($tenant['status'] ?? 'Active'); ?>
                        </span>
                    </div>
                    <div class="card-body-custom">
                        <form id="editTenantForm" method="POST" action="/platform/tenants/edit.php?id=<?php echo $tenantId; ?>" novalidate>
                            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <!-- ============================================ -->
                            <!-- SECTION 1: BASIC INFORMATION -->
                            <!-- ============================================ -->
                            <h6 class="mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Basic Information</h6>
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="tenantName">Tenant Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="tenantName" name="tenant_name"
                                            placeholder="Enter tenant name"
                                            value="<?php echo htmlspecialchars($displayData['tenant_name'] ?? ''); ?>" required autofocus>
                                        <div class="invalid-feedback"><i class="fas fa-exclamation-circle"></i> Tenant name is required</div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="tenantCode">Tenant Code <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="tenantCode" name="tenant_code"
                                            placeholder="e.g., ABC-001"
                                            value="<?php echo htmlspecialchars($displayData['tenant_code'] ?? ''); ?>" required>
                                        <div class="invalid-feedback"><i class="fas fa-exclamation-circle"></i> Tenant code is required</div>
                                        <div class="form-text">Unique code (2-20 characters, uppercase letters, numbers, hyphens)</div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="legalName">Legal Name</label>
                                        <input type="text" class="form-control" id="legalName" name="legal_name"
                                            placeholder="Enter legal name"
                                            value="<?php echo htmlspecialchars($displayData['legal_name'] ?? ''); ?>">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="institutionType">Institution Type</label>
                                        <select class="form-select" id="institutionType" name="institution_type">
                                            <option value="school" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'school') ? 'selected' : ''; ?>>School</option>
                                            <option value="university" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'university') ? 'selected' : ''; ?>>University</option>
                                            <option value="college" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'college') ? 'selected' : ''; ?>>College</option>
                                            <option value="training_center" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'training_center') ? 'selected' : ''; ?>>Training Center</option>
                                            <option value="institute" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'institute') ? 'selected' : ''; ?>>Institute</option>
                                            <option value="academy" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'academy') ? 'selected' : ''; ?>>Academy</option>
                                            <option value="other" <?php echo (isset($displayData['institution_type']) && $displayData['institution_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="email">Email</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            placeholder="tenant@example.com"
                                            value="<?php echo htmlspecialchars($displayData['email'] ?? ''); ?>">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="phone">Phone</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            placeholder="+233 XX XXX XXXX"
                                            value="<?php echo htmlspecialchars($displayData['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="website">Website</label>
                                        <input type="url" class="form-control" id="website" name="website"
                                            placeholder="https://tenant.example.com"
                                            value="<?php echo htmlspecialchars($displayData['website'] ?? ''); ?>">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="status">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <?php foreach ($statuses as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['status']) && $displayData['status'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- SECTION 2: ADDRESS INFORMATION -->
                            <!-- ============================================ -->
                            <h6 class="mb-3"><i class="fas fa-map-pin me-2 text-primary"></i>Address Information</h6>
                            <div class="row">
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="region">Region/State</label>
                                        <input type="text" class="form-control" id="region" name="region"
                                            placeholder="Region or State"
                                            value="<?php echo htmlspecialchars($displayData['region'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="city">City</label>
                                        <input type="text" class="form-control" id="city" name="city"
                                            placeholder="City"
                                            value="<?php echo htmlspecialchars($displayData['city'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="district">District</label>
                                        <input type="text" class="form-control" id="district" name="district"
                                            placeholder="District"
                                            value="<?php echo htmlspecialchars($displayData['district'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="postalAddress">Postal Address</label>
                                        <input type="text" class="form-control" id="postalAddress" name="postal_address"
                                            placeholder="P.O. Box, Street address"
                                            value="<?php echo htmlspecialchars($displayData['postal_address'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="countryId">Country</label>
                                        <select class="form-select" id="countryId" name="country_id">
                                            <option value="">Select Country</option>
                                            <?php foreach ($countries as $id => $name): ?>
                                                <option value="<?php echo $id; ?>" <?php echo (isset($displayData['country_id']) && $displayData['country_id'] == $id) ? 'selected' : ''; ?>>
                                                    <?php echo $name; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- SECTION 3: LOCALIZATION SETTINGS -->
                            <!-- ============================================ -->
                            <h6 class="mb-3"><i class="fas fa-globe me-2 text-primary"></i>Localization Settings</h6>
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="language">Default Language</label>
                                        <select class="form-select" id="language" name="language">
                                            <?php foreach ($languages as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['language']) && $displayData['language'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="timezone">Default Timezone</label>
                                        <select class="form-select" id="timezone" name="timezone">
                                            <?php foreach ($timezones as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['timezone']) && $displayData['timezone'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="currency">Default Currency</label>
                                        <select class="form-select" id="currency" name="currency">
                                            <?php foreach ($currencies as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['currency']) && $displayData['currency'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="dateFormat">Date Format</label>
                                        <select class="form-select" id="dateFormat" name="date_format">
                                            <?php foreach ($dateFormats as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['date_format']) && $displayData['date_format'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- SECTION 4: SUBSCRIPTION & LIMITS -->
                            <!-- ============================================ -->
                            <h6 class="mb-3"><i class="fas fa-crown me-2 text-primary"></i>Subscription & Limits</h6>
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="subscriptionPlanId">Subscription Plan</label>
                                        <div class="plan-select-wrapper">
                                            <select class="form-select" id="subscriptionPlanId" name="subscription_plan_id" onchange="autoPopulateLimits(this)">
                                                <option value="">Select Plan</option>
                                                <?php if (empty($subscriptionPlans)): ?>
                                                    <option value="" disabled>No subscription plans available</option>
                                                <?php else: ?>
                                                    <?php foreach ($subscriptionPlans as $plan): ?>
                                                        <option value="<?php echo $plan['id']; ?>"
                                                            <?php echo ($currentPlanId == $plan['id']) ? 'selected' : ''; ?>
                                                            data-max-students="<?php echo $plan['max_students']; ?>"
                                                            data-max-staff="<?php echo $plan['max_staff']; ?>"
                                                            data-max-campuses="<?php echo $plan['max_campuses']; ?>"
                                                            data-max-schools="<?php echo $plan['max_schools']; ?>"
                                                            data-max-storage="<?php echo $plan['max_storage_mb']; ?>"
                                                            data-max-api="<?php echo $plan['max_api_calls']; ?>"
                                                            data-max-ai="<?php echo $plan['max_ai_requests']; ?>"
                                                            data-sms-balance="<?php echo $plan['sms_balance']; ?>"
                                                            data-email-balance="<?php echo $plan['email_balance']; ?>">
                                                            <?php echo htmlspecialchars($plan['plan_name']); ?>
                                                            (<?php echo htmlspecialchars($plan['plan_code'] ?? ''); ?>)
                                                            <?php if ($plan['price'] > 0): ?>
                                                                - <?php echo htmlspecialchars($plan['currency'] ?? 'GHS'); ?> <?php echo number_format($plan['price'], 2); ?>/<?php echo $plan['billing_cycle'] ?? 'month'; ?>
                                                            <?php else: ?>
                                                                - Free
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="form-text">Select a subscription plan to automatically set limits below</div>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="licenseStatus">License Status</label>
                                        <select class="form-select" id="licenseStatus" name="license_status">
                                            <?php foreach ($licenseStatuses as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($displayData['license_status']) && $displayData['license_status'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" for="licenseExpiresAt">License Expires</label>
                                        <input type="date" class="form-control" id="licenseExpiresAt" name="license_expires_at"
                                            value="<?php echo htmlspecialchars($displayData['license_expires_at'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxStudents">Max Students</label>
                                        <input type="number" class="form-control" id="maxStudents" name="max_students"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_students'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxStaff">Max Staff</label>
                                        <input type="number" class="form-control" id="maxStaff" name="max_staff"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_staff'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxCampuses">Max Campuses</label>
                                        <input type="number" class="form-control" id="maxCampuses" name="max_campuses"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_campuses'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxSchools">Max Schools</label>
                                        <input type="number" class="form-control" id="maxSchools" name="max_schools"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_schools'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxStorageMb">Max Storage (MB)</label>
                                        <input type="number" class="form-control" id="maxStorageMb" name="max_storage_mb"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_storage_mb'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxApiCalls">Max API Calls</label>
                                        <input type="number" class="form-control" id="maxApiCalls" name="max_api_calls"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_api_calls'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="maxAiRequests">Max AI Requests</label>
                                        <input type="number" class="form-control" id="maxAiRequests" name="max_ai_requests"
                                            placeholder="Unlimited" value="<?php echo htmlspecialchars($displayData['max_ai_requests'] ?? ''); ?>" min="0">
                                        <div class="form-text">0 = Unlimited</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="smsBalance">SMS Balance</label>
                                        <input type="number" class="form-control" id="smsBalance" name="sms_balance"
                                            placeholder="0" value="<?php echo htmlspecialchars($displayData['sms_balance'] ?? 0); ?>" min="0">
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="emailBalance">Email Balance</label>
                                        <input type="number" class="form-control" id="emailBalance" name="email_balance"
                                            placeholder="0" value="<?php echo htmlspecialchars($displayData['email_balance'] ?? 0); ?>" min="0">
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- SECTION 5: DESCRIPTION -->
                            <!-- ============================================ -->
                            <h6 class="mb-3"><i class="fas fa-align-left me-2 text-primary"></i>Description</h6>
                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-2">
                                        <label class="form-label" for="description">Tenant Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"
                                            placeholder="Enter a description for this tenant"><?php echo htmlspecialchars($displayData['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- ============================================ -->
                            <!-- FORM ACTIONS -->
                            <!-- ============================================ -->
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <a href="/platform/tenants/view.php?id=<?php echo $tenantId; ?>" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-save me-2"></i> Update Tenant
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
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '/platform/login.php';
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
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        // ================================================
        // REAL-TIME VALIDATION
        // ================================================
        function validateField(input) {
            const value = input.value.trim();
            const isRequired = input.hasAttribute('required');

            if (!isRequired) return;

            if (value === '') {
                input.classList.remove('is-valid');
                input.classList.add('is-invalid');
                return;
            }

            if (input.type === 'email') {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    input.classList.remove('is-valid');
                    input.classList.add('is-invalid');
                    return;
                }
            }

            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            const requiredInputs = document.querySelectorAll('input[required], select[required]');
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

            const emailInputs = document.querySelectorAll('input[type="email"]');
            emailInputs.forEach(input => {
                input.addEventListener('input', function() {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (emailRegex.test(this.value.trim())) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                });
            });
        });

        // ================================================
        // AUTO-POPULATE LIMITS FROM SUBSCRIPTION PLAN
        // ================================================
        function autoPopulateLimits(select) {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.dataset) {
                const fields = [{
                        id: 'maxStudents',
                        key: 'maxStudents'
                    },
                    {
                        id: 'maxStaff',
                        key: 'maxStaff'
                    },
                    {
                        id: 'maxCampuses',
                        key: 'maxCampuses'
                    },
                    {
                        id: 'maxSchools',
                        key: 'maxSchools'
                    },
                    {
                        id: 'maxStorageMb',
                        key: 'maxStorage'
                    },
                    {
                        id: 'maxApiCalls',
                        key: 'maxApi'
                    },
                    {
                        id: 'maxAiRequests',
                        key: 'maxAi'
                    },
                    {
                        id: 'smsBalance',
                        key: 'smsBalance'
                    },
                    {
                        id: 'emailBalance',
                        key: 'emailBalance'
                    }
                ];

                fields.forEach(field => {
                    const value = selectedOption.dataset[field.key];
                    const element = document.getElementById(field.id);
                    if (element && value !== undefined && value !== '') {
                        if (parseInt(value) === 0) {
                            element.value = '';
                            element.placeholder = 'Unlimited';
                        } else {
                            element.value = value;
                        }
                    }
                });
            }
        }

        // ================================================
        // SHOW ALERT
        // ================================================
        function showAlert(message, type = 'info', title = '') {
            const container = document.getElementById('alertContainer');
            const titles = {
                success: 'Success!',
                danger: 'Error!',
                warning: 'Warning!',
                info: 'Information'
            };

            const alertHtml = `
                <div class="alert-custom alert-${type}">
                    <div class="alert-icon">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                    </div>
                    <div class="alert-content">
                        <div class="alert-title">${title || titles[type] || 'Information'}</div>
                        <div class="alert-message">${message}</div>
                    </div>
                    <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;

            container.innerHTML = alertHtml;

            setTimeout(() => {
                const alert = container.querySelector('.alert-custom');
                if (alert) {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }

        // ================================================
        // FORM SUBMISSION
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editTenantForm');
            const submitBtn = document.getElementById('submitBtn');

            form.addEventListener('submit', function(e) {
                let isValid = true;
                const requiredInputs = form.querySelectorAll('input[required], select[required]');

                requiredInputs.forEach(input => {
                    validateField(input);
                    if (input.classList.contains('is-invalid')) {
                        isValid = false;
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    showAlert('Please fill in all required fields correctly.', 'danger', 'Validation Error');
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
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Updating...';
            });

            // Enter key support
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && this.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                        const focusable = Array.from(form.querySelectorAll('input:not([readonly]), select, textarea, button'));
                        const index = focusable.indexOf(this);
                        if (index < focusable.length - 1 && focusable[index + 1].tagName !== 'BUTTON') {
                            focusable[index + 1].focus();
                        } else {
                            form.dispatchEvent(new Event('submit'));
                        }
                    }
                });
            });
        });
    </script>
</body>

</html>