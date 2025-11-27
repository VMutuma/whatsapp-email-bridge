<?php
/**
 * Storage Service
 * Handles all file-based data storage operations
 * High cohesion: Only deals with storage
 * Loose coupling: No knowledge of business logic
 */

class StorageService {
    
    /**
     * Load JSON data from file
     */
    public static function load($filepath, $defaultValue = []) {
        if (!file_exists($filepath)) {
            return $defaultValue;
        }
        
        $content = file_get_contents($filepath);
        $data = json_decode($content, true);
        
        return $data ?? $defaultValue;
    }
    
    /**
     * Save data to JSON file
     */
    public static function save($filepath, $data) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        $result = file_put_contents($filepath, $json, LOCK_EX);
        
        if ($result === false) {
            error_log("Failed to save data to: $filepath");
            return false;
        }
        
        return true;
    }
    
    /**
     * Atomic update: Load, modify, save
     */
    public static function update($filepath, callable $modifier, $defaultValue = []) {
        $data = self::load($filepath, $defaultValue);
        $modifiedData = $modifier($data);
        return self::save($filepath, $modifiedData);
    }
    
    /**
     * Append item to array in file
     */
    public static function append($filepath, $item) {
        return self::update($filepath, function($data) use ($item) {
            $data[] = $item;
            return $data;
        }, []);
    }
    
    /**
     * Remove items matching condition
     */
    public static function filter($filepath, callable $condition) {
        return self::update($filepath, function($data) use ($condition) {
            return array_values(array_filter($data, $condition));
        }, []);
    }
    
    /**
     * Get template configuration for a list
     */
    public static function getListConfig($listId) {
        $map = self::load(TEMPLATE_MAP_FILE, []);
        
        if (!isset($map[$listId])) {
            return null;
        }
        
        $config = $map[$listId];
        
        // Backward compatibility: convert old format to new format
        if (is_string($config)) {
            return [
                'mode' => 'single',
                'template_id' => $config
            ];
        }
        
        return $config;
    }
    
    /**
     * Save template configuration for a list
     */
    public static function saveListConfig($listId, $config) {
        return self::update(TEMPLATE_MAP_FILE, function($map) use ($listId, $config) {
            $map[$listId] = $config;
            return $map;
        }, []);
    }

    /**
     * Generate unique token for webhook
     */

    public static function generateToken() {
        return bin2hex(random_bytes(8));
    }

    /**
     * Save webhook configuration with token
     */
    public static function saveWebhookConfig($token, $config) {
        return self::update(TEMPLATE_MAP_FILE, function($map) use ($token, $config) {
            if (!isset($map['webhooks'])) {
                $map['webhooks'] = [];
            }  
            $map['webhooks'][$token] = $config;
            return $map;
        }, []);
    }

    /**
     * Get webhook configuration by token
     */
    public static function getWebhookConfigByToken($token) {
        $map = self::load(TEMPLATE_MAP_FILE, []);
        return $map['webhooks'][$token] ?? null;
    }

    /**
     * Get all webhook configurations
     */
    public static function getAllWebhooks() {
        $map = self::load(TEMPLATE_MAP_FILE, []);
        return $map['webhooks'] ?? [];
    }

    /**
     * Delete webhook configuration by token
     */
    public static function deleteWebhook($token) {
        return self::update(TEMPLATE_MAP_FILE, function($map) use ($token) {
            if (isset($map['webhooks'][$token])) {
                unset($map['webhooks'][$token]);
            }
            return $map;
        }, []);
    }

    /**
     * Check if token exists
     */
    public static function tokenExists($token) {
        $map = self::load(TEMPLATE_MAP_FILE, []);
        return isset($map['webhooks'][$token]);
    }
}