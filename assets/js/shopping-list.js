(function () {
    'use strict';

    var cfg     = window.zsShoppingList || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce   = cfg.nonce   || '';

    var body    = document.getElementById('zs-sl-body');
    var loading = document.getElementById('zs-sl-loading');

    function getFormValues() {
        return {
            from: document.getElementById('zs-sl-from').value,
            to:   document.getElementById('zs-sl-to').value,
            loc:  document.getElementById('zs-sl-loc').value,
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
        var html = '<div class="zs-sl__orders no-print" id="zs-sl-orders">';
        html += '<div class="zs-sl__orders-actions">';
        html += '<button type="button" class="" id="zs-sl-check-all">Seleccionar tot</button> ';
        html += '<button type="button" class="" id="zs-sl-uncheck-all">Desseleccionar tot</button>';
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
                var label = item.name + (item.qty > 1 ? ' ×' + item.qty : '');
                var isChecked = !selectedKeys || selectedKeys.indexOf(item.key) !== -1;
                html += '<label class="zs-sl__item-check-label">';
                html += '<span class="zs-sl__switch"><input type="checkbox" class="zs-sl__item-check" value="' + esc(item.key) + '" data-order-id="' + esc(oid) + '"' + (isChecked ? ' checked' : '') + '><span class="zs-sl__switch-track"></span></span>';
                html += '<span>' + esc(label) + '</span>';
                html += '</label>';
            });
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';
        html += '</div>';
        html += '<div class="zs-sl__list-actions">';
        html += '<button type="button" class="btn--neutral btn--outline" id="zs-sl-share">Copiar enllaç</button> ';
        html += '<button type="button" class="btn--neutral" id="zs-sl-print">Imprimir</button>';
        html += '</div>';
        return html;
    }

    function renderList(list) {
        if (!list || list.length === 0) {
            return '<div class="zs-sl__list-empty"><p>No hi ha ingredients per a les comandes seleccionades.</p></div>';
        }
        list = list.slice().sort(function (a, b) { return a.name.localeCompare(b.name); });
        var icon = '<svg class="zs-sl__list-icon" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
        var html = '<div class="zs-sl__list print-only" id="zs-sl-list">';
        html += '<div class="zs-sl__list-header"><h3 class="zs-sl__list-title">Llista de la compra</h3>' + icon + '</div>';
        html += '<div class="zs-sl__list-items">';
        list.forEach(function (item) {
            html += '<div class="zs-sl__list-item">';
            html += '<span class="zs-sl__item-name">' + esc(item.name) + '</span>';
            html += '<span class="zs-sl__item-qty-wrap"><span class="zs-sl__item-qty">' + formatQty(item.qty) + '</span><span class="zs-sl__item-unit">' + esc(item.unit) + '</span></span>';
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
                var ordersHtml = renderOrders(res.data.orders, orderIds);
                var listHtml   = renderList(res.data.list);
                body.innerHTML = ordersHtml + '<div id="zs-sl-list-wrap">' + listHtml + '</div>';
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

    document.addEventListener('DOMContentLoaded', function () {
        var searchBtn = document.getElementById('zs-sl-search');
        if (searchBtn) {
            searchBtn.addEventListener('click', function () { doRequest(null); });
        }
        var preKeys = cfg.preItemKeys && cfg.preItemKeys.length > 0 ? cfg.preItemKeys : null;
        if (preKeys) {
            var ordersEl = document.getElementById('zs-sl-orders');
            if (ordersEl) {
                var ordersData = window.zsShoppingListOrders || null;
                if (!ordersData) {
                    ordersEl.querySelectorAll('.zs-sl__item-check').forEach(function (el) {
                        el.checked = preKeys.indexOf(el.value) !== -1;
                    });
                    ordersEl.querySelectorAll('.zs-sl__order-toggle').forEach(function (toggle) {
                        var oid = toggle.getAttribute('data-order-id');
                        var items = ordersEl.querySelectorAll('.zs-sl__item-check[data-order-id="' + oid + '"]');
                        var anyChecked = Array.from(items).some(function (el) { return el.checked; });
                        toggle.checked = anyChecked;
                        var orderItem = ordersEl.querySelector('.zs-sl__order-item[data-order-id="' + oid + '"]');
                        if (orderItem) { orderItem.classList.toggle('is-disabled', !anyChecked); }
                    });
                }
            }
        }
        bindBodyEvents();
    });
}());
