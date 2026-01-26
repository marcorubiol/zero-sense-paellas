/**
 * Order Pay Conditional Display
 * Controls visibility of payment options and reservation section based on order status
 */
(function($) {
    'use strict';

    /**
     * Handle Terms and Conditions UX
     */
    function handleTermsAndConditionsUX() {
        // If verification screen is active, do not attach T&C UX yet
        var $body = $(document.body);
        if ($body.hasClass('wc-order-pay-verify-email')) {
            return;
        }

        const $termsCheckbox = $('#terms');
        const $payButton = $('#place_order');
        const $termsRow = $termsCheckbox.closest('p.form-row');
        
        if ($termsCheckbox.length === 0 || $payButton.length === 0) {
            // Clean up any legacy helper if present
            $('#zs-terms-helper-text, .zs-terms-helper').remove();
            return;
        }

        // Check if zsOrderPayData is available
        if (typeof zsOrderPayData === 'undefined') {
            // Fallback text used below when localization object is not available
        }

        // Helper text under the checkbox intentionally removed per UX request.
        // Clean up any pre-existing helper injected by legacy scripts
        $('#zs-terms-helper-text, .zs-terms-helper').remove();

        // Function to update pay button state
        function updatePayButtonState() {
            if ($termsCheckbox.is(':checked')) {
                $body.addClass('zs-terms-accepted');
            } else {
                $body.removeClass('zs-terms-accepted');
            }
        }

        // Initial state
        updatePayButtonState();

        // Listen for changes
        $termsCheckbox.on('change', updatePayButtonState);
    }

    $(document).ready(function() {
        // Setup with retry mechanism for dynamic content
        var retries = 0;
        var maxRetries = 20; // ~6 seconds total with initial + 300ms steps
        function trySetup() {
            var verifying = $(document.body).hasClass('wc-order-pay-verify-email');
            if (!verifying) {
                handleTermsAndConditionsUX();
            } else {
                // Still in verification mode, retry
                if (retries < maxRetries) {
                    retries++;
                    setTimeout(trySetup, 300);
                }
            }
        }
        
        trySetup();
        
        // Check if we have the necessary data
        if (typeof zsOrderPayData === 'undefined') {
            return;
        }

        const orderStatus = zsOrderPayData.orderStatus;
        const showPaymentOptions = zsOrderPayData.showPaymentOptions;
        const showMakeReservation = zsOrderPayData.showMakeReservation;

        // Handle payment options visibility
        if (!showPaymentOptions) {
            // Hide payment-related elements
            const paymentSelectors = [
                '#payment-options', // The main payment options section
                '#payment',
                '.woocommerce-checkout-payment',
                '.payment_methods',
                '#place_order',
                '.wc-proceed-to-checkout',
                '.woocommerce-form-coupon-toggle',
                '.checkout_coupon'
            ];
            
            paymentSelectors.forEach(function(selector) {
                $(selector).hide();
            });
        }

        // Handle make reservation section visibility
        if (!showMakeReservation) {
            $('#make-reservation').hide();
        }

        // Add CSS for terms button state (if terms are present)
        if ($('#terms').length > 0) {
            const css = `
                /* Prevent first-paint activation of the Pay button on pending orders.
                   JS will toggle body.zs-terms-accepted to re-enable when terms are checked. */
                #place_order {
                    pointer-events: none;
                    opacity: 0.6;
                }
                body.zs-terms-accepted #place_order {
                    pointer-events: auto;
                    opacity: 1;
                }
            `;
            
            if ($('#zs-order-pay-styles').length === 0) {
                $('<style id="zs-order-pay-styles">' + css + '</style>').appendTo('head');
            }
        }
    });

})(jQuery);
