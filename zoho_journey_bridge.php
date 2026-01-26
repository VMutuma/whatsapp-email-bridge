<?php
/**
 * Zoho Customer Journey WhatsApp Bridge
 * 
 * Strategy: Journey-Based Webhooks + Stage Mapping
 * 
 * This is the BEST approach for Zoho CRM customer journey integration because:
 * 1. Real-time triggers when contacts move between stages
 * 2. Each journey stage has its own WhatsApp sequence
 * 3. Automatic re-engagement based on CRM updates
 * 4. No polling needed - instant webhook responses
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';
require_once __DIR__ . '/lib/BeemService.php';

header('Content-Type: application/json');

/**
 * CUSTOMER JOURNEY CONFIGURATION
 * Map your Zoho CRM stages to WhatsApp campaigns
 */
class CustomerJourneyMapper {
    
    /**
     * Define journey stages and their corresponding WhatsApp sequences
     * Customize these based on YOUR customer journey
     */
    private static $journeyMap = [
        
        // STAGE 1: NEW LEAD (Just entered CRM)
        'new_lead' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'New',
            'campaign_token' => 'TOKEN_NEW_LEAD_WELCOME',
            'description' => 'Welcome sequence for brand new leads'
        ],
        
        // STAGE 2: CONTACTED (Sales team reached out)
        'contacted' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Contacted',
            'campaign_token' => 'TOKEN_CONTACTED_FOLLOWUP',
            'description' => 'Follow-up after initial contact'
        ],
        
        // STAGE 3: QUALIFIED (Lead shows interest)
        'qualified' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Qualified',
            'campaign_token' => 'TOKEN_QUALIFIED_NURTURE',
            'description' => 'Nurture sequence for qualified leads'
        ],
        
        // STAGE 4: PROPOSAL SENT (Waiting for decision)
        'proposal_sent' => [
            'zoho_field' => 'Deal_Stage',
            'zoho_value' => 'Proposal Sent',
            'campaign_token' => 'TOKEN_PROPOSAL_FOLLOWUP',
            'description' => 'Gentle reminders after proposal'
        ],
        
        // STAGE 5: NEW CUSTOMER (Deal won!)
        'new_customer' => [
            'zoho_field' => 'Deal_Stage',
            'zoho_value' => 'Closed Won',
            'campaign_token' => 'TOKEN_ONBOARDING',
            'description' => 'Customer onboarding sequence'
        ],
        
        // STAGE 6: ACTIVE CUSTOMER (Using product/service)
        'active_customer' => [
            'zoho_field' => 'Customer_Status',
            'zoho_value' => 'Active',
            'campaign_token' => 'TOKEN_ENGAGEMENT',
            'description' => 'Regular engagement messages'
        ],
        
        // STAGE 7: AT-RISK (Showing churn signals)
        'at_risk' => [
            'zoho_field' => 'Customer_Health',
            'zoho_value' => 'At Risk',
            'campaign_token' => 'TOKEN_RETENTION',
            'description' => 'Win-back campaign for at-risk customers'
        ],
        
        // STAGE 8: CHURNED (Lost customer)
        'churned' => [
            'zoho_field' => 'Customer_Status',
            'zoho_value' => 'Churned',
            'campaign_token' => 'TOKEN_WINBACK',
            'description' => 'Re-engagement for churned customers'
        ],
        
        // CUSTOM STAGES (Add your own)
        'free_trial' => [
            'zoho_field' => 'Account_Type',
            'zoho_value' => 'Free Trial',
            'campaign_token' => 'TOKEN_TRIAL_CONVERSION',
            'description' => 'Convert free trial to paid'
        ],
        
        'vip_customer' => [
            'zoho_field' => 'Customer_Tier',
            'zoho_value' => 'VIP',
            'campaign_token' => 'TOKEN_VIP_TREATMENT',
            'description' => 'VIP customer exclusive updates'
        ]
    ];
    
    /**
     * Get campaign token for a journey stage
     */
    public static function getCampaignForStage($stageName) {
        return self::$journeyMap[$stageName] ?? null;
    }
    
    /**
     * Get all journey configurations
     */
    public static function getAllJourneys() {
        return self::$journeyMap;
    }
    
    /**
     * Find matching journey based on Zoho field updates
     */
    public static function findMatchingJourney($fieldName, $fieldValue) {
        foreach (self::$journeyMap as $stageName => $config) {
            if ($config['zoho_field'] === $fieldName && $config['zoho_value'] === $fieldValue) {
                return [
                    'stage' => $stageName,
                    'config' => $config
                ];
            }
        }
        return null;
    }
}

/**
 * WEBHOOK HANDLER FOR ZOHO CRM
 * Receives real-time updates when contact/lead/deal changes
 */
function handle_zoho_journey_webhook() {
    
    // Log incoming webhook for debugging
    $rawInput = file_get_contents('php://input');
    error_log("Zoho Journey Webhook Received: " . $rawInput);
    
    // Zoho sends JSON payload
    $zohoData = json_decode($rawInput, true);
    
    // Validate webhook
    if (!$zohoData || !isset($zohoData['data'])) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Invalid webhook payload'];
    }
    
    $results = [];
    
    // Process each record (Zoho can send multiple)
    foreach ($zohoData['data'] as $record) {
        
        $recordId = $record['id'] ?? null;
        $module = $zohoData['module'] ?? 'Unknown';
        
        error_log("Processing Zoho $module: ID=$recordId");
        
        // Extract contact information
        $email = $record['Email'] ?? null;
        $phone = $record['Phone'] ?? $record['Mobile'] ?? null;
        $name = $record['Full_Name'] ?? $record['First_Name'] ?? 'Customer';
        
        if (!$email || !$phone) {
            error_log("Skipping record $recordId: Missing email or phone");
            continue;
        }
        
        // Detect journey stage changes
        $triggeredCampaigns = [];
        
        // Check all changed fields against journey map
        foreach ($record as $fieldName => $fieldValue) {
            
            $journey = CustomerJourneyMapper::findMatchingJourney($fieldName, $fieldValue);
            
            if ($journey) {
                error_log("Journey trigger detected: {$journey['stage']} for $email");
                
                // Subscribe to the stage-specific campaign
                try {
                    $result = subscribe_to_journey_campaign(
                        $email,
                        $phone,
                        $name,
                        $journey['config']['campaign_token'],
                        $journey['stage'],
                        $record
                    );
                    
                    $triggeredCampaigns[] = [
                        'stage' => $journey['stage'],
                        'result' => $result
                    ];
                    
                } catch (Exception $e) {
                    error_log("Failed to subscribe $email to {$journey['stage']}: " . $e->getMessage());
                }
            }
        }
        
        $results[] = [
            'record_id' => $recordId,
            'email' => $email,
            'campaigns_triggered' => count($triggeredCampaigns),
            'details' => $triggeredCampaigns
        ];
    }
    
    http_response_code(200);
    return [
        'status' => 'success',
        'message' => 'Journey webhooks processed',
        'processed' => count($results),
        'results' => $results
    ];
}

/**
 * Subscribe contact to journey-specific campaign
 */
function subscribe_to_journey_campaign($email, $phone, $name, $campaignToken, $journeyStage, $zohoRecord) {
    
    // Get campaign configuration
    $config = StorageService::getWebhookConfigByToken($campaignToken);
    
    if (!$config) {
        throw new Exception("Campaign not found: $campaignToken");
    }
    
    // Before subscribing to new journey, unsubscribe from previous stage campaigns
    // This prevents duplicate/conflicting messages
    unsubscribe_from_previous_journeys($email, $journeyStage);
    
    // Format subscriber data
    $subscriberData = [
        'email' => $email,
        'name' => $name,
        'CustomField1' => preg_replace('/[^0-9+]/', '', $phone),
        'list_id' => $config['list_id'],
        'trigger' => 'subscribe',
        'journey_stage' => $journeyStage,
        'zoho_record_id' => $zohoRecord['id'] ?? null,
        'zoho_module' => $zohoRecord['module'] ?? null
    ];
    
    // Load the appropriate handler
    require_once __DIR__ . '/lib/handlers/DripSequenceHandler.php';
    require_once __DIR__ . '/lib/handlers/HybridDripHandler.php';
    require_once __DIR__ . '/lib/handlers/SingleMessageHandler.php';
    
    $mode = $config['mode'];
    
    // Get handler based on mode
    $handler = null;
    switch ($mode) {
        case 'single':
            $handler = new SingleMessageHandler();
            break;
        case 'drip_sequence':
            $handler = new DripSequenceHandler();
            break;
        case 'hybrid_drip':
            $handler = new HybridDripHandler();
            break;
        default:
            throw new Exception("Unsupported mode: $mode");
    }
    
    // Process subscription
    $result = $handler->handleSubscription($subscriberData, $config);
    
    // Log journey progression
    log_journey_progression($email, $journeyStage, $result);
    
    return $result;
}

/**
 * Unsubscribe from previous journey campaigns to avoid conflicts
 * When customer moves from "Lead" to "Customer", stop lead nurturing messages
 */
function unsubscribe_from_previous_journeys($email, $newJourneyStage) {
    
    // Define journey progression rules
    $progressionRules = [
        'new_lead' => ['stop' => ['contacted', 'qualified', 'new_customer', 'active_customer']],
        'contacted' => ['stop' => ['new_lead']],
        'qualified' => ['stop' => ['new_lead', 'contacted']],
        'new_customer' => ['stop' => ['new_lead', 'contacted', 'qualified', 'proposal_sent']],
        'active_customer' => ['stop' => ['new_customer']],
        'at_risk' => ['stop' => ['active_customer']],
        'churned' => ['stop' => ['active_customer', 'at_risk']]
    ];
    
    if (!isset($progressionRules[$newJourneyStage])) {
        return;
    }
    
    $stagesToStop = $progressionRules[$newJourneyStage]['stop'];
    
    // Load all active drip sequences
    $dripQueue = StorageService::load(DRIP_QUEUE_FILE, []);
    $hybridQueue = StorageService::load(HYBRID_QUEUE_FILE, []);
    
    $stopped = 0;
    
    // Stop conflicting drip sequences
    foreach ($dripQueue as &$item) {
        if ($item['email'] === $email && 
            isset($item['journey_stage']) && 
            in_array($item['journey_stage'], $stagesToStop)) {
            
            $item['status'] = 'cancelled';
            $item['cancelled_reason'] = "Progressed to $newJourneyStage";
            $stopped++;
            
            error_log("Stopped {$item['journey_stage']} campaign for $email (moved to $newJourneyStage)");
        }
    }
    
    // Stop conflicting hybrid sequences
    foreach ($hybridQueue as &$item) {
        if ($item['email'] === $email && 
            isset($item['journey_stage']) && 
            in_array($item['journey_stage'], $stagesToStop)) {
            
            $item['status'] = 'cancelled';
            $item['cancelled_reason'] = "Progressed to $newJourneyStage";
            $stopped++;
            
            error_log("Stopped {$item['journey_stage']} hybrid campaign for $email (moved to $newJourneyStage)");
        }
    }
    
    if ($stopped > 0) {
        StorageService::save(DRIP_QUEUE_FILE, $dripQueue);
        StorageService::save(HYBRID_QUEUE_FILE, $hybridQueue);
        error_log("Cancelled $stopped previous campaigns for $email");
    }
}

/**
 * Log customer journey progression for analytics
 */
function log_journey_progression($email, $journeyStage, $campaignResult) {
    $logEntry = [
        'timestamp' => time(),
        'date' => date('Y-m-d H:i:s'),
        'email' => $email,
        'journey_stage' => $journeyStage,
        'campaign_result' => $campaignResult,
        'status' => $campaignResult['status'] ?? 'unknown'
    ];
    
    StorageService::append(DATA_DIR . '/journey_log.json', $logEntry);
}

/**
 * Get journey analytics
 */
function get_journey_analytics() {
    $logs = StorageService::load(DATA_DIR . '/journey_log.json', []);
    
    $analytics = [
        'total_transitions' => count($logs),
        'by_stage' => [],
        'success_rate' => 0,
        'recent_transitions' => []
    ];
    
    $successCount = 0;
    
    foreach ($logs as $log) {
        $stage = $log['journey_stage'];
        
        if (!isset($analytics['by_stage'][$stage])) {
            $analytics['by_stage'][$stage] = [
                'count' => 0,
                'success' => 0,
                'failed' => 0
            ];
        }
        
        $analytics['by_stage'][$stage]['count']++;
        
        if ($log['status'] === 'success') {
            $analytics['by_stage'][$stage]['success']++;
            $successCount++;
        } else {
            $analytics['by_stage'][$stage]['failed']++;
        }
    }
    
    if (count($logs) > 0) {
        $analytics['success_rate'] = round(($successCount / count($logs)) * 100, 2);
    }
    
    // Get last 10 transitions
    $analytics['recent_transitions'] = array_slice(array_reverse($logs), 0, 10);
    
    return $analytics;
}

// ==========================================
// API ROUTER
// ==========================================

$action = $_GET['action'] ?? null;
$response = null;

try {
    switch ($action) {
        
        // Main webhook endpoint for Zoho
        case 'zoho_journey':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response = ['status' => 'error', 'message' => 'Method not allowed'];
            } else {
                $response = handle_zoho_journey_webhook();
            }
            break;
        
        // Get journey configuration
        case 'journey_config':
            $response = [
                'status' => 'success',
                'journeys' => CustomerJourneyMapper::getAllJourneys()
            ];
            break;
        
        // Get journey analytics
        case 'journey_analytics':
            $response = [
                'status' => 'success',
                'analytics' => get_journey_analytics()
            ];
            break;
        
        // Manual subscription to journey (for testing)
        case 'subscribe_to_journey':
            $email = $_POST['email'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $name = $_POST['name'] ?? 'Customer';
            $journeyStage = $_POST['journey_stage'] ?? null;
            
            if (!$email || !$phone || !$journeyStage) {
                http_response_code(400);
                $response = ['status' => 'error', 'message' => 'Missing required fields'];
                break;
            }
            
            $journey = CustomerJourneyMapper::getCampaignForStage($journeyStage);
            
            if (!$journey) {
                http_response_code(404);
                $response = ['status' => 'error', 'message' => 'Journey stage not found'];
                break;
            }
            
            $result = subscribe_to_journey_campaign(
                $email, 
                $phone, 
                $name, 
                $journey['campaign_token'],
                $journeyStage,
                ['id' => 'manual_test']
            );
            
            $response = $result;
            break;
        
        default:
            http_response_code(404);
            $response = ['status' => 'error', 'message' => 'Action not found'];
            break;
    }
    
} catch (Exception $e) {
    error_log("Journey Bridge Error: " . $e->getMessage());
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Internal server error',
        'details' => $e->getMessage()
    ];
}

echo json_encode($response);