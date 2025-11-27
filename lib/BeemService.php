<?php
/**
 * Beem Service
 * Handles all Beem API interactions
 * High cohesion: Only deals with Beem API
 * Loose coupling: Returns standard arrays, no business logic
 */

class BeemService {
    
    private static function getAuthHeader() {
        return 'Basic ' . base64_encode(BEEM_API_KEY . ':' . BEEM_SECRET_KEY);
    }
    
    /**
     * Make authenticated request to Beem ChatCore API
     */
    private static function callChatCoreApi($endpoint, $params = []) {
        $queryString = http_build_query($params);
        $url = BEEM_API_BASE_URL . $endpoint . ($queryString ? '?' . $queryString : '');
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . self::getAuthHeader()
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Beem API Network Error: $error");
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("Beem API HTTP Error $httpCode: $response");
        }
        
        $decoded = json_decode($response, true);
        
        if ($decoded === null) {
            throw new Exception("Invalid JSON response from Beem API");
        }
        
        return $decoded;
    }
    
    /**
     * Get list of WhatsApp templates
     */
    public static function getTemplates() {
        $response = self::callChatCoreApi('/v1/message-templates', [
            'page' => 1,
            'user_id' => BEEM_USER_ID_FOR_TEMPLATES,
            'app_name' => 'CHAT'
        ]);
        
        if (!isset($response['data']) || !is_array($response['data'])) {
            return [];
        }
        
        $templates = [];
        
        foreach ($response['data'] as $t) {
            $isApproved = false;
            if (isset($t['metadata']) && is_array($t['metadata'])) {
                foreach ($t['metadata'] as $meta) {
                    if (isset($meta['status']['approved']) && $meta['status']['approved'] === true) {
                        $isApproved = true;
                        break;
                    }
                }
            }
            
            if ($t['status'] !== 'enabled' || !$isApproved) {
                continue;
            }
            
            $placeholderCount = 0;
            if (isset($t['content'])) {
                preg_match_all('/\{\{(\d+)\}\}/', $t['content'], $matches);
                $placeholderCount = count(array_unique($matches[1]));
            }
            
            $templates[] = [
                'id' => $t['id'],
                'template_id' => $t['template_id'] ?? null,
                'name' => $t['name'],
                'content' => $t['content'] ?? '',
                'category' => $t['category'],
                'type' => $t['type'] ?? 'text',
                'language' => $t['language'] ?? 'en',
                'placeholders' => $placeholderCount,
                'mediaUrl' => $t['mediaUrl'] ?? null,
                'buttons' => $t['buttons'] ?? []
            ];
        }
        
        return $templates;
    }
    
    /**
     * Send WhatsApp message using template
     */
    public static function sendTemplateMessage($phoneNumber, $templateId, $params = []) {
        if  (!str_starts_with($phoneNumber, '+')){
            $phoneNumber = '+' . $phoneNumber;
        }
        $requestBody = [
            [
            'from_addr' => BEEM_SENDER_NUMBER,
            'destination_addr' => [
                [
                    'phoneNumber' => $phoneNumber,
                    'params' => $params,
                ]
            ],
            'channel' => 'whatsapp',
            'messageTemplateData' => [
                'id' => (int)$templateId,
                ],
            ]
        ];
        
        $jsonBody = json_encode($requestBody);

        error_log( "Beem API Request: " . $jsonBody );
        
        $ch = curl_init(BEEM_BROADCAST_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . self::getAuthHeader(),
            'Content-Length: ' . strlen($jsonBody)
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        error_log( "Beem API Response ($httpCode): " . $response );
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Beem Broadcast Network Error: $error");
        }
        
        curl_close($ch);
        
        $responseJson = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = isset($responseJson['errors']['message']) 
            ? $responseJson['errors']['message'] 
            : (isset($responseJson['message']) ? $responseJson['message'] : "HTTP Error $httpCode");
        throw new Exception("Beem API Error: $errorMsg");
         }  
            if (!isset($responseJson['data']['successful']) || 
        $responseJson['data']['successful'] !== true) {
        $msg = $responseJson['data']['message'] ?? 
               $responseJson['message'] ?? 
               "Unknown Beem API failure";
        throw new Exception("Beem Broadcast Failed: $msg");
        }
        
        return [
            'job_id' => $responseJson['data']['jobId'] ?? null,
            'successful' => true,
            'message' => $responseJson['data']['message'] ?? 'Message sent',
            'validation' => $responseJson['data']['validation'] ?? null
        ];
    }
}