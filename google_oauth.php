<?php
/**
 * Google OAuth Handler
 * Handles the OAuth flow for Google Sign-In
 */

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_errors.log');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 1800,
        'path'     => '/',
        'domain'   => 'drips.beem.africa',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
require_once 'config.php';
require_once 'auth_config.php';

// Check if this is a callback from Google
if (isset($_GET['code'])) {
    handleGoogleCallback();
} else {
    initiateGoogleLogin();
}

/**
 * Initiate Google OAuth login
 */
function initiateGoogleLogin() {
    if (empty(GOOGLE_CLIENT_ID)) {
        die('Google OAuth is not configured. Please set GOOGLE_CLIENT_ID in your .env file.');
    }

    // Generate state token for CSRF protection
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    // Build Google OAuth URL
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account'
    ];

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header('Location: ' . $authUrl);
    exit;
}

/**
 * Handle callback from Google OAuth
 */
function handleGoogleCallback() {
    file_put_contents(
        'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
        date('Y-m-d H:i:s') . " - Callback started\n",
        FILE_APPEND
    );

    // Verify state token
    if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        file_put_contents(
            'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
            "State mismatch\n",
            FILE_APPEND
        );
        header('Location: login.php?error=invalid_state');
        exit;
    }

    file_put_contents(
        'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
        "State OK - exchanging token\n",
        FILE_APPEND
    );

    // Exchange authorization code for access token
    $tokenData = exchangeCodeForToken($_GET['code']);

    file_put_contents(
        'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
        "Token result: " . json_encode($tokenData) . "\n",
        FILE_APPEND
    );

    if (!$tokenData) {
        header('Location: login.php?error=oauth_failed');
        exit;
    }

    // Get user info from Google
    $userInfo = getUserInfo($tokenData['access_token']);

    file_put_contents(
        'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
        "User info: " . json_encode($userInfo) . "\n",
        FILE_APPEND
    );

    if (!$userInfo) {
        header('Location: login.php?error=oauth_failed');
        exit;
    }

    // Check if email is from allowed domain
    if (!isAllowedEmail($userInfo['email'])) {
        file_put_contents(
            'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
            "Unauthorized email: " . $userInfo['email'] . "\n",
            FILE_APPEND
        );
        header('Location: login.php?error=unauthorized');
        exit;
    }

    // Set session variables
    $_SESSION['user_email']   = $userInfo['email'];
    $_SESSION['user_name']    = $userInfo['name'] ?? '';
    $_SESSION['user_picture'] = $userInfo['picture'] ?? '';
    $_SESSION['last_activity'] = time();

    // Redirect to original page or dashboard
    $redirect = $_SESSION['redirect_after_login'] ?? 'index.html';
    unset($_SESSION['redirect_after_login']);

    file_put_contents(
        'C:/xampp/htdocs/project/whatsapp-email-bridge/data/oauth_debug.log',
        "Redirecting to: $redirect\n",
        FILE_APPEND
    );

    header('Location: ' . $redirect);
    exit;
}

/**
 * Exchange authorization code for access token
 */
function exchangeCodeForToken($code) {
    $params = [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // OK for localhost only
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // OK for localhost only

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('Token exchange failed: ' . $response . ' cURL error: ' . $curlError);
        return null;
    }

    return json_decode($response, true);
}

/**
 * Get user information from Google
 */
function getUserInfo($accessToken) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // OK for localhost only
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // OK for localhost only
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('User info fetch failed: ' . $response);
        return null;
    }

    return json_decode($response, true);
}