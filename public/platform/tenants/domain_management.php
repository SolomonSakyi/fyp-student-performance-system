<?php

/**
 * DomainManagement.php
 * Super Admin tenant domain management
 *
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 1.0
 * @filepath public/platform/tenants/domain_management.php
 *
 * v1.0 change (2026-10-08) [DEPRECATED]:
 *   This class is marked deprecated in place. It is a work-in-progress
 *   library class that is not require_once'd by any file on the record.
 *
 *   Facts established by the code as pasted:
 *
 *   - The file defines one class, DomainManagement, with one public
 *     method (registerTenantWithDomain) and six private helpers
 *     (createTenant, generateTenantCode, createAdminUser,
 *     assignAdminRole, registerSubdomain, registerCustomDomain).
 *   - It is not a page. It carries no session_start, no logged_in
 *     guard, no is_super_admin guard, no CSRF gate, no $pageTitle,
 *     no $currentPage, no inline sidebar, no .sidebar-family CSS,
 *     no toggleSidebar(), no logout(), and no header('Location: ...').
 *   - It does not require_once config/config.php, app/bootstrap.php,
 *     or app/helpers/DatabaseHelper.php. It assumes those are loaded
 *     by the caller before the class file is included.
 *   - No file on the record require_once's this class. The three
 *     tenants/ files that write (register.php, delete.php,
 *     domain_management.php itself) and the two tenants/ files that
 *     read (index.php, view.php) do not reference DomainManagement
 *     or domain_management.php by name. The other Batch 2 folders
 *     do not reference it either.
 *
 *   Its logic duplicates the tenants/register.php flow at the class
 *   level, but is NOT equivalent to the current register.php v2.2
 *   flow:
 *
 *   - register.php requires config/config.php and DatabaseHelper.php;
 *     this class requires neither.
 *   - register.php generates and writes tenants.uuid; this class's
 *     INSERT INTO tenants column list carries no uuid column.
 *   - register.php inserts an intermediate schools row and writes
 *     tenant_domains.school_id; this class's tenant_domains INSERT
 *     column lists carry no school_id column.
 *   - register.php enforces a minimum admin password length; this
 *     class calls password_hash directly with no length or
 *     complexity check.
 *
 *   Latent defect in the class body, stated but not changed:
 *
 *   - The catch block calls $this->db->rollBack() unconditionally.
 *     If beginTransaction itself throws, the catch will attempt a
 *     rollback with no active transaction.
 *
 *   New code should call the register.php flow, not this class.
 *
 *   The class body is byte-identical to the file as pasted. No
 *   method, no SQL, no require_once, and no variable was changed by
 *   this deprecation. Class names, table names, database name, and
 *   file paths remain EduTrack per X-1.
 *
 * DomainManagement.php
 * Super Admin tenant domain management
 */

class DomainManagement
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Register a tenant with domain
     */
    public function registerTenantWithDomain(array $data): array
    {
        try {
            $this->db->beginTransaction();

            // Step 1: Create tenant (UUID auto-generated)
            $tenantId = $this->createTenant($data);

            // Step 2: Generate tenant code
            $tenantCode = $this->generateTenantCode($data['tenant_name']);

            // Step 3: Update tenant with code
            $this->db->execute(
                "UPDATE tenants SET tenant_code = ? WHERE id = ?",
                [$tenantCode, $tenantId]
            );

            // Step 4: Create admin user (UUID auto-generated)
            $userId = $this->createAdminUser($tenantId, $data);

            // Step 5: Assign admin role
            $this->assignAdminRole($userId);

            // Step 6: Register subdomain
            $subdomain = $this->registerSubdomain($tenantId, $tenantCode);

            // Step 7: If custom domain provided, register it
            $customDomain = null;
            if (!empty($data['custom_domain'])) {
                $customDomain = $this->registerCustomDomain($tenantId, $data['custom_domain']);
            }

            $this->db->commit();

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'tenant_code' => $tenantCode,
                'tenant_name' => $data['tenant_name'],
                'subdomain' => $subdomain,
                'custom_domain' => $customDomain,
                'message' => 'Tenant created successfully with domain'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Create tenant
     */
    private function createTenant(array $data): int
    {
        $sql = "INSERT INTO tenants (
                    tenant_name, email, phone, address, 
                    status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())";

        $this->db->execute($sql, [
            $data['tenant_name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['address'] ?? null
        ]);

        return (int)$this->db->getValue("SELECT LAST_INSERT_ID()");
    }

    /**
     * Generate tenant code
     */
    private function generateTenantCode(string $tenantName): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $tenantName), 0, 3));

        while (strlen($prefix) < 3) {
            $prefix .= 'X';
        }

        $maxCode = $this->db->getValue(
            "SELECT MAX(tenant_code) FROM tenants WHERE tenant_code LIKE ? AND (deleted_at IS NULL OR deleted_at = '')",
            [$prefix . '-%']
        );

        if ($maxCode) {
            $parts = explode('-', $maxCode);
            $lastNumber = isset($parts[1]) ? (int)$parts[1] : 0;
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . '-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create admin user
     */
    private function createAdminUser(int $tenantId, array $data): int
    {
        $hashedPassword = password_hash($data['admin_password'], PASSWORD_DEFAULT);

        $sql = "INSERT INTO platform_users (
                    tenant_id, username, email, password_hash, 
                    first_name, last_name, phone, is_active, 
                    is_tenant_admin, is_super_admin, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 0, NOW(), NOW())";

        $this->db->execute($sql, [
            $tenantId,
            $data['admin_username'],
            $data['admin_email'],
            $hashedPassword,
            $data['admin_first_name'],
            $data['admin_last_name'],
            $data['admin_phone'] ?? null
        ]);

        return (int)$this->db->getValue("SELECT LAST_INSERT_ID()");
    }

    /**
     * Assign admin role to user
     */
    private function assignAdminRole(int $userId): void
    {
        $sql = "INSERT INTO platform_user_roles (user_id, role_id) 
                SELECT ?, id FROM roles WHERE role_name = 'tenant_admin' LIMIT 1";
        $this->db->execute($sql, [$userId]);
    }

    /**
     * Register subdomain for tenant
     */
    private function registerSubdomain(int $tenantId, string $tenantCode): string
    {
        // Convert tenant code to lowercase without hyphens for subdomain
        $subdomainPrefix = strtolower(str_replace('-', '', $tenantCode));
        $subdomain = $subdomainPrefix . '.edutrack.local';

        // Check if subdomain already exists
        $exists = $this->db->getValue(
            "SELECT COUNT(*) FROM tenant_domains WHERE domain_name = ? AND deleted_at IS NULL",
            [$subdomain]
        );

        if ($exists > 0) {
            // Add random number to make it unique
            $random = rand(100, 999);
            $subdomain = $subdomainPrefix . $random . '.edutrack.local';
        }

        // Register subdomain
        $sql = "INSERT INTO tenant_domains (
                    tenant_id, domain_name, is_primary, is_custom, 
                    is_verified, is_active, created_at
                ) VALUES (?, ?, 1, 0, 1, 1, NOW())";

        $this->db->execute($sql, [$tenantId, $subdomain]);

        return $subdomain;
    }

    /**
     * Register custom domain for tenant
     */
    private function registerCustomDomain(int $tenantId, string $domain): array
    {
        // Clean domain
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = preg_replace('/\/.*$/', '', $domain);

        // Check if domain is already used
        $exists = $this->db->getValue(
            "SELECT COUNT(*) FROM tenant_domains WHERE domain_name = ? AND deleted_at IS NULL",
            [$domain]
        );

        if ($exists > 0) {
            throw new Exception('Domain "' . $domain . '" is already registered.');
        }

        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));

        // Register custom domain
        $sql = "INSERT INTO tenant_domains (
                    tenant_id, domain_name, is_primary, is_custom, 
                    is_verified, verification_token, is_active, created_at
                ) VALUES (?, ?, 0, 1, 0, ?, 0, NOW())";

        $this->db->execute($sql, [$tenantId, $domain, $verificationToken]);

        return [
            'domain' => $domain,
            'verification_token' => $verificationToken,
            'verification_url' => 'https://' . $domain . '/verify-domain.php?token=' . $verificationToken,
            'status' => 'pending_verification'
        ];
    }
}
