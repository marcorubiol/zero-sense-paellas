/**
 * Log Deletion JavaScript
 * Handles individual log deletion across all Zero Sense log metaboxes
 */
(function($) {
    'use strict';
    
    // Initialize log deletion functionality
    function initLogDeletion() {
        // Handle click events on delete buttons
        $(document.body).on('click', '.zs-log-delete', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $button = $(this);
            const $logItem = $button.closest('.zs-log-item');
            const logType = $button.data('log-type');
            const logId = $button.data('log-id');
            const orderId = $button.data('order-id');
            const timestamp = $button.data('timestamp');
            
            // Confirmation dialog
            if (!confirm(zsLogDeletion.i18n.confirmDelete)) {
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).html('...');
            
            // Prepare AJAX data
            const ajaxData = {
                action: 'zs_delete_log',
                nonce: zsLogDeletion.nonce,
                log_type: logType,
                order_id: orderId
            };
            
            // Add identifier based on log type
            if (logId) {
                ajaxData.log_id = logId;
            } else if (timestamp) {
                ajaxData.timestamp = timestamp;
            }
            
            // Perform AJAX request
            $.post(zsLogDeletion.ajaxUrl, ajaxData)
                .done(function(response) {
                    if (response.success) {
                        // Remove log item with animation
                        $logItem.fadeOut(300, function() {
                            $(this).remove();
                            
                            // Update counters if they exist
                            updateLogCounters(logType);
                            
                            // Show success message
                            showNotice(response.data.message || zsLogDeletion.i18n.deleteSuccess, 'success');
                        });
                    } else {
                        // Show error message
                        showNotice(response.data.message || zsLogDeletion.i18n.deleteError, 'error');
                        $button.prop('disabled', false).html('×');
                    }
                })
                .fail(function() {
                    showNotice(zsLogDeletion.i18n.ajaxError, 'error');
                    $button.prop('disabled', false).html('×');
                });
        });
    }
    
    // Update log counters in metabox headers
    function updateLogCounters(logType) {
        // Find the corresponding metabox and update any counters
        const $metabox = $('.zs-' + logType + '-logs-metabox');
        if ($metabox.length) {
            const $counter = $metabox.find('.zs-log-counter');
            if ($counter.length) {
                const currentCount = parseInt($counter.text()) || 0;
                $counter.text(Math.max(0, currentCount - 1));
            }
        }
    }
    
    // Show admin notice
    function showNotice(message, type) {
        // Remove any existing notices
        $('.zs-log-deletion-notice').remove();
        
        // Create notice element
        const $notice = $('<div>')
            .addClass('zs-log-deletion-notice notice notice-' + type + ' is-dismissible')
            .html('<p>' + message + '</p>')
            .css({
                'position': 'fixed',
                'top': '32px',
                'right': '20px',
                'z-index': '9999',
                'max-width': '400px',
                'box-shadow': '0 4px 12px rgba(0,0,0,0.15)'
            });
        
        // Add to page and auto-remove after 3 seconds
        $('body').append($notice);
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
        
        // Make dismissible
        $notice.on('click', '.notice-dismiss', function() {
            $notice.remove();
        });
    }
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        // Only initialize on order edit pages
        if ($('body').hasClass('post-type-shop_order') || 
            $('body').hasClass('woocommerce_page_wc-orders') ||
            $('.zs-log-item').length > 0) {
            initLogDeletion();
        }
    });
    
})(jQuery);
