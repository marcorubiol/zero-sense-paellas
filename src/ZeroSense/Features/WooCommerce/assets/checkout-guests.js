(function() {
    'use strict';

    var TOTAL_ID   = 'zs_event_total_guests';
    var ADULTS_ID  = 'zs_event_adults';
    var CH58_ID    = 'zs_event_children_5_to_8';
    var CH04_ID    = 'zs_event_children_0_to_4';
    var ERROR_ID   = 'zs-guests-mismatch';

    function getVal(id) {
        var el = document.getElementById(id);
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    function validate() {
        var total   = getVal(TOTAL_ID);
        var adults  = getVal(ADULTS_ID);
        var ch58    = getVal(CH58_ID);
        var ch04    = getVal(CH04_ID);
        var sum     = adults + ch58 + ch04;
        var existing = document.getElementById(ERROR_ID);

        if (total > 0 && sum !== total) {
            if (!existing) {
                var msg = document.createElement('span');
                msg.id = ERROR_ID;
                msg.style.cssText = 'display:block;color:#c00;font-size:0.85em;margin-top:4px;';
                msg.textContent = zsGuests.msg;
                var field = document.getElementById(TOTAL_ID);
                if (field && field.parentNode) {
                    field.parentNode.appendChild(msg);
                }
            }
            return false;
        } else {
            if (existing) {
                existing.parentNode.removeChild(existing);
            }
            return true;
        }
    }

    function attachListeners() {
        [TOTAL_ID, ADULTS_ID, CH58_ID, CH04_ID].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', validate);
                el.addEventListener('change', validate);
            }
        });
    }

    function onSubmit(e) {
        if (!validate()) {
            e.preventDefault();
            e.stopImmediatePropagation();
            var field = document.getElementById(TOTAL_ID);
            if (field) {
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                field.focus();
            }
        }
    }

    function init() {
        attachListeners();

        var form = document.querySelector('form.checkout, form.woocommerce-checkout');
        if (form) {
            form.addEventListener('submit', onSubmit);
        }

        // WooCommerce blocks checkout
        document.addEventListener('submit', function(e) {
            if (e.target && e.target.classList.contains('wc-block-checkout__form')) {
                onSubmit(e);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
