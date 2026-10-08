<?php

/**
 * SchoolController.php
 * School Controller - HTTP Layer (Extended)
 * 
 * @package EduTrack
 * @subpackage Controllers\Platform
 * @version 2.0
 */

require_once dirname(__DIR__, 2) . '/services/Platform/SchoolService.php';
require_once dirname(__DIR__, 2) . '/services/Platform/CampusService.php';

class SchoolController
{
    private $schoolService;
    private $campusService;

    public function __construct()
    {
        $this->schoolService = new SchoolService();
        $this->campusService = new CampusService();
    }

    /**
     * List schools
     */
    public function index(int $tenantId): void
    {
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 20;
        $filters = [
            'status' => $_GET['status'] ?? null,
            'school_type' => $_GET['school_type'] ?? null,
            'search' => $_GET['search'] ?? null,
            'country_id' => $_GET['country_id'] ?? null,
            'region' => $_GET['region'] ?? null
        ];

        $result = $this->schoolService->getSchoolsByTenant($tenantId, false, $filters, (int)$page, (int)$limit);
        $stats = $this->schoolService->getSchoolStats($tenantId);
        $schoolTypes = $this->schoolService->getSchoolTypes();
        $statuses = $this->schoolService->getStatuses();

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/schools/index.php';
    }

    /**
     * Show create school form
     */
    public function createForm(int $tenantId): void
    {
        $schoolTypes = $this->schoolService->getSchoolTypes();
        $statuses = $this->schoolService->getStatuses();

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/schools/create.php';
    }

    /**
     * Create school
     */
    public function create(int $tenantId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /platform/schools');
            exit;
        }

        $data = [
            'tenant_id' => $tenantId,
            'school_name' => $_POST['school_name'] ?? '',
            'school_code' => $_POST['school_code'] ?? '',
            'school_type' => $_POST['school_type'] ?? 'private',
            'legal_name' => $_POST['legal_name'] ?? null,
            'short_name' => $_POST['short_name'] ?? null,
            'email' => $_POST['email'] ?? null,
            'phone' => $_POST['phone'] ?? null,
            'secondary_phone' => $_POST['secondary_phone'] ?? null,
            'website' => $_POST['website'] ?? null,
            'registration_number' => $_POST['registration_number'] ?? null,
            'address_line1' => $_POST['address_line1'] ?? null,
            'address_line2' => $_POST['address_line2'] ?? null,
            'city' => $_POST['city'] ?? null,
            'district' => $_POST['district'] ?? null,
            'region' => $_POST['region'] ?? null,
            'country_id' => $_POST['country_id'] ?? null,
            'postal_code' => $_POST['postal_code'] ?? null,
            'digital_address' => $_POST['digital_address'] ?? null,
            'primary_color' => $_POST['primary_color'] ?? '#4facfe',
            'secondary_color' => $_POST['secondary_color'] ?? '#00f2fe',
            'created_by' => $_SESSION['user_id'] ?? null
        ];

        $result = $this->schoolService->createSchool($data);

        if ($result['success']) {
            $_SESSION['success'] = 'School created successfully!';
            header('Location: /platform/schools');
        } else {
            $_SESSION['errors'] = isset($result['errors']) ? $result['errors'] : [$result['message']];
            $_SESSION['form_data'] = $_POST;
            header('Location: /platform/schools/create');
        }
        exit;
    }

    /**
     * Show school details
     */
    public function view(int $schoolId): void
    {
        $result = $this->schoolService->getSchool($schoolId);

        if (!$result['success']) {
            $_SESSION['errors'] = [$result['message']];
            header('Location: /platform/schools');
            exit;
        }

        $school = $result['data'];
        $dashboard = $this->schoolService->getSchoolDashboard($schoolId);
        $availableTransitions = $this->schoolService->getAvailableTransitions($schoolId);
        $statuses = $this->schoolService->getStatuses();

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/schools/view.php';
    }

    /**
     * Show edit school form
     */
    public function editForm(int $schoolId): void
    {
        $result = $this->schoolService->getSchool($schoolId);

        if (!$result['success']) {
            $_SESSION['errors'] = [$result['message']];
            header('Location: /platform/schools');
            exit;
        }

        $school = $result['data'];
        $schoolTypes = $this->schoolService->getSchoolTypes();
        $statuses = $this->schoolService->getStatuses();

        // Load view
        require_once dirname(__DIR__, 3) . '/public/platform/schools/edit.php';
    }

    /**
     * Update school
     */
    public function update(int $schoolId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /platform/schools');
            exit;
        }

        $data = [
            'school_name' => $_POST['school_name'] ?? null,
            'school_code' => $_POST['school_code'] ?? null,
            'school_type' => $_POST['school_type'] ?? null,
            'legal_name' => $_POST['legal_name'] ?? null,
            'short_name' => $_POST['short_name'] ?? null,
            'email' => $_POST['email'] ?? null,
            'phone' => $_POST['phone'] ?? null,
            'secondary_phone' => $_POST['secondary_phone'] ?? null,
            'website' => $_POST['website'] ?? null,
            'registration_number' => $_POST['registration_number'] ?? null,
            'address_line1' => $_POST['address_line1'] ?? null,
            'address_line2' => $_POST['address_line2'] ?? null,
            'city' => $_POST['city'] ?? null,
            'district' => $_POST['district'] ?? null,
            'region' => $_POST['region'] ?? null,
            'country_id' => $_POST['country_id'] ?? null,
            'postal_code' => $_POST['postal_code'] ?? null,
            'digital_address' => $_POST['digital_address'] ?? null,
            'primary_color' => $_POST['primary_color'] ?? null,
            'secondary_color' => $_POST['secondary_color'] ?? null,
            'updated_by' => $_SESSION['user_id'] ?? null
        ];

        $result = $this->schoolService->updateSchool($schoolId, $data);

        if ($result['success']) {
            $_SESSION['success'] = 'School updated successfully!';
            header("Location: /platform/schools/view?id={$schoolId}");
        } else {
            $_SESSION['errors'] = isset($result['errors']) ? $result['errors'] : [$result['message']];
            $_SESSION['form_data'] = $_POST;
            header("Location: /platform/schools/edit?id={$schoolId}");
        }
        exit;
    }

    /**
     * Change school status
     */
    public function status(int $schoolId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /platform/schools');
            exit;
        }

        $newStatus = $_POST['status'] ?? null;
        $updatedBy = $_SESSION['user_id'] ?? null;

        if (!$newStatus) {
            $_SESSION['errors'] = ['Status is required'];
            header("Location: /platform/schools/view?id={$schoolId}");
            exit;
        }

        $result = $this->schoolService->changeSchoolStatus($schoolId, $newStatus, $updatedBy);

        if ($result['success']) {
            $_SESSION['success'] = 'School status updated successfully!';
        } else {
            $_SESSION['errors'] = [$result['message']];
        }

        header("Location: /platform/schools/view?id={$schoolId}");
        exit;
    }

    /**
     * Archive school
     */
    public function archive(int $schoolId): void
    {
        $updatedBy = $_SESSION['user_id'] ?? null;
        $result = $this->schoolService->archiveSchool($schoolId, $updatedBy);

        if ($result['success']) {
            $_SESSION['success'] = 'School archived successfully!';
        } else {
            $_SESSION['errors'] = [$result['message']];
        }

        header('Location: /platform/schools');
        exit;
    }

    /**
     * Delete school
     */
    public function delete(int $schoolId): void
    {
        $result = $this->schoolService->deleteSchool($schoolId);

        if ($result['success']) {
            $_SESSION['success'] = 'School deleted successfully!';
        } else {
            $_SESSION['errors'] = [$result['message']];
        }

        header('Location: /platform/schools');
        exit;
    }

    /**
     * Manage school levels
     */
    public function levels(int $schoolId): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            $levelId = $_POST['level_id'] ?? null;

            if ($action === 'assign' && $levelId) {
                $result = $this->schoolService->assignLevel($schoolId, (int)$levelId);
            } elseif ($action === 'remove' && $levelId) {
                $result = $this->schoolService->removeLevel($schoolId, (int)$levelId);
            } else {
                $_SESSION['errors'] = ['Invalid action or level ID'];
                header("Location: /platform/schools/view?id={$schoolId}");
                exit;
            }

            if ($result['success']) {
                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['errors'] = [$result['message']];
            }

            header("Location: /platform/schools/view?id={$schoolId}");
            exit;
        }
    }

    /**
     * Manage school settings
     */
    public function settings(int $schoolId): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settings = $_POST['settings'] ?? [];

            if (empty($settings)) {
                $_SESSION['errors'] = ['No settings to update'];
                header("Location: /platform/schools/view?id={$schoolId}");
                exit;
            }

            $result = $this->schoolService->updateSchoolSettings($schoolId, $settings);

            if ($result['success']) {
                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['errors'] = [$result['message']];
            }

            header("Location: /platform/schools/view?id={$schoolId}");
            exit;
        }
    }

    /**
     * API: Get schools
     */
    public function apiGetSchools(int $tenantId): void
    {
        header('Content-Type: application/json');

        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 20;
        $filters = [
            'status' => $_GET['status'] ?? null,
            'school_type' => $_GET['school_type'] ?? null,
            'search' => $_GET['search'] ?? null
        ];

        $result = $this->schoolService->getSchoolsByTenant($tenantId, false, $filters, (int)$page, (int)$limit);

        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        exit;
    }

    /**
     * API: Get school details
     */
    public function apiGetSchool(int $schoolId): void
    {
        header('Content-Type: application/json');

        $result = $this->schoolService->getSchool($schoolId);

        echo json_encode($result);
        exit;
    }

    /**
     * API: Get school stats
     */
    public function apiGetStats(int $tenantId): void
    {
        header('Content-Type: application/json');

        $stats = $this->schoolService->getSchoolStats($tenantId);

        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
        exit;
    }
}
