<?php
/**
 * Authentication Configuration
 * Restricts access to @beem.africa email accounts only
 */

// Allowed email domain
define('ALLOWED_DOMAIN', '@beem.africa');

// Session timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

function isAuthenticated() {
    if (!isset($_SESSION['user_email']) || !isset($_SESSION['last_activity'])) {
        return false;
    }
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function isAllowedEmail($email) {
    return str_ends_with(strtolower($email), ALLOWED_DOMAIN);
}

function getCurrentUser() {
    return $_SESSION['user_email'] ?? null;
}

function requireAuth() {
    if (!isAuthenticated()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

function logout() {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}