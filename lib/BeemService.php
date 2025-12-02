<?php
/**
 * Beem Service
 * Handles all Beem WhatsApp API interactions
 * 
 * Uses hybrid authentication:
 * - Basic Auth (API Key + Secret) for ChatCore API (templates)
 * - Bearer Token for Broadcast API (sending messages)
 */

class BeemService {
    
    /**
     * Get Basic Auth header for ChatCore API (templates)
     */
    private static function getBasicAuthHeader() {
        if (!defined('BEEM_API_KEY') || !defined('BEEM_SECRET_KEY')) {
            throw new Exception("BEEM_API_KEY and BEEM_SECRET_KEY are required");
        }
        return 'Basic ' . base64_encode(BEEM_API_KEY . ':' . BEEM_SECRET_KEY);
    }
    
    /**
     * Get Bearer token header for Broadcast API (sending)
     */
    private static function getBearerTokenHeader() {
        if (!defined('BEEM_API_TOKEN') || empty(BEEM_API_TOKEN)) {
            throw new Exception("BEEM_API_TOKEN is not configured");
        }
        return BEEM_API_TOKEN;
    }
    
    /**
     * Make authenticated request to Beem ChatCore API (uses Basic Auth)
     */
    private static function callChatCoreApi($endpoint, $params = []) {
        $queryString = http_build_query($params);
        $url = BEEM_API_BASE_URL . $endpoint . ($queryString ? '?' . $queryString : '');
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . self::getBasicAuthHeader()
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
     * Get list of WhatsApp templates (uses Basic Auth)
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
     * Send WhatsApp message using template (uses Bearer Token)
     * 
     * @param string $phoneNumber Phone number (with or without + prefix)
     * @param string $templateId Template ID
     * @param array $params Template parameters as array of strings (e.g., ["TestParam1", "TestParam2"])
     * @param string|null $mediaUrl Media URL for templates with media (optional)
     * @return array Response with job_id, successful status, etc.
     */
    public static function sendTemplateMessage($phoneNumber, $templateId, $params = [], $mediaUrl = null) {
        $formattedNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        $senderNumber = preg_replace('/[^0-9]/', '', BEEM_SENDER_NUMBER);
        
        $paramsList = [];
        if (!empty($params)) {
            foreach ($params as $param) {
                $paramsList[] = (string)$param;
            }
        }
        
        if (!$mediaUrl) {
            try {
                $templates = self::getTemplates();
                foreach ($templates as $t) {
                    if ($t['id'] == $templateId) {
                        if (!empty($t['mediaUrl'])) {
                            $mediaUrl = $t['mediaUrl'];
                            error_log("Template $templateId: Auto-detected mediaUrl: $mediaUrl");
                        }
                        
                        error_log("Template $templateId details: type={$t['type']}, hasMedia=" . 
                                  (!empty($t['mediaUrl']) ? 'yes' : 'no') . 
                                  ", hasButtons=" . (!empty($t['buttons']) ? 'yes' : 'no'));
                        break;
                    }
                }
            } catch (Exception $e) {
                error_log("Warning: Could not fetch template metadata: " . $e->getMessage());
            }
        }
        
        $requestBody = [
            'from_addr' => $senderNumber,
            'destination_addr' => [
                [
                    'phoneNumber' => $formattedNumber,
                    'params' => $paramsList
                ]
            ],
            'channel' => 'whatsapp',
            'messageTemplateData' => [
                'id' => (int)$templateId
            ]
        ];
        
        if ($mediaUrl) {
            $requestBody['content'] = [
                'mediaUrl' => $mediaUrl
            ];
            error_log("Including mediaUrl in broadcast request: $mediaUrl");
        } else {
            error_log("No mediaUrl for template $templateId - sending text-only");
        }
        
        $jsonBody = json_encode($requestBody);
        
        error_log("Beem Broadcast API Request: " . $jsonBody);
        
        $ch = curl_init(BEEM_BROADCAST_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . self::getBearerTokenHeader()
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        error_log("Beem Broadcast API Response ($httpCode): " . $response);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Beem Broadcast Network Error: $error");
        }
        
        curl_close($ch);
        
        $responseJson = json_decode($response, true);
        
        if ($httpCode == 401) {
            throw new Exception("Beem API Error: Unauthorized - Check your BEEM_API_TOKEN");
        }
        
        if ($httpCode >= 400) {
            $errorMsg = "HTTP Error $httpCode";
            
            if (isset($responseJson['errors']['message'])) {
                $errorMsg = $responseJson['errors']['message'];
            } elseif (isset($responseJson['message'])) {
                $errorMsg = $responseJson['message'];
            }
            
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
            'validation' => $responseJson['data']['validation'] ?? null,
            'credits' => $responseJson['data']['credits'] ?? null
        ];
    }
}