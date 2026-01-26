/**
 * Cart Buttons with Global Locking - Stability over UX
 * Blocks all cart buttons during any AJAX operation to prevent race conditions
 *
 * NOTE: Reference-only script for Bricks templates. Do NOT enqueue from the Zerø Sense plugin.
 */
jQuery(function($) {
    // Debug flag and logger
    const debug = (window.location.hostname === 'localhost') || (window.location.hostname?.includes('staging')) || (typeof WP_DEBUG !== 'undefined' && WP_DEBUG);
    const logDebug = (...args) => { if (debug) console.log(...args); };

    // Global state to prevent concurrent operations
    let isCartOperationInProgress = false;

    // Function to block/unblock all cart buttons
    function setGlobalCartLock(locked) {
        isCartOperationInProgress = locked;
        const $allButtons = $('.cart-circle-btn');
        // Toggle global loading class on body to drive top progress bar and global shimmer
        $('body').toggleClass('zs-global-loading', locked);

        if (locked) {
            $allButtons.addClass('globally-disabled').prop('disabled', true);
            logDebug('🔒 All cart buttons locked');
        } else {
            $allButtons.removeClass('globally-disabled').prop('disabled', false);
            logDebug('🔓 All cart buttons unlocked');
        }

        // Update proceed button state if available
        if (typeof updateButtonState === 'function') {
            updateButtonState();
        }
    }
    
    // Add to cart with global locking
    $(document).on('click', '.zs-add-to-cart', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const productId = $button.data('product-id');
        const quantity = $button.data('quantity') || 1;
        if (!productId) {
            logDebug('⚠️ Missing product ID on add to cart button');
            return;
        }
        if (typeof zsCartHandler === 'undefined' || !zsCartHandler?.ajaxUrl || !zsCartHandler?.nonce) {
            logDebug('⚠️ Missing zsCartHandler configuration');
            return;
        }
        
        // Prevent any action if cart operation is in progress
        if (isCartOperationInProgress || $button.hasClass('loading') || $button.hasClass('globally-disabled')) {
            logDebug('⚠️ Cart operation blocked - another operation in progress');
            return;
        }
        
        // Lock all buttons globally
        setGlobalCartLock(true);
        $button.addClass('loading').removeClass('success error');
        
        $.ajax({
            url: zsCartHandler.ajaxUrl,
            type: 'POST',
            data: {
                action: 'zs_add_to_cart',
                security: zsCartHandler.nonce,
                product_id: productId,
                quantity: quantity
            },
            success: function(response) {
                logDebug('✅ Add to cart response:', response);
                
                if (response.success) {
                    // Update all buttons for this product
                    const $allButtons = $('.cart-circle-btn[data-product-id="' + productId + '"]');
                    $allButtons.removeClass('loading zs-add-to-cart')
                               .addClass('success in-cart zs-remove-from-cart')
                               .attr('data-cart-item-key', response.data.cart_item_key || '');
                    
                    // Update fragments
                    if (response.data.fragments) {
                        $.each(response.data.fragments, function(key, value) {
                            if ($(key).length) {
                                $(key).replaceWith(value);
                            }
                        });
                    }
                    
                    // Trigger WooCommerce events
                    const $body = $(document.body);
                    $body.trigger('added_to_cart', [response.data.fragments || {}, response.data.cart_hash || '', $allButtons.first()]);
                    $body.trigger('wc_fragment_refresh');
                    
                    // Reset success state
                    setTimeout(() => $allButtons.removeClass('success'), 1500);
                } else {
                    console.error('❌ Add to cart failed:', response?.data?.message);
                    $button.removeClass('loading').addClass('error');
                    setTimeout(() => $button.removeClass('error'), 2000);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX error:', status, error);
                $button.removeClass('loading').addClass('error');
                setTimeout(() => $button.removeClass('error'), 2000);
            },
            complete: function() {
                // Always unlock all buttons when operation completes
                setTimeout(() => setGlobalCartLock(false), 500);
            }
        });
    });
    
    // Remove from cart with global locking
    $(document).on('click', '.zs-remove-from-cart', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const productId = $button.data('product-id');
        const cartItemKey = $button.data('cart-item-key');
        if (!productId || !cartItemKey) {
            logDebug('⚠️ Missing productId/cartItemKey on remove');
            return;
        }
        if (typeof zsCartHandler === 'undefined' || !zsCartHandler?.ajaxUrl || !zsCartHandler?.nonce) {
            logDebug('⚠️ Missing zsCartHandler configuration');
            return;
        }
        
        // Prevent any action if cart operation is in progress
        if (isCartOperationInProgress || $button.hasClass('loading') || $button.hasClass('globally-disabled')) {
            logDebug('⚠️ Remove operation blocked - another operation in progress');
            return;
        }
        
        // Lock all buttons globally
        setGlobalCartLock(true);
        $button.addClass('loading');
        
        $.ajax({
            url: zsCartHandler.ajaxUrl,
            type: 'POST',
            data: {
                action: 'zs_remove_from_cart',
                security: zsCartHandler.nonce,
                cart_item_key: cartItemKey
            },
            success: function(response) {
                logDebug('✅ Remove from cart response:', response);
                
                if (response.success) {
                    const $allButtons = $('.cart-circle-btn[data-product-id="' + productId + '"]');
                    $allButtons.removeClass('loading in-cart zs-remove-from-cart')
                               .addClass('zs-add-to-cart')
                               .attr('data-cart-item-key', '');
                    
                    if (response.data.fragments) {
                        $.each(response.data.fragments, function(key, value) {
                            if ($(key).length) {
                                $(key).replaceWith(value);
                            }
                        });
                    }
                    
                    const $body = $(document.body);
                    $body.trigger('removed_from_cart', [response.data.fragments || {}, response.data.cart_hash || '', $allButtons.first()]);
                    $body.trigger('wc_fragment_refresh');
                } else {
                    console.error('❌ Remove from cart failed:', response?.data?.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Remove AJAX error:', status, error);
            },
            complete: function() {
                // Always unlock all buttons when operation completes
                setTimeout(() => setGlobalCartLock(false), 500);
            }
        });
    });
    
    // Touch support for mobile
    if ('ontouchstart' in window) {
        let touchTimeout;
        $(document).on('touchstart', '.cart-circle-btn.in-cart:not(.loading)', function(e) {
            const $button = $(this);
            if (!$button.hasClass('show-remove')) {
                e.preventDefault();
                clearTimeout(touchTimeout);
                $button.addClass('show-remove');
                touchTimeout = setTimeout(() => $button.removeClass('show-remove'), 3000);
            }
        });
    }
});
