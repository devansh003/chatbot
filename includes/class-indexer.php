<?php
/**
 * Enhanced Content Indexer - FIXED + SAFE VERSION (Full)
 * 
 * FIXES:
 * 1. Reduced chunk size to prevent token limit errors
 * 2. Better content validation
 * 3. Improved error handling and logging
 * 4. More efficient chunking algorithm
 * 5. Better metadata extraction
 * 6. Added safe progress logging + batch indexing + try/catch resilience
 */

class AIChatbot_Indexer {

    const MAX_CHUNK_SIZE = 6000;
    const CHUNK_OVERLAP = 30;

    /**
     * Flush-safe logger for progressive updates
     */
    private static function safe_progress($message) {
        self::write_debug_log($message);
        error_log($message);
        @ob_flush();
        @flush();
    }

    /**
     * Optional: Batch-safe version for large sites
     */
    public static function index_in_batches($batch_size = 100) {
        $args = [
            'post_type' => ['page', 'product'],
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1
        ];

        $posts = get_posts($args);
        $total = count($posts);

        if ($total === 0) {
            self::safe_progress("No posts found for batch indexing.");
            return ['success' => false, 'message' => 'No posts found.'];
        }

        $batches = array_chunk($posts, $batch_size);
        $batch_num = 0;
        $indexed = $errors = $chunks_created = 0;

        foreach ($batches as $batch) {
            $batch_num++;
            self::safe_progress("=== Starting batch {$batch_num} (" . count($batch) . " posts) ===");

            foreach ($batch as $post_id) {
                try {
                    $result = self::index_single_post($post_id, false);
                    if ($result['success']) {
                        $indexed++;
                        $chunks_created += $result['chunks'];
                    } else {
                        $errors++;
                    }
                } catch (Throwable $e) {
                    $errors++;
                    self::safe_progress("⚠️ Fatal in batch {$batch_num} on post {$post_id}: " . $e->getMessage());
                }
                usleep(300000);
            }

            self::safe_progress("=== Finished batch {$batch_num} ({$indexed} indexed so far) ===");
            sleep(2); // cooldown between batches
        }

        $final = "=== Batch Index Complete: {$indexed}/{$total} posts, {$chunks_created} chunks, {$errors} errors ===";
        self::safe_progress($final);
        return ['success' => true, 'message' => $final];
    }

    /**
     * Main Indexing Process (with safe try/catch)
     */
    public static function index_all_content() {
        $indexed = 0;
        $errors = 0;
        $error_details = [];
        $chunks_created = 0;

        self::safe_progress("=== AI Chatbot: Starting Full Index ===");

        $args = [
            'post_type' => ['post', 'page', 'product'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        $posts = get_posts($args);
        $total = count($posts);

        self::log_to_admin(0, true, "=== Indexing Started: {$total} posts ===");
        self::safe_progress("Found {$total} posts to index");

        foreach ($posts as $post_id) {
            try {
                $result = self::index_single_post($post_id, false);
            } catch (Throwable $e) {
                $errors++;
                $error_details[] = "Fatal on post {$post_id}: " . $e->getMessage();
                self::safe_progress("⚠️ Fatal error on post {$post_id}: " . $e->getMessage());
                continue;
            }

            if ($result['success']) {
                $indexed++;
                $chunks_created += $result['chunks'];
                self::log_to_admin($post_id, true, "Indexed with {$result['chunks']} chunks");
            } else {
                $errors++;
                $error_details[] = "Post ID {$post_id}: {$result['reason']}";
                self::log_to_admin($post_id, false, $result['reason']);
            }

            usleep(500000);

            if (($indexed + $errors) % 10 === 0) {
                $progress = "Progress: {$indexed}/{$total} indexed, {$chunks_created} chunks created, {$errors} errors";
                self::safe_progress($progress);
                self::log_to_admin(0, true, $progress);
            }
        }

        $final_msg = "=== Indexing Complete: {$indexed} indexed, {$chunks_created} chunks, {$errors} errors ===";
        self::safe_progress($final_msg);
        self::log_to_admin(0, true, $final_msg);

        return [
            'success' => true,
            'message' => "Indexed {$indexed} of {$total} items with {$chunks_created} chunks. {$errors} errors.",
            'indexed' => $indexed,
            'errors' => $errors,
            'total' => $total,
            'chunks' => $chunks_created,
            'error_details' => $error_details
        ];
    }

    // ==== Original methods start here (all preserved) ====

    /**
     * Index single post - FIXED VERSION (unchanged)
     */
    public static function index_single_post($post_id, $check_auto_index = true) {
        $post = get_post($post_id);
        if (!$post) {
            self::safe_progress("Post {$post_id} not found");
            return ['success' => false, 'reason' => 'Post not found', 'chunks' => 0];
        }
        if ($post->post_status !== 'publish') {
            return ['success' => false, 'reason' => 'Not published', 'chunks' => 0];
        }

        // Auto-index toggle
        if ($check_auto_index && doing_action('save_post')) {
            $settings = get_option('aichat_settings', []);
            if (($settings['auto_index'] ?? 'yes') !== 'yes') {
                return ['success' => false, 'reason' => 'Auto-index disabled', 'chunks' => 0];
            }
        }

        self::safe_progress("Indexing post {$post_id} - {$post->post_title}");

        $full_content = self::extract_all_content($post);
        if (empty(trim($full_content))) {
            self::safe_progress("No content extracted for post {$post_id}");
            return ['success' => false, 'reason' => 'No content extracted', 'chunks' => 0];
        }

        $content_length = strlen($full_content);
        self::safe_progress("Extracted {$content_length} characters from post {$post_id}");

        $title = $post->post_title;
        $url   = get_permalink($post_id);
        $chunks = self::split_into_chunks($full_content, $title);

        if (empty($chunks)) {
            self::safe_progress("No chunks created for post {$post_id}");
            return ['success' => false, 'reason' => 'Content too short', 'chunks' => 0];
        }

        $openai   = new AIChatbot_OpenAI();
        $supabase = new AIChatbot_Supabase();

        // Delete old embeddings
        try {
            $supabase->delete_embedding($post_id);
        } catch (Throwable $e) {
            self::safe_progress("⚠️ Delete old embeddings failed for post {$post_id}: " . $e->getMessage());
        }

        $chunks_stored = 0;
        foreach ($chunks as $chunk_index => $chunk_content) {
            self::safe_progress("Processing chunk {$chunk_index} for post {$post_id}");

            $chunk_length = strlen($chunk_content);
            if ($chunk_length < 50) {
                self::safe_progress("Skipping short chunk ({$chunk_length} chars)");
                continue;
            }

            try {
                $embedding = $openai->get_embedding($chunk_content);
            } catch (Throwable $e) {
                self::safe_progress("⚠️ Embedding error: " . $e->getMessage());
                continue;
            }

            if (!$embedding || !is_array($embedding)) {
                self::safe_progress("Failed to create embedding for chunk {$chunk_index}");
                continue;
            }

            $chunk_title = $title;
            if (count($chunks) > 1) {
                $chunk_title .= " (Part " . ($chunk_index + 1) . "/" . count($chunks) . ")";
            }

            $result = false;
            try {
                $result = $supabase->insert_embedding(
                    $post_id, $chunk_title, $chunk_content, $url, $embedding, $chunk_index
                );
            } catch (Throwable $e) {
                self::safe_progress("⚠️ Supabase insert failed: " . $e->getMessage());
            }

            if ($result) {
                $chunks_stored++;
                self::safe_progress("✅ Chunk {$chunk_index} stored");
            } else {
                self::safe_progress("❌ Failed to store chunk {$chunk_index}");
            }

            usleep(250000);
        }

        if ($chunks_stored === 0) {
            self::safe_progress("No chunks stored for post {$post_id}");
            return ['success' => false, 'reason' => 'Failed to store chunks', 'chunks' => 0];
        }

        self::safe_progress("✅ Indexed post {$post_id} with {$chunks_stored} chunks");
        return ['success' => true, 'reason' => 'Success', 'chunks' => $chunks_stored];
    }

    /** ---------------------- EXTRACTION ---------------------- */
    private static function extract_all_content($post) {
        $content_parts = [];

        if (!empty($post->post_title)) $content_parts[] = "TITLE: " . $post->post_title;
        if (!empty($post->post_excerpt)) $content_parts[] = "EXCERPT: " . $post->post_excerpt;

        $main_content = $post->post_content;
        if (!empty($main_content)) {
            $main_content = apply_filters('the_content', $main_content);
            $tables_text  = self::extract_tables_as_text($main_content);
            if (!empty($tables_text)) $content_parts[] = "\n=== TABLES DATA ===\n" . $tables_text;
            $main_content = wp_strip_all_tags($main_content, true);
            $main_content = trim($main_content);
            if (!empty($main_content)) $content_parts[] = $main_content;
        }

        $meta_data = self::extract_all_metadata($post->ID);
        if (!empty($meta_data)) $content_parts[] = "\n=== ADDITIONAL DATA ===\n" . $meta_data;

        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post->ID);
            if ($acf_fields && is_array($acf_fields)) {
                $acf_text = self::format_acf_fields($acf_fields);
                if (!empty($acf_text)) $content_parts[] = "\n=== CUSTOM FIELDS ===\n" . $acf_text;
            }
        }

        $taxonomies = self::extract_taxonomies($post->ID);
        if (!empty($taxonomies)) $content_parts[] = "\n=== CATEGORIES & TAGS ===\n" . $taxonomies;

        if ($post->post_type === 'product' && class_exists('WooCommerce')) {
            $product_data = self::extract_woocommerce_data($post->ID);
            if (!empty($product_data)) $content_parts[] = "\n=== PRODUCT INFORMATION ===\n" . $product_data;
        }

        $full_content = implode("\n\n", array_filter($content_parts));
        $full_content = preg_replace('/\n{3,}/', "\n\n", $full_content);
        $full_content = preg_replace('/[ \t]+/', ' ', $full_content);
        return trim($full_content);
    }

    private static function extract_tables_as_text($html) {
        $tables_text = '';
        preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $html, $tables);
        if (empty($tables[0])) return '';
        foreach ($tables[0] as $i => $table_html) {
            $tables_text .= "\n--- Table " . ($i + 1) . " ---\n";
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $rows);
            foreach ($rows[1] as $row_html) {
                preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $row_html, $cells);
                $row_data = [];
                foreach ($cells[1] as $cell_html) {
                    $cell_text = trim(wp_strip_all_tags($cell_html));
                    if ($cell_text !== '') $row_data[] = $cell_text;
                }
                if (!empty($row_data)) $tables_text .= implode(' | ', $row_data) . "\n";
            }
            $tables_text .= "\n";
        }
        return trim($tables_text);
    }

    private static function extract_all_metadata($post_id) {
        $meta_text = '';
        $all_meta = get_post_meta($post_id);
        if (!is_array($all_meta)) return '';
        $skip_keys = ['_edit_lock', '_edit_last', '_wp_page_template', '_thumbnail_id', '_wp_old_slug'];
        foreach ($all_meta as $key => $values) {
            if (strpos($key, '_') === 0 && !in_array($key, ['_price','_regular_price','_sale_price'])) continue;
            if (in_array($key, $skip_keys)) continue;
            if (!is_array($values)) continue;
            foreach ($values as $value) {
                if (is_serialized($value)) {
                    $value = maybe_unserialize($value);
                    if (is_array($value) || is_object($value)) $value = print_r($value, true);
                }
                $value = trim(strval($value));
                if ($value && strlen($value) < 5000) {
                    $label = ucfirst(str_replace('_', ' ', $key));
                    $meta_text .= "{$label}: {$value}\n";
                }
            }
        }
        return trim($meta_text);
    }

    private static function format_acf_fields($fields, $prefix = '') {
        $text = '';
        if (!is_array($fields)) return '';
        foreach ($fields as $key => $value) {
            $label = $prefix . ucfirst(str_replace('_', ' ', $key));
            if (is_array($value)) {
                $text .= self::format_acf_fields($value, $label . ' - ');
            } else {
                $value = trim(strval($value));
                if ($value !== '') $text .= "{$label}: {$value}\n";
            }
        }
        return $text;
    }

    private static function extract_taxonomies($post_id) {
        $post = get_post($post_id);
        if (!$post) return '';
        $taxonomies = get_object_taxonomies($post);
        $tax_text = '';
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                $names = array_filter(array_map(fn($t) => $t->name ?? '', $terms));
                if ($names) $tax_text .= ucfirst($taxonomy) . ': ' . implode(', ', $names) . "\n";
            }
        }
        return trim($tax_text);
    }

    private static function extract_woocommerce_data($post_id) {
        if (!function_exists('wc_get_product')) return '';
        $product = wc_get_product($post_id);
        if (!$product) return '';
        $data = [];
        $data[] = "Product Type: " . $product->get_type();
        if ($product->get_price_html()) $data[] = "Price: " . wp_strip_all_tags($product->get_price_html());
        if ($product->get_regular_price()) $data[] = "Regular Price: $" . $product->get_regular_price();
        if ($product->is_on_sale() && $product->get_sale_price()) $data[] = "Sale Price: $" . $product->get_sale_price();
        if ($product->get_sku()) $data[] = "SKU: " . $product->get_sku();
        $data[] = "Stock Status: " . $product->get_stock_status();
        if ($product->get_short_description()) {
            $desc = wp_strip_all_tags($product->get_short_description());
            $data[] = "Short Description: " . $desc;
        }
        $attrs = $product->get_attributes();
        if ($attrs && is_array($attrs)) {
            $data[] = "\nProduct Attributes:";
            foreach ($attrs as $attr) {
                if (method_exists($attr,'get_name') && method_exists($attr,'get_options')) {
                    $opts = $attr->get_options();
                    if (is_array($opts)) $data[] = $attr->get_name() . ': ' . implode(', ', $opts);
                }
            }
        }
        return implode("\n", array_filter($data));
    }

    private static function split_into_chunks($content, $title) {
        $len = strlen($content);
        if ($len <= self::MAX_CHUNK_SIZE) return [$content];
        $chunks = [];
        $start = 0;
        while ($start < $len) {
            $chunk = substr($content, $start, self::MAX_CHUNK_SIZE);
            $chunks[] = "Title: {$title}\n\n{$chunk}";
            $start += self::MAX_CHUNK_SIZE - self::CHUNK_OVERLAP;
        }
        self::safe_progress("Created " . count($chunks) . " chunks");
        return $chunks;
    }

    public static function delete_post_embedding($post_id) {
        $supabase = new AIChatbot_Supabase();
        try {
            $deleted = $supabase->delete_embedding($post_id);
        } catch (Throwable $e) {
            self::safe_progress("⚠️ Delete embedding failed: " . $e->getMessage());
            return false;
        }
        self::safe_progress($deleted ? "Deleted embeddings for post {$post_id}" : "Failed to delete embeddings for {$post_id}");
        return $deleted;
    }

    /**
     * Log to admin (stored in options)
     */
    private static function log_to_admin($post_id, $success, $message) {
        $logs = get_option('aichat_indexing_logs', []);
        $logs[] = [
            'time'    => current_time('Y-m-d H:i:s'),
            'post_id' => $post_id,
            'success' => $success,
            'message' => $message
        ];
        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }
        update_option('aichat_indexing_logs', $logs);
    }

    /**
     * Write to dedicated debug log
     */
    private static function write_debug_log($message) {
        $log_file = WP_CONTENT_DIR . '/debug-indexing.log';
        $timestamp = '[' . date('Y-m-d H:i:s') . '] ';
        @file_put_contents($log_file, $timestamp . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// Delete embeddings when post is deleted
add_action('before_delete_post', function($post_id) {
    AIChatbot_Indexer::delete_post_embedding($post_id);
});
