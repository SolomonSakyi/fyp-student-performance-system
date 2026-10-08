<?php

/**
 * BaseController.php
 * 
 * Base Controller for Platform API
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 1.0
 */

class BaseController
{
    protected $request;

    public function __construct()
    {
        // Get raw input
        $rawInput = file_get_contents('php://input');

        // Initialize request as empty array
        $this->request = [];

        // If there's raw input, try to parse as JSON
        if (!empty($rawInput)) {
            $jsonData = json_decode($rawInput, true);
            if (is_array($jsonData)) {
                $this->request = $jsonData;
            }
        }

        // If no JSON data, try form data
        if (empty($this->request)) {
            $this->request = $_POST;
        }

        // Merge GET parameters
        if (!empty($_GET)) {
            $this->request = array_merge($this->request, $_GET);
        }

        // Debug log
        error_log('BaseController request: ' . print_r($this->request, true));
    }

    protected function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    protected function success(string $message = 'Operation successful', $data = null, int $statusCode = 200): void
    {
        $this->sendResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ], $statusCode);
    }

    protected function error(string $message, int $statusCode = 400): void
    {
        $this->sendResponse([
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], $statusCode);
    }

    protected function validationError(array $errors): void
    {
        $this->sendResponse([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ], 422);
    }

    protected function unauthorized(string $message = 'Unauthorized'): void
    {
        $this->sendResponse([
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], 401);
    }

    protected function forbidden(string $message = 'Forbidden'): void
    {
        $this->sendResponse([
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], 403);
    }

    protected function notFound(string $message = 'Resource not found'): void
    {
        $this->sendResponse([
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], 404);
    }

    protected function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    protected function getTenantId(): ?int
    {
        return $_SESSION['tenant_id'] ?? null;
    }

    protected function validateRequired(array $data, array $required): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }
        return $errors;
    }

    protected function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
