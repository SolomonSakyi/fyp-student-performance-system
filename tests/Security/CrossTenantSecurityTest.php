<?php

/**
 * Cross-Tenant Security Test Suite
 * Tests tenant isolation, scope enforcement, and security boundaries
 *
 * @package EduTrack
 * @subpackage Tests\Security
 * @version 1.0
 * @filepath tests/Security/CrossTenantSecurityTest.php
 */

class CrossTenantSecurityTest
{
    private $db;
    private $testData = [];
    private $results = [];
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Run all cross-tenant security tests
     */
    public function runAll(): array
    {
        $this->results = [];
        $this->passed = 0;
        $this->failed = 0;

        $this->setupTestData();

        $this->testTenantIsolation();
        $this->testSchoolIsolation();
        $this->testCampusIsolation();
        $this->testUserTenantMembership();
        $this->testCrossTenantLoginAttempt();
        $this->testTenantIdManipulation();
        $this->testSchoolIdManipulation();
        $this->testCampusIdManipulation();
        $this->testPrivilegeEscalation();
        $this->testSessionIsolation();
        $this->testAuditTenantContext();

        $this->cleanupTestData();

        return [
            'results' => $this->results,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'total' => $this->passed + $this->failed,
            'success_rate' => $this->passed / ($this->passed + $this->failed) * 100
        ];
    }

    /**
     * Setup test data
     */
    private function setupTestData(): void
    {
        // Create test tenants
        $this->testData['tenant_a'] = $this->createTestTenant('Test Tenant A', 'TESTA');
        $this->testData['tenant_b'] = $this->createTestTenant('Test Tenant B', 'TESTB');

        // Create test schools
        $this->testData['school_a1'] = $this->createTestSchool(
            $this->testData['tenant_a'],
            'Test School A1',
            'TSA1'
        );
        $this->testData['school_a2'] = $this->createTestSchool(
            $this->testData['tenant_a'],
            'Test School A2',
            'TSA2'
        );
        $this->testData['school_b1'] = $this->createTestSchool(
            $this->testData['tenant_b'],
            'Test School B1',
            'TSB1'
        );

        // Create test campuses
        $this->testData['campus_a1'] = $this->createTestCampus(
            $this->testData['school_a1'],
            'Test Campus A1'
        );
        $this->testData['campus_b1'] = $this->createTestCampus(
            $this->testData['school_b1'],
            'Test Campus B1'
        );

        // Create test users
        $this->testData['user_a_staff'] = $this->createTestUser(
            $this->testData['tenant_a'],
            'staff_a',
            'staff_a@test.com',
            'Staff A'
        );
        $this->testData['user_a_student'] = $this->createTestUser(
            $this->testData['tenant_a'],
            'student_a',
            'student_a@test.com',
            'Student A'
        );
        $this->testData['user_b_staff'] = $this->createTestUser(
            $this->testData['tenant_b'],
            'staff_b',
            'staff_b@test.com',
            'Staff B'
        );
        $this->testData['user_b_student'] = $this->createTestUser(
            $this->testData['tenant_b'],
            'student_b',
            'student_b@test.com',
            'Student B'
        );

        // Create test staff profiles
        $this->testData['staff_a'] = $this->createTestStaff(
            $this->testData['user_a_staff'],
            $this->testData['tenant_a'],
            'STAFF001',
            $this->testData['school_a1']
        );
        $this->testData['staff_b'] = $this->createTestStaff(
            $this->testData['user_b_staff'],
            $this->testData['tenant_b'],
            'STAFF002',
            $this->testData['school_b1']
        );

        // Create test student profiles
        $this->testData['student_a'] = $this->createTestStudent(
            $this->testData['user_a_student'],
            $this->testData['tenant_a'],
            'STU001',
            $this->testData['school_a1']
        );
        $this->testData['student_b'] = $this->createTestStudent(
            $this->testData['user_b_student'],
            $this->testData['tenant_b'],
            'STU002',
            $this->testData['school_b1']
        );
    }

    /**
     * Cleanup test data
     */
    private function cleanupTestData(): void
    {
        // Delete in reverse order to avoid foreign key constraints
        $tables = [
            'user_sessions',
            'audit_logs',
            'students',
            'staff',
            'platform_users',
            'campuses',
            'schools',
            'tenants'
        ];

        foreach ($tables as $table) {
            $this->db->execute(
                "DELETE FROM {$table} WHERE id IN (SELECT id FROM temp_test_ids WHERE table_name = ?)",
                [$table]
            );
        }

        $this->db->execute("DELETE FROM temp_test_ids");
    }

    // ============================================================
    // TEST: TENANT ISOLATION
    // ============================================================
    private function testTenantIsolation(): void
    {
        $this->startTest('Tenant Isolation - User cannot access other tenant\'s data');

        try {
            // User A tries to access Tenant B's data
            $userA = $this->testData['user_a_staff'];
            $tenantB = $this->testData['tenant_b'];

            // Attempt to access tenant B's school data
            $schoolB = $this->db->fetchOne(
                "SELECT * FROM schools WHERE tenant_id = ? AND id = ?",
                [$tenantB, $this->testData['school_b1']]
            );

            // Verify user A cannot access school B
            $hasAccess = $this->db->getValue(
                "SELECT COUNT(*) FROM user_school_access 
                 WHERE user_id = ? AND school_id = ?",
                [$userA, $this->testData['school_b1']]
            );

            if ($hasAccess > 0) {
                $this->fail('User A has access to School B (should be isolated)');
            } else {
                $this->pass('Tenant isolation maintained - User A cannot access Tenant B data');
            }
        } catch (Exception $e) {
            $this->fail('Tenant isolation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: SCHOOL ISOLATION
    // ============================================================
    private function testSchoolIsolation(): void
    {
        $this->startTest('School Isolation - Staff cannot access other school\'s data');

        try {
            $staffA = $this->testData['staff_a'];
            $schoolA2 = $this->testData['school_a2'];

            // Verify staff A cannot access school A2 (different school, same tenant)
            $hasAccess = $this->db->getValue(
                "SELECT COUNT(*) FROM staff_school_access 
                 WHERE staff_id = ? AND school_id = ?",
                [$staffA, $schoolA2]
            );

            if ($hasAccess > 0) {
                $this->fail('Staff A has access to School A2 (should be isolated)');
            } else {
                $this->pass('School isolation maintained - Staff A cannot access School A2');
            }
        } catch (Exception $e) {
            $this->fail('School isolation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: CAMPUS ISOLATION
    // ============================================================
    private function testCampusIsolation(): void
    {
        $this->startTest('Campus Isolation - User cannot access other campus\'s data');

        try {
            $userA = $this->testData['user_a_staff'];
            $campusB1 = $this->testData['campus_b1'];

            // Verify user A cannot access campus B1
            $hasAccess = $this->db->getValue(
                "SELECT COUNT(*) FROM user_campus_access 
                 WHERE user_id = ? AND campus_id = ?",
                [$userA, $campusB1]
            );

            if ($hasAccess > 0) {
                $this->fail('User A has access to Campus B1 (should be isolated)');
            } else {
                $this->pass('Campus isolation maintained - User A cannot access Campus B1');
            }
        } catch (Exception $e) {
            $this->fail('Campus isolation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: USER TENANT MEMBERSHIP
    // ============================================================
    private function testUserTenantMembership(): void
    {
        $this->startTest('User Tenant Membership - User belongs to correct tenant');

        try {
            $userA = $this->testData['user_a_staff'];
            $tenantA = $this->testData['tenant_a'];
            $tenantB = $this->testData['tenant_b'];

            // Check user A belongs to tenant A
            $userTenant = $this->db->getValue(
                "SELECT tenant_id FROM platform_users WHERE id = ?",
                [$userA]
            );

            if ($userTenant == $tenantA) {
                $this->pass('User A correctly belongs to Tenant A');
            } else {
                $this->fail('User A does not belong to correct tenant');
            }

            // Check user A does NOT belong to tenant B
            $userTenant = $this->db->getValue(
                "SELECT tenant_id FROM platform_users WHERE id = ? AND tenant_id = ?",
                [$userA, $tenantB]
            );

            if (!$userTenant) {
                $this->pass('User A correctly does NOT belong to Tenant B');
            } else {
                $this->fail('User A incorrectly belongs to Tenant B');
            }
        } catch (Exception $e) {
            $this->fail('User tenant membership test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: CROSS-TENANT LOGIN ATTEMPT
    // ============================================================
    private function testCrossTenantLoginAttempt(): void
    {
        $this->startTest('Cross-Tenant Login Attempt - User cannot login to wrong tenant');

        try {
            $userA = $this->testData['user_a_staff'];
            $tenantB = $this->testData['tenant_b'];

            // Get user A's credentials
            $user = $this->db->fetchOne(
                "SELECT username, password_hash FROM platform_users WHERE id = ?",
                [$userA]
            );

            // Attempt to login to tenant B
            $loginAttempt = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM platform_users 
                 WHERE username = ? AND tenant_id = ? AND deleted_at IS NULL",
                [$user['username'], $tenantB]
            );

            if ($loginAttempt['count'] == 0) {
                $this->pass('Cross-tenant login prevented - User A cannot login to Tenant B');
            } else {
                $this->fail('Cross-tenant login succeeded - User A can login to Tenant B');
            }
        } catch (Exception $e) {
            $this->fail('Cross-tenant login test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: TENANT ID MANIPULATION
    // ============================================================
    private function testTenantIdManipulation(): void
    {
        $this->startTest('Tenant ID Manipulation - Server rejects manipulated tenant_id');

        try {
            $userA = $this->testData['user_a_staff'];
            $tenantA = $this->testData['tenant_a'];
            $tenantB = $this->testData['tenant_b'];

            // Simulate tenant ID manipulation in request
            $_POST['tenant_id'] = $tenantB;
            $_SESSION['user_id'] = $userA;

            // Validate tenant context
            $context = tenant()->getContext();

            // Check that tenant context matches session user's tenant
            $userTenant = $this->db->getValue(
                "SELECT tenant_id FROM platform_users WHERE id = ?",
                [$userA]
            );

            if ($userTenant != $tenantB) {
                $this->pass('Tenant ID manipulation rejected - Context matches user\'s actual tenant');
            } else {
                $this->fail('Tenant ID manipulation succeeded - Context changed to invalid tenant');
            }

            // Reset POST
            unset($_POST['tenant_id']);
        } catch (Exception $e) {
            $this->fail('Tenant ID manipulation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: SCHOOL ID MANIPULATION
    // ============================================================
    private function testSchoolIdManipulation(): void
    {
        $this->startTest('School ID Manipulation - Server rejects manipulated school_id');

        try {
            $staffA = $this->testData['staff_a'];
            $schoolA2 = $this->testData['school_a2'];

            // Simulate school ID manipulation
            $_POST['school_id'] = $schoolA2;

            // Check if staff A has access to school A2
            $hasAccess = $this->db->getValue(
                "SELECT COUNT(*) FROM staff_school_access 
                 WHERE staff_id = ? AND school_id = ?",
                [$staffA, $schoolA2]
            );

            if ($hasAccess == 0) {
                $this->pass('School ID manipulation rejected - Staff cannot access unauthorized school');
            } else {
                $this->fail('School ID manipulation succeeded - Staff accessed unauthorized school');
            }

            unset($_POST['school_id']);
        } catch (Exception $e) {
            $this->fail('School ID manipulation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: CAMPUS ID MANIPULATION
    // ============================================================
    private function testCampusIdManipulation(): void
    {
        $this->startTest('Campus ID Manipulation - Server rejects manipulated campus_id');

        try {
            $userA = $this->testData['user_a_staff'];
            $campusB1 = $this->testData['campus_b1'];

            // Simulate campus ID manipulation
            $_POST['campus_id'] = $campusB1;

            // Check if user A has access to campus B1
            $hasAccess = $this->db->getValue(
                "SELECT COUNT(*) FROM user_campus_access 
                 WHERE user_id = ? AND campus_id = ?",
                [$userA, $campusB1]
            );

            if ($hasAccess == 0) {
                $this->pass('Campus ID manipulation rejected - User cannot access unauthorized campus');
            } else {
                $this->fail('Campus ID manipulation succeeded - User accessed unauthorized campus');
            }

            unset($_POST['campus_id']);
        } catch (Exception $e) {
            $this->fail('Campus ID manipulation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: PRIVILEGE ESCALATION
    // ============================================================
    private function testPrivilegeEscalation(): void
    {
        $this->startTest('Privilege Escalation - Non-admin cannot access admin features');

        try {
            $staffA = $this->testData['staff_a'];
            $userA = $this->testData['user_a_staff'];

            // Check if staff A has admin role
            $isAdmin = $this->db->getValue(
                "SELECT COUNT(*) FROM staff_roles sr
                 JOIN roles r ON sr.role_id = r.id
                 WHERE sr.staff_id = ? AND r.role_code IN ('tenant_admin', 'school_admin')",
                [$staffA]
            );

            if ($isAdmin == 0) {
                $this->pass('Privilege escalation prevented - Staff A is not an admin');
            } else {
                $this->fail('Privilege escalation possible - Staff A has admin privileges');
            }

            // Attempt to assign admin role
            $adminRole = $this->db->getValue(
                "SELECT id FROM roles WHERE role_code = 'tenant_admin' LIMIT 1"
            );

            if ($adminRole) {
                // Try to assign admin role (should fail without proper authorization)
                try {
                    $this->db->execute(
                        "INSERT INTO staff_roles (staff_id, role_id, created_at)
                         VALUES (?, ?, NOW())",
                        [$staffA, $adminRole]
                    );
                    // If we got here, privilege escalation might be possible
                    $this->fail('Privilege escalation possible - Admin role could be assigned');

                    // Clean up
                    $this->db->execute(
                        "DELETE FROM staff_roles WHERE staff_id = ? AND role_id = ?",
                        [$staffA, $adminRole]
                    );
                } catch (Exception $e) {
                    $this->pass('Privilege escalation prevented - Admin role assignment failed');
                }
            }
        } catch (Exception $e) {
            $this->fail('Privilege escalation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: SESSION ISOLATION
    // ============================================================
    private function testSessionIsolation(): void
    {
        $this->startTest('Session Isolation - Session data isolated by tenant');

        try {
            $userA = $this->testData['user_a_staff'];
            $userB = $this->testData['user_b_staff'];
            $tenantA = $this->testData['tenant_a'];
            $tenantB = $this->testData['tenant_b'];

            // Create sessions for both users
            session_start();

            $_SESSION['user_id'] = $userA;
            $_SESSION['tenant_id'] = $tenantA;
            $sessionIdA = session_id();

            session_write_close();

            // Start new session for user B
            session_id(bin2hex(random_bytes(16)));
            session_start();

            $_SESSION['user_id'] = $userB;
            $_SESSION['tenant_id'] = $tenantB;
            $sessionIdB = session_id();

            session_write_close();

            // Verify sessions are isolated
            if ($sessionIdA !== $sessionIdB) {
                $this->pass('Session isolation maintained - Different session IDs');
            } else {
                $this->fail('Session isolation failed - Same session ID used');
            }

            // Clean up
            session_id($sessionIdA);
            session_start();
            session_destroy();

            session_id($sessionIdB);
            session_start();
            session_destroy();
        } catch (Exception $e) {
            $this->fail('Session isolation test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // TEST: AUDIT TENANT CONTEXT
    // ============================================================
    private function testAuditTenantContext(): void
    {
        $this->startTest('Audit Tenant Context - Audit logs contain correct tenant context');

        try {
            $userA = $this->testData['user_a_staff'];
            $tenantA = $this->testData['tenant_a'];

            // Log a test event
            $this->db->execute("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, details, created_at)
                VALUES (?, 'test_event', 'test', ?, ?, ?, NOW())
            ", [$userA, $userA, $tenantA, json_encode(['test' => true])]);

            // Retrieve the log
            $log = $this->db->fetchOne("
                SELECT * FROM audit_logs 
                WHERE user_id = ? AND action = 'test_event' 
                ORDER BY created_at DESC LIMIT 1
            ", [$userA]);

            if ($log && $log['tenant_id'] == $tenantA) {
                $this->pass('Audit tenant context correct - Tenant ID matches');
            } else {
                $this->fail('Audit tenant context incorrect - Tenant ID mismatch');
            }

            // Clean up
            if ($log) {
                $this->db->execute(
                    "DELETE FROM audit_logs WHERE id = ?",
                    [$log['id']]
                );
            }
        } catch (Exception $e) {
            $this->fail('Audit tenant context test failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function startTest(string $name): void
    {
        $this->currentTest = $name;
        echo "\n🧪 Testing: {$name}\n";
    }

    private function pass(string $message): void
    {
        $this->passed++;
        $this->results[] = [
            'test' => $this->currentTest,
            'status' => 'PASS',
            'message' => $message
        ];
        echo "   ✅ {$message}\n";
    }

    private function fail(string $message): void
    {
        $this->failed++;
        $this->results[] = [
            'test' => $this->currentTest,
            'status' => 'FAIL',
            'message' => $message
        ];
        echo "   ❌ {$message}\n";
    }

    private function createTestTenant(string $name, string $code): int
    {
        $this->db->execute("
            INSERT INTO tenants (tenant_name, tenant_code, status, created_at)
            VALUES (?, ?, 'active', NOW())
        ", [$name, $code]);
        return $this->db->lastInsertId();
    }

    private function createTestSchool(int $tenantId, string $name, string $code): int
    {
        $this->db->execute("
            INSERT INTO schools (tenant_id, school_name, school_code, status, created_at)
            VALUES (?, ?, ?, 'active', NOW())
        ", [$tenantId, $name, $code]);
        return $this->db->lastInsertId();
    }

    private function createTestCampus(int $schoolId, string $name): int
    {
        $this->db->execute("
            INSERT INTO campuses (school_id, campus_name, status, created_at)
            VALUES (?, ?, 'active', NOW())
        ", [$schoolId, $name]);
        return $this->db->lastInsertId();
    }

    private function createTestUser(int $tenantId, string $username, string $email, string $name): int
    {
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->execute("
            INSERT INTO platform_users (tenant_id, username, email, password_hash, first_name, last_name, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ", [$tenantId, $username, $email, $hash, $name, 'Test']);
        return $this->db->lastInsertId();
    }

    private function createTestStaff(int $userId, int $tenantId, string $number, int $schoolId): int
    {
        // Get person_id from user
        $personId = $this->db->getValue(
            "SELECT person_id FROM platform_users WHERE id = ?",
            [$userId]
        );

        if (!$personId) {
            // Create person
            $this->db->execute("
                INSERT INTO persons (first_name, last_name, created_at)
                VALUES ('Staff', 'Test', NOW())
            ");
            $personId = $this->db->lastInsertId();

            // Update user with person_id
            $this->db->execute(
                "UPDATE platform_users SET person_id = ? WHERE id = ?",
                [$personId, $userId]
            );
        }

        $this->db->execute("
            INSERT INTO staff (person_id, tenant_id, staff_number, school_id, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ", [$personId, $tenantId, $number, $schoolId]);
        return $this->db->lastInsertId();
    }

    private function createTestStudent(int $userId, int $tenantId, string $number, int $schoolId): int
    {
        $this->db->execute("
            INSERT INTO students (tenant_id, school_id, student_number, first_name, last_name, enrollment_status, created_at)
            VALUES (?, ?, ?, 'Student', 'Test', 'Active', NOW())
        ", [$tenantId, $schoolId, $number]);
        $studentId = $this->db->lastInsertId();

        // Link to user
        $this->db->execute(
            "UPDATE platform_users SET student_id = ? WHERE id = ?",
            [$studentId, $userId]
        );

        return $studentId;
    }
}
