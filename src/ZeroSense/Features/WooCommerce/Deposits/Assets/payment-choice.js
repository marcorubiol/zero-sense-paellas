(function(){
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    function decodeHtml(value) {
        if (!value) {
            return '';
        }
        var textarea = document.createElement('textarea');
        textarea.innerHTML = value;
        return textarea.value;
    }

    function findFormattedPrice(radio) {
        if (!radio) {
            return '';
        }

        var fromAttr = radio.getAttribute('data-price-html');
        if (fromAttr) {
            return decodeHtml(fromAttr);
        }

        var label = radio.closest('label, .payment-options__label');
        if (!label) {
            return '';
        }

        var amount = label.querySelector('.wd-price-amount, .payment-options__amount, .payment-options__remaining');
        if (amount) {
            return amount.innerHTML;
        }

        return label.innerHTML;
    }

    function updateSummary(container, htmlAmount) {
        if (!container || !htmlAmount) {
            return;
        }

        var summaryValue = container.querySelector('#zs-deposits-summary-price-value');
        if (summaryValue) {
            summaryValue.innerHTML = htmlAmount;
        }

        var summaryStrong = container.querySelector('.payment-summary__amount strong');
        if (summaryStrong) {
            summaryStrong.innerHTML = htmlAmount;
        }

        var selectors = (window.zsDepositsPaymentChoice && Array.isArray(window.zsDepositsPaymentChoice.selectors)) ? window.zsDepositsPaymentChoice.selectors : [];
        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(node) {
                node.innerHTML = htmlAmount;
            });
        });
    }

    function updateHiddenInputs(choice) {
        if (!choice) {
            return;
        }

        document.querySelectorAll('#zs_deposits_payment_choice_submit, input[name="zs_deposits_payment_choice_submit"]').forEach(function(input) {
            input.value = choice;
        });
    }

    function persistChoice(choice) {
        try { window.sessionStorage.setItem('zs_deposits_payment_choice', choice); } catch (e) {}
    }

    function restoreChoice(radios) {
        if (!radios || !radios.length) {
            return null;
        }

        var stored = null;
        try { stored = window.sessionStorage.getItem('zs_deposits_payment_choice'); } catch (e) {}

        if (!stored) {
            return null;
        }

        for (var i = 0; i < radios.length; i += 1) {
            if (radios[i].value === stored) {
                return radios[i];
            }
        }
        return null;
    }

    function sendOrderPayUpdate(choice) {
        var vars = window.zsDepositsDepositVars || {};
        if (!choice || !vars.ajax_url || !vars.orderId) {
            return;
        }

        var payload = new URLSearchParams();
        payload.set('action', 'zs_deposits_update_order_pay_choice_session');
        payload.set('choice', choice);
        payload.set('order_id', vars.orderId);
        payload.set('security', vars.orderPayNonce || '');

        window.fetch(vars.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload.toString()
        }).catch(function() {
            /* no-op */
        });
    }

    ready(function() {
        var containers = document.querySelectorAll('#zs-deposits-payment-options');
        if (!containers.length) {
            return;
        }

        var body = document.body;
        var isOrderPay = body.classList.contains('woocommerce-order-pay');

        containers.forEach(function(container) {
            var radios = container.querySelectorAll('input[name="zs_deposits_payment_choice"]');
            if (!radios.length) {
                return;
            }

            var initial = restoreChoice(radios) || container.querySelector('input[name="zs_deposits_payment_choice"]:checked') || radios[0];
            if (initial) {
                initial.checked = true;
                updateHiddenInputs(initial.value);
                updateSummary(container, findFormattedPrice(initial));
                persistChoice(initial.value);
            }

            radios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (!radio.checked) {
                        return;
                    }

                    var amountHtml = findFormattedPrice(radio);
                    updateHiddenInputs(radio.value);
                    updateSummary(container, amountHtml);
                    persistChoice(radio.value);

                    if (!isOrderPay && window.jQuery && window.jQuery(document.body).trigger) {
                        window.jQuery(document.body).trigger('update_checkout');
                    }

                    if (isOrderPay) {
                        sendOrderPayUpdate(radio.value);
                    }

                    body.dispatchEvent(new CustomEvent('zs-deposits-payment-choice-change', {
                        detail: {
                            choice: radio.value,
                            amountHtml: amountHtml
                        }
                    }));

                    // v3 event only
                });
            });
        });
    });
})();
