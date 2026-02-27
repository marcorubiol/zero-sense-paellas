jQuery(document).ready(function ($) {
    function addSubtitles() {
        $('#order_data h3').each(function () {
            var elem = $(this);

            if (elem.next('.zs-subtitle').length) {
                return;
            }

            var firstWord = elem.contents().first().text().trim().split(/\s+/)[0];

            if (firstWord === 'Billing' || firstWord === 'Facturación') {
                elem.after('<div class="zs-subtitle zs-subtitle-client" data-wc-label="Billing">Client</div>');
            } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                elem.after('<div class="zs-subtitle zs-subtitle-venue" data-wc-label="Shipping">Wedding Planner - Venue</div>');
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }

    addSubtitles();
    setTimeout(addSubtitles, 100);
});
