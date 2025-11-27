<?php
/**
 * Webhook Handler Interface
 * Defines contract for all webhook handlers
 * Enforces loose coupling through interface
 */

interface WebhookHandlerInterface {
    
    /**
     * Handle subscription event from Sendy
     * 
     * @param array $subscriberData Data from Sendy webhook
     * @param array $config Configuration for this list
     * @return array Response with status and message
     */
    public function handleSubscription($subscriberData, $config);
    
    /**
     * Get the mode this handler supports
     * 
     * @return string Mode identifier (e.g., 'single', 'drip_sequence', 'mirror_autoresponder')
     */
    public function getMode();
    
    /**
     * Validate configuration for this handler
     * 
     * @param array $config Configuration to validate
     * @return bool|array True if valid, array with errors if invalid
     */
    public function validateConfig($config);
}