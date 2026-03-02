(function ($) {
    if (window.wp_debug) console.log('[ZS Alerts] Script loaded. zsAlerts:', typeof zsAlerts !== 'undefined' ? zsAlerts : 'NOT DEFINED');

    $(document).on('click', '.zs-dismiss-alert', function (e) {
        e.preventDefault();
        if (window.wp_debug) console.log('[ZS Alerts] Dismiss clicked');

        var $badge = $(this).closest('.zs-alert-badge');
        var orderId = $badge.data('order');
        var materialKey = $badge.data('material');

        if (window.wp_debug) console.log('[ZS Alerts] order_id:', orderId, '| material_key:', materialKey);
        if (window.wp_debug) console.log('[ZS Alerts] Posting to:', zsAlerts.ajaxUrl);

        $badge.css('opacity', 0.4);
        $.post(zsAlerts.ajaxUrl, {
            action: 'zs_dismiss_inventory_alert',
            nonce: zsAlerts.nonce,
            order_id: orderId,
            material_key: materialKey
        }, function (res) {
            if (window.wp_debug) console.log('[ZS Alerts] AJAX response:', res);
            if (res.success) {
                var $row = $badge.closest('tr');
                $badge.remove();
                if ($row.find('.zs-alert-badge').length === 0) {
                    $row.fadeOut(200, function () { $(this).remove(); });
                }
            } else {
                console.warn('[ZS Alerts] Error response:', res);
                $badge.css('opacity', 1);
            }
        }).fail(function (xhr, status, error) {
            console.error('[ZS Alerts] AJAX failed:', status, error, xhr.responseText);
            $badge.css('opacity', 1);
        });
    });
})(jQuery);
