<?php
/**
 * health.php
 * 
 * Health Check Handler
 */

function handleHealth($params, $method, $action)
{
    // Check database connection
    $dbConnected = false;
    try {
        $db = DatabaseHelper::getInstance();
        $dbConnected = true;
    } catch (Exception $e) {
        $dbConnected = false;
    }

    ApiResponse::success([
        'status' => 'healthy',
        'version' => '1.0.0',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => phpversion(),
        'database' => $dbConnected ? 'connected' : 'disconnected'
    ], 'API is healthy');
}