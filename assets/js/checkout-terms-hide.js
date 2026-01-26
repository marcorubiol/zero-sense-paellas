document.addEventListener('DOMContentLoaded', function() {
    // Use jQuery as it's a WooCommerce dependency for checkout
    if (typeof jQuery === 'undefined') {
        return;
    }

    const $ = jQuery;

    const hideTermsAndConditions = () => {
        const termsWrapper = $('.woocommerce-terms-and-conditions-wrapper');
        if (termsWrapper.length) {
            termsWrapper.hide();
        }
    };

    // Initial hide on page load
    hideTermsAndConditions();

    // Re-hide after any AJAX update on the checkout page
    $(document.body).on('updated_checkout', function() {
        hideTermsAndConditions();
    });
});
