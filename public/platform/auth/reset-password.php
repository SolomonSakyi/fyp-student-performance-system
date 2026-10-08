<?php

/**
 * Reset Password
 * Handles password reset with token validation
 *
 * @package EduTrack
 * @subpackage Platform\Auth
 * @version 1.1
 * @filepath public/platform/auth/reset-password.php
 *
 * v1.1 change (2026-10-07) [SWEEP X-1]:
 *   Platform-auth sweep, X-1 in full. Two visible "EduTrack"
 *   strings were renamed to "Student 360":
 *     - The $pageTitle fallback. It read
 *         'Reset Password - ' . ($tenantName ?? 'EduTrack')
 *       and now reads
 *         'Reset Password - ' . ($tenantName ?? 'Student 360')
 *     - The tenant-badge fallback. It read
 *         <?php echo htmlspecialchars($tenantName ?? 'EduTrack'); ?>
 *       and now reads
 *         <?php echo htmlspecialchars($tenantName ?? 'Student 360'); ?>
 *   This v1.1 [SWEEP] docblock paragraph was added above the
 *   existing entry. The page is a standalone auth surface. It
 *   does not carry the tenant sidebar partial, does not carry the
 *   .nav-subgroup-label CSS rule, and does not set $currentPage.
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. Every other line of the file is byte-identical
 *   to v1.0. The audit_logs column list used by this file
 *   (entity_type, entity_id) is left as-is per the accepted policy.
 */

session_start();

// ============================================================
// LOAD CONTEXT
// ============================================================
require_once __DIR__ . '/../../../bootstrap/context.php';

$tenantId = tenantId();
$tenantName = tenantName();

$token = $_GET['token'] ?? '';
$audience = $_GET['audience'] ?? 'staff';
$validAudiences = ['staff', 'student', 'admin'];

if (!in_array($audience, $validAudiences)) {
    $audience = 'staff';
}

$pageTitle = 'Reset Password - ' . ($tenantName ?? 'Student 360');
$message = '';
$error = '';
$validToken = false;
$userId = null;

// ============================================================
// VALIDATE TOKEN
// ============================================================
if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    try {
        $db = DatabaseHelper::getInstance();

        $reset = $db->fetchOne("
            SELECT user_id, expires_at 
            FROM password_resets 
            WHERE token = ? AND used_at IS NULL AND deleted_at IS NULL
        ", [$token]);

        if (!$reset) {
            $error = 'Invalid or expired reset token.';
        } elseif (strtotime($reset['expires_at']) < time()) {
            $error = 'Reset token has expired. Please request a new one.';
        } else {
            $validToken = true;
            $userId = $reset['user_id'];
        }
    } catch (Exception $e) {
        $error = 'An error occurred. Please try again.';
        error_log('Reset token validation error: ' . $e->getMessage());
    }
}

// ============================================================
// PROCESS PASSWORD RESET
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken && $userId) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db = DatabaseHelper::getInstance();

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Update user password
            $db->execute(
                "UPDATE platform_users SET password_hash = ? WHERE id = ?",
                [$hashedPassword, $userId]
            );

            // Mark token as used
            $db->execute(
                "UPDATE password_resets SET used_at = NOW() WHERE token = ?",
                [$token]
            );

            // Log the reset
            $db->execute(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, details, created_at)
                 VALUES (?, 'password_reset_completed', 'user', ?, ?, ?, NOW())",
                [$userId, $userId, $tenantId, json_encode(['audience' => $audience])]
            );

            $message = 'Password reset successfully! You can now login with your new password.';
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log('Password reset error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .reset-container {
            width: 100%;
            max-width: 440px;
            background: #fff;
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .reset-container .icon {
            text-align: center;
            font-size: 48px;
            margin-bottom: 16px;
        }

        .reset-container h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            text-align: center;
            margin-bottom: 4px;
        }

        .reset-container .sub {
            text-align: center;
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 24px;
        }

        .reset-container .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
        }

        .reset-container .form-control {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 14px;
            height: 44px;
        }

        .reset-container .form-control:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
        }

        .reset-container .form-control.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .reset-container .btn-reset {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .reset-container .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
        }

        .reset-container .back-link {
            text-align: center;
            margin-top: 16px;
        }

        .reset-container .back-link a {
            color: #6c757d;
            font-size: 13px;
            text-decoration: none;
        }

        .reset-container .back-link a:hover {
            color: #4facfe;
            text-decoration: underline;
        }

        .reset-container .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
        }

        .reset-container .tenant-badge {
            text-align: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f0f2f5;
            font-size: 12px;
            color: #adb5bd;
        }

        .reset-container .tenant-badge i {
            color: #4facfe;
        }

        .password-requirements {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }

        .password-requirements .req {
            display: block;
            padding: 2px 0;
        }

        .password-requirements .req i {
            width: 16px;
        }

        .password-requirements .req.valid {
            color: #28a745;
        }

        .password-requirements .req.invalid {
            color: #dc3545;
        }

        @media (max-width: 480px) {
            .reset-container {
                padding: 28px 20px;
            }
        }
    </style>
</head>

<body>
    <div class="reset-container">
        <div class="icon">🔐</div>
        <h1>Reset Password</h1>
        <p class="sub">Enter your new password below</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="back-link">
                <a href="/platform/tenant/login.php?audience=<?php echo htmlspecialchars($audience); ?>">
                    <i class="fas fa-arrow-left me-1"></i> Back to Login
                </a>
            </div>
        <?php elseif (!$validToken): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <div class="back-link">
                <a href="/platform/auth/forgot-password.php?audience=<?php echo htmlspecialchars($audience); ?>">
                    <i class="fas fa-arrow-left me-1"></i> Request New Reset Link
                </a>
            </div>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="resetForm">
                <div class="mb-3">
                    <label class="form-label" for="password">New Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                        placeholder="Enter new password" required minlength="8">
                    <div class="password-requirements" id="passwordRequirements">
                        <span class="req invalid" id="reqLength"><i class="fas fa-times"></i> At least 8 characters</span>
                        <span class="req invalid" id="reqUpper"><i class="fas fa-times"></i> At least 1 uppercase letter</span>
                        <span class="req invalid" id="reqLower"><i class="fas fa-times"></i> At least 1 lowercase letter</span>
                        <span class="req invalid" id="reqNumber"><i class="fas fa-times"></i> At least 1 number</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                        placeholder="Confirm new password" required>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="fas fa-save me-2"></i> Reset Password
                </button>
            </form>

            <div class="back-link">
                <a href="/platform/tenant/login.php?audience=<?php echo htmlspecialchars($audience); ?>">
                    <i class="fas fa-arrow-left me-1"></i> Back to Login
                </a>
            </div>
        <?php endif; ?>

        <div class="tenant-badge">
            <i class="fas fa-shield-alt me-1"></i>
            <?php echo htmlspecialchars($tenantName ?? 'Student 360'); ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
    </script>
    <script>
        // Password validation
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;

            // Length check
            const lengthReq = document.getElementById('reqLength');
            if (password.length >= 8) {
                lengthReq.className = 'req valid';
                lengthReq.innerHTML = '<i class="fas fa-check"></i> At least 8 characters';
            } else {
                lengthReq.className = 'req invalid';
                lengthReq.innerHTML = '<i class="fas fa-times"></i> At least 8 characters';
            }

            // Uppercase check
            const upperReq = document.getElementById('reqUpper');
            if (/[A-Z]/.test(password)) {
                upperReq.className = 'req valid';
                upperReq.innerHTML = '<i class="fas fa-check"></i> At least 1 uppercase letter';
            } else {
                upperReq.className = 'req invalid';
                upperReq.innerHTML = '<i class="fas fa-times"></i> At least 1 uppercase letter';
            }

            // Lowercase check
            const lowerReq = document.getElementById('reqLower');
            if (/[a-z]/.test(password)) {
                lowerReq.className = 'req valid';
                lowerReq.innerHTML = '<i class="fas fa-check"></i> At least 1 lowercase letter';
            } else {
                lowerReq.className = 'req invalid';
                lowerReq.innerHTML = '<i class="fas fa-times"></i> At least 1 lowercase letter';
            }

            // Number check
            const numberReq = document.getElementById('reqNumber');
            if (/[0-9]/.test(password)) {
                numberReq.className = 'req valid';
                numberReq.innerHTML = '<i class="fas fa-check"></i> At least 1 number';
            } else {
                numberReq.className = 'req invalid';
                numberReq.innerHTML = '<i class="fas fa-times"></i> At least 1 number';
            }
        });

        // Form validation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;

            let valid = true;

            if (password.length < 8) {
                document.getElementById('password').classList.add('is-invalid');
                valid = false;
            } else {
                document.getElementById('password').classList.remove('is-invalid');
            }

            if (password !== confirm) {
                document.getElementById('confirm_password').classList.add('is-invalid');
                valid = false;
            } else {
                document.getElementById('confirm_password').classList.remove('is-invalid');
            }

            if (!valid) {
                e.preventDefault();
            }
        });
    </script>
</body>

</html>