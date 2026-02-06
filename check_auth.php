<?php
/**
 * Authentication Check and HTML File Loader
 * This file intercepts all .html requests and ensures user is authenticated
 */

require_once 'auth_config.php';

// Check if user is authenticated
if (!isAuthenticated()) {
    // Store the requested page to redirect after login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

// Get the requested file from URL parameter
$requestedFile = $_GET['file'] ?? '';

// Security: Prevent directory traversal attacks
$requestedFile = basename($requestedFile);

// Ensure it's an HTML file
if (!str_ends_with($requestedFile, '.html')) {
    http_response_code(403);
    die('Forbidden');
}

// Check if file exists
$filePath = __DIR__ . '/' . $requestedFile;
if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found');
}

// Set up session variables for use in HTML
$current_user = getCurrentUser();
$user_name = $_SESSION['user_name'] ?? '';
$user_picture = $_SESSION['user_picture'] ?? '';

// Read the HTML file
$htmlContent = file_get_contents($filePath);

// Inject user navigation before closing </body> tag
$userNavHtml = getUserNavigationHtml();
$htmlContent = str_replace('</body>', $userNavHtml . '</body>', $htmlContent);

// Output the HTML
header('Content-Type: text/html; charset=utf-8');
echo $htmlContent;

/**
 * Generate user navigation HTML
 */
function getUserNavigationHtml() {
    global $current_user, $user_name, $user_picture;
    
    $userName = htmlspecialchars($user_name ?: 'User');
    $userEmail = htmlspecialchars($current_user);
    $userInitial = strtoupper(substr($user_name ?: $current_user, 0, 1));
    
    $avatarHtml = '';
    if (!empty($user_picture)) {
        $avatarHtml = '<img src="' . htmlspecialchars($user_picture) . '" alt="User" class="user-avatar">';
    } else {
        $avatarHtml = '<div class="user-avatar-placeholder">' . $userInitial . '</div>';
    }
    
    return <<<HTML
<style>
    .user-nav {
        position: fixed;
        top: 0;
        right: 0;
        padding: 16px 24px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        border-bottom-left-radius: 8px;
        display: flex;
        align-items: center;
        gap: 16px;
        z-index: 10000;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
    }

    .user-avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 16px;
    }

    .user-details {
        display: flex;
        flex-direction: column;
    }

    .user-name {
        font-weight: 600;
        color: #1a202c;
        font-size: 14px;
    }

    .user-email {
        font-size: 12px;
        color: #718096;
    }

    .logout-btn {
        padding: 8px 16px;
        background: #f7fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        color: #2d3748;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-block;
    }

    .logout-btn:hover {
        background: #edf2f7;
        border-color: #cbd5e0;
    }

    @media (max-width: 640px) {
        .user-nav {
            padding: 12px 16px;
        }

        .user-details {
            display: none;
        }
    }
</style>

<div class="user-nav">
    <div class="user-info">
        $avatarHtml
        <div class="user-details">
            <div class="user-name">$userName</div>
            <div class="user-email">$userEmail</div>
        </div>
    </div>
    <a href="logout.php" class="logout-btn">Logout</a>
</div>
HTML;
}
