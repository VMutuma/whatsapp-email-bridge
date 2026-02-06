<?php
/**
 * API v2 - Complete Webhook API
 * Loads handlers on-demand to avoid errors
 */

// Kill all caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Start output buffering
ob_start();

// Disable display_errors
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/data/api_error.log');

// Load ONLY essential dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';
require_once __DIR__ . '/lib/BeemService.php';
require_once __DIR__ . '/lib/SendyService.php';

// Don't load handlers yet - load them only when needed

// Clear any output
ob_end_clean();

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Debug logging
$logFile = __DIR__ . '/data/webhook_debug.log';
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'get' => $_GET,
    'post' => $_POST,
    'raw_input' => file_get_contents('php://input')
];
file_put_contents(
    $logFile, 
    json_encode($logData, JSON_PRETTY_PRINT) . "\n\n" . str_repeat('=', 80) . "\n\n",
    FILE_APPEND
);

/**
 * Load handler on demand
 */
function getHandlerForMode($mode) {
    switch ($mode) {
        case 'single':
            require_once __DIR__ . '/lib/handlers/SingleMessageHandler.php';
            return new SingleMessageHandler();
        
        case 'drip_sequence':
            require_once __DIR__ . '/lib/handlers/DripSequenceHandler.php';
            return new DripSequenceHandler();
        
        case 'mirror_autoresponder':
            require_once __DIR__ . '/lib/handlers/AutoresponderMirrorHandler.php';
            return new AutoresponderMirrorHandler();

        case 'hybrid_drip': 
            require_once __DIR__ . '/lib/handlers/HybridDripHandler.php';
            return new HybridDripHandler();
        
        default:
            return null;
    }
}

/**
 * Handle Sendy subscription webhook
 */
function handle_sendy_webhook() {
    $sendyData = $_POST;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['status' => 'error', 'message' => 'Method not allowed'];
    }
    
    if (!isset($sendyData['trigger']) || $sendyData['trigger'] !== 'subscribe') {
        http_response_code(200);
        return ['status' => 'ignored', 'message' => 'Not a subscription event'];
    }
    
    $token = $_GET['token'] ?? null;
    if (!$token) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing webhook token'];
    }
    
    $config = StorageService::getWebhookConfigByToken($token);
    if (!$config) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Webhook token not found'];
    }
    
    $sendyData['list_id'] = $config['list_id'];
    $handler = getHandlerForMode($config['mode']);
    
    if (!$handler) {
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Invalid configuration mode'];
    }
    
    try {
        $result = $handler->handleSubscription($sendyData, $config);
        http_response_code(200);
        return $result;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Handle autoresponder webhook
 */
function handle_autoresponder_webhook() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['status' => 'error', 'message' => 'Method not allowed'];
    }
    
    try {
        require_once __DIR__ . '/lib/handlers/AutoresponderMirrorHandler.php';
        $result = AutoresponderMirrorHandler::handleAutoresponderTrigger($_POST);
        http_response_code(200);
        return $result;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * GET brands
 */
function handle_api_brands() {
    try {
        $sendy = new SendyService([
            'SENDY_API_KEY' => SENDY_API_KEY,
            'SENDY_URL' => SENDY_URL,
            'SENDY_GET_BRANDS_URL' => SENDY_GET_BRANDS_URL,
            'SENDY_GET_LISTS_URL' => SENDY_GET_LISTS_URL,
        ]);
        
        $brands = $sendy->getBrands();
        http_response_code(200);
        return $brands;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * GET lists
 */
function handle_api_lists() {
    $brandId = $_GET['brandId'] ?? null;
    
    if (!$brandId) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing brandId'];
    }
    
    try {
        $sendy = new SendyService([
            'SENDY_API_KEY' => SENDY_API_KEY,
            'SENDY_URL' => SENDY_URL,
            'SENDY_GET_BRANDS_URL' => SENDY_GET_BRANDS_URL,
            'SENDY_GET_LISTS_URL' => SENDY_GET_LISTS_URL,
        ]);
        
        $lists = $sendy->getLists($brandId);
        http_response_code(200);
        return $lists;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * GET templates
 */
function handle_api_whatsapp_templates() {
    try {
        $beem = new BeemService([
            'BEEM_API_KEY' => BEEM_API_KEY,
            'BEEM_SECRET_KEY' => BEEM_SECRET_KEY,
            'BEEM_API_BASE_URL' => BEEM_API_BASE_URL,
            'BEEM_USER_ID_FOR_TEMPLATES' => BEEM_USER_ID_FOR_TEMPLATES,
        ]);
        
        $templates = $beem->getTemplates();
        http_response_code(200);
        return $templates;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * POST save_configuration
 */
function handle_api_save_configuration() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $listId = $input['list_id'] ?? null;
    $listName = $input['list_name'] ?? 'Unknown';
    $webhookName = $input['webhook_name'] ?? $listName;
    $webhookUrlRoot = $input['webhook_url_root'] ?? null;
    $config = $input['config'] ?? null;
    
    if (!$listId || !$webhookUrlRoot || !$config) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing required parameters'];
    }
    
    $mode = $config['mode'] ?? 'single';
    $handler = getHandlerForMode($mode);
    
    if (!$handler) {
        http_response_code(400);
        return ['status' => 'error', 'message' => "Invalid mode: $mode"];
    }
    
    $validation = $handler->validateConfig($config);
    if ($validation !== true) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Invalid configuration', 'details' => $validation];
    }
    
    $token = StorageService::generateToken();
    
    $config['list_id'] = $listId;
    $config['list_name'] = $listName;
    $config['webhook_name'] = $webhookName;
    $config['created_at'] = time();
    $config['updated_at'] = time();
    $config['token'] = $token;
    $config['webhook_url_root'] = $webhookUrlRoot;

    $success = StorageService::saveWebhookConfig($token, $config);
    
    if (!$success) {
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Failed to save configuration'];
    }
    
    $webhookURL = rtrim($webhookUrlRoot, '/') . '/api_v2.php?action=sendy_handler&token=' . $token;
    
    $autoresponderWebhookURL = null;
    if ($mode === 'mirror_autoresponder') {
        $autoresponderWebhookURL = rtrim($webhookUrlRoot, '/') . '/api_v2.php?action=autoresponder_handler&token=' . $token;
    }
    
    http_response_code(200);
    return [
        'status' => 'success',
        'message' => 'Configuration saved',
        'webhook_url' => $webhookURL,
        'autoresponder_webhook_url' => $autoresponderWebhookURL,
        'token' => $token,
        'list_id' => $listId,
        'list_name' => $listName,
        'webhook_name' => $webhookName,
        'mode' => $mode
    ];
}

/**
 * GET list_webhooks
 */
function handle_api_list_webhooks() {
    $webhooks = StorageService::getAllWebhooks();
    
    $result = [];
    foreach ($webhooks as $token => $config) {
        $result[] = [
            'token' => $token,
            'list_id' => $config['list_id'] ?? null,
            'list_name' => $config['list_name'] ?? 'Unknown',
            'webhook_name' => $config['webhook_name'] ?? $config['list_name'] ?? 'Unknown',
            'mode' => $config['mode'] ?? 'unknown',
            'created_at' => $config['created_at'] ?? null,
            'webhook_url' => ($config['webhook_url_root'] ?? '') . '/api_v2.php?action=sendy_handler&token=' . $token
        ];
    }
    
    http_response_code(200);
    return $result;
}

/**
 * DELETE webhook
 */
function handle_api_delete_webhook() {
    $token = $_GET['token'] ?? null;
    
    if (!$token) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing token'];
    }
    
    if (!StorageService::tokenExists($token)) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Webhook not found'];
    }
    
    $success = StorageService::deleteWebhook($token);
    
    if ($success) {
        http_response_code(200);
        return ['status' => 'success', 'message' => 'Webhook deleted'];
    } else {
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Delete failed'];
    }
}

/**
 * GET drip_status
 */
function handle_api_drip_status() {
    $queue = StorageService::load(DRIP_QUEUE_FILE, []);
    
    $stats = [
        'total' => count($queue),
        'active' => 0,
        'completed' => 0,
        'pending_messages' => 0
    ];
    
    foreach ($queue as $item) {
        if (($item['status'] ?? '') === 'active') {
            $stats['active']++;
            $stats['pending_messages'] += (count($item['sequence'] ?? []) - count($item['completed_steps'] ?? []));
        } else if (($item['status'] ?? '') === 'completed') {
            $stats['completed']++;
        }
    }
    
    http_response_code(200);
    return $stats;
}

/**
 * GET hybrid_status
 */
function handle_api_hybrid_status() {
    try {
        require_once __DIR__ . '/lib/handlers/HybridDripHandler.php';
        $stats = HybridDripHandler::getStats();
        http_response_code(200);
        return $stats;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ROUTER
$action = $_GET['action'] ?? null;
$response = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sendy_handler') {
        $response = handle_sendy_webhook();
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'autoresponder_handler') {
        $response = handle_autoresponder_webhook();
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_configuration') {
        $response = handle_api_save_configuration();
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($action) {
            case 'brands':
                $response = handle_api_brands();
                break;
            case 'lists':
                $response = handle_api_lists();
                break;
            case 'whatsapp_templates':
                $response = handle_api_whatsapp_templates();
                break;
            case 'list_webhooks':
                $response = handle_api_list_webhooks();
                break;
            case 'delete_webhook':
                $response = handle_api_delete_webhook();
                break;
            case 'drip_status':
                $response = handle_api_drip_status();
                break;
            case 'hybrid_status':
                $response = handle_api_hybrid_status();
                break;
            default:
                http_response_code(404);
                $response = ['status' => 'error', 'message' => 'Endpoint not found'];
        }
    } else {
        http_response_code(400);
        $response = ['status' => 'error', 'message' => 'Invalid request'];
    }
    
} catch (Exception $e) {
    error_log("Router exception: " . $e->getMessage());
    http_response_code(500);
    $response = ['status' => 'error', 'message' => 'Server error', 'details' => $e->getMessage()];
}

echo json_encode($response);