jQuery(document).ready(function ($) {
    function addSubtitles() {
        $('#order_data h3').each(function () {
            var elem = $(this);

            if (elem.data('zs-processed')) {
                return;
            }

            var firstWord = elem.contents().first().text().trim().split(/\s+/)[0];

            if (firstWord === 'Billing' || firstWord === 'Facturación') {
                elem.text('Client');
                elem.append('<span class="zs-wc-tag">Billing</span>');
                elem.data('zs-processed', true);
            } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                elem.text('Wedding Planner - Venue');
                elem.append('<span class="zs-wc-tag">Shipping</span>');
                elem.data('zs-processed', true);
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }

    addSubtitles();
    setTimeout(addSubtitles, 100);
});
