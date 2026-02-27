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
                isShipping = headerText.indexOf('shipping') === 0 || headerText.indexOf('envío') === 0 || headerText.indexOf('wedding') === 0 || headerText.indexOf('in-situ') === 0;
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

            // View Mode: Handle collapsed view for Billing
            var $billingAddressView = $billingCol.find('.address');
            if ($billingAddressView.length && !$billingAddressView.data('zs-modified')) {
                $billingAddressView.data('zs-modified', true);
                
                var html = $billingAddressView.html();
                var $temp = $('<div>').html(html);
                
                $temp.prepend('<div class="zs-section-title" style="margin-top:0;">Client Details</div>');
                
                var nodes = $temp.children();
                nodes.each(function() {
                    var $node = $(this);
                    var text = $node.text().toLowerCase();
                    // Inject Payment title before anything related to email or phone (since they come after address in WC but before payment usually)
                    // Wait, WooCommerce default view shows: Address, Email, Phone, Payment via...
                    if (text.indexOf('payment') > -1 || text.indexOf('pago') > -1 || text.indexOf('transaction') > -1) {
                        $node.before('<div class="zs-section-title">Payment & Transactions</div>');
                    }
                });
                
                $billingAddressView.html($temp.html());
            }
        }
        
        if ($shippingCol && $shippingCol.length) {
            // Edit Mode: Add Contact Section Title
            var $contactField = $shippingCol.find('.zs-contact-block-start');
            if ($contactField.length && $contactField.prev('.zs-section-title').length === 0) {
                $contactField.before('<div class="zs-section-title">Contact Person (In-situ)</div>');
            }

            // Edit Mode: Add Venue Section Title
            var $venueField = $shippingCol.find('.zs-venue-block-start');
            if ($venueField.length && $venueField.prev('.zs-section-title').length === 0) {
                $venueField.before('<div class="zs-section-title">Venue & Location</div>');
            }

            // View Mode: Handle collapsed view
            var $shippingAddressView = $shippingCol.find('.address');
            if ($shippingAddressView.length && !$shippingAddressView.data('zs-modified')) {
                $shippingAddressView.data('zs-modified', true);
                
                // Keep original content but structure it with titles
                var html = $shippingAddressView.html();
                
                // Find where the address text starts vs email/phone links
                // WC puts email/phone inside <p> elements
                var $temp = $('<div>').html(html);
                
                // Start with Contact title
                $temp.prepend('<div class="zs-section-title" style="margin-top:0;">Contact Person (In-situ)</div>');
                
                // Assuming standard WC structure: First p is address, subsequent ps are phone/email
                // But we modified fields order via PHP, so the first lines might actually be Contact Person info
                
                // Let's insert the Venue title before the address part (which typically has <br> tags)
                // Fallback: just append titles visually if we can't parse easily
                var nodes = $temp.children();
                var foundVenue = false;
                
                nodes.each(function() {
                    var $node = $(this);
                    var text = $node.text().trim();
                    // Basic heuristic: if it looks like an address line or location link, it belongs to Venue
                    if (!foundVenue && (text.indexOf('Venue Name') > -1 || text.indexOf('Venue Phone') > -1 || text.indexOf('Location Link') > -1 || $node.find('br').length > 1)) {
                        $node.before('<div class="zs-section-title">Venue & Location</div>');
                        foundVenue = true;
                    }
                });
                
                // If we didn't find a good split point, just add it before the first <p> that has a <br>
                if (!foundVenue) {
                    var $addrP = $temp.find('p').filter(function() { return $(this).find('br').length > 0; }).first();
                    if ($addrP.length) {
                        $addrP.before('<div class="zs-section-title">Venue & Location</div>');
                    }
                }
                
                $shippingAddressView.html($temp.html());
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
