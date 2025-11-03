<?php
/**
 * Enhanced Content Indexer - FIXED VERSION
 * 
 * FIXES:
 * 1. Reduced chunk size to prevent token limit errors
 * 2. Better content validation
 * 3. Improved error handling and logging
 * 4. More efficient chunking algorithm
 * 5. Better metadata extraction
 */

class AIChatbot_Indexer {
    
    // FIXED: Safer chunk size to prevent token limit errors
    const MAX_CHUNK_SIZE = 6000;  // Reduced from 7000
    const CHUNK_OVERLAP = 300;    // Reduced from 500
    
    public static function index_all_content() {
        $indexed = 0;
        $errors = 0;
        $error_details = array();
        $chunks_created = 0;
        
        error_log('=== AI Chatbot: Starting Full Index ===');
        
        // Get all published posts and pages
        $args = array(
            'post_type' => array('post', 'page', 'product'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $posts = get_posts($args);
        $total = count($posts);
        
        self::log_to_admin(0, true, "=== Indexing Started: {$total} posts ===");
        error_log("AI Chatbot: Found {$total} posts to index");
        
        foreach ($posts as $post_id) {
            $result = self::index_single_post($post_id, false);
            
            if ($result['success']) {
                $indexed++;
                $chunks_created += $result['chunks'];
                self::log_to_admin($post_id, true, "Indexed with {$result['chunks']} chunks");
            } else {
                $errors++;
                $error_details[] = "Post ID {$post_id}: {$result['reason']}";
                self::log_to_admin($post_id, false, $result['reason']);
            }
            
            // Prevent rate limiting
            usleep(500000); // 0.5 second delay
            
            // Progress log every 10 posts
            if (($indexed + $errors) % 10 === 0) {
                $progress = "Progress: {$indexed}/{$total} indexed, {$chunks_created} chunks created, {$errors} errors";
                self::log_to_admin(0, true, $progress);
                error_log("AI Chatbot: {$progress}");
            }
        }
        
        $final_msg = "=== Indexing Complete: {$indexed} indexed, {$chunks_created} chunks, {$errors} errors ===";
        self::log_to_admin(0, true, $final_msg);
        error_log("AI Chatbot: {$final_msg}");
        
        return array(
            'success' => true,
            'message' => "Indexed {$indexed} out of {$total} items with {$chunks_created} chunks. {$errors} errors.",
            'indexed' => $indexed,
            'errors' => $errors,
            'total' => $total,
            'chunks' => $chunks_created,
            'error_details' => $error_details
        );
    }
    
    /**
     * Index single post - FIXED VERSION
     */
    public static function index_single_post($post_id, $check_auto_index = true) {
        $post = get_post($post_id);
        
        if (!$post) {
            error_log("AI Chatbot: Post {$post_id} not found");
            return array('success' => false, 'reason' => 'Post not found', 'chunks' => 0);
        }
        
        if ($post->post_status !== 'publish') {
            return array('success' => false, 'reason' => 'Not published', 'chunks' => 0);
        }
        
        // Check auto-index setting only when called from save_post
        if ($check_auto_index && doing_action('save_post')) {
            $settings = get_option('aichat_settings', array());
            if (($settings['auto_index'] ?? 'yes') !== 'yes') {
                return array('success' => false, 'reason' => 'Auto-index disabled', 'chunks' => 0);
            }
        }
        
        error_log("AI Chatbot: Starting to index post ID {$post_id} - {$post->post_title}");
        
        // EXTRACT EVERYTHING
        $full_content = self::extract_all_content($post);
        
        if (empty(trim($full_content))) {
            error_log("AI Chatbot: No content extracted for post ID {$post_id}");
            return array('success' => false, 'reason' => 'No content extracted', 'chunks' => 0);
        }
        
        $content_length = strlen($full_content);
        error_log("AI Chatbot: Extracted {$content_length} characters from post ID {$post_id}");
        
        $title = $post->post_title;
        $url = get_permalink($post_id);
        
        // Split into chunks if content is too large
        $chunks = self::split_into_chunks($full_content, $title);
        
        if (empty($chunks)) {
            error_log("AI Chatbot: No chunks created for post ID {$post_id}");
            return array('success' => false, 'reason' => 'Content too short after processing', 'chunks' => 0);
        }
        
        error_log("AI Chatbot: Created " . count($chunks) . " chunks for post ID {$post_id}");
        
        $openai = new AIChatbot_OpenAI();
        $supabase = new AIChatbot_Supabase();
        
        // Delete old embeddings first
        $supabase->delete_embedding($post_id);
        
        $chunks_stored = 0;
        
        // Store each chunk
        foreach ($chunks as $chunk_index => $chunk_content) {
            error_log("AI Chatbot: Processing chunk {$chunk_index} for post ID {$post_id}");
            
            // Validate chunk content
            $chunk_length = strlen($chunk_content);
            if ($chunk_length < 50) {
                error_log("AI Chatbot: Skipping chunk {$chunk_index} - too short ({$chunk_length} chars)");
                continue;
            }
            
            // Create embedding
            $embedding = $openai->get_embedding($chunk_content);
            
            if (!$embedding || !is_array($embedding)) {
                error_log("AI Chatbot: Failed to create embedding for chunk {$chunk_index} of post ID {$post_id}");
                continue; // Skip failed embeddings
            }
            
            error_log("AI Chatbot: Embedding created successfully for chunk {$chunk_index} of post ID {$post_id}");
            
            // Add chunk info to title if multiple chunks
            $chunk_title = $title;
            if (count($chunks) > 1) {
                $chunk_title .= " (Part " . ($chunk_index + 1) . "/" . count($chunks) . ")";
            }
            
            // Store in Supabase
            $result = $supabase->insert_embedding(
                $post_id, 
                $chunk_title, 
                $chunk_content, 
                $url, 
                $embedding,
                $chunk_index
            );
            
            if ($result) {
                $chunks_stored++;
                error_log("AI Chatbot: Chunk {$chunk_index} stored successfully for post ID {$post_id}");
            } else {
                error_log("AI Chatbot: Failed to store chunk {$chunk_index} for post ID {$post_id}");
            }
            
            // Small delay between chunks
            usleep(250000); // 0.25 seconds
        }
        
        if ($chunks_stored === 0) {
            error_log("AI Chatbot: Failed to store any chunks for post ID {$post_id}");
            return array('success' => false, 'reason' => 'Failed to store any chunks', 'chunks' => 0);
        }
        
        error_log("AI Chatbot: Successfully indexed post ID {$post_id} with {$chunks_stored} chunks");
        return array('success' => true, 'reason' => 'Success', 'chunks' => $chunks_stored);
    }
    
    /**
     * EXTRACT ALL CONTENT - FIXED VERSION
     */
    private static function extract_all_content($post) {
        $content_parts = array();
        
        // 1. POST TITLE (important for context)
        if (!empty($post->post_title)) {
            $content_parts[] = "TITLE: " . $post->post_title;
        }
        
        // 2. POST EXCERPT
        if (!empty($post->post_excerpt)) {
            $content_parts[] = "EXCERPT: " . $post->post_excerpt;
        }
        
        // 3. MAIN CONTENT - Expand shortcodes first
        $main_content = $post->post_content;
        
        if (!empty($main_content)) {
            // Apply WordPress content filters (expands shortcodes, galleries, etc.)
            $main_content = apply_filters('the_content', $main_content);
            
            // 4. EXTRACT TABLES (HTML tables with pricing, specs, etc.)
            $tables_text = self::extract_tables_as_text($main_content);
            if (!empty($tables_text)) {
                $content_parts[] = "\n=== TABLES DATA ===\n" . $tables_text;
            }
            
            // 5. Strip HTML but keep structure
            $main_content = wp_strip_all_tags($main_content, true);
            $main_content = trim($main_content);
            
            if (!empty($main_content)) {
                $content_parts[] = $main_content;
            }
        }
        
        // 6. CUSTOM FIELDS / POST META (pricing, specs, etc.)
        $meta_data = self::extract_all_metadata($post->ID);
        if (!empty($meta_data)) {
            $content_parts[] = "\n=== ADDITIONAL DATA ===\n" . $meta_data;
        }
        
        // 7. ACF FIELDS (if Advanced Custom Fields plugin is active)
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post->ID);
            if ($acf_fields && is_array($acf_fields)) {
                $acf_text = self::format_acf_fields($acf_fields);
                if (!empty($acf_text)) {
                    $content_parts[] = "\n=== CUSTOM FIELDS ===\n" . $acf_text;
                }
            }
        }
        
        // 8. TAXONOMIES (categories, tags, custom taxonomies)
        $taxonomies = self::extract_taxonomies($post->ID);
        if (!empty($taxonomies)) {
            $content_parts[] = "\n=== CATEGORIES & TAGS ===\n" . $taxonomies;
        }
        
        // 9. WOOCOMMERCE DATA (if it's a product)
        if ($post->post_type === 'product' && class_exists('WooCommerce')) {
            $product_data = self::extract_woocommerce_data($post->ID);
            if (!empty($product_data)) {
                $content_parts[] = "\n=== PRODUCT INFORMATION ===\n" . $product_data;
            }
        }
        
        // Combine everything
        $full_content = implode("\n\n", array_filter($content_parts));
        
        // Clean up extra whitespace
        $full_content = preg_replace('/\n{3,}/', "\n\n", $full_content);
        $full_content = preg_replace('/[ \t]+/', ' ', $full_content);
        $full_content = trim($full_content);
        
        return $full_content;
    }
    
    /**
     * Extract HTML tables and convert to readable text
     */
    private static function extract_tables_as_text($html) {
        $tables_text = '';
        
        // Find all tables
        preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $html, $tables);
        
        if (empty($tables[0])) {
            return '';
        }
        
        foreach ($tables[0] as $table_index => $table_html) {
            $tables_text .= "\n--- Table " . ($table_index + 1) . " ---\n";
            
            // Extract rows
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $rows);
            
            foreach ($rows[1] as $row_html) {
                // Extract cells (th or td)
                preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $row_html, $cells);
                
                $row_data = array();
                foreach ($cells[1] as $cell_html) {
                    $cell_text = wp_strip_all_tags($cell_html);
                    $cell_text = trim($cell_text);
                    if (!empty($cell_text)) {
                        $row_data[] = $cell_text;
                    }
                }
                
                if (!empty($row_data)) {
                    $tables_text .= implode(' | ', $row_data) . "\n";
                }
            }
            
            $tables_text .= "\n";
        }
        
        return trim($tables_text);
    }
    
    /**
     * Extract ALL metadata (custom fields)
     */
    private static function extract_all_metadata($post_id) {
        $meta_text = '';
        $all_meta = get_post_meta($post_id);
        
        if (!is_array($all_meta)) {
            return '';
        }
        
        // Skip WordPress internal meta
        $skip_keys = array('_edit_lock', '_edit_last', '_wp_page_template', '_thumbnail_id', '_wp_old_slug');
        
        foreach ($all_meta as $key => $values) {
            // Skip internal WordPress meta (starts with _)
            if (strpos($key, '_') === 0 && !in_array($key, array('_price', '_regular_price', '_sale_price'))) {
                continue;
            }
            
            if (in_array($key, $skip_keys)) {
                continue;
            }
            
            if (!is_array($values)) {
                continue;
            }
            
            foreach ($values as $value) {
                // Skip serialized data that's too complex
                if (is_serialized($value)) {
                    $value = maybe_unserialize($value);
                    if (is_array($value) || is_object($value)) {
                        $value = print_r($value, true);
                    }
                }
                
                if (!is_string($value)) {
                    $value = strval($value);
                }
                
                $value = trim($value);
                if (!empty($value) && strlen($value) < 5000) {
                    $label = ucfirst(str_replace('_', ' ', $key));
                    $meta_text .= $label . ": " . $value . "\n";
                }
            }
        }
        
        return trim($meta_text);
    }
    
    /**
     * Format ACF fields
     */
    private static function format_acf_fields($fields, $prefix = '') {
        $text = '';
        
        if (!is_array($fields)) {
            return '';
        }
        
        foreach ($fields as $key => $value) {
            if (is_string($key)) {
                $label = $prefix . ucfirst(str_replace('_', ' ', $key));
            } else {
                $label = $prefix . 'Field';
            }
            
            if (is_array($value)) {
                $text .= self::format_acf_fields($value, $label . ' - ');
            } elseif (is_string($value) || is_numeric($value)) {
                $value = trim(strval($value));
                if (!empty($value)) {
                    $text .= $label . ": " . $value . "\n";
                }
            }
        }
        
        return $text;
    }
    
    /**
     * Extract taxonomies (categories, tags)
     */
    private static function extract_taxonomies($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
        
        $taxonomies = get_object_taxonomies($post);
        $tax_text = '';
        
        if (!is_array($taxonomies)) {
            return '';
        }
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if ($terms && !is_wp_error($terms) && is_array($terms)) {
                $term_names = array_map(function($term) { 
                    return isset($term->name) ? $term->name : ''; 
                }, $terms);
                $term_names = array_filter($term_names);
                
                if (!empty($term_names)) {
                    $tax_text .= ucfirst($taxonomy) . ": " . implode(', ', $term_names) . "\n";
                }
            }
        }
        
        return trim($tax_text);
    }
    
    /**
     * Extract WooCommerce product data
     */
    private static function extract_woocommerce_data($post_id) {
        if (!function_exists('wc_get_product')) {
            return '';
        }
        
        $product = wc_get_product($post_id);
        if (!$product) {
            return '';
        }
        
        $data = array();
        
        $data[] = "Product Type: " . $product->get_type();
        
        if ($product->get_price_html()) {
            $data[] = "Price: " . wp_strip_all_tags($product->get_price_html());
        }
        
        if ($product->get_regular_price()) {
            $data[] = "Regular Price: $" . $product->get_regular_price();
        }
        
        if ($product->is_on_sale() && $product->get_sale_price()) {
            $data[] = "Sale Price: $" . $product->get_sale_price();
        }
        
        if ($product->get_sku()) {
            $data[] = "SKU: " . $product->get_sku();
        }
        
        $data[] = "Stock Status: " . $product->get_stock_status();
        
        if ($product->get_short_description()) {
            $short_desc = wp_strip_all_tags($product->get_short_description());
            $data[] = "Short Description: " . $short_desc;
        }
        
        // Attributes
        $attributes = $product->get_attributes();
        if ($attributes && is_array($attributes)) {
            $data[] = "\nProduct Attributes:";
            foreach ($attributes as $attribute) {
                if (method_exists($attribute, 'get_name') && method_exists($attribute, 'get_options')) {
                    $options = $attribute->get_options();
                    if (is_array($options)) {
                        $data[] = $attribute->get_name() . ": " . implode(', ', $options);
                    }
                }
            }
        }
        
        return implode("\n", array_filter($data));
    }
    
    /**
     * Split large content into chunks - FIXED VERSION
     */
    private static function split_into_chunks($content, $title) {
        $content_length = strlen($content);
        
        // If content is small enough, return as single chunk
        if ($content_length <= self::MAX_CHUNK_SIZE) {
            return array($content);
        }
        
        error_log("AI Chatbot: Splitting content into chunks (length: {$content_length})");
        
        $chunks = array();
        $start = 0;
        $chunk_num = 0;
        
        while ($start < $content_length) {
            $chunk = substr($content, $start, self::MAX_CHUNK_SIZE);
            
            // Add title to each chunk for context
            $chunk_with_context = "Title: {$title}\n\n{$chunk}";
            
            $chunks[] = $chunk_with_context;
            $chunk_num++;
            
            // Move forward with overlap
            $start += self::MAX_CHUNK_SIZE - self::CHUNK_OVERLAP;
        }
        
        error_log("AI Chatbot: Created {$chunk_num} chunks");
        
        return $chunks;
    }
    
    public static function delete_post_embedding($post_id) {
        $supabase = new AIChatbot_Supabase();
        $deleted = $supabase->delete_embedding($post_id);
        
        if ($deleted) {
            self::log_to_admin($post_id, true, "Deleted all embeddings");
            error_log("AI Chatbot: Deleted embeddings for post ID {$post_id}");
        } else {
            error_log("AI Chatbot: Failed to delete embeddings for post ID {$post_id}");
        }
        
        return $deleted;
    }
    
    /**
     * Log to WordPress options (visible in admin)
     */
    private static function log_to_admin($post_id, $success, $message) {
        $logs = get_option('aichat_indexing_logs', array());
        
        $logs[] = array(
            'time' => current_time('Y-m-d H:i:s'),
            'post_id' => $post_id,
            'success' => $success,
            'message' => $message
        );
        
        // Keep only last 500 logs
        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }
        
        update_option('aichat_indexing_logs', $logs);
    }
}

// Hook for deleting embeddings when posts are deleted
add_action('before_delete_post', function($post_id) {
    AIChatbot_Indexer::delete_post_embedding($post_id);
});