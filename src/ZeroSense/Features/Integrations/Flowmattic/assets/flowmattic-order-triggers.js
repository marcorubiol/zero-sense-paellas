(function($){
    'use strict';

    function getData(){
        return window.ZeroSenseFlowmattic || {};
    }

    $(document).ready(function(){
        var config = getData();
        var $buttons = $('.zero-sense-trigger-flowmattic');

        $buttons.each(function(){
            var $btn = $(this);
            if (!$btn.data('original-text')) {
                $btn.data('original-text', $btn.text());
            }
        });

        $buttons.on('click', function(e){
            e.preventDefault();

            var config = getData();
            var $btn = $(this);
            var actionKey = $btn.data('action');
            var orderId = $btn.data('order-id');

            if (!config.ajaxUrl || !config.nonce) {
                return;
            }

            if (!window.confirm(config.i18n ? config.i18n.confirm : 'Send Flowmattic workflow?')) {
                return;
            }

            $btn.prop('disabled', true).text(config.i18n ? config.i18n.sending : 'Sending...');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'zero_sense_trigger_flowmattic_manual',
                    security: config.nonce,
                    action_key: actionKey,
                    order_id: orderId
                }
            }).done(function(response){
                if (response && response.success) {
                    window.alert(config.i18n ? config.i18n.success : 'Workflow triggered.');
                } else {
                    var message = response && response.data ? response.data : null;
                    window.alert(message || (config.i18n ? config.i18n.error : 'Could not trigger workflow.'));
                }
            }).fail(function(){
                window.alert(config.i18n ? config.i18n.error : 'Could not trigger workflow.');
            }).always(function(){
                var original = $btn.data('original-text') || $btn.text();
                $btn.prop('disabled', false).text(original);
            });
        });
    });
})(jQuery);
