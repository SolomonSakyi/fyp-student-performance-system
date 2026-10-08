<?php

/**
 * Subscription Middleware - Check limits before resource creation
 * 
 * @package EduTrack
 * @subpackage Middleware
 * @filepath app/middleware/SubscriptionMiddleware.php
 * @version 2.0
 */

class SubscriptionMiddleware
{
    /**
     * Check if tenant can create a school
     */
    public static function canCreateSchool($tenantId)
    {
        $service = new SubscriptionService($tenantId);
        $result = $service->canCreate('schools');

        if (!$result['allowed']) {
            $_SESSION['subscription_error'] = "You have reached the maximum number of schools ({$result['max']}) for your subscription plan. Please upgrade to add more schools.";
            return false;
        }

        // Check if near limit for warning
        if ($service->isNearLimit('schools')) {
            $_SESSION['subscription_warning'] = "You are nearing your school limit ({$result['current']}/{$result['max']}). Consider upgrading your plan.";
        }

        return true;
    }

    /**
     * Check if tenant can create a campus
     */
    public static function canCreateCampus($tenantId)
    {
        $service = new SubscriptionService($tenantId);
        $result = $service->canCreate('campuses');

        if (!$result['allowed']) {
            $_SESSION['subscription_error'] = "You have reached the maximum number of campuses ({$result['max']}) for your subscription plan. Please upgrade to add more campuses.";
            return false;
        }

        if ($service->isNearLimit('campuses')) {
            $_SESSION['subscription_warning'] = "You are nearing your campus limit ({$result['current']}/{$result['max']}). Consider upgrading your plan.";
        }

        return true;
    }

    /**
     * Check if tenant can create staff
     */
    public static function canCreateStaff($tenantId)
    {
        $service = new SubscriptionService($tenantId);
        $result = $service->canCreate('staff');

        if (!$result['allowed']) {
            $_SESSION['subscription_error'] = "You have reached the maximum number of staff ({$result['max']}) for your subscription plan. Please upgrade to add more staff.";
            return false;
        }

        if ($service->isNearLimit('staff')) {
            $_SESSION['subscription_warning'] = "You are nearing your staff limit ({$result['current']}/{$result['max']}). Consider upgrading your plan.";
        }

        return true;
    }

    /**
     * Check if tenant can create a student
     */
    public static function canCreateStudent($tenantId)
    {
        $service = new SubscriptionService($tenantId);
        $result = $service->canCreate('students');

        if (!$result['allowed']) {
            $_SESSION['subscription_error'] = "You have reached the maximum number of students ({$result['max']}) for your subscription plan. Please upgrade to add more students.";
            return false;
        }

        if ($service->isNearLimit('students')) {
            $_SESSION['subscription_warning'] = "You are nearing your student limit ({$result['current']}/{$result['max']}). Consider upgrading your plan.";
        }

        return true;
    }

    /**
     * Get subscription status for a tenant
     */
    public static function getStatus($tenantId)
    {
        $service = new SubscriptionService($tenantId);
        return $service->getSubscriptionStatus();
    }

    /**
     * Get usage stats for a tenant
     */
    public static function getUsageStats($tenantId)
    {
        $service = new SubscriptionService($tenantId);
        return $service->getUsageStats();
    }

    /**
     * Display subscription warning/error messages
     */
    public static function displayMessages()
    {
        $output = '';
        if (isset($_SESSION['subscription_error'])) {
            $output .= '<div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i> ' . htmlspecialchars($_SESSION['subscription_error']) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
            unset($_SESSION['subscription_error']);
        }
        if (isset($_SESSION['subscription_warning'])) {
            $output .= '<div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i> ' . htmlspecialchars($_SESSION['subscription_warning']) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
            unset($_SESSION['subscription_warning']);
        }
        return $output;
    }
}
