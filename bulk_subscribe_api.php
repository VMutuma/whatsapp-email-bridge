<?php
/**
 * Bulk Subscribe API Router
 * Handles CSV uploads and bulk subscriptions to Sendy
 * Follows same pattern as webhook_api.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/SendyService.php';
require_once __DIR__ . '/lib/StorageService.php';

header('Content-Type: application/json');

/**
 * Handle CSV file upload and bulk subscription
 */
function handle_bulk_upload() {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['status' => 'error', 'message' => 'Method not allowed'];
    }
    
    // Validate required parameters
    $listId = $_POST['list_id'] ?? '';
    if (empty($listId)) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing list_id parameter'];
    }
    
    // Validate file upload
    if (!isset($_FILES['csv_file']) || empty($_FILES['csv_file']['tmp_name'])) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'No CSV file uploaded'];
    }
    
    $file = $_FILES['csv_file'];
    
    // Validate file type and size
    $allowedTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'File too large (max 10MB)'];
    }
    
    if (!in_array($file['type'], $allowedTypes) && 
        !preg_match('/\.csv$/i', $file['name'])) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Invalid file type. Only CSV files allowed'];
    }
    
    // Process CSV file
    $results = process_csv_file($file['tmp_name'], $listId);
    
    // Log the operation
    $logEntry = [
        'timestamp' => time(),
        'list_id' => $listId,
        'file_name' => $file['name'],
        'results' => $results
    ];
    
    StorageService::append('bulk_subscription_logs.json', $logEntry);
    
    return [
        'status' => 'success',
        'message' => 'Bulk subscription completed',
        'results' => $results
    ];
}

/**
 * Process CSV file line by line
 */
function process_csv_file($filePath, $listId) {
    $results = [
        'total' => 0,
        'success' => 0,
        'already_subscribed' => 0,
        'errors' => [],
        'failed_emails' => []
    ];
    
    // Open CSV file
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new Exception("Could not open CSV file");
    }
    
    $headerSkipped = false;
    
    while (($row = fgetcsv($handle)) !== false) {
        // Skip empty rows
        if (empty($row[0])) {
            continue;
        }
        
        // Skip header row (first row)
        if (!$headerSkipped) {
            $headerSkipped = true;
            
            // Check if first column looks like email header
            if (preg_match('/email/i', $row[0])) {
                continue;
            }
        }
        
        $email = trim($row[0]);
        $name = isset($row[1]) ? trim($row[1]) : '';
        
        $results['total']++;
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $results['errors'][] = "Invalid email format: $email";
            $results['failed_emails'][] = $email;
            continue;
        }
        
        // Subscribe user via Sendy API
        $subscribeUrl = SENDY_URL . '/subscribe';
        
        $postData = [
            'api_key' => SENDY_API_KEY,
            'list' => $listId,
            'email' => $email,
            'boolean' => 'true' // Return simple true/false response
        ];
        
        if (!empty($name)) {
            $postData['name'] = $name;
        }
        
        try {
            // Use SendyService's callApi method
            $response = SendyService::callApi($subscribeUrl, $postData);
            
            // Parse Sendy's response
            if ($response === '1' || stripos($response, 'subscribed') !== false) {
                $results['success']++;
            } elseif ($response === '2' || stripos($response, 'already subscribed') !== false) {
                $results['already_subscribed']++;
            } elseif (stripos($response, 'bounced') !== false) {
                $results['errors'][] = "Email bounced: $email";
                $results['failed_emails'][] = $email;
            } elseif (stripos($response, 'invalid') !== false) {
                $results['errors'][] = "Invalid email: $email";
                $results['failed_emails'][] = $email;
            } else {
                $results['errors'][] = "Unexpected response for $email: " . substr($response, 0, 100);
                $results['failed_emails'][] = $email;
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Error subscribing $email: " . $e->getMessage();
            $results['failed_emails'][] = $email;
        }
        
        // Small delay to avoid overwhelming Sendy
        if ($results['total'] % 10 === 0) {
            usleep(100000); // 0.1 second delay every 10 records
        }
    }
    
    fclose($handle);
    
    return $results;
}

/**
 * Get bulk subscription logs
 */
function handle_get_logs() {
    $logs = StorageService::load('bulk_subscription_logs.json', []);
    
    // Format timestamps for display
    foreach ($logs as &$log) {
        $log['date'] = date('Y-m-d H:i:s', $log['timestamp']);
    }
    
    return [
        'status' => 'success',
        'logs' => $logs
    ];
}

/**
 * Clear bulk subscription logs
 */
function handle_clear_logs() {
    StorageService::save('bulk_subscription_logs.json', []);
    
    return [
        'status' => 'success',
        'message' => 'Logs cleared successfully'
    ];
}

/**
 * Get available brands (reuse from webhook_api.php pattern)
 */
function handle_get_brands() {
    try {
        $brands = SendyService::getBrands();
        
        return [
            'status' => 'success',
            'brands' => $brands
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Failed to fetch brands: ' . $e->getMessage()
        ];
    }
}

/**
 * Get lists for a brand (reuse from webhook_api.php pattern)
 */
function handle_get_lists() {
    $brandId = $_GET['brand_id'] ?? '';
    
    if (empty($brandId)) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing brand_id parameter'];
    }
    
    try {
        $lists = SendyService::getLists($brandId);
        
        return [
            'status' => 'success',
            'lists' => $lists
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Failed to fetch lists: ' . $e->getMessage()
        ];
    }
}

// Main router
$action = $_GET['action'] ?? null;
$response = null;

try {
    switch ($action) {
        case 'bulk_upload':
            $response = handle_bulk_upload();
            break;
            
        case 'get_logs':
            $response = handle_get_logs();
            break;
            
        case 'clear_logs':
            $response = handle_clear_logs();
            break;
            
        case 'get_brands':
            $response = handle_get_brands();
            break;
            
        case 'get_lists':
            $response = handle_get_lists();
            break;
            
        default:
            http_response_code(404);
            $response = ['status' => 'error', 'message' => 'Action not found'];
            break;
    }
} catch (Exception $e) {
    error_log("Bulk Subscribe API Error: " . $e->getMessage());
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Internal server error',
        'details' => $e->getMessage()
    ];
}

echo json_encode($response);