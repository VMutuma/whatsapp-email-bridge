<?php
/**
 * Authentication Configuration
 * Restricts access to @beem.africa email accounts only
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Allowed email domain
define('ALLOWED_DOMAIN', '@beem.africa');

// Session timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Google OAuth credentials (you'll need to set these up)
// Get these from: https://console.cloud.google.com/
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: '');

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    if (!isset($_SESSION['user_email']) || !isset($_SESSION['last_activity'])) {
        return false;
    }
    
    // Check session timeout
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Check if email is from allowed domain
 */
function isAllowedEmail($email) {
    return str_ends_with(strtolower($email), ALLOWED_DOMAIN);
}

/**
 * Get current user email
 */
function getCurrentUser() {
    return $_SESSION['user_email'] ?? null;
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth() {
    if (!isAuthenticated()) {
        // Store the requested page to redirect after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

/**
 * Logout user
 */
function logout() {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
