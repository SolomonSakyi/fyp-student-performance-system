<?php

/**
 * CampusController.php
 * Campus Controller - HTTP Layer
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/services/Platform/CampusService.php';
require_once dirname(__DIR__, 2) . '/services/Platform/SchoolService.php';

class CampusController
{
    private $campusService;
    private $schoolService;

    public function __construct()
    {
        $this->campusService = new CampusService();
        $this->schoolService = new SchoolService();
    }

    /**
     * List campuses
     */
    public function index(int $tenantId): void
    {
        // Get filters
        $schoolId = $_GET['school_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $search = $_GET['search'] ?? null;

        // Get data
        if ($schoolId) {
            $result = $this->campusService->getCampusesBySchool($schoolId);
            $campuses = $result['success'] ? $result['data'] : [];
            $stats = $this->campusService->getCampusStats($schoolId);
        } else {
            $result = $this->campusService->getCampusesByTenant($tenantId);
            $campuses = $result['success'] ? $result['data'] : [];
            $stats = ['total' => count($campuses), 'active' => 0, 'pending' => 0, 'suspended' => 0, 'archived' => 0];
        }

        // Get schools for filter
        $schoolsResult = $this->schoolService->getSchoolsByTenant($tenantId);
        $schools = $schoolsResult['success'] ? $schoolsResult['data'] : [];

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/campuses/index.php';
    }

    /**
     * Show create campus form
     */
    public function createForm(int $tenantId): void
    {
        // Get schools for dropdown
        $schoolsResult = $this->schoolService->getSchoolsByTenant($tenantId, true);
        $schools = $schoolsResult['success'] ? $schoolsResult['data'] : [];

        $selectedSchoolId = $_GET['school_id'] ?? null;

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/campuses/create.php';
    }

    /**
     * Create campus
     */
    public function create(int $tenantId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /platform/campuses');
            exit;
        }

        $data = [
            'tenant_id' => $tenantId,
            'school_id' => $_POST['school_id'] ?? 0,
            'campus_name' => $_POST['campus_name'] ?? '',
            'campus_code' => $_POST['campus_code'] ?? '',
            'campus_type' => $_POST['campus_type'] ?? 'main',
            'short_name' => $_POST['short_name'] ?? null,
            'email' => $_POST['email'] ?? null,
            'phone' => $_POST['phone'] ?? null,
            'address_line1' => $_POST['address_line1'] ?? null,
            'city' => $_POST['city'] ?? null,
            'region' => $_POST['region'] ?? null,
            'country_id' => $_POST['country_id'] ?? null,
            'timezone' => $_POST['timezone'] ?? null,
            'created_by' => $_SESSION['user_id'] ?? null
        ];

        $result = $this->campusService->createCampus($data);

        if ($result['success']) {
            $_SESSION['success'] = 'Campus created successfully!';
            header('Location: /platform/campuses?school_id=' . $data['school_id']);
        } else {
            $_SESSION['errors'] = isset($result['errors']) ? $result['errors'] : [$result['message']];
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/campuses/create?school_id=' . $data['school_id']);
        }
        exit;
    }

    /**
     * Show campus details
     */
    public function view(int $campusId): void
    {
        $result = $this->campusService->getCampus($campusId);

        if (!$result['success']) {
            $_SESSION['errors'] = [$result['message']];
            header('Location: /platform/campuses');
            exit;
        }

        $campus = $result['data'];
        $dashboard = $this->campusService->getCampusDashboard($campusId);

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/campuses/view.php';
    }

    /**
     * Show edit campus form
     */
    public function editForm(int $campusId): void
    {
        $result = $this->campusService->getCampus($campusId);

        if (!$result['success']) {
            $_SESSION['errors'] = [$result['message']];
            header('Location: /platform/campuses');
            exit;
        }

        $campus = $result['data'];

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/campuses/edit.php';
    }

    /**
     * Update campus
     */
    public function update(int $campusId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /platform/campuses');
            exit;
        }

        $data = [
            'campus_name' => $_POST['campus_name'] ?? null,
            'campus_code' => $_POST['campus_code'] ?? null,
            'campus_type' => $_POST['campus_type'] ?? null,
            'short_name' => $_POST['short_name'] ?? null,
            'email' => $_POST['email'] ?? null,
            'phone' => $_POST['phone'] ?? null,
            'address_line1' => $_POST['address_line1'] ?? null,
            'city' => $_POST['city'] ?? null,
            'region' => $_POST['region'] ?? null,
            'country_id' => $_POST['country_id'] ?? null,
            'timezone' => $_POST['timezone'] ?? null,
            'updated_by' => $_SESSION['user_id'] ?? null
        ];

        $result = $this->campusService->updateCampus($campusId, $data);

        if ($result['success']) {
            $_SESSION['success'] = 'Campus updated successfully!';
            header("Location: /platform/campuses/view?id={$campusId}");
        } else {
            $_SESSION['errors'] = isset($result['errors']) ? $result['errors'] : [$result['message']];
            $_SESSION['form_data'] = $_POST;
            header("Location: /platform/campuses/edit?id={$campusId}");
        }
        exit;
    }

    /**
     * Change campus status
     */
    public function status(int $campusId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /platform/campuses');
            exit;
        }

        $newStatus = $_POST['status'] ?? null;
        $updatedBy = $_SESSION['user_id'] ?? null;

        if (!$newStatus) {
            $_SESSION['errors'] = ['Status is required'];
            header("Location: /platform/campuses/view?id={$campusId}");
            exit;
        }

        $result = $this->campusService->changeCampusStatus($campusId, $newStatus, $updatedBy);

        if ($result['success']) {
            $_SESSION['success'] = 'Campus status updated successfully!';
        } else {
            $_SESSION['errors'] = [$result['message']];
        }

        header("Location: /platform/campuses/view?id={$campusId}");
        exit;
    }

    /**
     * Archive campus
     */
    public function archive(int $campusId): void
    {
        $updatedBy = $_SESSION['user_id'] ?? null;
        $result = $this->campusService->archiveCampus($campusId, $updatedBy);

        if ($result['success']) {
            $_SESSION['success'] = 'Campus archived successfully!';
        } else {
            $_SESSION['errors'] = [$result['message']];
        }

        header('Location: /platform/campuses');
        exit;
    }

    /**
     * Delete campus
     */
    public function delete(int $campusId): void
    {
        $result = $this->campusService->deleteCampus($campusId);

        if ($result['success']) {
            $_SESSION['success'] = 'Campus deleted successfully!';
        } else {
            $_SESSION['errors'] = [$result['message']];
        }

        header('Location: /platform/campuses');
        exit;
    }

    /**
     * API: Get campuses
     */
    public function apiGetCampuses(int $tenantId): void
    {
        header('Content-Type: application/json');

        $schoolId = $_GET['school_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $search = $_GET['search'] ?? null;

        if ($schoolId) {
            $result = $this->campusService->getCampusesBySchool($schoolId);
        } else {
            $result = $this->campusService->getCampusesByTenant($tenantId);
        }

        echo json_encode($result);
        exit;
    }

    /**
     * API: Get campus details
     */
    public function apiGetCampus(int $campusId): void
    {
        header('Content-Type: application/json');

        $result = $this->campusService->getCampus($campusId);

        echo json_encode($result);
        exit;
    }
}
