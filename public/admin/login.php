<?php

require_once __DIR__ . '/../../app/helpers/AdminHelper.php';
require_once __DIR__ . '/../../app/helpers/DatabaseHelper.php';

// If already logged in, redirect to dashboard
if (AdminHelper::isLoggedIn()) {
    header('Location: /admin/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === 'admin' && $password === 'admin123') {
        AdminHelper::login($username);
        header('Location: /admin/index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - EduTrack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #1a3c6e 0%, #2a5c8e 100%);
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
            max-width: 400px;
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
        .form-group { margin-bottom: 15px; }
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
            border-color: #1a3c6e;
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
        .btn-login:hover { background: #2a4c8e; }
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
        .info strong { color: #1a3c6e; }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🛡️ Admin Login</h1>
        <div class="subtitle">EduTrack Administration Panel</div>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="admin" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn-login">🔑 Login</button>
        </form>

        <div class="info">
            Default: <strong>admin</strong> / <strong>admin123</strong>
        </div>
    </div>
</body>
</html>