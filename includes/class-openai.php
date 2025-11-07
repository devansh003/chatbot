<?php
/**
 * OpenAI API Handler Class - FIXED VERSION
 * 
 * FIXES:
 * 1. Added text truncation for embedding limits
 * 2. Added embedding dimension validation
 * 3. Improved error logging and handling
 * 4. Better response code checking
 */

class AIChatbot_OpenAI {
    
    private $api_key;
    private $model;
    private $temperature;
    private $max_tokens;
    
    public function __construct() {
        $settings = get_option('aichat_settings', array());
        $this->api_key = $settings['openai_api_key'] ?? '';
        $this->model = $settings['model'] ?? 'gpt-4o-mini';
        $this->temperature = floatval($settings['temperature'] ?? 0.7);
        $this->max_tokens = intval($settings['max_tokens'] ?? 500);
    }
    
    public function test_connection() {
        if (empty($this->api_key)) {
            error_log('OpenAI: API key is empty');
            return false;
        }
        
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            error_log('OpenAI Connection Test Failed: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            error_log('OpenAI Connection Test: SUCCESS');
            return true;
        }
        
        error_log('OpenAI Connection Test Failed: HTTP ' . $code);
        return false;
    }
    
    /**
     * Get embedding for text - FIXED VERSION
     * 
     * FIXES:
     * - Truncates text to prevent token limit errors
     * - Validates embedding dimensions
     * - Better error logging
     */
    public function get_embedding($text) {
        if (empty($this->api_key)) {
            error_log('OpenAI Embedding: API key is empty');
            return false;
        }
        
        if (empty(trim($text))) {
            error_log('OpenAI Embedding: Text is empty');
            return false;
        }
        
        // TRUNCATE text to prevent token limit errors
        // OpenAI embedding models have 8191 token limit (~30,000 chars for English)
        // We use 25,000 to be safe with special characters
        $text = mb_substr($text, 0, 25000);
        
        $response = wp_remote_post('https://api.openai.com/v1/embeddings', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'input' => $text,
                'model' => 'text-embedding-3-small'
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('OpenAI Embedding Error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Check for API errors
        if ($code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            error_log('OpenAI Embedding Failed: HTTP ' . $code . ' - ' . $error_msg);
            return false;
        }
        
        // Validate response structure
        if (!isset($data['data'][0]['embedding'])) {
            error_log('OpenAI Embedding: No embedding in response - ' . $body);
            return false;
        }
        
        $embedding = $data['data'][0]['embedding'];
        
        // VALIDATE DIMENSIONS - text-embedding-3-small produces 1536 dimensions
        if (!is_array($embedding) || count($embedding) !== 1536) {
            error_log('OpenAI Embedding: Invalid dimensions - got ' . count($embedding) . ', expected 1536');
            return false;
        }
        
        // Validate all values are floats
        foreach ($embedding as $value) {
            if (!is_numeric($value)) {
                error_log('OpenAI Embedding: Non-numeric value detected in embedding');
                return false;
            }
        }
        
        return $embedding;
    }
    
    /**
     * Chat completion with streaming - FIXED VERSION
     */
    public function chat_completion_stream($user_message, $conversation_history = array(), $system_prompt = '') {
        if (empty($this->api_key)) {
            error_log('OpenAI Chat Stream: API key is empty');
            echo "data: " . json_encode(array('type' => 'error', 'message' => 'API key not configured')) . "\n\n";
            flush();
            return false;
        }
        
        $messages = array();
        
        // Add system prompt
        if (!empty($system_prompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $system_prompt
            );
        }
        
        // Add conversation history (limit to last 10 messages)
        $history = array_slice($conversation_history, -10);
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }
        
        // Add current user message
        $messages[] = array(
            'role' => 'user',
            'content' => $user_message
        );
        
        $payload = array(
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            'stream' => true
        );
        
        // Use cURL for streaming
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this, 'stream_callback'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // Disable output buffering
        if (ob_get_level()) {
            ob_end_flush();
        }
        
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        
        $result = curl_exec($ch);
        
        if ($result === false) {
            $error = curl_error($ch);
            error_log('OpenAI Stream cURL Error: ' . $error);
            echo "data: " . json_encode(array('type' => 'error', 'message' => 'Connection error')) . "\n\n";
            flush();
        }
        
        curl_close($ch);
    }
    
    private function stream_callback($ch, $data) {
        $lines = explode("\n", $data);
        
        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $json_str = substr($line, 6);
                
                if (trim($json_str) === '[DONE]') {
                    echo "data: [DONE]\n\n";
                    flush();
                    continue;
                }
                
                $json = json_decode($json_str, true);
                
                if (isset($json['choices'][0]['delta']['content'])) {
                    $content = $json['choices'][0]['delta']['content'];
                    
                    // Send chunk to client
                    echo "data: " . json_encode(array('content' => $content)) . "\n\n";
                    flush();
                }
            }
        }
        
        return strlen($data);
    }
    
    /**
     * Non-streaming chat completion - FIXED VERSION
     */
    public function chat_completion($user_message, $conversation_history = array(), $system_prompt = '') {
        if (empty($this->api_key)) {
            error_log('OpenAI Chat: API key is empty');
            return false;
        }
        
        $messages = array();
        
        // Add system prompt
        if (!empty($system_prompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $system_prompt
            );
        }
        
        // Add conversation history (limit to last 10 messages)
        $history = array_slice($conversation_history, -10);
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array(
                    'role' => $msg['role'],
                    'content' => $msg['content']
                );
            }
        }
        
        // Add current user message
        $messages[] = array(
            'role' => 'user',
            'content' => $user_message
        );
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $this->temperature,
                'max_tokens' => $this->max_tokens
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('OpenAI Chat Error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            error_log('OpenAI Chat Failed: HTTP ' . $code . ' - ' . $error_msg);
            return false;
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        
        error_log('OpenAI Chat: No content in response - ' . $body);
        return false;
    }
}
