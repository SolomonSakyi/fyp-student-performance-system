<?php

/**
 * CampusService.php
 * Service for Campus operations
 * 
 * @package EduTrack
 * @subpackage Services\Platform
 * @version 2.0
 */

// Check if session is already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load required files
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

// Only define the class if it doesn't already exist
if (!class_exists('CampusService')) {

    class CampusService
    {
        private $db;

        public function __construct()
        {
            $this->db = DatabaseHelper::getInstance();
        }

        /**
         * Get campuses by tenant with school name included
         */
        public function getCampusesByTenant(int $tenantId): array
        {
            try {
                // Direct query with JOIN to get school names
                $sql = "SELECT c.*, s.school_name, s.school_code, s.tenant_id
                        FROM campuses c
                        LEFT JOIN schools s ON c.school_id = s.id
                        WHERE s.tenant_id = ? AND c.deleted_at IS NULL AND (s.deleted_at IS NULL OR s.deleted_at IS NOT NULL)
                        ORDER BY s.school_name ASC, c.campus_name ASC";

                $campuses = $this->db->fetchAll($sql, [$tenantId]);

                return ['success' => true, 'data' => $campuses];
            } catch (Exception $e) {
                error_log('getCampusesByTenant error: ' . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
            }
        }

        /**
         * Get campus statistics for a tenant
         */
        public function getCampusStats(?int $tenantId = null): array
        {
            try {
                if ($tenantId) {
                    $sql = "SELECT 
                                COUNT(c.id) as total,
                                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active,
                                SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending,
                                SUM(CASE WHEN c.status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                                SUM(CASE WHEN c.status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                                SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) as closed
                            FROM campuses c
                            LEFT JOIN schools s ON c.school_id = s.id
                            WHERE s.tenant_id = ? AND c.deleted_at IS NULL AND (s.deleted_at IS NULL OR s.deleted_at IS NOT NULL)";
                    $result = $this->db->fetchOne($sql, [$tenantId]);
                } else {
                    $sql = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
                            FROM campuses
                            WHERE deleted_at IS NULL";
                    $result = $this->db->fetchOne($sql);
                }

                return [
                    'total' => (int)($result['total'] ?? 0),
                    'active' => (int)($result['active'] ?? 0),
                    'pending' => (int)($result['pending'] ?? 0),
                    'suspended' => (int)($result['suspended'] ?? 0),
                    'inactive' => (int)($result['inactive'] ?? 0),
                    'closed' => (int)($result['closed'] ?? 0)
                ];
            } catch (Exception $e) {
                error_log('getCampusStats error: ' . $e->getMessage());
                return ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0, 'closed' => 0];
            }
        }
        /**
         * Get ALL campuses (no tenant filter) - for debugging
         */
        public function getAllCampuses(): array
        {
            try {
                $sql = "SELECT c.*, s.school_name, s.school_code
                FROM campuses c
                LEFT JOIN schools s ON c.school_id = s.id
                WHERE c.deleted_at IS NULL AND (s.deleted_at IS NULL OR s.deleted_at IS NOT NULL)
                ORDER BY s.school_name ASC, c.campus_name ASC";

                $campuses = $this->db->fetchAll($sql);
                return ['success' => true, 'data' => $campuses];
            } catch (Exception $e) {
                error_log('getAllCampuses error: ' . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
            }
        }

        /**
         * Get stats for ALL campuses (no tenant filter)
         */
        public function getAllCampusStats(): array
        {
            try {
                $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
                FROM campuses
                WHERE deleted_at IS NULL";
                $result = $this->db->fetchOne($sql);

                return [
                    'success' => true,
                    'data' => [
                        'total' => (int)($result['total'] ?? 0),
                        'active' => (int)($result['active'] ?? 0),
                        'pending' => (int)($result['pending'] ?? 0),
                        'suspended' => (int)($result['suspended'] ?? 0),
                        'inactive' => (int)($result['inactive'] ?? 0),
                        'closed' => (int)($result['closed'] ?? 0)
                    ]
                ];
            } catch (Exception $e) {
                error_log('getAllCampusStats error: ' . $e->getMessage());
                return ['success' => false, 'data' => ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0, 'closed' => 0]];
            }
        }

        /**
         * Get a single campus
         */
        public function getCampus(int $campusId): array
        {
            try {
                $sql = "SELECT c.*, s.school_name, s.school_code
                        FROM campuses c
                        LEFT JOIN schools s ON c.school_id = s.id
                        WHERE c.id = ? AND c.deleted_at IS NULL";
                $campus = $this->db->fetchOne($sql, [$campusId]);

                if (!$campus) {
                    return ['success' => false, 'message' => 'Campus not found'];
                }

                return ['success' => true, 'data' => $campus];
            } catch (Exception $e) {
                error_log('getCampus error: ' . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        /**
         * Get campus types
         */
        public function getCampusTypes(): array
        {
            return [
                'main' => 'Main Campus',
                'branch' => 'Branch Campus',
                'satellite' => 'Satellite Campus',
                'online' => 'Online Campus',
                'affiliated' => 'Affiliated Campus'
            ];
        }

        /**
         * Get campus statuses
         */
        public function getCampusStatuses(): array
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
