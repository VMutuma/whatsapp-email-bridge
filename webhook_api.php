<?php
/**
 * Webhook API - Main Router
 * Handles all incoming webhook requests and API calls
 * Delegates to appropriate handlers based on mode
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';
require_once __DIR__ . '/lib/BeemService.php';
require_once __DIR__ . '/lib/SendyService.php';
require_once __DIR__ . '/lib/handlers/SingleMessageHandler.php';
require_once __DIR__ . '/lib/handlers/DripSequenceHandler.php';
require_once __DIR__ . '/lib/handlers/AutoresponderMirrorHandler.php';

header('Content-Type: application/json');

// WEBHOOK HANDLERS

/**
 * Handle Sendy subscription webhook
 * Routes to appropriate handler based on list configuration
 */
function handle_sendy_webhook() {
    $sendyData = $_POST;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['status' => 'error', 'message' => 'Method not allowed'];
    }
    
    if (!isset($sendyData['list_id']) || !isset($sendyData['trigger'])) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing essential webhook data'];
    }
    
    if ($sendyData['trigger'] !== 'subscribe') {
        error_log("Webhook: Ignoring non-subscription trigger: {$sendyData['trigger']}");
        http_response_code(200);
        return ['status' => 'ignored', 'message' => 'Not a subscription event'];
    }
    
    $listId = $sendyData['list_id'];
    
    $config = StorageService::getListConfig($listId);
    
    if (!$config) {
        error_log("Webhook: No configuration found for list $listId");
        http_response_code(200);
        return ['status' => 'ignored', 'message' => 'List not configured for WhatsApp'];
    }
    
    $handler = getHandlerForMode($config['mode']);
    
    if (!$handler) {
        error_log("Webhook: Unknown mode '{$config['mode']}' for list $listId");
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Invalid configuration mode'];
    }
    
    try {
        $result = $handler->handleSubscription($sendyData, $config);
        
        http_response_code(200);
        return $result;
        
    } catch (Exception $e) {
        error_log("Webhook: Handler exception - " . $e->getMessage());
        http_response_code(500);
        return [
            'status' => 'error',
            'message' => 'Internal processing error',
            'details' => $e->getMessage()
        ];
    }
}

/**
 * Handle Sendy autoresponder webhook
 * Used for mirror_autoresponder mode
 */
function handle_autoresponder_webhook() {
    $webhookData = $_POST;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['status' => 'error', 'message' => 'Method not allowed'];
    }
    
    try {
        $result = AutoresponderMirrorHandler::handleAutoresponderTrigger($webhookData);
        
        http_response_code(200);
        return $result;
        
    } catch (Exception $e) {
        error_log("Autoresponder webhook: Exception - " . $e->getMessage());
        http_response_code(500);
        return [
            'status' => 'error',
            'message' => 'Failed to process autoresponder webhook',
            'details' => $e->getMessage()
        ];
    }
}

/**
 * Get handler instance for a given mode
 */
function getHandlerForMode($mode) {
    switch ($mode) {
        case 'single':
            return new SingleMessageHandler();
        
        case 'drip_sequence':
            return new DripSequenceHandler();
        
        case 'mirror_autoresponder':
            return new AutoresponderMirrorHandler();
        
        default:
            return null;
    }
}

// ====================================================================
// API ENDPOINTS
// ====================================================================

/**
 * GET /webhook_api.php?action=brands
 * Get all Sendy brands
 */
function handle_api_brands() {
    try {
        $brands = SendyService::getBrands();
        
        http_response_code(200);
        return $brands;
        
    } catch (Exception $e) {
        error_log("API brands: " . $e->getMessage());
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * GET /webhook_api.php?action=lists&brandId=X
 * Get all lists for a brand
 */
function handle_api_lists() {
    $brandId = $_GET['brandId'] ?? null;
    
    if (!$brandId) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing brandId parameter'];
    }
    
    try {
        $lists = SendyService::getLists($brandId);
        
        http_response_code(200);
        return $lists;
        
    } catch (Exception $e) {
        error_log("API lists: " . $e->getMessage());
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * GET /webhook_api.php?action=whatsapp_templates
 * Get all WhatsApp templates
 */
function handle_api_whatsapp_templates() {
    try {
        $templates = BeemService::getTemplates();
        
        http_response_code(200);
        return $templates;
        
    } catch (Exception $e) {
        error_log("API templates: " . $e->getMessage());
        http_response_code(500);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * POST /webhook_api.php?action=save_configuration
 * Save list configuration (supports all modes)
 */
function handle_api_save_configuration() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $listId = $input['list_id'] ?? null;
    $webhookUrlRoot = $input['webhook_url_root'] ?? null;
    $config = $input['config'] ?? null;
    
    if (!$listId || !$webhookUrlRoot || !$config) {
        http_response_code(400);
        return [
            'status' => 'error',
            'message' => 'Missing required parameters: list_id, webhook_url_root, or config'
        ];
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
    
    $success = StorageService::saveListConfig($listId, $config);
    
    if (!$success) {
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Failed to save configuration'];
    }
    
    $webhookURL = rtrim($webhookUrlRoot, '/') . '/webhook_api.php?action=sendy_handler';
    
    $autoresponderWebhookURL = null;
    if ($mode === 'mirror_autoresponder') {
        $autoresponderWebhookURL = rtrim($webhookUrlRoot, '/') . '/webhook_api.php?action=autoresponder_handler';
    }
    
    http_response_code(200);
    return [
        'status' => 'success',
        'message' => 'Configuration saved successfully',
        'webhook_url' => $webhookURL,
        'autoresponder_webhook_url' => $autoresponderWebhookURL,
        'list_id' => $listId,
        'mode' => $mode
    ];
}

/**
 * GET /webhook_api.php?action=get_configuration&list_id=X
 * Get configuration for a list
 */
function handle_api_get_configuration() {
    $listId = $_GET['list_id'] ?? null;
    
    if (!$listId) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Missing list_id parameter'];
    }
    
    $config = StorageService::getListConfig($listId);
    
    if (!$config) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Configuration not found'];
    }
    
    http_response_code(200);
    return ['status' => 'success', 'config' => $config];
}

/**
 * GET /webhook_api.php?action=drip_status
 * Get status of drip queue
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
        if ($item['status'] === 'active') {
            $stats['active']++;
            $stats['pending_messages'] += (count($item['sequence']) - count($item['completed_steps']));
        } else if ($item['status'] === 'completed') {
            $stats['completed']++;
        }
    }
    
    http_response_code(200);
    return $stats;
}

// MAIN ROUTER

$action = $_GET['action'] ?? null;
$response = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sendy_handler') {
        $response = handle_sendy_webhook();
        echo json_encode($response);
        exit;
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'autoresponder_handler') {
        $response = handle_autoresponder_webhook();
        echo json_encode($response);
        exit;
        
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
            
            case 'get_configuration':
                $response = handle_api_get_configuration();
                break;
            
            case 'drip_status':
                $response = handle_api_drip_status();
                break;
            
            default:
                http_response_code(404);
                $response = ['status' => 'error', 'message' => 'API endpoint not found'];
                break;
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_configuration') {
        $response = handle_api_save_configuration();
        
    } else {
        http_response_code(400);
        $response = ['status' => 'error', 'message' => 'Invalid request'];
    }
    
} catch (Exception $e) {
    error_log("Router exception: " . $e->getMessage());
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Internal server error',
        'details' => $e->getMessage()
    ];
}

echo json_encode($response);