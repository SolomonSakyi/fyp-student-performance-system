<?php

/**
 * SettingsRoutes.php
 * API Routes for Settings Module
 * 
 * @package EduTrack
 * @subpackage Routes\Platform
 * @version 1.0
 * 
 * @filepath app/routes/Platform/SettingsRoutes.php
 */

require_once dirname(__DIR__, 3) . '/app/controllers/Platform/SettingsController.php';
require_once dirname(__DIR__, 3) . '/app/middleware/TenantContextMiddleware.php';

class SettingsRoutes
{
    private $controller;
    private $middleware;

    public function __construct()
    {
        $this->controller = new SettingsController();
        $this->middleware = new TenantContextMiddleware();
    }

    /**
     * Register all settings routes
     */
    public function register(): void
    {
        $router = Router::getInstance();

        // ============================================================
        // PAYMENT PROVIDER SETTINGS
        // ============================================================

        $router->get('/api/settings/payments/providers', function () {
            $this->middleware->handle('/api/settings/payments', 'GET');
            $this->controller->getPaymentProviders();
        });

        $router->get('/api/settings/payments/provider/{providerId}', function ($providerId) {
            $this->middleware->handle('/api/settings/payments', 'GET');
            $this->controller->getPaymentProvider((int)$providerId);
        });

        $router->post('/api/settings/payments/provider', function () {
            $this->middleware->handle('/api/settings/payments', 'POST');
            $this->controller->savePaymentProvider();
        });

        $router->put('/api/settings/payments/provider/{providerId}', function ($providerId) {
            $this->middleware->handle('/api/settings/payments', 'PUT');
            $this->controller->updatePaymentProvider((int)$providerId);
        });

        $router->delete('/api/settings/payments/provider/{providerId}', function ($providerId) {
            $this->middleware->handle('/api/settings/payments', 'DELETE');
            $this->controller->deletePaymentProvider((int)$providerId);
        });

        $router->post('/api/settings/payments/provider/{providerId}/test', function ($providerId) {
            $this->middleware->handle('/api/settings/payments', 'POST');
            $this->controller->testPaymentProvider((int)$providerId);
        });

        // ============================================================
        // HUBTEL SETTINGS
        // ============================================================

        $router->get('/api/settings/payments/hubtel', function () {
            $this->middleware->handle('/api/settings/payments', 'GET');
            $this->controller->getHubtelConfig();
        });

        $router->post('/api/settings/payments/hubtel', function () {
            $this->middleware->handle('/api/settings/payments', 'POST');
            $this->controller->saveHubtelConfig();
        });

        $router->post('/api/settings/payments/hubtel/test', function () {
            $this->middleware->handle('/api/settings/payments', 'POST');
            $this->controller->testHubtelConnection();
        });

        // ============================================================
        // BANK SETTINGS
        // ============================================================

        $router->get('/api/settings/payments/bank', function () {
            $this->middleware->handle('/api/settings/payments', 'GET');
            $this->controller->getBankConfig();
        });

        $router->post('/api/settings/payments/bank', function () {
            $this->middleware->handle('/api/settings/payments', 'POST');
            $this->controller->saveBankConfig();
        });

        $router->post('/api/settings/payments/bank/test', function () {
            $this->middleware->handle('/api/settings/payments', 'POST');
            $this->controller->testBankConnection();
        });

        // ============================================================
        // GENERAL SETTINGS
        // ============================================================

        $router->get('/api/settings/general', function () {
            $this->middleware->handle('/api/settings/general', 'GET');
            $this->controller->getGeneralSettings();
        });

        $router->post('/api/settings/general', function () {
            $this->middleware->handle('/api/settings/general', 'POST');
            $this->controller->updateGeneralSettings();
        });

        // ============================================================
        // SECURITY SETTINGS
        // ============================================================

        $router->get('/api/settings/security', function () {
            $this->middleware->handle('/api/settings/security', 'GET');
            $this->controller->getSecuritySettings();
        });

        $router->post('/api/settings/security', function () {
            $this->middleware->handle('/api/settings/security', 'POST');
            $this->controller->updateSecuritySettings();
        });

        // ============================================================
        // EMAIL SETTINGS
        // ============================================================

        $router->get('/api/settings/email', function () {
            $this->middleware->handle('/api/settings/email', 'GET');
            $this->controller->getEmailSettings();
        });

        $router->post('/api/settings/email', function () {
            $this->middleware->handle('/api/settings/email', 'POST');
            $this->controller->updateEmailSettings();
        });

        $router->post('/api/settings/email/test', function () {
            $this->middleware->handle('/api/settings/email', 'POST');
            $this->controller->testEmailConnection();
        });

        // ============================================================
        // CURRENCY SETTINGS
        // ============================================================

        $router->get('/api/settings/currency', function () {
            $this->middleware->handle('/api/settings/currency', 'GET');
            $this->controller->getCurrencySettings();
        });

        $router->post('/api/settings/currency', function () {
            $this->middleware->handle('/api/settings/currency', 'POST');
            $this->controller->updateCurrencySettings();
        });

        $router->post('/api/settings/currency/refresh', function () {
            $this->middleware->handle('/api/settings/currency', 'POST');
            $this->controller->refreshExchangeRates();
        });

        // ============================================================
        // INTEGRATION SETTINGS
        // ============================================================

        $router->get('/api/settings/integrations', function () {
            $this->middleware->handle('/api/settings/integrations', 'GET');
            $this->controller->getIntegrationSettings();
        });

        $router->post('/api/settings/integrations', function () {
            $this->middleware->handle('/api/settings/integrations', 'POST');
            $this->controller->updateIntegrationSettings();
        });

        $router->post('/api/settings/integrations/test', function () {
            $this->middleware->handle('/api/settings/integrations', 'POST');
            $this->controller->testIntegrations();
        });

        // ============================================================
        // ADVANCED SETTINGS
        // ============================================================

        $router->get('/api/settings/advanced', function () {
            $this->middleware->handle('/api/settings/advanced', 'GET');
            $this->controller->getAdvancedSettings();
        });

        $router->post('/api/settings/advanced', function () {
            $this->middleware->handle('/api/settings/advanced', 'POST');
            $this->controller->updateAdvancedSettings();
        });

        $router->post('/api/settings/cache/clear', function () {
            $this->middleware->handle('/api/settings/advanced', 'POST');
            $this->controller->clearCache();
        });

        $router->post('/api/settings/database/optimize', function () {
            $this->middleware->handle('/api/settings/advanced', 'POST');
            $this->controller->optimizeDatabase();
        });

        // ============================================================
        // WEBHOOK SETTINGS
        // ============================================================

        $router->get('/api/settings/webhooks', function () {
            $this->middleware->handle('/api/settings/webhooks', 'GET');
            $this->controller->getWebhooks();
        });

        $router->post('/api/settings/webhook', function () {
            $this->middleware->handle('/api/settings/webhooks', 'POST');
            $this->controller->createWebhook();
        });

        $router->put('/api/settings/webhook/{webhookId}', function ($webhookId) {
            $this->middleware->handle('/api/settings/webhooks', 'PUT');
            $this->controller->updateWebhook((int)$webhookId);
        });

        $router->delete('/api/settings/webhook/{webhookId}', function ($webhookId) {
            $this->middleware->handle('/api/settings/webhooks', 'DELETE');
            $this->controller->deleteWebhook((int)$webhookId);
        });

        $router->post('/api/settings/webhook/{webhookId}/test', function ($webhookId) {
            $this->middleware->handle('/api/settings/webhooks', 'POST');
            $this->controller->testWebhook((int)$webhookId);
        });

        // ============================================================
        // SETTINGS AUDIT LOGS
        // ============================================================

        $router->get('/api/settings/audit-logs', function () {
            $this->middleware->handle('/api/settings/audit-logs', 'GET');
            $this->controller->getSettingsAuditLogs();
        });

        $router->get('/api/settings/audit-logs/export', function () {
            $this->middleware->handle('/api/settings/audit-logs', 'GET');
            $this->controller->exportSettingsAuditLogs();
        });

        $router->delete('/api/settings/audit-logs', function () {
            $this->middleware->handle('/api/settings/audit-logs', 'DELETE');
            $this->controller->clearSettingsAuditLogs();
        });

        // ============================================================
        // TENANT SETTINGS OVERVIEW
        // ============================================================

        $router->get('/api/settings/tenant/overview', function () {
            $this->middleware->handle('/api/settings/tenant', 'GET');
            $this->controller->getTenantSettingsOverview();
        });

        // ============================================================
        // APPEARANCE SETTINGS
        // ============================================================

        $router->get('/api/settings/appearance', function () {
            $this->middleware->handle('/api/settings/appearance', 'GET');
            $this->controller->getAppearanceSettings();
        });

        $router->post('/api/settings/appearance', function () {
            $this->middleware->handle('/api/settings/appearance', 'POST');
            $this->controller->updateAppearanceSettings();
        });

        $router->post('/api/settings/appearance/reset', function () {
            $this->middleware->handle('/api/settings/appearance', 'POST');
            $this->controller->resetAppearanceSettings();
        });

        // ============================================================
        // HEALTH CHECK
        // ============================================================

        $router->get('/api/health', function () {
            echo json_encode([
                'success' => true,
                'message' => 'API is running',
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '2.0'
            ]);
        });
    }
}
