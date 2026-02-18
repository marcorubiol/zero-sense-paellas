(function ($) {
    $(document).on('click', '.zs-dismiss-alert', function (e) {
        e.preventDefault();
        var $badge = $(this).closest('.zs-alert-badge');
        var orderId = $badge.data('order');
        var materialKey = $badge.data('material');
        $badge.css('opacity', 0.4);
        $.post(zsAlerts.ajaxurl, {
            action: 'zs_dismiss_inventory_alert',
            nonce: zsAlerts.nonce,
            order_id: orderId,
            material_key: materialKey
        }, function (res) {
            if (res.success) {
                var $row = $badge.closest('tr');
                $badge.remove();
                if ($row.find('.zs-alert-badge').length === 0) {
                    $row.fadeOut(200, function () { $(this).remove(); });
                }
            } else {
                $badge.css('opacity', 1);
            }
        });
    });
})(jQuery);
