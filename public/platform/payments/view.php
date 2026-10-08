<?php

/**
 * Payment Transaction Details
 * View a single payment transaction
 */
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /platform/payments/index.php');
    exit;
}

$pageTitle = 'Payment Details - EduTrack Platform';
$currentPage = 'payments';

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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

        .top-bar .header-actions .btn {
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 14px;
        }

        .top-bar .header-actions .btn i {
            margin-right: 6px;
        }

        .detail-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 20px 24px;
            margin-bottom: 20px;
        }

        .detail-card .detail-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-card .detail-value {
            font-size: 16px;
            font-weight: 500;
            color: #1a1a2e;
            margin-top: 2px;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
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

        .badge-status.processing {
            background: #cce5ff;
            color: #004085;
        }

        .badge-status.refunded {
            background: #e8d5f5;
            color: #6f42c1;
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

            .detail-card {
                padding: 14px 16px;
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
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small>Platform Administration</small>
                </div>
                <div class="nav">
                    <div class="nav-label">Main</div>
                    <a class="nav-link" href="/platform/index.php">
                        <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                    </a>
                    <a class="nav-link" href="/platform/tenants/index.php">
                        <i class="fas fa-building"></i> <span>Tenants</span>
                    </a>
                    <a class="nav-link" href="/platform/users/index.php">
                        <i class="fas fa-users"></i> <span>Users</span>
                    </a>

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link" href="/platform/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
                    </a>

                    <div class="nav-label mt-3">Management</div>
                    <a class="nav-link" href="/platform/subscriptions/index.php">
                        <i class="fas fa-crown"></i> <span>Subscriptions</span>
                    </a>
                    <a class="nav-link" href="/platform/domains/index.php">
                        <i class="fas fa-globe"></i> <span>Domains</span>
                    </a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/audit/index.php">
                        <i class="fas fa-history"></i> <span>Audit Logs</span>
                    </a>
                    <a class="nav-link" href="/platform/monitoring/index.php">
                        <i class="fas fa-chart-line"></i> <span>Monitoring</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage == 'settings') ? 'active' : ''; ?>" href="/platform/settings/index.php">
                        <i class="fas fa-cog"></i> <span>Settings</span>
                    </a>
                </div>
                <div class="sidebar-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="user-info">
                            <div class="user-avatar" id="userAvatar">A</div>
                            <div>
                                <div class="user-name" id="userName">Admin</div>
                                <div class="user-role" id="userRole">Administrator</div>
                            </div>
                        </div>
                        <button class="logout-btn" onclick="logout()" title="Logout">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </div>
                </div>
            </nav>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-credit-card me-2"></i>Payment Details</h1>
                        <p>View transaction information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/payments/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Payments
                        </a>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <div id="transactionDetails">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading transaction details...</p>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';
        const TRANSACTION_ID = <?php echo $id; ?>;

        // ================================================
        // SIDEBAR TOGGLE
        // ================================================
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php';
            }
        }

        // ================================================
        // API HELPERS
        // ================================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json'
            };
        }

        function formatCurrency(amount) {
            if (!amount || amount == 0) return 'GHS 0';
            return 'GHS ' + parseFloat(amount).toFixed(2);
        }

        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function getStatusBadge(status) {
            const map = {
                'SUCCESS': '<span class="badge-status success">Success</span>',
                'PENDING': '<span class="badge-status pending">Pending</span>',
                'PROCESSING': '<span class="badge-status processing">Processing</span>',
                'FAILED': '<span class="badge-status failed">Failed</span>',
                'CANCELLED': '<span class="badge-status failed">Cancelled</span>',
                'EXPIRED': '<span class="badge-status failed">Expired</span>',
                'REFUNDED': '<span class="badge-status refunded">Refunded</span>'
            };
            return map[status] || '<span class="badge-status">' + status + '</span>';
        }

        // ================================================
        // LOAD TRANSACTION DETAILS
        // ================================================
        async function loadTransaction() {
            const container = document.getElementById('transactionDetails');

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=payments&action=get&id=${TRANSACTION_ID}`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                if (result.success && result.data) {
                    const tx = result.data;
                    const statusBadge = getStatusBadge(tx.status);

                    container.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-card">
                                    <h6 class="mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Transaction Information</h6>
                                    <div class="row">
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Reference</div>
                                            <div class="detail-value"><strong>${tx.internal_reference || 'N/A'}</strong></div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Provider Reference</div>
                                            <div class="detail-value">${tx.provider_transaction_id || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Amount</div>
                                            <div class="detail-value" style="font-size:20px;color:#28a745;">${formatCurrency(tx.amount)}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Status</div>
                                            <div class="detail-value">${statusBadge}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Payment Method</div>
                                            <div class="detail-value">${tx.payment_method || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Provider</div>
                                            <div class="detail-value">${tx.provider_name || tx.provider || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Tenant</div>
                                            <div class="detail-value">${tx.tenant_name || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Customer</div>
                                            <div class="detail-value">${tx.customer_name || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Date Created</div>
                                            <div class="detail-value">${formatDateTime(tx.created_at)}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Last Updated</div>
                                            <div class="detail-value">${formatDateTime(tx.updated_at)}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-card">
                                    <h6 class="mb-3"><i class="fas fa-cog me-2 text-primary"></i>Additional Information</h6>
                                    <div class="row">
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Verification Status</div>
                                            <div class="detail-value">${tx.verification_status || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-3">
                                            <div class="detail-label">Reconciliation Status</div>
                                            <div class="detail-value">${tx.reconciliation_status || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-12 mb-3">
                                            <div class="detail-label">Description</div>
                                            <div class="detail-value" style="font-weight:400;">${tx.description || 'No description provided'}</div>
                                        </div>
                                        <div class="col-sm-12 mb-3">
                                            <div class="detail-label">Provider Response</div>
                                            <div class="detail-value" style="font-weight:400;font-size:13px;word-break:break-all;">
                                                ${tx.provider_response_message || 'No response recorded'}
                                            </div>
                                        </div>
                                        ${tx.metadata ? `
                                        <div class="col-sm-12 mb-3">
                                            <div class="detail-label">Metadata</div>
                                            <pre style="background:#f8f9fa;padding:10px;border-radius:8px;font-size:12px;max-height:150px;overflow:auto;">${typeof tx.metadata === 'object' ? JSON.stringify(tx.metadata, null, 2) : tx.metadata}</pre>
                                        </div>
                                        ` : ''}
                                    </div>
                                </div>

                                <div class="detail-card">
                                    <h6 class="mb-3"><i class="fas fa-clock me-2 text-warning"></i>Timeline</h6>
                                    <div class="row">
                                        <div class="col-sm-6 mb-2">
                                            <div class="detail-label">Initiated</div>
                                            <div class="detail-value" style="font-size:13px;">${formatDateTime(tx.initiated_at) || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-2">
                                            <div class="detail-label">Processed</div>
                                            <div class="detail-value" style="font-size:13px;">${formatDateTime(tx.processed_at) || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-2">
                                            <div class="detail-label">Completed</div>
                                            <div class="detail-value" style="font-size:13px;">${formatDateTime(tx.completed_at) || 'N/A'}</div>
                                        </div>
                                        <div class="col-sm-6 mb-2">
                                            <div class="detail-label">Failed</div>
                                            <div class="detail-value" style="font-size:13px;">${formatDateTime(tx.failed_at) || 'N/A'}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    container.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${result.message || 'Failed to load transaction details'}
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading transaction:', error);
                container.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading transaction details: ${error.message}
                    </div>
                `;
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
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadTransaction();
        });
    </script>
</body>

</html>