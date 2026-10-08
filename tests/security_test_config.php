<?php

/**
 * Security Test Configuration
 *
 * @package EduTrack
 * @subpackage Tests
 * @version 1.0
 * @filepath tests/security_test_config.php
 */

return [
    // Test environment
    'environment' => 'testing',

    // Test tenants
    'tenants' => [
        [
            'name' => 'Security Test Tenant A',
            'code' => 'SECTESTA',
            'domain' => 'testa.edutrack.local'
        ],
        [
            'name' => 'Security Test Tenant B',
            'code' => 'SECTESTB',
            'domain' => 'testb.edutrack.local'
        ]
    ],

    // Test schools
    'schools' => [
        [
            'name' => 'Security Test School A1',
            'code' => 'STSA1'
        ],
        [
            'name' => 'Security Test School A2',
            'code' => 'STSA2'
        ]
    ],

    // Test users
    'users' => [
        [
            'username' => 'test_admin_a',
            'email' => 'admin_a@test.local',
            'role' => 'tenant_admin'
        ],
        [
            'username' => 'test_staff_a',
            'email' => 'staff_a@test.local',
            'role' => 'staff'
        ],
        [
            'username' => 'test_student_a',
            'email' => 'student_a@test.local',
            'role' => 'student'
        ]
    ],

    // Test configurations
    'tests' => [
        'tenant_isolation' => true,
        'school_isolation' => true,
        'campus_isolation' => true,
        'cross_tenant_login' => true,
        'id_manipulation' => true,
        'privilege_escalation' => true,
        'session_isolation' => true,
        'audit_context' => true
    ],

    // Timeouts
    'timeouts' => [
        'login_attempt_window' => 900,  // 15 minutes
        'login_block_duration' => 3600, // 1 hour
        'session_lifetime' => 86400,    // 24 hours
        'idle_timeout' => 1800          // 30 minutes
    ]
];
