<?php
/**
 * Zoho Customer Journey WhatsApp Bridge
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';
require_once __DIR__ . '/lib/BeemService.php';

header('Content-Type: application/json');

class CustomerJourneyMapper {
    
    private static $journeyMap = [
        
        
        /**
         * TRIAL - MOJA PRODUCT
         * When lead signs up for Moja trial
         */
        'trial_moja' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Trial - Moja',
            'campaign_token' => 'REPLACE_WITH_TOKEN',  // ← Update after creating campaign
            'description' => 'Moja trial onboarding and conversion'
        ],
        
        /**
         * QUALIFIED PROSPECT
         * Lead shows genuine interest
         */
        'qualified_prospect' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Qualified Prospect',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Nurture qualified prospects'
        ],
        
        /**
         * EVALUATION STAGE
         * Lead is evaluating your solution
         */
        'evaluation' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Evaluation',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Support during evaluation phase'
        ],
        
        /**
         * NEGOTIATION STAGE
         * Deal in negotiation
         */
        'negotiation' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Negotiation',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Close the deal - negotiation support'
        ],
        
        /**
         * CLOSED WON - New Customer!
         * Deal won, now onboard
         */
        'closed_won' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Closed Won',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Customer onboarding sequence'
        ],
        
        /**
         * TRIAL - NON-MOJA PRODUCT
         * When lead signs up for other product trial
         */
        'trial_non_moja' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Trial - Non-Moja',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Non-Moja trial onboarding'
        ],
        
        
        /**
         * CONTACT IN FUTURE - MOJA
         * Not ready now, but interested in Moja
         */
        'future_contact_moja' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Contact in Future - Moja',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Long-term nurture for future Moja customers'
        ],
        
        /**
         * CONTACT IN FUTURE - NON-MOJA
         * Not ready now, interested in other products
         */
        'future_contact_non_moja' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Contact in Future - Non-Moja',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Long-term nurture for other products'
        ],
        
        /**
         * UNQUALIFIED LEAD
         * Lead doesn't meet criteria
         */
        'unqualified_lead' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Unqualified Lead',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Educational content, may re-qualify later'
        ],
        
        /**
         * CLOSED LOST
         * Deal lost to competitor or no decision
         */
        'closed_lost' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Closed Lost',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Win-back campaign for lost deals'
        ],
        
        /**
         * JUNK LEAD
         * Not a real prospect (spam, wrong contact, etc.)
         */
        'junk_lead' => [
            'zoho_field' => 'Lead_Status',
            'zoho_value' => 'Junk Lead',
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'No action - just log for records'
        ],
        
        
        /**
         * HIGH ENGAGEMENT (Based on Visitor Score)
         * Lead visiting frequently
         */
        'high_engagement' => [
            'zoho_field' => 'Visitor_Score',
            'zoho_value' => ['>', '80'],
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Highly engaged - push for demo/trial'
        ],
        
        /**
         * FREQUENT VISITOR
         * Lead visited multiple days
         */
        'frequent_visitor' => [
            'zoho_field' => 'Days_Visited',
            'zoho_value' => ['>', '5'],
            'campaign_token' => 'REPLACE_WITH_TOKEN',
            'description' => 'Frequent visitor - offer personal demo'
        ],
        
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
            if (is_array($config['zoho_value'])) {
                $operator = $config['zoho_value'][0];
                $compareValue = $config['zoho_value'][1];
                
                if ($config['zoho_field'] === $fieldName) {
                    $match = false;
                    switch ($operator) {
                        case '>':
                            $match = $fieldValue > $compareValue;
                            break;
                        case '<':
                            $match = $fieldValue < $compareValue;
                            break;
                        case '>=':
                            $match = $fieldValue >= $compareValue;
                            break;
                        case '<=':
                            $match = $fieldValue <= $compareValue;
                            break;
                    }
                    
                    if ($match) {
                        return [
                            'stage' => $stageName,
                            'config' => $config
                        ];
                    }
                }
            } 
            // Handle exact match values
            else if ($config['zoho_field'] === $fieldName && $config['zoho_value'] === $fieldValue) {
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
 */
function handle_zoho_journey_webhook() {
    
    $rawInput = file_get_contents('php://input');
    error_log("Zoho Journey Webhook Received: " . $rawInput);
    
    $zohoData = json_decode($rawInput, true);
    
    if (!$zohoData || !isset($zohoData['data'])) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'Invalid webhook payload'];
    }
    
    $results = [];
    
    foreach ($zohoData['data'] as $record) {
        
        $recordId = $record['id'] ?? null;
        $module = $zohoData['module'] ?? 'Unknown';
        
        error_log("Processing Zoho $module: ID=$recordId");
        
        // Extract contact information
        $email = $record['Email'] ?? null;
        $phone = $record['Phone'] ?? $record['Mobile'] ?? null;
        
        $firstName = $record['First_Name'] ?? '';
        $lastName = $record['Last_Name'] ?? '';
        $name = trim("$firstName $lastName");
        if (empty($name)) {
            $name = $record['Company'] ?? 'Customer';
        }
        
        if (!$email) {
            error_log("Skipping record $recordId: Missing email");
            continue;
        }
        
        if (!$phone) {
            error_log("Warning: Record $recordId ($email) has no phone - WhatsApp will fail");
        }
        
        $triggeredCampaigns = [];
        
        // Check all changed fields against journey map
        foreach ($record as $fieldName => $fieldValue) {
            
            $journey = CustomerJourneyMapper::findMatchingJourney($fieldName, $fieldValue);
            
            if ($journey) {
                error_log("Journey trigger detected: {$journey['stage']} for $email (Field: $fieldName = $fieldValue)");
                
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
                        'field' => $fieldName,
                        'value' => $fieldValue,
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
            'name' => $name,
            'phone' => $phone,
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
    
    $config = StorageService::getWebhookConfigByToken($campaignToken);
    
    if (!$config) {
        throw new Exception("Campaign not found for token: $campaignToken. Create campaign first!");
    }
    
    unsubscribe_from_previous_journeys($email, $journeyStage);
    
    $subscriberData = [
        'email' => $email,
        'name' => $name,
        'CustomField1' => $phone ? preg_replace('/[^0-9+]/', '', $phone) : '',
        'list_id' => $config['list_id'],
        'trigger' => 'subscribe',
        'journey_stage' => $journeyStage,
        'zoho_record_id' => $zohoRecord['id'] ?? null,
        'zoho_module' => 'Leads'
    ];
    
    require_once __DIR__ . '/lib/handlers/DripSequenceHandler.php';
    require_once __DIR__ . '/lib/handlers/HybridDripHandler.php';
    require_once __DIR__ . '/lib/handlers/SingleMessageHandler.php';
    
    $mode = $config['mode'];
    
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
    
    $result = $handler->handleSubscription($subscriberData, $config);
    
    log_journey_progression($email, $journeyStage, $result);
    
    return $result;
}

/**
 * Unsubscribe from previous journey campaigns
 */
function unsubscribe_from_previous_journeys($email, $newJourneyStage) {
    
    // Define which stages should cancel which previous campaigns
    $progressionRules = [
        // When moving to trial, stop all previous nurture
        'trial_moja' => ['stop' => ['qualified_prospect', 'future_contact_moja', 'unqualified_lead']],
        'trial_non_moja' => ['stop' => ['qualified_prospect', 'future_contact_non_moja', 'unqualified_lead']],
        
        // When qualified, stop basic nurture
        'qualified_prospect' => ['stop' => ['unqualified_lead']],
        
        // When evaluating, stop earlier stages
        'evaluation' => ['stop' => ['qualified_prospect', 'trial_moja', 'trial_non_moja']],
        
        // When negotiating, stop all previous
        'negotiation' => ['stop' => ['qualified_prospect', 'evaluation', 'trial_moja', 'trial_non_moja']],
        
        // When won, stop everything except onboarding
        'closed_won' => ['stop' => ['qualified_prospect', 'evaluation', 'negotiation', 'trial_moja', 'trial_non_moja', 'future_contact_moja', 'future_contact_non_moja']],
        
        // When lost, stop active campaigns
        'closed_lost' => ['stop' => ['qualified_prospect', 'evaluation', 'negotiation', 'trial_moja', 'trial_non_moja']],
        
        // Future contact stops active selling
        'future_contact_moja' => ['stop' => ['qualified_prospect', 'trial_moja']],
        'future_contact_non_moja' => ['stop' => ['qualified_prospect', 'trial_non_moja']],
    ];
    
    if (!isset($progressionRules[$newJourneyStage])) {
        return;
    }
    
    $stagesToStop = $progressionRules[$newJourneyStage]['stop'];
    
    $dripQueue = StorageService::load(DRIP_QUEUE_FILE, []);
    $hybridQueue = StorageService::load(HYBRID_QUEUE_FILE, []);
    
    $stopped = 0;
    
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
    
    foreach ($hybridQueue as &$item) {
        if ($item['email'] === $email && 
            isset($item['journey_stage']) && 
            in_array($item['journey_stage'], $stagesToStop)) {
            
            $item['status'] = 'cancelled';
            $item['cancelled_reason'] = "Progressed to $newJourneyStage";
            $stopped++;
        }
    }
    
    if ($stopped > 0) {
        StorageService::save(DRIP_QUEUE_FILE, $dripQueue);
        StorageService::save(HYBRID_QUEUE_FILE, $hybridQueue);
        error_log("Cancelled $stopped previous campaigns for $email");
    }
}

/**
 * Log journey progression
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
    
    $analytics['recent_transitions'] = array_slice(array_reverse($logs), 0, 10);
    
    return $analytics;
}

// API ROUTER
$action = $_GET['action'] ?? null;
$response = null;

try {
    switch ($action) {
        
        case 'zoho_journey':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response = ['status' => 'error', 'message' => 'Method not allowed'];
            } else {
                $response = handle_zoho_journey_webhook();
            }
            break;
        
        case 'journey_config':
            $response = [
                'status' => 'success',
                'journeys' => CustomerJourneyMapper::getAllJourneys()
            ];
            break;
        
        case 'journey_analytics':
            $response = [
                'status' => 'success',
                'analytics' => get_journey_analytics()
            ];
            break;
        
        case 'subscribe_to_journey':
            $email = $_POST['email'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $name = $_POST['name'] ?? 'Customer';
            $journeyStage = $_POST['journey_stage'] ?? null;
            
            if (!$email || !$journeyStage) {
                http_response_code(400);
                $response = ['status' => 'error', 'message' => 'Missing email or journey_stage'];
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