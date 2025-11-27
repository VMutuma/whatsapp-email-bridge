<?php
/**
 * Sendy Service
 * Handles all Sendy API interactions
 * High cohesion: Only deals with Sendy API
 * Loose coupling: Returns standard arrays, no business logic
 */

class SendyService {
    
    /**
     * Make POST request to Sendy API
     */
    private static function callApi($url, $params) {
        $ch = curl_init($url);
        $postFields = http_build_query($params);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Sendy API Network Error: $error");
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("Sendy API HTTP Error $httpCode: $response");
        }
        
        $decoded = json_decode($response, true);
        return $decoded ?? $response;
    }
    
    /**
     * Get all brands
     */
    public static function getBrands() {
        $response = self::callApi(SENDY_GET_BRANDS_URL, [
            'api_key' => SENDY_API_KEY
        ]);
        
        if (!is_array($response)) {
            return [];
        }
        
        $brands = [];
        foreach ($response as $brand) {
            if (isset($brand['id'], $brand['name'])) {
                $brands[] = [
                    'id' => $brand['id'],
                    'name' => $brand['name']
                ];
            }
        }
        
        return $brands;
    }
    
    /**
     * Get lists for a brand
     */
    public static function getLists($brandId) {
        $response = self::callApi(SENDY_GET_LISTS_URL, [
            'api_key' => SENDY_API_KEY,
            'brand_id' => $brandId
        ]);
        
        if (!is_array($response)) {
            return [];
        }
        
        $lists = [];
        foreach ($response as $list) {
            if (isset($list['id'], $list['name'])) {
                $lists[] = [
                    'id' => $list['id'],
                    'name' => $list['name']
                ];
            }
        }
        
        return $lists;
    }
    
    /**
     * Get subscriber details (if needed in future)
     */
    public static function getSubscriberStatus($email, $listId) {
        $url = SENDY_URL . '/api/subscribers/subscription-status.php';
        
        try {
            $response = self::callApi($url, [
                'api_key' => SENDY_API_KEY,
                'email' => $email,
                'list_id' => $listId
            ]);
            
            return $response;
        } catch (Exception $e) {
            error_log("Failed to get subscriber status: " . $e->getMessage());
            return null;
        }
    }
}