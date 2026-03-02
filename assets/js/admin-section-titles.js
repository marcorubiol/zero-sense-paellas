jQuery(document).ready(function ($) {
    /**
     * Count missing required fields in a column
     */
    function countMissingRequired(column) {
        var count = 0;
        var requiredSelectors = [
            'input[name="_billing_first_name"]',
            'input[name="_billing_email"]'
        ];
        
        requiredSelectors.forEach(function(selector) {
            var field = column.find(selector);
            if (field.length && !field.val().trim()) {
                count++;
            }
        });
        
        return count;
    }
    
    /**
     * Update required fields notice
     */
    function updateRequiredNotice(elem, column, type) {
        var existingNotice = elem.siblings('.zs-required-notice');
        var missingCount = countMissingRequired(column);
        
        if (missingCount > 0) {
            var msg = missingCount === 1 
                ? '⚠️ 1 required field missing' 
                : '⚠️ ' + missingCount + ' required fields missing';
            
            if (existingNotice.length) {
                existingNotice.text(msg);
            } else {
                var badge = elem.siblings('.zs-subtitle-' + type);
                if (badge.length) {
                    badge.after('<div class="zs-required-notice">' + msg + '</div>');
                }
            }
        } else {
            existingNotice.remove();
        }
    }
    
    function modifyTitles() {
        $('#order_data .order_data_column h3').each(function () {
            var elem = $(this);
            // Skip our injected note header
            if (elem.hasClass('zs-note-header')) return;
            
            var text = elem.text().trim();
            var column = elem.closest('.order_data_column');
            
            // For Shipping
            if (text.indexOf('Shipping') === 0 || text.indexOf('Envío') === 0 || text.indexOf('In-Situ') === 0) {
                if (!elem.data('zs-modified')) {
                    var textNodes = elem.contents().filter(function() {
                        return this.nodeType === 3 && this.textContent.trim().length > 0;
                    });
                    if (textNodes.length) {
                        textNodes[0].textContent = 'In-Situ Contact (WP) & Venue Details ';
                    }
                    elem.data('zs-modified', true);
                    elem.parent().css('position', 'relative');
                }
                if (elem.siblings('.zs-subtitle-shipping').length === 0) {
                    elem.after('<span class="zs-badge zs-badge-status zs-subtitle-shipping">Shipping</span>');
                }
            }
            // For Billing
            else if (text.indexOf('Billing') === 0 || text.indexOf('Facturación') === 0 || text.indexOf('Client') === 0) {
                if (!elem.data('zs-modified')) {
                    var textNodes = elem.contents().filter(function() {
                        return this.nodeType === 3 && this.textContent.trim().length > 0;
                    });
                    if (textNodes.length) {
                        textNodes[0].textContent = 'Client ';
                    }
                    elem.data('zs-modified', true);
                    elem.parent().css('position', 'relative');
                }
                if (elem.siblings('.zs-subtitle-billing').length === 0) {
                    elem.after('<span class="zs-badge zs-badge-auto zs-subtitle-billing">Billing</span>');
                }
                
                // Check and add required fields notice
                updateRequiredNotice(elem, column, 'billing');
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }
    
    /**
     * Monitor field changes to update the notice dynamically
     */
    function bindFieldMonitoring() {
        $(document).on('input change', 'input[name="_billing_first_name"], input[name="_billing_email"]', function() {
            var column = $(this).closest('.order_data_column');
            var h3 = column.find('h3').first();
            updateRequiredNotice(h3, column, 'billing');
        });
    }

    modifyTitles();
    bindFieldMonitoring();
    setTimeout(modifyTitles, 100);
    setTimeout(modifyTitles, 500);
    setTimeout(modifyTitles, 1000);
});
