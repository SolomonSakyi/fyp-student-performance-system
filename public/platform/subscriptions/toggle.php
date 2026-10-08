<?php

/**
 * Toggle Subscription Plan Status - Super Admin
 * 
 * @package EduTrack
 * @subpackage Platform\Subscriptions
 * @filepath public/platform/subscriptions/toggle.php
 */

session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// Load config and database
$projectRoot = dirname(__DIR__, 3);
if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$planId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';

if (!$planId || !in_array($status, ['active', 'inactive'])) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Invalid request') . '&type=danger');
    exit;
}

// Check if plan exists
$plan = $db->fetchOne("SELECT id, plan_name FROM subscription_plans WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')", [$planId]);
if (!$plan) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Plan not found') . '&type=danger');
    exit;
}

// Update plan status
$newStatus = ($status === 'active') ? 1 : 0;
$db->execute("UPDATE subscription_plans SET is_active = ? WHERE id = ?", [$newStatus, $planId]);

$message = 'Plan "' . htmlspecialchars($plan['plan_name']) . '" has been ' . ($newStatus ? 'activated' : 'deactivated') . '.';
header('Location: /platform/subscriptions/index.php?message=' . urlencode($message) . '&type=success');
exit;
