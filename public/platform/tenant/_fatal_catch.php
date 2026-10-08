<?php

/**
 * Fatal error catcher for the tenant dashboard.
 *
 * Registers a shutdown function that fires when the script ends,
 * including on fatal errors. It prints the error's message, file,
 * and line to the response so the cause of a 500 is visible.
 *
 * TEMPORARY DIAGNOSTIC. Delete this file and its require_once
 * after the dashboard's 500 is diagnosed and fixed.
 */

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    if (headers_sent() === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "\n\n=== FATAL ERROR CAUGHT ===\n";
    echo "Type:    " . $error['type'] . "\n";
    echo "Message: " . $error['message'] . "\n";
    echo "File:    " . $error['file'] . "\n";
    echo "Line:    " . $error['line'] . "\n";
    echo "=== END FATAL ERROR ===\n";
});
