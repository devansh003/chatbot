<?php
/**
 * Shortcode Handler Class
 */

class AIChatbot_Shortcode {
    
    public function __construct() {
        add_shortcode('chatbot', array($this, 'render_chatbot'));
    }
    
    public function render_chatbot($atts) {
        $atts = shortcode_atts(array(
            'type' => 'normal' // 'normal' or 'popup'
        ), $atts);
        
        $settings = get_option('aichat_settings', array());
        $title = $settings['chatbot_title'] ?? 'AI Assistant';
        
        if ($atts['type'] === 'popup') {
            return $this->render_popup();
        } else {
            return $this->render_normal();
        }
    }
    
    private function render_popup() {
        ob_start();
        ?>
        <!-- Popup Backdrop -->
        <div class="aichat-popup-backdrop" id="aichat-popup-backdrop" style="display: none;"></div>
        
        <!-- Popup Chatbot Button -->
        <div class="aichat-popup-trigger" id="aichat-popup-trigger">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
        </div>
        
        <!-- Popup Chatbot Container -->
        <div class="aichat-popup-container" id="aichat-popup-container" style="display: none;">
            <div class="aichat-popup-header">
                <h3>Ask AI</h3>
                <button class="aichat-close-btn" id="aichat-close-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <?php echo $this->render_chat_interface(); ?>
            <?php echo $this->render_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_normal() {
        ob_start();
        ?>
        <div class="aichat-normal-container">
            <div class="aichat-normal-header">
                <h3>Ask AI</h3>
            </div>
            <?php echo $this->render_chat_interface(); ?>
            <?php echo $this->render_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_chat_interface() {
        $settings = get_option('aichat_settings', array());
        $placeholder = $settings['chatbot_placeholder'] ?? 'Ask me anything...';
        
        ob_start();
        ?>
        <div class="aichat-messages" id="aichat-messages">
            <div class="aichat-message aichat-bot-message">
                <div class="aichat-avatar">A</div>
                <div class="aichat-message-content">
                    Hi!<br><br>I'm an AI assistant trained on documentation, help articles, and other content.<br><br>Ask me anything about <strong><?php echo esc_html(get_bloginfo('name')); ?></strong>
                </div>
            </div>
        </div>
        <div class="aichat-input-container">
            <textarea 
                class="aichat-input" 
                id="aichat-input" 
                placeholder="<?php echo esc_attr($placeholder); ?>"
                rows="1"
            ></textarea>
            <button class="aichat-send-btn" id="aichat-send-btn" aria-label="Send message">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"
                fill="white" stroke="white" stroke-width="1.5"
                stroke-linecap="round" stroke-linejoin="round"
                class="aichat-send-icon">
                <path d="M60 4L28 60L24 36L4 24L60 4Z"></path>
            </svg>
        </button>
        </div>
        <div class="aichat-loading" id="aichat-loading" style="display: none;">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_footer() {
        ob_start();
        ?>
        <div class="aichat-footer">
            <span class="aichat-footer-text">Powered by</span>
            <a href="https://accessibility.org" target="_blank" rel="noopener" class="aichat-footer-logo">
                <svg class="aichat-footer-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Accessibility.org
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}
