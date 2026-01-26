/**
 * Proceed Button Control with Global Lock Awareness
 *
 * NOTE: Reference-only script for Bricks templates. Do NOT enqueue from the Zerø Sense plugin runtime.
 */
jQuery(function($) {
    const $proceedWrapper = $('.form-steps__button--next');
    // Fallback: some templates may not render an inner button; use wrapper as control
    let $proceedButton = $proceedWrapper.find('button[type="submit"]');
    if ($proceedButton.length === 0) {
        $proceedButton = $proceedWrapper; // fallback to wrapper itself
    }
    
    // Make updateButtonState globally available for add-button-shop.js
    window.updateButtonState = function() {
        // Shop context signals (shop buttons)
        const productsInCartShop = $('.cart-circle-btn.in-cart').length;
        const isLoadingShop = $('.cart-circle-btn.loading').length > 0;
        const isGloballyDisabledShop = $('.cart-circle-btn.globally-disabled').length > 0;

        // Cart page signals (mini-cart DOM)
        const $miniCart = $('.zs_minicart');
        const miniCartItems = $miniCart.find('[data-cart_item_key]').length;
        const isCartEmptyBanner = $miniCart.find('.zs_empty_cart').length > 0;
        const hasProductsMiniCart = $miniCart.length > 0 && miniCartItems > 0 && !isCartEmptyBanner;
        const isMiniCartLoading = $miniCart.find('.zs_loading').length > 0;

        // Global loading state (set by shop and cart handlers)
        const isGlobalLoading = $('body').hasClass('zs-global-loading');

        // Determine if there are products using either context
        const hasProducts = hasProductsMiniCart || productsInCartShop > 0;

        const shouldDisable = (
            isGlobalLoading || isMiniCartLoading || isLoadingShop || isGloballyDisabledShop || !hasProducts
        );

        // Toggle both wrapper class and inner button state
        $proceedWrapper.toggleClass('disabled', shouldDisable)
                       .attr('aria-disabled', shouldDisable);
        // Remove any stale disabled attribute on wrapper when enabling
        if (shouldDisable) {
            $proceedWrapper.attr('disabled', 'disabled');
        } else {
            $proceedWrapper.removeAttr('disabled');
        }
        $proceedButton.prop('disabled', shouldDisable).attr('aria-disabled', shouldDisable);
    };
    
    // Update on events
    $(document).ready(updateButtonState);
    $(document).on('click', '.cart-circle-btn', updateButtonState);
    $(document.body).on('added_to_cart removed_from_cart wc_fragment_refresh wc_fragments_refreshed', function(){
        // Defer slightly to allow DOM replacements to settle
        setTimeout(updateButtonState, 20);
    });
});
