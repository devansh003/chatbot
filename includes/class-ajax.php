<?php
/**
 * AJAX Handler - CORRECTED VERSION
 * 
 * FIXES:
 * 1. Correctly passes user query text ($message) to Supabase search
 * 2. Adds detailed logging for debugging pricing detection
 * 3. Keeps all existing features intact
 */

class AIChatbot_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_aichat_send_message', array($this, 'send_message'));
        add_action('wp_ajax_nopriv_aichat_send_message', array($this, 'send_message'));
        add_action('wp_ajax_aichat_stream_message', array($this, 'stream_message'));
        add_action('wp_ajax_nopriv_aichat_stream_message', array($this, 'stream_message'));
        
        // Batch indexing endpoints
        add_action('wp_ajax_aichat_start_batch_index', array($this, 'start_batch_index'));
        add_action('wp_ajax_aichat_process_batch', array($this, 'process_batch'));
    }

    /**
     * Start batch indexing process
     */
    public function start_batch_index() {
        check_ajax_referer('aichat_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $args = array(
            'post_type' => array('post', 'page', 'product'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $post_ids = get_posts($args);

        set_transient('aichat_batch_index_queue', $post_ids, HOUR_IN_SECONDS);
        set_transient('aichat_batch_index_stats', array(
            'total' => count($post_ids),
            'processed' => 0,
            'indexed' => 0,
            'errors' => 0,
            'chunks' => 0
        ), HOUR_IN_SECONDS);

        delete_option('aichat_indexing_logs');
        error_log("AI Chatbot: Batch indexing started with " . count($post_ids) . " posts");

        wp_send_json_success(array(
            'total' => count($post_ids),
            'message' => 'Batch indexing started'
        ));
    }

    /**
     * Process one batch of posts
     */
    public function process_batch() {
        check_ajax_referer('aichat_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        set_time_limit(120);

        $queue = get_transient('aichat_batch_index_queue');
        $stats = get_transient('aichat_batch_index_stats');

        if (!$queue || !$stats) {
            error_log('AI Chatbot: No indexing in progress');
            wp_send_json_error('No indexing in progress');
        }

        $batch_size = 10;
        $batch = array_splice($queue, 0, $batch_size);

        $batch_indexed = 0;
        $batch_errors = 0;
        $batch_chunks = 0;

        error_log("AI Chatbot: Processing batch of " . count($batch) . " posts");

        foreach ($batch as $post_id) {
            $result = AIChatbot_Indexer::index_single_post($post_id, false);

            if ($result['success']) {
                $batch_indexed++;
                $batch_chunks += $result['chunks'];
            } else {
                $batch_errors++;
            }

            $stats['processed']++;
            usleep(300000); // 0.3 seconds
        }

        $stats['indexed'] += $batch_indexed;
        $stats['errors'] += $batch_errors;
        $stats['chunks'] += $batch_chunks;

        set_transient('aichat_batch_index_queue', $queue, HOUR_IN_SECONDS);
        set_transient('aichat_batch_index_stats', $stats, HOUR_IN_SECONDS);

        $is_complete = empty($queue);

        if ($is_complete) {
            delete_transient('aichat_batch_index_queue');
            delete_transient('aichat_batch_index_stats');
            error_log("AI Chatbot: Batch indexing complete - {$stats['indexed']} indexed, {$stats['chunks']} chunks, {$stats['errors']} errors");
        }

        wp_send_json_success(array(
            'is_complete' => $is_complete,
            'stats' => $stats,
            'message' => "Batch complete: +{$batch_indexed} indexed, +{$batch_chunks} chunks, +{$batch_errors} errors"
        ));
    }

    /**
     * Stream message handler
     */
    public function stream_message() {
        check_ajax_referer('aichat_nonce', 'nonce');

        $message = isset($_POST['message']) ? $_POST['message'] : '';
error_log("AIChatbot DEBUG: Raw \$_POST['message'] = " . print_r($_POST['message'], true));
error_log("AIChatbot DEBUG: After sanitize, message = " . $message);
        $conversation_history = json_decode(stripslashes($_POST['history'] ?? '[]'), true);

        if (empty($message)) {
            echo "data: " . json_encode(array('type' => 'error', 'message' => 'Message is required')) . "\n\n";
            flush();
            die();
        }

        error_log("AI Chatbot: Received stream query: " . substr($message, 0, 100));

        try {
            $openai = new AIChatbot_OpenAI();
            $query_embedding = $openai->get_embedding($message);

            if (!$query_embedding) {
                throw new Exception('Failed to generate query embedding');
            }

            error_log("AI Chatbot: Query embedding generated successfully");

            $supabase = new AIChatbot_Supabase();
            error_log("AI Chatbot: Supabase search triggered with query text: " . $message);

            $relevant_content = $supabase->search_similar_content($query_embedding, 5, $message);

            error_log("AI Chatbot: Found " . count($relevant_content) . " relevant results");

            $relevant_content = array_filter($relevant_content, function($item) {
                return isset($item['similarity']) && $item['similarity'] > 0.5;
            });

            $context = '';
            if (!empty($relevant_content)) {
                $context = "Here is relevant information from the website:\n\n";
                foreach ($relevant_content as $index => $item) {
                    $context .= "Source " . ($index + 1) . ":\n";
                    $context .= "Title: " . $item['title'] . "\n";
                    $context .= "Content: " . $item['content'] . "\n";
                    $context .= "URL: " . $item['url'] . "\n\n";
                }

                echo "data: " . json_encode(array(
                    'type' => 'sources',
                    'sources' => array_map(function($item) {
                        return array(
                            'title' => $item['title'],
                            'url' => $item['url']
                        );
                    }, $relevant_content)
                )) . "\n\n";
                flush();
            }

            $system_prompt = "You are a helpful AI assistant for this website. Your role is to answer questions accurately based on the provided context.\n\n";
            $system_prompt .= "- Use the context below to answer questions accurately\n";
            $system_prompt .= "- When you see pricing information or tables, provide exact details\n";
            $system_prompt .= "- If context doesn't include info, politely say so\n";
            $system_prompt .= "- Be concise and helpful\n\n";

            $system_prompt .= !empty($context)
                ? $context
                : "No relevant content found. Politely indicate missing info.";

            error_log("AI Chatbot: Starting streaming response");

            $openai->chat_completion_stream($message, $conversation_history, $system_prompt);

        } catch (Exception $e) {
            error_log('AI Chatbot Stream Error: ' . $e->getMessage());
            echo "data: " . json_encode(array('type' => 'error', 'message' => 'Sorry, I encountered an error. Please try again.')) . "\n\n";
            flush();
        }

        die();
    }

    /**
     * Non-streaming message handler
     */
    public function send_message() {
        check_ajax_referer('aichat_nonce', 'nonce');

        $message = isset($_POST['message']) ? $_POST['message'] : '';
error_log("AIChatbot DEBUG: Raw \$_POST['message'] = " . print_r($_POST['message'], true));
error_log("AIChatbot DEBUG: After sanitize, message = " . $message);
        $conversation_history = json_decode(stripslashes($_POST['history'] ?? '[]'), true);

        if (empty($message)) {
            wp_send_json_error('Message is required');
        }

        error_log("AI Chatbot: Received query: " . substr($message, 0, 100));

        try {
            $openai = new AIChatbot_OpenAI();
            $query_embedding = $openai->get_embedding($message);

            if (!$query_embedding) {
                throw new Exception('Failed to generate query embedding');
            }

            error_log("AI Chatbot: Query embedding generated successfully");

            $supabase = new AIChatbot_Supabase();
            error_log("AI Chatbot: Supabase search triggered with query text: " . $message);

            $relevant_content = $supabase->search_similar_content($query_embedding, 5, $message);

            error_log("AI Chatbot: Found " . count($relevant_content) . " relevant results");

            $relevant_content = array_filter($relevant_content, function($item) {
                return isset($item['similarity']) && $item['similarity'] > 0.5;
            });
            $relevant_content = array_values($relevant_content);

            $context = '';
            if (!empty($relevant_content)) {
                $context = "Here is relevant information from the website:\n\n";
                foreach ($relevant_content as $index => $item) {
                    $context .= "Source " . ($index + 1) . ":\n";
                    $context .= "Title: " . $item['title'] . "\n";
                    $context .= "Content: " . $item['content'] . "\n";
                    $context .= "URL: " . $item['url'] . "\n\n";
                }
            }

            $system_prompt = "You are a helpful AI assistant for this website. Answer questions based on the provided context.\n\n";
            $system_prompt .= "- Use the context accurately\n";
            $system_prompt .= "- Provide exact pricing details if available\n";
            $system_prompt .= "- If info is missing, say so politely\n";
            $system_prompt .= "- Keep responses short and clear\n\n";

            $system_prompt .= !empty($context)
                ? $context
                : "No relevant content found for this query.";

            error_log("AI Chatbot: Generating response");

            $response = $openai->chat_completion($message, $conversation_history, $system_prompt);

            if (!$response) {
                throw new Exception('Failed to generate response');
            }

            error_log("AI Chatbot: Response generated successfully");

            wp_send_json_success(array(
                'message' => $response,
                'sources' => array_map(function($item) {
                    return array(
                        'title' => $item['title'],
                        'url' => $item['url'],
                        'similarity' => $item['similarity']
                    );
                }, $relevant_content)
            ));

        } catch (Exception $e) {
            error_log('AI Chatbot Error: ' . $e->getMessage());
            wp_send_json_error('Sorry, I encountered an error. Please try again.');
        }
    }
}
