#!/usr/bin/env php
<?php
/**
 * Domain DNS Verification Script
 * Verifies that DNS records are properly configured for all domains
 *
 * @package EduTrack
 * @subpackage Scripts
 * @version 1.0
 * @filepath scripts/verify_domain_dns.php
 */

require_once __DIR__ . '/../bootstrap/init.php';

echo "\n========================================\n";
echo "  EDUTRACK DOMAIN DNS VERIFICATION\n";
echo "========================================\n\n";

$db = DatabaseHelper::getInstance();

// Get all active domains
$domains = $db->fetchAll("
    SELECT d.*, t.tenant_name, t.tenant_code, 
           s.school_name, s.school_code
    FROM domains d
    LEFT JOIN tenants t ON d.tenant_id = t.id
    LEFT JOIN schools s ON d.school_id = s.id
    WHERE d.status = 'active' 
      AND d.deleted_at IS NULL
      AND t.status = 'active'
");

if (empty($domains)) {
    echo "No active domains found.\n";
    exit(0);
}

$verified = 0;
$failed = 0;
$results = [];

foreach ($domains as $domain) {
    $domainName = $domain['domain_name'];
    echo "Checking: {$domainName}\n";
    echo "  Tenant: " . ($domain['tenant_name'] ?? 'N/A') . "\n";
    echo "  School: " . ($domain['school_name'] ?? 'N/A') . "\n";

    // Try to resolve domain
    $ip = gethostbyname($domainName);

    if ($ip && $ip !== $domainName) {
        echo "  ✅ Resolved to: {$ip}\n";
        $verified++;
        $results[] = [
            'domain' => $domainName,
            'status' => 'success',
            'ip' => $ip
        ];
    } else {
        echo "  ❌ DNS resolution failed\n";
        $failed++;
        $results[] = [
            'domain' => $domainName,
            'status' => 'failed',
            'ip' => null
        ];
    }

    echo "\n";
}

echo "========================================\n";
echo "VERIFICATION SUMMARY\n";
echo "========================================\n";
echo "Total Domains:  " . count($domains) . "\n";
echo "Verified:       {$verified} ✅\n";
echo "Failed:         {$failed} ❌\n";

if ($failed > 0) {
    echo "\n⚠️ Some domains failed DNS resolution. Please check your DNS records.\n";
    exit(1);
} else {
    echo "\n✅ All domains verified successfully!\n";
    exit(0);
}
