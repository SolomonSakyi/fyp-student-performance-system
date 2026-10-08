<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .error-container {
            background: #fff;
            padding: 48px 40px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            text-align: center;
            max-width: 480px;
            width: 100%;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .error-container .icon {
            font-size: 64px;
            margin-bottom: 16px;
            display: block;
        }

        .error-container h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .error-container p {
            color: #6c757d;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 8px;
        }

        .error-container .sub-text {
            font-size: 13px;
            color: #adb5bd;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f0f2f5;
        }

        .error-container .sub-text a {
            color: #4facfe;
            text-decoration: none;
        }

        .error-container .sub-text a:hover {
            text-decoration: underline;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 24px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .back-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <span class="icon">🚫</span>
        <h1>Access Denied</h1>
        <p>You do not have permission to access this page.</p>
        <p style="font-size: 14px; color: #adb5bd;">Please contact your administrator if you believe this is an error.</p>
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left me-2"></i> Go Back
        </a>
        <div class="sub-text">
            <a href="/platform/auth/logout.php">Logout</a>
        </div>
    </div>
</body>

</html>