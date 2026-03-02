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
    
    /**
     * Auto-expand billing/shipping sections if they have empty required fields
     */
    function autoExpandRequiredSections() {
        // Check billing section
        var billingEmpty = false;
        $('input[name="_billing_first_name"][required], input[name="_billing_email"][required]').each(function() {
            if ($(this).val().trim() === '') {
                billingEmpty = true;
            }
        });
        
        if (billingEmpty) {
            // Find the billing column and click the Edit button
            var billingColumn = $('.order_data_column').filter(function() {
                return $(this).find('input[name="_billing_first_name"]').length > 0;
            });
            
            if (billingColumn.length) {
                var editButton = billingColumn.find('.edit_address');
                if (editButton.length && editButton.is(':visible')) {
                    editButton.click();
                }
            }
        }
        
        // Check shipping section (for future required fields)
        var shippingEmpty = false;
        var shippingColumn = $('.order_data_column').filter(function() {
            return $(this).find('input[name^="_shipping"]').length > 0;
        });
        
        if (shippingColumn.length) {
            shippingColumn.find('input[required], select[required], textarea[required]').each(function() {
                if ($(this).val().trim() === '') {
                    shippingEmpty = true;
                }
            });
            
            if (shippingEmpty) {
                var editButton = shippingColumn.find('.edit_address');
                if (editButton.length && editButton.is(':visible')) {
                    editButton.click();
                }
            }
        }
    }
    
    // Run on page load
    markRequiredFields();
    autoExpandRequiredSections();
    
    // Run after a short delay to catch dynamically loaded fields
    setTimeout(function() {
        markRequiredFields();
        autoExpandRequiredSections();
    }, 100);
    
    setTimeout(function() {
        markRequiredFields();
        autoExpandRequiredSections();
    }, 500);
    
    setTimeout(function() {
        markRequiredFields();
        autoExpandRequiredSections();
    }, 1000);
});
