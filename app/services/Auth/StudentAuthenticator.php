<?php

/**
 * Student Authenticator
 * Specialized authentication for student users
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/StudentAuthenticator.php
 */

class StudentAuthenticator
{
    private $db;
    private $tenantContext;
    private $auditService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
        $this->auditService = new AuditService();
    }

    /**
     * Authenticate student user
     */
    public function authenticate(string $identifier, string $password): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $schoolId = $this->tenantContext->getSchoolId();

        if (!$tenantId) {
            return [
                'success' => false,
                'error' => 'Tenant context not found.'
            ];
        }

        // Find student user
        $studentUser = $this->findStudentUser($identifier, $tenantId);

        if (!$studentUser) {
            $this->auditService->logLoginFailure($tenantId, 'student', $identifier, 'user_not_found');
            return [
                'success' => false,
                'error' => 'Invalid student number or password.'
            ];
        }

        // Verify password
        if (!password_verify($password, $studentUser['password_hash'])) {
            $this->auditService->logLoginFailure($tenantId, 'student', $identifier, 'invalid_password', $studentUser['user_id']);
            return [
                'success' => false,
                'error' => 'Invalid student number or password.'
            ];
        }

        // Check if user is active
        if (!$studentUser['user_is_active']) {
            $this->auditService->logLoginFailure($tenantId, 'student', $identifier, 'user_inactive', $studentUser['user_id']);
            return [
                'success' => false,
                'error' => 'Your account is inactive.'
            ];
        }

        // Check if student is active
        if ($studentUser['enrollment_status'] !== 'Active') {
            $this->auditService->logLoginFailure($tenantId, 'student', $identifier, 'student_inactive', $studentUser['user_id']);
            return [
                'success' => false,
                'error' => 'Your student account is inactive.'
            ];
        }

        // Verify school access
        if ($schoolId && $studentUser['school_id'] != $schoolId) {
            $this->auditService->logSchoolScopeDenial($studentUser['user_id'], $tenantId, $schoolId, 'student');
            return [
                'success' => false,
                'error' => 'You are not enrolled in this school.'
            ];
        }

        // Build student context
        $studentContext = $this->buildStudentContext($studentUser, $tenantId, $schoolId);

        // Log success
        $this->auditService->logLoginSuccess(
            $studentUser['user_id'],
            $tenantId,
            $schoolId,
            null,
            'student'
        );

        return [
            'success' => true,
            'user' => $studentContext,
            'error' => null
        ];
    }

    /**
     * Find student user by identifier
     */
    private function findStudentUser(string $identifier, int $tenantId): ?array
    {
        // Try by student number
        $student = $this->db->fetchOne("
            SELECT 
                s.id as student_id,
                s.student_number,
                s.enrollment_status,
                s.school_id,
                s.class_id,
                s.date_of_birth,
                s.gender,
                s.tenant_id,
                u.id as user_id,
                u.username,
                u.email,
                u.password_hash,
                u.is_active as user_is_active,
                p.id as person_id,
                p.first_name,
                p.last_name,
                p.middle_name,
                p.primary_phone,
                p.secondary_phone,
                p.email as person_email
            FROM students s
            LEFT JOIN platform_users u ON s.id = u.student_id
            LEFT JOIN persons p ON u.person_id = p.id
            WHERE s.student_number = ? 
              AND s.tenant_id = ?
              AND (s.deleted_at IS NULL OR s.deleted_at = '')
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ", [$identifier, $tenantId]);

        if ($student) {
            return $student;
        }

        // Try by email
        $student = $this->db->fetchOne("
            SELECT 
                s.id as student_id,
                s.student_number,
                s.enrollment_status,
                s.school_id,
                s.class_id,
                s.date_of_birth,
                s.gender,
                s.tenant_id,
                u.id as user_id,
                u.username,
                u.email,
                u.password_hash,
                u.is_active as user_is_active,
                p.id as person_id,
                p.first_name,
                p.last_name,
                p.middle_name,
                p.primary_phone,
                p.secondary_phone,
                p.email as person_email
            FROM students s
            LEFT JOIN platform_users u ON s.id = u.student_id
            LEFT JOIN persons p ON u.person_id = p.id
            WHERE (p.email = ? OR u.email = ?) 
              AND s.tenant_id = ?
              AND (s.deleted_at IS NULL OR s.deleted_at = '')
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ", [$identifier, $identifier, $tenantId]);

        return $student;
    }

    /**
     * Build student context
     */
    private function buildStudentContext(array $student, int $tenantId, ?int $schoolId): array
    {
        // Get student's class
        $class = null;
        if ($student['class_id']) {
            $class = $this->db->fetchOne("
                SELECT id, class_name, class_code, academic_year_id
                FROM classes
                WHERE id = ?
            ", [$student['class_id']]);
        }

        // Get student's guardian info
        $guardian = $this->db->fetchOne("
            SELECT 
                guardian_name,
                guardian_phone,
                guardian_alt_phone,
                guardian_relationship,
                guardian_email,
                guardian_address
            FROM students
            WHERE id = ?
        ", [$student['student_id']]);

        return [
            'user_id' => $student['user_id'],
            'person_id' => $student['person_id'],
            'student_id' => $student['student_id'],
            'student_number' => $student['student_number'],
            'username' => $student['username'] ?? '',
            'email' => $student['email'] ?? $student['person_email'] ?? '',
            'first_name' => $student['first_name'] ?? '',
            'last_name' => $student['last_name'] ?? '',
            'middle_name' => $student['middle_name'] ?? '',
            'full_name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
            'primary_phone' => $student['primary_phone'] ?? '',
            'secondary_phone' => $student['secondary_phone'] ?? '',
            'date_of_birth' => $student['date_of_birth'] ?? null,
            'gender' => $student['gender'] ?? '',
            'enrollment_status' => $student['enrollment_status'] ?? 'Active',
            'school_id' => $student['school_id'] ?? $schoolId,
            'class' => $class,
            'guardian' => $guardian,
            'tenant_id' => $tenantId,
            'login_audience' => 'student',
            'is_active' => $student['user_is_active'] && $student['enrollment_status'] === 'Active'
        ];
    }
}
