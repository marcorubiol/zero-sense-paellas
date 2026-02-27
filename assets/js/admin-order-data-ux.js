/**
 * UX/UI improvements for Billing/Shipping Admin Fields 
 * HPOS Compatible
 */
jQuery(document).ready(function ($) {
    
    function refineOrderDataLayout() {
        // Find the columns by looking at their headers to be 100% sure which is which
        var $billingCol = null;
        var $shippingCol = null;
        
        $('#order_data .order_data_column').each(function() {
            var $col = $(this);
            var headerText = $col.find('h3').first().text().trim().toLowerCase();
            var isBilling = headerText.indexOf('billing') === 0 || headerText.indexOf('facturaci') === 0 || headerText.indexOf('client') === 0;
            var isShipping = headerText.indexOf('shipping') === 0 || headerText.indexOf('envío') === 0 || headerText.indexOf('wedding') === 0;
            
            // In case of the edit icon being present, we might have multiple elements
            if ($col.find('.edit_address').length) {
                if ($col.find('.edit_address').attr('href').indexOf('billing') > -1) {
                    isBilling = true;
                } else if ($col.find('.edit_address').attr('href').indexOf('shipping') > -1) {
                    isShipping = true;
                }
            }

            if (isBilling) {
                $billingCol = $col;
                $col.addClass('zs-billing-col');
            } else if (isShipping) {
                $shippingCol = $col;
                $col.addClass('zs-shipping-col');
            }
        });
        
        if ($shippingCol && $shippingCol.length) {
            // Add Contact Section Title
            var $contactField = $shippingCol.find('.zs-contact-block-start');
            if ($contactField.length && $contactField.prev('.zs-section-title').length === 0) {
                $contactField.before('<div class="zs-section-title">Contact Person (In-situ)</div>');
            }

            // Add Venue Section Title
            var $venueField = $shippingCol.find('.zs-venue-block-start');
            if ($venueField.length && $venueField.prev('.zs-section-title').length === 0) {
                $venueField.before('<div class="zs-section-title">Venue & Location</div>');
            }
            
            // Find the Customer Note field by its label
            var $noteLabels = $shippingCol.find('label').filter(function() {
                var text = $(this).text().toLowerCase();
                return text.indexOf('note') > -1 || text.indexOf('nota') > -1;
            });
            
            if ($noteLabels.length) {
                var $noteWrapper = $noteLabels.closest('.form-field');
                $noteWrapper.addClass('zs-customer-note-block');
                
                // If it doesn't have an h3, add one
                if ($noteWrapper.find('h3').length === 0) {
                    $noteLabels.hide(); // Hide the normal label to replace it with a bolder header
                    $noteWrapper.prepend('<h3 class="zs-note-header">' + $noteLabels.text() + '</h3>');
                }
            }
        }
    }

    refineOrderDataLayout();
    
    // Wait for WooCommerce scripts that might alter DOM
    setTimeout(refineOrderDataLayout, 500);
});
