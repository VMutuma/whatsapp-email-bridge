<?php
/**
 * Single Message Handler
 * Handles original behavior: send one WhatsApp message immediately on subscription
 * High cohesion: Only handles single message sending
 * Loose coupling: Uses services, implements interface
 */

require_once __DIR__ . '/WebhookHandlerInterface.php';
require_once __DIR__ . '/../BeemService.php';

class SingleMessageHandler implements WebhookHandlerInterface {
    
    public function getMode() {
        return 'single';
    }
    
    public function validateConfig($config) {
        if (!isset($config['template_id']) || empty($config['template_id'])) {
            return ['error' => 'template_id is required for single mode'];
        }
        
        return true;
    }
    
    public function handleSubscription($subscriberData, $config) {
        $phoneNumber = $subscriberData[SENDY_PHONE_FIELD_NAME] ?? null;
        $name = $subscriberData['name'] ?? $subscriberData['email'] ?? 'Subscriber';
        $email = $subscriberData['email'];
        
        if (!$phoneNumber) {
            error_log("Single mode: Missing phone number for $email");
            return [
                'status' => 'error',
                'message' => 'Phone number is required'
            ];
        }
        
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }
        
        try {
            $result = BeemService::sendTemplateMessage(
                $phoneNumber,
                $config['template_id'],
                [] 
            );
            
            error_log("Single mode: Sent WhatsApp to $email (Job ID: {$result['job_id']})");
            
            return [
                'status' => 'success',
                'message' => 'WhatsApp message sent',
                'job_id' => $result['job_id'],
                'mode' => 'single'
            ];
            
        } catch (Exception $e) {
            error_log("Single mode: Failed to send WhatsApp to $email - " . $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => 'Failed to send WhatsApp message',
                'details' => $e->getMessage()
            ];
        }
    }
}