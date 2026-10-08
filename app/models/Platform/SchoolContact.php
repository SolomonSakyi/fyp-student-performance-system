<?php

/**
 * SchoolContact.php
 * School Contact Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class SchoolContact extends BaseModel
{
    protected $table = 'school_contacts';

    protected $fillable = [
        'school_id',
        'contact_type',
        'name',
        'email',
        'phone',
        'position',
        'is_primary',
        'sort_order'
    ];

    /**
     * Get contacts by school
     */
    public function getContactsBySchool(int $schoolId): array
    {
        $sql = "SELECT * FROM school_contacts 
                WHERE school_id = ? 
                ORDER BY is_primary DESC, sort_order ASC";
        return $this->rawFetch($sql, [$schoolId]);
    }

    /**
     * Get primary contact
     */
    public function getPrimaryContact(int $schoolId): ?array
    {
        $sql = "SELECT * FROM school_contacts 
                WHERE school_id = ? AND is_primary = 1 
                LIMIT 1";
        $result = $this->db->fetchOne($sql, [$schoolId]);
        return $result ?: null;
    }

    /**
     * Set contact as primary (removes primary from others)
     */
    public function setPrimary(int $schoolId, int $contactId): bool
    {
        try {
            $this->db->beginTransaction();

            // Remove primary from all contacts
            $sql = "UPDATE school_contacts SET is_primary = 0 WHERE school_id = ?";
            $this->db->query($sql, [$schoolId]);

            // Set this contact as primary
            $sql = "UPDATE school_contacts SET is_primary = 1 WHERE contact_id = ?";
            $this->db->query($sql, [$contactId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Get contact types
     */
    public function getContactTypes(): array
    {
        return [
            'primary' => 'Primary Contact',
            'secondary' => 'Secondary Contact',
            'emergency' => 'Emergency Contact',
            'administrative' => 'Administrative Contact',
            'finance' => 'Finance Contact',
            'academic' => 'Academic Contact'
        ];
    }
}
