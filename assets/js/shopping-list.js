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

    function getCheckedIds() {
        var boxes = document.querySelectorAll('.zs-sl__order-check:checked');
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

    function renderOrders(orders, selectedIds) {
        if (!orders || orders.length === 0) {
            return '<div class="zs-sl__no-orders"><p>No hi ha comandes per a aquest període i localització.</p></div>';
        }
        var html = '<div class="zs-sl__orders no-print" id="zs-sl-orders">';
        html += '<h3 class="zs-sl__section-title">Comandes incloses</h3>';
        html += '<div class="zs-sl__orders-actions">';
        html += '<button type="button" class="zs-sl__btn zs-sl__btn--sm" id="zs-sl-check-all">Tots</button> ';
        html += '<button type="button" class="zs-sl__btn zs-sl__btn--sm" id="zs-sl-uncheck-all">Cap</button>';
        html += '</div>';
        html += '<div class="zs-sl__orders-list" id="zs-sl-orders-list">';
        orders.forEach(function (o) {
            var chk = (!selectedIds || selectedIds.indexOf(String(o.id)) !== -1) ? ' checked' : '';
            html += '<label class="zs-sl__order-item">';
            html += '<input type="checkbox" class="zs-sl__order-check" value="' + esc(o.id) + '"' + chk + '>';
            html += '<span class="zs-sl__order-num">#' + esc(o.number) + '</span>';
            html += '<span class="zs-sl__order-customer">' + esc(o.customer) + '</span>';
            html += '<span class="zs-sl__order-date">' + esc(o.date) + '</span>';
            html += '<span class="zs-sl__order-guests">' + esc(o.guests) + ' pax</span>';
            html += '<span class="zs-sl__order-products">' + esc(o.products) + '</span>';
            html += '</label>';
        });
        html += '</div>';
        html += '<div class="zs-sl__orders-footer">';
        html += '<button type="button" class="zs-sl__btn zs-sl__btn--primary" id="zs-sl-update">Actualitzar llista</button> ';
        html += '<button type="button" class="zs-sl__btn zs-sl__btn--secondary" id="zs-sl-share">Copiar enllaç</button> ';
        html += '<button type="button" class="zs-sl__btn zs-sl__btn--secondary" id="zs-sl-print">Imprimir</button>';
        html += '</div></div>';
        return html;
    }

    function renderList(list) {
        if (!list || list.length === 0) {
            return '<div class="zs-sl__list-empty"><p>No hi ha ingredients per a les comandes seleccionades.</p></div>';
        }
        list = list.slice().sort(function (a, b) { return a.name.localeCompare(b.name); });
        var html = '<div class="zs-sl__list print-only" id="zs-sl-list"><div class="zs-sl__list-items">';
        list.forEach(function (item) {
            html += '<div class="zs-sl__list-item">';
            html += '<span class="zs-sl__item-name">' + esc(item.name) + '</span>';
            html += '<span class="zs-sl__item-qty">' + formatQty(item.qty) + ' <span class="zs-sl__item-unit">' + esc(item.unit) + '</span></span>';
            html += '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function zsPrint() {
        document.body.classList.add('zs-printing');
        window.addEventListener('afterprint', function handler() {
            document.body.classList.remove('zs-printing');
            window.removeEventListener('afterprint', handler);
        });
        window.print();
    }

    var currentSignedUrl = '';

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
        if (orderIds && orderIds.length > 0) {
            data.append('order_ids', orderIds.join(','));
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
                var ordersHtml = renderOrders(res.data.orders, null);
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
        var update     = document.getElementById('zs-sl-update');
        var share      = document.getElementById('zs-sl-share');

        if (checkAll) {
            checkAll.addEventListener('click', function () {
                document.querySelectorAll('.zs-sl__order-check').forEach(function (el) { el.checked = true; });
            });
        }
        if (uncheckAll) {
            uncheckAll.addEventListener('click', function () {
                document.querySelectorAll('.zs-sl__order-check').forEach(function (el) { el.checked = false; });
            });
        }
        if (update) {
            update.addEventListener('click', function () {
                var ids = getCheckedIds();
                doRequest(ids.length > 0 ? ids : null);
            });
        }
        if (share) {
            share.addEventListener('click', function () {
                var ids = getCheckedIds();
                if (ids.length > 0) {
                    doRequest(ids);
                    setTimeout(function () {
                        if (currentSignedUrl) {
                            navigator.clipboard.writeText(currentSignedUrl).then(function () {
                                share.textContent = 'Copiat!';
                                setTimeout(function () { share.textContent = 'Copiar enllaç'; }, 2000);
                            });
                        }
                    }, 600);
                } else if (currentSignedUrl) {
                    navigator.clipboard.writeText(currentSignedUrl).then(function () {
                        share.textContent = 'Copiat!';
                        setTimeout(function () { share.textContent = 'Copiar enllaç'; }, 2000);
                    });
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

        bindBodyEvents();

        var checkAll   = document.getElementById('zs-sl-check-all');
        var uncheckAll = document.getElementById('zs-sl-uncheck-all');
        var update     = document.getElementById('zs-sl-update');
        var share      = document.getElementById('zs-sl-share');

        if (checkAll) {
            checkAll.addEventListener('click', function () {
                document.querySelectorAll('.zs-sl__order-check').forEach(function (el) { el.checked = true; });
            });
        }
        if (uncheckAll) {
            uncheckAll.addEventListener('click', function () {
                document.querySelectorAll('.zs-sl__order-check').forEach(function (el) { el.checked = false; });
            });
        }
        if (update) {
            update.addEventListener('click', function () {
                var ids = getCheckedIds();
                doRequest(ids.length > 0 ? ids : null);
            });
        }
        if (share) {
            share.addEventListener('click', function () {
                if (currentSignedUrl) {
                    navigator.clipboard.writeText(currentSignedUrl).then(function () {
                        share.textContent = 'Copiat!';
                        setTimeout(function () { share.textContent = 'Copiar enllaç'; }, 2000);
                    });
                }
            });
        }
    });
}());
