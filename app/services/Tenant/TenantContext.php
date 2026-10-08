<?php

/**
 * Tenant Context Service
 * Manages the current tenant, school, and campus context for the request
 *
 * @package EduTrack
 * @subpackage Services\Tenant
 * @version 2.2
 * @filepath app/services/Tenant/TenantContext.php
 *
 * v2.2 change (2026-10-08) [CALLER-ALIGNMENT]:
 *   One getter is added: isPlatformAdmin(). It returns the private
 *   $isSuperAdmin flag, which is the same flag returned by the
 *   existing isSuperAdmin() getter. The addition is made to satisfy
 *   the callers across the codebase that delegate to
 *   $this->context->isPlatformAdmin(). The class v2.2 declared
 *   isSuperAdmin() but not isPlatformAdmin(); the callers declared
 *   their own private wrappers named isPlatformAdmin() whose bodies
 *   are `return $this->context->isPlatformAdmin();`. Adding the
 *   method here makes every caller's delegation line resolve
 *   without touching the callers.
 *
 *   Callers whose private wrapper delegates to
 *   $this->context->isPlatformAdmin(), per the grep on the record:
 *     app/controllers/Health/HealthController.php
 *     app/controllers/Identity/IdentityController.php
 *     app/controllers/Platform/AuditLogController.php
 *     app/controllers/Platform/SubscriptionController.php
 *     app/controllers/Platform/TenantController.php
 *     app/controllers/Staff/StaffController.php
 *     app/controllers/Student/GuardianController.php
 *     app/controllers/Student/StudentController.php
 *     app/services/Health/HealthService.php
 *     app/services/Identity/PersonAddressService.php
 *     app/services/Identity/PersonContactService.php
 *     app/services/Identity/PersonDocumentService.php
 *     app/services/Identity/PersonLifecycleService.php
 *     app/services/Identity/PersonRelationshipService.php
 *
 *   app/services/Platform/SchoolService.php is a hybrid caller: its
 *   private wrapper guards with method_exists($this->context,
 *   'isPlatformAdmin') and property_exists($this->context,
 *   'isPlatformAdmin'). Under this addition, the method_exists
 *   check resolves and the wrapper delegates cleanly; the
 *   property_exists fallback and the property assignment on
 *   SchoolService.php line 97 become dead code. Cleaning that file
 *   is a separate decision and is not part of this addition.
 *
 *   No caller file is changed. No setter is changed. No existing
 *   getter is changed. The class version stays at v2.2.
 *
 * v2.1 change (2026-09-30) [SHAPE-C]:
 *   restoreFromSession() now falls back to the flat session keys
 *   written by login.php v2.2 when $_SESSION['tenant_context'] is
 *   absent. The nested context is still the preferred source; it is
 *   written by TenantContextMiddleware::resolveFromDomain() ->
 *   saveToSession() for school hostnames. The flat keys are the
 *   source of truth for requests on the platform hostname, where
 *   TenantContextMiddleware::handle() returns true early without
 *   populating the nested context.
 *
 * v2.2 change (2026-09-30) [1B]:
 *   The permission-string model is dropped. There is no permissions
 *   table and no role_permissions table on the live database.
 *
 *   loadUserPermissions()'s roles query is rewritten to match the
 *   live roles table (id, role_name, description). The columns
 *   role_code, tenant_id, and deleted_at do not exist on roles and
 *   were removed from the query. The permissions query is removed
 *   entirely because there are no permissions tables.
 *
 *   hasRole() now normalises underscores to spaces and lowercases
 *   both sides before comparing, so hasRole('TENANT_ADMIN') matches
 *   the live 'Tenant Admin'.
 *
 *   hasPermission() now returns true when the user holds any of the
 *   three live roles (Admin, Super Admin, Tenant Admin) or is a
 *   super admin. The live roles table has exactly those three rows:
 *     id 1  Admin
 *     id 2  Super Admin
 *     id 3  Tenant Admin
 *
 *   The Admin row is kept. It is inert in the current code but is
 *   treated as permissive here, on the assumption that a user with
 *   the Admin role is meant to manage settings.
 */

class TenantContext
{
    private static $instance = null;

    // Core context
    private $tenantId = null;
    private $tenantName = null;
    private $tenantCode = null;
    private $tenantSettings = [];

    // School context
    private $schoolId = null;
    private $schoolName = null;
    private $schoolCode = null;
    private $schoolSettings = [];

    // Campus context
    private $campusId = null;
    private $campusName = null;

    // Domain context
    private $domainId = null;
    private $domainName = null;
    private $domainType = null;
    private $isVerified = false;

    // User context (set after authentication)
    private $userId = null;
    private $personId = null;
    private $userRoles = [];
    private $userPermissions = [];
    private $loginAudience = null;

    // Scope
    private $scope = 'tenant'; // tenant, school, campus
    private $isSuperAdmin = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ============================================================
    // SETTERS
    // ============================================================

    public function setTenantId(?int $id): self
    {
        $this->tenantId = $id;
        return $this;
    }

    public function setTenantName(?string $name): self
    {
        $this->tenantName = $name;
        return $this;
    }

    public function setTenantCode(?string $code): self
    {
        $this->tenantCode = $code;
        return $this;
    }

    public function setTenantSettings(array $settings): self
    {
        $this->tenantSettings = $settings;
        return $this;
    }

    public function setSchoolId(?int $id): self
    {
        $this->schoolId = $id;
        return $this;
    }

    public function setSchoolName(?string $name): self
    {
        $this->schoolName = $name;
        return $this;
    }

    public function setSchoolCode(?string $code): self
    {
        $this->schoolCode = $code;
        return $this;
    }

    public function setSchoolSettings(array $settings): self
    {
        $this->schoolSettings = $settings;
        return $this;
    }

    public function setCampusId(?int $id): self
    {
        $this->campusId = $id;
        return $this;
    }

    public function setCampusName(?string $name): self
    {
        $this->campusName = $name;
        return $this;
    }

    public function setDomainId(?int $id): self
    {
        $this->domainId = $id;
        return $this;
    }

    public function setDomainName(?string $name): self
    {
        $this->domainName = $name;
        return $this;
    }

    public function setDomainType(?string $type): self
    {
        $this->domainType = $type;
        return $this;
    }

    public function setIsVerified(bool $verified): self
    {
        $this->isVerified = $verified;
        return $this;
    }

    public function setUserId(?int $id): self
    {
        $this->userId = $id;
        return $this;
    }

    public function setPersonId(?int $id): self
    {
        $this->personId = $id;
        return $this;
    }

    public function setUserRoles(array $roles): self
    {
        $this->userRoles = $roles;
        return $this;
    }

    public function setUserPermissions(array $permissions): self
    {
        $this->userPermissions = $permissions;
        return $this;
    }

    public function setLoginAudience(?string $audience): self
    {
        $this->loginAudience = $audience;
        return $this;
    }

    public function setScope(string $scope): self
    {
        $validScopes = ['tenant', 'school', 'campus'];
        if (in_array($scope, $validScopes)) {
            $this->scope = $scope;
        }
        return $this;
    }

    public function setIsSuperAdmin(bool $isSuperAdmin): self
    {
        $this->isSuperAdmin = $isSuperAdmin;
        return $this;
    }

    // ============================================================
    // GETTERS
    // ============================================================

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getTenantName(): ?string
    {
        return $this->tenantName;
    }

    public function getTenantCode(): ?string
    {
        return $this->tenantCode;
    }

    public function getTenantSettings(): array
    {
        return $this->tenantSettings;
    }

    public function getTenantSetting(string $key, $default = null)
    {
        return $this->tenantSettings[$key] ?? $default;
    }

    public function getSchoolId(): ?int
    {
        return $this->schoolId;
    }

    public function getSchoolName(): ?string
    {
        return $this->schoolName;
    }

    public function getSchoolCode(): ?string
    {
        return $this->schoolCode;
    }

    public function getSchoolSettings(): array
    {
        return $this->schoolSettings;
    }

    public function getSchoolSetting(string $key, $default = null)
    {
        return $this->schoolSettings[$key] ?? $default;
    }

    public function getCampusId(): ?int
    {
        return $this->campusId;
    }

    public function getCampusName(): ?string
    {
        return $this->campusName;
    }

    public function getDomainId(): ?int
    {
        return $this->domainId;
    }

    public function getDomainName(): ?string
    {
        return $this->domainName;
    }

    public function getDomainType(): ?string
    {
        return $this->domainType;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getPersonId(): ?int
    {
        return $this->personId;
    }

    public function getUserRoles(): array
    {
        return $this->userRoles;
    }

    public function getUserPermissions(): array
    {
        return $this->userPermissions;
    }

    public function getLoginAudience(): ?string
    {
        return $this->loginAudience;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    /**
     * Check if the current user is a platform admin.
     *
     * v2.2 (2026-10-08) [CALLER-ALIGNMENT]:
     *   Added to satisfy the fourteen callers that delegate to
     *   $this->context->isPlatformAdmin(). The class v2.2 did not
     *   declare this method; it declared isSuperAdmin(), which
     *   returns the same private $isSuperAdmin flag. This method
     *   returns that flag.
     *
     *   See the class-level dockblock above for the full caller list.
     *
     * @return bool
     */
    public function isPlatformAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    // ============================================================
    // UTILITY METHODS
    // ============================================================

    /**
     * Check if tenant context is set
     */
    public function hasTenantContext(): bool
    {
        return $this->tenantId !== null && $this->domainId !== null;
    }

    /**
     * Check if school context is set
     */
    public function hasSchoolContext(): bool
    {
        return $this->schoolId !== null;
    }

    /**
     * Check if campus context is set
     */
    public function hasCampusContext(): bool
    {
        return $this->campusId !== null;
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->userId !== null && $this->personId !== null;
    }

    /**
     * Check if user has a specific role
     *
     * [1B] Normalises underscores to spaces and lowercases both sides
     * before comparing. hasRole('TENANT_ADMIN') matches 'Tenant Admin'.
     * hasRole('SUPER_ADMIN') matches 'Super Admin'.
     */
    public function hasRole(string $roleName): bool
    {
        $needle = strtolower(str_replace('_', ' ', $roleName));

        foreach ($this->userRoles as $role) {
            $haystack = strtolower(str_replace('_', ' ', $role['role_name'] ?? ''));
            if ($needle === $haystack) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has a specific permission
     *
     * [1B] The permission-string model is dropped. A user is treated
     * as having every permission if they hold any of the three live
     * roles: Admin, Super Admin, Tenant Admin. Super admins are also
     * permissive.
     */
    public function hasPermission(string $permissionCode): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }

        foreach ($this->userRoles as $role) {
            $name = strtolower($role['role_name'] ?? '');
            if (
                $name === 'admin'
                || $name === 'super admin'
                || $name === 'tenant admin'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get full context array
     */
    public function getContext(): array
    {
        return [
            'tenant' => [
                'id' => $this->tenantId,
                'name' => $this->tenantName,
                'code' => $this->tenantCode,
                'settings' => $this->tenantSettings
            ],
            'school' => [
                'id' => $this->schoolId,
                'name' => $this->schoolName,
                'code' => $this->schoolCode,
                'settings' => $this->schoolSettings
            ],
            'campus' => [
                'id' => $this->campusId,
                'name' => $this->campusName
            ],
            'domain' => [
                'id' => $this->domainId,
                'name' => $this->domainName,
                'type' => $this->domainType,
                'is_verified' => $this->isVerified
            ],
            'user' => [
                'id' => $this->userId,
                'person_id' => $this->personId,
                'roles' => $this->userRoles,
                'permissions' => $this->userPermissions,
                'login_audience' => $this->loginAudience
            ],
            'scope' => $this->scope,
            'is_super_admin' => $this->isSuperAdmin
        ];
    }

    /**
     * Get safe public context (no sensitive data)
     */
    public function getPublicContext(): array
    {
        return [
            'tenant' => [
                'id' => $this->tenantId,
                'name' => $this->tenantName,
                'code' => $this->tenantCode
            ],
            'school' => [
                'id' => $this->schoolId,
                'name' => $this->schoolName,
                'code' => $this->schoolCode
            ],
            'campus' => [
                'id' => $this->campusId,
                'name' => $this->campusName
            ],
            'domain' => [
                'name' => $this->domainName,
                'type' => $this->domainType
            ],
            'scope' => $this->scope
        ];
    }

    /**
     * Load tenant settings from database
     */
    public function loadTenantSettings(): self
    {
        if (!$this->tenantId) {
            return $this;
        }

        try {
            $db = DatabaseHelper::getInstance();
            $settings = $db->fetchAll(
                "SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?",
                [$this->tenantId]
            );

            foreach ($settings as $setting) {
                $this->tenantSettings[$setting['setting_key']] = $setting['setting_value'];
            }
        } catch (Exception $e) {
            error_log('Error loading tenant settings: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * Load school settings from database
     */
    public function loadSchoolSettings(): self
    {
        if (!$this->schoolId) {
            return $this;
        }

        try {
            $db = DatabaseHelper::getInstance();
            $settings = $db->fetchAll(
                "SELECT setting_key, setting_value FROM school_settings WHERE school_id = ?",
                [$this->schoolId]
            );

            foreach ($settings as $setting) {
                $this->schoolSettings[$setting['setting_key']] = $setting['setting_value'];
            }
        } catch (Exception $e) {
            error_log('Error loading school settings: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * Load user roles and permissions
     *
     * [1B] The permissions query is removed. There is no permissions
     * table and no role_permissions table on the live database. Only
     * the roles query runs.
     *
     * The roles query is rewritten to match the live roles table:
     *   roles(id, role_name, description, created_at)
     * The columns role_code, tenant_id, and deleted_at do not exist
     * on roles and were removed from the query.
     */
    public function loadUserPermissions(): self
    {
        if (!$this->userId) {
            return $this;
        }

        try {
            $db = DatabaseHelper::getInstance();

            $roles = $db->fetchAll(
                "SELECT r.id, r.role_name, r.description
                 FROM platform_user_roles ur
                 JOIN roles r ON ur.role_id = r.id
                 WHERE ur.user_id = ?",
                [$this->userId]
            );

            $this->userRoles = $roles;
            $this->userPermissions = [];
        } catch (Exception $e) {
            error_log('Error loading user roles: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * Clear all context
     */
    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenantName = null;
        $this->tenantCode = null;
        $this->tenantSettings = [];
        $this->schoolId = null;
        $this->schoolName = null;
        $this->schoolCode = null;
        $this->schoolSettings = [];
        $this->campusId = null;
        $this->campusName = null;
        $this->domainId = null;
        $this->domainName = null;
        $this->domainType = null;
        $this->isVerified = false;
        $this->userId = null;
        $this->personId = null;
        $this->userRoles = [];
        $this->userPermissions = [];
        $this->loginAudience = null;
        $this->scope = 'tenant';
        $this->isSuperAdmin = false;
    }

    /**
     * Restore context from session
     *
     * [SHAPE-C] Two paths:
     *   1. Preferred: $_SESSION['tenant_context'], the nested array
     *      written by TenantContextMiddleware::resolveFromDomain()
     *      -> saveToSession().
     *   2. Fallback: the flat session keys written by login.php v2.2
     *      (tenant_id, school_id, school_name). Used on the platform
     *      hostname, where TenantContextMiddleware::handle() returns
     *      true early without populating the nested context.
     */
    public function restoreFromSession(): self
    {
        if (isset($_SESSION['tenant_context'])) {
            $context = $_SESSION['tenant_context'];

            $this->tenantId = $context['tenant_id'] ?? null;
            $this->tenantName = $context['tenant_name'] ?? null;
            $this->tenantCode = $context['tenant_code'] ?? null;
            $this->schoolId = $context['school_id'] ?? null;
            $this->schoolName = $context['school_name'] ?? null;
            $this->schoolCode = $context['school_code'] ?? null;
            $this->campusId = $context['campus_id'] ?? null;
            $this->campusName = $context['campus_name'] ?? null;
            $this->domainId = $context['domain_id'] ?? null;
            $this->domainName = $context['domain_name'] ?? null;
            $this->domainType = $context['domain_type'] ?? null;
            $this->isVerified = $context['is_verified'] ?? false;
            $this->scope = $context['scope'] ?? 'tenant';

            // Load settings
            $this->loadTenantSettings();
            $this->loadSchoolSettings();
        } else {
            // [SHAPE-C] Fallback to the flat session keys.
            $this->tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
            $this->schoolId = isset($_SESSION['school_id']) ? (int)$_SESSION['school_id'] : null;
            $this->schoolName = $_SESSION['school_name'] ?? null;
            $this->scope = $this->schoolId ? 'school' : 'tenant';

            if ($this->tenantId) {
                $this->loadTenantSettings();
            }
            if ($this->schoolId) {
                $this->loadSchoolSettings();
            }
        }

        if (isset($_SESSION['user_id'])) {
            $this->userId = $_SESSION['user_id'];
            $this->personId = $_SESSION['person_id'] ?? null;
            $this->loginAudience = $_SESSION['login_audience'] ?? null;
            $this->isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

            if ($this->userId) {
                $this->loadUserPermissions();
            }
        }

        return $this;
    }

    /**
     * Save context to session
     */
    public function saveToSession(): self
    {
        $_SESSION['tenant_context'] = [
            'tenant_id' => $this->tenantId,
            'tenant_name' => $this->tenantName,
            'tenant_code' => $this->tenantCode,
            'school_id' => $this->schoolId,
            'school_name' => $this->schoolName,
            'school_code' => $this->schoolCode,
            'campus_id' => $this->campusId,
            'campus_name' => $this->campusName,
            'domain_id' => $this->domainId,
            'domain_name' => $this->domainName,
            'domain_type' => $this->domainType,
            'is_verified' => $this->isVerified,
            'scope' => $this->scope,
            'login_audience' => $this->loginAudience
        ];

        if ($this->userId) {
            $_SESSION['user_id'] = $this->userId;
            $_SESSION['person_id'] = $this->personId;
            $_SESSION['is_super_admin'] = $this->isSuperAdmin;
            $_SESSION['login_audience'] = $this->loginAudience;
        }

        return $this;
    }

    /**
     * Validate that the current context matches the request
     * Prevents tenant_id manipulation
     */
    public function validateContext(array $request): bool
    {
        // If tenant_id is provided in request, verify it matches context
        if (isset($request['tenant_id']) && $request['tenant_id'] != $this->tenantId) {
            error_log("Context validation failed: tenant_id mismatch. Request: {$request['tenant_id']}, Context: {$this->tenantId}");
            return false;
        }

        // If school_id is provided in request, verify it matches context
        if (isset($request['school_id']) && $request['school_id'] != $this->schoolId) {
            error_log("Context validation failed: school_id mismatch. Request: {$request['school_id']}, Context: {$this->schoolId}");
            return false;
        }

        // If campus_id is provided in request, verify it matches context
        if (isset($request['campus_id']) && $request['campus_id'] != $this->campusId) {
            error_log("Context validation failed: campus_id mismatch. Request: {$request['campus_id']}, Context: {$this->campusId}");
            return false;
        }

        return true;
    }
}
