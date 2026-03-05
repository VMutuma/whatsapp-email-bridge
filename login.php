<?php
session_start();

$alert = '';

if (isset($_GET['error'])) {
    $error_messages = [
        'unauthorized' => 'Access denied. Only @beem.africa email accounts are allowed.',
        'oauth_failed' => 'Google authentication failed. Please try again.',
        'session_expired' => 'Your session has expired. Please sign in again.',
        'invalid_state' => 'Invalid authentication state. Please try again.'
    ];
    
    $error = $_GET['error'];
    $message = $error_messages[$error] ?? 'An error occurred. Please try again.';
    $alert = "<div class='alert alert-error'>$message</div>";
}

if (isset($_GET['info'])) {
    if ($_GET['info'] === 'logged_out') {
        $alert = "<div class='alert alert-info'>You have been successfully logged out.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WhatsApp Email Bridge</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 440px;
            width: 100%;
            padding: 48px 40px;
            text-align: center;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }

        h1 {
            color: #1a202c;
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .subtitle {
            color: #718096;
            font-size: 16px;
            margin-bottom: 40px;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            text-align: left;
        }

        .alert-error {
            background-color: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }

        .alert-info {
            background-color: #bee3f8;
            color: #2c5282;
            border: 1px solid #63b3ed;
        }

        .google-btn {
            width: 100%;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 24px;
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.2s;
        }

        .google-btn:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .google-btn:active {
            transform: translateY(0);
        }

        .google-icon {
            width: 20px;
            height: 20px;
        }

        .divider {
            margin: 32px 0;
            text-align: center;
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e2e8f0;
        }

        .divider span {
            background: white;
            padding: 0 16px;
            color: #a0aec0;
            font-size: 14px;
            position: relative;
        }

        .restriction-note {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 16px;
            margin-top: 24px;
            text-align: left;
            border-radius: 4px;
        }

        .restriction-note strong {
            color: #2d3748;
            display: block;
            margin-bottom: 8px;
        }

        .restriction-note p {
            color: #718096;
            font-size: 14px;
            line-height: 1.6;
        }

        .footer {
            margin-top: 32px;
            color: #a0aec0;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">📧</div>
        <h1>WhatsApp Email Bridge</h1>
        <p class="subtitle">Sign in to access the dashboard</p>

        <?= $alert ?>

        <button class="google-btn" onclick="loginWithGoogle()">
            <svg class="google-icon" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Continue with Google
        </button>

        <div class="restriction-note">
            <strong>Access Restricted</strong>
            <p>Only email addresses ending with <strong>@beem.africa</strong> can access this system.</p>
        </div>

        <div class="footer">
            Powered by Beem
        </div>
    </div>

    <script>
        function loginWithGoogle() {
            window.location.href = 'google_oauth.php';
        }
    </script>
</body>
</html>