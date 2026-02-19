(function () {
    var cfg = window.zsCartTimeout || {};
    var COOKIE = cfg.cookieName || 'zs_cart_last_seen';
    var TIMEOUT = parseInt(cfg.timeout, 10) || 300;
    var AJAX_URL = cfg.ajaxUrl || '';
    var NONCE = cfg.nonce || '';

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? parseInt(match[1], 10) : null;
    }

    function setCookie(name, value) {
        document.cookie = name + '=' + value + '; path=/; SameSite=Lax';
    }

    try {
        var last = getCookie(COOKIE);
        var now = Math.floor(Date.now() / 1000);

        if (last !== null && (now - last) > TIMEOUT) {
            var fd = new FormData();
            fd.append('action', 'zs_clear_cart_after_timeout');
            fd.append('nonce', NONCE);
            fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function (data) {
                    if (data && data.success) {
                        setCookie(COOKIE, now);
                        window.location.replace(window.location.href);
                    }
                });
        } else {
            setCookie(COOKIE, now);
        }
    } catch (e) { /* silent */ }
})();
