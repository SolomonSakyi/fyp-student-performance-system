<?php
/**
 * sidebar.php
 * Admin Sidebar Navigation - Complete with Finance Module
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100%;
    background: #1a237e;
    color: #fff;
    padding: 20px 0;
    overflow-y: auto;
    z-index: 1000;
}
.sidebar .logo {
    text-align: center;
    padding: 10px 0 20px;
    border-bottom: 1px solid #283593;
    font-size: 20px;
    font-weight: bold;
}
.sidebar .logo span {
    color: #ffd54f;
}
.sidebar .nav-section {
    padding: 15px 25px 5px;
    font-size: 10px;
    text-transform: uppercase;
    color: #7986cb;
    letter-spacing: 1px;
    font-weight: 600;
}
.sidebar .nav-item {
    display: block;
    padding: 10px 25px;
    color: #c5cae9;
    text-decoration: none;
    font-size: 14px;
    transition: 0.3s;
    border-left: 3px solid transparent;
}
.sidebar .nav-item:hover {
    background: #283593;
    color: #fff;
    border-left-color: #ffd54f;
}
.sidebar .nav-item.active {
    background: #283593;
    color: #fff;
    border-left-color: #ffd54f;
}
.sidebar .nav-item .icon {
    margin-right: 10px;
    width: 20px;
    display: inline-block;
    text-align: center;
}
.sidebar .nav-item .badge-nav {
    float: right;
    background: #ffd54f;
    color: #1a237e;
    padding: 0 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}
.main-content {
    margin-left: 250px;
    padding: 20px;
    min-height: 100vh;
}
@media (max-width: 768px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }
    .main-content {
        margin-left: 0;
    }
}
</style>

<div class="sidebar">
    <div class="logo">
        Edu<span>Track</span>
    </div>
    
    <!-- DASHBOARD -->
    <div class="nav-section">Navigation</div>
    <a href="/admin/index.php" class="nav-item <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
        <span class="icon">📊</span> Dashboard
    </a>
    
    <!-- ACADEMIC -->
    <div class="nav-section">Academic</div>
    <a href="/admin/academic.php" class="nav-item <?php echo $current_page == 'academic.php' ? 'active' : ''; ?>">
        <span class="icon">📚</span> Academic
    </a>
    <a href="/admin/subjects.php" class="nav-item <?php echo $current_page == 'subjects.php' ? 'active' : ''; ?>">
        <span class="icon">📖</span> Subjects
    </a>
    <a href="/admin/classes.php" class="nav-item <?php echo $current_page == 'classes.php' ? 'active' : ''; ?>">
        <span class="icon">🏫</span> Classes
    </a>
    
    <!-- ASSESSMENT & GRADING -->
    <div class="nav-section">Assessment</div>
    <a href="/admin/assessment.php" class="nav-item <?php echo $current_page == 'assessment.php' ? 'active' : ''; ?>">
        <span class="icon">📝</span> Assessment
    </a>
    <a href="/admin/grading.php" class="nav-item <?php echo $current_page == 'grading.php' ? 'active' : ''; ?>">
        <span class="icon">📊</span> Grading
    </a>
    
    <!-- STUDENTS & STAFF -->
    <div class="nav-section">People</div>
    <a href="/admin/students.php" class="nav-item <?php echo $current_page == 'students.php' ? 'active' : ''; ?>">
        <span class="icon">👨‍🎓</span> Students
    </a>
    <a href="/admin/teachers.php" class="nav-item <?php echo $current_page == 'teachers.php' ? 'active' : ''; ?>">
        <span class="icon">👨‍🏫</span> Teachers
    </a>
    <a href="/admin/staff.php" class="nav-item <?php echo $current_page == 'staff.php' ? 'active' : ''; ?>">
        <span class="icon">👤</span> Staff
    </a>
    <a href="/admin/parents.php" class="nav-item <?php echo $current_page == 'parents.php' ? 'active' : ''; ?>">
        <span class="icon">👨‍👩‍👦</span> Parents
    </a>
    
    <!-- ATTENDANCE & FEES -->
    <div class="nav-section">Operations</div>
    <a href="/admin/attendance.php" class="nav-item <?php echo $current_page == 'attendance.php' ? 'active' : ''; ?>">
        <span class="icon">📋</span> Attendance
    </a>
    <a href="/admin/fees.php" class="nav-item <?php echo $current_page == 'fees.php' ? 'active' : ''; ?>">
        <span class="icon">💰</span> Fees
    </a>
    
    <!-- FINANCE -->
    <div class="nav-section">Finance</div>
    <a href="/admin/finance/index.php" class="nav-item <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
        <span class="icon">📊</span> Dashboard
    </a>
    <a href="/admin/finance/billing.php" class="nav-item <?php echo $current_page == 'billing.php' ? 'active' : ''; ?>">
        <span class="icon">📋</span> Billing
    </a>
    <a href="/admin/finance/payments.php" class="nav-item <?php echo $current_page == 'payments.php' ? 'active' : ''; ?>">
        <span class="icon">💳</span> Payments
    </a>
    <a href="/admin/finance/reports.php" class="nav-item <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
        <span class="icon">📄</span> Reports
    </a>
    <a href="/admin/finance/settings.php" class="nav-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
        <span class="icon">⚙️</span> Settings
    </a>
    
    <!-- LIBRARY -->
    <div class="nav-section">Resources</div>
    <a href="/admin/library.php" class="nav-item <?php echo $current_page == 'library.php' ? 'active' : ''; ?>">
        <span class="icon">📚</span> Library
    </a>
    
    <!-- REPORTS & SETTINGS -->
    <div class="nav-section">System</div>
    <a href="/admin/reports.php" class="nav-item <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
        <span class="icon">📄</span> Reports
    </a>
    <a href="/admin/users.php" class="nav-item <?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
        <span class="icon">👤</span> Users
    </a>
    <a href="/admin/settings.php" class="nav-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
        <span class="icon">⚙️</span> Settings
    </a>
    
    <!-- LOGOUT -->
    <a href="/admin/logout.php" class="nav-item" style="color: #fca5a5; border-top: 1px solid #283593; margin-top: 10px; padding-top: 15px;">
        <span class="icon">🚪</span> Logout
    </a>
</div>