<?php

/**
 * SchoolService.php
 * Service for managing schools with tenant isolation
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 * 
 * @filepath app/services/Platform/SchoolService.php
 */

// Check if session is already active before starting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only define the class if it doesn't already exist
if (!class_exists('SchoolService')) {

    class SchoolService
    {
        /**
         * Database instance
         * @var DatabaseHelper|PDO|null
         */
        private $db;

        /**
         * Tenant context
         * @var TenantContext|stdClass|null
         */
        private $context;

        /**
         * Constructor
         */
        public function __construct()
        {
            // Load required files with proper path detection
            $basePath = dirname(__DIR__, 2);

            // Try to load DatabaseHelper
            $helperPath = $basePath . '/helpers/DatabaseHelper.php';
            if (file_exists($helperPath)) {
                require_once $helperPath;
            }

            // Try to load TenantContext
            $contextPath = $basePath . '/services/Tenant/TenantContext.php';
            if (file_exists($contextPath)) {
                require_once $contextPath;
            }

            // Initialize database
            if (class_exists('DatabaseHelper')) {
                try {
                    $this->db = DatabaseHelper::getInstance();
                } catch (Exception $e) {
                    error_log('DatabaseHelper init error: ' . $e->getMessage());
                    $this->db = null;
                }
            }

            // If DatabaseHelper failed, try PDO directly
            if (!$this->db) {
                try {
                    $projectRoot = dirname(__DIR__, 3);
                    $configPath = $projectRoot . '/app/config/database.php';
                    if (file_exists($configPath)) {
                        require_once $configPath;
                        if (function_exists('getDBConnection')) {
                            $this->db = getDBConnection();
                        }
                    }
                } catch (Exception $e) {
                    error_log('PDO connection error: ' . $e->getMessage());
                    $this->db = null;
                }
            }

            // Initialize tenant context
            if (class_exists('TenantContext')) {
                try {
                    $this->context = TenantContext::getInstance();
                } catch (Exception $e) {
                    error_log('TenantContext init error: ' . $e->getMessage());
                    $this->context = null;
                }
            }

            // Fallback context if not available
            if (!$this->context) {
                $this->context = new stdClass();
                $this->context->tenantId = $_SESSION['tenant_id'] ?? 1;
                $this->context->isPlatformAdmin = $_SESSION['is_platform_admin'] ?? false;
            }
        }

        /**
         * Get the tenant ID from context
         */
        private function getTenantId()
        {
            if ($this->context && method_exists($this->context, 'getTenantId')) {
                return $this->context->getTenantId();
            }
            if ($this->context && property_exists($this->context, 'tenantId')) {
                return $this->context->tenantId;
            }
            return $_SESSION['tenant_id'] ?? 1;
        }

        /**
         * Check if user is platform admin
         */
        private function isPlatformAdmin()
        {
            if ($this->context && method_exists($this->context, 'isPlatformAdmin')) {
                return $this->context->isPlatformAdmin();
            }
            if ($this->context && property_exists($this->context, 'isPlatformAdmin')) {
                return $this->context->isPlatformAdmin;
            }
            return $_SESSION['is_platform_admin'] ?? false;
        }

        /**
         * Execute a query using available database connection
         */
        private function executeQuery($sql, $params = [], $single = false)
        {
            // Try using DatabaseHelper if available
            if ($this->db && method_exists($this->db, 'fetchAll')) {
                return $single ? $this->db->fetchOne($sql, $params) : $this->db->fetchAll($sql, $params);
            }

            // Try using PDO directly
            if ($this->db instanceof PDO) {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return $single ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // If we have a database helper with different method names
            if ($this->db && method_exists($this->db, 'query')) {
                if ($single) {
                    return $this->db->query($sql, $params)->fetch();
                } else {
                    return $this->db->query($sql, $params)->fetchAll();
                }
            }

            // Fallback: try to get a fresh connection
            try {
                $projectRoot = dirname(__DIR__, 3);
                $configPath = $projectRoot . '/app/config/database.php';
                if (file_exists($configPath)) {
                    require_once $configPath;
                    if (function_exists('getDBConnection')) {
                        $db = getDBConnection();
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        return $single ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            } catch (Exception $e) {
                error_log('executeQuery fallback error: ' . $e->getMessage());
            }

            return $single ? [] : [];
        }

        /**
         * Get all schools for the current tenant
         */
        public function getSchools(int $page = 1, int $limit = 20, array $filters = []): array
        {
            try {
                $tenantId = $this->getTenantId();

                // DEBUG: Log received filters
                error_log("=== SchoolService::getSchools called ===");
                error_log("Filters received: " . json_encode($filters));
                error_log("Tenant ID: " . ($tenantId ?: 'none'));
                error_log("Is Platform Admin: " . ($this->isPlatformAdmin() ? 'true' : 'false'));

                // Build WHERE clause
                $where = "s.deleted_at IS NULL";
                $params = [];

                // Tenant isolation - only show schools for current tenant
                if ($tenantId && !$this->isPlatformAdmin()) {
                    $where .= " AND s.tenant_id = ?";
                    $params[] = $tenantId;
                }

                // Apply filters
                if (!empty($filters['search'])) {
                    $where .= " AND (s.school_name LIKE ? OR s.school_code LIKE ?)";
                    $search = '%' . $filters['search'] . '%';
                    $params[] = $search;
                    $params[] = $search;
                    error_log("Search filter applied: " . $filters['search']);
                }

                if (!empty($filters['status'])) {
                    $where .= " AND s.status = ?";
                    $params[] = $filters['status'];
                    error_log("Status filter applied: " . $filters['status']);
                }

                if (!empty($filters['school_type'])) {
                    $where .= " AND s.school_type = ?";
                    $params[] = $filters['school_type'];
                    error_log("School type filter applied: " . $filters['school_type']);
                }

                // DEBUG: Log the final WHERE clause
                error_log("Final WHERE clause: " . $where);
                error_log("Params: " . json_encode($params));

                $offset = ($page - 1) * $limit;

                // Get schools
                $sql = "SELECT s.*, 
                               t.tenant_name,
                               (SELECT COUNT(*) FROM campuses c WHERE c.school_id = s.id AND c.deleted_at IS NULL) as campus_count
                        FROM schools s
                        LEFT JOIN tenants t ON s.tenant_id = t.id
                        WHERE $where
                        ORDER BY s.school_name ASC
                        LIMIT ? OFFSET ?";

                $params[] = $limit;
                $params[] = $offset;

                error_log("Final SQL: " . $sql);
                error_log("Final params: " . json_encode($params));

                $schools = $this->executeQuery($sql, $params, false);

                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM schools s WHERE $where";
                $countParams = array_slice($params, 0, -2);
                $countResult = $this->executeQuery($countSql, $countParams, true);
                $total = (int)($countResult['total'] ?? 0);

                // Get stats
                $stats = $this->getSchoolStats($tenantId);

                error_log("Schools found: " . count($schools));
                error_log("Total schools: " . $total);
                error_log("=== SchoolService::getSchools completed ===");

                return [
                    'schools' => $schools,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit),
                    'stats' => $stats
                ];
            } catch (Exception $e) {
                error_log('SchoolService::getSchools error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                return [
                    'schools' => [],
                    'total' => 0,
                    'total_pages' => 0,
                    'stats' => ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0]
                ];
            }
        }

        /**
         * Get school stats for tenant
         */
        public function getSchoolStats(?int $tenantId = null): array
        {
            try {
                $tenantId = $tenantId ?? $this->getTenantId();

                $where = "deleted_at IS NULL";
                $params = [];

                if ($tenantId && !$this->isPlatformAdmin()) {
                    $where .= " AND tenant_id = ?";
                    $params[] = $tenantId;
                }

                $sql = "SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
                        FROM schools
                        WHERE $where";

                $result = $this->executeQuery($sql, $params, true);

                return [
                    'total' => (int)($result['total'] ?? 0),
                    'active' => (int)($result['active'] ?? 0),
                    'pending' => (int)($result['pending'] ?? 0),
                    'suspended' => (int)($result['suspended'] ?? 0),
                    'inactive' => (int)($result['inactive'] ?? 0)
                ];
            } catch (Exception $e) {
                error_log('SchoolService::getSchoolStats error: ' . $e->getMessage());
                return ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0];
            }
        }

        /**
         * Get a single school by ID
         */
        public function getSchool(int $schoolId): ?array
        {
            try {
                $tenantId = $this->getTenantId();

                $sql = "SELECT s.*, t.tenant_name 
                        FROM schools s
                        LEFT JOIN tenants t ON s.tenant_id = t.id
                        WHERE s.id = ? AND s.deleted_at IS NULL";

                $params = [$schoolId];

                // If not platform admin, check tenant ownership
                if ($tenantId && !$this->isPlatformAdmin()) {
                    $sql .= " AND s.tenant_id = ?";
                    $params[] = $tenantId;
                }

                return $this->executeQuery($sql, $params, true);
            } catch (Exception $e) {
                error_log('SchoolService::getSchool error: ' . $e->getMessage());
                return null;
            }
        }

        /**
         * Get school types
         */
        public function getSchoolTypes(): array
        {
            return [
                'combined' => 'Combined',
                'primary' => 'Primary',
                'secondary' => 'Secondary',
                'basic' => 'Basic',
                'senior_high' => 'Senior High',
                'junior_high' => 'Junior High',
                'elementary' => 'Elementary',
                'middle' => 'Middle School',
                'high' => 'High School',
                'college' => 'College'
            ];
        }

        /**
         * Get school statuses
         */
        public function getStatuses(): array
        {
            return [
                'active' => 'Active',
                'pending' => 'Pending',
                'suspended' => 'Suspended',
                'inactive' => 'Inactive',
                'closed' => 'Closed'
            ];
        }
    }
}
