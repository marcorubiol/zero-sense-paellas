(function () {
    'use strict';

    var cfg     = window.zsShoppingList || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce   = cfg.nonce   || '';

    var body    = document.getElementById('zs-sl-body');
    var loading = document.getElementById('zs-sl-loading');

    function getInputValue(id) {
        var el = document.getElementById(id);
        if (!el) { return ''; }
        return el._flatpickr ? el.value : el.value;
    }

    function getFormValues() {
        return {
            from: getInputValue('zs-sl-from'),
            to:   getInputValue('zs-sl-to'),
            loc:  document.getElementById('zs-sl-loc') ? document.getElementById('zs-sl-loc').value : '',
        };
    }

    function getCheckedKeys() {
        var boxes = document.querySelectorAll('.zs-sl__item-check:checked');
        return Array.from(boxes).map(function (el) { return el.value; });
    }

    function formatQty(qty) {
        var s = parseFloat(qty).toFixed(1);
        return s.replace(/\.0$/, '');
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function renderOrders(orders, selectedKeys) {
        if (!orders || orders.length === 0) {
            return '<div class="zs-sl__no-orders"><p>No hi ha comandes per a aquest període i localització.</p></div>';
        }
        var html = '<div class="zs-sl__list-actions no-print">';
        html += '<button type="button" class="btn--neutral" id="zs-sl-print">Imprimir</button>';
        html += '<button type="button" class="btn--neutral btn--outline" id="zs-sl-share">Copiar enllaç</button>';
        html += '</div>';
        html += '<div class="zs-sl__orders no-print" id="zs-sl-orders">';
        html += '<div class="zs-sl__orders-actions">';
        html += '<button type="button" id="zs-sl-check-all">Seleccionar tot</button>';
        html += '<button type="button" id="zs-sl-uncheck-all">Desseleccionar tot</button>';
        html += '</div>';
        html += '<div class="zs-sl__orders-list" id="zs-sl-orders-list">';
        orders.forEach(function (o) {
            var oid = String(o.id);
            html += '<div class="zs-sl__order-item" data-order-id="' + esc(oid) + '">';
            html += '<div class="zs-sl__order-row1">';
            var orderItems = o.items || [];
            var orderKeys = orderItems.map(function (i) { return i.key; });
            var anySelected = !selectedKeys || orderKeys.some(function (k) { return selectedKeys.indexOf(k) !== -1; });
            html += '<label class="zs-sl__switch"><input type="checkbox" class="zs-sl__order-toggle" data-order-id="' + esc(oid) + '"' + (anySelected ? ' checked' : '') + '><span class="zs-sl__switch-track"></span></label>';
            html += '<span class="zs-sl__order-num">#' + esc(o.number) + '</span>';
            html += '<span class="zs-sl__order-customer">' + esc(o.customer) + '</span>';
            html += '<span class="zs-sl__order-date">' + esc(o.date) + '</span>';
            html += '<span class="zs-sl__order-guests">' + esc(o.guests) + ' pax</span>';
            html += '</div>';
            html += '<div class="zs-sl__order-row2">';
            orderItems.forEach(function (item) {
                var eqStr = item.eq > 0 ? ' <span class="zs-sl__item-eq">· ' + esc(item.eq) + ' rac. eq.</span>' : '';
                var labelText = esc(item.name) + (item.qty > 1 ? ' ×' + esc(item.qty) : '') + eqStr;
                var isChecked = !selectedKeys || selectedKeys.indexOf(item.key) !== -1;
                html += '<label class="zs-sl__item-check-label">';
                html += '<span class="zs-sl__switch"><input type="checkbox" class="zs-sl__item-check" value="' + esc(item.key) + '" data-order-id="' + esc(oid) + '"' + (isChecked ? ' checked' : '') + '><span class="zs-sl__switch-track"></span></span>';
                html += '<span>' + labelText + '</span>';
                html += '</label>';
            });
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';
        html += '</div>';
        return html;
    }

    function renderList(list, totals) {
        if (!list || list.length === 0) {
            return '<div class="zs-sl__list-empty"><p>No hi ha ingredients per a les comandes seleccionades.</p></div>';
        }
        list = list.slice().sort(function (a, b) { return a.name.localeCompare(b.name); });
        var icon = '<svg class="zs-sl__list-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
        var eqBadge = (totals && totals.eq > 0) ? '<span class="zs-sl__eq-badge">' + esc(totals.eq) + ' rac. eq.</span>' : '';
        var html = '<div class="zs-sl__list print-only" id="zs-sl-list">';
        html += '<div class="zs-sl__list-header"><h3 class="zs-sl__list-title">Llista de la compra</h3><div class="zs-sl__list-meta">' + eqBadge + icon + '</div></div>';
        html += '<div class="zs-sl__list-items">';
        list.forEach(function (item) {
            html += '<div class="zs-sl__list-item">';
            html += '<span class="zs-sl__item-name">' + esc(item.name) + '</span>';
            // For c/n (cantidad necesaria), show only the unit without any quantity
            if (item.unit === 'c/n') {
                html += '<span class="zs-sl__item-qty-wrap"><span class="zs-sl__item-unit">' + esc(item.unit) + '</span></span>';
            } else {
                html += '<span class="zs-sl__item-qty-wrap"><span class="zs-sl__item-qty">' + formatQty(item.qty) + '</span><span class="zs-sl__item-unit">' + esc(item.unit) + '</span></span>';
            }
            html += '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function zsPrint() {
        var list = document.querySelector('.zs-sl__list');
        if (!list) { return; }
        var target = document.createElement('div');
        target.id = 'zs-sl-print-target';

        var fromVal = document.getElementById('zs-sl-from') ? document.getElementById('zs-sl-from').value : '';
        var toVal = document.getElementById('zs-sl-to') ? document.getElementById('zs-sl-to').value : '';
        var locSel = document.getElementById('zs-sl-loc');
        var locText = locSel && locSel.selectedIndex > 0 ? locSel.options[locSel.selectedIndex].text : '';
        if (fromVal || locText) {
            var header = document.createElement('div');
            header.className = 'zs-sl-print-header';
            var parts = [];
            if (fromVal && toVal && fromVal !== toVal) { parts.push(fromVal + ' — ' + toVal); }
            else if (fromVal) { parts.push(fromVal); }
            if (locText) { parts.push(locText); }
            header.textContent = parts.join(' · ');
            target.appendChild(header);
        }

        target.appendChild(list.cloneNode(true));
        document.body.appendChild(target);
        document.body.classList.add('zs-printing');
        window.addEventListener('afterprint', function handler() {
            document.body.classList.remove('zs-printing');
            target.remove();
            window.removeEventListener('afterprint', handler);
        });
        window.print();
    }

    var currentSignedUrl = window.location.href.indexOf('zs_sl_sig') !== -1 ? window.location.href : '';

    function doRequest(orderIds) {
        var vals = getFormValues();
        if (!vals.from || !vals.to || !vals.loc) {
            alert('Selecciona dates i localització.');
            return;
        }

        loading.style.display = 'block';
        body.style.opacity = '0.4';
        body.style.pointerEvents = 'none';

        var data = new FormData();
        data.append('action', 'zs_shopping_list_data');
        data.append('nonce', nonce);
        data.append('from', vals.from);
        data.append('to', vals.to);
        data.append('loc', vals.loc);
        if (orderIds !== null) {
            data.append('item_keys', orderIds.length > 0 ? orderIds.join(',') : '__none__');
        }

        fetch(ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                loading.style.display = 'none';
                body.style.opacity = '';
                body.style.pointerEvents = '';

                if (!res.success) {
                    body.innerHTML = '<div class="zs-sl__error"><p>' + esc(res.data && res.data.message ? res.data.message : 'Error desconegut.') + '</p></div>';
                    return;
                }

                currentSignedUrl = res.data.signed_url || '';
                var orders = res.data.orders;
                var ordersHtml = renderOrders(orders, orderIds);
                var listHtml   = (orders && orders.length > 0) ? renderList(res.data.list, res.data.totals) : '';
                body.innerHTML = ordersHtml + (listHtml ? '<div id="zs-sl-list-wrap">' + listHtml + '</div>' : '');
                bindBodyEvents();
            })
            .catch(function () {
                loading.style.display = 'none';
                body.style.opacity = '';
                body.style.pointerEvents = '';
                body.innerHTML = '<div class="zs-sl__error"><p>Error de connexió.</p></div>';
            });
    }

    function bindBodyEvents() {
        var checkAll   = document.getElementById('zs-sl-check-all');
        var uncheckAll = document.getElementById('zs-sl-uncheck-all');
        var share      = document.getElementById('zs-sl-share');
        function syncItemLabels(scope) {
            (scope || document).querySelectorAll('.zs-sl__item-check').forEach(function (el) {
                var label = el.closest('.zs-sl__item-check-label');
                if (label) { label.classList.toggle('is-unchecked', !el.checked); }
            });
        }
        syncItemLabels();

        var autoUpdateTimer = null;
        function scheduleAutoUpdate() {
            clearTimeout(autoUpdateTimer);
            autoUpdateTimer = setTimeout(function () {
                doRequest(getCheckedKeys());
            }, 600);
        }

        if (checkAll) {
            checkAll.addEventListener('click', function () {
                document.querySelectorAll('.zs-sl__item-check, .zs-sl__order-toggle').forEach(function (el) { el.checked = true; });
                document.querySelectorAll('.zs-sl__order-item').forEach(function (el) { el.classList.remove('is-disabled'); });
                syncItemLabels();
                scheduleAutoUpdate();
            });
        }
        if (uncheckAll) {
            uncheckAll.addEventListener('click', function () {
                document.querySelectorAll('.zs-sl__item-check, .zs-sl__order-toggle').forEach(function (el) { el.checked = false; });
                document.querySelectorAll('.zs-sl__order-item').forEach(function (el) { el.classList.add('is-disabled'); });
                syncItemLabels();
                scheduleAutoUpdate();
            });
        }

        document.querySelectorAll('.zs-sl__order-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var oid = this.getAttribute('data-order-id');
                var orderItem = document.querySelector('.zs-sl__order-item[data-order-id="' + oid + '"]');
                document.querySelectorAll('.zs-sl__item-check[data-order-id="' + oid + '"]').forEach(function (el) {
                    el.checked = toggle.checked;
                });
                syncItemLabels();
                if (orderItem) {
                    orderItem.classList.toggle('is-disabled', !toggle.checked);
                }
                scheduleAutoUpdate();
            });
            var oid = toggle.getAttribute('data-order-id');
            var orderItem = document.querySelector('.zs-sl__order-item[data-order-id="' + oid + '"]');
            if (orderItem && !toggle.checked) {
                orderItem.classList.add('is-disabled');
            }
        });
        document.querySelectorAll('.zs-sl__item-check').forEach(function (item) {
            item.addEventListener('change', function () {
                var label = this.closest('.zs-sl__item-check-label');
                if (label) { label.classList.toggle('is-unchecked', !this.checked); }
                var oid = this.getAttribute('data-order-id');
                var items = document.querySelectorAll('.zs-sl__item-check[data-order-id="' + oid + '"]');
                var anyChecked = Array.from(items).some(function (el) { return el.checked; });
                var toggle = document.querySelector('.zs-sl__order-toggle[data-order-id="' + oid + '"]');
                if (toggle) { toggle.checked = anyChecked; }
                var orderItem = document.querySelector('.zs-sl__order-item[data-order-id="' + oid + '"]');
                if (orderItem) { orderItem.classList.toggle('is-disabled', !anyChecked); }
                scheduleAutoUpdate();
            });
        });
        if (share) {
            share.addEventListener('click', function () {
                if (!currentSignedUrl) { return; }
                var btn = share;
                function onCopied() {
                    btn.textContent = 'Copiat!';
                    setTimeout(function () { btn.textContent = 'Copiar enllaç'; }, 2000);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(currentSignedUrl).then(onCopied).catch(function () {
                        prompt('Copia aquest enllaç:', currentSignedUrl);
                    });
                } else {
                    prompt('Copia aquest enllaç:', currentSignedUrl);
                }
            });
        }
        var printBtn = document.getElementById('zs-sl-print');
        if (printBtn) {
            printBtn.addEventListener('click', zsPrint);
        }
    }

    function initFlatpickr() {
        if (typeof flatpickr === 'undefined') { return; }
        var opts = {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd/m/Y',
            allowInput: true,
            locale: { firstDayOfWeek: 1 },
            disableMobile: false
        };
        flatpickr('#zs-sl-from', opts);
        flatpickr('#zs-sl-to', opts);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initFlatpickr();

        var searchBtn = document.getElementById('zs-sl-search');
        if (searchBtn) {
            searchBtn.addEventListener('click', function () { doRequest(null); });
        }

        var resetBtn = document.getElementById('zs-sl-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var fromEl = document.getElementById('zs-sl-from');
                var toEl   = document.getElementById('zs-sl-to');
                var locEl  = document.getElementById('zs-sl-loc');
                if (fromEl && fromEl._flatpickr) { fromEl._flatpickr.clear(); } else if (fromEl) { fromEl.value = ''; }
                if (toEl   && toEl._flatpickr)   { toEl._flatpickr.clear(); }   else if (toEl)   { toEl.value = ''; }
                if (locEl) { locEl.value = ''; }
                body.innerHTML = '<div class="zs-sl__empty" id="zs-sl-empty"><p>Selecciona un rang de dates i una localització per veure la llista de la compra.</p></div>';
            });
        }

        var preKeys = cfg.preItemKeys && cfg.preItemKeys.length > 0 ? cfg.preItemKeys : null;
        if (preKeys) {
            doRequest(preKeys);
        } else {
            bindBodyEvents();
        }
    });
}());
