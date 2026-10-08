<?php

/**
 * StudentController.php
 * Controller for student registration API endpoints
 * 
 * @package EduTrack
 * @subpackage Controllers\Identity
 * @filepath app/controllers/Identity/StudentController.php
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/services/Identity/StudentRegistrationService.php';
require_once $projectRoot . 'app/helpers/ResponseHelper.php';

class StudentController
{
    private $registrationService;
    private $response;

    public function __construct()
    {
        $this->registrationService = new StudentRegistrationService();
        $this->response = new ResponseHelper();
    }

    public function register(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                $this->response->error('Invalid request body. Please provide valid JSON.', 400);
                return;
            }

            if (!isset($input['created_by']) && isset($_SESSION['user_id'])) {
                $input['created_by'] = $_SESSION['user_id'];
            }

            if (!isset($input['tenant_id']) && isset($_SERVER['HTTP_X_TENANT_ID'])) {
                $input['tenant_id'] = (int)$_SERVER['HTTP_X_TENANT_ID'];
            }

            if (!isset($input['school_id']) && isset($_SERVER['HTTP_X_SCHOOL_ID'])) {
                $input['school_id'] = (int)$_SERVER['HTTP_X_SCHOOL_ID'];
            }

            $result = $this->registrationService->register($input);

            $this->response->success($result['data'], $result['message']);
        } catch (Exception $e) {
            error_log('Student registration error: ' . $e->getMessage());
            $this->response->error($e->getMessage(), 400);
        }
    }
}
