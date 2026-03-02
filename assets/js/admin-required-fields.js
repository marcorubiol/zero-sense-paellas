/**
 * Add required attribute to billing fields and mark all required field labels
 */
jQuery(document).ready(function($) {
    'use strict';
    
    function markRequiredFields() {
        // 1. Add required attribute to billing fields
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
        
        // 2. Add .zs-required class to all labels of required fields
        // For metaboxes (.zs-mb-field structure)
        $('.zs-mb-field input[required], .zs-mb-field select[required], .zs-mb-field textarea[required]').each(function() {
            var field = $(this);
            var fieldId = field.attr('id');
            if (fieldId) {
                var label = field.closest('.zs-mb-field').find('label[for="' + fieldId + '"]');
                if (label.length && !label.hasClass('zs-required')) {
                    label.addClass('zs-required');
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
                }
            }
        });
    }
    
    // Run on page load
    markRequiredFields();
    
    // Run after a short delay to catch dynamically loaded fields
    setTimeout(markRequiredFields, 100);
    setTimeout(markRequiredFields, 500);
    setTimeout(markRequiredFields, 1000);
});
