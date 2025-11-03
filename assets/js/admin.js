jQuery(document).ready(function ($) {

    /**
     * 🔌 Test Connection Button
     */
    $('#test-connection').on('click', function () {
        const button = $(this);
        const status = $('#connection-status');

        button.prop('disabled', true).text('Testing...');
        status.html('<span style="color:blue;">Testing connection...</span>');

        $.ajax({
            url: aichatAdmin.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'aichat_test_connection',
                nonce: aichatAdmin.nonce
            },
            success: function (response) {
                console.log('Test Connection Response:', response);
                if (response.success) {
                    status.html('<span style="color:green;">✓ ' + response.data + '</span>');
                } else {
                    status.html('<span style="color:red;">✗ ' + response.data + '</span>');
                }
            },
            error: function (xhr) {
                console.error('AJAX Error:', xhr);
                status.html('<span style="color:red;">✗ Connection failed (HTTP ' + xhr.status + ')</span>');
            },
            complete: function () {
                button.prop('disabled', false).text('Test Connection');
            }
        });
    });

    /**
     * 📄 Index All Content Button
     */
    $('#index-now').on('click', function () {
        const button = $(this);
        const status = $('#index-status');

        if (!confirm('This will index all posts and pages. Continue?')) {
            return;
        }

        button.prop('disabled', true).text('Indexing...');
        status.html('<span style="color:blue;">⏳ Indexing started...</span>');

        $.ajax({
            url: aichatAdmin.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'aichat_index_now',
                nonce: aichatAdmin.nonce
            },
            success: function (response) {
                console.log('Index Now Response:', response);
                if (response.success) {
                    status.html('<span style="color:green;">✓ ' + response.data + '</span>');
                } else {
                    status.html('<span style="color:red;">✗ ' + response.data + '</span>');
                }
            },
            error: function (xhr) {
                console.error('AJAX Error:', xhr);
                status.html('<span style="color:red;">✗ Indexing failed (HTTP ' + xhr.status + ')</span>');
            },
            complete: function () {
                button.prop('disabled', false).text('Index All Content Now');
            }
        });
    });
});