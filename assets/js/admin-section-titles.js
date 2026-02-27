jQuery(document).ready(function ($) {
    function modifyTitles() {
        $('#order_data .order_data_column h3').each(function () {
            var elem = $(this);
            // Skip our injected note header
            if (elem.hasClass('zs-note-header')) return;
            
            var text = elem.text().trim();
            
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
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }

    modifyTitles();
    setTimeout(modifyTitles, 100);
    setTimeout(modifyTitles, 500);
    setTimeout(modifyTitles, 1000);
});
