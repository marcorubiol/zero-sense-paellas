jQuery(document).ready(function ($) {
    function modifyTitles() {
        $('#order_data h3').each(function () {
            var elem = $(this);
            var fullText = elem.contents().first().text().trim();
            var firstWord = fullText.split(/\s+/)[0];

            // Check if it's already been modified (Client/Wedding Planner - Venue)
            if (firstWord === 'Client' || firstWord === 'Wedding') {
                // Already modified, just ensure badge exists
                if (elem.next('.zs-subtitle-billing, .zs-subtitle-shipping').length === 0) {
                    if (firstWord === 'Client') {
                        elem.after('<span class="zs-badge zs-badge-auto zs-subtitle-billing">Billing</span>');
                    } else {
                        elem.after('<span class="zs-badge zs-badge-auto zs-subtitle-shipping">Shipping</span>');
                    }
                    elem.parent().css('position', 'relative');
                }
            } 
            // Original WooCommerce titles (Billing/Shipping) - modify them
            else if (firstWord === 'Billing' || firstWord === 'Facturación') {
                if (!elem.data('zs-modified')) {
                    // Replace only the text node, preserving the edit button
                    elem.contents().filter(function() {
                        return this.nodeType === 3 && this.textContent.trim().length > 0;
                    }).first().get(0).textContent = 'Client ';
                    
                    elem.data('zs-modified', true);
                    elem.parent().css('position', 'relative');
                    elem.after('<span class="zs-badge zs-badge-auto zs-subtitle-billing">Billing</span>');
                }
            } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                if (!elem.data('zs-modified')) {
                    // Replace only the text node, preserving the edit button
                    elem.contents().filter(function() {
                        return this.nodeType === 3 && this.textContent.trim().length > 0;
                    }).first().get(0).textContent = 'Wedding Planner & Venue ';
                    
                    elem.data('zs-modified', true);
                    elem.parent().css('position', 'relative');
                    elem.after('<span class="zs-badge zs-badge-status zs-subtitle-shipping">Shipping</span>');
                }
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }

    modifyTitles();
    setTimeout(modifyTitles, 100);
});
