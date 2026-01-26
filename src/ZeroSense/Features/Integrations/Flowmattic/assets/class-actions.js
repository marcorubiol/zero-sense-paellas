/**
 * Zero Sense - Class Actions Handler
 * Detects clicks on buttons with configured classes and triggers Flowmattic workflows
 */
(function($) {
    'use strict';

    // Wait for DOM to be ready
    $(document).ready(function() {
        
        // Check if we have the required configuration
        if (typeof zsClassActions === 'undefined') {
            return;
        }

        const { ajaxUrl, nonce, classes } = zsClassActions;

        // Function to extract order ID from various contexts
        function getOrderId() {
            // Try to get order ID from various sources
            
            // 1. From URL path (order-pay pages: /order-pay/12345/)
            const pathMatch = window.location.pathname.match(/\/order-pay\/(\d+)\//); 
            if (pathMatch) {
                return parseInt(pathMatch[1]);
            }
            
            // 2. From URL parameters (admin order edit page)
            const urlParams = new URLSearchParams(window.location.search);
            const postId = urlParams.get('post');
            if (postId && window.location.href.includes('post_type=shop_order')) {
                return parseInt(postId);
            }

            // 3. From page body classes or data attributes
            const bodyClasses = document.body.className;
            const orderMatch = bodyClasses.match(/order-(\d+)/);
            if (orderMatch) {
                return parseInt(orderMatch[1]);
            }

            // 4. From data attributes on the page
            const orderElement = $('[data-order-id]').first();
            if (orderElement.length) {
                return parseInt(orderElement.data('order-id'));
            }

            // 5. From WooCommerce order details
            const orderIdElement = $('.woocommerce-order-details .order-number, .order-id');
            if (orderIdElement.length) {
                const orderText = orderIdElement.text();
                const orderMatch = orderText.match(/(\d+)/);
                if (orderMatch) {
                    return parseInt(orderMatch[1]);
                }
            }
            
            // 6. From URL fragments or other patterns
            const urlOrderMatch = window.location.href.match(/[\/?]order[\/_-](\d+)[\/?]?/);
            if (urlOrderMatch) {
                return parseInt(urlOrderMatch[1]);
            }

            return null;
        }

        // Function to trigger class action and then continue normal behavior
        function triggerClassAction(className, orderId, button, originalEvent) {
            // Trigger workflow and then continue normal behavior
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                async: true,
                data: {
                    action: 'zs_trigger_class_action',
                    nonce: nonce,
                    class_name: className,
                    order_id: orderId
                },
                complete: function() {
                    // After AJAX (success or error), continue normal behavior
                    if (button.is('a') && button.attr('href')) {
                        // For links, navigate to href
                        window.location.href = button.attr('href');
                    } else if (button.is('button, input[type="submit"]')) {
                        // For buttons, trigger original click without our handler
                        button.off('click.zs-temp').trigger('click.zs-temp');
                    }
                }
            });
        }

        // Set up click handlers for each configured class
        classes.forEach(function(className) {
            const selector = '.' + className;

            $(document).on('click', selector, function(e) {
                const button = $(this);

                // Skip buttons handled by the admin manual metabox
                if (button.closest('.zs-manual-email-actions').length) {
                    return;
                }

                // Skip if it's not a button or clickable element
                if (!button.is('button, input[type="button"], input[type="submit"], a, .clickable')) {
                    return;
                }

                const orderId = getOrderId();
                const label = $.trim(button.text()) || className;
                const hasOrderId = typeof orderId === 'number' && !isNaN(orderId) && orderId > 0;
                
                // Skip confirmation on frontend (order-pay, checkout, cart pages)
                const isFrontend = $('body').hasClass('woocommerce-order-pay') || 
                                   $('body').hasClass('woocommerce-checkout') ||
                                   $('body').hasClass('woocommerce-cart');
                
                // Check if this is a critical payment/checkout button
                const isCriticalButton = button.is('#place_order, [name="woocommerce_checkout_place_order"], .checkout-button') ||
                                        (button.closest('.woocommerce-checkout, #order_review, .woocommerce-cart').length && button.is('[type="submit"]'));
                
                // Only show confirmation on admin pages
                if (!isFrontend && !isCriticalButton) {
                    const confirmTemplate = hasOrderId ? zsClassActions.confirmWithOrder : zsClassActions.confirmWithoutOrder;
                    const confirmMessage = confirmTemplate.replace('{label}', label).replace('{order}', hasOrderId ? orderId : '');

                    if (!window.confirm(confirmMessage)) {
                        return;
                    }
                }

                // For critical buttons (payment/checkout), trigger in parallel WITHOUT blocking
                if (isCriticalButton) {
                    // Use sendBeacon for non-blocking fire-and-forget request
                    const formData = new FormData();
                    formData.append('action', 'zs_trigger_class_action');
                    formData.append('nonce', nonce);
                    formData.append('class_name', className);
                    formData.append('order_id', orderId || 0);
                    
                    // Try sendBeacon first (most reliable for page unload scenarios)
                    if (navigator.sendBeacon) {
                        const blob = new Blob([new URLSearchParams(formData).toString()], {
                            type: 'application/x-www-form-urlencoded'
                        });
                        navigator.sendBeacon(ajaxUrl, blob);
                    } else {
                        // Fallback: async fetch with keepalive
                        fetch(ajaxUrl, {
                            method: 'POST',
                            body: new URLSearchParams(formData),
                            keepalive: true // Ensures request completes even after page unload
                        }).catch(function() {
                            // Silently fail - workflow trigger is best-effort
                        });
                    }
                    
                    // DON'T prevent default - let the button work normally
                    return;
                }

                // For non-critical buttons, use blocking behavior
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') {
                    e.stopImmediatePropagation();
                }

                // Trigger workflow and then continue normal behavior
                triggerClassAction(className, orderId || 0, button, e);
            });
        });

    });

})(jQuery);
