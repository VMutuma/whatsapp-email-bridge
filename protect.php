<?php
/**
 * Protected Page Wrapper
 * Include this at the top of any page that requires authentication
 * 
 * Usage:
 * require_once 'protect.php';
 */
// Ensure session is persistent on siteground
if (session_status() ===PHP_SESSION_NONE) {
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
require_once 'auth_config.php';
requireAuth();

// Optional: Add user info to all protected pages
$current_user = getCurrentUser();
$user_name = $_SESSION['user_name'] ?? '';
$user_picture = $_SESSION['user_picture'] ?? '';
