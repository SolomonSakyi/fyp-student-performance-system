<?php

/**
 * Platform Sidebar Partial - the single source of truth for the
 * platform-root sidebar.
 *
 * @package EduTrack
 * @subpackage Views\Partial
 * @version 1.0
 * @filepath app/views/partials/platform-sidebar.php
 *
 * v1.0 change (2026-10-07) [SIDEBAR RECONCILIATION]:
 *   Extracted from the inline sidebar carried by
 *   public/platform/index.php v2.1. The canonical platform-root
 *   sidebar. Every platform-root page includes this file in place
 *   of its own inline sidebar. The tenant sidebar partial at
 *   app/views/partials/sidebar.php is untouched and remains the
 *   tenant-surface sidebar.
 *
 *   Canonical shape:
 *     - Brand heading: <h4><i class="fas fa-graduation-cap me-2">
 *       </i>Student 360</h4>; subtitle 'Platform Administration'.
 *     - Main group: Dashboard, Tenants, Users, Approvals (with
 *       pending badge).
 *     - Institution group: Schools, Campuses.
 *     - Management group: Subscriptions, Domains.
 *     - System group: Audit Logs, Monitoring, Settings.
 *     - Footer: avatar, user name, role 'Super Administrator',
 *       logout button.
 *     - Mobile toggle button and toggleSidebar() JS.
 *
 *   All sidebar hrefs point at folder paths under /platform/.
 *   Tenants link uses /platform/tenants/index.php per the
 *   confirmed folder listing. Settings link uses
 *   /platform/settings/index.php per the confirmed folder listing.
 *   No Register Tenant item: it is not part of the canonical shape.
 *   No .nav-subgroup-label rule: this is the platform-root sidebar,
 *   not the tenant sidebar.
 *
 *   Variables read from the including scope (all optional, with
 *   fallbacks):
 *     $currentPage      string  active-link key
 *     $currentUser      string  user name
 *     $userFirstName    string  fallback for avatar letter
 *     $userAvatar       string  avatar letter
 *     $pendingApprovals int     badge count on Approvals
 *
 *   This partial does not redeclare h(). Security.php from
 *   app/bootstrap.php provides it.
 */

$currentPage      = isset($currentPage)      ? (string)$currentPage      : '';
$currentUser      = isset($currentUser)      ? (string)$currentUser      : 'Admin';
$userFirstName    = isset($userFirstName)    ? (string)$userFirstName    : 'User';
$userAvatar       = isset($userAvatar)       ? (string)$userAvatar       : strtoupper(substr($userFirstName, 0, 1));
$pendingApprovals = isset($pendingApprovals) ? (int)$pendingApprovals    : 0;

if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
    <i class="fas fa-bars"></i>
</button>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-graduation-cap me-2"></i>Student 360</h4>
        <small>Platform Administration</small>
    </div>
    <div class="nav">
        <!-- MAIN SECTION -->
        <div class="nav-label">Main</div>
        <a class="nav-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>" href="/platform/index.php">
            <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'tenants' ? 'active' : ''; ?>" href="/platform/tenants/index.php">
            <i class="fas fa-building"></i> <span>Tenants</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'users' ? 'active' : ''; ?>" href="/platform/users/index.php">
            <i class="fas fa-users"></i> <span>Users</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'approvals' ? 'active' : ''; ?>" href="/platform/approvals/index.php">
            <i class="fas fa-check-double"></i> <span>Approvals</span>
            <?php if ($pendingApprovals > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?php echo $pendingApprovals; ?></span>
            <?php endif; ?>
        </a>

        <!-- INSTITUTION SECTION -->
        <div class="nav-label mt-3">Institution</div>
        <a class="nav-link <?php echo $currentPage === 'schools' ? 'active' : ''; ?>" href="/platform/schools/index.php">
            <i class="fas fa-school"></i> <span>Schools</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'campuses' ? 'active' : ''; ?>" href="/platform/campuses/index.php">
            <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
        </a>

        <!-- MANAGEMENT SECTION -->
        <div class="nav-label mt-3">Management</div>
        <a class="nav-link <?php echo $currentPage === 'subscriptions' ? 'active' : ''; ?>" href="/platform/subscriptions/index.php">
            <i class="fas fa-crown"></i> <span>Subscriptions</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'domains' ? 'active' : ''; ?>" href="/platform/domains/index.php">
            <i class="fas fa-globe"></i> <span>Domains</span>
        </a>

        <!-- SYSTEM SECTION -->
        <div class="nav-label mt-3">System</div>
        <a class="nav-link <?php echo $currentPage === 'audit' ? 'active' : ''; ?>" href="/platform/audit/index.php">
            <i class="fas fa-history"></i> <span>Audit Logs</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'monitoring' ? 'active' : ''; ?>" href="/platform/monitoring/index.php">
            <i class="fas fa-chart-line"></i> <span>Monitoring</span>
        </a>
        <a class="nav-link <?php echo $currentPage === 'settings' ? 'active' : ''; ?>" href="/platform/settings/index.php">
            <i class="fas fa-cog"></i> <span>Settings</span>
        </a>
    </div>
    <div class="sidebar-footer">
        <div class="d-flex justify-content-between align-items-center">
            <div class="user-info">
                <div class="user-avatar" id="userAvatar"><?php echo h($userAvatar); ?></div>
                <div>
                    <div class="user-name" id="userName"><?php echo h($currentUser); ?></div>
                    <div class="user-role" id="userRole">Super Administrator</div>
                </div>
            </div>
            <button class="logout-btn" onclick="logout()" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
        </div>
    </div>
</nav>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
    }
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        if (!sidebar || !toggle) return;
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        }
    });
    window.addEventListener('resize', function() {
        const sb = document.getElementById('sidebar');
        if (sb && window.innerWidth > 768) {
            sb.classList.remove('open');
        }
    });
</script>