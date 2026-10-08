<?php
/**
 * AttendanceModel.php
 * 
 * Enterprise Attendance Model
 * Handles all attendance-related database operations
 * 
 * @package EduTrack
 * @subpackage Models\Attendance
 */

class AttendanceModel
{
    /**
     * @var DatabaseHelper Database connection instance
     */
    private $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    // ================================================================
    // STUDENT ATTENDANCE
    // ================================================================

    /**
     * Create student attendance record
     */
    public function createStudentAttendance(array $data): int
    {
        try {
            $sql = "INSERT INTO student_attendance (
                uuid, school_id, campus_id, session_id, student_id,
                status_id, method_id, device_id, location_id,
                check_in_time, check_out_time,
                check_in_latitude, check_in_longitude,
                check_out_latitude, check_out_longitude,
                is_late, late_minutes, is_excused, excused_reason,
                is_verified, is_synced, notes, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, 1
            )";

            $params = [
                $data['school_id'] ?? 1,
                $data['campus_id'] ?? null,
                $data['session_id'],
                $data['student_id'],
                $data['status_id'],
                $data['method_id'] ?? 7,
                $data['device_id'] ?? null,
                $data['location_id'] ?? null,
                $data['check_in_time'] ?? date('Y-m-d H:i:s'),
                $data['check_out_time'] ?? null,
                $data['check_in_latitude'] ?? null,
                $data['check_in_longitude'] ?? null,
                $data['check_out_latitude'] ?? null,
                $data['check_out_longitude'] ?? null,
                $data['is_late'] ?? 0,
                $data['late_minutes'] ?? null,
                $data['is_excused'] ?? 0,
                $data['excused_reason'] ?? null,
                $data['is_verified'] ?? 0,
                $data['is_synced'] ?? 1,
                $data['notes'] ?? null
            ];

            $this->db->query($sql, $params);
            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create attendance record: " . $e->getMessage());
        }
    }

    /**
     * Update student attendance
     */
    public function updateStudentAttendance(int $id, array $data): bool
    {
        try {
            $fields = [];
            $params = [];

            $allowedFields = [
                'status_id', 'is_late', 'late_minutes', 'is_excused',
                'excused_reason', 'check_out_time', 'notes', 'is_verified',
                'verified_by', 'verified_date', 'is_synced', 'sync_date'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $id;
            $sql = "UPDATE student_attendance SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

            $this->db->query($sql, $params);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get student attendance by ID
     */
    public function getStudentAttendanceById(int $id): ?array
    {
        try {
            $sql = "SELECT sa.*, 
                    s.first_name, s.last_name, s.admission_number,
                    astatus.status_name, astatus.category, astatus.color,
                    am.method_name,
                    ad.device_name,
                    al.location_name
                    FROM student_attendance sa
                    LEFT JOIN students s ON sa.student_id = s.id
                    LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    LEFT JOIN attendance_methods am ON sa.method_id = am.id
                    LEFT JOIN attendance_devices ad ON sa.device_id = ad.id
                    LEFT JOIN attendance_locations al ON sa.location_id = al.id
                    WHERE sa.id = ? AND sa.is_active = 1";

            return $this->db->fetchOne($sql, [$id]);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get student attendance records with filters
     */
    public function getStudentAttendance(int $studentId, array $filters = []): array
    {
        try {
            $sql = "SELECT sa.*, 
                    ases.session_date, ases.session_type,
                    astatus.status_name, astatus.category,
                    am.method_name,
                    ad.device_name,
                    al.location_name
                    FROM student_attendance sa
                    JOIN attendance_sessions ases ON sa.session_id = ases.id
                    LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    LEFT JOIN attendance_methods am ON sa.method_id = am.id
                    LEFT JOIN attendance_devices ad ON sa.device_id = ad.id
                    LEFT JOIN attendance_locations al ON sa.location_id = al.id
                    WHERE sa.student_id = ? AND sa.is_active = 1";

            $params = [$studentId];

            if (!empty($filters['start_date'])) {
                $sql .= " AND ases.session_date >= ?";
                $params[] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $sql .= " AND ases.session_date <= ?";
                $params[] = $filters['end_date'];
            }

            if (!empty($filters['term_id'])) {
                $sql .= " AND ases.academic_term_id = ?";
                $params[] = $filters['term_id'];
            }

            if (!empty($filters['status_id'])) {
                $sql .= " AND sa.status_id = ?";
                $params[] = $filters['status_id'];
            }

            $sql .= " ORDER BY ases.session_date DESC, sa.check_in_time DESC";

            return $this->db->fetchAll($sql, $params);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Check if attendance exists for a student in a session
     */
    public function exists(int $studentId, int $sessionId): bool
    {
        try {
            $sql = "SELECT COUNT(*) as count FROM student_attendance 
                    WHERE student_id = ? AND session_id = ? AND is_active = 1";
            $result = $this->db->fetchOne($sql, [$studentId, $sessionId]);
            return ($result['count'] ?? 0) > 0;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get student attendance statistics
     */
    public function getStudentStatistics(int $studentId, int $termId): array
    {
        try {
            // First try with academic_term_id
            $sql = "SELECT 
                        COUNT(DISTINCT ases.id) AS total_sessions,
                        SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS late,
                        SUM(CASE WHEN astatus.is_excused = 1 THEN 1 ELSE 0 END) AS excused,
                        ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS percentage
                    FROM student_attendance sa
                    JOIN attendance_sessions ases ON sa.session_id = ases.id
                    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE sa.student_id = ?
                    AND sa.is_active = 1";

            $params = [$studentId];

            // Try to add term filter if column exists
            try {
                $sql .= " AND ases.academic_term_id = ?";
                $params[] = $termId;
            } catch (Exception $e) {
                // If column doesn't exist, use date range
                $term = $this->db->fetchOne("SELECT start_date, end_date FROM academic_terms WHERE id = ?", [$termId]);
                if ($term) {
                    $sql = str_replace("WHERE sa.student_id = ?", "WHERE sa.student_id = ? AND ases.session_date BETWEEN ? AND ?", $sql);
                    $params = [$studentId, $term['start_date'], $term['end_date']];
                }
            }

            return $this->db->fetchOne($sql, $params) ?? [];

        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // STAFF ATTENDANCE
    // ================================================================

    /**
     * Create staff attendance record
     */
    public function createStaffAttendance(array $data): int
    {
        try {
            $sql = "INSERT INTO staff_attendance (
                uuid, school_id, campus_id, staff_id, status_id,
                method_id, device_id, location_id,
                attendance_date, check_in_time, check_out_time,
                check_in_latitude, check_in_longitude,
                check_out_latitude, check_out_longitude,
                is_late, late_minutes, is_excused, excused_reason,
                overtime_hours, is_verified, is_synced, notes, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, 1
            )";

            $params = [
                $data['school_id'] ?? 1,
                $data['campus_id'] ?? null,
                $data['staff_id'],
                $data['status_id'],
                $data['method_id'] ?? 7,
                $data['device_id'] ?? null,
                $data['location_id'] ?? null,
                $data['attendance_date'] ?? date('Y-m-d'),
                $data['check_in_time'] ?? null,
                $data['check_out_time'] ?? null,
                $data['check_in_latitude'] ?? null,
                $data['check_in_longitude'] ?? null,
                $data['check_out_latitude'] ?? null,
                $data['check_out_longitude'] ?? null,
                $data['is_late'] ?? 0,
                $data['late_minutes'] ?? null,
                $data['is_excused'] ?? 0,
                $data['excused_reason'] ?? null,
                $data['overtime_hours'] ?? null,
                $data['is_verified'] ?? 0,
                $data['is_synced'] ?? 1,
                $data['notes'] ?? null
            ];

            $this->db->query($sql, $params);
            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create staff attendance: " . $e->getMessage());
        }
    }

    /**
     * Get staff attendance records
     */
    public function getStaffAttendance(int $staffId, array $filters = []): array
    {
        try {
            $sql = "SELECT sa.*, 
                    astatus.status_name, astatus.category,
                    am.method_name,
                    ad.device_name,
                    al.location_name
                    FROM staff_attendance sa
                    LEFT JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    LEFT JOIN attendance_methods am ON sa.method_id = am.id
                    LEFT JOIN attendance_devices ad ON sa.device_id = ad.id
                    LEFT JOIN attendance_locations al ON sa.location_id = al.id
                    WHERE sa.staff_id = ? AND sa.is_active = 1";

            $params = [$staffId];

            if (!empty($filters['start_date'])) {
                $sql .= " AND sa.attendance_date >= ?";
                $params[] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $sql .= " AND sa.attendance_date <= ?";
                $params[] = $filters['end_date'];
            }

            $sql .= " ORDER BY sa.attendance_date DESC";

            return $this->db->fetchAll($sql, $params);

        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // ATTENDANCE SESSIONS
    // ================================================================

    /**
     * Get or create attendance session
     */
    public function getOrCreateSession(int $classId, string $date, string $type = 'morning'): ?int
    {
        try {
            // Check if session exists
            $sql = "SELECT id FROM attendance_sessions 
                    WHERE class_section_id = ? 
                    AND session_date = ? 
                    AND session_type = ? 
                    AND is_completed = 0
                    AND is_active = 1";

            $session = $this->db->fetchOne($sql, [$classId, $date, $type]);

            if ($session) {
                return $session['id'];
            }

            // Get total students in class
            $total = $this->db->fetchOne("
                SELECT COUNT(DISTINCT se.student_id) as total
                FROM student_enrollments se
                WHERE se.class_section_id = ? 
                AND se.is_active = 1 
                AND se.enrollment_status = 'Active'
            ", [$classId]);

            // Create new session
            $sql = "INSERT INTO attendance_sessions (
                uuid, school_id, class_section_id,
                session_date, session_type, session_code, total_expected, is_active
            ) VALUES (
                UUID(), 1, ?,
                ?, ?, ?, ?, 1
            )";

            $sessionCode = 'SESS_' . $classId . '_' . str_replace('-', '', $date) . '_' . strtoupper(substr($type, 0, 3));

            $this->db->query($sql, [
                $classId,
                $date,
                $type,
                $sessionCode,
                $total['total'] ?? 0
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get session by ID
     */
    public function getSession(int $sessionId): ?array
    {
        try {
            $sql = "SELECT ases.*, 
                    cs.section_name, gl.level_name,
                    ay.year_name, at.term_name
                    FROM attendance_sessions ases
                    LEFT JOIN class_sections cs ON ases.class_section_id = cs.id
                    LEFT JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    LEFT JOIN academic_years ay ON ases.academic_year_id = ay.id
                    LEFT JOIN academic_terms at ON ases.academic_term_id = at.id
                    WHERE ases.id = ? AND ases.is_active = 1";

            return $this->db->fetchOne($sql, [$sessionId]);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update session counts
     */
    public function updateSessionCounts(int $sessionId): bool
    {
        try {
            $sql = "UPDATE attendance_sessions SET 
                    total_present = (SELECT COUNT(*) FROM student_attendance 
                                    WHERE session_id = ? AND status_id = (SELECT id FROM attendance_statuses WHERE category = 'present' AND is_active = 1 LIMIT 1)),
                    total_absent = (SELECT COUNT(*) FROM student_attendance 
                                    WHERE session_id = ? AND status_id = (SELECT id FROM attendance_statuses WHERE category = 'absent' AND is_active = 1 LIMIT 1)),
                    total_late = (SELECT COUNT(*) FROM student_attendance 
                                    WHERE session_id = ? AND status_id = (SELECT id FROM attendance_statuses WHERE category = 'late' AND is_active = 1 LIMIT 1)),
                    total_excused = (SELECT COUNT(*) FROM student_attendance 
                                    WHERE session_id = ? AND status_id = (SELECT id FROM attendance_statuses WHERE category = 'excused' AND is_active = 1 LIMIT 1)),
                    is_completed = 1,
                    completed_date = NOW()
                    WHERE id = ?";

            $this->db->query($sql, [$sessionId, $sessionId, $sessionId, $sessionId, $sessionId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Complete a session (lock it)
     */
    public function completeSession(int $sessionId, int $lockedBy): bool
    {
        try {
            $sql = "UPDATE attendance_sessions SET 
                    is_completed = 1,
                    is_locked = 1,
                    locked_date = NOW(),
                    locked_by = ?,
                    completed_date = NOW()
                    WHERE id = ?";

            $this->db->query($sql, [$lockedBy, $sessionId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // ATTENDANCE STATUSES
    // ================================================================

    /**
     * Get all attendance statuses
     */
    public function getStatuses(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM attendance_statuses 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY sort_order";

            return $this->db->fetchAll($sql, [$schoolId]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get status ID from status code
     */
    public function getStatusId(string $statusCode): ?int
    {
        try {
            $sql = "SELECT id FROM attendance_statuses 
                    WHERE status_code = ? AND is_active = 1 
                    LIMIT 1";

            $result = $this->db->fetchOne($sql, [$statusCode]);

            if ($result) {
                return $result['id'];
            }

            // Default to present
            $result = $this->db->fetchOne(
                "SELECT id FROM attendance_statuses WHERE category = 'present' AND is_active = 1 LIMIT 1"
            );
            return $result['id'] ?? null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create attendance status
     */
    public function createStatus(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_statuses (
                uuid, school_id, status_name, status_code, category,
                is_present, is_absent, is_excused, is_late,
                color, icon, sort_order, requires_reason,
                requires_approval, notification_required, is_system, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, 1
            )";

            $params = [
                $data['school_id'] ?? 1,
                $data['status_name'],
                $data['status_code'],
                $data['category'] ?? 'unknown',
                $data['is_present'] ?? 0,
                $data['is_absent'] ?? 0,
                $data['is_excused'] ?? 0,
                $data['is_late'] ?? 0,
                $data['color'] ?? '#6c757d',
                $data['icon'] ?? null,
                $data['sort_order'] ?? 0,
                $data['requires_reason'] ?? 0,
                $data['requires_approval'] ?? 0,
                $data['notification_required'] ?? 0,
                $data['is_system'] ?? 0
            ];

            $this->db->query($sql, $params);
            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create status: " . $e->getMessage());
        }
    }

    // ================================================================
    // ATTENDANCE DEVICES
    // ================================================================

    /**
     * Get all devices
     */
    public function getDevices(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT d.*, dt.type_name, dt.category
                    FROM attendance_devices d
                    LEFT JOIN attendance_device_types dt ON d.device_type_id = dt.id
                    WHERE d.school_id = ? AND d.is_active = 1 
                    ORDER BY d.device_name";

            return $this->db->fetchAll($sql, [$schoolId]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Register a device
     */
    public function registerDevice(array $data): int
    {
        try {
            $apiKey = 'dev_' . bin2hex(random_bytes(32));
            $apiSecret = 'sec_' . bin2hex(random_bytes(32));

            $sql = "INSERT INTO attendance_devices (
                uuid, school_id, device_type_id, device_name, device_serial,
                device_code, location, ip_address, port_number,
                api_key, api_secret, status, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 'offline', 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['device_type_id'],
                $data['device_name'],
                $data['device_serial'],
                $data['device_code'] ?? $data['device_serial'],
                $data['location'] ?? null,
                $data['ip_address'] ?? null,
                $data['port_number'] ?? null,
                $apiKey,
                $apiSecret
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to register device: " . $e->getMessage());
        }
    }

    /**
     * Update device status
     */
    public function updateDeviceStatus(int $deviceId, string $status): bool
    {
        try {
            $sql = "UPDATE attendance_devices 
                    SET status = ?, last_connected = NOW() 
                    WHERE id = ? AND is_active = 1";

            $this->db->query($sql, [$status, $deviceId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Authenticate device by API keys
     */
    public function authenticateDevice(string $apiKey, string $apiSecret): ?array
    {
        try {
            $sql = "SELECT id, device_name, status FROM attendance_devices 
                    WHERE api_key = ? AND api_secret = ? AND is_active = 1 
                    LIMIT 1";

            $device = $this->db->fetchOne($sql, [$apiKey, $apiSecret]);

            if ($device) {
                // Update last connected
                $this->updateDeviceStatus($device['id'], 'online');
            }

            return $device;

        } catch (Exception $e) {
            return null;
        }
    }

    // ================================================================
    // ATTENDANCE LOCATIONS
    // ================================================================

    /**
     * Get all locations
     */
    public function getLocations(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM attendance_locations 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY location_name";

            return $this->db->fetchAll($sql, [$schoolId]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Create location
     */
    public function createLocation(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_locations (
                uuid, school_id, campus_id, location_name, location_code,
                location_type, latitude, longitude, radius_meters, address, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['campus_id'] ?? null,
                $data['location_name'],
                $data['location_code'],
                $data['location_type'] ?? 'classroom',
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['radius_meters'] ?? 50,
                $data['address'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create location: " . $e->getMessage());
        }
    }

    // ================================================================
    // ATTENDANCE RULES
    // ================================================================

    /**
     * Get all rules
     */
    public function getRules(int $schoolId = 1): array
    {
        try {
            $sql = "SELECT * FROM attendance_rules 
                    WHERE school_id = ? AND is_active = 1 
                    ORDER BY rule_name";

            return $this->db->fetchAll($sql, [$schoolId]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get rules for a grade level
     */
    public function getRulesForGrade(int $gradeLevelId, int $schoolId = 1): ?array
    {
        try {
            $sql = "SELECT * FROM attendance_rules 
                    WHERE school_id = ? 
                    AND (grade_level_id = ? OR grade_level_id IS NULL) 
                    AND is_active = 1 
                    ORDER BY grade_level_id DESC 
                    LIMIT 1";

            return $this->db->fetchOne($sql, [$schoolId, $gradeLevelId]);

        } catch (Exception $e) {
            return null;
        }
    }

    // ================================================================
    // ATTENDANCE SUMMARY
    // ================================================================

    /**
     * Get student summary
     */
    public function getStudentSummary(int $studentId, int $termId): ?array
    {
        try {
            $sql = "SELECT * FROM attendance_summary 
                    WHERE student_id = ? AND term_id = ? AND is_active = 1";

            return $this->db->fetchOne($sql, [$studentId, $termId]);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculate and save student summary
     */
    public function calculateSummary(int $studentId, int $termId): bool
    {
        try {
            // Delete existing summary
            $this->db->query(
                "DELETE FROM attendance_summary WHERE student_id = ? AND term_id = ?",
                [$studentId, $termId]
            );

            // Get year ID
            $year = $this->db->fetchOne("SELECT academic_year_id FROM academic_terms WHERE id = ?", [$termId]);
            $yearId = $year['academic_year_id'] ?? 1;

            // Get class
            $class = $this->db->fetchOne("
                SELECT class_section_id FROM student_enrollments 
                WHERE student_id = ? AND is_active = 1 
                ORDER BY created_at DESC LIMIT 1
            ", [$studentId]);

            $classId = $class['class_section_id'] ?? null;

            // Calculate summary
            $sql = "INSERT INTO attendance_summary (
                uuid, school_id, student_id, academic_year_id, term_id, class_section_id,
                total_sessions, present_days, absent_days, late_days, excused_days,
                attendance_percentage, is_calculated, calculated_date, is_active
            )
            SELECT 
                UUID(), 1, ?, ?, ?, ?,
                COUNT(DISTINCT ases.id),
                SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END),
                SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END),
                SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END),
                SUM(CASE WHEN astatus.is_excused = 1 THEN 1 ELSE 0 END),
                ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2),
                1, NOW(), 1
            FROM student_attendance sa
            JOIN attendance_sessions ases ON sa.session_id = ases.id
            JOIN attendance_statuses astatus ON sa.status_id = astatus.id
            WHERE sa.student_id = ?
            AND ases.academic_term_id = ?
            AND sa.is_active = 1";

            $this->db->query($sql, [$studentId, $yearId, $termId, $classId, $studentId, $termId]);

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // ATTENDANCE NOTIFICATIONS
    // ================================================================

    /**
     * Create notification
     */
    public function createNotification(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_notifications (
                uuid, school_id, campus_id, student_id, parent_id,
                notification_type, channel, subject, message,
                reference_type, reference_id, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['campus_id'] ?? null,
                $data['student_id'] ?? null,
                $data['parent_id'] ?? null,
                $data['notification_type'] ?? 'absence',
                $data['channel'] ?? 'sms',
                $data['subject'],
                $data['message'],
                $data['reference_type'] ?? null,
                $data['reference_id'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create notification: " . $e->getMessage());
        }
    }

    /**
     * Get notifications for student
     */
    public function getStudentNotifications(int $studentId, int $limit = 50): array
    {
        try {
            $sql = "SELECT * FROM attendance_notifications 
                    WHERE student_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?";

            return $this->db->fetchAll($sql, [$studentId, $limit]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationRead(int $notificationId): bool
    {
        try {
            $sql = "UPDATE attendance_notifications 
                    SET is_read = 1, read_date = NOW() 
                    WHERE id = ?";

            $this->db->query($sql, [$notificationId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // ATTENDANCE RISK PROFILES
    // ================================================================

    /**
     * Get students at risk
     */
    public function getAtRiskStudents(int $termId, float $threshold = 75, int $limit = 50): array
    {
        try {
            $sql = "SELECT 
                    s.id, s.first_name, s.last_name, s.admission_number,
                    gl.level_name, cs.section_name,
                    COUNT(DISTINCT ases.id) AS total_sessions,
                    SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS absences,
                    SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS lates,
                    ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS percentage,
                    CASE 
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 80 THEN 'Low'
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 60 THEN 'Moderate'
                        WHEN ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) >= 40 THEN 'High'
                        ELSE 'Critical'
                    END AS risk_level
                    FROM students s
                    JOIN student_attendance sa ON s.id = sa.student_id AND sa.is_active = 1
                    JOIN attendance_sessions ases ON sa.session_id = ases.id
                    JOIN class_sections cs ON ases.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE ases.academic_term_id = ?
                    AND s.is_active = 1
                    GROUP BY s.id
                    HAVING percentage < ?
                    ORDER BY percentage ASC
                    LIMIT ?";

            return $this->db->fetchAll($sql, [$termId, $threshold, $limit]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Save risk profile
     */
    public function saveRiskProfile(array $data): int
    {
        try {
            // Check if exists
            $existing = $this->db->fetchOne("
                SELECT id FROM attendance_risk_profiles 
                WHERE student_id = ? AND term_id = ? AND is_active = 1
            ", [$data['student_id'], $data['term_id']]);

            if ($existing) {
                $sql = "UPDATE attendance_risk_profiles SET 
                        risk_level = ?, risk_score = ?, attendance_percentage = ?,
                        total_absences = ?, total_lates = ?, consecutive_absences = ?,
                        declining_trend = ?, intervention_required = ?,
                        recommendation = ?, updated_at = NOW()
                        WHERE id = ?";

                $this->db->query($sql, [
                    $data['risk_level'],
                    $data['risk_score'] ?? 0,
                    $data['attendance_percentage'] ?? 0,
                    $data['total_absences'] ?? 0,
                    $data['total_lates'] ?? 0,
                    $data['consecutive_absences'] ?? 0,
                    $data['declining_trend'] ?? 0,
                    $data['intervention_required'] ?? 0,
                    $data['recommendation'] ?? null,
                    $existing['id']
                ]);

                return $existing['id'];
            }

            $sql = "INSERT INTO attendance_risk_profiles (
                uuid, school_id, student_id, academic_year_id, term_id,
                risk_level, risk_score, attendance_percentage,
                total_absences, total_lates, consecutive_absences,
                declining_trend, intervention_required, recommendation, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['student_id'],
                $data['academic_year_id'] ?? 1,
                $data['term_id'],
                $data['risk_level'],
                $data['risk_score'] ?? 0,
                $data['attendance_percentage'] ?? 0,
                $data['total_absences'] ?? 0,
                $data['total_lates'] ?? 0,
                $data['consecutive_absences'] ?? 0,
                $data['declining_trend'] ?? 0,
                $data['intervention_required'] ?? 0,
                $data['recommendation'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to save risk profile: " . $e->getMessage());
        }
    }

    // ================================================================
    // BIOMETRIC TEMPLATES
    // ================================================================

    /**
     * Save biometric template
     */
    public function saveBiometricTemplate(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_biometric_templates (
                uuid, school_id, student_id, staff_id, device_type_id,
                biometric_type, biometric_template, template_hash,
                finger_position, template_format, template_version,
                quality_score, enrollment_date, expiry_date,
                is_primary, is_active, enrolled_by, notes
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, 1, ?, ?
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['student_id'] ?? null,
                $data['staff_id'] ?? null,
                $data['device_type_id'],
                $data['biometric_type'] ?? 'fingerprint',
                $data['biometric_template'],
                $data['template_hash'],
                $data['finger_position'] ?? null,
                $data['template_format'] ?? null,
                $data['template_version'] ?? null,
                $data['quality_score'] ?? null,
                $data['enrollment_date'] ?? date('Y-m-d H:i:s'),
                $data['expiry_date'] ?? null,
                $data['is_primary'] ?? 0,
                $data['enrolled_by'] ?? null,
                $data['notes'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to save biometric template: " . $e->getMessage());
        }
    }

    /**
     * Get student biometric templates
     */
    public function getStudentBiometricTemplates(int $studentId): array
    {
        try {
            $sql = "SELECT * FROM attendance_biometric_templates 
                    WHERE student_id = ? AND is_active = 1 
                    ORDER BY is_primary DESC, created_at DESC";

            return $this->db->fetchAll($sql, [$studentId]);

        } catch (Exception $e) {
            return [];
        }
    }

    // ================================================================
    // RFID CARDS
    // ================================================================

    /**
     * Register RFID card
     */
    public function registerRfidCard(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_rfid_cards (
                uuid, school_id, card_number, uid,
                student_id, staff_id, card_type,
                issued_date, expiry_date, issued_by, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['card_number'],
                $data['uid'],
                $data['student_id'] ?? null,
                $data['staff_id'] ?? null,
                $data['card_type'] ?? 'student',
                $data['issued_date'] ?? date('Y-m-d'),
                $data['expiry_date'] ?? null,
                $data['issued_by'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to register RFID card: " . $e->getMessage());
        }
    }

    /**
     * Get RFID card by UID
     */
    public function getRfidCardByUid(string $uid): ?array
    {
        try {
            $sql = "SELECT * FROM attendance_rfid_cards 
                    WHERE uid = ? AND is_active = 1 AND is_blocked = 0 
                    LIMIT 1";

            return $this->db->fetchOne($sql, [$uid]);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Block RFID card
     */
    public function blockRfidCard(int $cardId): bool
    {
        try {
            $sql = "UPDATE attendance_rfid_cards 
                    SET is_blocked = 1, updated_at = NOW() 
                    WHERE id = ?";

            $this->db->query($sql, [$cardId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // QR CODES
    // ================================================================

    /**
     * Generate QR code
     */
    public function generateQrCode(array $data): int
    {
        try {
            $qrCode = 'QR_' . bin2hex(random_bytes(16)) . '_' . time();

            $sql = "INSERT INTO attendance_qr_codes (
                uuid, school_id, student_id, staff_id, session_id,
                qr_code, qr_content, purpose, expires_at, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?, ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['student_id'] ?? null,
                $data['staff_id'] ?? null,
                $data['session_id'] ?? null,
                $qrCode,
                $data['qr_content'],
                $data['purpose'] ?? 'student_checkin',
                $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'))
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to generate QR code: " . $e->getMessage());
        }
    }

    /**
     * Validate QR code
     */
    public function validateQrCode(string $qrCode): ?array
    {
        try {
            $sql = "SELECT * FROM attendance_qr_codes 
                    WHERE qr_code = ? AND is_active = 1 AND is_used = 0 
                    AND expires_at > NOW() 
                    LIMIT 1";

            return $this->db->fetchOne($sql, [$qrCode]);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Mark QR code as used
     */
    public function markQrCodeUsed(int $qrId): bool
    {
        try {
            $sql = "UPDATE attendance_qr_codes 
                    SET is_used = 1, used_at = NOW() 
                    WHERE id = ?";

            $this->db->query($sql, [$qrId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // ATTENDANCE EXCEPTIONS
    // ================================================================

    /**
     * Create attendance exception
     */
    public function createException(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_exceptions (
                uuid, school_id, campus_id, student_id, staff_id,
                exception_date, start_time, end_time,
                exception_type, reason, supporting_document,
                status, notes, is_active
            ) VALUES (
                UUID(), ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                'pending', ?, 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['campus_id'] ?? null,
                $data['student_id'] ?? null,
                $data['staff_id'] ?? null,
                $data['exception_date'],
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
                $data['exception_type'] ?? 'other',
                $data['reason'],
                $data['supporting_document'] ?? null,
                $data['notes'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to create exception: " . $e->getMessage());
        }
    }

    /**
     * Approve exception
     */
    public function approveException(int $exceptionId, int $approvedBy): bool
    {
        try {
            $sql = "UPDATE attendance_exceptions 
                    SET status = 'approved', 
                        approved_by = ?, 
                        approved_date = NOW() 
                    WHERE id = ?";

            $this->db->query($sql, [$approvedBy, $exceptionId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Reject exception
     */
    public function rejectException(int $exceptionId, int $rejectedBy, string $reason): bool
    {
        try {
            $sql = "UPDATE attendance_exceptions 
                    SET status = 'rejected', 
                        rejected_by = ?, 
                        rejected_date = NOW(),
                        rejection_reason = ?
                    WHERE id = ?";

            $this->db->query($sql, [$rejectedBy, $reason, $exceptionId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // DEVICE LOGS
    // ================================================================

    /**
     * Log device activity
     */
    public function logDeviceActivity(int $deviceId, string $identifier, string $logType, array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_device_logs (
                uuid, school_id, device_id, log_timestamp, log_type,
                identifier, raw_data, processed_data, match_status, is_active
            ) VALUES (
                UUID(), ?, ?, NOW(), ?,
                ?, ?, ?, 'pending', 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $deviceId,
                $logType,
                $identifier,
                $data['raw_data'] ?? null,
                $data['processed_data'] ?? null
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to log device activity: " . $e->getMessage());
        }
    }

    /**
     * Match device log to student
     */
    public function matchDeviceLog(int $logId, int $studentId, float $confidence): bool
    {
        try {
            $sql = "UPDATE attendance_device_logs 
                    SET match_status = 'matched',
                        matched_student_id = ?,
                        confidence_score = ?,
                        processed = 1,
                        processed_date = NOW()
                    WHERE id = ?";

            $this->db->query($sql, [$studentId, $confidence, $logId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // SYNC QUEUE
    // ================================================================

    /**
     * Add to sync queue
     */
    public function addToSyncQueue(array $data): int
    {
        try {
            $sql = "INSERT INTO attendance_sync_queue (
                uuid, school_id, sync_type, sync_operation,
                entity_id, entity_data, sync_priority, status, is_active
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?, 'pending', 1
            )";

            $this->db->query($sql, [
                $data['school_id'] ?? 1,
                $data['sync_type'],
                $data['sync_operation'],
                $data['entity_id'],
                json_encode($data['entity_data']),
                $data['sync_priority'] ?? 0
            ]);

            return $this->db->lastInsertId();

        } catch (Exception $e) {
            throw new Exception("Failed to add to sync queue: " . $e->getMessage());
        }
    }

    /**
     * Get pending sync items
     */
    public function getPendingSyncItems(int $limit = 100): array
    {
        try {
            $sql = "SELECT * FROM attendance_sync_queue 
                    WHERE status = 'pending' 
                    ORDER BY sync_priority DESC, created_at ASC 
                    LIMIT ?";

            return $this->db->fetchAll($sql, [$limit]);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Update sync status
     */
    public function updateSyncStatus(int $syncId, string $status, ?string $error = null): bool
    {
        try {
            $sql = "UPDATE attendance_sync_queue 
                    SET status = ?, 
                        error_message = ?,
                        processed_date = NOW(),
                        retry_count = retry_count + 1
                    WHERE id = ?";

            $this->db->query($sql, [$status, $error, $syncId]);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    // ================================================================
    // STATISTICS
    // ================================================================

    /**
     * Get attendance statistics
     */
    public function getStatistics(array $filters): array
    {
        try {
            $sql = "SELECT 
                    COUNT(DISTINCT s.id) AS total_students,
                    COUNT(DISTINCT ases.id) AS total_sessions,
                    SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) AS total_present,
                    SUM(CASE WHEN astatus.is_absent = 1 THEN 1 ELSE 0 END) AS total_absent,
                    SUM(CASE WHEN astatus.is_late = 1 THEN 1 ELSE 0 END) AS total_late,
                    SUM(CASE WHEN astatus.is_excused = 1 THEN 1 ELSE 0 END) AS total_excused,
                    ROUND(SUM(CASE WHEN astatus.is_present = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT ases.id), 0) * 100, 2) AS overall_percentage
                    FROM students s
                    JOIN student_attendance sa ON s.id = sa.student_id AND sa.is_active = 1
                    JOIN attendance_sessions ases ON sa.session_id = ases.id
                    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE s.is_active = 1";

            $params = [];

            if (!empty($filters['term_id'])) {
                $sql .= " AND ases.academic_term_id = ?";
                $params[] = $filters['term_id'];
            }

            if (!empty($filters['class_id'])) {
                $sql .= " AND ases.class_section_id = ?";
                $params[] = $filters['class_id'];
            }

            if (!empty($filters['start_date'])) {
                $sql .= " AND ases.session_date >= ?";
                $params[] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $sql .= " AND ases.session_date <= ?";
                $params[] = $filters['end_date'];
            }

            $result = $this->db->fetchOne($sql, $params);
            return $result ?? [];

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get today's attendance summary
     */
    public function getTodaySummary(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(DISTINCT se.student_id) AS total_expected,
                        COUNT(DISTINCT sa.id) AS total_present
                    FROM student_enrollments se
                    LEFT JOIN attendance_sessions ases ON ases.class_section_id = se.class_section_id AND ases.session_date = CURDATE()
                    LEFT JOIN student_attendance sa ON sa.session_id = ases.id
                    WHERE se.is_active = 1 AND se.enrollment_status = 'Active'";

            return $this->db->fetchOne($sql) ?? ['total_expected' => 0, 'total_present' => 0];

        } catch (Exception $e) {
            return ['total_expected' => 0, 'total_present' => 0];
        }
    }

    /**
     * Get recent attendance records
     */
    public function getRecentAttendance(int $limit = 50): array
    {
        try {
            $sql = "SELECT sa.*, 
                           s.first_name, s.last_name, s.admission_number,
                           astatus.status_name,
                           ases.session_date, ases.session_type,
                           cs.section_name, gl.level_name
                    FROM student_attendance sa
                    JOIN students s ON sa.student_id = s.id
                    JOIN attendance_sessions ases ON sa.session_id = ases.id
                    JOIN class_sections cs ON ases.class_section_id = cs.id
                    JOIN grade_levels gl ON cs.grade_level_id = gl.id
                    JOIN attendance_statuses astatus ON sa.status_id = astatus.id
                    WHERE sa.school_id = 1 AND sa.is_active = 1
                    ORDER BY sa.created_at DESC
                    LIMIT ?";

            return $this->db->fetchAll($sql, [$limit]);

        } catch (Exception $e) {
            return [];
        }
    }
}