<?php
/**
 * Autoresponder Mirror Handler
 * Handles WhatsApp messages that mirror Sendy autoresponders
 * High cohesion: Only deals with autoresponder syncing
 * Loose coupling: Uses services, implements interface
 */

require_once __DIR__ . '/WebhookHandlerInterface.php';
require_once __DIR__ . '/../BeemService.php';
require_once __DIR__ . '/../StorageService.php';

class AutoresponderMirrorHandler implements WebhookHandlerInterface {
    
    public function getMode() {
        return 'mirror_autoresponder';
    }
    
    public function validateConfig($config) {
        if (!isset($config['autoresponder_id']) || empty($config['autoresponder_id'])) {
            return ['error' => 'autoresponder_id is required for mirror_autoresponder mode'];
        }
        
        if (!isset($config['template_map']) || !is_array($config['template_map'])) {
            return ['error' => 'template_map is required for mirror_autoresponder mode'];
        }
        
        if (empty($config['template_map'])) {
            return ['error' => 'template_map cannot be empty'];
        }
        
        return true;
    }
    
    public function handleSubscription($subscriberData, $config) {
        // For mirror mode, we don't send anything on subscription
        // We wait for Sendy to trigger autoresponder webhooks
        
        $email = $subscriberData['email'];
        $phoneNumber = $subscriberData[SENDY_PHONE_FIELD_NAME] ?? null;
        
        if (!$phoneNumber) {
            error_log("Mirror mode: Missing phone number for $email - autoresponder messages will fail");
            return [
                'status' => 'warning',
                'message' => 'Subscribed but phone number missing - autoresponder WhatsApp messages will fail',
                'mode' => 'mirror_autoresponder'
            ];
        }
        
        $this->storeSubscriberInfo($subscriberData);
        
        error_log("Mirror mode: Subscribed $email - waiting for autoresponder triggers");
        
        return [
            'status' => 'success',
            'message' => 'Subscribed - WhatsApp will be sent when autoresponder triggers',
            'mode' => 'mirror_autoresponder',
            'autoresponder_id' => $config['autoresponder_id']
        ];
    }
    
    /**
     * Store subscriber info for later retrieval
     */
    private function storeSubscriberInfo($subscriberData) {
        $subscriberCache = [
            'email' => $subscriberData['email'],
            'phone' => preg_replace('/[^0-9+]/', '', $subscriberData[SENDY_PHONE_FIELD_NAME] ?? ''),
            'name' => $subscriberData['name'] ?? $subscriberData['email'] ?? 'Subscriber',
            'list_id' => $subscriberData['list_id'],
            'updated_at' => time()
        ];
        
        $cacheFile = DATA_DIR . '/subscriber_cache.json';
        
        StorageService::update($cacheFile, function($cache) use ($subscriberCache) {
            $email = $subscriberCache['email'];
            $cache[$email] = $subscriberCache;
            return $cache;
        }, []);
    }
    
    /**
     * Handle autoresponder webhook from Sendy
     */
    public static function handleAutoresponderTrigger($webhookData) {
        $email = $webhookData['email'] ?? null;
        $listId = $webhookData['list_id'] ?? null;
        $autoresponderStep = $webhookData['autoresponder_step'] ?? 1;
        
        if (!$email || !$listId) {
            error_log("Mirror mode: Invalid autoresponder webhook data");
            return [
                'status' => 'error',
                'message' => 'Missing email or list_id in webhook data'
            ];
        }
        
        $config = StorageService::getListConfig($listId);
        
        if (!$config || $config['mode'] !== 'mirror_autoresponder') {
            error_log("Mirror mode: List $listId not configured for mirror mode");
            return [
                'status' => 'error',
                'message' => 'List not configured for autoresponder mirroring'
            ];
        }
        
        $templateId = $config['template_map'][$autoresponderStep] ?? null;
        
        if (!$templateId) {
            error_log("Mirror mode: No WhatsApp template mapped for step $autoresponderStep");
            return [
                'status' => 'error',
                'message' => "No WhatsApp template configured for autoresponder step $autoresponderStep"
            ];
        }
        
        $subscriberInfo = self::getSubscriberInfo($email);
        
        if (!$subscriberInfo || !$subscriberInfo['phone']) {
            error_log("Mirror mode: No phone number found for $email");
            return [
                'status' => 'error',
                'message' => 'Phone number not found for subscriber'
            ];
        }
        
        try {
            $result = BeemService::sendTemplateMessage(
                $subscriberInfo['phone'],
                $templateId,
                []
            );
            
            error_log("Mirror mode: Sent autoresponder step $autoresponderStep to $email (Job ID: {$result['job_id']})");
            
            return [
                'status' => 'success',
                'message' => 'WhatsApp message sent',
                'job_id' => $result['job_id'],
                'step' => $autoresponderStep,
                'mode' => 'mirror_autoresponder'
            ];
            
        } catch (Exception $e) {
            error_log("Mirror mode: Failed to send autoresponder step $autoresponderStep to $email - " . $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => 'Failed to send WhatsApp message',
                'details' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Retrieve subscriber info from cache
     */
    private static function getSubscriberInfo($email) {
        $cacheFile = DATA_DIR . '/subscriber_cache.json';
        $cache = StorageService::load($cacheFile, []);
        
        return $cache[$email] ?? null;
    }
}