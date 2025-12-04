<?php
/**
 * Drip Sequence Handler - Enhanced with Multiple Time Units
 */

require_once __DIR__ . '/WebhookHandlerInterface.php';
require_once __DIR__ . '/../StorageService.php';
require_once __DIR__ . '/../BeemService.php';

class DripSequenceHandler implements WebhookHandlerInterface {
    
    public function getMode() {
        return 'drip_sequence';
    }
    
    /**
     * Convert delay with unit to minutes
     * 
     * @param int $delay Delay value
     * @param string $unit Time unit: 'minutes', 'hours', 'days', 'weeks', 'months'
     * @return int Delay in minutes
     */
    public static function convertDelayToMinutes($delay, $unit = 'days') {
        $conversions = [
            'minutes' => 1,
            'hours' => 60,
            'days' => 1440,           // 60 * 24
            'weeks' => 10080,         // 60 * 24 * 7
            'months' => 43200         // 60 * 24 * 30 (approximate)
        ];
        
        $unit = strtolower($unit);
        
        if (!isset($conversions[$unit])) {
            error_log("Invalid time unit '$unit', defaulting to days");
            $unit = 'days';
        }
        
        return $delay * $conversions[$unit];
    }
    
    public function validateConfig($config) {
        if (!isset($config['sequence']) || !is_array($config['sequence'])) {
            return ['error' => 'sequence array is required for drip_sequence mode'];
        }
        
        if (empty($config['sequence'])) {
            return ['error' => 'sequence cannot be empty'];
        }
        
        $validUnits = ['minutes', 'hours', 'days', 'weeks', 'months'];
        
        foreach ($config['sequence'] as $index => $step) {
            if (!isset($step['template_id'])) {
                return ['error' => "Step $index missing template_id"];
            }
            
            // Support both old format (delay_minutes) and new format (delay + delay_unit)
            if (!isset($step['delay_minutes']) && !isset($step['delay'])) {
                return ['error' => "Step $index missing delay or delay_minutes"];
            }
            
            // Validate delay_unit if provided
            if (isset($step['delay_unit'])) {
                $unit = strtolower($step['delay_unit']);
                if (!in_array($unit, $validUnits)) {
                    return ['error' => "Step $index has invalid delay_unit. Must be one of: " . implode(', ', $validUnits)];
                }
            }
            
            // Validate delay value
            $delayValue = $step['delay'] ?? $step['delay_minutes'];
            if (!is_numeric($delayValue) || $delayValue < 0) {
                return ['error' => "Step $index has invalid delay value"];
            }
        }
        
        return true;
    }
    
    public function handleSubscription($subscriberData, $config) {
        $phoneNumber = $subscriberData[SENDY_PHONE_FIELD_NAME] ?? null;
        $name = $subscriberData['name'] ?? $subscriberData['email'] ?? 'Subscriber';
        $email = $subscriberData['email'];
        $listId = $subscriberData['list_id'];
        
        error_log("=== DRIP SUBSCRIPTION DEBUG ===");
        error_log("Email: $email");
        error_log("Phone: " . ($phoneNumber ?: 'MISSING'));
        error_log("List ID: $listId");
        error_log("Config: " . json_encode($config));
        
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

        // Calculate next send time for first step with time unit support
        $firstStep = $config['sequence'][0];
        
        // Support both old format (delay_minutes) and new format (delay + delay_unit)
        if (isset($firstStep['delay_minutes'])) {
            $delayInMinutes = $firstStep['delay_minutes'];
        } else {
            $delay = $firstStep['delay'];
            $unit = $firstStep['delay_unit'] ?? 'days';
            $delayInMinutes = self::convertDelayToMinutes($delay, $unit);
        }
        
        $nextSendAt = time() + ($delayInMinutes * 60);
        
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

        error_log("=== QUEUE APPEND RESULT ===");
        error_log("Success: " . ($success ? 'YES' : 'NO'));
        error_log("Queue file: " . DRIP_QUEUE_FILE);
        error_log("Item ID: " . $queueItem['id']);

        if (!$success) {
            error_log("ERROR: Append failed!");
            error_log("File exists: " . (file_exists(DRIP_QUEUE_FILE) ? 'YES' : 'NO'));
            error_log("Writable: " . (is_writable(DRIP_QUEUE_FILE) ? 'YES' : 'NO'));
        }

        // Verify it was saved
        $verify = StorageService::load(DRIP_QUEUE_FILE, []);
        error_log("Queue count after append: " . count($verify));


        
        if ($success) {
            $stepCount = count($config['sequence']);
            $delayText = $this->formatDelay($firstStep);
            error_log("Drip sequence: Queued $stepCount messages for $email ($phoneNumber)");
            error_log("Drip sequence: First message in $delayText at " . date('Y-m-d H:i:s', $nextSendAt));
            
            return [
                'status' => 'success',
                'message' => "Drip sequence queued with $stepCount steps",
                'mode' => 'drip_sequence',
                'drip_id' => $queueItem['id'],
                'first_message_at' => date('Y-m-d H:i:s', $nextSendAt),
                'first_message_delay' => $delayText
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
     * Format delay for human-readable output
     */
    private function formatDelay($step) {
        if (isset($step['delay_minutes'])) {
            return $step['delay_minutes'] . ' minutes';
        }
        
        $delay = $step['delay'];
        $unit = $step['delay_unit'] ?? 'days';
        
        // Add proper pluralization
        if ($delay == 1) {
            $unit = rtrim($unit, 's'); // Remove 's' for singular
        }
        
        return "$delay $unit";
    }
    
    /**
     * Process due drip messages (called by cron)
     */
    public static function processDripQueue() {
        error_log("=== STARTING DRIP QUEUE PROCESSING ===");
        $queue = StorageService::load(DRIP_QUEUE_FILE, []);
        $currentTime = time();
        $processed = 0;
        $sent = 0;
        $failed = 0;
        
        error_log("Current time: " . date('Y-m-d H:i:s', $currentTime));
        error_log("Queue items loaded: " . count($queue));
        
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
                error_log("Skipping {$item['email']} - status: {$item['status']}");
                continue;
            }
            
            error_log("Checking {$item['email']} - Next send: " . date('Y-m-d H:i:s', $item['next_send_at']));
            
            if ($currentTime < $item['next_send_at']) {
                error_log("Not yet due - time left: " . ($item['next_send_at'] - $currentTime) . " seconds");
                continue;
            }
            
            $processed++;
            $currentStep = $item['current_step'];
            
            error_log("Processing {$item['email']} - Step {$currentStep}");
            
            if ($currentStep >= count($item['sequence'])) {
                $item['status'] = 'completed';
                error_log("Drip processor: Completed all steps for {$item['email']}");
                continue;
            }
            
            $step = $item['sequence'][$currentStep];
            
            try {
                error_log("Sending step {$currentStep} to {$item['phone']} with template: {$step['template_id']}");
                
                $result = BeemService::sendTemplateMessage(
                    $item['phone'],
                    $step['template_id'],
                    $step['params'] ?? []
                );
                
                error_log("BeemService response: " . json_encode($result));
                
                $sent++;
                $item['completed_steps'][] = $currentStep;
                $item['current_step']++;
                $item['last_processed'] = $currentTime;
                
                // Calculate next send time if there are more steps
                if ($item['current_step'] < count($item['sequence'])) {
                    $nextStep = $item['sequence'][$item['current_step']];
                    
                    // Support both formats
                    if (isset($nextStep['delay_minutes'])) {
                        $delayInMinutes = $nextStep['delay_minutes'];
                    } else {
                        $delay = $nextStep['delay'];
                        $unit = $nextStep['delay_unit'] ?? 'days';
                        $delayInMinutes = self::convertDelayToMinutes($delay, $unit);
                    }
                    
                    $item['next_send_at'] = $currentTime + ($delayInMinutes * 60);
                    error_log("Next step scheduled for: " . date('Y-m-d H:i:s', $item['next_send_at']));
                } else {
                    $item['status'] = 'completed';
                    $item['next_send_at'] = null;
                    error_log("All steps completed for {$item['email']}");
                }
                
                $stepName = $step['name'] ?? "Step " . ($currentStep + 1);
                error_log("Drip processor: Sent '$stepName' to {$item['phone']} ({$item['email']})");
                
            } catch (Exception $e) {
                $failed++;
                error_log("Drip processor: Failed to send step $currentStep to {$item['email']}: " . $e->getMessage());
                
                // Retry after 5 minutes
                $item['next_send_at'] = $currentTime + 300;
                error_log("Retry scheduled for: " . date('Y-m-d H:i:s', $item['next_send_at']));
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