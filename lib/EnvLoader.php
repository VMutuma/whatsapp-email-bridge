<?php
/**
 * Simple .env file loader
 * Loads environment variables from .env file
 */

class EnvLoader {
    
    public static function load($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception(".env file not found at: $filePath");
        }
        
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                $value = trim($value, '"\'');
                
                // Set as environment variable and in $_ENV
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
    
    public static function get($key, $default = null) {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}