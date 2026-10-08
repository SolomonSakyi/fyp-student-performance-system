<?php

/**
 * TenantHelper - Multi-tenant detection and context management
 * @package EduTrack
 * @filepath app/helpers/TenantHelper.php
 */

class TenantHelper
{
    private static $tenant = null;
    private static $db = null;

    /**
     * Get database instance
     */
    private static function getDb()
    {
        if (self::$db === null) {
            require_once dirname(__DIR__, 2) . '/app/helpers/DatabaseHelper.php';
            self::$db = DatabaseHelper::getInstance();
        }
        return self::$db;
    }

    /**
     * Detect tenant from current request
     */
    public static function detectTenant()
    {
        if (self::$tenant !== null) {
            return self::$tenant;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $tenant = null;

        // Method 1: Subdomain detection
        $parts = explode('.', $host);
        $subdomain = $parts[0] ?? '';

        if (!empty($subdomain) && !in_array($subdomain, ['www', 'platform', 'api', 'localhost', '127.0.0.1'])) {
            $db = self::getDb();
            $tenant = $db->fetchOne(
                "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
                 FROM tenants 
                 WHERE subdomain = ? AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
                [$subdomain]
            );
        }

        // Method 2: Session fallback
        if (!$tenant && isset($_SESSION['tenant_id'])) {
            $db = self::getDb();
            $tenant = $db->fetchOne(
                "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
                 FROM tenants 
                 WHERE id = ? AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
                [$_SESSION['tenant_id']]
            );
        }

        // Method 3: URL parameter
        if (!$tenant && isset($_GET['tenant'])) {
            $db = self::getDb();
            $tenant = $db->fetchOne(
                "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
                 FROM tenants 
                 WHERE (id = ? OR subdomain = ?) AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
                [$_GET['tenant'], $_GET['tenant']]
            );
        }

        // Method 4: Header-based detection (for API requests)
        if (!$tenant && isset($_SERVER['HTTP_X_TENANT_ID'])) {
            $db = self::getDb();
            $tenant = $db->fetchOne(
                "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
                 FROM tenants 
                 WHERE id = ? AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
                [(int)$_SERVER['HTTP_X_TENANT_ID']]
            );
        }

        if (!$tenant && isset($_SERVER['HTTP_X_TENANT'])) {
            $db = self::getDb();
            $tenant = $db->fetchOne(
                "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
                 FROM tenants 
                 WHERE subdomain = ? AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
                [$_SERVER['HTTP_X_TENANT']]
            );
        }

        if ($tenant) {
            $_SESSION['tenant_id'] = $tenant['id'];
            $_SESSION['tenant_name'] = $tenant['tenant_name'];
            $_SESSION['tenant_subdomain'] = $tenant['subdomain'];
        }

        self::$tenant = $tenant;
        return $tenant;
    }

    /**
     * Get current tenant ID
     */
    public static function getCurrentTenantId()
    {
        return $_SESSION['tenant_id'] ?? null;
    }

    /**
     * Get current tenant name
     */
    public static function getTenantName()
    {
        return $_SESSION['tenant_name'] ?? 'EduTrack';
    }

    /**
     * Get current tenant subdomain
     */
    public static function getTenantSubdomain()
    {
        return $_SESSION['tenant_subdomain'] ?? '';
    }

    /**
     * Check if tenant context exists
     */
    public static function hasTenantContext()
    {
        return !empty($_SESSION['tenant_id']);
    }

    /**
     * Get tenant isolation SQL condition
     */
    public static function getTenantCondition($tableAlias = null)
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return '1=1';
        }
        $alias = $tableAlias ? $tableAlias . '.' : '';
        return "{$alias}tenant_id = " . (int)$tenantId;
    }

    /**
     * Add tenant filter to SQL query
     */
    public static function addTenantFilter($sql, $tableAlias = null)
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return $sql;
        }

        $alias = $tableAlias ? $tableAlias . '.' : '';
        $condition = "{$alias}tenant_id = " . (int)$tenantId;

        if (strpos($sql, 'WHERE') === false) {
            return $sql . ' WHERE ' . $condition;
        }

        return preg_replace('/WHERE\s+/i', 'WHERE ' . $condition . ' AND ', $sql);
    }

    /**
     * Apply tenant filter to a SQL query with more control
     * Alias for addTenantFilter for consistency
     */
    public static function applyTenantFilter($sql, $tableAlias = null, $tenantColumn = 'tenant_id')
    {
        $tenantId = self::getCurrentTenantId();

        if (!$tenantId) {
            return $sql;
        }

        $alias = $tableAlias ? $tableAlias . '.' : '';
        $condition = "{$alias}{$tenantColumn} = " . (int)$tenantId;

        if (stripos($sql, 'WHERE') === false) {
            return $sql . ' WHERE ' . $condition;
        }

        return preg_replace('/\bWHERE\b/i', 'WHERE ' . $condition . ' AND ', $sql, 1);
    }

    /**
     * Get tenant condition with placeholder for prepared statements
     */
    public static function getTenantConditionWithPlaceholder($tableAlias = null, $tenantColumn = 'tenant_id')
    {
        $alias = $tableAlias ? $tableAlias . '.' : '';
        return "{$alias}{$tenantColumn} = ?";
    }

    /**
     * Get tenant ID for use in prepared statements
     */
    public static function getTenantIdForQuery()
    {
        return self::getCurrentTenantId();
    }

    /**
     * Check if a user has access to a specific tenant
     */
    public static function userHasTenantAccess($userId, $tenantId)
    {
        $db = self::getDb();

        $count = $db->getValue(
            "SELECT COUNT(*) FROM platform_users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$userId, $tenantId]
        );

        return $count > 0;
    }

    /**
     * Get all accessible tenant IDs for a user
     */
    public static function getUserTenants($userId)
    {
        $db = self::getDb();

        $results = $db->fetchAll(
            "SELECT tenant_id FROM platform_users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        return array_column($results, 'tenant_id');
    }

    /**
     * Get tenant by ID
     */
    public static function getTenantById($tenantId)
    {
        $db = self::getDb();

        return $db->fetchOne(
            "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
             FROM tenants 
             WHERE id = ? AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
            [$tenantId]
        );
    }

    /**
     * Get tenant by subdomain
     */
    public static function getTenantBySubdomain($subdomain)
    {
        $db = self::getDb();

        return $db->fetchOne(
            "SELECT id, tenant_name, subdomain, status, subscription_plan_id 
             FROM tenants 
             WHERE subdomain = ? AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '')",
            [$subdomain]
        );
    }

    /**
     * Get full tenant URL
     */
    public static function getTenantUrl($path = '')
    {
        $subdomain = self::getTenantSubdomain();
        if (empty($subdomain)) {
            return $path;
        }

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $parts = explode('.', $host);
        $domain = implode('.', array_slice($parts, 1));

        return $protocol . '://' . $subdomain . '.' . $domain . $path;
    }

    /**
     * Set tenant context manually (for CLI or testing)
     */
    public static function setTenantContext($tenantId, $tenantName = null, $subdomain = null)
    {
        if ($tenantId) {
            $_SESSION['tenant_id'] = $tenantId;
            if ($tenantName) {
                $_SESSION['tenant_name'] = $tenantName;
            }
            if ($subdomain) {
                $_SESSION['tenant_subdomain'] = $subdomain;
            }

            // If name not provided, fetch it
            if (!$tenantName) {
                $tenant = self::getTenantById($tenantId);
                if ($tenant) {
                    $_SESSION['tenant_name'] = $tenant['tenant_name'];
                    $_SESSION['tenant_subdomain'] = $tenant['subdomain'];
                }
            }

            return true;
        }
        return false;
    }

    /**
     * Clear tenant context
     */
    public static function clearTenantContext()
    {
        unset($_SESSION['tenant_id']);
        unset($_SESSION['tenant_name']);
        unset($_SESSION['tenant_subdomain']);
        self::$tenant = null;
    }

    /**
     * Get tenant-scoped table name
     */
    public static function getScopedTable($tableName)
    {
        $tenantId = self::getCurrentTenantId();
        if ($tenantId) {
            return $tableName;
        }
        return $tableName;
    }

    /**
     * Build a tenant-scoped query with proper filtering
     */
    public static function buildScopedQuery($baseQuery, $tenantColumn = 'tenant_id', $tableAlias = null)
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return $baseQuery;
        }

        $alias = $tableAlias ? $tableAlias . '.' : '';
        $condition = "{$alias}{$tenantColumn} = " . (int)$tenantId;

        // Check if query already has WHERE
        if (stripos($baseQuery, 'WHERE') === false) {
            return $baseQuery . ' WHERE ' . $condition;
        }

        return preg_replace('/\bWHERE\b/i', 'WHERE ' . $condition . ' AND ', $baseQuery, 1);
    }

    /**
     * Check if current user is in a tenant context
     */
    public static function isInTenantContext()
    {
        return self::hasTenantContext();
    }

    /**
     * Get tenant-specific configuration value
     */
    public static function getTenantConfig($key, $default = null)
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return $default;
        }

        $db = self::getDb();
        $value = $db->getValue(
            "SELECT config_value FROM tenant_configs WHERE tenant_id = ? AND config_key = ?",
            [$tenantId, $key]
        );

        return $value !== null ? $value : $default;
    }

    /**
     * Get all tenant data
     */
    public static function getCurrentTenantData()
    {
        if (self::$tenant !== null) {
            return self::$tenant;
        }

        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return null;
        }

        self::$tenant = self::getTenantById($tenantId);
        return self::$tenant;
    }

    /**
     * Get tenant logo URL
     */
    public static function getTenantLogo()
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return null;
        }

        $db = self::getDb();
        $logo = $db->getValue(
            "SELECT logo_url FROM tenants WHERE id = ?",
            [$tenantId]
        );

        return $logo;
    }

    /**
     * Get tenant favicon URL
     */
    public static function getTenantFavicon()
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return null;
        }

        $db = self::getDb();
        $favicon = $db->getValue(
            "SELECT favicon_url FROM tenants WHERE id = ?",
            [$tenantId]
        );

        return $favicon;
    }

    /**
     * Get tenant theme colors
     */
    public static function getTenantTheme()
    {
        $tenantId = self::getCurrentTenantId();
        if (!$tenantId) {
            return [
                'primary' => '#4facfe',
                'secondary' => '#6c757d',
                'success' => '#28a745',
                'danger' => '#dc3545',
                'warning' => '#ffc107',
                'info' => '#17a2b8',
                'dark' => '#1a1a2e'
            ];
        }

        $db = self::getDb();
        $theme = $db->fetchOne(
            "SELECT primary_color, secondary_color, accent_color FROM tenants WHERE id = ?",
            [$tenantId]
        );

        return $theme ?: [
            'primary' => '#4facfe',
            'secondary' => '#6c757d',
            'success' => '#28a745',
            'danger' => '#dc3545',
            'warning' => '#ffc107',
            'info' => '#17a2b8',
            'dark' => '#1a1a2e'
        ];
    }
}
