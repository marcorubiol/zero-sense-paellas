/**
 * Add required attribute to billing fields for backend validation
 */
jQuery(document).ready(function($) {
    'use strict';
    
    function markRequiredFields() {
        // Billing required fields
        var billingRequired = [
            'input[name="_billing_first_name"]',
            'input[name="_billing_email"]'
        ];
        
        billingRequired.forEach(function(selector) {
            var field = $(selector);
            if (field.length) {
                field.attr('required', 'required');
            }
        });
    }
    
    // Run on page load
    markRequiredFields();
    
    // Run after a short delay to catch dynamically loaded fields
    setTimeout(markRequiredFields, 100);
    setTimeout(markRequiredFields, 500);
});
