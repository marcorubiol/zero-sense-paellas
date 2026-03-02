/**
 * Add required attribute to billing fields and mark all required field labels
 */
jQuery(document).ready(function($) {
    'use strict';
    
    function markRequiredFields() {
        console.log('[ZS Required] Running markRequiredFields...');
        
        // 1. Add required attribute to billing fields
        var billingRequired = [
            'input[name="_billing_first_name"]',
            'input[name="_billing_email"]'
        ];
        
        billingRequired.forEach(function(selector) {
            var field = $(selector);
            if (field.length) {
                field.attr('required', 'required');
                console.log('[ZS Required] Added required to:', selector);
            }
        });
        
        // 2. Add .zs-required class to all labels of required fields
        var metaboxCount = 0;
        var wcCount = 0;
        
        // For metaboxes (.zs-mb-field structure)
        $('.zs-mb-field input[required], .zs-mb-field select[required], .zs-mb-field textarea[required]').each(function() {
            var field = $(this);
            var fieldId = field.attr('id');
            if (fieldId) {
                var label = field.closest('.zs-mb-field').find('label[for="' + fieldId + '"]');
                if (label.length && !label.hasClass('zs-required')) {
                    label.addClass('zs-required');
                    metaboxCount++;
                    console.log('[ZS Required] Marked label for:', fieldId);
                }
            }
        });
        
        // For WooCommerce fields (#order_data .form-field structure)
        $('#order_data .form-field input[required], #order_data .form-field select[required], #order_data .form-field textarea[required]').each(function() {
            var field = $(this);
            var fieldId = field.attr('id');
            if (fieldId) {
                var label = field.closest('.form-field').find('label[for="' + fieldId + '"]');
                if (label.length && !label.hasClass('zs-required')) {
                    label.addClass('zs-required');
                    wcCount++;
                    console.log('[ZS Required] Marked WC label for:', fieldId);
                }
            }
        });
        
        console.log('[ZS Required] Marked ' + metaboxCount + ' metabox labels, ' + wcCount + ' WC labels');
    }
    
    // Run on page load
    markRequiredFields();
    
    // Run after delays to catch dynamically loaded fields
    setTimeout(markRequiredFields, 100);
    setTimeout(markRequiredFields, 500);
    setTimeout(markRequiredFields, 1000);
    setTimeout(markRequiredFields, 2000);
    
    // Watch for DOM changes (for AJAX-loaded content)
    if (window.MutationObserver) {
        var observer = new MutationObserver(function(mutations) {
            var shouldRun = false;
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    shouldRun = true;
                }
            });
            if (shouldRun) {
                setTimeout(markRequiredFields, 100);
            }
        });
        
        // Observe the entire order data area
        var orderData = document.getElementById('order_data');
        if (orderData) {
            observer.observe(orderData, {
                childList: true,
                subtree: true
            });
        }
        
        // Observe metaboxes container
        var metaboxes = document.getElementById('normal-sortables');
        if (metaboxes) {
            observer.observe(metaboxes, {
                childList: true,
                subtree: true
            });
        }
    }
});
