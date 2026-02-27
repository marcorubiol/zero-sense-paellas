/**
 * UX/UI improvements for Billing/Shipping Admin Fields 
 * HPOS Compatible
 */
jQuery(document).ready(function ($) {
    
    function refineOrderDataLayout() {
        // First add a flex gap to the container
        $('#order_data .order_data_column_container').css({
            'display': 'flex',
            'flex-wrap': 'wrap',
            'gap': '20px'
        });

        // Ensure columns take up correct width within flex, but allow stacking
        $('#order_data .order_data_column').css({
            'flex': '1 1 min(300px, calc(33.33% - 14px))',
            'min-width': '300px',
            'width': 'auto',
            'float': 'none'
        });

        // Find the columns by looking at their headers and edit links
        var $billingCol = null;
        var $shippingCol = null;
        
        $('#order_data .order_data_column').each(function() {
            var $col = $(this);
            var isBilling = false;
            var isShipping = false;
            
            // Primary detection: use edit_address link which is reliable
            if ($col.find('.edit_address').length) {
                var editHref = $col.find('.edit_address').attr('href') || '';
                if (editHref.indexOf('billing') > -1) {
                    isBilling = true;
                } else if (editHref.indexOf('shipping') > -1) {
                    isShipping = true;
                }
            }
            
            // Fallback: check header text (supports both old and new titles)
            if (!isBilling && !isShipping) {
                var headerText = $col.find('h3').first().text().trim().toLowerCase();
                isBilling = headerText.indexOf('billing') === 0 || headerText.indexOf('facturaci') === 0 || headerText.indexOf('client') === 0;
                isShipping = headerText.indexOf('shipping') === 0 || headerText.indexOf('envío') === 0 || headerText.indexOf('wedding') === 0;
            }

            if (isBilling) {
                $billingCol = $col;
                $col.addClass('zs-billing-col');
            } else if (isShipping) {
                $shippingCol = $col;
                $col.addClass('zs-shipping-col');
            }
        });
        
        if ($billingCol && $billingCol.length) {
            // Add Client Details Section Title
            var $firstNameField = $billingCol.find('._billing_first_name_field');
            if ($firstNameField.length && $firstNameField.prev('.zs-section-title').length === 0) {
                $firstNameField.before('<div class="zs-section-title">Client Details</div>');
            }

            // Add Payment Section Title
            var $paymentMethodField = $billingCol.find('._billing_payment_method_field, .payment_method_field, select[name="_payment_method"]').closest('.form-field');
            if ($paymentMethodField.length === 0) {
                // If standard field not found, try to find the label
                $paymentMethodField = $billingCol.find('label:contains("Payment"), label:contains("Pago")').closest('.form-field');
            }
            
            if ($paymentMethodField.length && $paymentMethodField.prev('.zs-section-title').length === 0) {
                $paymentMethodField.before('<div class="zs-section-title">Payment & Transactions</div>');
            }
        }
        
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
            
            // Find the Customer Note field by its label and move it OUT of the shipping column
            var $noteLabels = $shippingCol.find('label').filter(function() {
                var text = $(this).text().toLowerCase();
                return text.indexOf('note') > -1 || text.indexOf('nota') > -1;
            });
            
            if ($noteLabels.length) {
                var $noteWrapper = $noteLabels.closest('.form-field');
                
                // Only move it if it's still inside the shipping column
                if ($noteWrapper.parents('.zs-shipping-col').length > 0) {
                    $noteWrapper.addClass('zs-customer-note-block');
                    
                    // If it doesn't have an h3, add one
                    if ($noteWrapper.find('h3').length === 0) {
                        $noteLabels.hide(); // Hide the normal label
                        $noteWrapper.prepend('<h3 class="zs-note-header">' + $noteLabels.text() + '</h3>');
                    }
                    
                    // Move it to the bottom of the container, OUTSIDE the columns
                    $('#order_data .order_data_column_container').append($noteWrapper);
                }
            }
        }
    }

    refineOrderDataLayout();
    
    // Wait for WooCommerce scripts that might alter DOM
    setTimeout(refineOrderDataLayout, 500);
});
