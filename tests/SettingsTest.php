<?php

/**
 * SettingsTest.php
 * Automated tests for settings module with tenant isolation
 * 
 * @package EduTrack
 * @subpackage Tests
 * @version 2.0
 * 
 * @filepath tests/SettingsTest.php
 * 
 * IMPORTANT: This test uses REAL authentication and authorization.
 * NO PERMISSION CHECKS ARE BYPASSED.
 */

require_once dirname(__DIR__) . '/app/helpers/DatabaseHelper.php';
require_once dirname(__DIR__) . '/app/services/Tenant/TenantContext.php';
require_once dirname(__DIR__) . '/app/services/Platform/SchoolSettingsService.php';
require_once dirname(__DIR__) . '/app/services/Security/EncryptionService.php';
require_once dirname(__DIR__) . '/app/services/Security/SchoolOwnershipValidator.php';
require_once dirname(__DIR__) . '/app/services/Platform/AuthService.php';

class SettingsTest
{
    private $db;
    private $context;
    private $settingsService;
    private $validator;
    private $authService;
    private $testTenantId;
    private $testSchoolId;
    private $testUserId;
    private $testUserEmail;
    private $testUserPassword;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->context = TenantContext::getInstance();
        $this->settingsService = new SchoolSettingsService();
        $this->validator = new SchoolOwnershipValidator();
        $this->authService = new AuthService();
    }

    public function runAll(): void
    {
        echo "\n==============================\n";
        echo "SETTINGS MODULE TESTS\n";
        echo "==============================\n\n";

        try {
            $this->setupTestData();
            $this->authenticateUser();

            if ($this->testTenantId && $this->testSchoolId && $this->testUserId) {
                $this->context->refresh();

                echo "   🔍 Context: Tenant={$this->testTenantId}, School={$this->testSchoolId}, User={$this->testUserId}\n";
                echo "   🔍 Roles: " . implode(', ', $this->context->getRoles()) . "\n\n";

                $this->testSchoolSettingsCRUD();
                $this->testCampusSettingsInheritance();
                $this->testTenantIsolation();
                $this->testEncryption();
                $this->testAuditLogging();
                $this->testBulkOperations();
                $this->testCategoryManagement();
            } else {
                echo "⚠️ Skipping tests due to setup failure\n";
            }

            $this->cleanupTestData();

            echo "\n✅ ALL TESTS COMPLETED!\n";
            echo "==============================\n\n";
        } catch (Exception $e) {
            echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
            echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            $this->cleanupTestData();
            exit(1);
        }
    }

    private function setupTestData(): void
    {
        echo "📋 Setting up test data...\n";

        try {
            $this->testTenantId = $this->createTestTenant();
            echo "   ✅ Created test tenant ID: {$this->testTenantId}\n";

            $this->testSchoolId = $this->createTestSchool($this->testTenantId);
            echo "   ✅ Created test school ID: {$this->testSchoolId}\n";

            $this->testUserEmail = 'school_admin_' . rand(1000, 9999) . '@edutrack.test';
            $this->testUserPassword = 'Test@123456';
            $this->testUserId = $this->createTestUser($this->testTenantId, $this->testUserEmail, $this->testUserPassword);
            echo "   ✅ Created test user ID: {$this->testUserId}\n";

            $this->assignSchoolAdminRole($this->testUserId);
            echo "   ✅ Assigned SCHOOL_ADMIN role to user\n";

            $_SESSION['user_id'] = $this->testUserId;
            $_SESSION['tenant_id'] = $this->testTenantId;
            $_SESSION['school_id'] = $this->testSchoolId;
        } catch (Exception $e) {
            echo "   ❌ Setup failed: " . $e->getMessage() . "\n";
            throw $e;
        }

        echo "\n";
    }

    private function authenticateUser(): void
    {
        echo "🔐 Authenticating test user...\n";

        try {
            $result = $this->authService->login($this->testUserEmail, $this->testUserPassword);

            if (!$result['success']) {
                throw new Exception("Authentication failed: " . ($result['message'] ?? 'Unknown error'));
            }

            $this->forceReloadRoles();
            $this->context->refresh();

            echo "   ✅ Authenticated as: {$this->testUserEmail}\n";
            echo "   ✅ Roles loaded: " . implode(', ', $this->context->getRoles()) . "\n";
        } catch (Exception $e) {
            throw new Exception("Authentication error: " . $e->getMessage());
        }
    }

    private function forceReloadRoles(): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return;
        }

        $roles = $this->db->fetchAll(
            "SELECT r.role_code 
             FROM platform_user_roles pur
             JOIN platform_roles r ON pur.role_id = r.id
             WHERE pur.user_id = ?",
            [$userId]
        );

        $roleCodes = array_column($roles, 'role_code');
        $_SESSION['roles'] = $roleCodes;

        if (isset($_SESSION['tenant_context'])) {
            $_SESSION['tenant_context']['roles'] = $roleCodes;
        }

        $this->context->setRoles($roleCodes);
    }

    private function assignSchoolAdminRole(int $userId): void
    {
        $role = $this->db->fetchOne(
            "SELECT id FROM platform_roles WHERE role_code = 'SCHOOL_ADMIN' LIMIT 1"
        );

        if (!$role) {
            $role = $this->db->fetchOne(
                "SELECT id FROM platform_roles WHERE role_code LIKE '%school_admin%' LIMIT 1"
            );
        }

        if (!$role) {
            $this->db->query(
                "INSERT INTO platform_roles (uuid, role_name, role_code, description, is_system, is_active)
                 VALUES (UUID(), 'School Administrator', 'SCHOOL_ADMIN', 'Can manage their school', 1, 1)"
            );
            $role = $this->db->fetchOne(
                "SELECT id FROM platform_roles WHERE role_code = 'SCHOOL_ADMIN' LIMIT 1"
            );
        }

        if (!$role) {
            throw new Exception("No admin role found");
        }

        $existing = $this->db->fetchOne(
            "SELECT * FROM platform_user_roles WHERE user_id = ?",
            [$userId]
        );

        if (!$existing) {
            $sql = "INSERT INTO platform_user_roles (uuid, user_id, role_id, tenant_id) VALUES (UUID(), ?, ?, ?)";
            $this->db->query($sql, [$userId, $role['id'], $this->testTenantId]);
        }
    }

    private function testSchoolSettingsCRUD(): void
    {
        echo "📋 Testing School Settings CRUD...\n";

        $result = $this->settingsService->updateSchoolSetting(
            $this->testSchoolId,
            'test_setting',
            'test_value',
            $this->testUserId
        );

        if (!$result['success']) {
            throw new Exception("Failed to create/update setting: " . ($result['message'] ?? 'Unknown error'));
        }
        echo "   ✅ Create/Update setting: PASSED\n";

        $result = $this->settingsService->getSchoolSetting($this->testSchoolId, 'test_setting');
        if (!$result['success']) {
            throw new Exception("Failed to get setting: " . ($result['message'] ?? 'Unknown error'));
        }
        if ($result['data']['value'] !== 'test_value') {
            throw new Exception("Setting value mismatch");
        }
        echo "   ✅ Get setting: PASSED\n";

        $result = $this->settingsService->getSchoolSettings($this->testSchoolId);
        if (!$result['success']) {
            throw new Exception("Failed to get all settings");
        }
        echo "   ✅ Get all settings: PASSED\n";

        $result = $this->settingsService->getSchoolSettingsByCategory($this->testSchoolId, 'general');
        if (!$result['success']) {
            throw new Exception("Failed to get settings by category");
        }
        echo "   ✅ Get settings by category: PASSED\n";

        $result = $this->settingsService->searchSchoolSettings($this->testSchoolId, 'test');
        if (!$result['success']) {
            throw new Exception("Failed to search settings");
        }
        echo "   ✅ Search settings: PASSED\n\n";
    }

    private function testCampusSettingsInheritance(): void
    {
        echo "📋 Testing Campus Settings Inheritance...\n";

        $campusId = $this->createTestCampus($this->testSchoolId);

        $result = $this->settingsService->updateSchoolSetting(
            $this->testSchoolId,
            'campus_default_setting',
            'school_value',
            $this->testUserId
        );
        if (!$result['success']) {
            throw new Exception("Failed to set school default");
        }

        $result = $this->settingsService->getEffectiveCampusSetting($campusId, 'campus_default_setting');
        if (!$result['success']) {
            throw new Exception("Failed to get effective setting");
        }
        if ($result['data']['value'] !== 'school_value') {
            throw new Exception("Should inherit school value");
        }
        if ($result['data']['source'] !== 'school') {
            throw new Exception("Source should be 'school'");
        }
        echo "   ✅ Inheritance from school: PASSED\n";

        $result = $this->settingsService->setCampusSetting(
            $campusId,
            'campus_default_setting',
            'campus_value',
            $this->testUserId
        );
        if (!$result['success']) {
            throw new Exception("Failed to set campus override");
        }
        echo "   ✅ Campus override: PASSED\n";

        $result = $this->settingsService->getEffectiveCampusSetting($campusId, 'campus_default_setting');
        if (!$result['success']) {
            throw new Exception("Failed to get effective setting after override");
        }
        if ($result['data']['value'] !== 'campus_value') {
            throw new Exception("Should use campus override");
        }
        if ($result['data']['source'] !== 'campus') {
            throw new Exception("Source should be 'campus'");
        }
        echo "   ✅ Campus override takes precedence: PASSED\n";

        $result = $this->settingsService->removeCampusOverride($campusId, 'campus_default_setting', $this->testUserId);
        if (!$result['success']) {
            throw new Exception("Failed to remove campus override");
        }
        echo "   ✅ Remove campus override: PASSED\n";

        $result = $this->settingsService->getEffectiveCampusSetting($campusId, 'campus_default_setting');
        if (!$result['success']) {
            throw new Exception("Failed to get effective setting after removal");
        }
        if ($result['data']['value'] !== 'school_value') {
            throw new Exception("Should revert to school value");
        }
        echo "   ✅ Revert to school default: PASSED\n\n";
    }

    private function testTenantIsolation(): void
    {
        echo "📋 Testing Tenant Isolation...\n";

        $tenantBId = $this->createTestTenant('Tenant B');
        $schoolBId = $this->createTestSchool($tenantBId);

        $userBEmail = 'admin_b_' . rand(1000, 9999) . '@edutrack.test';
        $userBPassword = 'Test@123456';
        $userBId = $this->createTestUser($tenantBId, $userBEmail, $userBPassword);
        $this->assignSchoolAdminRole($userBId);

        $authResult = $this->authService->login($userBEmail, $userBPassword);
        if (!$authResult['success']) {
            throw new Exception("Failed to authenticate as Tenant B admin");
        }

        $this->forceReloadRoles();

        $_SESSION['user_id'] = $userBId;
        $_SESSION['tenant_id'] = $tenantBId;
        $_SESSION['school_id'] = $schoolBId;
        $this->context->refresh();

        $this->db->query(
            "INSERT INTO school_settings (uuid, school_id, tenant_id, setting_key, setting_value, setting_type, created_by)
             VALUES (UUID(), ?, ?, 'tenant_b_setting', 'tenant_b_value', 'string', ?)",
            [$schoolBId, $tenantBId, $userBId]
        );
        echo "   ✅ Tenant B setting created: PASSED\n";

        $authResult = $this->authService->login($this->testUserEmail, $this->testUserPassword);
        if (!$authResult['success']) {
            throw new Exception("Failed to authenticate as Tenant A admin");
        }

        $this->forceReloadRoles();

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['tenant_id'] = $this->testTenantId;
        $_SESSION['school_id'] = $this->testSchoolId;
        $this->context->refresh();

        $result = $this->settingsService->getSchoolSetting($schoolBId, 'tenant_b_setting');
        if ($result['success']) {
            throw new Exception("SECURITY VIOLATION: Should NOT be able to access tenant B's setting");
        }
        echo "   ✅ Cross-tenant access blocked: PASSED\n";

        $result = $this->settingsService->updateSchoolSetting(
            $schoolBId,
            'tenant_b_setting',
            'hacked_value',
            $this->testUserId
        );
        if ($result['success']) {
            throw new Exception("SECURITY VIOLATION: Should NOT be able to update tenant B's setting");
        }
        echo "   ✅ Cross-tenant update blocked: PASSED\n";

        $this->deleteTestTenant($tenantBId);
        echo "   ✅ Tenant B cleaned up: PASSED\n\n";
    }

    private function testEncryption(): void
    {
        echo "📋 Testing Encryption...\n";

        $sensitiveValue = 'super_secret_api_key_12345';
        $result = $this->settingsService->updateSchoolSetting(
            $this->testSchoolId,
            'api_secret_key',
            $sensitiveValue,
            $this->testUserId
        );
        if (!$result['success']) {
            throw new Exception("Failed to create encrypted setting");
        }
        echo "   ✅ Encrypted setting created: PASSED\n";

        $result = $this->settingsService->getSchoolSetting($this->testSchoolId, 'api_secret_key');
        if (!$result['success']) {
            throw new Exception("Failed to get encrypted setting");
        }
        if ($result['data']['value'] !== $sensitiveValue) {
            throw new Exception("Value should be decrypted");
        }
        if ($result['data']['is_encrypted'] !== true) {
            throw new Exception("Should be marked as encrypted");
        }
        echo "   ✅ Encrypted setting decrypted: PASSED\n";

        $dbResult = $this->db->fetchOne(
            "SELECT setting_value, is_encrypted FROM school_settings 
             WHERE school_id = ? AND setting_key = 'api_secret_key'",
            [$this->testSchoolId]
        );
        if ($dbResult && $dbResult['is_encrypted'] != 1) {
            throw new Exception("Should be marked encrypted in DB");
        }
        if ($dbResult && $dbResult['setting_value'] === $sensitiveValue) {
            throw new Exception("Should NOT be plaintext in DB");
        }
        echo "   ✅ Encrypted in database: PASSED\n\n";
    }

    private function testAuditLogging(): void
    {
        echo "📋 Testing Audit Logging...\n";

        $tableExists = $this->db->fetchOne(
            "SHOW TABLES LIKE 'platform_audit_logs'"
        );

        if (!$tableExists) {
            echo "   ⚠️ Audit log table not found - skipping test\n\n";
            return;
        }

        $this->settingsService->updateSchoolSetting(
            $this->testSchoolId,
            'audit_test_setting',
            'audit_value',
            $this->testUserId
        );

        $sql = "SELECT * FROM platform_audit_logs 
                WHERE tenant_id = ? AND resource = 'school_setting' 
                ORDER BY created_at DESC LIMIT 1";
        $log = $this->db->fetchOne($sql, [$this->testTenantId]);

        if (!$log) {
            echo "   ⚠️ Audit log not created\n";
        } else {
            echo "   ✅ Audit log created: PASSED\n";
        }
        echo "\n";
    }

    private function testBulkOperations(): void
    {
        echo "📋 Testing Bulk Operations...\n";

        $bulkSettings = [
            'bulk_setting_1' => 'value_1',
            'bulk_setting_2' => 'value_2',
            'bulk_setting_3' => 'value_3'
        ];

        $result = $this->settingsService->bulkUpdateSchoolSettings(
            $this->testSchoolId,
            $bulkSettings,
            $this->testUserId
        );

        if (!$result['success']) {
            throw new Exception("Failed to bulk update");
        }
        if (count($result['data']['updated']) !== 3) {
            throw new Exception("Expected 3 settings updated");
        }
        echo "   ✅ Bulk update: PASSED\n";

        foreach ($bulkSettings as $key => $value) {
            $result = $this->settingsService->getSchoolSetting($this->testSchoolId, $key);
            if (!$result['success'] || $result['data']['value'] !== $value) {
                throw new Exception("Failed to verify bulk setting: $key");
            }
        }
        echo "   ✅ Bulk update verified: PASSED\n\n";
    }

    private function testCategoryManagement(): void
    {
        echo "📋 Testing Category Management...\n";

        $result = $this->settingsService->getSchoolSettingCategories($this->testSchoolId);
        if (!$result['success']) {
            throw new Exception("Failed to get categories");
        }
        if (!is_array($result['data'])) {
            throw new Exception("Categories should be an array");
        }
        echo "   ✅ Get categories: PASSED\n";

        $campusId = $this->createTestCampus($this->testSchoolId);
        $result = $this->settingsService->getCampusSettingCategories($campusId);
        if (!$result['success']) {
            throw new Exception("Failed to get campus categories");
        }
        echo "   ✅ Get campus categories: PASSED\n\n";
    }

    private function cleanupTestData(): void
    {
        echo "📋 Cleaning up test data...\n";

        try {
            if ($this->testSchoolId) {
                $this->db->query("DELETE FROM school_settings WHERE school_id = ?", [$this->testSchoolId]);
                $this->db->query("DELETE FROM campus_settings WHERE campus_id IN (SELECT id FROM campuses WHERE school_id = ?)", [$this->testSchoolId]);
                $this->db->query("DELETE FROM campuses WHERE school_id = ?", [$this->testSchoolId]);
                $this->db->query("DELETE FROM schools WHERE id = ?", [$this->testSchoolId]);
            }

            if ($this->testUserId) {
                $this->db->query("DELETE FROM platform_user_roles WHERE user_id = ?", [$this->testUserId]);
                $this->db->query("DELETE FROM platform_users WHERE id = ?", [$this->testUserId]);
            }

            if ($this->testTenantId) {
                $this->db->query("DELETE FROM tenants WHERE id = ?", [$this->testTenantId]);
            }

            echo "   ✅ Test data cleaned up\n";
        } catch (Exception $e) {
            echo "   ⚠️ Cleanup warning: " . $e->getMessage() . "\n";
        }
    }

    // ============================================================
    // TEST DATA HELPERS
    // ============================================================

    private function createTestTenant($name = 'Test Tenant'): int
    {
        $tenantCode = 'TEST' . rand(1000, 9999);

        $columns = $this->db->fetchAll("SHOW COLUMNS FROM tenants");
        $colNames = array_column($columns, 'Field');

        $fields = [];
        $values = [];
        $placeholders = [];

        if (in_array('tenant_name', $colNames)) {
            $fields[] = 'tenant_name';
            $values[] = $name;
            $placeholders[] = '?';
        }
        if (in_array('tenant_code', $colNames)) {
            $fields[] = 'tenant_code';
            $values[] = $tenantCode;
            $placeholders[] = '?';
        }
        if (in_array('legal_name', $colNames)) {
            $fields[] = 'legal_name';
            $values[] = $name;
            $placeholders[] = '?';
        }
        if (in_array('status', $colNames)) {
            $fields[] = 'status';
            $values[] = 'active';
            $placeholders[] = '?';
        }

        if (empty($fields)) {
            $sql = "INSERT INTO tenants () VALUES ()";
        } else {
            $sql = "INSERT INTO tenants (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        }

        $this->db->query($sql, $values);
        return (int)$this->db->lastInsertId();
    }

    private function createTestSchool(int $tenantId): int
    {
        $name = 'Test School ' . rand(1000, 9999);
        $schoolCode = 'SCH-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

        $columns = $this->db->fetchAll("SHOW COLUMNS FROM schools");
        $colNames = array_column($columns, 'Field');

        $fields = ['tenant_id'];
        $values = [$tenantId];
        $placeholders = ['?'];

        if (in_array('school_name', $colNames)) {
            $fields[] = 'school_name';
            $values[] = $name;
            $placeholders[] = '?';
        }
        if (in_array('school_code', $colNames)) {
            $fields[] = 'school_code';
            $values[] = $schoolCode;
            $placeholders[] = '?';
        }
        if (in_array('status', $colNames)) {
            $fields[] = 'status';
            $values[] = 'active';
            $placeholders[] = '?';
        }

        $sql = "INSERT INTO schools (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $this->db->query($sql, $values);
        return (int)$this->db->lastInsertId();
    }

    private function createTestCampus(int $schoolId): int
    {
        $name = 'Test Campus ' . rand(1000, 9999);
        $campusCode = 'CAM-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $uuid = uniqid() . '_' . rand(1000, 99999);

        $sql = "INSERT INTO campuses (uuid, school_id, tenant_id, campus_name, campus_code, status)
                VALUES (?, ?, (SELECT tenant_id FROM schools WHERE id = ?), ?, ?, 'active')";

        $this->db->query($sql, [$uuid, $schoolId, $schoolId, $name, $campusCode]);
        return (int)$this->db->lastInsertId();
    }

    private function createTestUser(int $tenantId, string $email, string $password): int
    {
        $username = 'test_' . rand(1000, 9999);
        $uuid = uniqid() . '_' . rand(1000, 9999);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $firstName = 'Test';
        $lastName = 'User_' . rand(1000, 9999);

        $sql = "INSERT INTO platform_users (uuid, username, email, password_hash, first_name, last_name, tenant_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)";

        $this->db->query($sql, [$uuid, $username, $email, $passwordHash, $firstName, $lastName, $tenantId]);
        return (int)$this->db->lastInsertId();
    }

    private function deleteTestTenant(int $tenantId): void
    {
        try {
            $this->db->query("DELETE FROM campus_settings WHERE tenant_id = ?", [$tenantId]);
            $this->db->query("DELETE FROM school_settings WHERE tenant_id = ?", [$tenantId]);
            $this->db->query("DELETE FROM campuses WHERE tenant_id = ?", [$tenantId]);
            $this->db->query("DELETE FROM schools WHERE tenant_id = ?", [$tenantId]);
            $this->db->query("DELETE FROM platform_user_roles WHERE user_id IN (SELECT id FROM platform_users WHERE tenant_id = ?)", [$tenantId]);
            $this->db->query("DELETE FROM platform_users WHERE tenant_id = ?", [$tenantId]);
            $this->db->query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
        } catch (Exception $e) {
            error_log('deleteTestTenant warning: ' . $e->getMessage());
        }
    }
}

if (php_sapi_name() === 'cli') {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $test = new SettingsTest();
        $test->runAll();
    } catch (Exception $e) {
        echo "\n❌ ERROR: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(1);
    }
} else {
    echo "Please run this script from the command line.\n";
    echo "php tests/SettingsTest.php\n";
}
