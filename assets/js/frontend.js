jQuery(document).ready(function($) {
    let conversationHistory = [];
    let currentBotMessageElement = null;
    let currentBotMessage = '';
    let streamQueue = [];
    let isProcessingQueue = false;
    
    const pluginUrl = aichatData.plugin_url || '/wp-content/plugins/ai-chatbot-plugin';
    
    // Popup trigger
    $('#aichat-popup-trigger').on('click', function() {
        $('body').addClass('aichat-popup-open');
        $('#aichat-popup-backdrop').fadeIn(300);
        $('#aichat-popup-container').fadeIn(300);
    });
    
    // Close popup
    $('#aichat-close-btn, #aichat-popup-backdrop').on('click', function() {
        $('body').removeClass('aichat-popup-open');
        $('#aichat-popup-backdrop').fadeOut(300);
        $('#aichat-popup-container').fadeOut(300);
    });
    
    // Auto-resize textarea
    $('.aichat-input').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
    
    // Send message on button click
    $(document).on('click', '.aichat-send-btn', function() {
        sendMessage();
    });
    
    // Send message on Enter
    $(document).on('keypress', '.aichat-input', function(e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    function sendMessage() {
        const input = $('.aichat-input');
        const message = input.val().trim();
        
        if (!message) return;
        
        // Add user message
        appendMessage(message, 'user');
        
        // Clear input
        input.val('').css('height', 'auto');
        
        // Add to history
        conversationHistory.push({
            role: 'user',
            content: message
        });
        
        // Disable input
        input.prop('disabled', true);
        $('.aichat-send-btn').prop('disabled', true);
        
        // Create streaming message
        createStreamingMessage();
        
        // Start streaming
        streamMessage(message);
    }
    
    function streamMessage(message) {
        const formData = new FormData();
        formData.append('nonce', aichatData.nonce);
        formData.append('message', message);
        formData.append('history', JSON.stringify(conversationHistory));
        
        const streamUrl = pluginUrl + '/stream-endpoint.php';
        
        fetch(streamUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            
            function processStream() {
                return reader.read().then(({ done, value }) => {
                    if (done) {
                        // Process remaining queue
                        processStreamQueue().then(() => {
                            finishStreaming();
                        });
                        return;
                    }
                    
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    
                    buffer = lines.pop();
                    
                    lines.forEach(line => {
                        if (line.startsWith('data: ')) {
                            const data = line.substring(6).trim();
                            
                            if (data === '[DONE]') {
                                return;
                            }
                            
                            try {
                                const parsed = JSON.parse(data);
                                
                                if (parsed.type === 'sources') {
                                    window.currentSources = parsed.sources;
                                } else if (parsed.type === 'content') {
                                    // Add to queue instead of immediate display
                                    streamQueue.push(parsed.content);
                                    if (!isProcessingQueue) {
                                        processStreamQueue();
                                    }
                                } else if (parsed.type === 'error') {
                                    appendTextToStream(parsed.message);
                                    finishStreaming();
                                }
                            } catch (e) {
                                console.error('Parse error:', e);
                            }
                        }
                    });
                    
                    return processStream();
                });
            }
            
            return processStream();
        })
        .catch(error => {
            console.error('Streaming error:', error);
            if (currentBotMessageElement) {
                appendTextToStream('Sorry, something went wrong. Please try again.');
            } else {
                appendMessage('Sorry, something went wrong. Please try again.', 'bot');
            }
            finishStreaming();
        });
    }
    
    // Process stream queue with delay for slower effect
    function processStreamQueue() {
        if (streamQueue.length === 0) {
            isProcessingQueue = false;
            return Promise.resolve();
        }
        
        isProcessingQueue = true;
        const text = streamQueue.shift();
        appendTextToStream(text);
        
        // Delay between chunks (adjust this for speed: 30-50ms is good)
        return new Promise(resolve => {
            setTimeout(() => {
                processStreamQueue().then(resolve);
            }, 40); // 40ms delay = slower, smoother streaming
        });
    }
    
    function createStreamingMessage() {
        const messagesContainer = $('.aichat-messages');
        currentBotMessage = '';
        streamQueue = [];
        isProcessingQueue = false;
        
        const messageHtml = `
            <div class="aichat-message aichat-bot-message" id="aichat-streaming-message">
                <div class="aichat-avatar">A</div>
                <div class="aichat-message-content">
                    <span class="aichat-streaming-text"></span>
                    <span class="aichat-cursor"></span>
                </div>
            </div>
        `;
        
        messagesContainer.append(messageHtml);
        currentBotMessageElement = $('#aichat-streaming-message .aichat-streaming-text');
        
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }
    
    function appendTextToStream(text) {
        if (!currentBotMessageElement) return;
        
        currentBotMessage += text;
        
        const formatted = formatBotMessage(currentBotMessage);
        currentBotMessageElement.html(formatted);
        
        const messagesContainer = $('.aichat-messages');
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }
    
    function finishStreaming() {
        // Remove cursor
        $('#aichat-streaming-message .aichat-cursor').remove();
        
        // Add sources if available
        if (window.currentSources && window.currentSources.length > 0) {
            let sourcesHtml = '<div class="aichat-sources-container">';
            sourcesHtml += '<div class="aichat-sources-header">Sources</div>';
            
            window.currentSources.forEach(source => {
                sourcesHtml += `
                    <div class="aichat-source-item">
                        <svg class="aichat-source-icon" viewBox="0 0 24 24" fill="none">
                            <path d="M12 6.25278V19.2528M12 6.25278C10.8321 5.47686 9.24649 5 7.5 5C5.75351 5 4.16789 5.47686 3 6.25278V19.2528C4.16789 18.4769 5.75351 18 7.5 18C9.24649 18 10.8321 18.4769 12 19.2528M12 6.25278C13.1679 5.47686 14.7535 5 16.5 5C18.2465 5 19.8321 5.47686 21 6.25278V19.2528C19.8321 18.4769 18.2465 18 16.5 18C14.7535 18 13.1679 18.4769 12 19.2528" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="aichat-source-content">
                            <a href="${escapeHtml(source.url)}" target="_blank" class="aichat-source-link">
                                ${escapeHtml(source.title)}
                            </a>
                        </div>
                    </div>
                `;
            });
            
            sourcesHtml += '</div>';
            
            $('#aichat-streaming-message .aichat-message-content').append(sourcesHtml);
            window.currentSources = null;
        }
        
        $('#aichat-streaming-message').removeAttr('id');
        
        if (currentBotMessage) {
            conversationHistory.push({
                role: 'assistant',
                content: currentBotMessage
            });
        }
        
        currentBotMessage = '';
        currentBotMessageElement = null;
        
        enableInput();
    }
    
    function enableInput() {
        const input = $('.aichat-input');
        input.prop('disabled', false);
        $('.aichat-send-btn').prop('disabled', false);
        input.focus();
    }
    
    function appendMessage(text, type) {
        const messagesContainer = $('.aichat-messages');
        const messageClass = type === 'user' ? 'aichat-user-message' : 'aichat-bot-message';
        const avatar = type === 'user' ? 
            '<div class="aichat-avatar">👤</div>' : 
            '<div class="aichat-avatar">A</div>';
        
        let formattedContent = type === 'bot' ? formatBotMessage(text) : escapeHtml(text);
        
        let messageHtml = `
            <div class="aichat-message ${messageClass}">
                ${avatar}
                <div class="aichat-message-content">${formattedContent}</div>
            </div>
        `;
        
        messagesContainer.append(messageHtml);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }
    
    function formatBotMessage(text) {
        text = escapeHtml(text);
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/^(\d+)\.\s+/gm, '<br><strong>$1.</strong> ');
        text = text.replace(/\n/g, '<br>');
        text = text.replace(/(<br\s*\/?>){3,}/gi, '<br><br>');
        return text;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});