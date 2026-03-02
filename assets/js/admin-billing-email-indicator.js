/**
 * Add visual indicator to billing email field showing it's the primary contact
 */
jQuery(document).ready(function($) {
    'use strict';
    
    function addEmailIndicator() {
        var emailField = $('#_billing_email');
        
        if (!emailField.length) {
            return;
        }
        
        var formField = emailField.closest('.form-field');
        if (!formField.length) {
            return;
        }
        
        var label = formField.find('label[for="_billing_email"]');
        if (!label.length) {
            return;
        }
        
        // Check if already added
        if (label.find('.zs-email-indicator').length > 0) {
            return;
        }
        
        // Add icon with tooltip to label
        var icon = $('<span class="zs-email-indicator" title="Primary contact for all order notifications">📧</span>');
        label.append(' ').append(icon);
        
        // Add helper text below the field
        if (formField.find('.zs-email-helper').length === 0) {
            var helperText = $('<p class="zs-email-helper"><span class="dashicons dashicons-info-outline"></span> All order notifications will be sent here</p>');
            emailField.after(helperText);
        }
    }
    
    // Run on page load
    addEmailIndicator();
    
    // Run after delays to catch WooCommerce dynamic loading
    setTimeout(addEmailIndicator, 100);
    setTimeout(addEmailIndicator, 500);
    setTimeout(addEmailIndicator, 1000);
    
    // Re-run when edit mode is activated
    $(document).on('click', '.edit_address', function() {
        setTimeout(addEmailIndicator, 100);
    });
});
