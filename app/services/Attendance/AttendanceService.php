<?php
/**
 * AttendanceService.php
 * 
 * Complete Enterprise Attendance Service Layer
 * Handles all business logic for attendance management
 * 
 * @package EduTrack
 * @subpackage Services\Attendance
 */

require_once __DIR__ . '/../../models/Attendance/AttendanceModel.php';
require_once __DIR__ . '/../../helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../helpers/LoggerHelper.php';

class AttendanceService
{
    /**
     * @var AttendanceModel Attendance model instance
     */
    private $model;

    /**
     * @var DatabaseHelper Database instance
     */
    private $db;

    /**
     * @var LoggerHelper Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->model = new AttendanceModel();
        $this->db = DatabaseHelper::getInstance();
        $this->logger = new LoggerHelper();
    }

    // ================================================================
    // STUDENT ATTENDANCE
    // ================================================================

    /**
     * Mark student attendance
     * 
     * @param array $data Attendance data
     * @return array Result
     */
    public function markStudentAttendance(array $data): array
    {
        try {
            // Validate required fields
            $required = ['student_id', 'class_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $date = $data['date'] ?? date('Y-m-d');
            $type = $data['session_type'] ?? 'morning';

            // Get or create session
            $sessionId = $this->model->getOrCreateSession($data['class_id'], $date, $type);

            if (!$sessionId) {
                return [
                    'success' => false,
                    'message' => 'Failed to create attendance session'
                ];
            }

            // Get status ID
            $statusCode = $data['status'] ?? 'P';
            $statusId = $this->model->getStatusId($statusCode);

            if (!$statusId) {
                return [
                    'success' => false,
                    'message' => 'Invalid attendance status'
                ];
            }

            // Check for duplicate
            if ($this->model->exists($data['student_id'], $sessionId)) {
                // Update existing record
                $this->model->updateStudentAttendance($data['student_id'], [
                    'status_id' => $statusId,
                    'is_late' => $data['is_late'] ?? 0,
                    'late_minutes' => $data['late_minutes'] ?? null,
                    'is_excused' => $data['is_excused'] ?? 0,
                    'excused_reason' => $data['excused_reason'] ?? null,
                    'notes' => $data['notes'] ?? null
                ]);

                return [
                    'success' => true,
                    'message' => 'Attendance updated successfully',
                    'session_id' => $sessionId,
                    'updated' => true
                ];
            }

            // Prepare attendance data
            $attendanceData = [
                'session_id' => $sessionId,
                'student_id' => $data['student_id'],
                'status_id' => $statusId,
                'method_id' => $data['method_id'] ?? 7,
                'device_id' => $data['device_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'check_in_time' => $data['check_in_time'] ?? date('Y-m-d H:i:s'),
                'check_out_time' => $data['check_out_time'] ?? null,
                'check_in_latitude' => $data['check_in_latitude'] ?? null,
                'check_in_longitude' => $data['check_in_longitude'] ?? null,
                'is_late' => $data['is_late'] ?? 0,
                'late_minutes' => $data['late_minutes'] ?? null,
                'is_excused' => $data['is_excused'] ?? 0,
                'excused_reason' => $data['excused_reason'] ?? null,
                'is_verified' => $data['is_verified'] ?? 0,
                'is_synced' => $data['is_synced'] ?? 1,
                'notes' => $data['notes'] ?? null
            ];

            // Create attendance record
            $attendanceId = $this->model->createStudentAttendance($attendanceData);

            if (!$attendanceId) {
                return [
                    'success' => false,
                    'message' => 'Failed to save attendance'
                ];
            }

            // Update session counts
            $this->model->updateSessionCounts($sessionId);

            // Check if parent notification is required
            if ($this->shouldNotifyParent($statusCode)) {
                $this->sendAttendanceNotification($data['student_id'], $statusCode);
            }

            // Log the action
            $this->logger->info('Attendance marked successfully', [
                'student_id' => $data['student_id'],
                'session_id' => $sessionId,
                'status' => $statusCode
            ]);

            return [
                'success' => true,
                'message' => 'Attendance marked successfully',
                'attendance_id' => $attendanceId,
                'session_id' => $sessionId
            ];

        } catch (Exception $e) {
            $this->logger->error('Attendance marking failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get student attendance records
     * 
     * @param int $studentId Student ID
     * @param array $filters Filters
     * @return array
     */
    public function getStudentAttendance(int $studentId, array $filters = []): array
    {
        return $this->model->getStudentAttendance($studentId, $filters);
    }

    /**
     * Get student attendance summary
     * 
     * @param int $studentId Student ID
     * @param int $termId Term ID
     * @return array
     */
    public function getStudentSummary(int $studentId, int $termId): array
    {
        $summary = $this->model->getStudentSummary($studentId, $termId);
        
        if (!$summary) {
            // Calculate if not exists
            $this->model->calculateSummary($studentId, $termId);
            $summary = $this->model->getStudentSummary($studentId, $termId);
        }

        return $summary ?? [];
    }

    /**
     * Get student attendance statistics
     * 
     * @param int $studentId Student ID
     * @param int $termId Term ID
     * @return array
     */
    public function getStudentStatistics(int $studentId, int $termId): array
    {
        return $this->model->getStudentStatistics($studentId, $termId) ?? [];
    }

    // ================================================================
    // STAFF ATTENDANCE
    // ================================================================

    /**
     * Mark staff attendance
     * 
     * @param array $data Staff attendance data
     * @return array Result
     */
    public function markStaffAttendance(array $data): array
    {
        try {
            $required = ['staff_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $statusCode = $data['status'] ?? 'P';
            $statusId = $this->model->getStatusId($statusCode);

            if (!$statusId) {
                return [
                    'success' => false,
                    'message' => 'Invalid attendance status'
                ];
            }

            $attendanceData = [
                'staff_id' => $data['staff_id'],
                'status_id' => $statusId,
                'method_id' => $data['method_id'] ?? 7,
                'device_id' => $data['device_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'attendance_date' => $data['date'] ?? date('Y-m-d'),
                'check_in_time' => $data['check_in_time'] ?? date('Y-m-d H:i:s'),
                'check_out_time' => $data['check_out_time'] ?? null,
                'check_in_latitude' => $data['check_in_latitude'] ?? null,
                'check_in_longitude' => $data['check_in_longitude'] ?? null,
                'is_late' => $data['is_late'] ?? 0,
                'late_minutes' => $data['late_minutes'] ?? null,
                'is_excused' => $data['is_excused'] ?? 0,
                'excused_reason' => $data['excused_reason'] ?? null,
                'overtime_hours' => $data['overtime_hours'] ?? null,
                'is_verified' => $data['is_verified'] ?? 0,
                'is_synced' => $data['is_synced'] ?? 1,
                'notes' => $data['notes'] ?? null
            ];

            $attendanceId = $this->model->createStaffAttendance($attendanceData);

            return [
                'success' => true,
                'message' => 'Staff attendance marked successfully',
                'attendance_id' => $attendanceId
            ];

        } catch (Exception $e) {
            $this->logger->error('Staff attendance marking failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get staff attendance records
     * 
     * @param int $staffId Staff ID
     * @param array $filters Filters
     * @return array
     */
    public function getStaffAttendance(int $staffId, array $filters = []): array
    {
        return $this->model->getStaffAttendance($staffId, $filters);
    }

    // ================================================================
    // CLASS ATTENDANCE
    // ================================================================

    /**
     * Get class attendance for a specific date
     * 
     * @param int $classId Class Section ID
     * @param string $date Date
     * @param string $type Session type
     * @return array
     */
    public function getClassAttendance(int $classId, string $date, string $type = 'morning'): array
    {
        try {
            $sql = "SELECT 
                    s.id AS student_id,
                    s.first_name,
                    s.last_name,
                    s.admission_number,
                    astatus.status_name AS attendance_status,
                    astatus.category,
                    astatus.color,
                    sa.check_in_time,
                    sa.is_late,
                    sa.id AS attendance_id
                    FROM students s
                    JOIN student_enrollments se ON s.id = se.student_id
                    LEFT JOIN attendance_sessions ases ON ases.class_section_id = se.class_section_id 
                        AND ases.session_date = ? 
                        AND ases.session_type = ?
                        AND ases.is_active = 1
                    LEFT JOIN student_attendance sa ON sa.session_id = ases.id AND sa.student_id = s.id AND sa.is_active = 1
                    LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE se.class_section_id = ?
                    AND se.is_active = 1
                    AND se.enrollment_status = 'Active'
                    AND s.is_active = 1
                    ORDER BY s.first_name ASC";

            $result = $this->db->fetchAll($sql, [$date, $type, $classId]);
            
            // Get the session ID if it exists
            $session = $this->db->fetchOne("
                SELECT id FROM attendance_sessions 
                WHERE class_section_id = ? AND session_date = ? AND session_type = ? AND is_active = 1
            ", [$classId, $date, $type]);

            return [
                'students' => $result,
                'session_id' => $session['id'] ?? null,
                'date' => $date,
                'type' => $type,
                'total' => count($result)
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get class attendance: ' . $e->getMessage());
            return [
                'students' => [],
                'session_id' => null,
                'date' => $date,
                'type' => $type,
                'total' => 0
            ];
        }
    }

    /**
     * Save class attendance (batch)
     * 
     * @param array $data Attendance data
     * @return array Result
     */
    public function saveClassAttendance(array $data): array
    {
        try {
            $classId = $data['class_id'] ?? 0;
            $date = $data['date'] ?? date('Y-m-d');
            $type = $data['session_type'] ?? 'morning';
            $attendanceList = $data['attendance'] ?? [];

            if (!$classId || empty($attendanceList)) {
                return [
                    'success' => false,
                    'message' => 'Invalid data: class_id and attendance list required'
                ];
            }

            $saved = 0;
            $errors = [];

            foreach ($attendanceList as $item) {
                $studentId = $item['student_id'] ?? 0;
                $status = $item['status'] ?? 'P';
                $isLate = $item['is_late'] ?? 0;
                $lateMinutes = $item['late_minutes'] ?? null;
                $isExcused = $item['is_excused'] ?? 0;
                $excusedReason = $item['excused_reason'] ?? null;

                if ($studentId) {
                    $result = $this->markStudentAttendance([
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'date' => $date,
                        'session_type' => $type,
                        'status' => $status,
                        'is_late' => $isLate,
                        'late_minutes' => $lateMinutes,
                        'is_excused' => $isExcused,
                        'excused_reason' => $excusedReason,
                        'method_id' => $data['method_id'] ?? 7,
                        'notes' => $item['notes'] ?? null
                    ]);

                    if ($result['success']) {
                        $saved++;
                    } else {
                        $errors[] = $result['message'];
                    }
                }
            }

            return [
                'success' => true,
                'message' => "Attendance saved: $saved records",
                'saved' => $saved,
                'total' => count($attendanceList),
                'errors' => $errors
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // ATTENDANCE SESSIONS
    // ================================================================

    /**
     * Get session details
     * 
     * @param int $sessionId Session ID
     * @return array|null
     */
    public function getSession(int $sessionId): ?array
    {
        return $this->model->getSession($sessionId);
    }

    /**
     * Complete a session
     * 
     * @param int $sessionId Session ID
     * @param int $userId User ID
     * @return array
     */
    public function completeSession(int $sessionId, int $userId): array
    {
        try {
            // Update counts first
            $this->model->updateSessionCounts($sessionId);
            
            // Complete the session
            $result = $this->model->completeSession($sessionId, $userId);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Session completed successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to complete session'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // ATTENDANCE STATUSES
    // ================================================================

    /**
     * Get all attendance statuses
     * 
     * @param int $schoolId School ID
     * @return array
     */
    public function getStatuses(int $schoolId = 1): array
    {
        return $this->model->getStatuses($schoolId);
    }

    /**
     * Create a new attendance status
     * 
     * @param array $data Status data
     * @return array
     */
    public function createStatus(array $data): array
    {
        try {
            $required = ['status_name', 'status_code'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $id = $this->model->createStatus($data);

            return [
                'success' => true,
                'message' => 'Status created successfully',
                'status_id' => $id
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // ATTENDANCE DEVICES
    // ================================================================

    /**
     * Get all devices
     * 
     * @param int $schoolId School ID
     * @return array
     */
    public function getDevices(int $schoolId = 1): array
    {
        return $this->model->getDevices($schoolId);
    }

    /**
     * Register a device
     * 
     * @param array $data Device data
     * @return array
     */
    public function registerDevice(array $data): array
    {
        try {
            $required = ['device_name', 'device_serial', 'device_type_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $deviceId = $this->model->registerDevice($data);

            return [
                'success' => true,
                'message' => 'Device registered successfully',
                'device_id' => $deviceId
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Update device status
     * 
     * @param int $deviceId Device ID
     * @param string $status Status
     * @return array
     */
    public function updateDeviceStatus(int $deviceId, string $status): array
    {
        try {
            $result = $this->model->updateDeviceStatus($deviceId, $status);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Device status updated'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to update device status'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Authenticate device
     * 
     * @param string $apiKey API Key
     * @param string $apiSecret API Secret
     * @return array
     */
    public function authenticateDevice(string $apiKey, string $apiSecret): array
    {
        $device = $this->model->authenticateDevice($apiKey, $apiSecret);

        if ($device) {
            return [
                'success' => true,
                'device' => $device
            ];
        }

        return [
            'success' => false,
            'message' => 'Invalid API credentials'
        ];
    }

    // ================================================================
    // BIOMETRIC INTEGRATION
    // ================================================================

    /**
     * Process biometric check-in
     * 
     * @param array $data Biometric data
     * @return array
     */
    public function processBiometricCheckin(array $data): array
    {
        try {
            $deviceId = $data['device_id'] ?? 0;
            $identifier = $data['identifier'] ?? '';
            $schoolId = $data['school_id'] ?? 1;

            if (!$deviceId || !$identifier) {
                return [
                    'success' => false,
                    'message' => 'Missing device_id or identifier'
                ];
            }

            // Log the device activity
            $logId = $this->model->logDeviceActivity(
                $deviceId,
                $identifier,
                'checkin',
                ['school_id' => $schoolId, 'raw_data' => $data['raw_data'] ?? null]
            );

            // Find student by identifier (admission number)
            $student = $this->db->fetchOne("
                SELECT id, first_name, last_name, admission_number
                FROM students 
                WHERE (admission_number = ? OR id = ?) 
                AND school_id = ? 
                AND is_active = 1
                LIMIT 1
            ", [$identifier, $identifier, $schoolId]);

            if (!$student) {
                // Try RFID card
                $card = $this->model->getRfidCardByUid($identifier);
                if ($card) {
                    $student = $this->db->fetchOne("
                        SELECT id, first_name, last_name, admission_number
                        FROM students 
                        WHERE id = ? AND is_active = 1
                    ", [$card['student_id']]);
                }
            }

            if (!$student) {
                $this->model->matchDeviceLog($logId, 0, 0);
                return [
                    'success' => false,
                    'message' => 'Student not found',
                    'log_id' => $logId
                ];
            }

            // Match the log
            $this->model->matchDeviceLog($logId, $student['id'], 100);

            // Get current session
            $session = $this->db->fetchOne("
                SELECT id FROM attendance_sessions 
                WHERE session_date = CURDATE() 
                AND is_completed = 0 
                AND is_active = 1
                LIMIT 1
            ");

            if (!$session) {
                return [
                    'success' => false,
                    'message' => 'No active attendance session found',
                    'student' => $student
                ];
            }

            // Get present status
            $statusId = $this->model->getStatusId('P');

            // Check if already recorded
            $exists = $this->model->exists($student['id'], $session['id']);

            if (!$exists) {
                // Record attendance
                $attendanceData = [
                    'session_id' => $session['id'],
                    'student_id' => $student['id'],
                    'status_id' => $statusId,
                    'method_id' => 1, // Fingerprint
                    'device_id' => $deviceId,
                    'check_in_time' => date('Y-m-d H:i:s'),
                    'is_synced' => 1
                ];

                $attendanceId = $this->model->createStudentAttendance($attendanceData);
                $this->model->updateSessionCounts($session['id']);

                return [
                    'success' => true,
                    'message' => 'Attendance recorded successfully',
                    'student' => $student,
                    'session_id' => $session['id'],
                    'attendance_id' => $attendanceId
                ];
            }

            return [
                'success' => true,
                'message' => 'Attendance already recorded',
                'student' => $student,
                'already_recorded' => true
            ];

        } catch (Exception $e) {
            $this->logger->error('Biometric check-in failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Save biometric template
     * 
     * @param array $data Template data
     * @return array
     */
    public function saveBiometricTemplate(array $data): array
    {
        try {
            $required = ['biometric_template', 'template_hash'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $id = $this->model->saveBiometricTemplate($data);

            return [
                'success' => true,
                'message' => 'Biometric template saved successfully',
                'template_id' => $id
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get student biometric templates
     * 
     * @param int $studentId Student ID
     * @return array
     */
    public function getStudentBiometricTemplates(int $studentId): array
    {
        return $this->model->getStudentBiometricTemplates($studentId);
    }

    // ================================================================
    // RFID CARDS
    // ================================================================

    /**
     * Register RFID card
     * 
     * @param array $data Card data
     * @return array
     */
    public function registerRfidCard(array $data): array
    {
        try {
            $required = ['card_number', 'uid'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $id = $this->model->registerRfidCard($data);

            return [
                'success' => true,
                'message' => 'RFID card registered successfully',
                'card_id' => $id
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Block RFID card
     * 
     * @param int $cardId Card ID
     * @return array
     */
    public function blockRfidCard(int $cardId): array
    {
        try {
            $result = $this->model->blockRfidCard($cardId);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'RFID card blocked successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to block RFID card'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // QR CODES
    // ================================================================

    /**
     * Generate QR code
     * 
     * @param array $data QR code data
     * @return array
     */
    public function generateQrCode(array $data): array
    {
        try {
            $id = $this->model->generateQrCode($data);

            return [
                'success' => true,
                'message' => 'QR code generated successfully',
                'qr_id' => $id
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate QR code
     * 
     * @param string $qrCode QR code string
     * @return array
     */
    public function validateQrCode(string $qrCode): array
    {
        $qr = $this->model->validateQrCode($qrCode);

        if ($qr) {
            return [
                'success' => true,
                'qr_data' => $qr
            ];
        }

        return [
            'success' => false,
            'message' => 'Invalid or expired QR code'
        ];
    }

    /**
     * Use QR code
     * 
     * @param int $qrId QR ID
     * @return array
     */
    public function useQrCode(int $qrId): array
    {
        try {
            $result = $this->model->markQrCodeUsed($qrId);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'QR code used successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to use QR code'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // ATTENDANCE EXCEPTIONS
    // ================================================================

    /**
     * Create attendance exception
     * 
     * @param array $data Exception data
     * @return array
     */
    public function createException(array $data): array
    {
        try {
            $required = ['exception_date', 'reason'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            $id = $this->model->createException($data);

            return [
                'success' => true,
                'message' => 'Exception created successfully',
                'exception_id' => $id
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Approve exception
     * 
     * @param int $exceptionId Exception ID
     * @param int $userId User ID
     * @return array
     */
    public function approveException(int $exceptionId, int $userId): array
    {
        try {
            $result = $this->model->approveException($exceptionId, $userId);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Exception approved successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to approve exception'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Reject exception
     * 
     * @param int $exceptionId Exception ID
     * @param int $userId User ID
     * @param string $reason Rejection reason
     * @return array
     */
    public function rejectException(int $exceptionId, int $userId, string $reason): array
    {
        try {
            $result = $this->model->rejectException($exceptionId, $userId, $reason);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Exception rejected successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to reject exception'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // RISK ANALYSIS
    // ================================================================

    /**
     * Get risk analysis for students
     * 
     * @param array $filters Filters
     * @return array
     */
    public function getRiskAnalysis(array $filters): array
    {
        try {
            $termId = $filters['term_id'] ?? null;

            if (!$termId) {
                return [
                    'success' => false,
                    'message' => 'Term ID is required'
                ];
            }

            $threshold = $filters['threshold'] ?? 75;
            $limit = $filters['limit'] ?? 50;

            $students = $this->model->getAtRiskStudents($termId, $threshold, $limit);

            return [
                'success' => true,
                'data' => $students,
                'total_at_risk' => count($students)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate and save risk profile
     * 
     * @param int $studentId Student ID
     * @param int $termId Term ID
     * @return array
     */
    public function calculateRiskProfile(int $studentId, int $termId): array
    {
        try {
            // Get student stats
            $stats = $this->getStudentStatistics($studentId, $termId);

            $percentage = $stats['percentage'] ?? 100;
            $absences = $stats['absent'] ?? 0;
            $lates = $stats['late'] ?? 0;
            $totalSessions = $stats['total_sessions'] ?? 0;

            // Determine risk level
            if ($percentage >= 80) {
                $riskLevel = 'Low';
                $riskScore = 20;
                $recommendation = 'Good standing - no intervention needed';
                $intervention = 0;
            } elseif ($percentage >= 60) {
                $riskLevel = 'Moderate';
                $riskScore = 50;
                $recommendation = 'Monitor attendance - inform parents';
                $intervention = 0;
            } elseif ($percentage >= 40) {
                $riskLevel = 'High';
                $riskScore = 75;
                $recommendation = 'Intervention required - parent meeting recommended';
                $intervention = 1;
            } else {
                $riskLevel = 'Critical';
                $riskScore = 90;
                $recommendation = 'Urgent intervention required - immediate action needed';
                $intervention = 1;
            }

            // Get consecutive absences (simplified)
            $consecutive = 0;
            if ($absences > 0) {
                $consecutive = min($absences, 5);
            }

            // Save risk profile
            $this->model->saveRiskProfile([
                'student_id' => $studentId,
                'term_id' => $termId,
                'academic_year_id' => 1,
                'risk_level' => $riskLevel,
                'risk_score' => $riskScore,
                'attendance_percentage' => $percentage,
                'total_absences' => $absences,
                'total_lates' => $lates,
                'consecutive_absences' => $consecutive,
                'declining_trend' => 0,
                'intervention_required' => $intervention,
                'recommendation' => $recommendation
            ]);

            return [
                'success' => true,
                'risk_level' => $riskLevel,
                'risk_score' => $riskScore,
                'recommendation' => $recommendation
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // NOTIFICATIONS
    // ================================================================

    /**
     * Check if parent should be notified
     * 
     * @param string $status Status code
     * @return bool
     */
    private function shouldNotifyParent(string $status): bool
    {
        $notifyStatuses = ['A', 'L', 'ML', 'PER'];
        return in_array($status, $notifyStatuses);
    }

    /**
     * Send attendance notification to parent
     * 
     * @param int $studentId Student ID
     * @param string $status Status code
     * @return bool
     */
    private function sendAttendanceNotification(int $studentId, string $status): bool
    {
        try {
            // Get student info
            $student = $this->db->fetchOne("
                SELECT id, first_name, last_name, admission_number
                FROM students 
                WHERE id = ? AND is_active = 1
                LIMIT 1
            ", [$studentId]);

            if (!$student) {
                return false;
            }

            $statusNames = [
                'P' => 'Present',
                'A' => 'Absent',
                'L' => 'Late',
                'E' => 'Excused',
                'ML' => 'Medical Leave',
                'PER' => 'Permission Granted'
            ];

            $statusName = $statusNames[$status] ?? 'Unknown';

            $subject = "Attendance Notification - {$student['first_name']} {$student['last_name']}";
            $message = "Dear Parent/Guardian,\n\n" .
                       "This is to inform you that your child, {$student['first_name']} {$student['last_name']} " .
                       "({$student['admission_number']}), was marked as **{$statusName}** today.\n\n" .
                       "Date: " . date('F j, Y') . "\n" .
                       "Time: " . date('h:i A') . "\n\n" .
                       "Please contact the school for more information.\n\n" .
                       "Regards,\n" .
                       "EduTrack Attendance System";

            // Create notification record
            $this->model->createNotification([
                'student_id' => $studentId,
                'notification_type' => strtolower($status) === 'a' ? 'absence' : 'late',
                'channel' => 'sms',
                'subject' => $subject,
                'message' => $message,
                'reference_type' => 'attendance',
                'reference_id' => $studentId
            ]);

            return true;

        } catch (Exception $e) {
            $this->logger->error('Failed to send notification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get notifications for a student
     * 
     * @param int $studentId Student ID
     * @param int $limit Limit
     * @return array
     */
    public function getStudentNotifications(int $studentId, int $limit = 50): array
    {
        return $this->model->getStudentNotifications($studentId, $limit);
    }

    /**
     * Mark notification as read
     * 
     * @param int $notificationId Notification ID
     * @return array
     */
    public function markNotificationRead(int $notificationId): array
    {
        try {
            $result = $this->model->markNotificationRead($notificationId);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Notification marked as read'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to mark notification as read'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ================================================================
    // SYNCHRONIZATION
    // ================================================================

    /**
     * Sync offline attendance data
     * 
     * @param array $offlineData Offline attendance data
     * @return array Sync result
     */
    public function syncOfflineAttendance(array $offlineData): array
    {
        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($offlineData as $record) {
            // Add to sync queue
            $this->model->addToSyncQueue([
                'sync_type' => 'student',
                'sync_operation' => 'create',
                'entity_id' => $record['id'] ?? 0,
                'entity_data' => $record,
                'sync_priority' => 1
            ]);

            // Process immediately
            $result = $this->markStudentAttendance($record);
            
            if ($result['success']) {
                $synced++;
                $this->model->updateSyncStatus(
                    $this->db->lastInsertId(),
                    'completed'
                );
            } else {
                $failed++;
                $errors[] = $result['message'];
                $this->model->updateSyncStatus(
                    $this->db->lastInsertId(),
                    'failed',
                    $result['message']
                );
            }
        }

        return [
            'success' => $failed === 0,
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Get pending sync items
     * 
     * @param int $limit Limit
     * @return array
     */
    public function getPendingSyncItems(int $limit = 100): array
    {
        return $this->model->getPendingSyncItems($limit);
    }

    /**
     * Process sync queue
     * 
     * @return array
     */
    public function processSyncQueue(): array
    {
        $processed = 0;
        $failed = 0;
        $items = $this->model->getPendingSyncItems();

        foreach ($items as $item) {
            $data = json_decode($item['entity_data'], true);
            
            // Process based on sync type
            switch ($item['sync_type']) {
                case 'student':
                    $result = $this->markStudentAttendance($data);
                    break;
                case 'staff':
                    $result = $this->markStaffAttendance($data);
                    break;
                default:
                    $result = ['success' => false, 'message' => 'Unknown sync type'];
            }

            if ($result['success']) {
                $this->model->updateSyncStatus($item['id'], 'completed');
                $processed++;
            } else {
                $this->model->updateSyncStatus($item['id'], 'failed', $result['message']);
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($items)
        ];
    }

    // ================================================================
    // STATISTICS
    // ================================================================

    /**
     * Get attendance statistics
     * 
     * @param array $filters Filters
     * @return array
     */
    public function getStatistics(array $filters = []): array
    {
        return $this->model->getStatistics($filters);
    }

    /**
     * Get today's summary
     * 
     * @return array
     */
    public function getTodaySummary(): array
    {
        return $this->model->getTodaySummary();
    }

    /**
     * Get recent attendance
     * 
     * @param int $limit Limit
     * @return array
     */
    public function getRecentAttendance(int $limit = 50): array
    {
        return $this->model->getRecentAttendance($limit);
    }

    /**
     * Get class summary for a date
     * 
     * @param int $classId Class ID
     * @param string $date Date
     * @return array
     */
    public function getClassSummary(int $classId, string $date): array
    {
        try {
            $sql = "SELECT 
                    COUNT(DISTINCT se.student_id) AS total_expected,
                    COUNT(DISTINCT sa.id) AS total_present
                    FROM student_enrollments se
                    LEFT JOIN attendance_sessions ases ON ases.class_section_id = se.class_section_id AND ases.session_date = ?
                    LEFT JOIN student_attendance sa ON sa.session_id = ases.id
                    WHERE se.class_section_id = ?
                    AND se.is_active = 1
                    AND se.enrollment_status = 'Active'";

            return $this->db->fetchOne($sql, [$date, $classId]) ?? ['total_expected' => 0, 'total_present' => 0];

        } catch (Exception $e) {
            return ['total_expected' => 0, 'total_present' => 0];
        }
    }
}