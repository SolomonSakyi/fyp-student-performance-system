<?php
/**
 * checkin.php
 * 
 * Mobile Check-in Handler
 * Mobile-optimized check-in with GPS and photo support
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleMobileCheckin($params, $method, $action)
{
    if ($method !== 'POST') {
        MobileApiResponse::methodNotAllowed('Use POST method');
    }

    // Authenticate user
    $user = MobileAuth::authenticate();

    // Rate limit
    MobileAuth::rateLimit('mobile_checkin');

    $db = DatabaseHelper::getInstance();
    $service = new AttendanceService();

    // Validate required fields
    $required = ['student_id', 'class_id'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            MobileApiResponse::validationError([$field => 'Field is required']);
        }
    }

    // Get device info
    $deviceId = $params['device_id'] ?? null;
    $deviceName = $params['device_name'] ?? 'Mobile App';
    $deviceType = $params['device_type'] ?? 'android';
    $appVersion = $params['app_version'] ?? '1.0.0';

    // Get GPS data
    $latitude = $params['latitude'] ?? null;
    $longitude = $params['longitude'] ?? null;
    $accuracy = $params['accuracy'] ?? null;

    // Get photo (base64 encoded)
    $photo = $params['photo'] ?? null;

    // Validate GPS if required
    $gpsRequired = $params['gps_required'] ?? false;
    if ($gpsRequired && (!$latitude || !$longitude)) {
        MobileApiResponse::validationError(['location' => 'GPS location is required']);
    }

    // Check if student is within geofence (if enabled)
    if ($latitude && $longitude) {
        $geofence = $db->fetchOne("
            SELECT g.* FROM attendance_geofences g
            JOIN attendance_locations l ON g.location_id = l.id
            JOIN class_sections cs ON l.id = cs.location_id
            WHERE cs.id = ? AND g.is_active = 1
            LIMIT 1
        ", [$params['class_id']]);

        if ($geofence) {
            $distance = calculateDistance(
                $latitude, $longitude,
                $geofence['center_lat'], $geofence['center_lng']
            );
            
            if ($distance > $geofence['radius_meters']) {
                MobileApiResponse::error(
                    'You are outside the allowed check-in area. Distance: ' . round($distance) . 'm',
                    403
                );
            }
        }
    }

    // Mark attendance
    $result = $service->markStudentAttendance([
        'student_id' => $params['student_id'],
        'class_id' => $params['class_id'],
        'date' => $params['date'] ?? date('Y-m-d'),
        'session_type' => $params['session_type'] ?? 'morning',
        'status' => $params['status'] ?? 'P',
        'method_id' => 8, // Mobile app
        'device_id' => $deviceId,
        'location_id' => $params['location_id'] ?? null,
        'check_in_time' => $params['check_in_time'] ?? date('Y-m-d H:i:s'),
        'check_in_latitude' => $latitude,
        'check_in_longitude' => $longitude,
        'is_late' => $params['is_late'] ?? 0,
        'late_minutes' => $params['late_minutes'] ?? null,
        'is_excused' => $params['is_excused'] ?? 0,
        'excused_reason' => $params['excused_reason'] ?? null,
        'notes' => $params['notes'] ?? null,
        'is_synced' => 1
    ]);

    if ($result['success']) {
        // Log device info
        if ($deviceId) {
            $db->query("
                UPDATE attendance_devices 
                SET last_connected = NOW(), status = 'online' 
                WHERE id = ?
            ", [$deviceId]);
        }

        // Send push notification if enabled
        if ($params['send_notification'] ?? true) {
            sendAttendanceNotification($params['student_id'], $result, $params);
        }

        MobileApiResponse::success([
            'attendance_id' => $result['attendance_id'],
            'session_id' => $result['session_id'],
            'student_id' => $params['student_id'],
            'check_in_time' => date('Y-m-d H:i:s'),
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy
            ],
            'device' => [
                'name' => $deviceName,
                'type' => $deviceType,
                'app_version' => $appVersion
            ]
        ], $result['message']);
    } else {
        MobileApiResponse::error($result['message']);
    }
}

/**
 * Calculate distance between two coordinates
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371000; // meters
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distance = $earthRadius * $c;
    
    return $distance;
}

/**
 * Send attendance notification
 */
function sendAttendanceNotification($studentId, $result, $params)
{
    try {
        $db = DatabaseHelper::getInstance();
        $notificationService = new NotificationService();
        
        // Get student info
        $student = $db->fetchOne("
            SELECT first_name, last_name, admission_number
            FROM students WHERE id = ? AND is_active = 1
        ", [$studentId]);
        
        if (!$student) {
            return false;
        }
        
        $statusMap = [
            'P' => 'Present',
            'A' => 'Absent',
            'L' => 'Late',
            'E' => 'Excused'
        ];
        
        $status = $params['status'] ?? 'P';
        $statusName = $statusMap[$status] ?? 'Unknown';
        
        $notificationService->sendPushNotification(
            $studentId,
            'Attendance Marked',
            $student['first_name'] . ' ' . $student['last_name'] . ' was marked as ' . $statusName,
            [
                'type' => 'attendance',
                'attendance_id' => $result['attendance_id'],
                'student_id' => $studentId,
                'status' => $status,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        return true;
        
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log('Failed to send notification: ' . $e->getMessage());
        return false;
    }
}