<?php

/**
 * Sidebar partial — the single source of truth for the tenant-facing
 * sidebar.
 *
 * @package Student 360
 * @subpackage Views\Partials
 * @version 1.0.8
 * @filepath app/views/partials/sidebar.php
 *
 * v1.0.8 change (2026-10-07) [CLASS PROGRESSION]:
 *   The Academic sub-menu's Structure subgroup gains a "Class
 *   Progression" entry, immediately after "Classes" and before
 *   "Curriculum". The entry links to
 *   /platform/tenant/academic/class-progression.php.
 *   The v1.0.7 Report Phrases entry in the Assessment subgroup is
 *   preserved unchanged.
 *
 * v1.0.7 change (2026-10-07) [REPORT PHRASES]:
 *   The Academic sub-menu gains a "Report Phrases" entry in the
 *   Assessment subgroup, between Promotion Rules and the end of the
 *   subgroup. The entry links to
 *   /platform/tenant/academic/report-phrases.php.
 *
 * v1.0.6 change (2026-10-07) [REPORTS]:
 *   The sidebar gains a new top-level "Reports" item in the
 *   Management group, immediately after "Academic" and before
 *   "Subscription". It links to /platform/tenant/reports/index.php.
 *
 * v1.0.5 change (2026-10-07) [SCROLL FIX]:
 *   The sidebar's inner nav area is an independently scrollable
 *   region so the entire sub-menu is reachable.
 *
 * v1.0.4 change (2026-10-07) [CLASSES MODULE]:
 *   The Academic sub-menu gains a "Classes" entry in the Structure
 *   subgroup, between "Class Subjects" and "Curriculum".
 *
 * v1.0.3 change (2026-10-05) [SWEEP]:
 *   The sidebar gains a top-level "Academic" item.
 *
 * v1.0.2 change (2026-10-05) [SWEEP]:
 *   The Students item gains a conditional sub-menu.
 *
 * v1.0.1 change (2026-10-05) [SWEEP]:
 *   Extended the uniform sidebar to ten items across four labels.
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   First file of the tenant-surface sweep.
 *
 * VARIABLES READ FROM THE INCLUDING SCOPE (all optional):
 *   $currentPage  string
 *   $tenantName   string
 *   $currentUser  string
 *   $userAvatar   string
 *   $userRole     string
 *
 * WHAT THIS FILE DOES NOT DO:
 *   - It does not read $_SESSION.
 *   - It does not query the database.
 *   - It does not require any other file.
 *   - It does not define its own h().
 */

if (!function_exists('sidebar_partial_h')) {
    function sidebar_partial_h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
$__h = function_exists('h') ? 'h' : 'sidebar_partial_h';

$__currentPage = isset($currentPage) && is_string($currentPage) ? $currentPage : '';
$__tenantName  = isset($tenantName)  && $tenantName  !== ''    ? (string)$tenantName  : 'Student 360';
$__currentUser = isset($currentUser) && $currentUser !== ''    ? (string)$currentUser : 'Admin';
$__userAvatar  = isset($userAvatar)  && $userAvatar  !== ''    ? (string)$userAvatar  : strtoupper(substr($__currentUser, 0, 1));
$__userRole    = isset($userRole)    && $userRole    !== ''    ? (string)$userRole    : 'Tenant Admin';

$__currentPath = '';
if (isset($_SERVER['REQUEST_URI'])) {
    $__path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_string($__path) && $__path !== '') {
        $__currentPath = basename($__path);
    }
}

$__items = [
    ['group' => 'Main',        'label' => 'Dashboard',     'icon' => 'fas fa-tachometer-alt',  'href' => '/platform/tenant/dashboard.php',              'key' => 'dashboard'],
    ['group' => 'Institution', 'label' => 'Schools',       'icon' => 'fas fa-school',          'href' => '/platform/tenant/schools/index.php',          'key' => 'schools'],
    ['group' => 'Institution', 'label' => 'Campuses',      'icon' => 'fas fa-map-marker-alt',  'href' => '/platform/tenant/campuses/index.php',         'key' => 'campuses'],
    ['group' => 'Management',  'label' => 'Staff',         'icon' => 'fas fa-user-tie',        'href' => '/platform/tenant/staff/index.php',            'key' => 'staff'],
    [
        'group' => 'Management',
        'label' => 'Students',
        'icon'  => 'fas fa-user-graduate',
        'href'  => '/platform/tenant/students/index.php',
        'key'   => 'students',
        'sub'   => [
            ['label' => 'Biometric Registration', 'icon' => 'fas fa-fingerprint',    'href' => '/platform/tenant/students/biometric-registration.php'],
            ['label' => 'Biometric Devices',      'icon' => 'fas fa-server',         'href' => '/platform/tenant/students/biometric-devices.php'],
            ['label' => 'Device Users',           'icon' => 'fas fa-user-tag',       'href' => '/platform/tenant/students/biometric-users.php'],
            ['label' => 'Biometric Events',       'icon' => 'fas fa-stream',         'href' => '/platform/tenant/students/biometric-events.php'],
            ['label' => 'Attendance',             'icon' => 'fas fa-calendar-check', 'href' => '/platform/tenant/students/attendance.php'],
            ['label' => 'Attendance Setup',       'icon' => 'fas fa-cog',            'href' => '/platform/tenant/students/attendance-setup.php'],
            ['label' => 'Behaviour',              'icon' => 'fas fa-star-half-alt',  'href' => '/platform/tenant/students/behaviour.php'],
            ['label' => 'Discipline',             'icon' => 'fas fa-gavel',          'href' => '/platform/tenant/students/discipline.php'],
            ['label' => 'Financial Records',      'icon' => 'fas fa-money-bill-wave', 'href' => '/platform/tenant/students/financial-records.php'],
        ],
    ],
    [
        'group' => 'Management',
        'label' => 'Academic',
        'icon'  => 'fas fa-book-open',
        'href'  => '/platform/tenant/academic/dashboard.php',
        'key'   => 'academic',
        'sub'   => [
            // -- Structure -----------------------------------------------
            ['subgroup' => 'Structure',  'label' => 'Levels',              'icon' => 'fas fa-layer-group',   'href' => '/platform/tenant/academic/levels.php'],
            ['subgroup' => 'Structure',  'label' => 'Years',               'icon' => 'fas fa-calendar-alt',  'href' => '/platform/tenant/academic/years.php'],
            ['subgroup' => 'Structure',  'label' => 'Terms',               'icon' => 'fas fa-calendar-week', 'href' => '/platform/tenant/academic/terms.php'],
            ['subgroup' => 'Structure',  'label' => 'Streams',             'icon' => 'fas fa-water',         'href' => '/platform/tenant/academic/streams.php'],
            ['subgroup' => 'Structure',  'label' => 'Subjects',            'icon' => 'fas fa-book',          'href' => '/platform/tenant/academic/subjects.php'],
            ['subgroup' => 'Structure',  'label' => 'Class Subjects',      'icon' => 'fas fa-book-reader',   'href' => '/platform/tenant/academic/class-subjects.php'],
            ['subgroup' => 'Structure',  'label' => 'Classes',             'icon' => 'fas fa-door-closed',   'href' => '/platform/tenant/academic/classes.php'],
            // [v1.0.8 CLASS PROGRESSION] new entry.
            ['subgroup' => 'Structure',  'label' => 'Class Progression',   'icon' => 'fas fa-sitemap',       'href' => '/platform/tenant/academic/class-progression.php'],
            ['subgroup' => 'Structure',  'label' => 'Curriculum',          'icon' => 'fas fa-sitemap',       'href' => '/platform/tenant/academic/curriculum.php'],
            // -- Assessment ----------------------------------------------
            ['subgroup' => 'Assessment', 'label' => 'Assessment Schemes',  'icon' => 'fas fa-tasks',         'href' => '/platform/tenant/academic/assessment-schemes.php'],
            ['subgroup' => 'Assessment', 'label' => 'Grading Scales',      'icon' => 'fas fa-chart-line',    'href' => '/platform/tenant/academic/grading-scales.php'],
            ['subgroup' => 'Assessment', 'label' => 'Results',             'icon' => 'fas fa-poll',          'href' => '/platform/tenant/academic/results.php'],
            ['subgroup' => 'Assessment', 'label' => 'Result Locks',        'icon' => 'fas fa-lock',          'href' => '/platform/tenant/academic/result-locks.php'],
            ['subgroup' => 'Assessment', 'label' => 'Promotion Rules',     'icon' => 'fas fa-arrow-up',      'href' => '/platform/tenant/academic/promotion-rules.php'],
            // [v1.0.7 REPORT PHRASES] new entry. Preserved unchanged.
            ['subgroup' => 'Assessment', 'label' => 'Report Phrases',      'icon' => 'fas fa-quote-left',    'href' => '/platform/tenant/academic/report-phrases.php'],
            // -- Operations ----------------------------------------------
            ['subgroup' => 'Operations', 'label' => 'Class Offerings',     'icon' => 'fas fa-chalkboard',    'href' => '/platform/tenant/academic/class-offerings.php'],
            ['subgroup' => 'Operations', 'label' => 'Teacher Assignments', 'icon' => 'fas fa-user-tie',      'href' => '/platform/tenant/academic/teacher-assignments.php'],
            ['subgroup' => 'Operations', 'label' => 'Enrollments',         'icon' => 'fas fa-user-plus',     'href' => '/platform/tenant/academic/enrollments.php'],
            ['subgroup' => 'Operations', 'label' => 'Settings',            'icon' => 'fas fa-cog',           'href' => '/platform/tenant/academic/settings.php'],
            ['subgroup' => 'Operations', 'label' => 'Academic Dashboard',  'icon' => 'fas fa-tachometer-alt', 'href' => '/platform/tenant/academic/dashboard.php'],
        ],
    ],
    ['group' => 'Management',  'label' => 'Reports',         'icon' => 'fas fa-file-alt',         'href' => '/platform/tenant/reports/index.php',            'key' => 'reports'],
    ['group' => 'System',      'label' => 'Subscription',  'icon' => 'fas fa-crown',           'href' => '/platform/tenant/subscriptions/upgrade.php',  'key' => 'subscription'],
    ['group' => 'System',      'label' => 'Notifications', 'icon' => 'fas fa-bell',            'href' => '/platform/tenant/notifications.php',          'key' => 'notifications'],
    ['group' => 'System',      'label' => 'Settings',      'icon' => 'fas fa-cog',             'href' => '/platform/tenant/settings/index.php',         'key' => 'settings'],
    ['group' => 'System',      'label' => 'Logout',        'icon' => 'fas fa-sign-out-alt',    'href' => '/platform/logout.php',                        'key' => 'logout'],
];
?>
<?php /* [v1.0.5 SCROLL FIX] Scoped layout rules for the sidebar itself. */ ?>
<style>
    nav.sidebar#sidebar {
        height: 100vh !important;
        min-height: 100vh !important;
        max-height: 100vh !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    nav.sidebar#sidebar>.sidebar-header {
        flex: 0 0 auto !important;
    }

    nav.sidebar#sidebar>.nav {
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding-bottom: 96px !important;
        min-height: 0 !important;
    }

    nav.sidebar#sidebar>.sidebar-footer {
        position: absolute !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
    }
</style>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-graduation-cap me-2"></i>Student 360</h4>
        <small><?php echo $__h($__tenantName); ?></small>
    </div>
    <div class="nav">
        <?php
        $__lastGroup = null;
        foreach ($__items as $__item):
            if ($__item['group'] !== $__lastGroup):
                if ($__lastGroup !== null) {
                    echo '<div class="nav-label mt-3">' . $__h($__item['group']) . '</div>';
                } else {
                    echo '<div class="nav-label">' . $__h($__item['group']) . '</div>';
                }
                $__lastGroup = $__item['group'];
            endif;
            $__isActive = ($__currentPage === $__item['key']);
        ?>
            <a class="nav-link<?php echo $__isActive ? ' active' : ''; ?>"
                href="<?php echo $__h($__item['href']); ?>">
                <i class="<?php echo $__h($__item['icon']); ?>"></i>
                <span><?php echo $__h($__item['label']); ?></span>
            </a>
            <?php
            if (!empty($__item['sub']) && is_array($__item['sub']) && $__isActive):
            ?>
                <div class="nav-sub">
                    <?php
                    $__lastSubgroup = null;
                    foreach ($__item['sub'] as $__sub):
                        $__subPath = basename((string)parse_url($__sub['href'], PHP_URL_PATH));
                        $__subActive = ($__subPath !== '' && $__currentPath === $__subPath);
                        $__subgroup = isset($__sub['subgroup']) ? (string)$__sub['subgroup'] : '';
                        if ($__subgroup !== '' && $__subgroup !== $__lastSubgroup) {
                            echo '<div class="nav-subgroup-label">' . $__h($__subgroup) . '</div>';
                            $__lastSubgroup = $__subgroup;
                        }
                    ?>
                        <a class="nav-link<?php echo $__subActive ? ' active' : ''; ?>"
                            href="<?php echo $__h($__sub['href']); ?>">
                            <i class="<?php echo $__h($__sub['icon']); ?>"></i>
                            <span><?php echo $__h($__sub['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">
        <div class="d-flex justify-content-between align-items-center">
            <div class="user-info">
                <div class="user-avatar"><?php echo $__h($__userAvatar); ?></div>
                <div>
                    <div class="user-name"><?php echo $__h($__currentUser); ?></div>
                    <div class="user-role"><?php echo $__h($__userRole); ?></div>
                </div>
            </div>
            <div>
                <button class="logout-btn" onclick="logout()" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        </div>
    </div>
</nav>