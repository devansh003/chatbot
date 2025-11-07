<?php
/**
 * Admin Panel Class
 */

class AIChatbot_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_aichat_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_aichat_index_now', array($this, 'index_now'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'AI Chatbot Settings',
            'AI Chatbot',
            'manage_options',
            'ai-chatbot',
            array($this, 'settings_page'),
            'dashicons-format-chat',
            30
        );

        add_submenu_page(
            'ai-chatbot',
            'AI Chatbot Logs',
            'View Logs',
            'manage_options',
            'aichat-logs',
            array($this, 'view_logs_page')
        );
    }
    
    public function register_settings() {
        register_setting('aichat_settings_group', 'aichat_settings');
    }
    
    public function settings_page() {
        $settings = get_option('aichat_settings', array());
        ?>
        <div class="wrap">
            <h1>AI Chatbot Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('aichat_settings_group'); ?>
                
                <div class="aichat-admin-container">
                    <!-- API Settings -->
                    <div class="aichat-section">
                        <h2>API Configuration</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">OpenAI API Key</th>
                                <td>
                                    <input type="password" name="aichat_settings[openai_api_key]" 
                                           value="<?php echo esc_attr($settings['openai_api_key'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Supabase URL</th>
                                <td>
                                    <input type="text" name="aichat_settings[supabase_url]" 
                                           value="<?php echo esc_attr($settings['supabase_url'] ?? ''); ?>" 
                                           class="regular-text" placeholder="https://xxxxx.supabase.co" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Supabase API Key</th>
                                <td>
                                    <input type="password" name="aichat_settings[supabase_api_key]" 
                                           value="<?php echo esc_attr($settings['supabase_api_key'] ?? ''); ?>" 
                                           class="regular-text" />
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button" id="test-connection">Test Connection</button>
                        <span id="connection-status"></span>
                    </div>
                    
                    <!-- Model Settings -->
                    <div class="aichat-section">
                        <h2>Model Configuration</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">OpenAI Model</th>
                                <td>
                                    <select name="aichat_settings[model]">
                                        <option value="gpt-4o-mini" <?php selected($settings['model'] ?? 'gpt-4o-mini', 'gpt-4o-mini'); ?>>GPT-4o Mini ⭐ (Recommended)</option>
                                        <option value="gpt-4o" <?php selected($settings['model'] ?? '', 'gpt-4o'); ?>>GPT-4o</option>
                                        <option value="gpt-4" <?php selected($settings['model'] ?? '', 'gpt-4'); ?>>GPT-4</option>
                                        <option value="gpt-4-turbo" <?php selected($settings['model'] ?? '', 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                                        <option value="gpt-3.5-turbo" <?php selected($settings['model'] ?? '', 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                                    </select>
                                    <p class="description">GPT-4o Mini is cost-effective and fast. Perfect for chatbots!</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Temperature</th>
                                <td>
                                    <input type="number" name="aichat_settings[temperature]" 
                                           value="<?php echo esc_attr($settings['temperature'] ?? '0.7'); ?>" 
                                           step="0.1" min="0" max="2" />
                                    <p class="description">Controls randomness (0-2). Lower is more focused.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Max Tokens</th>
                                <td>
                                    <input type="number" name="aichat_settings[max_tokens]" 
                                           value="<?php echo esc_attr($settings['max_tokens'] ?? '500'); ?>" 
                                           min="50" max="4000" />
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Appearance Settings -->
                    <div class="aichat-section">
                        <h2>Chatbot Appearance</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Chatbot Title</th>
                                <td>
                                    <input type="text" name="aichat_settings[chatbot_title]" 
                                           value="<?php echo esc_attr($settings['chatbot_title'] ?? 'AI Assistant'); ?>" 
                                           class="regular-text" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Input Placeholder</th>
                                <td>
                                    <input type="text" name="aichat_settings[chatbot_placeholder]" 
                                           value="<?php echo esc_attr($settings['chatbot_placeholder'] ?? 'Ask me anything...'); ?>" 
                                           class="regular-text" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Primary Color</th>
                                <td>
                                    <input type="color" name="aichat_settings[primary_color]" 
                                           value="<?php echo esc_attr($settings['primary_color'] ?? '#0073aa'); ?>" />
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Indexing Settings -->
                    <div class="aichat-section">
                        <h2>Content Indexing</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Auto-Index Content</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="aichat_settings[auto_index]" value="yes" 
                                               <?php checked($settings['auto_index'] ?? 'yes', 'yes'); ?> />
                                        Automatically index new/updated content
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button button-primary" id="index-now">Index All Content Now</button>
                        <span id="index-status"></span>
                    </div>
                    
                    <!-- Shortcode Usage -->
                    <div class="aichat-section">
                        <h2>How to Use</h2>
                        <p><strong>Shortcodes:</strong></p>
                        <ul>
                            <li><code>[chatbot type="popup"]</code> - Opens chatbot as a popup (button in bottom-right corner)</li>
                            <li><code>[chatbot type="normal"]</code> - Displays chatbot inline on the page</li>
                        </ul>
                    </div>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public function test_connection() {
        check_ajax_referer('aichat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $settings = get_option('aichat_settings', array());
        
        // Test OpenAI
        $openai = new AIChatbot_OpenAI();
        $openai_test = $openai->test_connection();
        
        // Test Supabase
        $supabase = new AIChatbot_Supabase();
        $supabase_test = $supabase->test_connection();
        
        if ($openai_test && $supabase_test) {
            wp_send_json_success('All connections successful!');
        } else {
            $errors = array();
            if (!$openai_test) $errors[] = 'OpenAI connection failed';
            if (!$supabase_test) $errors[] = 'Supabase connection failed';
            wp_send_json_error(implode(', ', $errors));
        }
    }
    
    public function index_now() {
        check_ajax_referer('aichat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = AIChatbot_Indexer::index_all_content();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function view_logs_page() {
        $log_file = WP_CONTENT_DIR . '/debug-indexing.log';
        echo '<div class="wrap"><h1>AI Chatbot Indexing Logs</h1>';
    
        if (file_exists($log_file)) {
            echo '<pre style="background:#111;color:#0f0;padding:15px;max-height:600px;overflow:auto;font-size:13px;line-height:1.4;">';
            $lines = file($log_file);
            $last_100 = array_slice($lines, -100); // show last 100 lines
            echo esc_html(implode('', $last_100));
            echo '</pre>';
            echo '<p><a href="' . esc_url(add_query_arg('refresh', '1')) . '" class="button">Refresh Logs</a></p>';
        } else {
            echo '<p><strong>No log file found.</strong><br>Run indexing once to generate it.</p>';
        }
    
        echo '</div>';
    }
}
