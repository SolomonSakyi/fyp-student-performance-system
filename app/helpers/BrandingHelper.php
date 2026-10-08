<?php

/**
 * Branding Helper
 * Provides easy access to branding functions
 *
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.0
 * @filepath app/helpers/BrandingHelper.php
 */

if (!function_exists('branding')) {
    /**
     * Get branding service instance
     */
    function branding(): BrandingService
    {
        static $instance = null;
        if ($instance === null) {
            require_once __DIR__ . '/../services/Branding/BrandingService.php';
            $instance = new BrandingService();
        }
        return $instance;
    }
}

if (!function_exists('loginBranding')) {
    /**
     * Get login branding for current context
     */
    function loginBranding(): array
    {
        return branding()->getLoginBranding();
    }
}

if (!function_exists('loginCSS')) {
    /**
     * Get login page CSS
     */
    function loginCSS(): string
    {
        return branding()->getLoginCSS(loginBranding());
    }
}

if (!function_exists('loginHead')) {
    /**
     * Get login page head content
     */
    function loginHead(): string
    {
        return branding()->getLoginHead(loginBranding());
    }
}

if (!function_exists('welcomeMessage')) {
    /**
     * Get welcome message
     */
    function welcomeMessage(): string
    {
        return branding()->getWelcomeMessage(loginBranding());
    }
}

if (!function_exists('schoolMotto')) {
    /**
     * Get school motto
     */
    function schoolMotto(): string
    {
        return branding()->getMotto(loginBranding());
    }
}

if (!function_exists('supportInfo')) {
    /**
     * Get support contact information
     */
    function supportInfo(): array
    {
        return branding()->getSupportInfo(loginBranding());
    }
}
