<?php
/**
 * Google OAuth Handler
 * Handles the OAuth flow for Google Sign-In
 */

require_once 'auth_config.php';

// Load Google OAuth library (we'll use a simple approach without external libraries)
// If you want to use Google's official library, install it via composer:
// composer require google/apiclient:"^2.0"

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
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account'
    ];
    
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header('Location: ' . $authUrl);
    exit;
}

/**
 * Handle callback from Google OAuth
 */
function handleGoogleCallback() {
    // Verify state token
    if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        header('Location: login.php?error=invalid_state');
        exit;
    }
    
    // Exchange authorization code for access token
    $tokenData = exchangeCodeForToken($_GET['code']);
    
    if (!$tokenData) {
        header('Location: login.php?error=oauth_failed');
        exit;
    }
    
    // Get user info from Google
    $userInfo = getUserInfo($tokenData['access_token']);
    
    if (!$userInfo) {
        header('Location: login.php?error=oauth_failed');
        exit;
    }
    
    // Check if email is from allowed domain
    if (!isAllowedEmail($userInfo['email'])) {
        header('Location: login.php?error=unauthorized');
        exit;
    }
    
    // Set session variables
    $_SESSION['user_email'] = $userInfo['email'];
    $_SESSION['user_name'] = $userInfo['name'] ?? '';
    $_SESSION['user_picture'] = $userInfo['picture'] ?? '';
    $_SESSION['last_activity'] = time();
    
    // Redirect to original page or dashboard
    $redirect = $_SESSION['redirect_after_login'] ?? 'index.html';
    unset($_SESSION['redirect_after_login']);
    
    header('Location: ' . $redirect);
    exit;
}

/**
 * Exchange authorization code for access token
 */
function exchangeCodeForToken($code) {
    $params = [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log('Token exchange failed: ' . $response);
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
