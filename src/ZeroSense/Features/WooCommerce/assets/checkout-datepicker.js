/**
 * Checkout Datepicker - Flatpickr integration for WooCommerce checkout
 * Zero Sense v3 - Optimized for performance and vanilla JS
 */
(function() {
    'use strict';

    /**
     * Initialize Flatpickr on checkout date/time fields
     */
    function initFlatpickrOnCheckoutFields() {
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Use modern selector and avoid jQuery dependency where possible
        const dateTimeFields = document.querySelectorAll('input[type="date"], input[type="time"]');
        
        dateTimeFields.forEach(function(field) {
            const originalType = field.getAttribute('type');
            
            // Skip if already activated
            if (field.classList.contains('zs-flatpickr-activated')) {
                return;
            }

            // Mark as activated and change type to text
            field.setAttribute('type', 'text');
            field.classList.add('zs-flatpickr-activated');

            // Check if Flatpickr is available
            if (typeof flatpickr === 'undefined') {
                return;
            }

            // Initialize based on original field type
            if (originalType === 'date') {
                flatpickr(field, {
                    dateFormat: 'd/m/Y',
                    minDate: today,
                    allowInput: true,
                    locale: {
                        firstDayOfWeek: 1 // Monday
                    }
                });
            } else if (originalType === 'time') {
                flatpickr(field, {
                    enableTime: true,
                    noCalendar: true,
                    dateFormat: 'H:i',
                    time_24hr: true,
                    minuteIncrement: 15,
                    defaultDate: '18:00',
                    allowInput: true
                });
            }
        });
    }

    /**
     * Initialize when DOM is ready
     */
    function init() {
        // Initial run
        initFlatpickrOnCheckoutFields();

        // Watch for DOM changes (fields added dynamically by WooCommerce)
        const checkoutForm = document.querySelector('form.checkout');
        if (checkoutForm && typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(function(mutations) {
                let shouldReinit = false;
                
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        // Check if any added nodes contain date/time inputs
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === Node.ELEMENT_NODE) {
                                const hasDateTimeFields = node.querySelector && 
                                    node.querySelector('input[type="date"], input[type="time"]');
                                if (hasDateTimeFields) {
                                    shouldReinit = true;
                                }
                            }
                        });
                    }
                });

                if (shouldReinit) {
                    // Debounce reinit to avoid excessive calls
                    clearTimeout(init.reinitTimeout);
                    init.reinitTimeout = setTimeout(initFlatpickrOnCheckoutFields, 100);
                }
            });

            observer.observe(checkoutForm, { 
                childList: true, 
                subtree: true 
            });
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Fallback for jQuery environments (WooCommerce compatibility)
    if (typeof jQuery !== 'undefined') {
        jQuery(function($) {
            // Ensure we run after WooCommerce checkout scripts
            setTimeout(init, 100);
        });
    }

})();
