<?php
/**
 * Protected Page Wrapper
 * Include this at the top of any page that requires authentication
 * 
 * Usage:
 * require_once 'protect.php';
 */

require_once 'auth_config.php';
requireAuth();

// Optional: Add user info to all protected pages
$current_user = getCurrentUser();
$user_name = $_SESSION['user_name'] ?? '';
$user_picture = $_SESSION['user_picture'] ?? '';
