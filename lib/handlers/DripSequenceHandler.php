<?php
/**
 * Drip Sequence Handler - Updated for Beem API
 */

require_once __DIR__ . '/WebhookHandlerInterface.php';
require_once __DIR__ . '/../StorageService.php';
require_once __DIR__ . '/../BeemService.php';

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
            
            if (!isset($step['delay_minutes'])) {
                return ['error' => "Step $index missing delay_minutes"];
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
        
        // Clean and format phone number
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }

        // Calculate next send time for first step
        $firstStep = $config['sequence'][0];
        $nextSendAt = time() + ($firstStep['delay_minutes'] * 60);
        
        $queueItem = [
            'id' => uniqid('drip_', true),
            'email' => $email,
            'phone' => $phoneNumber,
            'name' => $name,
            'list_id' => $listId,
            'subscribed_at' => time(),
            'sequence' => $config['sequence'],
            'current_step' => 0,
            'completed_steps' => [],
            'status' => 'active',
            'next_send_at' => $nextSendAt,
            'last_processed' => null
        ];
        
        $success = StorageService::append(DRIP_QUEUE_FILE, $queueItem);
        
        if ($success) {
            $stepCount = count($config['sequence']);
            error_log("Drip sequence: Queued $stepCount messages for $email ($phoneNumber)");
            error_log("Drip sequence: First message scheduled for " . date('Y-m-d H:i:s', $nextSendAt));
            
            return [
                'status' => 'success',
                'message' => "Drip sequence queued with $stepCount steps",
                'mode' => 'drip_sequence',
                'drip_id' => $queueItem['id'],
                'first_message_at' => date('Y-m-d H:i:s', $nextSendAt)
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
        
        if (empty($queue)) {
            error_log("Drip processor: No active drip sequences found");
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0
            ];
        }
        
        foreach ($queue as &$item) {
            if ($item['status'] !== 'active') {
                continue;
            }
            
            if ($currentTime < $item['next_send_at']) {
                continue;
            }
            
            $processed++;
            $currentStep = $item['current_step'];
            
            if ($currentStep >= count($item['sequence'])) {
                $item['status'] = 'completed';
                error_log("Drip processor: Completed all steps for {$item['email']}");
                continue;
            }
            
            $step = $item['sequence'][$currentStep];
            
            try {
                $result = BeemService::sendTemplateMessage(
                    $item['phone'],
                    $step['template_id'],
                    $step['params'] ?? []
                );
                
                $sent++;
                $item['completed_steps'][] = $currentStep;
                $item['current_step']++;
                $item['last_processed'] = $currentTime;
                
                if ($item['current_step'] < count($item['sequence'])) {
                    $nextStep = $item['sequence'][$item['current_step']];
                    $item['next_send_at'] = $currentTime + ($nextStep['delay_minutes'] * 60);
                } else {
                    $item['status'] = 'completed';
                    $item['next_send_at'] = null;
                }
                
                $stepName = $step['name'] ?? "Step " . ($currentStep + 1);
                error_log("Drip processor: Sent '$stepName' to {$item['phone']} ({$item['email']})");
                
            } catch (Exception $e) {
                $failed++;
                error_log("Drip processor: Failed to send step $currentStep to {$item['email']}: " . $e->getMessage());
                
                $item['next_send_at'] = $currentTime + 300;
            }
        }
        
        StorageService::save(DRIP_QUEUE_FILE, $queue);
        
        self::cleanupCompletedDrips();
        
        error_log("Drip processor: Processed $processed, Sent: $sent, Failed: $failed");
        
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
        $removedCount = 0;
        
        StorageService::filter(DRIP_QUEUE_FILE, function($item) use ($thirtyDaysAgo, &$removedCount) {
            $keep = $item['status'] === 'active' || 
                   ($item['status'] === 'completed' && $item['subscribed_at'] > $thirtyDaysAgo);
            
            if (!$keep) {
                $removedCount++;
            }
            
            return $keep;
        });
        
        if ($removedCount > 0) {
            error_log("Drip cleanup: Removed $removedCount completed sequences older than 30 days");
        }
    }
    
    /**
     * Get drip sequence statistics
     */
    public static function getStats() {
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
                $stats['pending_messages'] += (count($item['sequence']) - $item['current_step']);
            } else if ($item['status'] === 'completed') {
                $stats['completed']++;
            }
        }
        
        return $stats;
    }
}