<?php

/**
 * login.php
 * Tenant-specific login page with domain branding
 * 
 * @filepath public/tenant/views/login.php
 */

$tenantName = $tenantData['tenant_name'] ?? 'EduTrack';
$tenantCode = $tenantData['tenant_code'] ?? '';
$domainType = $domainType ?? 'subdomain';
$domainName = $domainName ?? $tenantCode . '.edutrack.local';
$settings = $settings ?? [];
$primaryColor = $settings['primary_color'] ?? '#4facfe';
$logoUrl = $settings['logo'] ?? '/assets/images/default-logo.png';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tenantName); ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primaryColor; ?>;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }

        .login-container {
            max-width: 420px;
            width: 100%;
        }

        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            padding: 40px 32px;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .login-brand {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-brand img {
            max-height: 60px;
            margin-bottom: 16px;
        }

        .login-brand h4 {
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
            font-size: 20px;
        }

        .login-brand p {
            color: #6c757d;
            font-size: 14px;
            margin: 4px 0 0;
        }

        .login-brand .domain-badge {
            display: inline-block;
            background: #f0f2f5;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
        }

        .login-brand .domain-badge i {
            margin-right: 4px;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            font-size: 14px;
            height: 48px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 6px 8px;
            font-size: 16px;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
            padding: 12px 16px;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
            font-size: 13px;
        }

        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.15em;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }

            .login-brand h4 {
                font-size: 18px;
            }

            .form-control {
                font-size: 13px;
                padding: 10px 14px;
                height: 44px;
            }

            .btn-primary {
                font-size: 13px;
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Brand Section -->
            <div class="login-brand">
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($tenantName); ?> Logo">
                <h4><?php echo htmlspecialchars($tenantName); ?></h4>
                <p>Sign in to your account</p>
                <div class="domain-badge">
                    <i class="fas fa-globe"></i>
                    <?php echo htmlspecialchars($domainName); ?>
                    <?php if ($domainType === 'custom'): ?>
                        <span class="badge bg-success ms-1">Custom</span>
                    <?php else: ?>
                        <span class="badge bg-info ms-1">Subdomain</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alert Container -->
            <div id="alertContainer"></div>

            <!-- Login Form -->
            <form id="loginForm" method="POST" action="/tenant/login.php">
                <input type="hidden" name="action" value="login">

                <div class="mb-3">
                    <label class="form-label" for="username">Username or Email</label>
                    <input type="text" class="form-control" id="username" name="username"
                        placeholder="Enter your username or email" required autofocus>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="password" name="password"
                            placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" id="togglePassword"
                            aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                        <label class="form-check-label" for="remember" style="font-size: 13px; color: #6c757d;">
                            Remember me
                        </label>
                    </div>
                    <a href="/tenant/forgot-password.php" style="font-size: 13px; color: var(--primary-color); text-decoration: none;">
                        Forgot password?
                    </a>
                </div>

                <button type="submit" class="btn-primary" id="loginBtn">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In
                </button>
            </form>

            <!-- Footer -->
            <div class="login-footer">
                Powered by <a href="https://edutrack.com">EduTrack</a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const alertContainer = document.getElementById('alertContainer');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');

            // Toggle password visibility
            document.getElementById('togglePassword').addEventListener('click', function() {
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

            // Form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Basic validation
                if (!usernameInput.value.trim()) {
                    showAlert('Please enter your username or email.', 'danger');
                    usernameInput.classList.add('is-invalid');
                    usernameInput.focus();
                    return;
                }

                if (!passwordInput.value.trim()) {
                    showAlert('Please enter your password.', 'danger');
                    passwordInput.classList.add('is-invalid');
                    passwordInput.focus();
                    return;
                }

                // Clear previous errors
                usernameInput.classList.remove('is-invalid');
                passwordInput.classList.remove('is-invalid');
                hideAlert();

                // Submit form
                const formData = new FormData(form);
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Signing in...';

                fetch('/tenant/login.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showAlert('Login successful! Redirecting...', 'success');
                            setTimeout(function() {
                                window.location.href = data.redirect || '/dashboard.php';
                            }, 1000);
                        } else {
                            showAlert(data.error || 'Invalid credentials. Please try again.', 'danger');
                            loginBtn.disabled = false;
                            loginBtn.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i> Sign In';
                        }
                    })
                    .catch(error => {
                        showAlert('An error occurred. Please try again.', 'danger');
                        loginBtn.disabled = false;
                        loginBtn.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i> Sign In';
                    });
            });

            // Remove error state on input
            usernameInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
                hideAlert();
            });

            passwordInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
                hideAlert();
            });

            // Alert functions
            function showAlert(message, type = 'danger') {
                alertContainer.innerHTML = `
                    <div class="alert alert-${type} alert-dismissible fade show">
                        <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'check-circle'} me-2"></i>
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" 
                                onclick="this.closest('.alert').remove()"></button>
                    </div>
                `;
            }

            function hideAlert() {
                alertContainer.innerHTML = '';
            }
        });
    </script>
</body>

</html>