(function() {
    'use strict';

    var TOTAL_ID = 'zs_event_total_guests';
    var ADULTS_ID = 'zs_event_adults';
    var CH58_ID  = 'zs_event_children_5_to_8';
    var CH04_ID  = 'zs_event_children_0_to_4';
    var MSG_ID   = 'zs-guests-hint';

    function getVal(id) {
        var el = document.getElementById(id);
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    function getOrCreateMsg() {
        var msg = document.getElementById(MSG_ID);
        if (!msg) {
            msg = document.createElement('span');
            msg.id = MSG_ID;
            msg.style.cssText = 'display:block;font-size:0.85em;margin-top:4px;';
            var field = document.getElementById(TOTAL_ID);
            if (field && field.parentNode) {
                field.parentNode.appendChild(msg);
            }
        }
        return msg;
    }

    function update() {
        var total  = getVal(TOTAL_ID);
        var adults = getVal(ADULTS_ID);
        var ch58   = getVal(CH58_ID);
        var ch04   = getVal(CH04_ID);
        var sum    = adults + ch58 + ch04;
        var msg    = getOrCreateMsg();

        if (!msg) return;

        msg.textContent = zsGuests.msg;

        if (total > 0 && sum !== total) {
            msg.style.color = '#c00';
        } else {
            msg.style.color = '#999';
        }
    }

    function init() {
        [TOTAL_ID, ADULTS_ID, CH58_ID, CH04_ID].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', update);
                el.addEventListener('change', update);
            }
        });
        update();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
