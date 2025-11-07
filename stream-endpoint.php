<?php
/**
 * endpoint.php — v28 Fixed Pricing Consistency + Better Context Handling
 * ✅ Handles Contact, Pricing, and General queries
 * ✅ Consistent pricing results for "cost" and "price" queries
 * ✅ Better "X of Y" pattern detection
 * ✅ Improved context building from multiple sources
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

// 🔍 Extract cleaned keyword for general queries
function extract_main_term($text) {
    // Normalize
    $text = strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text));
    $text = trim(preg_replace('/\s+/', ' ', $text));

    // ✅ Detect "X of Y" patterns (e.g., "drawbacks of audit and remediation")
    if (preg_match('/\b(drawbacks?|benefits?|advantages?|disadvantages?|pros?|cons?|features?|aspects?|elements?)\s+of\s+(.+)$/i', $text, $match)) {
        return trim($match[1] . ' ' . $match[2]); // Keep full phrase
    }

    // Remove auxiliary/question starters
    $text = preg_replace('/\b(what|is|are|was|were|do|does|did|can|could|would|should|will|please|may|might|shall|to|for|about|explain|tell|give|help|with|me|you|your|our|their)\b/', '', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));

    // Remove leading service verbs
    $text = preg_replace('/^(create|make|offer|provide|build|develop|design|write|generate|prepare|deliver|support|perform|do|implement|assist|help)\s+/', '', $text);

    // Tokenize and rebuild
    $words = explode(' ', $text);
    $words = array_filter($words, fn($w) => strlen($w) > 2);
    $phrase = trim(implode(' ', $words));

    // Focus on last 4–5 words (tends to capture the subject)
    $tokens = explode(' ', $phrase);
    $keyword = implode(' ', array_slice($tokens, -5));

    return trim($keyword);
}

try {
    $settings = get_option('aichat_settings', []);
    $api_key = $settings['openai_api_key'] ?? '';
    $model   = $settings['model'] ?? 'gpt-4o-mini';

    $openai   = new AIChatbot_OpenAI();
    $supabase = new AIChatbot_Supabase();

    $keyword = extract_main_term($message);
    error_log("STREAM DEBUG: Extracted cleaned keyword => {$keyword}");

    /* --------------------------------------------------------
       INTENT DETECTION: CONTACT + PRICING + GENERAL
    -------------------------------------------------------- */
    if (preg_match('/\b(contact|reach|email|phone|connect|message|help|talk|touch)\b/i', $message)) {
        // 📞 Contact intent
        error_log("STREAM DEBUG: 📞 Contact intent detected – loading contact page content");
        $results = $supabase->get_contact_page_content();

    } elseif (preg_match('/\b(price|cost|rate|charge|fee|pricing|quote|amount)\b/i', $message)) {
        // 💰 Pricing intent - FIXED VERSION
        error_log("STREAM DEBUG: 💰 Pricing intent detected");

        $matches = [];
        // ✅ FIX: Match ANY pricing word (price|cost|fee|rate|etc.) not just "price"
        preg_match('/\b(?:price|cost|fee|rate|charge|amount)\s+(?:of|for)\s+([a-z0-9\s\-]+?)(?:\s+in\s+([a-z0-9\s\-]+))?$/i', $message, $matches);
        
        $item = isset($matches[1]) ? trim($matches[1]) : '';
        $context = isset($matches[2]) ? trim($matches[2]) : '';

        error_log("STREAM DEBUG: 💰 Extracted => item='{$item}', context='{$context}'");

        // ✅ Case 1: "price of X in Y" (e.g., "price of complex remediation in PDF remediation")
        if (!empty($item) && !empty($context)) {
            error_log("STREAM DEBUG: 🎯 Specific pricing query with context");
            
            // Step 1: Search for the context (e.g., "PDF Remediation")
            $context_results = $supabase->search_by_keyword($context, 10);
            
            if (!empty($context_results)) {
                // Step 2: Filter for results that mention the specific item
                $filtered = [];
                foreach ($context_results as $res) {
                    $content = strtolower($res['content'] ?? '');
                    $title = strtolower($res['title'] ?? '');
                    
                    // Must contain BOTH the item AND be a pricing page
                    if ((stripos($content, $item) !== false || stripos($title, $item) !== false) &&
                        (stripos($title, 'pricing') !== false || stripos($content, 'pricing') !== false)) {
                        $filtered[] = $res;
                    }
                }
                
                // Use filtered results if found, otherwise search directly for item
                $results = !empty($filtered) ? $filtered : $supabase->search_by_keyword($item, 10);
            } else {
                // If no context results, search for item directly
                $results = $supabase->search_by_keyword($item, 10);
            }
            
            // ✅ Always fallback to main pricing page if results are weak
            if (empty($results) || count($results) < 2) {
                $pricing_fallback = $supabase->search_by_keyword('Accessibility Services Pricing', 5);
                $results = array_merge($results, $pricing_fallback);
            }

        // ✅ Case 2: "price of X" (no context specified)
        } elseif (!empty($item)) {
            error_log("STREAM DEBUG: 💰 Pricing query for item without context: '{$item}'");
            
            // First, try to find it on the main pricing page
            $pricing_page = $supabase->search_by_keyword('Accessibility Services Pricing', 10);
            
            if (!empty($pricing_page)) {
                $filtered = [];
                foreach ($pricing_page as $res) {
                    $content = strtolower($res['content'] ?? '');
                    if (stripos($content, strtolower($item)) !== false) {
                        $filtered[] = $res;
                    }
                }
                $results = !empty($filtered) ? $filtered : $supabase->search_by_keyword($item, 10);
            } else {
                $results = $supabase->search_by_keyword($item, 10);
            }

        // ✅ Case 3: Generic pricing query (e.g., "what are your prices?")
        } else {
            error_log("STREAM DEBUG: 💰 Generic pricing query");
            $results = $supabase->search_by_keyword('Accessibility Services Pricing', 10);
            
            if (empty($results)) {
                $emb = $openai->get_embedding('pricing cost rates');
                $results = $supabase->search_similar_content($emb, 10, 'pricing', 'general');
            }
        }

        // ✅ CRITICAL: Force prioritize "Accessibility Services Pricing Page" results
        if (!empty($results)) {
            usort($results, function($a, $b) {
                $titleA = strtolower($a['title'] ?? '');
                $titleB = strtolower($b['title'] ?? '');
                
                $isPricingA = stripos($titleA, 'accessibility services pricing') !== false;
                $isPricingB = stripos($titleB, 'accessibility services pricing') !== false;
                
                // Pricing pages always come first
                if ($isPricingA && !$isPricingB) return -1;
                if (!$isPricingA && $isPricingB) return 1;
                
                return 0; // Keep original order otherwise
            });
        }

        // Debug: Log what we found
        if (!empty($results)) {
            $top_titles = array_map(fn($r) => $r['title'] ?? 'No title', array_slice($results, 0, 3));
            error_log("STREAM DEBUG: 💰 Top 3 pricing results: " . json_encode($top_titles));
        }

    } else {
        // 🌐 General intent — IMPROVED X/Y detection
        error_log("STREAM DEBUG: 🌐 General query detected — checking for relation patterns");
        
        $x = ''; $y = '';
        
        // ✅ Pattern 1: "X of Y" (e.g., "drawbacks of audit and remediation")
        if (preg_match('/\b(drawbacks?|benefits?|advantages?|disadvantages?)\s+of\s+(.+)/i', $message, $m)) {
            $x = trim($m[1]); // e.g., "drawbacks"
            $y = trim($m[2]); // e.g., "audit and remediation"
            error_log("STREAM DEBUG: Detected 'X of Y' => X='{$x}' | Y='{$y}'");
            
            // Search primarily for Y (the subject), then filter by X
            $results = $supabase->search_by_keyword($y, 10);
            if (!empty($results)) {
                $filtered = [];
                foreach ($results as $res) {
                    $content = strtolower($res['content'] ?? '');
                    // Must contain BOTH the subject AND the qualifier
                    if (stripos($content, strtolower($y)) !== false && stripos($content, strtolower($x)) !== false) {
                        $filtered[] = $res;
                    }
                }
                $results = !empty($filtered) ? $filtered : $results;
            }
            
            // If still empty, try combined search
            if (empty($results)) {
                $combined = $y . ' ' . $x;
                $results = $supabase->search_by_keyword($combined, 10);
            }
            
        // ✅ Pattern 2: "X in Y" (original logic)
        } elseif (preg_match('/\b([a-z0-9\s\-]+?)\s+in\s+([a-z0-9\s\-]+)/i', strtolower($message), $m)) {
            $x = trim($m[1]);
            $y = trim($m[2]);
            error_log("STREAM DEBUG: Extracted 'X in Y' relation => X='{$x}' | Y='{$y}'");

            $context_results = $supabase->search_by_keyword($y, 10);
            $filtered = [];
            foreach ($context_results as $res) {
                if (stripos($res['content'] ?? '', $x) !== false) {
                    $filtered[] = $res;
                }
            }
            $results = !empty($filtered) ? $filtered : $supabase->search_by_keyword($x, 10);
            if (empty($results)) {
                $emb = $openai->get_embedding($message);
                $results = $supabase->search_similar_content($emb, 10, $message, 'general');
            }

        } else {
            // No relation, simple clean content search
            $results = $supabase->search_by_keyword($keyword, 10);
            if (empty($results)) {
                $emb = $openai->get_embedding($message);
                $results = $supabase->search_similar_content($emb, 10, $message, 'general');
            }
        }
    }

    /* --------------------------------------------------------
       🧭 PAGE PRIORITY + POST FALLBACK + PART SORTING
    -------------------------------------------------------- */

    if (!empty($results)) {
        $priority_results = $page_results = $post_results = [];
    
        foreach ($results as $r) {
            $url   = strtolower($r['url'] ?? '');
            $title = strtolower($r['title'] ?? '');
    
            // Mark pages that aren't blog/news/post/article
            if (!preg_match('#/(blog|news|post|article)/#', $url)) {
                $page_results[] = $r;  // ✅ Page first
            } else {
                $post_results[] = $r;  // ⬇️ Posts always later
            }
        }
    
        // Keep any service/training/wcag/priority pages at top
        $priority_results = array_filter($page_results, function($r) {
            $url = strtolower($r['url'] ?? '');
            $title = strtolower($r['title'] ?? '');
            return preg_match('#/(services|training|wcag|ada-website-compliance|pricing|resources|partnership)#', $url)
                || preg_match('/(services|training|wcag|pricing|resources|partnership)/', $title);
        });
    
        // Remove them from normal pages so they don't repeat
        $page_results = array_udiff($page_results, $priority_results, fn($a,$b) => strcmp($a['url'],$b['url']));
    
        // ✅ Final merge order: Priority pages → all other pages → posts
        $results = array_merge($priority_results, $page_results, $post_results);

        // ✅ Prefer Part 1 before Part 2/3/... when titles match
        usort($results, function ($a, $b) {
            $ta = strtolower(trim($a['title'] ?? ''));
            $tb = strtolower(trim($b['title'] ?? ''));

            // remove (part x/x) text for comparison
            $baseA = preg_replace('/\(\s*part\s*\d+\s*\/\s*\d+\s*\)/i', '', $ta);
            $baseB = preg_replace('/\(\s*part\s*\d+\s*\/\s*\d+\s*\)/i', '', $tb);

            // if both share the same base title (ignoring punctuation)
            if (preg_replace('/[^\w]/', '', $baseA) === preg_replace('/[^\w]/', '', $baseB)) {
                preg_match('/\(.*?part\s*(\d+)\s*\/\s*(\d+)\s*\)/i', $ta, $ma);
                preg_match('/\(.*?part\s*(\d+)\s*\/\s*(\d+)\s*\)/i', $tb, $mb);

                $numA = isset($ma[1]) ? (int)$ma[1] : 0;
                $numB = isset($mb[1]) ? (int)$mb[1] : 0;

                // ✅ Lower part number always first (Part 1 before Part 2, etc.)
                if ($numA !== $numB) return $numA <=> $numB;
            }

            // fallback: preserve original order if no part numbers or unrelated
            return 0;
        });
    
        error_log("STREAM DEBUG: 🧭 Final order → "
            . count($priority_results) . " priority pages, "
            . count($page_results) . " normal pages, "
            . count($post_results) . " posts");
    }

    /* --------------------------------------------------------
       Build Sources and Stream Response
    -------------------------------------------------------- */
    
    // ✅ NOW slice AFTER all sorting is complete
    $top = array_slice($results ?? [], 0, 10);
    
    $sources = [];
    $seen_urls = [];
    
    foreach ($top as $r) {
        $url = trim($r['url'] ?? '');
        $title = trim($r['title'] ?? '');
    
        if (empty($url) || isset($seen_urls[$url])) {
            continue; // skip if no URL or already added
        }
    
        if (!empty($title)) {
            $sources[] = ['title' => $title, 'url' => $url];
            $seen_urls[$url] = true; // mark as added
        }
    
        // stop once we have 3–4 unique URLs
        if (count($sources) >= 3) break;
    }

    if (!empty($sources)) {
        echo "data: " . json_encode(['type' => 'sources', 'sources' => $sources]) . "\n\n";
        @ob_flush(); @flush();
        error_log("STREAM DEBUG: ✅ Sent " . count($sources) . " sources (keyword: {$keyword})");
    }

    // ✅ Build context from properly sorted results (use $results not $top)
    $context = '';
    $used_base_titles = [];
    $context_count = 0;
    $max_context_items = 3; // Include top 3 most relevant unique pages

    foreach ($results as $r) {
        if ($context_count >= $max_context_items) break;
        
        $title = trim($r['title'] ?? '');
        $base  = preg_replace('/\(part\s*\d+\/\d+\)/i', '', strtolower($title));

        // Skip if we already added same base title
        if (in_array($base, $used_base_titles, true)) continue;
        $used_base_titles[] = $base;

        $content = wp_strip_all_tags($r['content'] ?? '');
        
        // ✅ Only include if content is substantial
        if (strlen($content) > 100) {
            $context .= "---\nTitle: {$title}\n{$content}\n\n";
            $context_count++;
        }
    }

    // ✅ If context is empty, log error
    if (empty($context)) {
        error_log("STREAM DEBUG: ⚠️ WARNING - No context built for keyword: {$keyword}");
        $context = "No relevant content found in our documentation.";
    }

    $system = <<<SYS
You are an AI assistant for this website. Answer ONLY using the information in the Context below.

**CRITICAL RULES:**
1. If the Context doesn't contain information to answer the question, say: "I don't have specific information about this in our documentation."
2. Do NOT make up, assume, or infer information not explicitly stated in the Context.
3. Use exact terminology and details from the Context.
4. If multiple sources are provided, synthesize them but stay accurate to each source.
5. For pricing questions, provide exact numbers and details as stated.

**User asked about:** "{$keyword}"

---
Context:
{$context}
---

Answer based ONLY on the Context above.
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
                    echo "data: [DONE]\n\n"; @ob_flush(); @flush(); continue;
                }
                $parsed = json_decode($json, true);
                if (isset($parsed['choices'][0]['delta']['content'])) {
                    echo "data: " . json_encode(['type' => 'content', 'content' => $parsed['choices'][0]['delta']['content']]) . "\n\n";
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
