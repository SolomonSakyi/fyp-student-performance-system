<?php

/**
 * Subscription Plans Management
 */
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

$pageTitle = 'Subscription Plans - EduTrack Platform';
$currentPage = 'subscriptions';

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
        }

        .sidebar {
            background: #1a1a2e !important;
            min-height: 100vh;
        }

        .sidebar .nav-link {
            color: #a8b2d1;
            padding: 10px 20px;
            border-radius: 8px;
            margin: 2px 10px;
            transition: all 0.3s;
            text-decoration: none;
            display: block;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: rgba(26, 60, 110, 0.8);
            color: #fff;
        }

        .sidebar .nav-link i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .topbar {
            background: #fff;
            padding: 12px 25px;
            border-bottom: 1px solid #e9ecf;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar h4 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 20px;
        }

        .topbar h4 i {
            color: #1a3c6e;
            margin-right: 10px;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            background: #fff;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .status-badge {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-action {
            padding: 4px 8px;
            margin: 0 2px;
            border-radius: 6px;
            font-size: 13px;
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #1a3c6e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 15px;
        }

        .plan-card {
            border: 2px solid #e9ecf;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
            background: #fff;
        }

        .plan-card:hover {
            border-color: #1a3c6e;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .plan-card .price {
            font-size: 28px;
            font-weight: 700;
            color: #1a3c6e;
        }

        .plan-card .price small {
            font-size: 16px;
            font-weight: 400;
            color: #6c757d;
        }

        .plan-card .features {
            list-style: none;
            padding: 0;
            margin: 15px 0;
        }

        .plan-card .features li {
            padding: 5px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }

        .plan-card .features li:last-child {
            border-bottom: none;
        }

        .plan-card .features li i {
            color: #28a745;
            margin-right: 8px;
        }

        .plan-card.border-primary {
            border-color: #1a3c6e;
        }

        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-custom {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            border-left: 4px solid #1a3c6e;
            padding: 14px 18px;
            margin-top: 8px;
            animation: slideIn 0.3s ease;
        }

        .toast-custom.success {
            border-left-color: #28a745;
        }

        .toast-custom.danger {
            border-left-color: #dc3545;
        }

        .toast-custom.warning {
            border-left-color: #ffc107;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }

            .topbar {
                flex-wrap: wrap;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar vh-100 collapse" id="sidebar">
                <div class="position-sticky pt-0">
                    <div class="text-center py-3 border-bottom border-secondary">
                        <h5 class="text-white mb-0"><i class="fas fa-graduation-cap text-warning"></i> <span class="text-white">EduTrack</span></h5>
                        <small class="text-muted">Platform Administration</small>
                    </div>
                    <ul class="nav flex-column mt-3">
                        <li class="nav-item"><a class="nav-link" href="/platform/index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="/platform/tenants/index.php"><i class="fas fa-building"></i> Tenants</a></li>
                        <li class="nav-item"><a class="nav-link" href="/platform/schools/index.php"><i class="fas fa-school"></i> Schools</a></li>
                        <li class="nav-item"><a class="nav-link" href="/platform/campuses/index.php"><i class="fas fa-map-marker-alt"></i> Campuses</a></li>
                        <li class="nav-item"><a class="nav-link active" href="/platform/subscriptions/index.php"><i class="fas fa-crown"></i> Subscriptions</a></li>
                        <li class="nav-divider"></li>
                        <li class="nav-item"><a class="nav-link text-danger" href="#" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-0">
                <div class="topbar d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-link d-md-none me-2" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar">
                            <i class="fas fa-bars fs-5"></i>
                        </button>
                        <h4><i class="fas fa-list"></i> Subscription Plans</h4>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <a href="/platform/subscriptions/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                        <a href="/platform/subscriptions/create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Plan</a>
                        <div class="user-avatar">A</div>
                    </div>
                </div>

                <div class="p-3 p-md-4">
                    <!-- Plans Grid -->
                    <div id="plansContainer">
                        <div class="text-center py-5">
                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                            <p class="mt-2 text-muted">Loading plans...</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            'use strict';

            const API_BASE = '<?php echo $apiBase; ?>';
            var token = localStorage.getItem('platform_token');
            if (!token) {
                window.location.href = '/platform/login.php';
                return;
            }

            function logout() {
                if (confirm('Are you sure you want to logout?')) {
                    localStorage.removeItem('platform_token');
                    localStorage.removeItem('platform_user');
                    localStorage.removeItem('platform_refresh_token');
                    window.location.href = '/platform/login.php';
                }
            }
            window.logout = logout;

            function showToast(type, message) {
                var container = document.getElementById('toastContainer');
                var colors = {
                    success: '#28a745',
                    danger: '#dc3545',
                    warning: '#ffc107',
                    info: '#17a2b8'
                };
                var icons = {
                    success: 'fa-check-circle',
                    danger: 'fa-exclamation-circle',
                    warning: 'fa-exclamation-triangle',
                    info: 'fa-info-circle'
                };
                var toast = document.createElement('div');
                toast.className = 'toast-custom ' + type;
                toast.innerHTML = '<div class="d-flex align-items-center"><i class="fas ' + (icons[type] || 'fa-info-circle') + ' me-2" style="color: ' + (colors[type] || '#1a3c6e') + ';"></i><span>' + message + '</span><button class="btn btn-sm btn-link ms-auto text-secondary" onclick="this.closest(\'.toast-custom\').remove()"><i class="fas fa-times"></i></button></div>';
                container.appendChild(toast);
                setTimeout(function() {
                    if (toast.parentNode) toast.remove();
                }, 5000);
            }
            window.showToast = showToast;

            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function formatCurrency(amount, currency) {
                if (!currency) currency = 'GHS';
                var symbol = currency === 'GHS' ? '₵' : currency === 'USD' ? '$' : currency === 'GBP' ? '£' : currency === 'EUR' ? '€' : '';
                return symbol + parseFloat(amount).toFixed(2);
            }

            function getPlanTypeBadge(type) {
                var colors = {
                    'free_trial': 'info',
                    'monthly': 'primary',
                    'quarterly': 'success',
                    'annual': 'warning',
                    'enterprise': 'danger',
                    'custom': 'secondary'
                };
                var labels = {
                    'free_trial': 'Free Trial',
                    'monthly': 'Monthly',
                    'quarterly': 'Quarterly',
                    'annual': 'Annual',
                    'enterprise': 'Enterprise',
                    'custom': 'Custom'
                };
                return '<span class="badge bg-' + (colors[type] || 'secondary') + '">' + (labels[type] || type) + '</span>';
            }

            async function loadPlans() {
                var container = document.getElementById('plansContainer');

                try {
                    // CORRECTED API URL - using index.php with parameters
                    var response = await fetch(API_BASE + '/index.php?endpoint=subscriptions&action=plans', {
                        headers: {
                            'Authorization': 'Bearer ' + localStorage.getItem('platform_token')
                        }
                    });
                    var result = await response.json();

                    if (result.success && result.data) {
                        var plans = result.data;
                        if (plans.length === 0) {
                            container.innerHTML = '<div class="text-center py-5"><i class="fas fa-crown fa-3x text-muted mb-3 d-block"></i><p class="text-muted">No subscription plans found.</p><a href="/platform/subscriptions/create.php" class="btn btn-primary">Create First Plan</a></div>';
                            return;
                        }

                        var html = '<div class="row g-4">';
                        for (var i = 0; i < plans.length; i++) {
                            var p = plans[i];
                            var features = p.features ? Object.keys(p.features) : [];
                            var isDefault = p.is_default ? 'border-primary' : '';

                            html += '<div class="col-md-6 col-lg-4">';
                            html += '<div class="plan-card ' + isDefault + '">';
                            html += '<div class="d-flex justify-content-between align-items-start">';
                            html += '<div><h5 class="mb-0">' + escapeHtml(p.plan_name) + '</h5><small class="text-muted">' + escapeHtml(p.plan_code) + '</small></div>';
                            html += '<div>' + getPlanTypeBadge(p.plan_type) + '</div>';
                            html += '</div>';
                            html += '<div class="price mt-2">' + formatCurrency(p.price, p.currency) + ' <small>/ ' + (p.billing_cycle || 'month') + '</small></div>';
                            html += '<div class="mt-2">';
                            html += '<span class="badge bg-secondary me-1">Students: ' + (p.max_students || '∞') + '</span>';
                            html += '<span class="badge bg-secondary me-1">Staff: ' + (p.max_staff || '∞') + '</span>';
                            html += '<span class="badge bg-secondary">Campuses: ' + (p.max_campuses || '∞') + '</span>';
                            html += '</div>';
                            if (p.trial_days > 0) {
                                html += '<div class="mt-2"><span class="badge bg-info">' + p.trial_days + ' days trial</span></div>';
                            }
                            if (features.length > 0) {
                                html += '<ul class="features">';
                                for (var j = 0; j < features.length; j++) {
                                    var featureKey = features[j];
                                    var featureLabel = featureKey.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                                        return l.toUpperCase();
                                    });
                                    html += '<li><i class="fas fa-check-circle"></i> ' + escapeHtml(featureLabel) + '</li>';
                                }
                                html += '</ul>';
                            }
                            html += '<div class="d-flex gap-2 mt-3">';
                            html += '<a href="/platform/subscriptions/edit.php?id=' + p.id + '" class="btn btn-outline-primary btn-sm flex-grow-1"><i class="fas fa-edit"></i> Edit</a>';
                            if (p.is_default) {
                                html += '<span class="btn btn-success btn-sm flex-grow-1"><i class="fas fa-check"></i> Default</span>';
                            } else {
                                html += '<button class="btn btn-outline-success btn-sm flex-grow-1" onclick="setDefault(' + p.id + ')"><i class="fas fa-star"></i> Set Default</button>';
                            }
                            html += '<button class="btn btn-outline-danger btn-sm" onclick="deletePlan(' + p.id + ')"><i class="fas fa-trash"></i></button>';
                            html += '</div>';
                            html += '</div></div>';
                        }
                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="text-center text-danger py-5"><i class="fas fa-exclamation-circle fa-2x"></i><p class="mt-2">' + (result.message || 'Error loading plans') + '</p></div>';
                    }
                } catch (error) {
                    console.error('Error loading plans:', error);
                    container.innerHTML = '<div class="text-center text-danger py-5"><i class="fas fa-exclamation-circle fa-2x"></i><p class="mt-2">Failed to load plans</p></div>';
                }
            }

            async function setDefault(id) {
                if (!confirm('Set this plan as default?')) return;
                try {
                    var r = await fetch(API_BASE + '/index.php?endpoint=subscriptions&action=update_plan&id=' + id, {
                        method: 'PUT',
                        headers: {
                            'Authorization': 'Bearer ' + localStorage.getItem('platform_token'),
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            is_popular: 1
                        })
                    });
                    var result = await r.json();
                    if (result.success) {
                        showToast('success', 'Default plan updated');
                        loadPlans();
                    } else {
                        showToast('danger', result.message || 'Failed');
                    }
                } catch (e) {
                    showToast('danger', 'Error: ' + e.message);
                }
            }

            async function deletePlan(id) {
                if (!confirm('Delete this plan? This cannot be undone!')) return;
                try {
                    var r = await fetch(API_BASE + '/index.php?endpoint=subscriptions&action=delete_plan&id=' + id, {
                        method: 'DELETE',
                        headers: {
                            'Authorization': 'Bearer ' + localStorage.getItem('platform_token')
                        }
                    });
                    var result = await r.json();
                    if (result.success) {
                        showToast('success', 'Plan deleted');
                        loadPlans();
                    } else {
                        showToast('danger', result.message || 'Deletion failed');
                    }
                } catch (e) {
                    showToast('danger', 'Error: ' + e.message);
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                if (!localStorage.getItem('platform_token')) {
                    window.location.href = '/platform/login.php';
                    return;
                }
                loadPlans();
            });

        })();
    </script>
</body>

</html>