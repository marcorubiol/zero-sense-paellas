/**
 * Payment Method Classes
 * 
 * Adds CSS classes to payment buttons based on selected payment method
 * for Flowmattic integration and other features.
 */
(function() {
    'use strict';
    
    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        // Check if we have the configuration
        if (typeof zsPaymentClasses === 'undefined') {
            return;
        }
        
        const classMap = zsPaymentClasses.classes || {};
        let currentMethod = null;
        let cachedButton = null;

        function getCheckoutButton() {
            if (cachedButton && document.body.contains(cachedButton)) {
                return cachedButton;
            }

            const selectors = [
                'form.checkout #place_order',
                'form.checkout button[type="submit"]',
                'form#order_review #place_order',
                'form#order_review button[type="submit"]'
            ];

            for (const selector of selectors) {
                const button = document.querySelector(selector);
                if (button) {
                    cachedButton = button;
                    return cachedButton;
                }
            }

            cachedButton = null;
            return null;
        }
        // Function to update classes based on selected payment method
        function updatePaymentClasses() {
            const selectedMethod = getSelectedPaymentMethod();
            
            if (selectedMethod !== currentMethod) {
                const button = getCheckoutButton();

                if (button) {
                    // Remove old classes from button and body (cleanup from previous versions)
                    if (currentMethod && classMap[currentMethod]) {
                        button.classList.remove(classMap[currentMethod]);
                        document.body.classList.remove(classMap[currentMethod]);
                    }

                    // Add new classes to button
                    if (selectedMethod && classMap[selectedMethod]) {
                        button.classList.add(classMap[selectedMethod]);
                        document.body.classList.remove(classMap[selectedMethod]);
                    }
                } else if (currentMethod && classMap[currentMethod]) {
                    // Button not found but ensure legacy body class is removed
                    document.body.classList.remove(classMap[currentMethod]);
                }

                currentMethod = selectedMethod;
            }
        }
        
        // Function to get currently selected payment method
        function getSelectedPaymentMethod() {
            const checkedInput = document.querySelector('input[name="payment_method"]:checked');
            return checkedInput ? checkedInput.value : null;
        }
        
        // Initial update
        updatePaymentClasses();
        
        // Listen for payment method changes
        document.addEventListener('change', function(e) {
            if (e.target && e.target.name === 'payment_method') {
                updatePaymentClasses();
            }
        });
        
        // Also listen for WooCommerce checkout updates
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('updated_checkout', function() {
                setTimeout(updatePaymentClasses, 100);
            });
        }
    });
})();
