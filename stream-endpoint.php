<?php
/**
 * endpoint.php — v23 Advanced Intent Handling
 * ✅ Handles Contact, Pricing, and General queries
 * ✅ Smart pricing logic:
 *     - “price of X” → searches Accessibility Services Pricing page
 *     - “price of X in Y” → filters Y first, then searches for X
 * ✅ Uses Supabase keyword/vector search with fallbacks
 * ✅ Streams OpenAI completion
 */

require_once dirname(__DIR__, 3) . '/wp-load.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-openai.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-supabase.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-ajax.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-indexer.php';

if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aichat_nonce')) {
    die('Security check failed');
}

$message = sanitize_text_field($_POST['message'] ?? '');
if (empty($message)) die('Message is required');
$history = json_decode(stripslashes($_POST['history'] ?? '[]'), true);

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
ignore_user_abort(true);
set_time_limit(0);

// 🧠 Helper: Extract keyword(s)
function extract_keyword($text) {
    $text = strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text));
    $stopwords = ['what','is','about','for','how','does','a','an','the','in','of','on','to','and','me'];
    $words = array_filter(explode(' ', $text), fn($w) => !in_array($w, $stopwords) && strlen($w) > 2);
    if (empty($words)) return trim($text);
    return implode(' ', array_slice($words, 0, 4));
}

try {
    $settings = get_option('aichat_settings', []);
    $api_key = $settings['openai_api_key'] ?? '';
    $model   = $settings['model'] ?? 'gpt-4o-mini';

    $openai   = new AIChatbot_OpenAI();
    $supabase = new AIChatbot_Supabase();

    // Extract primary keyword
    $keyword = extract_keyword($message);
    error_log("STREAM DEBUG: Extracted intent keyword => {$keyword}");

    /* --------------------------------------------------------
       INTENT DETECTION: CONTACT + PRICING + GENERAL
    -------------------------------------------------------- */
    if (preg_match('/\b(contact|reach|email|phone|support|connect|message|help|talk|touch)\b/i', $message)) {
        // 📞 Contact intent
        error_log("STREAM DEBUG: 📞 Contact intent detected – loading contact page content");
        $results = $supabase->get_contact_page_content();

    } elseif (preg_match('/\b(price|cost|rate|charge|fee|pricing|quote|amount)\b/i', $message)) {
        // 💰 Pricing intent
        error_log("STREAM DEBUG: 💰 Pricing intent detected");

        // Try to capture patterns like:
        // “price of X”  or “price of X in Y”
        $matches = [];
        preg_match('/price\s+(of|for)\s+([a-z0-9\s\-]+?)(?:\s+in\s+([a-z0-9\s\-]+))?$/i', $message, $matches);
        $item = trim($matches[2] ?? '');
        $context = trim($matches[3] ?? '');

        if (!empty($context)) {
            // 🎯 Case: “price of X in Y” → Filter Y then search X
            error_log("STREAM DEBUG: 🎯 Specific price inside context detected — item='{$item}', context='{$context}'");

            // First search pages matching the context (e.g. “PDF rendering”)
            $context_results = $supabase->search_by_keyword($context, 5);

            if (!empty($context_results)) {
                // Then search item keywords within those context results
                $filtered = [];
                foreach ($context_results as $res) {
                    if (
                        stripos($res['content'] ?? '', $item) !== false ||
                        stripos($res['title'] ?? '', $item) !== false
                    ) {
                        $filtered[] = $res;
                    }
                }

                if (!empty($filtered)) {
                    $results = $filtered;
                    error_log("STREAM DEBUG: ✅ Found {$item} inside {$context}");
                } else {
                    // If not found in context, fallback to full content search
                    error_log("STREAM DEBUG: ⚠️ Not found inside {$context}, fallback to full content search");
                    $results = $supabase->search_by_keyword($item, 10);
                    if (empty($results)) {
                        $emb = $openai->get_embedding($item);
                        $results = $supabase->search_similar_content($emb, 10, $item, 'general');
                    }
                }
            } else {
                // If context page itself isn’t found
                error_log("STREAM DEBUG: ⚠️ Context '{$context}' not found, falling back to general price search");
                $results = $supabase->search_by_keyword('Accessibility Services Pricing', 5);
            }

        } else {
            // 📄 Case: “price of X” → Search Accessibility Services Pricing page
            $target = !empty($item) ? $item : $keyword;
            error_log("STREAM DEBUG: 🧾 General service pricing search for '{$target}'");

            $pricing_page = $supabase->search_by_keyword('Accessibility Services Pricing', 5);
            if (!empty($pricing_page)) {
                // Search item keywords inside pricing page content
                $filtered = [];
                foreach ($pricing_page as $res) {
                    if (
                        stripos($res['content'] ?? '', $target) !== false ||
                        stripos($res['title'] ?? '', $target) !== false
                    ) {
                        $filtered[] = $res;
                    }
                }

                $results = !empty($filtered) ? $filtered : $pricing_page;
                error_log("STREAM DEBUG: ✅ Found pricing page content for '{$target}'");
            } else {
                // If pricing page not found, fallback to global search
                $results = $supabase->search_by_keyword($target, 10);
                if (empty($results)) {
                    $emb = $openai->get_embedding($target);
                    $results = $supabase->search_similar_content($emb, 10, $target, 'general');
                }
            }
        }

    } else {
        // 🌐 General intent (no special keywords)
        $results = $supabase->search_by_keyword($keyword, 10);
        if (empty($results)) {
            error_log("STREAM DEBUG: ⚠️ No keyword matches, fallback to embedding search");
            $emb = $openai->get_embedding($message);
            $results = $supabase->search_similar_content($emb, 10, $message, 'general');
        }
    }

    /* --------------------------------------------------------
       Build response and stream
    -------------------------------------------------------- */
    $top = array_slice($results ?? [], 0, 3);
    $sources = [];
    foreach ($top as $r) {
        if (!empty($r['title']) && !empty($r['url'])) {
            $sources[] = ['title' => $r['title'], 'url' => $r['url']];
        }
    }

    if (!empty($sources)) {
        echo "data: " . json_encode(['type' => 'sources', 'sources' => $sources]) . "\n\n";
        @ob_flush(); @flush();
        error_log("STREAM DEBUG: ✅ Sent " . count($sources) . " sources (keyword: {$keyword})");
    }

    $context = '';
    foreach ($top as $r) {
        $context .= "Title: " . ($r['title'] ?? '') . "\n" .
            wp_strip_all_tags($r['content'] ?? '') . "\n\n";
    }

    $system = <<<SYS
You are an AI assistant using this website’s indexed data.
Focus your answer on the keyword "{$keyword}".
---
Context:
{$context}
SYS;

    $messages = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($history, -10) as $m)
        $messages[] = ['role' => $m['role'], 'content' => $m['content']];
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 900,
        'stream' => true
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) {
            $lines = explode("\n", trim($data));
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') !== 0) continue;
                $json = substr($line, 6);
                if (trim($json) === '[DONE]') {
                    echo "data: [DONE]\n\n";
                    @ob_flush(); @flush();
                    continue;
                }
                $parsed = json_decode($json, true);
                if (isset($parsed['choices'][0]['delta']['content'])) {
                    $content = $parsed['choices'][0]['delta']['content'];
                    echo "data: " . json_encode(['type' => 'content', 'content' => $content]) . "\n\n";
                    @ob_flush(); @flush();
                }
            }
            return strlen($data);
        }
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'OpenAI error: ' . curl_error($ch)]) . "\n\n";
        @ob_flush(); @flush();
    }
    curl_close($ch);

} catch (Exception $e) {
    echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
    @ob_flush(); @flush();
}
exit;