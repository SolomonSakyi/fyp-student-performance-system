<?php

/**
 * platform.php
 * Platform API Routes Registration
 * 
 * @package EduTrack
 * @subpackage Routes
 * @version 1.0
 */

require_once dirname(__DIR__) . '/routes/Platform/AssessmentRoutes.php';
require_once dirname(__DIR__) . '/controllers/Platform/SchoolSettingsController.php';
require_once dirname(__DIR__) . '/controllers/Platform/SettingsController.php';
require_once dirname(__DIR__) . '/controllers/Platform/AuthController.php';

class PlatformRoutes
{
    public static function register($router)
    {
        // =============================================
        // HEALTH CHECK - MUST BE FIRST
        // =============================================
        $router->get('/api/health', function ($request) {
            echo json_encode([
                'success' => true,
                'message' => 'API is running',
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '2.0'
            ]);
        });

        // =============================================
        // REGISTER ASSESSMENT ROUTES
        // =============================================
        AssessmentRoutes::register($router);

        // =============================================
        // AUTH ROUTES
        // =============================================
        $router->post('/api/auth/login', function ($request) {
            $controller = new AuthController();
            $controller->login();
        });

        $router->post('/api/auth/logout', function ($request) {
            $controller = new AuthController();
            $controller->logout();
        });

        $router->get('/api/auth/status', function ($request) {
            $controller = new AuthController();
            $controller->status();
        });

        // =============================================
        // PAYMENT SETTINGS ROUTES
        // =============================================

        // Providers
        $router->get('/api/settings/payments/providers', function ($request) {
            $controller = new SettingsController();
            $controller->getPaymentProviders();
        });

        $router->get('/api/settings/payments/provider/{providerId}', function ($request) {
            $controller = new SettingsController();
            $providerId = $request['providerId'] ?? 0;
            $controller->getPaymentProvider((int)$providerId);
        });

        $router->post('/api/settings/payments/provider', function ($request) {
            $controller = new SettingsController();
            $controller->savePaymentProvider();
        });

        $router->put('/api/settings/payments/provider/{providerId}', function ($request) {
            $controller = new SettingsController();
            $providerId = $request['providerId'] ?? 0;
            $controller->updatePaymentProvider((int)$providerId);
        });

        $router->delete('/api/settings/payments/provider/{providerId}', function ($request) {
            $controller = new SettingsController();
            $providerId = $request['providerId'] ?? 0;
            $controller->deletePaymentProvider((int)$providerId);
        });

        $router->post('/api/settings/payments/provider/{providerId}/test', function ($request) {
            $controller = new SettingsController();
            $providerId = $request['providerId'] ?? 0;
            $controller->testPaymentProvider((int)$providerId);
        });

        // Hubtel
        $router->get('/api/settings/payments/hubtel', function ($request) {
            $controller = new SettingsController();
            $controller->getHubtelConfig();
        });

        $router->post('/api/settings/payments/hubtel', function ($request) {
            $controller = new SettingsController();
            $controller->saveHubtelConfig();
        });

        $router->post('/api/settings/payments/hubtel/test', function ($request) {
            $controller = new SettingsController();
            $controller->testHubtelConnection();
        });

        // Bank
        $router->get('/api/settings/payments/bank', function ($request) {
            $controller = new SettingsController();
            $controller->getBankConfig();
        });

        $router->post('/api/settings/payments/bank', function ($request) {
            $controller = new SettingsController();
            $controller->saveBankConfig();
        });

        $router->post('/api/settings/payments/bank/test', function ($request) {
            $controller = new SettingsController();
            $controller->testBankConnection();
        });

        // Audit Logs
        $router->get('/api/settings/audit-logs', function ($request) {
            $controller = new SettingsController();
            $controller->getSettingsAuditLogs();
        });

        // Webhooks
        $router->get('/api/settings/webhooks', function ($request) {
            $controller = new SettingsController();
            $controller->getWebhooks();
        });

        $router->post('/api/settings/webhook', function ($request) {
            $controller = new SettingsController();
            $controller->createWebhook();
        });

        $router->put('/api/settings/webhook/{webhookId}', function ($request) {
            $controller = new SettingsController();
            $webhookId = $request['webhookId'] ?? 0;
            $controller->updateWebhook((int)$webhookId);
        });

        $router->delete('/api/settings/webhook/{webhookId}', function ($request) {
            $controller = new SettingsController();
            $webhookId = $request['webhookId'] ?? 0;
            $controller->deleteWebhook((int)$webhookId);
        });

        $router->post('/api/settings/webhook/{webhookId}/test', function ($request) {
            $controller = new SettingsController();
            $webhookId = $request['webhookId'] ?? 0;
            $controller->testWebhook((int)$webhookId);
        });

        // =============================================
        // SCHOOL SETTINGS ROUTES
        // =============================================

        // Configuration Routes
        $router->get('/api/schools/{schoolId}/settings', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('GET', 'settings', ['schoolId' => $schoolId]);
        });

        $router->put('/api/schools/{schoolId}/settings', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('PUT', 'settings', ['schoolId' => $schoolId]);
        });

        $router->get('/api/schools/{schoolId}/settings/health', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('GET', 'settings/health', ['schoolId' => $schoolId]);
        });

        // Level Routes
        $router->get('/api/schools/{schoolId}/levels', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('GET', 'levels', ['schoolId' => $schoolId]);
        });

        $router->post('/api/schools/{schoolId}/levels', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('POST', 'levels', ['schoolId' => $schoolId]);
        });

        $router->put('/api/schools/{schoolId}/levels/{levelCode}/display-name', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $levelCode = $request['levelCode'] ?? '';
            $controller->dispatch('PUT', 'levels/' . $levelCode . '/display-name', ['schoolId' => $schoolId, 'levelCode' => $levelCode]);
        });

        $router->delete('/api/schools/{schoolId}/levels/{levelCode}', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $levelCode = $request['levelCode'] ?? '';
            $controller->dispatch('DELETE', 'levels/' . $levelCode, ['schoolId' => $schoolId, 'levelCode' => $levelCode]);
        });

        $router->get('/api/schools/available-levels', function ($request) {
            $controller = new SchoolSettingsController();
            $controller->dispatch('GET', 'available-levels');
        });

        // Subject Routes
        $router->get('/api/schools/{schoolId}/subjects', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('GET', 'subjects', ['schoolId' => $schoolId]);
        });

        $router->post('/api/schools/{schoolId}/subjects', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $controller->dispatch('POST', 'subjects', ['schoolId' => $schoolId]);
        });

        $router->get('/api/schools/{schoolId}/subjects/{subjectId}', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $subjectId = $request['subjectId'] ?? 0;
            $controller->dispatch('GET', 'subjects/' . $subjectId, ['schoolId' => $schoolId, 'subjectId' => $subjectId]);
        });

        $router->put('/api/schools/{schoolId}/subjects/{subjectId}', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $subjectId = $request['subjectId'] ?? 0;
            $controller->dispatch('PUT', 'subjects/' . $subjectId, ['schoolId' => $schoolId, 'subjectId' => $subjectId]);
        });

        $router->delete('/api/schools/{schoolId}/subjects/{subjectId}', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $subjectId = $request['subjectId'] ?? 0;
            $controller->dispatch('DELETE', 'subjects/' . $subjectId, ['schoolId' => $schoolId, 'subjectId' => $subjectId]);
        });

        $router->get('/api/schools/{schoolId}/subjects/level/{levelId}', function ($request) {
            $controller = new SchoolSettingsController();
            $schoolId = $request['schoolId'] ?? 0;
            $levelId = $request['levelId'] ?? 0;
            $controller->dispatch('GET', 'subjects/level/' . $levelId, ['schoolId' => $schoolId, 'levelId' => $levelId]);
        });

        // ... rest of existing routes ...
    }
}
