<?php

/**
 * Diagnostic file. Delete after use.
 * Prints the effective php.ini settings and any error log content.
 */

header('Content-Type: text/plain; charset=utf-8');

echo "display_errors:      " . ini_get('display_errors') . "\n";
echo "display_startup_errors: " . ini_get('display_startup_errors') . "\n";
echo "error_reporting:     " . ini_get('error_reporting') . "\n";
echo "error_log:           " . ini_get('error_log') . "\n";
echo "log_errors:          " . ini_get('log_errors') . "\n";
echo "REMOTE_ADDR:         " . ($_SERVER['REMOTE_ADDR'] ?? '(unset)') . "\n";
echo "\n";

$logFile = ini_get('error_log');
if ($logFile && file_exists($logFile)) {
    echo "--- contents of $logFile ---\n";
    echo file_get_contents($logFile);
} else {
    echo "(error log file not present at: " . $logFile . ")\n";
}
