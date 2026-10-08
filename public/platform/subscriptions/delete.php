<?php

/**
 * Delete Subscription Plan - Super Admin
 * 
 * @package EduTrack
 * @subpackage Platform\Subscriptions
 * @filepath public/platform/subscriptions/delete.php
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

if (!$planId) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Invalid request') . '&type=danger');
    exit;
}

// Check if plan exists
$plan = $db->fetchOne("SELECT id, plan_name FROM subscription_plans WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')", [$planId]);
if (!$plan) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Plan not found') . '&type=danger');
    exit;
}

// Check if plan is in use
$inUse = $db->getValue(
    "SELECT COUNT(*) FROM tenant_subscriptions WHERE plan_id = ? AND status IN ('active', 'trial')",
    [$planId]
);

if ($inUse > 0) {
    header('Location: /platform/subscriptions/index.php?message=' . urlencode('Cannot delete plan. It is currently in use by ' . $inUse . ' active subscription(s).') . '&type=danger');
    exit;
}

// Soft delete the plan
$db->execute("UPDATE subscription_plans SET deleted_at = NOW() WHERE id = ?", [$planId]);

$message = 'Plan "' . htmlspecialchars($plan['plan_name']) . '" has been deleted successfully.';
header('Location: /platform/subscriptions/index.php?message=' . urlencode($message) . '&type=success');
exit;
