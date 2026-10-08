<?php
/**
 * profile.php
 * 
 * Mobile Profile Handler
 * Manages user profile for mobile app
 * 
 * @package EduTrack
 * @subpackage API
 */

function handleMobileProfile($params, $method, $action)
{
    // Authenticate user
    $user = MobileAuth::authenticate();

    // Rate limit
    MobileAuth::rateLimit('mobile_profile');

    $db = DatabaseHelper::getInstance();

    switch ($method) {
        case 'GET':
            handleGetProfile($params, $db, $user);
            break;

        case 'PUT':
            handleUpdateProfile($params, $db, $user);
            break;

        default:
            MobileApiResponse::methodNotAllowed('Use GET or PUT');
    }
}

function handleGetProfile($params, $db, $user)
{
    // Get user details
    $userData = $db->fetchOne("
        SELECT id, username, email, user_type, is_active
        FROM users WHERE id = ?
    ", [$user['id']]);

    if (!$userData) {
        MobileApiResponse::notFound('User not found');
    }

    // Get student details if user is a student
    $studentData = null;
    if ($user['user_type'] === 'student') {
        $studentData = $db->fetchOne("
            SELECT s.id, s.first_name, s.last_name, s.admission_number, s.gender,
                   s.date_of_birth, s.phone, s.email, s.profile_photo,
                   gl.level_name, cs.section_name,
                   ay.year_name, at.term_name
            FROM students s
            LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
            LEFT JOIN class_sections cs ON se.class_section_id = cs.id
            LEFT JOIN grade_levels gl ON cs.grade_level_id = gl.id
            LEFT JOIN academic_years ay ON se.academic_year_id = ay.id
            LEFT JOIN academic_terms at ON se.academic_term_id = at.id
            WHERE s.user_id = ? AND s.is_active = 1
        ", [$user['id']]);
    }

    // Get staff details if user is staff
    $staffData = null;
    if ($user['user_type'] === 'staff' || $user['user_type'] === 'teacher') {
        $staffData = $db->fetchOne("
            SELECT st.id, p.first_name, p.last_name, st.staff_number,
                   st.designation, st.department, st.phone, st.email,
                   p.profile_photo
            FROM staff st
            JOIN people p ON st.person_id = p.id
            WHERE st.user_id = ? AND st.is_active = 1
        ", [$user['id']]);
    }

    // Get device count
    $deviceCount = $db->fetchOne("
        SELECT COUNT(*) as count FROM devices WHERE user_id = ? AND is_active = 1
    ", [$user['id']]);

    MobileApiResponse::success([
        'user' => $userData,
        'student' => $studentData,
        'staff' => $staffData,
        'devices' => [
            'count' => (int)($deviceCount['count'] ?? 0)
        ]
    ]);
}

function handleUpdateProfile($params, $db, $user)
{
    $updateData = [];
    $updateFields = [];

    // Allowed fields to update
    $allowedFields = ['email', 'phone', 'profile_photo'];

    foreach ($allowedFields as $field) {
        if (isset($params[$field])) {
            $updateFields[] = "$field = ?";
            $updateData[] = $params[$field];
        }
    }

    if (empty($updateFields)) {
        MobileApiResponse::validationError(['message' => 'No fields to update']);
    }

    // Update user
    $updateData[] = $user['id'];
    $db->query("
        UPDATE users 
        SET " . implode(', ', $updateFields) . " 
        WHERE id = ?
    ", $updateData);

    // Update student if applicable
    if ($user['user_type'] === 'student') {
        $studentFields = [];
        $studentData = [];

        if (isset($params['phone'])) {
            $studentFields[] = "phone = ?";
            $studentData[] = $params['phone'];
        }

        if (isset($params['profile_photo'])) {
            $studentFields[] = "profile_photo = ?";
            $studentData[] = $params['profile_photo'];
        }

        if (!empty($studentFields)) {
            $studentData[] = $user['id'];
            $db->query("
                UPDATE students 
                SET " . implode(', ', $studentFields) . " 
                WHERE user_id = ?
            ", $studentData);
        }
    }

    MobileApiResponse::success([], 'Profile updated successfully');
}