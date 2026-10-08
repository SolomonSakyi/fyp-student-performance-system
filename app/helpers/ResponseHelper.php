<?php

/**
 * ResponseHelper.php
 * Standardized API response helper
 * 
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.1
 * @filepath app/helpers/ResponseHelper.php
 *
 * v1.1 change (2026-10-05):
 *   success(), error() and send() are now static. Every caller in
 *   this codebase — SettingsController, api/platform/index.php, the
 *   settings services, the payment services, the auth flow — calls
 *   them statically. The previous version declared them as instance
 *   methods with no instance state, so PHP 8.2 raised
 *   "Non-static method ResponseHelper::error() cannot be called
 *   statically" on every call. The class has no properties, no
 *   constructor, and no $this usage, so making the three methods
 *   static is safe and strictly backward-compatible: an instance
 *   call $obj->success(...) still works, because PHP allows calling
 *   a static method on an instance. The method signatures — names,
 *   parameter lists, return types, default values — are unchanged.
 */

class ResponseHelper
{
    public static function success($data = null, string $message = 'Success', int $statusCode = 200): void
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, $data = null): void
    {
        self::send([
            'success' => false,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function send(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
