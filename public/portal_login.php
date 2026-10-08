<?php

require_once __DIR__ . '/../app/helpers/DatabaseHelper.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['portal_student_id'])) {
    header('Location: /portal_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter your admission number and password.';
    } else {
        try {
            $db = DatabaseHelper::getInstance();
            
            // Find the account
            $sql = "
                SELECT sa.*, s.first_name, s.last_name, s.admission_number
                FROM student_accounts sa
                JOIN students s ON sa.student_id = s.id
                WHERE sa.username = ?
                  AND sa.school_id = 1
                  AND sa.is_active = 1
            ";
            $account = $db->fetchOne($sql, [$username]);
            
            if ($account && password_verify($password, $account['password_hash'])) {
                // Check if account is locked
                if ($account['account_locked'] == 1) {
                    $locked_until = new DateTime($account['locked_until']);
                    $now = new DateTime();
                    if ($now < $locked_until) {
                        $error = 'Account is temporarily locked. Please try again later.';
                    } else {
                        // Unlock account
                        $db->query("UPDATE student_accounts SET account_locked = 0, locked_until = NULL, login_attempts = 0 WHERE id = ?", [$account['id']]);
                        // Proceed with login
                        $_SESSION['portal_user_id'] = $account['id'];
                        $_SESSION['portal_student_id'] = $account['student_id'];
                        $_SESSION['portal_username'] = $account['username'];
                        $_SESSION['portal_logged_in'] = true;
                        
                        // Update last login
                        $db->query("UPDATE student_accounts SET last_login = NOW(), last_login_ip = ?, login_attempts = 0 WHERE id = ?", [$_SERVER['REMOTE_ADDR'] ?? '', $account['id']]);
                        
                        header('Location: /portal_dashboard.php');
                        exit;
                    }
                } else {
                    // Successful login
                    $_SESSION['portal_user_id'] = $account['id'];
                    $_SESSION['portal_student_id'] = $account['student_id'];
                    $_SESSION['portal_username'] = $account['username'];
                    $_SESSION['portal_logged_in'] = true;
                    
                    // Update last login
                    $db->query("UPDATE student_accounts SET last_login = NOW(), last_login_ip = ?, login_attempts = 0 WHERE id = ?", [$_SERVER['REMOTE_ADDR'] ?? '', $account['id']]);
                    
                    // Check if first login
                    if ($account['first_login'] == 1) {
                        header('Location: /portal_change_password.php?first=1');
                    } else {
                        header('Location: /portal_dashboard.php');
                    }
                    exit;
                }
            } else {
                // Failed login - increment attempts
                if ($account) {
                    $attempts = $account['login_attempts'] + 1;
                    if ($attempts >= 5) {
                        $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        $db->query("UPDATE student_accounts SET login_attempts = ?, account_locked = 1, locked_until = ? WHERE id = ?", [$attempts, $locked_until, $account['id']]);
                        $error = 'Too many failed attempts. Account locked for 15 minutes.';
                    } else {
                        $db->query("UPDATE student_accounts SET login_attempts = ? WHERE id = ?", [$attempts, $account['id']]);
                        $error = 'Invalid admission number or password.';
                    }
                } else {
                    $error = 'Invalid admission number or password.';
                }
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - EduTrack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            width: 100%;
            max-width: 420px;
        }
        .login-container h1 {
            text-align: center;
            color: #1a3c6e;
            font-size: 24px;
            margin-bottom: 5px;
        }
        .login-container .subtitle {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input:focus {
            border-color: #667eea;
            outline: none;
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-login:hover {
            background: #2a4c8e;
        }
        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .info {
            text-align: center;
            margin-top: 15px;
            font-size: 12px;
            color: #888;
        }
        .school-name {
            text-align: center;
            font-size: 12px;
            color: #999;
            margin-top: 10px;
        }
        .logo-area {
            text-align: center;
            margin-bottom: 15px;
        }
        .logo-area .icon {
            font-size: 48px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-area">
            <div class="icon">🎓</div>
        </div>
        <h1>Student Portal</h1>
        <div class="subtitle">Login to access your academic report</div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Admission Number</label>
                <input type="text" id="username" name="username" placeholder="e.g., CCI/2026/001" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn-login">🔑 Login</button>
        </form>

        <div class="info">
            <p>Default password: <strong>password</strong> (Change on first login)</p>
        </div>
        <div class="school-name">Church of Christ International School</div>
    </div>
</body>
</html>