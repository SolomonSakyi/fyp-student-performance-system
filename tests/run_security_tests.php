#!/usr/bin/env php
<?php
/**
 * Security Test Runner
 * Executes all security tests and generates report
 *
 * @package EduTrack
 * @subpackage Tests
 * @version 1.0
 * @filepath tests/run_security_tests.php
 */

// Load bootstrap
require_once __DIR__ . '/../bootstrap/init.php';

// Load test suite
require_once __DIR__ . '/Security/CrossTenantSecurityTest.php';

echo "\n========================================\n";
echo "  EDUTRACK CROSS-TENANT SECURITY TESTS  \n";
echo "========================================\n";
echo "Starting test suite...\n\n";

$test = new CrossTenantSecurityTest();
$results = $test->runAll();

echo "\n========================================\n";
echo "  TEST RESULTS SUMMARY\n";
echo "========================================\n";
echo "Total Tests:   {$results['total']}\n";
echo "Passed:        {$results['passed']} ✅\n";
echo "Failed:        {$results['failed']} ❌\n";
echo "Success Rate:  " . round($results['success_rate'], 2) . "%\n";

if ($results['failed'] > 0) {
    echo "\n❌ FAILED TESTS:\n";
    foreach ($results['results'] as $result) {
        if ($result['status'] === 'FAIL') {
            echo "   - " . $result['test'] . "\n";
            echo "     → " . $result['message'] . "\n";
        }
    }
    exit(1);
}

echo "\n✅ ALL TESTS PASSED!\n";
exit(0);
