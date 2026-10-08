<?php

/**
 * IdentityDocumentRepository.php
 * Repository for identity document operations
 * 
 * @package EduTrack
 * @subpackage Repositories\Identity
 * @filepath app/repositories/Identity/IdentityDocumentRepository.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class IdentityDocumentRepository
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO identity_documents (
            uid, tenant_id, document_type, document_number, document_name,
            issuing_authority, issue_date, expiry_date, status,
            verification_status, file_path, file_name, file_size,
            mime_type, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $data['uid'],
            $data['tenant_id'],
            $data['document_type'],
            $data['document_number'],
            $data['document_name'] ?? null,
            $data['issuing_authority'] ?? null,
            $data['issue_date'] ?? null,
            $data['expiry_date'] ?? null,
            $data['status'] ?? 'pending',
            $data['verification_status'] ?? 'pending',
            $data['file_path'] ?? null,
            $data['file_name'] ?? null,
            $data['file_size'] ?? null,
            $data['mime_type'] ?? null,
            $data['created_by']
        ]);

        return $this->db->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT d.*,
                t.tenant_name,
                u.first_name, u.last_name as creator_name
                FROM identity_documents d
                LEFT JOIN tenants t ON d.tenant_id = t.id
                LEFT JOIN platform_users u ON d.created_by = u.id
                WHERE d.id = ? AND d.deleted_at IS NULL";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function search(array $filters, int $page = 1, int $limit = 20): array
    {
        $params = [];
        $where = ["d.deleted_at IS NULL"];

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(d.document_number LIKE ? OR d.document_name LIKE ? OR d.document_type LIKE ? OR d.issuing_authority LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['type'])) {
            $where[] = "d.document_type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['verification_status'])) {
            $where[] = "d.verification_status = ?";
            $params[] = $filters['verification_status'];
        }

        if (!empty($filters['tenant_id'])) {
            $where[] = "d.tenant_id = ?";
            $params[] = $filters['tenant_id'];
        }

        if (!empty($filters['person_id'])) {
            $where[] = "d.person_id = ?";
            $params[] = $filters['person_id'];
        }

        $whereClause = "WHERE " . implode(" AND ", $where);
        $offset = ($page - 1) * $limit;

        $countSql = "SELECT COUNT(*) as total FROM identity_documents d $whereClause";
        $countResult = $this->db->fetchOne($countSql, $params);
        $total = (int)($countResult['total'] ?? 0);

        $sql = "SELECT d.*,
                t.tenant_name,
                u.first_name, u.last_name as creator_name
                FROM identity_documents d
                LEFT JOIN tenants t ON d.tenant_id = t.id
                LEFT JOIN platform_users u ON d.created_by = u.id
                $whereClause
                ORDER BY d.created_at DESC
                LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $documents = $this->db->fetchAll($sql, $params);

        return [
            'documents' => $documents,
            'total' => $total,
            'total_pages' => ceil($total / $limit),
            'current_page' => $page,
            'per_page' => $limit
        ];
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'document_type',
            'document_number',
            'document_name',
            'issuing_authority',
            'issue_date',
            'expiry_date',
            'status',
            'verification_status',
            'file_path',
            'file_name',
            'file_size',
            'mime_type'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE identity_documents SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, $params);
    }

    public function verify(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE identity_documents SET 
                verification_status = 'verified', 
                verified_by = ?, 
                verified_at = NOW(), 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function reject(int $id, int $verifiedBy): bool
    {
        $sql = "UPDATE identity_documents SET 
                verification_status = 'rejected', 
                verified_by = ?, 
                verified_at = NOW(), 
                updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$verifiedBy, $id]);
    }

    public function delete(int $id): bool
    {
        $sql = "UPDATE identity_documents SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL";
        return $this->db->execute($sql, [$id]);
    }

    public function getStats(?int $tenantId = null): array
    {
        $params = [];
        $where = ["deleted_at IS NULL"];

        if ($tenantId) {
            $where[] = "tenant_id = ?";
            $params[] = $tenantId;
        }

        $whereClause = "WHERE " . implode(" AND ", $where);

        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
                    SUM(CASE WHEN verification_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    COUNT(DISTINCT document_type) as document_types,
                    COUNT(DISTINCT tenant_id) as tenants
                FROM identity_documents
                $whereClause";

        return $this->db->fetchOne($sql, $params);
    }
}
