jQuery(document).ready(function ($) {
    function addSubtitles() {
        $('#order_data h3').each(function () {
            var elem = $(this);

            if (elem.next('.zs-subtitle').length) {
                return;
            }

            var firstWord = elem.contents().first().text().trim().split(/\s+/)[0];
            var editLink = elem.find('a.edit_address');
            var editHtml = editLink.length ? editLink[0].outerHTML : '';

            if (firstWord === 'Billing' || firstWord === 'Facturación') {
                if (editLink.length) editLink.remove();
                elem.after('<div class="zs-subtitle zs-subtitle-client">Client' + (editHtml ? '<span class="zs-subtitle-edit">' + editHtml + '</span>' : '') + '</div>');
            } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                if (editLink.length) editLink.remove();
                elem.after('<div class="zs-subtitle zs-subtitle-venue">Wedding Planner - Venue' + (editHtml ? '<span class="zs-subtitle-edit">' + editHtml + '</span>' : '') + '</div>');
            }
        });

        $('#order_data h3').css('visibility', 'visible');
    }

    addSubtitles();
    setTimeout(addSubtitles, 100);
});
