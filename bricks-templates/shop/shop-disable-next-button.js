/**
 * Proceed Button Control with Global Lock Awareness
 */
jQuery(function($) {
    const $proceedButton = $('.form-steps__button--next');
    
    // Make updateButtonState globally available for add-button-shop.js
    window.updateButtonState = function() {
        const productsInCart = $('.cart-circle-btn.in-cart').length;
        const isLoading = $('.cart-circle-btn.loading').length > 0;
        const isGloballyDisabled = $('.cart-circle-btn.globally-disabled').length > 0;
        
        if (isLoading || productsInCart === 0 || isGloballyDisabled) {
            $proceedButton.prop('disabled', true).addClass('disabled');
            if (window.location.hostname === 'localhost' || window.location.hostname.includes('staging') || typeof WP_DEBUG !== 'undefined' && WP_DEBUG) {
                console.log('🚫 Proceed button disabled - Loading:', isLoading, 'Products:', productsInCart, 'GlobalLock:', isGloballyDisabled);
            }
        } else {
            $proceedButton.prop('disabled', false).removeClass('disabled');
            if (window.location.hostname === 'localhost' || window.location.hostname.includes('staging') || typeof WP_DEBUG !== 'undefined' && WP_DEBUG) {
                console.log('✅ Proceed button enabled - Products in cart:', productsInCart);
            }
        }
    };
    
    // Update on events
    $(document).ready(updateButtonState);
    $(document).on('click', '.cart-circle-btn', updateButtonState);
    $(document.body).on('added_to_cart removed_from_cart wc_fragments_refreshed', updateButtonState);
});
