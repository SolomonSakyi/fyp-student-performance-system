<?php

/**
 * Resend OTP - AJAX endpoint for resending OTP codes
 *
 * @package EduTrack
 * @subpackage Platform\Auth
 * @version 1.0
 * @filepath public/platform/auth/resend-otp.php
 *
 * v1.0 change (2026-10-07) [SWEEP]:
 *   Platform-auth sweep. The file carried no @version tag and no
 *   @subpackage tag. This sweep adds both, in the shape every other
 *   file on the record uses, and adds this v1.0 [SWEEP] docblock
 *   paragraph above the existing description. No code changed.
 *
 *   The file is a JSON-only AJAX endpoint. It emits no HTML, has no
 *   $pageTitle, has no visible brand string, and carries no sidebar
 *   partial, no .nav-subgroup-label CSS rule, and no $currentPage.
 *   The sweep doctrine's other four changes do not apply because
 *   there is nothing in this file for them to operate on. Every
 *   line of code is byte-identical to the version that was on disk
 *   before this sweep.
 */

// ============================================================
// HEADERS
// ============================================================
header('Content-Type: application/json');

// ============================================================
// LOAD HELPERS
// ============================================================
require_once dirname(__DIR__, 3) . '/app/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 3) . '/app/helpers/SessionHelper.php';
require_once dirname(__DIR__, 3) . '/app/services/OTPService.php';

// ============================================================
// HANDLE REQUEST
// ============================================================
SessionHelper::start();

$response = [
    'success' => false,
    'message' => 'Invalid request.'
];

$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($input['email'] ?? '');
    $purpose = $input['purpose'] ?? 'password_reset';

    if (empty($email)) {
        $response['message'] = 'Email is required.';
    } else {
        try {
            $db = DatabaseHelper::getInstance();

            // Find user
            $user = $db->fetchOne(
                "SELECT id, email, first_name FROM platform_users WHERE email = ? AND deleted_at IS NULL",
                [$email]
            );

            if (!$user) {
                $response['message'] = 'User not found.';
            } else {
                // Resend OTP
                $result = OTPService::resend($user['id'], $purpose);

                if ($result['success']) {
                    $response['success'] = true;
                    $response['message'] = 'OTP sent successfully to ' . htmlspecialchars($email);
                } else {
                    $response['message'] = $result['message'];
                }
            }
        } catch (Exception $e) {
            error_log('Resend OTP error: ' . $e->getMessage());
            $response['message'] = 'An error occurred. Please try again.';
        }
    }
}

echo json_encode($response);
exit;
