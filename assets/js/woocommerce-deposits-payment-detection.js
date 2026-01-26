/**
 * WooCommerce Deposits – Payment detection helper.
 * Ported from the legacy implementation to ensure the order-pay page
 * correctly detects when payment methods are present (including WPML URLs
 * and dynamically injected gateways).
 */
jQuery(function($) {
    'use strict';

    function detectPaymentMethods() {
        var standardElements =
            $('.payment_methods, .woocommerce-checkout-payment').length > 0 ||
            $('input[name="payment_method"]').length > 0 ||
            $('#place_order, .button.alt[name="woocommerce_checkout_place_order"]').length > 0 ||
            $('.payment_box:visible').length > 0 ||
            $('#payment, form.checkout #payment, #order_review #payment').length > 0;

        var gatewayElements =
            $('#stripe-payment-data').length > 0 ||
            $('#payment_method_stripe').length > 0 ||
            $('#payment_method_bacs').length > 0 ||
            $('#payment_method_cheque').length > 0 ||
            $('#payment_method_cod').length > 0 ||
            $('#payment_method_paypal').length > 0 ||
            $('input[id^="payment_method_"]').length > 0;

        var urlHasIndicators = false;
        if (window.location && window.location.href) {
            var url = window.location.href.toLowerCase();
            urlHasIndicators =
                url.indexOf('/order-pay/') > -1 ||
                url.indexOf('pedido-pago') > -1 ||
                url.indexOf('commande-paiement') > -1 ||
                url.indexOf('bestelling-betalen') > -1 ||
                url.indexOf('bestellung-bezahlen') > -1 ||
                url.indexOf('ordine-pagamento') > -1;
        }

        var isOrderPayPage = $('body').hasClass('woocommerce-order-pay') ||
                             $('.woocommerce-order-pay').length > 0;

        var formNotVerification =
            $('#order_review').length > 0 &&
            $('.woocommerce-verify-email').length === 0 &&
            $('form.woocommerce-verify-email').length === 0;

        var hasPaymentMethods =
            (standardElements || gatewayElements) &&
            (urlHasIndicators || isOrderPayPage) &&
            formNotVerification;

        if ($('input[name="payment_method"]:checked').length > 0 || $('.payment_box:visible').length > 0) {
            hasPaymentMethods = true;
        }

        return hasPaymentMethods;
    }

    function runDetection() {
        var hasPaymentMethods = detectPaymentMethods();
        window.zsDepositsPaymentContext = hasPaymentMethods;
        $('body').toggleClass('zs-deposits-payment-context', hasPaymentMethods);
        return hasPaymentMethods;
    }

    function setupObserver() {
        if (!window.MutationObserver) {
            return;
        }

        var observer = new MutationObserver(function() {
            if ($('#payment, .payment_methods, input[name="payment_method"]').length) {
                runDetection();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    runDetection();

    $(document).ready(function() {
        runDetection();
        setupObserver();
    });

    $(window).on('load', function() {
        setTimeout(runDetection, 500);
    });

    $(document.body).on('updated_checkout', function() {
        runDetection();
    });
});
