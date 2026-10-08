<?php

/**
 * Delete Tenant - Soft delete a tenant
 * 
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 2.1
 * @filepath public/platform/tenants/delete.php
 *
 * v2.1 change (2026-10-08) [SWEEP + CSRF + AUTH + TX]:
 *   Platform-tenants delete handler. Three repairs.
 *
 *   AUTH: the is_super_admin guard is added immediately after the
 *   logged_in guard. Prior to this change — like tenants/view.php
 *   and tenants/edit.php before their sweeps — this file carried
 *   only the logged_in guard. Any authenticated user who knew a
 *   tenant id could POST to this handler and cascade a soft-delete
 *   across schools, campuses, platform_users, and finally tenants.
 *   The added guard matches tenants/index.php,
 *   tenants/register.php, tenants/view.php, and tenants/edit.php:
 *     if (!isset($_SESSION['is_super_admin']) ||
 *         $_SESSION['is_super_admin'] !== true) {
 *         header('Location: /platform/tenant/dashboard.php');
 *         exit;
 *     }
 *
 *   CSRF: verify_csrf() is called at the top of the DELETE
 *   handling, before the tenant_id is read. Prior to this change
 *   the handler trusted $_POST['tenant_id'] and $_POST['confirm']
 *   directly, with no CSRF verification. The three form builders
 *   that target this handler — tenants/index.php v2.1,
 *   tenants/view.php v2.1, and tenants/edit.php's sibling Delete
 *   flows — now emit a csrf_token field; before this change the
 *   field was present in the form body and ignored by the handler.
 *   The verify_csrf() call matches the project convention used by
 *   the guardian requests.php v1.1.2, the tenant notifications.php
 *   v2.6, public/platform/approvals/approve.php v1.0, and the three
 *   other swept tenants/ files.
 *
 *   TX: the four soft-delete writes are wrapped in a single
 *   transaction. Prior to this change, if any write between the
 *   first (schools) and the fourth (tenants) failed, the earlier
 *   writes stayed committed — leaving the tenant's schools,
 *   campuses, and platform_users soft-deleted while the tenant
 *   itself was not. The transaction wrap matches the shape used by
 *   tenants/register.php v2.2 and approvals/approve.php v1.0: a
 *   $transactionStarted flag guarded by its own try/catch around
 *   the beginTransaction call, so a rollback is attempted only
 *   when a transaction was actually started.
 *
 *   The dockblock carries a new v2.1 entry. The two-line summary
 *   comment the file previously carried is preserved inside this
 *   dockblock.
 *
 *   Open items on the record (NOT changed by this sweep):
 *     - The third write is
 *       UPDATE platform_users SET deleted_at = NOW()
 *       WHERE tenant_id = ?
 *       which cascades every platform_users row for the tenant,
 *       not only the tenant admin. Whether that is intended is a
 *       product decision.
 *     - No audit-log row is written for the delete.
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

// Super Admin check - matches tenants/index.php, register.php, view.php, edit.php
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// CSRF GATE
// =============================================
if (function_exists('verify_csrf')) {
    verify_csrf();
} elseif (class_exists('Security')) {
    Security::verifyCsrf();
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
// HANDLE DELETE REQUEST
// =============================================
$tenantId = isset($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : 0;
$confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';

if (!$tenantId) {
    $_SESSION['error'] = 'Invalid tenant ID';
    header('Location: /platform/tenants/index.php');
    exit;
}

// Get tenant details
$tenant = $db->fetchOne(
    "SELECT id, tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL",
    [$tenantId]
);

if (!$tenant) {
    $_SESSION['error'] = 'Tenant not found';
    header('Location: /platform/tenants/index.php');
    exit;
}

// Check if this is the current tenant
$isCurrentTenant = ($_SESSION['tenant_id'] ?? 0) == $tenantId;

// Handle confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirm) {
    $transactionStarted = false;

    try {
        $db->beginTransaction();
        $transactionStarted = true;

        // Check if tenant has schools
        $schoolCount = $db->getValue(
            "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL",
            [$tenantId]
        );

        if ($schoolCount > 0) {
            // Soft delete schools first
            $db->execute(
                "UPDATE schools SET deleted_at = NOW() WHERE tenant_id = ? AND deleted_at IS NULL",
                [$tenantId]
            );

            // Soft delete campuses
            $db->execute(
                "UPDATE campuses c 
                 JOIN schools s ON c.school_id = s.id 
                 SET c.deleted_at = NOW() 
                 WHERE s.tenant_id = ? AND c.deleted_at IS NULL",
                [$tenantId]
            );
        }

        // Soft delete platform users
        $db->execute(
            "UPDATE platform_users SET deleted_at = NOW() WHERE tenant_id = ? AND deleted_at IS NULL",
            [$tenantId]
        );

        // Soft delete the tenant
        $result = $db->execute(
            "UPDATE tenants SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL",
            [$tenantId]
        );

        if ($result) {
            $db->commit();

            // If this was the current tenant, clear session
            if ($isCurrentTenant) {
                unset($_SESSION['tenant_id']);
                unset($_SESSION['tenant_name']);
                unset($_SESSION['school_id']);
                unset($_SESSION['school_name']);
            }

            $_SESSION['success'] = 'Tenant "' . htmlspecialchars($tenant['tenant_name']) . '" deleted successfully!';
            header('Location: /platform/tenants/index.php');
            exit;
        } else {
            throw new RuntimeException('Failed to delete tenant');
        }
    } catch (Exception $e) {
        if ($transactionStarted) {
            try {
                $db->rollBack();
            } catch (Throwable $rollbackException) {
                error_log('Delete tenant rollback error: ' . $rollbackException->getMessage());
            }
        }
        error_log('Delete tenant error: ' . $e->getMessage());
        $_SESSION['error'] = 'Error deleting tenant: ' . $e->getMessage();
    }

    header('Location: /platform/tenants/index.php');
    exit;
}

// If not confirmed, redirect back with error
$_SESSION['error'] = 'Delete confirmation required';
header('Location: /platform/tenants/view.php?id=' . $tenantId);
exit;
