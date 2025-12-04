<?php
/**
 * Hybrid Drip Handler
 * Manages unified drip campaigns that alternate between Email (Sendy) and WhatsApp (Beem)
 * 
 * Example flow:
 * Step 1: Email (Sendy autoresponder) - immediately
 * Step 2: WhatsApp - after 2 hours
 * Step 3: Email - after 1 day
 * Step 4: WhatsApp - after 3 days
 */

require_once __DIR__ . '/WebhookHandlerInterface.php';
require_once __DIR__ . '/../StorageService.php';
require_once __DIR__ . '/../BeemService.php';
require_once __DIR__ . '/../SendyService.php';

class HybridDripHandler implements WebhookHandlerInterface {
    
    public function getMode() {
        return 'hybrid_drip';
    }
    
    /**
     * Convert delay with unit to minutes
     */
    private function convertDelayToMinutes($delay, $unit = 'days') {
        $conversions = [
            'minutes' => 1,
            'hours' => 60,
            'days' => 1440,
            'weeks' => 10080,
            'months' => 43200
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
            return ['error' => 'sequence array is required for hybrid_drip mode'];
        }
        
        if (empty($config['sequence'])) {
            return ['error' => 'sequence cannot be empty'];
        }
        
        $validChannels = ['email', 'whatsapp'];
        $validUnits = ['minutes', 'hours', 'days', 'weeks', 'months'];
        
        foreach ($config['sequence'] as $index => $step) {
            // Validate channel
            if (!isset($step['channel'])) {
                return ['error' => "Step $index missing channel (email or whatsapp)"];
            }
            
            if (!in_array(strtolower($step['channel']), $validChannels)) {
                return ['error' => "Step $index has invalid channel. Must be 'email' or 'whatsapp'"];
            }
            
            // Validate based on channel
            if (strtolower($step['channel']) === 'whatsapp') {
                if (!isset($step['template_id'])) {
                    return ['error' => "Step $index (WhatsApp) missing template_id"];
                }
            } else {
                // Email step - we'll send via Sendy autoresponder
                // No template_id needed, just track that it's an email step
                if (!isset($step['subject'])) {
                    return ['error' => "Step $index (Email) missing subject"];
                }
            }
            
            // Validate delay
            if (!isset($step['delay'])) {
                return ['error' => "Step $index missing delay"];
            }
            
            // Validate delay_unit if provided
            if (isset($step['delay_unit'])) {
                $unit = strtolower($step['delay_unit']);
                if (!in_array($unit, $validUnits)) {
                    return ['error' => "Step $index has invalid delay_unit"];
                }
            }
        }
        
        return true;
    }
    
    public function handleSubscription($subscriberData, $config) {
        $phoneNumber = $subscriberData[SENDY_PHONE_FIELD_NAME] ?? null;
        $name = $subscriberData['name'] ?? $subscriberData['email'] ?? 'Subscriber';
        $email = $subscriberData['email'];
        $listId = $subscriberData['list_id'];
        
        // Check if phone is required (has WhatsApp steps)
        $hasWhatsAppSteps = false;
        foreach ($config['sequence'] as $step) {
            if (strtolower($step['channel']) === 'whatsapp') {
                $hasWhatsAppSteps = true;
                break;
            }
        }
        
        if ($hasWhatsAppSteps && !$phoneNumber) {
            error_log("Hybrid drip: Missing phone number for $email (has WhatsApp steps)");
            return [
                'status' => 'error',
                'message' => 'Phone number is required for campaigns with WhatsApp steps'
            ];
        }
        
        // Clean and format phone number if provided
        if ($phoneNumber) {
            $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
            if (!str_starts_with($phoneNumber, '+')) {
                $phoneNumber = '+' . $phoneNumber;
            }
        }

        // Calculate next send time for first step
        $firstStep = $config['sequence'][0];
        $delay = $firstStep['delay'];
        $unit = $firstStep['delay_unit'] ?? 'days';
        $delayInMinutes = $this->convertDelayToMinutes($delay, $unit);
        $nextSendAt = time() + ($delayInMinutes * 60);
        
        $queueItem = [
            'id' => uniqid('hybrid_', true),
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
        
        $success = StorageService::append(HYBRID_QUEUE_FILE, $queueItem);
        
        if ($success) {
            $stepCount = count($config['sequence']);
            $whatsappCount = count(array_filter($config['sequence'], fn($s) => strtolower($s['channel']) === 'whatsapp'));
            $emailCount = $stepCount - $whatsappCount;
            
            error_log("Hybrid drip: Queued $stepCount steps for $email ($emailCount email, $whatsappCount WhatsApp)");
            error_log("Hybrid drip: First message ({$firstStep['channel']}) at " . date('Y-m-d H:i:s', $nextSendAt));
            
            return [
                'status' => 'success',
                'message' => "Hybrid drip sequence queued with $stepCount steps ($emailCount email, $whatsappCount WhatsApp)",
                'mode' => 'hybrid_drip',
                'drip_id' => $queueItem['id'],
                'first_message_at' => date('Y-m-d H:i:s', $nextSendAt),
                'email_steps' => $emailCount,
                'whatsapp_steps' => $whatsappCount
            ];
        } else {
            error_log("Hybrid drip: Failed to queue for $email");
            
            return [
                'status' => 'error',
                'message' => 'Failed to queue hybrid drip sequence'
            ];
        }
    }
    
    /**
     * Process due hybrid drip messages (called by cron)
     */
    public static function processHybridQueue() {
        $queue = StorageService::load(HYBRID_QUEUE_FILE, []);
        $currentTime = time();
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $emailSent = 0;
        $whatsappSent = 0;
        
        if (empty($queue)) {
            error_log("Hybrid processor: No active hybrid sequences found");
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'email_sent' => 0,
                'whatsapp_sent' => 0
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
                error_log("Hybrid processor: Completed all steps for {$item['email']}");
                continue;
            }
            
            $step = $item['sequence'][$currentStep];
            $channel = strtolower($step['channel']);
            
            try {
                if ($channel === 'whatsapp') {
                    // Send WhatsApp via Beem
                    $result = BeemService::sendTemplateMessage(
                        $item['phone'],
                        $step['template_id'],
                        $step['params'] ?? []
                    );
                    
                    $whatsappSent++;
                    error_log("Hybrid processor: Sent WhatsApp to {$item['phone']} (Job ID: {$result['job_id']})");
                    
                } else {
                    // Send Email via Sendy
                    $result = self::sendEmailViaAPI($item, $step);
                    
                    $emailSent++;
                    error_log("Hybrid processor: Triggered email to {$item['email']}");
                }
                
                $sent++;
                $item['completed_steps'][] = $currentStep;
                $item['current_step']++;
                $item['last_processed'] = $currentTime;
                
                // Calculate next send time if there are more steps
                if ($item['current_step'] < count($item['sequence'])) {
                    $nextStep = $item['sequence'][$item['current_step']];
                    $delay = $nextStep['delay'];
                    $unit = $nextStep['delay_unit'] ?? 'days';
                    $delayInMinutes = self::convertDelayToMinutesStatic($delay, $unit);
                    
                    $item['next_send_at'] = $currentTime + ($delayInMinutes * 60);
                } else {
                    $item['status'] = 'completed';
                    $item['next_send_at'] = null;
                }
                
                $stepName = $step['name'] ?? "Step " . ($currentStep + 1);
                error_log("Hybrid processor: Completed '$stepName' ($channel) for {$item['email']}");
                
            } catch (Exception $e) {
                $failed++;
                error_log("Hybrid processor: Failed step $currentStep ($channel) for {$item['email']}: " . $e->getMessage());
                
                // Retry after 5 minutes
                $item['next_send_at'] = $currentTime + 300;
            }
        }
        
        StorageService::save(HYBRID_QUEUE_FILE, $queue);
        
        self::cleanupCompletedHybrids();
        
        error_log("Hybrid processor: Processed $processed, Sent: $sent (Email: $emailSent, WhatsApp: $whatsappSent), Failed: $failed");
        
        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'email_sent' => $emailSent,
            'whatsapp_sent' => $whatsappSent
        ];
    }
    
    /**
     * Send email via Sendy API
     */
    /**
     * Send email via Sendy API
     */
    private static function sendEmailViaAPI($subscriber, $step) {
        $sendyUrl = defined('SENDY_URL') ? SENDY_URL : '';
        $sendyApiKey = defined('SENDY_API_KEY') ? SENDY_API_KEY : '';
        
        if (empty($sendyUrl) || empty($sendyApiKey)) {
            throw new Exception("Sendy URL or API key not configured");
        }
        
        // Prepare campaign data
        $postData = [
            'api_key' => $sendyApiKey,
            'from_name' => $step['from_name'] ?? defined('DEFAULT_FROM_NAME') ? DEFAULT_FROM_NAME : 'Support',
            'from_email' => $step['from_email'] ?? defined('DEFAULT_FROM_EMAIL') ? DEFAULT_FROM_EMAIL : 'noreply@example.com',
            'reply_to' => $step['reply_to'] ?? $step['from_email'] ?? defined('DEFAULT_FROM_EMAIL') ? DEFAULT_FROM_EMAIL : 'noreply@example.com',
            'title' => $step['title'] ?? ($step['subject'] . ' - ' . date('Y-m-d H:i:s')),
            'subject' => $step['subject'],
            'html_text' => $step['html_text'] ?? $step['content'] ?? '',
            'list_ids' => $step['list_id'] ?? $subscriber['list_id'],
            'track_opens' => $step['track_opens'] ?? 1,
            'track_clicks' => $step['track_clicks'] ?? 1,
            'send_campaign' => 1
        ];
        
        // Add plain text version if provided
        if (isset($step['plain_text'])) {
            $postData['plain_text'] = $step['plain_text'];
        }
        
        // Add query string (UTM parameters) if provided
        if (isset($step['query_string'])) {
            $postData['query_string'] = $step['query_string'];
        }
        
        // Personalize content with subscriber data
        $postData['html_text'] = self::personalizeContent($postData['html_text'], $subscriber);
        $postData['subject'] = self::personalizeContent($postData['subject'], $subscriber);
        if (isset($postData['plain_text'])) {
            $postData['plain_text'] = self::personalizeContent($postData['plain_text'], $subscriber);
        }
        
        // Make API request
        $ch = curl_init($sendyUrl . '/api/campaigns/create.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Sendy API request failed: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Sendy API returned HTTP $httpCode");
        }
        
        // Parse response
        $response = trim($response);
        
        if (strpos($response, 'Campaign created and now sending') !== false) {
            error_log("Hybrid: Successfully sent email '{$step['subject']}' to {$subscriber['email']} via Sendy");
            return ['success' => true, 'message' => $response];
        } else if (strpos($response, 'Campaign created') !== false) {
            error_log("Hybrid: Email campaign created but may be pending: {$subscriber['email']}");
            return ['success' => true, 'message' => $response];
        } else {
            throw new Exception("Sendy API error: $response");
        }
    }
    
    /**
     * Personalize email content with subscriber data
     */
    private static function personalizeContent($content, $subscriber) {
        $replacements = [
            '[name]' => $subscriber['name'] ?? '',
            '[email]' => $subscriber['email'] ?? '',
            '[Name]' => $subscriber['name'] ?? '',
            '[Email]' => $subscriber['email'] ?? '',
            '{name}' => $subscriber['name'] ?? '',
            '{email}' => $subscriber['email'] ?? '',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * Static version of convertDelayToMinutes
     */
    private static function convertDelayToMinutesStatic($delay, $unit = 'days') {
        $conversions = [
            'minutes' => 1,
            'hours' => 60,
            'days' => 1440,
            'weeks' => 10080,
            'months' => 43200
        ];
        
        $unit = strtolower($unit);
        
        if (!isset($conversions[$unit])) {
            error_log("Invalid time unit '$unit', defaulting to days");
            $unit = 'days';
        }
        
        return $delay * $conversions[$unit];
    }
    
    /**
     * Remove completed hybrids older than 30 days
     */
    private static function cleanupCompletedHybrids() {
        $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
        $removedCount = 0;
        
        StorageService::filter(HYBRID_QUEUE_FILE, function($item) use ($thirtyDaysAgo, &$removedCount) {
            $keep = $item['status'] === 'active' || 
                   ($item['status'] === 'completed' && $item['subscribed_at'] > $thirtyDaysAgo);
            
            if (!$keep) {
                $removedCount++;
            }
            
            return $keep;
        });
        
        if ($removedCount > 0) {
            error_log("Hybrid cleanup: Removed $removedCount completed sequences older than 30 days");
        }
    }
    
    /**
     * Get hybrid drip statistics
     */
    public static function getStats() {
        $queue = StorageService::load(HYBRID_QUEUE_FILE, []);
        
        $stats = [
            'total' => count($queue),
            'active' => 0,
            'completed' => 0,
            'pending_messages' => 0,
            'pending_email' => 0,
            'pending_whatsapp' => 0
        ];
        
        foreach ($queue as $item) {
            if ($item['status'] === 'active') {
                $stats['active']++;
                
                // Count remaining messages by channel
                for ($i = $item['current_step']; $i < count($item['sequence']); $i++) {
                    $stats['pending_messages']++;
                    $channel = strtolower($item['sequence'][$i]['channel']);
                    if ($channel === 'email') {
                        $stats['pending_email']++;
                    } else {
                        $stats['pending_whatsapp']++;
                    }
                }
            } else if ($item['status'] === 'completed') {
                $stats['completed']++;
            }
        }
        
        return $stats;
    }
}