#!/usr/bin/env php
<?php
/**
 * Test Tenant Access Script
 * Verifies that all tenant domains are accessible
 *
 * @package EduTrack
 * @subpackage Scripts
 * @version 1.0
 * @filepath scripts/test_tenant_access.php
 */

echo "\n========================================\n";
echo "  TENANT ACCESS TEST\n";
echo "========================================\n\n";

$testDomains = [
    'http://admin.edutrack.local',
    'http://edutrack.churchofchristintschool.edu.gh',
    'http://edutrack.anotherschool.edu.gh',
    'http://portal.churchofchristintschool.edu.gh'
];

$results = [];

foreach ($testDomains as $domain) {
    echo "Testing: {$domain}\n";

    // Check if domain is reachable
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $domain);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 302 || $httpCode === 404) {
        echo "  ✅ Accessible (HTTP {$httpCode})\n";
        $results[$domain] = 'success';
    } else {
        echo "  ❌ Failed (HTTP {$httpCode}): {$error}\n";
        $results[$domain] = 'failed';
    }

    echo "\n";
}

echo "========================================\n";
echo "ACCESS TEST SUMMARY\n";
echo "========================================\n";

$passed = count(array_filter($results, function ($r) {
    return $r === 'success';
}));
$total = count($results);

echo "Total Domains: {$total}\n";
echo "Accessible:    {$passed} ✅\n";
echo "Failed:        " . ($total - $passed) . " ❌\n";

if ($passed < $total) {
    echo "\n⚠️ Some domains are not accessible. Please check your configuration.\n";
    exit(1);
} else {
    echo "\n✅ All domains are accessible!\n";
    exit(0);
}
