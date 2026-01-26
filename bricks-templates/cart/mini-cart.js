// NOTE: Reference-only script for Bricks templates. Do NOT enqueue from the Zerø Sense plugin runtime.

jQuery(document).ready(function($) {
    const CartHandler = {
        ajaxInProgress: false,
        updateTimeout: null,
        pendingRequests: new Map(),

        init: function() {
            this.bindEvents();
            this.initForceRefresh();
        },

        bindEvents: function() {
            $(document).on('click', '.action.plus', this.handlePlusClick.bind(this));
            $(document).on('click', '.action.minus', this.handleMinusClick.bind(this));
            $(document).on('change', '.qty', this.handleQuantityChange.bind(this));
            $(document).on('click', '.zs_remove_link', this.handleRemoveProduct.bind(this));
            $(document).on('keydown', '.qty', this.handleEnterKey.bind(this));
            $(document).on('blur', '.qty', this.handleInputBlur.bind(this));
        },

        handlePlusClick: function(e) {
            e.preventDefault();
            const input = $(e.currentTarget).closest('.quantity').find('.qty');
            if (!input.length) return;
            
            const currentQty = parseInt(input.val()) || 1;
            const maxQty = parseInt(input.attr('max')) || 9999;
            const newQty = Math.min(currentQty + 1, maxQty);
            
            this.queueUpdate(input, newQty);
        },

        handleMinusClick: function(e) {
            e.preventDefault();
            const input = $(e.currentTarget).closest('.quantity').find('.qty');
            if (!input.length) return;
            
            const currentQty = parseInt(input.val()) || 1;
            this.queueUpdate(input, Math.max(1, currentQty - 1));
        },

        handleQuantityChange: function(e) {
            const input = $(e.currentTarget);
            const newQty = Math.max(1, parseInt(input.val()) || 1);
            const maxQty = parseInt(input.attr('max')) || 9999;
            this.queueUpdate(input, Math.min(newQty, maxQty));
        },

        handleRemoveProduct: function(e) {
            e.preventDefault();
            if (this.ajaxInProgress) return;

            const link = $(e.currentTarget);
            const wrapper = link.closest('.zs_product_wrapper');
            if (!wrapper.length) return;

            this.queueUpdate(wrapper.find('.qty'), 0);
        },

        // NEW: Queue system to prevent race conditions
        queueUpdate: function(input, newQuantity) {
            const wrapper = input.closest('.zs_product_wrapper');
            if (!wrapper.length) return;

            const itemQty = input.closest('.zs_cart_item_qty');
            if (!itemQty.length) return;

            const cartItemKey = itemQty.data('cart_item_key');
            
            // Cancel any pending request for this product
            if (this.pendingRequests.has(cartItemKey)) {
                clearTimeout(this.pendingRequests.get(cartItemKey));
            }
            
            // Debounce the update
            const timeoutId = setTimeout(() => {
                this.updateQuantity(input, newQuantity);
                this.pendingRequests.delete(cartItemKey);
            }, 300);
            
            this.pendingRequests.set(cartItemKey, timeoutId);
        },

        updateQuantity: function(input, newQuantity) {
            if (this.ajaxInProgress || !input.length) return;

            const wrapper = input.closest('.zs_product_wrapper');
            if (!wrapper.length) return;

            const itemQty = input.closest('.zs_cart_item_qty');
            if (!itemQty.length) return;

            const cartItemKey = itemQty.data('cart_item_key');
            const variationId = itemQty.data('variation-id');

            // Validate AJAX requirements
            if (typeof zsCartHandler === 'undefined' || !zsCartHandler.ajaxUrl || !zsCartHandler.nonce) {
                console.error('❌ zsCartHandler not properly configured');
                this.handleError('Configuración de carrito no disponible');
                return;
            }

            this.ajaxInProgress = true;
            wrapper.addClass('zs_loading');
            if (typeof window.updateButtonState === 'function') {
                window.updateButtonState();
            }
            // Activate global loading UI (bar + shimmer) for consistency with shop
            $('body').addClass('zs-global-loading');
            let triggeredFragmentRefresh = false;

            $.ajax({
                url: zsCartHandler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zs_update_quantity',
                    security: zsCartHandler.nonce,
                    cart_item_key: cartItemKey,
                    quantity: newQuantity,
                    variation_id: variationId
                },
                success: (response) => {
                    if (response.success) {
                        if (newQuantity === 0) {
                            this.handleProductRemoval(wrapper);
                        } else {
                            input.val(newQuantity);
                            wrapper.addClass('zs_quantity_updated');
                            setTimeout(() => wrapper.removeClass('zs_quantity_updated'), 800);
                        }
                        
                        // Let WooCommerce refresh fragments; avoid extra totals request
                        triggeredFragmentRefresh = true;
                        $(document.body).trigger('wc_fragment_refresh');
                    } else {
                        this.handleError(response.data?.message || 'Error al actualizar el carrito');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Ajax Error:', error);
                    this.handleError('Error en la conexión');
                },
                complete: () => {
                    this.ajaxInProgress = false;
                    wrapper.removeClass('zs_loading');
                    if (triggeredFragmentRefresh) {
                        // Wait until Woo fragments are refreshed to clear global loading
                        $(document.body).one('wc_fragments_refreshed', () => {
                            $('body').removeClass('zs-global-loading');
                            if (typeof window.updateButtonState === 'function') {
                                window.updateButtonState();
                            }
                        });
                        // Fallback in case the fragment refresh takes too long or is not present
                        setTimeout(() => {
                            if ($('body').hasClass('zs-global-loading')) {
                                $('body').removeClass('zs-global-loading');
                                if (typeof window.updateButtonState === 'function') {
                                    window.updateButtonState();
                                }
                            }
                        }, 1500);
                    } else {
                        // No fragment refresh; clear loading immediately
                        $('body').removeClass('zs-global-loading');
                        if (typeof window.updateButtonState === 'function') {
                            window.updateButtonState();
                        }
                    }
                }
            });
        },

        handleProductRemoval: function(wrapper) {
            wrapper.fadeOut(300, function() {
                const categorySection = wrapper.closest('.zs_category_section');
                wrapper.remove();
                
                if (categorySection.length && categorySection.find('.zs_product_wrapper').length === 0) {
                    categorySection.fadeOut(300, function() {
                        categorySection.remove();
                    });
                }
                
                if ($('.zs_product_wrapper').length === 0) {
                    $('.zs_minicart').html('<p class="zs_empty_cart">Tu carrito está vacío</p>');
                }
            });
        },

        updateCartTotals: function() {
            // Validate AJAX requirements before making request
            if (typeof zsCartHandler === 'undefined' || !zsCartHandler.ajaxUrl || !zsCartHandler.nonce) {
                console.warn('⚠️ zsCartHandler not available for updateCartTotals');
                return;
            }

            $.ajax({
                url: zsCartHandler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zs_get_cart_totals',
                    security: zsCartHandler.nonce
                },
                success: (response) => {
                    if (response.success && response.data) {
                        $('.cart-subtotal .amount').html(response.data.subtotal);
                        $('.order-total .amount').html(response.data.total);
                    }
                },
                error: function(xhr, status, error) {
                    console.warn('⚠️ Failed to update cart totals:', error);
                }
            });
        },

        handleEnterKey: function(e) {
            if (e.keyCode === 13) {
                e.preventDefault();
                $(e.currentTarget).blur();
            }
        },

        handleInputBlur: function(e) {
            const input = $(e.currentTarget);
            const newQty = Math.max(1, parseInt(input.val()) || 1);
            const maxQty = parseInt(input.attr('max')) || 9999;
            input.val(Math.min(newQty, maxQty));
            this.queueUpdate(input, Math.min(newQty, maxQty));
        },

        handleError: function(message) {
            console.error(message);
            // Avoid blocking the main thread with alert; use console for now.
            // A non-blocking inline/toast UI can be added later.
        },

        // Force refresh functionality for back navigation
        initForceRefresh: function() {
            const self = this;
            
            function forceCartUpdate() {
                // Force a lightweight fragment refresh
                // Let Woo refresh fragments; avoid extra totals and mass change triggers
                $(document.body).trigger('wc_fragment_refresh');
            }
            
            // Execute on page load
            setTimeout(forceCartUpdate, 500);
            
            // Execute when coming from back button
            $(window).on('pageshow', function(event) {
                if (event.originalEvent.persisted) {
                    setTimeout(forceCartUpdate, 200);
                }
            });

            // Optional: hook into fragments refreshed for any lightweight sync if needed
            $(document.body).on('wc_fragments_refreshed', function() {
                // Fragments refreshed
            });
        }
    };

    // Make CartHandler globally accessible for synchronization
    window.CartHandler = CartHandler;
    
    // Initialize the cart handler
    CartHandler.init();
    
    // Listen for shop button events to stay synchronized (lightweight)
    $(document.body).on('added_to_cart removed_from_cart', function(event, fragments, cart_hash, button) {
        // Let WooCommerce refresh fragments; avoid duplicate totals calls and mass input change
        $(document.body).trigger('wc_fragment_refresh');
    });
});
