(function () {
    var cfg = window.zsOrderPayTerms || {};
    var text = cfg.termsText || '';

    if (!text) { return; }
    if (document.querySelector('input[name="terms"]')) { return; }

    var btn = document.getElementById('place_order');
    if (!btn) { return; }

    var p = document.createElement('p');
    p.className = 'form-row validate-required';
    p.innerHTML =
        '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">' +
        '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="terms" id="zs-terms" />' +
        '<span>' + text + '</span> <span class="required">*</span>' +
        '</label>';
    btn.parentNode.insertBefore(p, btn);
})();
