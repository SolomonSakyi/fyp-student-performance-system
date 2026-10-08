<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['portal_student_id'])) {
    header('Location: /portal_login.php');
    exit;
}

require_once __DIR__ . '/../app/helpers/DatabaseHelper.php';

$db = DatabaseHelper::getInstance();
$userId = $_SESSION['portal_user_id'];
$isFirstLogin = isset($_GET['first']);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Verify current password
        $sql = "SELECT password_hash FROM student_accounts WHERE id = ?";
        $account = $db->fetchOne($sql, [$userId]);
        
        if ($account && password_verify($currentPassword, $account['password_hash'])) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->query("UPDATE student_accounts SET password_hash = ?, first_login = 0 WHERE id = ?", [$newHash, $userId]);
            
            $message = 'Password changed successfully!';
            
            if ($isFirstLogin) {
                echo '<meta http-equiv="refresh" content="2;url=/portal_dashboard.php">';
            }
        } else {
            $error = 'Current password is incorrect.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - EduTrack</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .container {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
        }
        h1 {
            color: #1a3c6e;
            margin-bottom: 5px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        .form-group input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input:focus {
            border-color: #667eea;
            outline: none;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #1a3c6e;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        .btn:hover {
            background: #2a4c8e;
        }
        .btn-back {
            background: #666;
            margin-top: 10px;
        }
        .btn-back:hover {
            background: #555;
        }
        .message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .first-login-notice {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🔑 Change Password</h1>
        <div class="subtitle">Update your portal password</div>

        <?php if ($isFirstLogin): ?>
            <div class="first-login-notice">🔒 This is your first login. Please set a new password to continue.</div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="message">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="form-group">
                <label for="new_password">New Password (min 6 characters)</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn">Update Password</button>
        </form>

        <button onclick="window.location.href='portal_dashboard.php'" class="btn btn-back">← Back to Dashboard</button>

        <div class="info">EduTrack - Student Performance Management System</div>
    </div>
</body>
</html>