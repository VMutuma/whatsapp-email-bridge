<?php
/**
 * Drip Sequence Handler
 * Handles independent WhatsApp drip sequences
 * High cohesion: Only deals with queuing drip sequences
 * Loose coupling: Uses StorageService, implements interface
 */

require_once __DIR__ . '/WebhookHandlerInterface.php';
require_once __DIR__ . '/../StorageService.php';

class DripSequenceHandler implements WebhookHandlerInterface {
    
    public function getMode() {
        return 'drip_sequence';
    }
    
    public function validateConfig($config) {
        if (!isset($config['sequence']) || !is_array($config['sequence'])) {
            return ['error' => 'sequence array is required for drip_sequence mode'];
        }
        
        if (empty($config['sequence'])) {
            return ['error' => 'sequence cannot be empty'];
        }
        
        foreach ($config['sequence'] as $index => $step) {
            if (!isset($step['template_id'])) {
                return ['error' => "Step $index missing template_id"];
            }
            
            if (!isset($step['delay_days']) && $step['delay_days'] !== 0) {
                return ['error' => "Step $index missing delay_days"];
            }
        }
        
        return true;
    }
    
    public function handleSubscription($subscriberData, $config) {
        $phoneNumber = $subscriberData[SENDY_PHONE_FIELD_NAME] ?? null;
        $name = $subscriberData['name'] ?? $subscriberData['email'] ?? 'Subscriber';
        $email = $subscriberData['email'];
        $listId = $subscriberData['list_id'];
        
        if (!$phoneNumber) {
            error_log("Drip sequence: Missing phone number for $email");
            return [
                'status' => 'error',
                'message' => 'Phone number is required'
            ];
        }
        
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }

        $queueItem = [
            'id' => uniqid('drip_', true),
            'email' => $email,
            'phone' => $phoneNumber,
            'name' => $name,
            'list_id' => $listId,
            'subscribed_at' => time(),
            'sequence' => $config['sequence'],
            'completed_steps' => [],
            'status' => 'active'
        ];
        
        $success = StorageService::append(DRIP_QUEUE_FILE, $queueItem);
        
        if ($success) {
            $stepCount = count($config['sequence']);
            error_log("Drip sequence: Queued $stepCount messages for $email");
            
            return [
                'status' => 'success',
                'message' => "Drip sequence queued with $stepCount steps",
                'mode' => 'drip_sequence',
                'drip_id' => $queueItem['id']
            ];
        } else {
            error_log("Drip sequence: Failed to queue for $email");
            
            return [
                'status' => 'error',
                'message' => 'Failed to queue drip sequence'
            ];
        }
    }
    
    /**
     * Process due drip messages (called by cron)
     */
    public static function processDripQueue() {
        $queue = StorageService::load(DRIP_QUEUE_FILE, []);
        $currentTime = time();
        $processed = 0;
        $sent = 0;
        $failed = 0;
        
        foreach ($queue as &$item) {
            if ($item['status'] !== 'active') {
                continue;
            }
            
            $processed++;
            $subscribedAt = $item['subscribed_at'];
            $completedSteps = $item['completed_steps'];
            
            foreach ($item['sequence'] as $stepIndex => $step) {
                if (in_array($stepIndex, $completedSteps)) {
                    continue;
                }
                
                $delaySeconds = $step['delay_days'] * 24 * 60 * 60;
                $sendTime = $subscribedAt + $delaySeconds;
                
                if ($currentTime >= $sendTime) {
                    try {
                        BeemService::sendTemplateMessage(
                            $item['phone'],
                            $step['template_id'],
                            []
                        );
                        
                        $item['completed_steps'][] = $stepIndex;
                        $sent++;
                        
                        $stepName = $step['name'] ?? "Step " . ($stepIndex + 1);
                        error_log("Drip processor: Sent '$stepName' to {$item['email']}");
                        
                    } catch (Exception $e) {
                        $failed++;
                        error_log("Drip processor: Failed to send step $stepIndex to {$item['email']}: " . $e->getMessage());
                    }
                }
            }
            
            if (count($item['completed_steps']) >= count($item['sequence'])) {
                $item['status'] = 'completed';
                error_log("Drip processor: Completed all steps for {$item['email']}");
            }
        }
        
        StorageService::save(DRIP_QUEUE_FILE, $queue);
        
        self::cleanupCompletedDrips();
        
        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed
        ];
    }
    
    /**
     * Remove completed drips older than 30 days
     */
    private static function cleanupCompletedDrips() {
        $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
        
        StorageService::filter(DRIP_QUEUE_FILE, function($item) use ($thirtyDaysAgo) {
            return $item['status'] === 'active' || 
                   ($item['status'] === 'completed' && $item['subscribed_at'] > $thirtyDaysAgo);
        });
    }
}