<?php

/**
 * 403 Forbidden Error Page
 *
 * @package EduTrack
 * @subpackage Errors
 * @filepath public/errors/403.php
 */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden - EduTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .error-container {
            text-align: center;
            padding: 40px;
            max-width: 500px;
        }

        .error-container .error-icon {
            font-size: 80px;
            color: #dc3545;
            margin-bottom: 20px;
        }

        .error-container h1 {
            font-size: 72px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 0;
        }

        .error-container h2 {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 12px;
        }

        .error-container p {
            color: #6c757d;
            margin-bottom: 24px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            padding: 10px 30px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-lock"></i>
        </div>
        <h1>403</h1>
        <h2>Access Forbidden</h2>
        <p>You don't have permission to access this page. Please contact your administrator if you believe this is an error.</p>
        <a href="/platform/login.php" class="btn-primary">
            <i class="fas fa-sign-in-alt me-2"></i> Back to Login
        </a>
        <br><br>
        <a href="/platform/index.php" class="text-muted small">
            <i class="fas fa-arrow-left me-1"></i> Return to Dashboard
        </a>
    </div>
</body>

</html>