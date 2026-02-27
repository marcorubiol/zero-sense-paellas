jQuery(document).ready(function ($) {
    function modifyTitles() {
        $('#order_data h3').each(function () {
            var elem = $(this);
            var firstWord = elem.contents().first().text().trim().split(/\s+/)[0];

            if (firstWord === 'Billing' || firstWord === 'Facturación') {
                if (!elem.data('zs-modified')) {
                    elem.text('Client');
                    elem.data('zs-modified', true);
                    elem.parent().css('position', 'relative');
                    elem.after('<span class="zs-badge zs-badge-autozs-subtitle-billing">Billing</span>');
                }
            } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                if (!elem.data('zs-modified')) {
                    elem.text('Wedding Planner - Venue');
                    elem.data('zs-modified', true);
                    elem.parent().css('position', 'relative');
                    elem.after('<span class="zs-badge zs-badge-auto zs-subtitle-shipping">Shipping</span>');
                }
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }

    modifyTitles();
    setTimeout(modifyTitles, 100);
});
