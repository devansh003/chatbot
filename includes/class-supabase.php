<?php
/**
 * Supabase Handler – FINAL UNIVERSAL VERSION (2025-11-05)
 * 
 * ✅ Hybrid vector + keyword + direct phrase + fuzzy search
 * ✅ Scope-aware fuzzy fallback (pg_trgm)
 * ✅ Deduplication by URL & post_id
 * ✅ Keeps contact, RPC, and title matching intact
 */

class AIChatbot_Supabase {

    private $url;
    private $api_key;
    private $table_name = 'chatbot_embeddings';

    public function __construct() {
        $settings = get_option('aichat_settings', []);
        $this->url = rtrim($settings['supabase_url'] ?? '', '/');
        $this->api_key = $settings['supabase_api_key'] ?? '';
    }

    public function test_connection() {
        if (empty($this->url) || empty($this->api_key)) {
            error_log('Supabase: URL or API key is empty');
            return false;
        }
        
        $response = wp_remote_get($this->url . '/rest/v1/', array(
            'headers' => array(
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase Connection Test Failed: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            error_log('Supabase Connection Test: SUCCESS');
            return true;
        }
        
        error_log('Supabase Connection Test Failed: HTTP ' . $code);
        return false;
    }


    public function insert_embedding($post_id, $title, $content, $url, $embedding, $chunk_index = 0) {
        if (empty($this->url) || empty($this->api_key)) {
            $error = 'Missing Supabase credentials';
            $this->log_to_admin($post_id, false, $error);
            error_log('Supabase Insert Error: ' . $error);
            return false;
        }
        
        // VALIDATE EMBEDDING
        if (!is_array($embedding)) {
            $error = 'Embedding is not an array';
            $this->log_to_admin($post_id, false, $error);
            error_log('Supabase Insert Error: ' . $error);
            return false;
        }
        
        if (count($embedding) !== 1536) {
            $error = 'Invalid embedding dimensions: expected 1536, got ' . count($embedding);
            $this->log_to_admin($post_id, false, $error);
            error_log('Supabase Insert Error: ' . $error);
            return false;
        }
        
        // Validate all embedding values are numeric
        foreach ($embedding as $value) {
            if (!is_numeric($value)) {
                $error = 'Embedding contains non-numeric values';
                $this->log_to_admin($post_id, false, $error);
                error_log('Supabase Insert Error: ' . $error);
                return false;
            }
        }
        
        // Validate required fields
        if (empty($title) || empty($content) || empty($url)) {
            $error = 'Missing required fields (title, content, or url)';
            $this->log_to_admin($post_id, false, $error);
            error_log('Supabase Insert Error: ' . $error);
            return false;
        }
        
        $data = array(
            'post_id' => (int)$post_id,
            'title' => $title,
            'content' => $content,
            'url' => $url,
            'embedding' => array_values($embedding), // Ensure indexed array
            'site_url' => get_site_url(),
            'chunk_index' => (int)$chunk_index
        );
        
        $response = wp_remote_post($this->url . '/rest/v1/' . $this->table_name, array(
            'headers' => array(
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'  // CHANGED from return=minimal
            ),
            'body' => json_encode($data),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            $error_msg = 'Supabase request error: ' . $response->get_error_message();
            $this->log_to_admin($post_id, false, $error_msg);
            error_log($error_msg);
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $success = $code === 201 || $code === 200;
        
        if (!$success) {
            $error_msg = 'Supabase HTTP ' . $code . ': ' . substr($body, 0, 500);
            $this->log_to_admin($post_id, false, $error_msg);
            error_log('Supabase Insert Failed: ' . $error_msg);
        } else {
            $success_msg = "Chunk {$chunk_index} stored successfully (HTTP {$code})";
            $this->log_to_admin($post_id, true, $success_msg);
            error_log("Supabase: Post ID {$post_id}, Chunk {$chunk_index} - SUCCESS");
        }
        
        return $success;
    }
    
    /**
     * Delete all embeddings for a post (including all chunks)
     */
    public function delete_embedding($post_id) {
        if (empty($this->url) || empty($this->api_key)) {
            error_log('Supabase Delete: Missing credentials');
            return false;
        }
        
        $site_url = get_site_url();
        
        // Delete all chunks for this post_id
        $response = wp_remote_request($this->url . '/rest/v1/' . $this->table_name . '?post_id=eq.' . $post_id . '&site_url=eq.' . urlencode($site_url), array(
            'method' => 'DELETE',
            'headers' => array(
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase Delete Error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $success = $code === 204 || $code === 200;
        
        if ($success) {
            error_log("Supabase: Deleted embeddings for post ID {$post_id}");
        } else {
            error_log("Supabase: Delete failed for post ID {$post_id} - HTTP {$code}");
        }
        
        return $success;
    }
    
    /**
     * Delete ALL embeddings for this site
     */
    public function delete_all_embeddings() {
        if (empty($this->url) || empty($this->api_key)) {
            error_log('Supabase Delete All: Missing credentials');
            return false;
        }
        
        $site_url = get_site_url();
        
        $response = wp_remote_request($this->url . '/rest/v1/' . $this->table_name . '?site_url=eq.' . urlencode($site_url), array(
            'method' => 'DELETE',
            'headers' => array(
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase Delete All Error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $success = $code === 204 || $code === 200;
        
        if ($success) {
            error_log('Supabase: Deleted all embeddings for site ' . $site_url);
        } else {
            error_log('Supabase: Delete all failed - HTTP ' . $code);
        }
        
        return $success;
    }


    /* ---------------------------------------------------------
       🔑 Main hybrid search
    --------------------------------------------------------- */
    public function search_similar_content($query_embedding, $limit = 5, $user_query_text = '', $intent = 'general') {
        if (empty($this->url) || empty($this->api_key)) {
            error_log('Supabase Search: Missing credentials');
            return $this->get_fallback_content($limit);
        }

        $site_url = get_site_url();
        $results = [];

        // --- 1️⃣ Base vector search
        $vector_hits = $this->try_rpc_search($query_embedding, $limit, $site_url);
        if (!empty($vector_hits)) $results = array_merge($results, $vector_hits);

        // --- 2️⃣ Intent-aware keyword supplementation
        if ($intent === 'pricing') {
            $pricing_hits = $this->keyword_fallback_search('pricing', 6);
            $service_hits = $this->keyword_fallback_search('service', 6);
            $results = array_merge($results, $pricing_hits, $service_hits);
            error_log("Supabase: Added pricing + service keyword hits");
        } elseif ($intent === 'services') {
            $service_hits = $this->keyword_fallback_search('services', 8);
            $results = array_merge($results, $service_hits);
        }

        // --- 3️⃣ REST fallback if still empty
        if (empty($results)) {
            $rest_hits = $this->get_content_via_rest($site_url, $limit);
            if (!empty($rest_hits)) $results = array_merge($results, $rest_hits);
        }

        // --- 4️⃣ Keyword fallback if absolutely nothing
        if (empty($results)) {
            $keyword_hits = $this->search_by_keyword($user_query_text, $limit);
            if (!empty($keyword_hits)) $results = array_merge($results, $keyword_hits);
        }

        // --- 5️⃣ Filter out generic pages
        $filtered = [];
        foreach ($results as $r) {
            $t = strtolower($r['title'] ?? '');
            $u = strtolower($r['url'] ?? '');
            if (preg_match('/about\s+us/i', $t) ||
                preg_match('/^https?:\/\/[^\/]+\/(about|about-us|resources|partnerships)\/?$/i', $u)) {
                error_log("Supabase: Skipping generic page => " . ($r['title'] ?? 'Unknown'));
                continue;
            }
            $filtered[] = $r;
        }

        // --- 6️⃣ Deduplicate by post_id
        $unique = [];
        $seen = [];
        foreach ($filtered as $row) {
            $pid = $row['post_id'] ?? null;
            if ($pid && in_array($pid, $seen, true)) continue;
            $seen[] = $pid;
            $unique[] = $row;
        }

        // --- 7️⃣ Intent filtering by tokens
        if (!empty($user_query_text)) {
            $query = strtolower(str_replace('-', ' ', $user_query_text));
            $tokens = array_filter(preg_split('/[\s,\.\?\!\-\/]+/', $query), fn($t) => strlen($t) > 2);

            $scored = [];
            foreach ($unique as $item) {
                $content = strtolower($item['content'] ?? '');
                $title   = strtolower($item['title'] ?? '');
                $url     = $item['url'] ?? '';
                $hits = 0;
                $matched = [];

                foreach ($tokens as $token) {
                    if (strpos($content, $token) !== false || strpos($title, $token) !== false) {
                        $hits++; $matched[] = $token;
                    }
                }
                $ratio = count($tokens) ? $hits / count($tokens) : 0;
                if ($ratio >= 0.15) {
                    error_log("Supabase: ✅ Matched URL => {$url} (ratio {$ratio}) [tokens: " . implode(',', $matched) . "]");
                    $scored[] = $item;
                }
            }
            if (!empty($scored)) $unique = $scored;
        }

        // --- 8️⃣ Final clean keyword verification
        if (!empty($user_query_text)) {
            $clean_kw = strtolower(trim(preg_replace('/\b(you|do|offer|what|is|the|a|an)\b/', '', $user_query_text)));
            $filtered_final = [];
            foreach ($unique as $r) {
                $content = strtolower($r['content'] ?? '');
                $title = strtolower($r['title'] ?? '');
                // Looser final check for partial / stemmed match
if (stripos($content, $clean_kw) !== false || stripos($title, $clean_kw) !== false) {
    $filtered_final[] = $r;
} elseif (preg_match('/' . preg_quote($clean_kw, '/') . '/i', $content)) {
    $filtered_final[] = $r;
}
            }
            if (!empty($filtered_final)) $unique = $filtered_final;
        }

        error_log("Supabase: Returning " . count($unique) . " filtered results (final)");
        return $unique ?: $this->get_fallback_content($limit);
    }

    /* ---------------------------------------------------------
       🔍 Exact phrase search
    --------------------------------------------------------- */
    public function direct_phrase_search($phrase, $limit = 10) {
        if (empty($phrase)) return [];
        $site_url = get_site_url();
        $encoded = urlencode(trim($phrase));

        $endpoint = $this->url . '/rest/v1/' . $this->table_name .
            '?select=id,post_id,title,content,url' .
            '&or=(content.ilike.*' . $encoded . '*,title.ilike.*' . $encoded . '*)' .
            '&site_url=eq.' . urlencode($site_url) .
            '&limit=' . intval($limit);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) return [];
        if (wp_remote_retrieve_response_code($response) !== 200) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data)) return [];

        $unique = [];
        $seen = [];
        foreach ($data as $item) {
            $url = $item['url'] ?? '';
            if (in_array($url, $seen, true)) continue;
            $seen[] = $url;
            $unique[] = [
                'id' => $item['id'] ?? 0,
                'post_id' => $item['post_id'] ?? 0,
                'title' => $item['title'] ?? 'Content',
                'content' => wp_strip_all_tags($item['content'] ?? ''),
                'url' => $url,
                'similarity' => 0.95
            ];
        }
        return $unique;
    }

    /* ---------------------------------------------------------
       🧭 Vector RPC
    --------------------------------------------------------- */
    private function try_rpc_search($query_embedding, $limit, $site_url = null) {
        $endpoint = $this->url . '/rest/v1/rpc/match_embeddings';
        $body = [
            'query_embedding' => array_values(array_map('floatval', $query_embedding)),
            'match_threshold' => 0.5,
            'match_count' => $limit,
            'filter_site_url' => $site_url
        ];
        $res = wp_remote_post($endpoint, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return [];
        $out = json_decode(wp_remote_retrieve_body($res), true);
        return array_values(array_filter($out, fn($i) => isset($i['similarity']) && $i['similarity'] > 0.5));
    }

    /* ---------------------------------------------------------
       🌐 REST fallback
    --------------------------------------------------------- */
    private function get_content_via_rest($site_url, $limit) {
        $endpoints = [
            "{$this->url}/rest/v1/{$this->table_name}?select=id,post_id,title,content,url&site_url=eq." . urlencode($site_url) . "&limit={$limit}",
            "{$this->url}/rest/v1/{$this->table_name}?select=id,post_id,title,content,url&limit={$limit}"
        ];
        foreach ($endpoints as $endpoint) {
            $r = wp_remote_get($endpoint, [
                'headers' => [
                    'apikey' => $this->api_key,
                    'Authorization' => 'Bearer ' . $this->api_key
                ],
                'timeout' => 15
            ]);
            if (is_wp_error($r)) continue;
            if (wp_remote_retrieve_response_code($r) !== 200) continue;
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (!empty($d)) {
                $out = [];
                foreach ($d as $i => $it)
                    $out[] = [
                        'id' => $it['id'] ?? 0,
                        'post_id' => $it['post_id'] ?? 0,
                        'title' => $it['title'] ?? 'Content',
                        'content' => $it['content'] ?? '',
                        'url' => $it['url'] ?? $site_url,
                        'similarity' => 0.7 - ($i * 0.05)
                    ];
                return $out;
            }
        }
        return [];
    }

    /* ---------------------------------------------------------
       🔠 Keyword + normalization + fuzzy fallback
    --------------------------------------------------------- */
    public function search_by_keyword($keyword, $limit = 10, $scope = 'both') {
        try {
            if (empty($this->url) || empty($this->api_key)) return [];

            $clean = trim(strtolower($keyword));
            if (empty($clean)) return [];

            // 1️⃣ direct match
            $data = $this->run_supabase_query($clean, $limit, $scope);
            if (!empty($data))
                return $this->format_results($data);

            // 2️⃣ normalized
            $normalized = $this->normalize_keyword_generic($clean);
            if ($normalized !== $clean) {
                $data = $this->run_supabase_query($normalized, $limit, $scope);
                if (!empty($data))
                    return $this->format_results($data);
            }

            // 3️⃣ fuzzy pg_trgm fallback
            $data = $this->run_fuzzy_query($clean, $limit, $scope);
            if (!empty($data))
                return $this->format_results($data);

            return [];
        } catch (Exception $e) {
            error_log("Supabase Keyword Search Exception: " . $e->getMessage());
            return [];
        }
    }

    private function run_supabase_query($term, $limit, $scope) {
        $encoded = urlencode($term);
        switch ($scope) {
            case 'title': $filter = "title.ilike.*{$encoded}*"; break;
            case 'content': $filter = "content.ilike.*{$encoded}*"; break;
            default: $filter = "or=(content.ilike.*{$encoded}*,title.ilike.*{$encoded}*)"; break;
        }
        $endpoint = "{$this->url}/rest/v1/{$this->table_name}?select=id,post_id,title,content,url&{$filter}&limit={$limit}";
        $r = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'timeout' => 20
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return [];
        return json_decode(wp_remote_retrieve_body($r), true);
    }

    private function run_fuzzy_query($term, $limit = 10, $scope = 'both') {
        try {
            $encoded = urlencode($term);
            $endpoint = "{$this->url}/rest/v1/rpc/fuzzy_search?term={$encoded}&limit={$limit}&scope=" . urlencode($scope);
            $r = wp_remote_get($endpoint, [
                'headers' => [
                    'apikey' => $this->api_key,
                    'Authorization' => 'Bearer ' . $this->api_key
                ],
                'timeout' => 25
            ]);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return [];
            $d = json_decode(wp_remote_retrieve_body($r), true);
            return $d ?? [];
        } catch (Exception $e) {
            error_log("Supabase Fuzzy Query Exception: " . $e->getMessage());
            return [];
        }
    }

    private function normalize_keyword_generic($keyword) {
        $keyword = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $keyword)));
        $keyword = preg_replace('/\s+/', ' ', $keyword);
        $tokens = explode(' ', $keyword);
        $normalized = [];
        foreach ($tokens as $word) {
            $stem = preg_replace('/(ing|ed|es|s|ly|er|est|tion|ions|ment|ments)$/i', '', $word);
            if (strlen($stem) > 2) $normalized[] = $stem;
        }
        $normalized_phrase = implode(' ', array_unique($normalized));
        error_log("Supabase: Normalized '{$keyword}' → '{$normalized_phrase}'");
        return $normalized_phrase;
    }

    private function format_results($data) {
        $results = []; $seen = [];
        foreach ($data as $i => $it) {
            $url = $it['url'] ?? '';
            if (in_array($url, $seen, true)) continue;
            $seen[] = $url;
            $results[] = [
                'id' => $it['id'] ?? 0,
                'post_id' => $it['post_id'] ?? 0,
                'title' => $it['title'] ?? 'Content',
                'content' => wp_strip_all_tags($it['content'] ?? ''),
                'url' => $url,
                'similarity' => 0.55 - ($i * 0.05)
            ];
        }
        return $results;
    }

    /* ---------------------------------------------------------
       📞 Contact page
    --------------------------------------------------------- */
    public function get_contact_page_content() {
        $contact_post_id = 2401;
        $site_url = get_site_url();
        $endpoint = "{$this->url}/rest/v1/{$this->table_name}?select=id,post_id,title,content,url&post_id=eq.{$contact_post_id}&site_url=eq." . urlencode($site_url);
        $r = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'timeout' => 20
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return [];
        $d = json_decode(wp_remote_retrieve_body($r), true);
        if (!empty($d)) return [array_merge($d[0], ['similarity' => 4.0])];
        return [];
    }

    /* -----------------------------------------------------------
       RPC utility + title embedding
    --------------------------------------------------------- */
    public function rpc($function, $params = []) {
        if (empty($this->url) || empty($this->api_key)) return [];
        $url = rtrim($this->url, '/') . '/rest/v1/rpc/' . $function;
        $res = wp_remote_post($url, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($params),
            'timeout' => 20
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 400) return [];
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : [];
    }

    public function search_similar_titles($embedding, $limit = 8) {
        if (!is_array($embedding)) return [];
        $r = $this->rpc('match_titles', [
            'query_embedding' => $embedding,
            'match_threshold' => 0.6,
            'match_count' => $limit
        ]);
        if (empty($r)) return [];
        $out = [];
        foreach ($r as $it)
            $out[] = [
                'title' => $it['title'] ?? '',
                'url' => $it['url'] ?? '',
                'content' => $it['content'] ?? '',
                'similarity' => floatval($it['similarity'] ?? 0)
            ];
        usort($out, fn($a,$b)=>$b['similarity']<=>$a['similarity']);
        return $out;
    }

    public function extract_roles_from_wcag_content($content) {
        // Match " | Role(s)" pattern at end of a table row
        if (preg_match('/\|\s*([A-Za-z,\s]+)\s*$/m', $content, $m)) {
            $roles = array_map('trim', explode(',', $m[1]));
            return array_filter($roles);
        }
        return [];
    }

    private function log_to_admin($post_id, $success, $message) {
        $logs = get_option('aichat_indexing_logs', array());
        
        $logs[] = array(
            'time' => current_time('Y-m-d H:i:s'),
            'post_id' => $post_id,
            'success' => $success,
            'message' => $message
        );
        
        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }
        
        update_option('aichat_indexing_logs', $logs);
    }
}

/* ---------------------------------------------------------
   🧭 Extract Roles from WCAG checklist-style content
--------------------------------------------------------- */

/* -----------------------------------------------------------
   🧪 WP-CLI Test Command for Fuzzy Search
   Usage: wp supabase test "advanced remediation"
----------------------------------------------------------- */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('supabase test', function($args) {
        list($term) = $args;
        $supabase = new AIChatbot_Supabase();
        $results = $supabase->search_by_keyword($term, 5, 'both');
        if (empty($results)) {
            WP_CLI::warning("No results found for '{$term}'");
        } else {
            foreach ($results as $r) {
                WP_CLI::log("• {$r['title']} ({$r['url']})");
            }
        }
    });

}

