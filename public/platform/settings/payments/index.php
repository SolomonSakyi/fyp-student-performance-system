<?php

/**
 * Payment Settings - Main Dashboard
 *
 * @package EduTrack
 * @subpackage Platform\Settings\Payments
 * @version 1.0
 * @filepath public/platform/settings/payments/index.php
 *
 * v1.0 change (2026-10-08) [SWEEP X-1 + SIDEBAR + REPAIR]:
 *   Platform-settings payments hub sweep. X-1 in full, plus
 *   sidebar reconciliation, plus two repairs. This is the first
 *   versioned dockblock on this file — it carried only a two-line
 *   comment before, so no prior version is displaced.
 *
 *   X-1: the visible "EduTrack" brand heading that this page
 *   carried in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 11 items across
 *   4 groups (Main: Dashboard, Tenants, Users; Institution: Schools,
 *   Campuses; Management: Subscriptions, Domains; System: Audit Logs,
 *   Monitoring, Settings), brand heading "EduTrack", width 260px —
 *   is replaced by a single require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups, brand heading "Student 360", plus the mobile
 *   toggle, the sidebar footer, and the toggleSidebar() JS. This
 *   file's inline sidebar already matched the canonical item set;
 *   only the brand string and the toggleSidebar() copy differed
 *   from the partial.
 *
 *   $projectRoot = dirname(__DIR__, 4) — FOUR levels up, not three.
 *   This file is one directory deeper than the parent
 *   settings/index.php: its path is
 *   public/platform/settings/payments/index.php, so four levels up
 *   resolves to the project root. Using three levels up would
 *   resolve to public/platform/settings/ and the partial require
 *   would fail with the same "Undefined variable $projectRoot" or
 *   "Failed opening required" error the parent file produced in an
 *   earlier sweep round.
 *
 *   $currentPage stays 'settings' so the Settings item in the
 *   canonical partial is marked active. $currentUser, $userFirstName,
 *   $userAvatar, and $pendingApprovals are set before the partial,
 *   matching the other swept pages.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS. The inner settings-sidebar / settings-nav — the payments
 *   tabs' own navigation — is distinct from the outer sidebar and
 *   is unchanged.
 *
 *   The inline toggleSidebar() function, the inline click-outside
 *   handler, and the inline resize handler are removed from the
 *   page's bottom <script> block, because the partial provides them.
 *   Every tab-body function in that <script> block is retained.
 *
 *   Repairs:
 *
 *     - AUTH. The is_super_admin guard is added immediately after
 *       the logged_in guard. Prior to this change this page — the
 *       payments hub, reachable from the parent settings/index.php
 *       v2.3 — carried only the logged_in guard. Every other
 *       platform-root page swept in this session carries both
 *       guards. The added guard matches the convention:
 *         if (!isset($_SESSION['is_super_admin']) ||
 *             $_SESSION['is_super_admin'] !== true) {
 *             header('Location: /platform/tenant/dashboard.php');
 *             exit;
 *         }
 *
 *     - LOGOUT. The inline logout() function previously redirected
 *       to '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php'
 *       — the login page, not the logout handler. Clicking Logout
 *       on this page did not clear the session. The redirect is
 *       changed to the relative '/platform/logout.php', matching
 *       the convention used by every other swept platform-root
 *       page and by the parent settings/index.php v2.3.
 *
 *   Open items on the record (NOT changed by this sweep):
 *
 *     - The inner settings-nav uses <a> links only. There is no
 *       form and no POST on this page. The two modals
 *       (providerConfigModal, addWebhookModal) do not post to PHP —
 *       every write goes through the JS fetch() calls to the
 *       /api/platform/settings/payments/... endpoints. So there is
 *       no CSRF surface on this file. Any of the ten tab files this
 *       dispatcher includes that carries a PHP POST will need its
 *       own CSRF gate; each is on its own record.
 *
 *     - The file dispatches to ten sibling tab files by $activeTab:
 *       overview.php, providers.php, hubtel.php, bank.php,
 *       webhooks.php, security.php, currency.php, transactions.php,
 *       reconciliation.php, logs.php. All ten exist in the folder
 *       listing. Each is on its own record.
 *
 *   The ten-tab dispatch logic, the ten tab links in the settings-
 *   nav, the two modals, the API contract script block (API_BASE,
 *   TOKEN, TENANT_ID, SCHOOL_ID, ACTIVE_TAB, getHeaders(),
 *   showAlert(), togglePasswordVisibility(), refreshData(),
 *   loadUserInfo()), and every load* / save* / test* / toggle*
 *   function the tab bodies call are unchanged.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

$projectRoot = dirname(__DIR__, 4);

// Check if session is already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Super Admin check - matches settings/index.php v2.3 and the platform-root convention
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'Payment Settings - Student 360 Platform';
$currentPage = 'settings';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';

// Get tenant and school context from session
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;

// Determine API base URL dynamically
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

// Variables read by the platform sidebar partial
$pendingApprovals = 0;

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';
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
        /* GLOBAL RESET - MATCHES DASHBOARD                */
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
        /* SIDEBAR - MATCHES DASHBOARD                     */
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

        /* ================================================ */
        /* MAIN CONTENT                                   */
        /* ================================================ */
        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        /* ================================================ */
        /* TOP BAR                                        */
        /* ================================================ */
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

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
            font-weight: 400;
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

        .top-bar .header-actions .btn i {
            margin-right: 6px;
        }

        /* ================================================ */
        /* SETTINGS LAYOUT                                */
        /* ================================================ */
        .settings-container {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .settings-sidebar {
            width: 220px;
            flex-shrink: 0;
        }

        .settings-content {
            flex: 1;
            min-width: 0;
        }

        .settings-nav {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .settings-nav .nav-item {
            border-bottom: 1px solid #f0f2f5;
        }

        .settings-nav .nav-item:last-child {
            border-bottom: none;
        }

        .settings-nav .nav-link {
            padding: 10px 16px;
            color: #6c757d;
            font-weight: 500;
            font-size: 13px;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .settings-nav .nav-link:hover {
            background: #f8f9fa;
            color: #1a1a2e;
        }

        .settings-nav .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
        }

        .settings-nav .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 14px;
        }

        .settings-nav .nav-link .badge {
            margin-left: auto;
            font-size: 10px;
            padding: 2px 8px;
        }

        /* ================================================ */
        /* CARDS - MATCHES DASHBOARD                      */
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
            padding: 14px 20px;
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
            padding: 18px 20px;
        }

        /* ================================================ */
        /* FORM ELEMENTS                                  */
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
            padding: 8px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            max-width: 100%;
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

        .form-control.textarea {
            height: auto;
            min-height: 80px;
            resize: vertical;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .form-control.credential {
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
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

        .align-items-center {
            align-items: center;
        }

        .gap-3 {
            gap: 12px;
        }

        /* ================================================ */
        /* STATUS BADGES                                  */
        /* ================================================ */
        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.success {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.failed {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.info {
            background: #cce5ff;
            color: #004085;
        }

        /* ================================================ */
        /* PROVIDER CARDS                                 */
        /* ================================================ */
        .provider-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 12px;
            transition: all 0.3s;
            cursor: pointer;
            background: #fff;
        }

        .provider-card:hover {
            border-color: #4facfe;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }

        .provider-card.active {
            border-color: #28a745;
            background: #f8fff9;
        }

        .provider-card.inactive {
            border-color: #e9ecef;
            opacity: 0.7;
        }

        .provider-card .provider-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .provider-card .provider-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .provider-card .provider-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .provider-card .provider-icon.hubtel {
            background: linear-gradient(135deg, #00aef0, #0084c8);
        }

        .provider-card .provider-icon.paystack {
            background: linear-gradient(135deg, #00b365, #008a4e);
        }

        .provider-card .provider-icon.flutterwave {
            background: linear-gradient(135deg, #f5a623, #d4880f);
        }

        .provider-card .provider-icon.bank {
            background: linear-gradient(135deg, #6c5ce7, #4a3db8);
        }

        .provider-card .provider-name {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a2e;
        }

        .provider-card .provider-type {
            font-size: 12px;
            color: #6c757d;
        }

        .provider-card .provider-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .provider-card .provider-status.active {
            background: #d4edda;
            color: #155724;
        }

        .provider-card .provider-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .provider-card .provider-status.default {
            background: #cce5ff;
            color: #004085;
        }

        .provider-card .provider-actions {
            display: flex;
            gap: 6px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .provider-card .provider-actions .btn {
            padding: 3px 10px;
            font-size: 12px;
            border-radius: 8px;
        }

        /* ================================================ */
        /* SECURITY SECTION                              */
        /* ================================================ */
        .security-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .security-item:last-child {
            border-bottom: none;
        }

        .security-item .security-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .security-item .security-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .security-item .security-icon.encryption {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .security-item .security-icon.keys {
            background: #fff3cd;
            color: #856404;
        }

        .security-item .security-icon.webhook {
            background: #d4edda;
            color: #155724;
        }

        .security-item .security-icon.audit {
            background: #f8d7da;
            color: #721c24;
        }

        .security-item .security-icon.shield {
            background: #cce5ff;
            color: #004085;
        }

        .security-item .security-icon.rate {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .security-item .security-icon.network {
            background: #d4edda;
            color: #28a745;
        }

        .security-item .security-info div div:first-child {
            font-weight: 600;
            font-size: 13px;
        }

        .security-item .security-info div div:last-child {
            font-size: 12px;
            color: #6c757d;
        }

        /* ================================================ */
        /* STAT BOXES                                     */
        /* ================================================ */
        .stat-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
        }

        .stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }

        .stat-box .stat-number {
            font-size: 28px;
            font-weight: 700;
        }

        .stat-box .stat-label {
            font-size: 13px;
            color: #6c757d;
        }

        /* ================================================ */
        /* WEBHOOK STATUS                                 */
        /* ================================================ */
        .webhook-status {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        .webhook-status .status-icon {
            font-size: 24px;
        }

        .webhook-status .status-icon.success {
            color: #28a745;
        }

        .webhook-status .status-icon.warning {
            color: #ffc107;
        }

        .webhook-status .status-icon.danger {
            color: #dc3545;
        }

        /* ================================================ */
        /* HEALTH INDICATOR                               */
        /* ================================================ */
        .health-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .health-item:last-child {
            border-bottom: none;
        }

        .health-item .health-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .health-item .health-dot.operational {
            background: #28a745;
        }

        .health-item .health-dot.degraded {
            background: #ffc107;
        }

        .health-item .health-dot.down {
            background: #dc3545;
        }

        .health-item .health-indicator {
            font-size: 12px;
        }

        /* ================================================ */
        /* TABLE STYLES                                   */
        /* ================================================ */
        .table {
            margin: 0;
        }

        .table th {
            font-weight: 600;
            font-size: 12px;
            color: #6c757d;
            border-bottom: 2px solid #e9ecef;
        }

        .table td {
            font-size: 13px;
            vertical-align: middle;
            padding: 10px 8px;
        }

        .table code {
            font-size: 11px;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
            color: #1a1a2e;
        }

        .table .badge {
            font-size: 10px;
        }

        /* ================================================ */
        /* INPUT GROUP                                   */
        /* ================================================ */
        .input-group .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-right: none;
            font-weight: 500;
            font-size: 13px;
            color: #6c757d;
            border-radius: 10px 0 0 10px;
        }

        .input-group .form-control {
            border-radius: 0 10px 10px 0;
        }

        .input-group .btn-outline-secondary {
            border-radius: 0 10px 10px 0;
            border: 2px solid #e9ecef;
            border-left: none;
        }

        /* ================================================ */
        /* RESPONSIVE                                     */
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

            .settings-sidebar {
                width: 100%;
            }

            .settings-container {
                flex-direction: column;
            }

            .settings-nav {
                display: flex;
                flex-wrap: wrap;
            }

            .settings-nav .nav-item {
                border-bottom: none;
                border-right: 1px solid #f0f2f5;
            }

            .settings-nav .nav-link {
                padding: 8px 12px;
                font-size: 12px;
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

            .settings-nav .nav-link {
                font-size: 12px;
                padding: 6px 10px;
            }

            .provider-card {
                padding: 12px 16px;
            }

            .provider-card .provider-icon {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .stat-box .stat-number {
                font-size: 22px;
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

            .card-custom .card-header-custom {
                padding: 10px 14px;
            }

            .card-custom .card-body-custom {
                padding: 12px 14px;
            }

            .provider-card .provider-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stat-box {
                padding: 12px;
            }

            .stat-box .stat-number {
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
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-credit-card me-2"></i>Payment Settings</h1>
                        <p>Configure payment providers, credentials, and transaction rules</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/settings/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Settings
                        </a>
                        <button class="btn btn-outline-secondary" onclick="refreshData()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <!-- Settings Container -->
                <div class="settings-container">
                    <!-- Sidebar -->
                    <div class="settings-sidebar">
                        <nav class="settings-nav">
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'overview' ? 'active' : ''; ?>" href="?tab=overview">
                                    <i class="fas fa-chart-simple"></i> Overview
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'providers' ? 'active' : ''; ?>" href="?tab=providers">
                                    <i class="fas fa-plug"></i> Providers
                                    <span class="badge bg-primary" id="providerCount">0</span>
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'hubtel' ? 'active' : ''; ?>" href="?tab=hubtel">
                                    <i class="fas fa-mobile-alt"></i> Hubtel
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'bank' ? 'active' : ''; ?>" href="?tab=bank">
                                    <i class="fas fa-university"></i> Bank API
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'webhooks' ? 'active' : ''; ?>" href="?tab=webhooks">
                                    <i class="fas fa-bolt"></i> Webhooks
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'security' ? 'active' : ''; ?>" href="?tab=security">
                                    <i class="fas fa-shield-alt"></i> Security
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'currency' ? 'active' : ''; ?>" href="?tab=currency">
                                    <i class="fas fa-dollar-sign"></i> Currency
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'transactions' ? 'active' : ''; ?>" href="?tab=transactions">
                                    <i class="fas fa-cog"></i> Transaction Rules
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'reconciliation' ? 'active' : ''; ?>" href="?tab=reconciliation">
                                    <i class="fas fa-balance-scale"></i> Reconciliation
                                </a>
                            </div>
                            <div class="nav-item">
                                <a class="nav-link <?php echo $activeTab == 'logs' ? 'active' : ''; ?>" href="?tab=logs">
                                    <i class="fas fa-list"></i> Audit Logs
                                </a>
                            </div>
                        </nav>
                    </div>

                    <!-- Content -->
                    <div class="settings-content">
                        <?php
                        // Load the appropriate tab content
                        $tabFile = __DIR__ . '/' . $activeTab . '.php';
                        if (file_exists($tabFile)) {
                            include $tabFile;
                        } else {
                            include __DIR__ . '/overview.php';
                        }
                        ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Provider Configuration Modal -->
    <div class="modal fade" id="providerConfigModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
                <div class="modal-header" style="border-bottom:1px solid #f0f2f5;padding:16px 24px;">
                    <h5 class="modal-title"><i class="fas fa-cog me-2 text-primary"></i> <span id="providerConfigTitle">Configure Provider</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:24px;">
                    <form id="providerConfigForm">
                        <input type="hidden" id="configProviderId">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Provider Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="configProviderName" placeholder="e.g., Hubtel" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Provider Type <span class="required">*</span></label>
                                    <select class="form-select" id="configProviderType" required>
                                        <option value="">Select Type</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="card">Card Payment</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="multi_currency">Multi-Currency</option>
                                        <option value="custom">Custom</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">API Key / Client ID <span class="required">*</span></label>
                                    <input type="text" class="form-control credential" id="configApiKey" placeholder="Enter API Key" required>
                                    <div class="form-text">This credential will be encrypted before storage</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">API Secret / Client Secret <span class="required">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control credential" id="configApiSecret" placeholder="Enter API Secret" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('configApiSecret')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">This credential will be encrypted before storage</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Environment</label>
                                    <select class="form-select" id="configEnvironment">
                                        <option value="sandbox">Sandbox (Test)</option>
                                        <option value="production">Production (Live)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="configStatus">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Description</label>
                            <textarea class="form-control textarea" id="configDescription" placeholder="Provider description" rows="2"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer" style="border-top:1px solid #f0f2f5;padding:16px 24px;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveProviderConfig()"><i class="fas fa-save me-2"></i> Save Configuration</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Webhook Modal -->
    <div class="modal fade" id="addWebhookModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
                <div class="modal-header" style="border-bottom:1px solid #f0f2f5;padding:16px 24px;">
                    <h5 class="modal-title"><i class="fas fa-plus me-2 text-primary"></i>Add Webhook</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:24px;">
                    <form id="webhookForm">
                        <div class="mb-2">
                            <label class="form-label">Event <span class="required">*</span></label>
                            <select class="form-select" id="webhookEvent" required>
                                <option value="">Select Event</option>
                                <option value="payment.success">Payment Success</option>
                                <option value="payment.failed">Payment Failed</option>
                                <option value="payment.pending">Payment Pending</option>
                                <option value="payment.refund">Payment Refund</option>
                                <option value="subscription.created">Subscription Created</option>
                                <option value="subscription.renewal">Subscription Renewal</option>
                                <option value="subscription.cancelled">Subscription Cancelled</option>
                                <option value="invoice.paid">Invoice Paid</option>
                                <option value="invoice.overdue">Invoice Overdue</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Provider <span class="required">*</span></label>
                            <select class="form-select" id="webhookProvider" required>
                                <option value="">Select Provider</option>
                                <option value="hubtel">Hubtel</option>
                                <option value="bank">Bank API</option>
                                <option value="paystack">Paystack</option>
                                <option value="flutterwave">Flutterwave</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Webhook URL <span class="required">*</span></label>
                            <input type="url" class="form-control" id="webhookUrl" placeholder="https://yourdomain.com/webhook/endpoint" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Secret Key</label>
                            <input type="text" class="form-control credential" id="webhookSecret" placeholder="Enter webhook secret for verification">
                            <div class="form-text">Used to verify webhook signatures</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="webhookStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer" style="border-top:1px solid #f0f2f5;padding:16px 24px;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveWebhook()"><i class="fas fa-save me-2"></i> Save Webhook</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';
        const TENANT_ID = <?php echo json_encode($tenantId); ?>;
        const SCHOOL_ID = <?php echo json_encode($schoolId); ?>;
        const ACTIVE_TAB = '<?php echo $activeTab; ?>';

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '/platform/logout.php';
            }
        }

        // ================================================
        // API HELPERS
        // ================================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json',
                'X-Tenant-ID': TENANT_ID,
                'X-School-ID': SCHOOL_ID
            };
        }

        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            if (!container) return;

            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            const icons = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            container.innerHTML = `
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                    <i class="fas ${icons[type] || 'fa-info-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;

            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    setTimeout(() => {
                        container.innerHTML = '';
                    }, 300);
                }
            }, 5000);
        }

        function togglePasswordVisibility(id) {
            const input = document.getElementById(id);
            if (!input) return;
            const btn = input.parentElement.querySelector('.btn');
            if (input.type === 'password') {
                input.type = 'text';
                if (btn) btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                input.type = 'password';
                if (btn) btn.innerHTML = '<i class="fas fa-eye"></i>';
            }
        }

        function refreshData() {
            const btn = document.querySelector('.btn-outline-secondary i');
            if (btn) {
                btn.className = 'fas fa-sync-alt fa-spin';
            }
            showAlert('Refreshing data...', 'info');
            setTimeout(() => {
                if (btn) {
                    btn.className = 'fas fa-sync-alt';
                }
                showAlert('Data refreshed successfully', 'success');
                location.reload();
            }, 1500);
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

        // ============================================================
        // OVERVIEW TAB FUNCTIONS
        // ============================================================
        async function loadOverviewData() {
            try {
                const resp = await fetch(API_BASE + '/settings/payments/providers', {
                    headers: getHeaders()
                });
                const result = await resp.json();

                if (result.success) {
                    const providers = result.data || [];
                    const active = providers.filter(p => p.status === 'active');
                    document.getElementById('activeProviders').textContent = active.length;
                    document.getElementById('paymentMethods').textContent = providers.length || 0;
                    document.getElementById('providerCount').textContent = providers.length;
                    renderHealthStatus(providers);
                }
            } catch (e) {
                console.error('Error loading overview:', e);
            }
        }

        function renderHealthStatus(providers) {
            const container = document.getElementById('healthStatus');
            if (!container) return;
            if (!providers || providers.length === 0) {
                container.innerHTML = '<div class="text-muted text-center py-2"><i class="fas fa-info-circle me-2"></i>No providers configured</div>';
                return;
            }
            let html = '';
            providers.forEach(p => {
                const isActive = p.status === 'active';
                const color = isActive ? '#28a745' : '#dc3545';
                html += `<div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>${p.provider_name}</span><span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};margin-right:8px;"></span><span style="font-size:12px;color:${color};">${isActive ? 'Operational' : 'Inactive'}</span></span></div>`;
            });
            container.innerHTML = html;
        }

        // ============================================================
        // PROVIDERS TAB FUNCTIONS
        // ============================================================
        async function loadProviders() {
            try {
                const resp = await fetch(API_BASE + '/settings/payments/providers', {
                    headers: getHeaders()
                });
                const result = await resp.json();
                const container = document.getElementById('providerList');
                if (!container) return;

                if (result.success && result.data && result.data.length > 0) {
                    let html = '';
                    result.data.forEach(p => {
                        const isActive = p.status === 'active';
                        const iconMap = {
                            'mobile_money': 'hubtel',
                            'bank_transfer': 'bank',
                            'card': 'paystack'
                        };
                        const iconClass = iconMap[p.provider_type] || 'flutterwave';
                        const icon = p.provider_type === 'mobile_money' ? 'fa-mobile-alt' : p.provider_type === 'bank_transfer' ? 'fa-university' : 'fa-credit-card';
                        html += `
                            <div class="provider-card ${isActive ? 'active' : 'inactive'}">
                                <div class="provider-header">
                                    <div class="provider-info">
                                        <div class="provider-icon ${iconClass}"><i class="fas ${icon}"></i></div>
                                        <div><div class="provider-name">${p.provider_name}</div><div class="provider-type">${p.provider_type}</div></div>
                                    </div>
                                    <span class="provider-status ${isActive ? 'active' : 'inactive'}">${isActive ? 'Active' : 'Inactive'}</span>
                                </div>
                                <div class="provider-actions">
                                    <button class="btn btn-outline-primary btn-sm" onclick="editProvider(${p.id})"><i class="fas fa-cog me-1"></i> Configure</button>
                                    <button class="btn btn-outline-success btn-sm" onclick="testProvider(${p.id})"><i class="fas fa-plug me-1"></i> Test</button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="toggleProvider(${p.id})"><i class="fas ${isActive ? 'fa-pause' : 'fa-play'} me-1"></i> ${isActive ? 'Pause' : 'Activate'}</button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteProvider(${p.id})"><i class="fas fa-trash me-1"></i></button>
                                </div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                    document.getElementById('providerCount').textContent = result.data.length;
                } else {
                    container.innerHTML = `<div class="text-center py-4 text-muted"><i class="fas fa-plug fa-2x mb-2 d-block"></i>No payment providers configured yet.<br><button class="btn btn-primary btn-sm mt-2" onclick="showAddProviderModal()">Add Provider</button></div>`;
                }
            } catch (e) {
                document.getElementById('providerList').innerHTML = `<div class="alert alert-danger">Failed to load providers: ${e.message}</div>`;
            }
        }

        function showAddProviderModal() {
            document.getElementById('configProviderId').value = '';
            document.getElementById('providerConfigTitle').textContent = 'Add Payment Provider';
            document.getElementById('configProviderName').value = '';
            document.getElementById('configProviderType').value = '';
            document.getElementById('configApiKey').value = '';
            document.getElementById('configApiSecret').value = '';
            document.getElementById('configEnvironment').value = 'sandbox';
            document.getElementById('configStatus').value = 'pending';
            document.getElementById('configDescription').value = '';
            new bootstrap.Modal(document.getElementById('providerConfigModal')).show();
        }

        async function editProvider(id) {
            try {
                const resp = await fetch(API_BASE + '/settings/payments/provider/' + id, {
                    headers: getHeaders()
                });
                const result = await resp.json();
                if (result.success && result.data) {
                    const p = result.data;
                    document.getElementById('configProviderId').value = p.id;
                    document.getElementById('providerConfigTitle').textContent = 'Configure ' + p.provider_name;
                    document.getElementById('configProviderName').value = p.provider_name;
                    document.getElementById('configProviderType').value = p.provider_type;
                    document.getElementById('configApiKey').value = p.api_key_decrypted || '';
                    document.getElementById('configApiSecret').value = p.api_secret_decrypted || '';
                    document.getElementById('configEnvironment').value = p.environment || 'sandbox';
                    document.getElementById('configStatus').value = p.status || 'pending';
                    document.getElementById('configDescription').value = p.description || '';
                    new bootstrap.Modal(document.getElementById('providerConfigModal')).show();
                }
            } catch (e) {
                showAlert('Error loading provider: ' + e.message, 'danger');
            }
        }

        async function saveProviderConfig() {
            const form = document.getElementById('providerConfigForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const id = document.getElementById('configProviderId').value;
            const data = {
                provider_code: document.getElementById('configProviderName').value.toLowerCase().replace(/\s+/g, '_'),
                provider_name: document.getElementById('configProviderName').value,
                provider_type: document.getElementById('configProviderType').value,
                api_key: document.getElementById('configApiKey').value.trim(),
                api_secret: document.getElementById('configApiSecret').value,
                environment: document.getElementById('configEnvironment').value,
                status: document.getElementById('configStatus').value,
                description: document.getElementById('configDescription').value
            };

            try {
                const url = id ? API_BASE + '/settings/payments/provider/' + id : API_BASE + '/settings/payments/provider';
                const method = id ? 'PUT' : 'POST';
                const resp = await fetch(url, {
                    method: method,
                    headers: getHeaders(),
                    body: JSON.stringify(data)
                });
                const result = await resp.json();
                if (result.success) {
                    showAlert('✅ Provider saved successfully!', 'success');
                    document.getElementById('providerConfigModal').querySelector('.btn-close').click();
                    loadProviders();
                    if (ACTIVE_TAB === 'overview') loadOverviewData();
                } else {
                    showAlert('❌ ' + (result.message || 'Failed to save provider'), 'danger');
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            }
        }

        async function testProvider(id) {
            showAlert('Testing connection...', 'info');
            try {
                const resp = await fetch(API_BASE + '/settings/payments/provider/' + id + '/test', {
                    method: 'POST',
                    headers: getHeaders()
                });
                const result = await resp.json();
                if (result.success) {
                    showAlert('✅ Connection test successful!', 'success');
                } else {
                    showAlert('❌ Connection test failed: ' + (result.message || 'Unknown error'), 'danger');
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            }
        }

        async function toggleProvider(id) {
            try {
                const resp = await fetch(API_BASE + '/settings/payments/provider/' + id, {
                    headers: getHeaders()
                });
                const result = await resp.json();
                if (result.success && result.data) {
                    const newStatus = result.data.status === 'active' ? 'inactive' : 'active';
                    const update = await fetch(API_BASE + '/settings/payments/provider/' + id, {
                        method: 'PUT',
                        headers: getHeaders(),
                        body: JSON.stringify({
                            ...result.data,
                            status: newStatus
                        })
                    });
                    const updateResult = await update.json();
                    if (updateResult.success) {
                        showAlert('✅ Provider ' + (newStatus === 'active' ? 'activated' : 'paused') + ' successfully!', 'success');
                        loadProviders();
                    }
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            }
        }

        async function deleteProvider(id) {
            if (!confirm('Are you sure you want to delete this provider?')) return;
            try {
                const resp = await fetch(API_BASE + '/settings/payments/provider/' + id, {
                    method: 'DELETE',
                    headers: getHeaders()
                });
                const result = await resp.json();
                if (result.success) {
                    showAlert('✅ Provider deleted successfully!', 'success');
                    loadProviders();
                } else {
                    showAlert('❌ ' + (result.message || 'Failed to delete provider'), 'danger');
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            }
        }

        // ============================================================
        // HUBTEL TAB FUNCTIONS
        // ============================================================
        async function loadHubtelConfig() {
            try {
                const resp = await fetch(API_BASE + '/settings/payments/hubtel', {
                    headers: getHeaders()
                });
                const result = await resp.json();
                if (result.success && result.data) {
                    const c = result.data;
                    document.getElementById('hubtelProviderId').value = c.id || '';
                    document.getElementById('hubtelClientId').value = c.api_key_decrypted || '';
                    document.getElementById('hubtelEnvironment').value = c.environment || 'sandbox';
                    document.getElementById('hubtelStatus').value = c.status || 'active';
                    if (c.additional_config) {
                        const cfg = typeof c.additional_config === 'string' ? JSON.parse(c.additional_config) : c.additional_config;
                        if (cfg.methods) {
                            document.getElementById('hubtelMomo').checked = cfg.methods.momo || false;
                            document.getElementById('hubtelCard').checked = cfg.methods.card || false;
                            document.getElementById('hubtelBank').checked = cfg.methods.bank || false;
                            document.getElementById('hubtelQr').checked = cfg.methods.qr || false;
                        }
                        if (cfg.networks) {
                            document.getElementById('hubtelMtn').checked = cfg.networks.mtn || false;
                            document.getElementById('hubtelVodafone').checked = cfg.networks.vodafone || false;
                            document.getElementById('hubtelTigo').checked = cfg.networks.tigo || false;
                            document.getElementById('hubtelAirtel').checked = cfg.networks.airtel || false;
                        }
                    }
                    updateHubtelBadge(c.status);
                }
            } catch (e) {
                console.error('Error loading Hubtel:', e);
            }
        }

        function updateHubtelBadge(status) {
            const badge = document.getElementById('hubtelStatusBadge');
            if (!badge) return;
            const map = {
                'active': 'bg-success',
                'inactive': 'bg-danger',
                'maintenance': 'bg-warning',
                'pending': 'bg-warning'
            };
            const text = {
                'active': 'Active',
                'inactive': 'Inactive',
                'maintenance': 'Maintenance',
                'pending': 'Pending'
            };
            badge.className = 'badge ' + (map[status] || 'bg-warning');
            badge.textContent = text[status] || 'Pending';
        }

        async function testHubtelConnection() {
            const btn = event.target;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';
            try {
                const data = {
                    client_id: document.getElementById('hubtelClientId').value.trim(),
                    client_secret: document.getElementById('hubtelClientSecret').value,
                    environment: document.getElementById('hubtelEnvironment').value
                };
                const resp = await fetch(API_BASE + '/settings/payments/hubtel/test', {
                    method: 'POST',
                    headers: getHeaders(),
                    body: JSON.stringify(data)
                });
                const result = await resp.json();
                if (result.success) {
                    showAlert('✅ Hubtel connection successful!', 'success');
                    updateHubtelBadge('active');
                } else {
                    showAlert('❌ Hubtel connection failed: ' + (result.message || 'Unknown error'), 'danger');
                    updateHubtelBadge('inactive');
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function saveHubtelConfig(event) {
            event.preventDefault();
            const form = document.getElementById('hubtelConfigForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            try {
                const data = {
                    client_id: document.getElementById('hubtelClientId').value.trim(),
                    client_secret: document.getElementById('hubtelClientSecret').value,
                    environment: document.getElementById('hubtelEnvironment').value,
                    status: document.getElementById('hubtelStatus').value,
                    methods: {
                        momo: document.getElementById('hubtelMomo').checked,
                        card: document.getElementById('hubtelCard').checked,
                        bank: document.getElementById('hubtelBank').checked,
                        qr: document.getElementById('hubtelQr').checked
                    },
                    networks: {
                        mtn: document.getElementById('hubtelMtn').checked,
                        vodafone: document.getElementById('hubtelVodafone').checked,
                        tigo: document.getElementById('hubtelTigo').checked,
                        airtel: document.getElementById('hubtelAirtel').checked
                    }
                };

                const id = document.getElementById('hubtelProviderId').value;
                const url = id ? API_BASE + '/settings/payments/provider/' + id : API_BASE + '/settings/payments/provider';
                const method = id ? 'PUT' : 'POST';

                const providerData = {
                    provider_code: 'hubtel',
                    provider_name: 'Hubtel',
                    provider_type: 'mobile_money',
                    api_key: data.client_id,
                    api_secret: data.client_secret,
                    environment: data.environment,
                    status: data.status,
                    additional_config: {
                        methods: data.methods,
                        networks: data.networks
                    }
                };

                const resp = await fetch(url, {
                    method: method,
                    headers: getHeaders(),
                    body: JSON.stringify(providerData)
                });
                const result = await resp.json();
                if (result.success) {
                    showAlert('✅ Hubtel configuration saved successfully!', 'success');
                    updateHubtelBadge(data.status);
                    document.getElementById('hubtelProviderId').value = result.provider_id || id;
                } else {
                    showAlert('❌ ' + (result.message || 'Failed to save configuration'), 'danger');
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        // ============================================================
        // BANK TAB FUNCTIONS
        // ============================================================
        async function loadBankConfig() {
            try {
                const resp = await fetch(API_BASE + '/settings/payments/bank', {
                    headers: getHeaders()
                });
                const result = await resp.json();
                if (result.success && result.data) {
                    const c = result.data;
                    document.getElementById('bankProviderId').value = c.id || '';
                    document.getElementById('bankApiKey').value = c.api_key_decrypted || '';
                    document.getElementById('bankName').value = c.additional_config?.bank_name || 'ecobank';
                    document.getElementById('bankApiVersion').value = c.additional_config?.api_version || 'v2';
                    document.getElementById('bankBaseUrl').value = c.additional_config?.base_url || '';
                    document.getElementById('bankAccountNumber').value = c.additional_config?.account_number || '';
                    document.getElementById('bankAccountName').value = c.additional_config?.account_name || '';
                    document.getElementById('bankBranchCode').value = c.additional_config?.branch_code || '';
                    document.getElementById('bankCurrency').value = c.additional_config?.currency || 'GHS';
                    document.getElementById('bankStatus').value = c.status || 'active';
                    if (c.additional_config?.transaction_types) {
                        const t = c.additional_config.transaction_types;
                        document.getElementById('bankTransfer').checked = t.transfer || false;
                        document.getElementById('bankDirectDebit').checked = t.direct_debit || false;
                        document.getElementById('bankCheque').checked = t.cheque || false;
                        document.getElementById('bankWire').checked = t.wire || false;
                    }
                    updateBankBadge(c.status);
                }
            } catch (e) {
                console.error('Error loading Bank:', e);
            }
        }

        function updateBankBadge(status) {
            const badge = document.getElementById('bankStatusBadge');
            if (!badge) return;
            const map = {
                'active': 'bg-success',
                'inactive': 'bg-danger',
                'pending': 'bg-warning'
            };
            const text = {
                'active': 'Active',
                'inactive': 'Inactive',
                'pending': 'Pending'
            };
            badge.className = 'badge ' + (map[status] || 'bg-warning');
            badge.textContent = text[status] || 'Pending';
        }

        async function testBankConnection() {
            const resultDiv = document.getElementById('testResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin me-2"></i> Testing Bank API connection...</div>';

            try {
                const data = {
                    api_key: document.getElementById('bankApiKey').value.trim(),
                    api_secret: document.getElementById('bankApiSecret').value,
                    test_account: document.getElementById('testAccountNumber').value
                };
                const resp = await fetch(API_BASE + '/settings/payments/bank/test', {
                    method: 'POST',
                    headers: getHeaders(),
                    body: JSON.stringify(data)
                });
                const result = await resp.json();
                if (result.success) {
                    resultDiv.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> <strong>Connection successful!</strong><br>${result.message || 'Bank API connection verified.'}</div>`;
                    updateBankBadge('active');
                } else {
                    resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i> <strong>Connection failed!</strong><br>${result.message || 'Unable to verify bank API connection.'}</div>`;
                    updateBankBadge('inactive');
                }
            } catch (e) {
                resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i> Error: ${e.message}</div>`;
            }
        }

        async function saveBankConfig(event) {
            event.preventDefault();
            const form = document.getElementById('bankConfigForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            try {
                const data = {
                    api_key: document.getElementById('bankApiKey').value.trim(),
                    api_secret: document.getElementById('bankApiSecret').value,
                    bank_name: document.getElementById('bankName').value,
                    api_version: document.getElementById('bankApiVersion').value,
                    base_url: document.getElementById('bankBaseUrl').value.trim(),
                    account_number: document.getElementById('bankAccountNumber').value.trim(),
                    account_name: document.getElementById('bankAccountName').value.trim(),
                    branch_code: document.getElementById('bankBranchCode').value.trim(),
                    currency: document.getElementById('bankCurrency').value,
                    status: document.getElementById('bankStatus').value,
                    transaction_types: {
                        transfer: document.getElementById('bankTransfer').checked,
                        direct_debit: document.getElementById('bankDirectDebit').checked,
                        cheque: document.getElementById('bankCheque').checked,
                        wire: document.getElementById('bankWire').checked
                    }
                };

                const id = document.getElementById('bankProviderId').value;
                const url = id ? API_BASE + '/settings/payments/provider/' + id : API_BASE + '/settings/payments/provider';
                const method = id ? 'PUT' : 'POST';

                const providerData = {
                    provider_code: 'bank',
                    provider_name: 'Bank API',
                    provider_type: 'bank_transfer',
                    api_key: data.api_key,
                    api_secret: data.api_secret,
                    environment: 'production',
                    status: data.status,
                    additional_config: {
                        bank_name: data.bank_name,
                        api_version: data.api_version,
                        base_url: data.base_url,
                        account_number: data.account_number,
                        account_name: data.account_name,
                        branch_code: data.branch_code,
                        currency: data.currency,
                        transaction_types: data.transaction_types
                    }
                };

                const resp = await fetch(url, {
                    method: method,
                    headers: getHeaders(),
                    body: JSON.stringify(providerData)
                });
                const result = await resp.json();
                if (result.success) {
                    showAlert('✅ Bank API configuration saved successfully!', 'success');
                    updateBankBadge(data.status);
                    document.getElementById('bankProviderId').value = result.provider_id || id;
                } else {
                    showAlert('❌ ' + (result.message || 'Failed to save configuration'), 'danger');
                }
            } catch (e) {
                showAlert('Error: ' + e.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        // ============================================================
        // WEBHOOK TAB FUNCTIONS
        // ============================================================
        function showAddWebhookModal() {
            document.getElementById('webhookEvent').value = '';
            document.getElementById('webhookProvider').value = '';
            document.getElementById('webhookUrl').value = '';
            document.getElementById('webhookSecret').value = '';
            document.getElementById('webhookStatus').value = 'active';
            new bootstrap.Modal(document.getElementById('addWebhookModal')).show();
        }

        function saveWebhook() {
            const form = document.getElementById('webhookForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            const data = {
                event_name: document.getElementById('webhookEvent').value,
                provider_id: document.getElementById('webhookProvider').value,
                webhook_url: document.getElementById('webhookUrl').value,
                secret_key: document.getElementById('webhookSecret').value,
                status: document.getElementById('webhookStatus').value
            };
            showAlert('Webhook added successfully!', 'success');
            document.getElementById('addWebhookModal').querySelector('.btn-close').click();
        }

        function testWebhook(event) {
            showAlert('Testing webhook: ' + event, 'info');
            setTimeout(() => showAlert('Webhook test successful!', 'success'), 1500);
        }

        function editWebhook(event) {
            showAlert('Editing webhook: ' + event, 'info');
        }

        function deleteWebhook(event) {
            if (confirm('Delete webhook: ' + event + '?')) {
                showAlert('Webhook deleted: ' + event, 'danger');
            }
        }

        // ============================================================
        // SECURITY TAB FUNCTIONS
        // ============================================================
        function viewIndexRegister() {
            showAlert('Viewing Index Register - All payment indexes are securely encrypted', 'info');
        }

        function viewConstraintRegister() {
            showAlert('Viewing Constraint Register - 15 validation rules active', 'info');
        }

        function viewPermissions() {
            showAlert('Managing Platform Admin Permissions', 'info');
        }

        function viewPhpSettings() {
            showAlert('Viewing PHP Settings / Provider Manager', 'info');
        }

        function manageEncryption() {
            showAlert('Managing Secure Credential Encryption - AES-256 enabled', 'success');
        }

        function viewRateLimits() {
            showAlert('Viewing API Rate Limiting - 1000 requests per minute', 'info');
        }

        function configureIpWhitelist() {
            showAlert('Configure IP Whitelist', 'info');
        }

        function runSecurityAudit() {
            showAlert('Running security audit...', 'info');
            setTimeout(() => showAlert('Security audit complete! All systems secure.', 'success'), 2000);
        }

        function saveSecuritySettings() {
            showAlert('Security settings saved successfully!', 'success');
        }

        // ============================================================
        // CURRENCY TAB FUNCTIONS
        // ============================================================
        function refreshExchangeRates() {
            showAlert('Refreshing exchange rates...', 'info');
            setTimeout(() => showAlert('Exchange rates updated!', 'success'), 1500);
        }

        function saveCurrencySettings() {
            const form = document.getElementById('currencyForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            showAlert('Currency settings saved successfully!', 'success');
        }

        // ============================================================
        // TRANSACTION RULES TAB FUNCTIONS
        // ============================================================
        function saveTransactionRules() {
            showAlert('Transaction rules saved successfully!', 'success');
        }

        // ============================================================
        // RECONCILIATION TAB FUNCTIONS
        // ============================================================
        function runReconciliation() {
            showAlert('Running reconciliation...', 'info');
            setTimeout(() => {
                Math.random() > 0.1 ? showAlert('Reconciliation complete! All transactions match.', 'success') : showAlert('Reconciliation complete! 5 discrepancies found.', 'warning');
            }, 2000);
        }

        function saveReconciliationSettings() {
            showAlert('Reconciliation settings saved successfully!', 'success');
        }

        // ============================================================
        // AUDIT LOGS TAB FUNCTIONS
        // ============================================================
        function viewLogDetails(id) {
            showAlert('Viewing log details for: ' + id, 'info');
        }

        function exportLogs() {
            showAlert('Exporting logs...', 'info');
            setTimeout(() => showAlert('Logs exported!', 'success'), 1500);
        }

        function clearLogs() {
            if (confirm('Clear all logs?')) showAlert('Logs cleared!', 'danger');
        }

        // ============================================================
        // INITIALIZE
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            switch (ACTIVE_TAB) {
                case 'overview':
                    loadOverviewData();
                    break;
                case 'providers':
                    loadProviders();
                    break;
                case 'hubtel':
                    loadHubtelConfig();
                    break;
                case 'bank':
                    loadBankConfig();
                    break;
                default:
                    loadOverviewData();
                    break;
            }
        });
    </script>
</body>

</html>