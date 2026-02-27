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
                
                var clientHtml = '<div class="zs-section-title" style="margin-top:0;">Client Details</div>';
                clientHtml += '<p>';
                var bName = ($('#_billing_first_name').val() + ' ' + $('#_billing_last_name').val()).trim();
                if (bName) clientHtml += bName + '<br>';
                var bCompany = $('#_billing_company').val();
                if (bCompany) clientHtml += bCompany + '<br>';
                
                var bAddr1 = $('#_billing_address_1').val();
                var bAddr2 = $('#_billing_address_2').val();
                var bCity = $('#_billing_city').val();
                var bPostcode = $('#_billing_postcode').val();
                var bCountry = $('#_billing_country option:selected').text();
                var bState = $('#_billing_state option:selected').text();
                
                var bAddrArr = [bAddr1, bAddr2, bCity, bPostcode].filter(function(v) { return v && v.trim() !== ''; });
                if (bAddrArr.length) clientHtml += bAddrArr.join(', ') + '<br>';
                
                var bEmail = $('#_billing_email').val();
                if (bEmail) clientHtml += '<strong>Email address:</strong> <a href="mailto:' + bEmail + '">' + bEmail + '</a><br>';
                var bPhone = $('#_billing_phone').val();
                if (bPhone) clientHtml += '<strong>Phone:</strong> <a href="tel:' + bPhone + '">' + bPhone + '</a><br>';
                clientHtml += '</p>';

                var paymentHtml = '<div class="zs-section-title">Payment & Transactions</div>';
                paymentHtml += '<p>';
                var payMethod = $('#_payment_method option:selected').text() || $('#_payment_method').val() || $billingCol.find('._billing_payment_method_field input, .payment_method_field input').val();
                if (payMethod && payMethod.trim() !== '') {
                    paymentHtml += '<strong>Payment method:</strong> ' + payMethod + '<br>';
                } else {
                    // Try to extract from original view if not found in inputs
                    var origHtml = $billingAddressView.html();
                    if (origHtml.toLowerCase().indexOf('payment via') > -1) {
                        var pmMatch = origHtml.match(/Payment via ([^<]+)/i);
                        if (pmMatch && pmMatch[1]) paymentHtml += '<strong>Payment method:</strong> ' + pmMatch[1].trim() + '<br>';
                    }
                }
                
                var transId = $('#_transaction_id').val();
                if (transId) paymentHtml += '<strong>Transaction ID:</strong> ' + transId + '<br>';
                paymentHtml += '</p>';
                
                if (paymentHtml.indexOf('<strong>') === -1) {
                    paymentHtml = '<div class="zs-section-title">Payment & Transactions</div><p><em>Not provided</em></p>';
                }

                $billingAddressView.html(clientHtml + paymentHtml);
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

            // View Mode: Rebuild custom structured view to match edit mode exactly
            var $shippingAddressView = $shippingCol.find('.address');
            if ($shippingAddressView.length && !$shippingAddressView.data('zs-modified')) {
                $shippingAddressView.data('zs-modified', true);
                
                var contactHtml = '<div class="zs-section-title" style="margin-top:0;">Contact Person (In-situ)</div>';
                contactHtml += '<p>';
                var sName = ($('#_shipping_first_name').val() + ' ' + $('#_shipping_last_name').val()).trim();
                if (sName) contactHtml += sName + '<br>';
                var sCompany = $('#_shipping_company').val();
                if (sCompany) contactHtml += sCompany + '<br>';
                var sEmail = $('#_shipping_email').val();
                if (sEmail) contactHtml += '<strong>Contact Email:</strong> <a href="mailto:' + sEmail + '">' + sEmail + '</a><br>';
                var sPhone = $('#_shipping_phone').val();
                if (sPhone) contactHtml += '<strong>Contact Phone:</strong> <a href="tel:' + sPhone + '">' + sPhone + '</a><br>';
                contactHtml += '</p>';
                
                if (contactHtml === '<div class="zs-section-title" style="margin-top:0;">Contact Person (In-situ)</div><p></p>') {
                    contactHtml = '<div class="zs-section-title" style="margin-top:0;">Contact Person (In-situ)</div><p><em>Not provided</em></p>';
                }

                var venueHtml = '<div class="zs-section-title">Venue & Location</div>';
                venueHtml += '<p>';
                var vName = $('#_shipping_venue_name').val();
                if (vName) venueHtml += '<strong>Venue Name:</strong> ' + vName + '<br>';
                var vPhone = $('#_shipping_venue_phone').val();
                if (vPhone) venueHtml += '<strong>Venue Phone:</strong> <a href="tel:' + vPhone + '">' + vPhone + '</a><br>';
                
                var sAddr1 = $('#_shipping_address_1').val();
                var sAddr2 = $('#_shipping_address_2').val();
                var sCity = $('#_shipping_city').val();
                var sPostcode = $('#_shipping_postcode').val();
                var sCountry = $('#_shipping_country option:selected').text();
                var sState = $('#_shipping_state option:selected').text();
                
                var sAddrArr = [sAddr1, sAddr2, sCity, sPostcode].filter(function(v) { return v && v.trim() !== ''; });
                if (sAddrArr.length) venueHtml += sAddrArr.join(', ') + '<br>';
                
                var sLink = $('#_shipping_location_link').val();
                if (sLink) venueHtml += '<strong>Location Link:</strong> <a href="' + sLink + '" target="_blank">View Map</a>';
                venueHtml += '</p>';

                if (venueHtml === '<div class="zs-section-title">Venue & Location</div><p></p>') {
                    venueHtml = '<div class="zs-section-title">Venue & Location</div><p><em>Not provided</em></p>';
                }
                
                $shippingAddressView.html(contactHtml + venueHtml);
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
