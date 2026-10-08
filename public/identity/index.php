<?php

/**
 * People Management - Registration Center
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/index.php
 */

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Get tenant and school context from session
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$schoolName = $_SESSION['school_name'] ?? 'School';

// If no school context, redirect to school selection
if (!$schoolId) {
    header('Location: /platform/schools/select.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Get user info
$userName = $_SESSION['first_name'] ?? 'Admin';
$userAvatar = substr($userName, 0, 1);

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

$pageTitle = 'People Registration Center - EduTrack Platform';
$currentPage = 'identity';
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
        /* GLOBAL RESET - MATCHES PLATFORM                 */
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
        /* SIDEBAR - MATCHES PLATFORM                      */
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

        .sidebar .sidebar-header .school-badge {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            color: #4facfe;
            display: inline-block;
            margin-top: 4px;
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

        /* ================================================ */
        /* SCHOOL CONTEXT BANNER                           */
        /* ================================================ */
        .school-context-banner {
            background: #fff;
            border-radius: 14px;
            padding: 12px 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .school-context-banner .school-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .school-context-banner .school-info .school-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
            flex-shrink: 0;
        }

        .school-context-banner .school-info .school-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .school-context-banner .school-info .school-id {
            font-size: 12px;
            color: #6c757d;
        }

        .school-context-banner .badge-tenant {
            background: #f0f2f5;
            color: #6c757d;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }

        .school-context-banner .badge-tenant i {
            margin-right: 4px;
        }

        /* ================================================ */
        /* STATS CARDS                                    */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: default;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: 16px 16px 0 0;
            opacity: 0;
            transition: opacity 0.4s;
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .stat-card:nth-child(1)::before {
            background: linear-gradient(90deg, #4facfe, #00f2fe);
        }

        .stat-card:nth-child(2)::before {
            background: linear-gradient(90deg, #43e97b, #38f9d7);
        }

        .stat-card:nth-child(3)::before {
            background: linear-gradient(90deg, #fa709a, #fee140);
        }

        .stat-card:nth-child(4)::before {
            background: linear-gradient(90deg, #a18cd1, #fbc2eb);
        }

        .stat-card:nth-child(5)::before {
            background: linear-gradient(90deg, #ff6b6b, #ee5a24);
        }

        .stat-card:nth-child(6)::before {
            background: linear-gradient(90deg, #fdcb6e, #f39c12);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            transition: all 0.4s;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(-5deg);
        }

        .stat-card .stat-icon.blue {
            background: rgba(79, 172, 254, 0.12);
            color: #4facfe;
        }

        .stat-card .stat-icon.green {
            background: rgba(67, 233, 123, 0.12);
            color: #28a745;
        }

        .stat-card .stat-icon.yellow {
            background: rgba(254, 225, 64, 0.12);
            color: #f59f00;
        }

        .stat-card .stat-icon.purple {
            background: rgba(161, 140, 209, 0.12);
            color: #7c3aed;
        }

        .stat-card .stat-icon.red {
            background: rgba(255, 107, 107, 0.12);
            color: #dc3545;
        }

        .stat-card .stat-icon.orange {
            background: rgba(253, 126, 20, 0.12);
            color: #fd7e14;
        }

        .stat-card .stat-info {
            flex: 1;
            min-width: 0;
        }

        .stat-card .stat-number {
            font-size: 22px;
            font-weight: 800;
            color: #1a1a2e;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #868e96;
            font-weight: 500;
            margin-top: 2px;
        }

        /* ================================================ */
        /* REGISTRATION CENTER CARDS                      */
        /* ================================================ */
        .registration-center {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .registration-card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.04);
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: #1a1a2e;
            display: block;
        }

        .registration-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.12);
            text-decoration: none;
            color: #1a1a2e;
        }

        .registration-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 0 0 20px 20px;
            opacity: 0;
            transition: opacity 0.4s;
        }

        .registration-card:hover::after {
            opacity: 1;
        }

        .registration-card.student::after {
            background: linear-gradient(90deg, #4facfe, #00f2fe);
        }

        .registration-card.staff::after {
            background: linear-gradient(90deg, #43e97b, #38f9d7);
        }

        .registration-card .card-icon {
            font-size: 56px;
            margin-bottom: 16px;
            display: block;
        }

        .registration-card .card-icon.student-icon {
            background: linear-gradient(135deg, #4facfe22, #00f2fe22);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 36px;
        }

        .registration-card .card-icon.staff-icon {
            background: linear-gradient(135deg, #43e97b22, #38f9d722);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 36px;
        }

        .registration-card .card-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .registration-card .card-title.student-title {
            color: #4facfe;
        }

        .registration-card .card-title.staff-title {
            color: #28a745;
        }

        .registration-card .card-description {
            color: #6c757d;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 0;
        }

        .registration-card .card-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 12px;
        }

        .registration-card .card-badge.student-badge {
            background: rgba(79, 172, 254, 0.12);
            color: #4facfe;
        }

        .registration-card .card-badge.staff-badge {
            background: rgba(40, 167, 69, 0.12);
            color: #28a745;
        }

        /* ================================================ */
        /* RECENT REGISTRATIONS                            */
        /* ================================================ */
        .recent-table {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .recent-table .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .recent-table .table-header h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .recent-table .table-body {
            padding: 0;
            overflow-x: auto;
        }

        .recent-table .table-body table {
            margin: 0;
            width: 100%;
        }

        .recent-table .table-body table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            padding: 12px 16px;
            border-bottom: 2px solid #f0f2f5;
            white-space: nowrap;
        }

        .recent-table .table-body table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .recent-table .table-body table tr:last-child td {
            border-bottom: none;
        }

        .recent-table .table-body table tr:hover td {
            background: #f8f9fa;
        }

        /* ================================================ */
        /* BADGES                                         */
        /* ================================================ */
        .badge-status {
            padding: 4px 12px;
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

        .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        .badge-status.suspended {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.archived {
            background: #e9ecef;
            color: #6c757d;
        }

        .badge-type {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-type.student {
            background: #cce5ff;
            color: #004085;
        }

        .badge-type.teacher {
            background: #d4edda;
            color: #155724;
        }

        .badge-type.staff {
            background: #e2e3e5;
            color: #383d41;
        }

        .badge-type.parent {
            background: #fff3cd;
            color: #856404;
        }

        .badge-type.guardian {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-type.admin {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* ================================================ */
        /* RESPONSIVE                                     */
        /* ================================================ */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
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

            .sidebar .sidebar-header .school-badge {
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

            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }

            .registration-center {
                grid-template-columns: 1fr;
                gap: 16px;
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

            .sidebar .sidebar-header .school-badge {
                display: inline-block;
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

            .school-context-banner {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .stat-card {
                padding: 14px 16px;
            }

            .stat-card .stat-number {
                font-size: 18px;
            }

            .stat-card .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .registration-card {
                padding: 28px 20px;
            }

            .registration-card .card-icon.student-icon,
            .registration-card .card-icon.staff-icon {
                width: 64px;
                height: 64px;
                font-size: 28px;
            }

            .registration-card .card-title {
                font-size: 18px;
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

            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-card {
                padding: 10px 12px;
            }

            .stat-card .stat-number {
                font-size: 16px;
            }

            .stat-card .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .stat-card .stat-label {
                font-size: 10px;
            }

            .registration-card {
                padding: 20px 16px;
            }

            .registration-card .card-icon.student-icon,
            .registration-card .card-icon.staff-icon {
                width: 56px;
                height: 56px;
                font-size: 24px;
            }

            .registration-card .card-title {
                font-size: 16px;
            }

            .registration-card .card-description {
                font-size: 13px;
            }

            .recent-table .table-header {
                padding: 12px 14px;
            }

            .recent-table .table-body table th,
            .recent-table .table-body table td {
                padding: 6px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar Toggle -->
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar -->
            <nav class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h4><i class="fas fa-graduation-cap me-2"></i>EduTrack</h4>
                    <small><?php echo htmlspecialchars($schoolName); ?></small>
                    <div class="school-badge"><i class="fas fa-building me-1"></i>School ID: <?php echo $schoolId; ?></div>
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

                    <div class="nav-label mt-3">People</div>
                    <a class="nav-link" href="/platform/people/index.php">
                        <i class="fas fa-user-friends"></i> <span>People Directory</span>
                    </a>
                    <a class="nav-link active" href="/identity/index.php">
                        <i class="fas fa-user-plus"></i> <span>Register</span>
                    </a>
                    <a class="nav-link" href="/identity/students.php">
                        <i class="fas fa-user-graduate"></i> <span>Students</span>
                    </a>
                    <a class="nav-link" href="/identity/staff.php">
                        <i class="fas fa-user-tie"></i> <span>Staff</span>
                    </a>
                    <a class="nav-link" href="/identity/relationships.php">
                        <i class="fas fa-users"></i> <span>Relationships</span>
                    </a>

                    <div class="nav-label mt-3">Institution</div>
                    <a class="nav-link" href="/platform/schools/index.php">
                        <i class="fas fa-school"></i> <span>Schools</span>
                    </a>
                    <a class="nav-link" href="/platform/campuses/index.php">
                        <i class="fas fa-map-marker-alt"></i> <span>Campuses</span>
                    </a>

                    <div class="nav-label mt-3">System</div>
                    <a class="nav-link" href="/platform/audit/index.php">
                        <i class="fas fa-history"></i> <span>Audit Logs</span>
                    </a>
                    <a class="nav-link" href="/platform/monitoring/index.php">
                        <i class="fas fa-chart-line"></i> <span>Monitoring</span>
                    </a>
                    <a class="nav-link" href="/platform/settings/index.php">
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

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-plus me-2"></i>People Registration Center</h1>
                        <p>Register students and staff for <?php echo htmlspecialchars($schoolName); ?></p>
                    </div>
                    <div class="header-actions">
                        <span id="lastUpdated" style="font-size:12px;color:#868e96;">
                            <i class="fas fa-clock me-1"></i> Updated: Just now
                        </span>
                        <button class="btn btn-outline-secondary" onclick="refreshDashboard()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <a href="/platform/people/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> People Directory
                        </a>
                    </div>
                </div>

                <!-- School Context Banner -->
                <div class="school-context-banner">
                    <div class="school-info">
                        <div class="school-icon"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="school-name"><?php echo htmlspecialchars($schoolName); ?></div>
                            <div class="school-id">School ID: <?php echo $schoolId; ?> | Tenant ID: <?php echo $tenantId; ?></div>
                        </div>
                    </div>
                    <div>
                        <span class="badge-tenant"><i class="fas fa-building"></i> Tenant: <?php echo $tenantId; ?></span>
                        <span class="badge-tenant ms-2"><i class="fas fa-check-circle text-success"></i> School Context Active</span>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Stats -->
                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <div class="stat-number" id="totalPeople">0</div>
                            <div class="stat-label">Total People</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info">
                            <div class="stat-number" id="activePeople">0</div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow"><i class="fas fa-user-clock"></i></div>
                        <div class="stat-info">
                            <div class="stat-number" id="pendingPeople">0</div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-user-graduate"></i></div>
                        <div class="stat-info">
                            <div class="stat-number" id="studentCount">0</div>
                            <div class="stat-label">Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-user-tie"></i></div>
                        <div class="stat-info">
                            <div class="stat-number" id="staffCount">0</div>
                            <div class="stat-label">Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <div class="stat-number" id="guardianCount">0</div>
                            <div class="stat-label">Guardians</div>
                        </div>
                    </div>
                </div>

                <!-- Registration Center -->
                <div class="registration-center">
                    <!-- Add Student Card -->
                    <a href="/identity/register-student.php" class="registration-card student">
                        <div class="card-icon student-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="card-title student-title">Add Student</div>
                        <p class="card-description">
                            Register a new student with complete profile including<br>
                            personal information, parent/guardian, health records,<br>
                            identity documents, and academic assignment.
                        </p>
                        <span class="card-badge student-badge">
                            <i class="fas fa-arrow-right me-1"></i> Start Registration
                        </span>
                    </a>

                    <!-- Add Staff Card -->
                    <a href="/identity/register-staff.php" class="registration-card staff">
                        <div class="card-icon staff-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="card-title staff-title">Add Staff</div>
                        <p class="card-description">
                            Register a new staff member with complete profile including<br>
                            employment information, qualifications, professional records,<br>
                            and identity documents.
                        </p>
                        <span class="card-badge staff-badge">
                            <i class="fas fa-arrow-right me-1"></i> Start Registration
                        </span>
                    </a>
                </div>

                <!-- Recent Registrations -->
                <div class="recent-table">
                    <div class="table-header">
                        <h6><i class="fas fa-clock me-2 text-primary"></i>Recent Registrations</h6>
                        <div class="table-actions">
                            <span class="text-muted small" id="recentCount">0 people</span>
                        </div>
                    </div>
                    <div class="table-body" id="recentTableContainer">
                        <!-- Table will be rendered by JavaScript -->
                        <div class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            Loading recent registrations...
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- ================================================ -->
    <!-- JAVASCRIPT                                      -->
    <!-- ================================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';

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
        // GET HEADERS
        // ================================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json',
                'X-Tenant-ID': '<?php echo $tenantId; ?>',
                'X-School-ID': '<?php echo $schoolId; ?>'
            };
        }

        // ================================================
        // FORMAT HELPERS
        // ================================================
        function getStatusBadge(status) {
            const labels = {
                'active': 'Active',
                'inactive': 'Inactive',
                'pending': 'Pending',
                'suspended': 'Suspended',
                'archived': 'Archived'
            };
            const classes = {
                'active': 'active',
                'inactive': 'inactive',
                'pending': 'pending',
                'suspended': 'suspended',
                'archived': 'archived'
            };
            return `<span class="badge-status ${classes[status] || 'pending'}">${labels[status] || status}</span>`;
        }

        function getTypeBadge(type) {
            const labels = {
                'student': 'Student',
                'teacher': 'Teacher',
                'staff': 'Staff',
                'admin': 'Admin',
                'parent': 'Parent',
                'guardian': 'Guardian',
                'alumni': 'Alumni',
                'visitor': 'Visitor',
                'contractor': 'Contractor',
                'volunteer': 'Volunteer'
            };
            const classes = {
                'student': 'student',
                'teacher': 'teacher',
                'staff': 'staff',
                'admin': 'admin',
                'parent': 'parent',
                'guardian': 'guardian',
                'alumni': 'student',
                'visitor': 'staff',
                'contractor': 'staff',
                'volunteer': 'staff'
            };
            return `<span class="badge-type ${classes[type] || 'staff'}">${labels[type] || type}</span>`;
        }

        function getFullName(person) {
            let name = person.first_name || '';
            if (person.middle_name) name += ' ' + person.middle_name;
            if (person.last_name) name += ' ' + person.last_name;
            return name.trim() || 'Unknown';
        }

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        // ================================================
        // SHOW ALERT
        // ================================================
        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            container.innerHTML = `
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border: none;box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
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

        // ================================================
        // DASHBOARD CONTROLLER
        // ================================================
        const DashboardController = {
            state: {
                stats: {},
                recent: [],
                isLoading: false
            },

            init: function() {
                loadUserInfo();
                this.loadStats();
                this.loadRecent();
            },

            loadStats: function() {
                fetch(API_BASE + '/index.php?endpoint=identity&action=stats', {
                        headers: getHeaders()
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            this.state.stats = result.data || {};
                            this.renderStats();
                        }
                    })
                    .catch(error => console.error('Error loading stats:', error));
            },

            renderStats: function() {
                const stats = this.state.stats;
                document.getElementById('totalPeople').textContent = stats.total || 0;
                document.getElementById('activePeople').textContent = stats.active || 0;
                document.getElementById('pendingPeople').textContent = stats.pending || 0;
                document.getElementById('studentCount').textContent = stats.students || 0;
                document.getElementById('staffCount').textContent = stats.staff || 0;
                document.getElementById('guardianCount').textContent = stats.guardians || 0;
            },

            loadRecent: function() {
                const container = document.getElementById('recentTableContainer');

                fetch(API_BASE + '/index.php?endpoint=identity&action=list&limit=10', {
                        headers: getHeaders()
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            this.state.recent = result.data || [];
                            this.renderRecent();
                        } else {
                            container.innerHTML = `
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-exclamation-circle me-2"></i> Failed to load recent registrations
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error loading recent:', error);
                        container.innerHTML = `
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-exclamation-circle me-2"></i> Error loading recent registrations
                            </div>
                        `;
                    });
            },

            renderRecent: function() {
                const container = document.getElementById('recentTableContainer');

                if (this.state.recent.length === 0) {
                    container.innerHTML = `
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                            <p>No recent registrations</p>
                            <a href="/identity/register-student.php" class="btn btn-sm btn-primary mt-2">
                                <i class="fas fa-plus me-1"></i> Register First Student
                            </a>
                        </div>
                    `;
                    document.getElementById('recentCount').textContent = '0 people';
                    return;
                }

                let html = `
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Person Number</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th style="width:80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                this.state.recent.forEach((person, index) => {
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td><code style="background:#f0f2f5;padding:2px 8px;border-radius:4px;font-size:12px;">${person.person_number || 'N/A'}</code></td>
                            <td><strong>${getFullName(person)}</strong></td>
                            <td>${getTypeBadge(person.person_type)}</td>
                            <td>${getStatusBadge(person.status)}</td>
                            <td style="font-size:13px;color:#6c757d;">${formatDate(person.created_at)}</td>
                            <td>
                                <a href="/identity/view.php?id=${person.id}" class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    `;
                });

                html += `</tbody></table>`;
                container.innerHTML = html;
                document.getElementById('recentCount').textContent = this.state.recent.length + ' people';
            },

            refresh: function() {
                this.loadStats();
                this.loadRecent();
                showAlert('Dashboard refreshed successfully', 'success');
            }
        };

        // ================================================
        // REFRESH DASHBOARD
        // ================================================
        function refreshDashboard() {
            DashboardController.refresh();
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            DashboardController.init();
        });
    </script>
</body>

</html>