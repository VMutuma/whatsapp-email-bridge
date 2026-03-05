<?php
session_start();

$alert = '';

if (isset($_GET['error'])) {
    $error_messages = [
        'unauthorized'   => 'Access denied. Only @beem.africa email accounts are allowed.',
        'oauth_failed'   => 'Google authentication failed. Please try again.',
        'session_expired'=> 'Your session has expired. Please sign in again.',
        'invalid_state'  => 'Invalid authentication state. Please try again.'
    ];
    $error   = $_GET['error'];
    $message = $error_messages[$error] ?? 'An error occurred. Please try again.';
    $alert   = "<div class='alert alert-error'>$message</div>";
}

if (isset($_GET['info']) && $_GET['info'] === 'logged_out') {
    $alert = "<div class='alert alert-info'>You have been successfully logged out.</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — WhatsApp Bridge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --teal:      #33B1BA;
            --teal-dark: #2a9aa2;
            --teal-dim:  #eaf7f8;
            --amber:     #F3A929;
            --amber-dim: #fef8ec;
            --ink:       #111827;
            --ink-2:     #374151;
            --ink-3:     #6B7280;
            --ink-4:     #9CA3AF;
            --border:    #E5E7EB;
            --bg:        #F9FAFB;
            --white:     #ffffff;
            --red:       #EF4444;
            --radius:    10px;
            --radius-lg: 16px;
            --shadow:    0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        /* subtle grid pattern */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(var(--border) 1px, transparent 1px),
                linear-gradient(90deg, var(--border) 1px, transparent 1px);
            background-size: 40px 40px;
            opacity: .45;
            pointer-events: none;
        }

        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 420px;
            padding: 48px 40px 40px;
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 36px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: var(--teal);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .brand-icon svg { display: block; }

        .brand-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--ink);
            letter-spacing: -.01em;
        }

        .brand-tag {
            font-size: 12px;
            color: var(--ink-3);
            font-weight: 400;
        }

        h1 {
            font-size: 26px;
            font-weight: 600;
            color: var(--ink);
            letter-spacing: -.02em;
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: 14px;
            color: var(--ink-3);
            margin-bottom: 32px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            font-size: 13.5px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: var(--teal-dim);
            color: var(--teal-dark);
            border: 1px solid #a5d8db;
        }

        .google-btn {
            width: 100%;
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 13px 20px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14.5px;
            font-weight: 500;
            color: var(--ink);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: border-color .15s, box-shadow .15s, transform .1s;
        }

        .google-btn:hover {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(51,177,186,.12);
            transform: translateY(-1px);
        }

        .google-btn:active { transform: translateY(0); }

        .google-icon { width: 18px; height: 18px; flex-shrink: 0; }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 28px 0;
            color: var(--ink-4);
            font-size: 12px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .restriction {
            background: var(--teal-dim);
            border-left: 3px solid var(--teal);
            border-radius: 0 var(--radius) var(--radius) 0;
            padding: 14px 16px;
        }

        .restriction strong {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink-2);
            margin-bottom: 4px;
        }

        .restriction p {
            font-size: 13px;
            color: var(--ink-3);
            line-height: 1.5;
        }

        .restriction code {
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            background: rgba(51,177,186,.15);
            color: var(--teal-dark);
            padding: 1px 5px;
            border-radius: 4px;
        }

        .footer {
            margin-top: 28px;
            text-align: center;
            font-size: 12px;
            color: var(--ink-4);
        }

        .footer span {
            color: var(--teal);
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <div class="brand-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
        </div>
        <div>
            <div class="brand-name">WhatsApp Bridge</div>
            <div class="brand-tag">Beem Internal Tool</div>
        </div>
    </div>

    <h1>Welcome back</h1>
    <p class="subtitle">Sign in to access the dashboard</p>

    <?= $alert ?>

    <button class="google-btn" onclick="window.location.href='google_oauth.php'">
        <svg class="google-icon" viewBox="0 0 24 24">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        Continue with Google
    </button>

    <div class="divider">or</div>

    <div class="restriction">
        <strong>Access Restricted</strong>
        <p>Only email addresses ending with <code>@beem.africa</code> can access this system.</p>
    </div>

    <div class="footer">Powered by <span>Beem</span></div>
</div>
</body>
</html>