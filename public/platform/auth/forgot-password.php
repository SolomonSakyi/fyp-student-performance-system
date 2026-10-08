<?php

/**
 * Forgot Password
 * Handles password reset requests for tenant users
 *
 * @package EduTrack
 * @subpackage Platform\Auth
 * @version 1.1
 * @filepath public/platform/auth/forgot-password.php
 *
 * v1.1 change (2026-10-07) [SWEEP X-1]:
 *   Platform-auth sweep, X-1 in full. Two visible "EduTrack"
 *   strings were renamed to "Student 360":
 *     - The $pageTitle fallback. It read
 *         'Forgot Password - ' . ($schoolName ?? $tenantName ?? 'EduTrack')
 *       and now reads
 *         'Forgot Password - ' . ($schoolName ?? $tenantName ?? 'Student 360')
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
 *   to v1.0.
 */

session_start();

// ============================================================
// LOAD CONTEXT
// ============================================================
require_once __DIR__ . '/../../../bootstrap/context.php';

$tenantId = tenantId();
$tenantName = tenantName();
$schoolName = schoolName();
$domainName = domainName();

$audience = $_GET['audience'] ?? 'staff';
$validAudiences = ['staff', 'student', 'admin'];

if (!in_array($audience, $validAudiences)) {
    $audience = 'staff';
}

$pageTitle = 'Forgot Password - ' . ($schoolName ?? $tenantName ?? 'Student 360');
$message = '';
$error = '';

// ============================================================
// PROCESS FORM
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $audience = $_POST['audience'] ?? 'staff';

    if (empty($identifier)) {
        $error = 'Please enter your email or username.';
    } else {
        try {
            $db = DatabaseHelper::getInstance();

            // Find user
            $user = $db->fetchOne("
                SELECT u.id, u.username, u.email, u.tenant_id, 
                       p.first_name, p.last_name, p.email as person_email
                FROM platform_users u
                LEFT JOIN persons p ON u.person_id = p.id
                WHERE (u.username = ? OR u.email = ? OR p.email = ?)
                  AND u.tenant_id = ?
                  AND u.deleted_at IS NULL
            ", [$identifier, $identifier, $identifier, $tenantId]);

            if (!$user) {
                $error = 'No account found with that email or username.';
            } else {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                $db->execute(
                    "INSERT INTO password_resets (user_id, token, expires_at, created_at)
                     VALUES (?, ?, ?, NOW())",
                    [$user['id'], $token, $expires]
                );

                // Build reset link
                $resetLink = ($_SERVER['HTTPS'] ?? 'on' ? 'https' : 'http') . '://' .
                    $_SERVER['HTTP_HOST'] .
                    '/platform/auth/reset-password.php?token=' . $token . '&audience=' . $audience;

                // In a production environment, send email here
                // For now, store in session for demo
                $_SESSION['reset_token'] = $token;
                $_SESSION['reset_email'] = $user['email'] ?? $user['person_email'];

                $message = 'A password reset link has been sent to your email. (Demo: ' . $resetLink . ')';

                // Log the reset request
                $db->execute(
                    "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, details, created_at)
                     VALUES (?, 'password_reset_requested', 'user', ?, ?, ?, NOW())",
                    [$user['id'], $user['id'], $tenantId, json_encode(['audience' => $audience])]
                );
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log('Forgot password error: ' . $e->getMessage());
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

        @media (max-width: 480px) {
            .reset-container {
                padding: 28px 20px;
            }
        }
    </style>
</head>

<body>
    <div class="reset-container">
        <div class="icon">🔑</div>
        <h1>Forgot Password</h1>
        <p class="sub">Enter your email or username to reset your password</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($message)): ?>
            <form method="POST" action="">
                <input type="hidden" name="audience" value="<?php echo htmlspecialchars($audience); ?>">

                <div class="mb-3">
                    <label class="form-label" for="identifier">Email or Username</label>
                    <input type="text" class="form-control" id="identifier" name="identifier"
                        placeholder="Enter your email or username" required autofocus>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="fas fa-paper-plane me-2"></i> Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="/platform/tenant/login.php?audience=<?php echo htmlspecialchars($audience); ?>">
                <i class="fas fa-arrow-left me-1"></i> Back to Login
            </a>
        </div>

        <div class="tenant-badge">
            <i class="fas fa-shield-alt me-1"></i>
            <?php echo htmlspecialchars($tenantName ?? 'Student 360'); ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
    </script>
</body>

</html>