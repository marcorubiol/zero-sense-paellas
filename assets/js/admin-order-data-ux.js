/**
 * UX/UI improvements for Billing/Shipping Admin Fields 
 * HPOS Compatible
 */
jQuery(document).ready(function ($) {
    
    function refineOrderDataLayout() {
        // order_data_column usually has 3 columns: General (0), Billing (1), Shipping (2)
        var $billingCol = $('#order_data .order_data_column').eq(1);
        var $shippingCol = $('#order_data .order_data_column').eq(2);
        
        if ($shippingCol.length) {
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
