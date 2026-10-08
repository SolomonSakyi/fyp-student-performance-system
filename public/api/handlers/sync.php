<?php
/**
 * sync.php
 * 
 * Offline Sync Handler
 * Synchronizes offline attendance data from mobile devices
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleSync($params, $method, $action)
{
    if ($method !== 'POST') {
        ApiResponse::methodNotAllowed('Use POST method');
    }

    ApiAuth::rateLimit('sync');

    // Authenticate user
    try {
        $user = ApiAuth::authenticateUser();
    } catch (Exception $e) {
        ApiResponse::unauthorized('Authentication required');
    }

    $offlineData = $params['attendance_data'] ?? [];
    $deviceId = $params['device_id'] ?? null;
    $lastSync = $params['last_sync'] ?? null;

    if (empty($offlineData)) {
        ApiResponse::validationError(['attendance_data' => 'No data to sync']);
    }

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    $synced = 0;
    $failed = 0;
    $errors = [];
    $conflicts = [];

    foreach ($offlineData as $record) {
        try {
            // Check for conflicts
            if (!empty($record['sync_token'])) {
                $existing = $db->fetchOne("
                    SELECT id, sync_token, updated_at 
                    FROM student_attendance 
                    WHERE student_id = ? AND session_id = ? AND is_active = 1
                ", [$record['student_id'], $record['session_id']]);

                if ($existing && $existing['sync_token'] !== $record['sync_token']) {
                    // Conflict detected
                    $conflicts[] = [
                        'record_id' => $record['id'] ?? 0,
                        'student_id' => $record['student_id'],
                        'local' => $record,
                        'server' => $existing
                    ];
                    continue;
                }
            }

            // Process the attendance record
            $result = $service->markStudentAttendance([
                'student_id' => $record['student_id'],
                'class_id' => $record['class_id'] ?? 1,
                'date' => $record['date'] ?? date('Y-m-d'),
                'session_type' => $record['session_type'] ?? 'morning',
                'status' => $record['status'] ?? 'P',
                'method_id' => $record['method_id'] ?? 8,
                'device_id' => $record['device_id'] ?? $deviceId,
                'location_id' => $record['location_id'] ?? null,
                'check_in_time' => $record['check_in_time'] ?? date('Y-m-d H:i:s'),
                'check_in_latitude' => $record['latitude'] ?? null,
                'check_in_longitude' => $record['longitude'] ?? null,
                'is_late' => $record['is_late'] ?? 0,
                'late_minutes' => $record['late_minutes'] ?? null,
                'is_excused' => $record['is_excused'] ?? 0,
                'excused_reason' => $record['excused_reason'] ?? null,
                'notes' => $record['notes'] ?? null,
                'is_synced' => 1
            ]);

            if ($result['success']) {
                $synced++;
                
                // Update sync token if provided
                if (!empty($record['sync_token'])) {
                    $db->query("
                        UPDATE student_attendance 
                        SET sync_token = ?, sync_date = NOW() 
                        WHERE id = ?
                    ", [$record['sync_token'], $result['attendance_id']]);
                }
            } else {
                $failed++;
                $errors[] = $result['message'];
            }

        } catch (Exception $e) {
            $failed++;
            $errors[] = $e->getMessage();
        }
    }

    // Update device last sync
    if ($deviceId) {
        $db->query("
            UPDATE attendance_devices 
            SET last_sync = NOW() 
            WHERE id = ?
        ", [$deviceId]);
    }

    ApiResponse::success([
        'synced' => $synced,
        'failed' => $failed,
        'conflicts' => count($conflicts),
        'conflict_data' => $conflicts,
        'errors' => $errors,
        'total' => count($offlineData),
        'last_sync' => date('Y-m-d H:i:s')
    ], 'Sync completed');
}