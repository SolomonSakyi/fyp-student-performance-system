<?php

/**
 * Super Admin Login - Login page for Super Administrators only
 * 
 * @package EduTrack
 * @subpackage Platform
 * @version 2.0
 * @filepath public/platform/login.php
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// REDIRECT IF ALREADY LOGGED IN
// =============================================
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
        header('Location: /platform/index.php');
    } else {
        header('Location: /platform/tenant/dashboard.php');
    }
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Super Admin Login - EduTrack Platform';
$error = '';
$returnUrl = isset($_GET['return']) ? urldecode($_GET['return']) : '';

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 2);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// HANDLE LOGIN
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Get user by username or email - Super Admin only
        $user = $db->fetchOne(
            "SELECT * FROM platform_users 
             WHERE (username = ? OR email = ?) 
             AND is_super_admin = 1
             AND is_active = 1 
             AND (deleted_at IS NULL OR deleted_at = '')",
            [$username, $username]
        );

        if (!$user) {
            $error = 'Invalid credentials or you do not have Super Admin privileges.';
        } else {
            // Verify password
            $isValid = false;

            // Check using password_verify
            if (password_verify($password, $user['password_hash'])) {
                $isValid = true;
            }

            // For default admin password (Admin@123) - allow first login
            if (!$isValid && $password === 'Admin@123' && $user['username'] === 'admin') {
                // Rehash the password for security
                $newHash = password_hash('Admin@123', PASSWORD_DEFAULT);
                $db->execute("UPDATE platform_users SET password_hash = ? WHERE id = ?", [$newHash, $user['id']]);
                $isValid = true;
            }

            if (!$isValid) {
                $error = 'Invalid password. Please try again.';
            } else {
                // Set session variables
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['is_super_admin'] = true;
                $_SESSION['is_tenant_admin'] = false;
                $_SESSION['tenant_id'] = $user['tenant_id'];

                // Update last login
                $db->execute("UPDATE platform_users SET last_login = NOW() WHERE id = ?", [$user['id']]);

                // Redirect to platform dashboard
                header('Location: ' . ($returnUrl ?: '/platform/index.php'));
                exit;
            }
        }
    }
}

// =============================================
// GET RETURN URL
// =============================================
$returnParam = '';
if ($returnUrl) {
    $returnParam = '&return=' . urlencode($returnUrl);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }

        .login-container {
            max-width: 420px;
            width: 100%;
            padding: 20px;
        }

        .login-card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 36px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #4facfe, #00f2fe, #4facfe);
            background-size: 200% 200%;
            animation: gradientMove 3s ease infinite;
        }

        @keyframes gradientMove {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }

        .login-logo .logo-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #fff;
            margin: 0 auto 12px;
            box-shadow: 0 8px 24px rgba(79, 172, 254, 0.3);
        }

        .login-logo h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }

        .login-logo p {
            color: #6c757d;
            font-size: 14px;
            margin: 4px 0 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            display: block;
            margin-bottom: 4px;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            font-size: 14px;
            width: 100%;
            transition: all 0.3s;
            background: #f8f9fa;
        }

        .form-control:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
            background: #fff;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 6px;
            font-size: 16px;
        }

        .password-toggle:hover {
            color: #4facfe;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
            cursor: pointer;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
        }

        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .error-message {
            background: #fef2f2;
            border-left: 4px solid #dc3545;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-message i {
            font-size: 18px;
            color: #dc3545;
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: #6c757d;
        }

        .login-footer a {
            color: #4facfe;
            text-decoration: none;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .super-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            background: #d4edda;
            color: #155724;
            margin-top: 4px;
        }

        .platform-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 4px;
            font-size: 12px;
            color: #6c757d;
        }

        .platform-info i {
            color: #4facfe;
        }

        .back-link {
            display: inline-block;
            margin-top: 12px;
            color: #6c757d;
            font-size: 13px;
            text-decoration: none;
        }

        .back-link:hover {
            color: #4facfe;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 28px 20px;
            }

            .login-logo h1 {
                font-size: 20px;
            }

            .login-logo .logo-icon {
                width: 52px;
                height: 52px;
                font-size: 22px;
            }

            .form-control {
                font-size: 13px;
                padding: 10px 14px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-icon">
                    <i class="fas fa-crown"></i>
                </div>
                <h1>EduTrack</h1>
                <p>Super Administrator Login</p>
                <div class="platform-info">
                    <i class="fas fa-globe"></i> Platform Management
                    <span class="super-badge">Super Admin</span>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/platform/login.php<?php echo $returnParam; ?>" id="loginForm">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" class="form-control" id="username" name="username"
                        placeholder="Enter your username or email" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="password" name="password"
                            placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" id="togglePassword"
                            aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In as Super Admin
                </button>
            </form>

            <div class="login-footer">
                <p>Default Super Admin: <strong>admin</strong> / <strong>Admin@123</strong></p>
                <a href="/platform/tenant/login.php" class="back-link">
                    <i class="fas fa-arrow-left me-1"></i> Tenant Admin Login
                </a>
            </div>
        </div>
    </div>

    <script>
        // ================================================
        // PASSWORD TOGGLE
        // ================================================
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // ================================================
        // FORM SUBMIT HANDLER
        // ================================================
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('loginBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Signing In...';
        });

        // ================================================
        // ENTER KEY SUPPORT
        // ================================================
        document.getElementById('password').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });

        // ================================================
        // AUTO-FILL FOR DEVELOPMENT
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-fill for quick testing (remove in production)
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            if (username && !username.value) {
                username.value = 'admin';
            }
            if (password && !password.value) {
                password.value = 'Admin@123';
            }
        });
    </script>
</body>

</html>