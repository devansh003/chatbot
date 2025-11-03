<?php
/**
 * Plugin Name: AI Chatbot with Vector Search
 * Plugin URI: https://yourwebsite.com
 * Description: AI-powered chatbot that learns from your website content using OpenAI and Supabase vector database
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * Text Domain: ai-chatbot
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AICHAT_VERSION', '1.0.0');
define('AICHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AICHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AICHAT_PLUGIN_FILE', __FILE__);

// Include required files
require_once AICHAT_PLUGIN_DIR . 'includes/class-admin.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-ajax.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-indexer.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-openai.php';
require_once AICHAT_PLUGIN_DIR . 'includes/class-supabase.php';

class AIChatbot_Plugin {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(AICHAT_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(AICHAT_PLUGIN_FILE, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    public function activate() {
        $default_options = array(
            'openai_api_key' => '',
            'supabase_url' => '',
            'supabase_api_key' => '',
            'model' => 'gpt-4',
            'temperature' => 0.7,
            'max_tokens' => 500,
            'chatbot_title' => 'AI Assistant',
            'chatbot_placeholder' => 'Ask me anything...',
            'primary_color' => '#0073aa',
            'auto_index' => 'yes'
        );
        
        add_option('aichat_settings', $default_options);
        
        if (!wp_next_scheduled('aichat_auto_index')) {
            wp_schedule_event(time(), 'daily', 'aichat_auto_index');
        }
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('aichat_auto_index');
    }
    
    public function init() {
        if (is_admin()) {
            new AIChatbot_Admin();
        }

        new AIChatbot_Shortcode();
        new AIChatbot_Ajax();

        add_action('aichat_auto_index', array('AIChatbot_Indexer', 'index_all_content'));
        add_action('save_post', array('AIChatbot_Indexer', 'index_single_post'), 10, 1);
    }
    
    /**
     * FRONTEND ASSETS
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'aichat-frontend',
            AICHAT_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            AICHAT_VERSION
        );
        
        wp_enqueue_script(
            'aichat-frontend',
            AICHAT_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            AICHAT_VERSION,
            true
        );
        
        $settings = get_option('aichat_settings', array());
        wp_localize_script('aichat-frontend', 'aichatData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aichat_nonce'),
            'title' => $settings['chatbot_title'] ?? 'AI Assistant',
            'placeholder' => $settings['chatbot_placeholder'] ?? 'Ask me anything...',
            'primary_color' => $settings['primary_color'] ?? '#0073aa',
            'plugin_url' => AICHAT_PLUGIN_URL
        ));
    }

    /**
     * ADMIN ASSETS
     */
    public function enqueue_admin_assets($hook) {
        // Load admin assets only on chatbot settings page
        if ($hook !== 'toplevel_page_ai-chatbot') {
            return;
        }

        wp_enqueue_style(
            'aichat-admin',
            AICHAT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AICHAT_VERSION
        );
        
        wp_enqueue_script(
            'aichat-admin',
            AICHAT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            AICHAT_VERSION,
            true
        );

        wp_localize_script('aichat-admin', 'aichatAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('aichat_admin_nonce')
        ));
    }
}

AIChatbot_Plugin::get_instance();