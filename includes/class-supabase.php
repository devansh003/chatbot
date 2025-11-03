<?php
/**
 * Supabase Handler – FIXED VERSION (2025-11-02)
 *
 * ✅ Hybrid vector + keyword search
 * ✅ Preserves contact logic
 * ✅ Correctly fetches specific URLs like partnership white-labeling
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

        // --- 1️⃣ Base vector search ---
        $vector_hits = $this->try_rpc_search($query_embedding, $limit, $site_url);
        if (!empty($vector_hits)) $results = array_merge($results, $vector_hits);

        // --- 2️⃣ Intent-aware keyword supplementation ---
        if ($intent === 'pricing') {
            $pricing_hits = $this->keyword_fallback_search('pricing', 6);
            $service_hits = $this->keyword_fallback_search('service', 6);
            $results = array_merge($results, $pricing_hits, $service_hits);
            error_log("Supabase: Added pricing + service keyword hits");
        } elseif ($intent === 'services') {
            $service_hits = $this->keyword_fallback_search('services', 8);
            $results = array_merge($results, $service_hits);
        }

        // --- 3️⃣ REST fallback if still empty ---
        if (empty($results)) {
            $rest_hits = $this->get_content_via_rest($site_url, $limit);
            if (!empty($rest_hits)) $results = array_merge($results, $rest_hits);
        }

        // --- 4️⃣ Keyword fallback if absolutely nothing ---
        if (empty($results)) {
            $keyword_hits = $this->keyword_fallback_search($user_query_text, $limit);
            if (!empty($keyword_hits)) $results = array_merge($results, $keyword_hits);
        }

        // --- 5️⃣ Filter out generic root-level or noise pages ---
        $filtered = [];
        foreach ($results as $r) {
            $t = strtolower($r['title'] ?? '');
            $u = strtolower($r['url'] ?? '');
            if (
                preg_match('/about\s+us/i', $t) ||
                preg_match('/^https?:\/\/[^\/]+\/(about|about-us|resources|partnerships)\/?$/i', $u)
            ) {
                error_log("Supabase: Skipping generic page => " . ($r['title'] ?? 'Unknown'));
                continue;
            }
            $filtered[] = $r;
        }

        // --- 6️⃣ Deduplicate by post_id ---
        $unique = [];
        $seen = [];
        foreach ($filtered as $row) {
            $pid = $row['post_id'] ?? null;
            if ($pid && in_array($pid, $seen, true)) continue;
            $seen[] = $pid;
            $unique[] = $row;
        }

        // --- 7️⃣ Content-intent filtering (enhanced) ---
        if (!empty($user_query_text)) {
            $query = strtolower(str_replace('-', ' ', $user_query_text));
            $tokens = preg_split('/[\s,\.\?\!\-\/]+/', $query);
            $tokens = array_filter($tokens, fn($t) => strlen($t) > 2);

            $scored = [];
            foreach ($unique as $item) {
                $content = strtolower($item['content'] ?? '');
                $title   = strtolower($item['title'] ?? '');
                $url     = $item['url'] ?? '';
                $hits = 0;
                $matched_tokens = [];

                foreach ($tokens as $token) {
                    if (strpos($content, $token) !== false || strpos($title, $token) !== false) {
                        $hits++;
                        $matched_tokens[] = $token;
                    }
                }
                $ratio = count($tokens) ? $hits / count($tokens) : 0;

                if ($ratio >= 0.15) {
                    error_log("Supabase: ✅ Matched URL => {$url} (ratio {$ratio}) [tokens: " . implode(',', $matched_tokens) . "]");
                    $scored[] = $item;
                } else {
                    error_log("Supabase: ❌ Skipped {$url} (ratio {$ratio})");
                }
            }

            if (!empty($scored)) {
                $unique = $scored;
                error_log("Supabase: Content-intent filter retained " . count($unique) . " items");
            }
        }

        error_log("Supabase: Returning " . count($unique) . " filtered results (final)");
        return $unique ?: $this->get_fallback_content($limit);
    }

    /* ---------------------------------------------------------
       🔎 Vector RPC search
    --------------------------------------------------------- */
    private function try_rpc_search($query_embedding, $limit, $site_url = null) {
        $endpoint = $this->url . '/rest/v1/rpc/match_embeddings';
        $request_body = [
            'query_embedding' => array_values(array_map('floatval', $query_embedding)),
            'match_threshold' => 0.5,
            'match_count' => $limit,
            'filter_site_url' => $site_url
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($request_body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('Supabase RPC Error: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('Supabase RPC Failed: HTTP ' . $code);
            return [];
        }

        $results = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($results)) return [];

        return array_values(array_filter($results, fn($item) =>
            isset($item['similarity']) && $item['similarity'] > 0.5
        ));
    }

    /* ---------------------------------------------------------
       🌐 REST fallback when RPC unavailable
    --------------------------------------------------------- */
    private function get_content_via_rest($site_url, $limit) {
        $endpoints = [
            $this->url . '/rest/v1/' . $this->table_name .
                '?select=id,post_id,title,content,url&site_url=eq.' . urlencode($site_url) . '&limit=' . $limit,
            $this->url . '/rest/v1/' . $this->table_name .
                '?select=id,post_id,title,content,url&limit=' . $limit
        ];

        foreach ($endpoints as $endpoint) {
            $response = wp_remote_get($endpoint, [
                'headers' => [
                    'apikey' => $this->api_key,
                    'Authorization' => 'Bearer ' . $this->api_key
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($response)) continue;
            if (wp_remote_retrieve_response_code($response) !== 200) continue;

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data)) {
                $results = [];
                foreach ($data as $i => $item) {
                    $results[] = [
                        'id' => $item['id'] ?? 0,
                        'post_id' => $item['post_id'] ?? 0,
                        'title' => $item['title'] ?? 'Content',
                        'content' => $item['content'] ?? '',
                        'url' => $item['url'] ?? $site_url,
                        'similarity' => 0.7 - ($i * 0.05)
                    ];
                }
                return $results;
            }
        }
        return [];
    }

    /* ---------------------------------------------------------
       🪄 Keyword fallback search (improved)
    --------------------------------------------------------- */
    private function keyword_fallback_search($text, $limit = 5) {
        if (empty($text)) return [];

        $site_url = get_site_url();

        // handle both spaced and hyphenated forms
        $clean = strtolower(trim($text));
        $alt = str_replace(' ', '-', $clean);
        $encoded = urlencode($clean);
        $encoded_alt = urlencode($alt);

        $endpoint = $this->url . '/rest/v1/' . $this->table_name .
            '?select=id,post_id,title,content,url' .
            '&site_url=eq.' . urlencode($site_url) .
            '&or=(content.ilike.*' . $encoded . '*,content.ilike.*' . $encoded_alt . '*,title.ilike.*' . $encoded . '*,title.ilike.*' . $encoded_alt . '*)' .
            '&limit=' . intval($limit);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'apikey' => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            error_log("Supabase Keyword Fallback Error: " . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log("Supabase Keyword Fallback HTTP {$code}");
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data)) return [];

        $results = [];
        foreach ($data as $i => $item) {
            $results[] = [
                'id' => $item['id'] ?? 0,
                'post_id' => $item['post_id'] ?? 0,
                'title' => $item['title'] ?? 'Content',
                'content' => wp_strip_all_tags($item['content'] ?? ''),
                'url' => $item['url'] ?? '',
                'similarity' => 0.55 - ($i * 0.05)
            ];
        }
        return $results;
    }

    /* ---------------------------------------------------------
       🧱 WordPress fallback (no Supabase)
    --------------------------------------------------------- */
    private function get_fallback_content($limit) {
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        $results = [];
        foreach ($posts as $i => $post) {
            $content = wp_strip_all_tags($post->post_content);
            $content = wp_trim_words($content, 150);
            $results[] = [
                'id' => $post->ID,
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'content' => $content,
                'url' => get_permalink($post->ID),
                'similarity' => 0.5 - ($i * 0.1)
            ];
        }

        if (empty($results)) {
            $results[] = [
                'id' => 0,
                'post_id' => 0,
                'title' => 'Website Information',
                'content' => 'I’m here to help you with questions about this website.',
                'url' => get_site_url(),
                'similarity' => 0.5
            ];
        }
        return $results;
    }

    /* ---------------------------------------------------------
       🔠 Public keyword search utility
    --------------------------------------------------------- */
    public function search_by_keyword($keyword, $limit = 10) {
        try {
            $endpoint = $this->url . '/rest/v1/' . $this->table_name .
                '?select=*' .
                '&or=(content.ilike.*' . urlencode($keyword) . '*,title.ilike.*' . urlencode($keyword) . '*)' .
                '&limit=' . intval($limit);

            $response = wp_remote_get($endpoint, [
                'headers' => [
                    'apikey' => $this->api_key,
                    'Authorization' => 'Bearer ' . $this->api_key
                ],
                'timeout' => 20
            ]);

            if (is_wp_error($response)) {
                error_log("Supabase Keyword Search Error: " . $response->get_error_message());
                return [];
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            return is_array($data) ? $data : [];
        } catch (Exception $e) {
            error_log("Supabase Keyword Search Exception: " . $e->getMessage());
            return [];
        }
    }

    /* ---------------------------------------------------------
       📞 Forced Contact page loader
    --------------------------------------------------------- */
    public function get_contact_page_content() {
        $contact_post_id = 2401;
        $site_url = get_site_url();

        $endpoint = $this->url . '/rest/v1/' . $this->table_name .
            '?select=id,post_id,title,content,url' .
            '&post_id=eq.' . $contact_post_id .
            '&site_url=eq.' . urlencode($site_url);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'apikey'        => $this->api_key,
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            error_log("Supabase: Contact fetch failed – " . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log("Supabase: Contact fetch HTTP {$code}");
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data)) {
            error_log("Supabase: ✅ Loaded contact page title => " . $data[0]['title']);
            return [array_merge($data[0], ['similarity' => 4.0])];
        }

        error_log("Supabase: ❌ No data returned for contact page ID {$contact_post_id}");
        return [];
    }

    // public function search_similar_titles($embedding, $limit = 8) {
    //     // identical to search_similar_content but only selects title,url,similarity
    //     return $this->rpc('match_titles', [
    //         'query_embedding' => $embedding,
    //         'match_count' => $limit
    //     ]);
    // }
    /* -----------------------------------------------------------
 * RPC utility and title-only semantic search
 * ---------------------------------------------------------*/

    /**
     * Low-level Supabase RPC call helper.
     */
    public function rpc($function, $params = array())
    {
        if (empty($this->base_url) || empty($this->api_key)) {
            error_log('Supabase RPC error: missing base_url or api_key.');
            return array();
        }

        $url = rtrim($this->base_url, '/') . '/rpc/' . $function;

        $headers = array(
            'apikey: ' . $this->api_key,
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json'
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            error_log("Supabase RPC error ({$function}): $err");
            return array();
        }
        if ($code >= 400) {
            error_log("Supabase RPC {$function} failed with HTTP {$code}");
            return array();
        }

        $data = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Supabase RPC {$function} invalid JSON: " . json_last_error_msg());
            return array();
        }

        return is_array($data) ? $data : array();
    }

    /**
     * Title-only similarity search for intent-anchor detection.
     */
    public function search_similar_titles($embedding, $limit = 8)
    {
        if (!is_array($embedding)) {
            error_log('Supabase: invalid embedding passed to search_similar_titles.');
            return array();
        }

        $results = $this->rpc('match_titles', array(
            'query_embedding' => $embedding,
            'match_threshold' => 0.6,
            'match_count'     => $limit
        ));

        if (empty($results)) {
            error_log('Supabase: no title matches found.');
            return array();
        }

        $out = array();
        foreach ($results as $r) {
            $out[] = array(
                'title'      => isset($r['title']) ? $r['title'] : '',
                'url'        => isset($r['url']) ? $r['url'] : '',
                'content'    => isset($r['content']) ? $r['content'] : '',
                'similarity' => isset($r['similarity']) ? floatval($r['similarity']) : 0
            );
        }

        usort($out, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return $out;
    }

    
}