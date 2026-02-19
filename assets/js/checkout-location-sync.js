(function () {
    var style = document.createElement('style');
    style.textContent = '#event_city_checkout:-webkit-autofill,#event_city_checkout:-webkit-autofill:hover,#event_city_checkout:-webkit-autofill:focus{-webkit-box-shadow:0 0 0 1000px #fff inset;box-shadow:0 0 0 1000px #fff inset;-webkit-text-fill-color:inherit;transition:background-color 9999s ease-in-out 0s;}';
    document.head.appendChild(style);

    var ajaxUrl = (typeof zsLocationSync !== 'undefined') ? zsLocationSync.ajaxUrl : '';
    if (!ajaxUrl) { return; }

    var sa   = localStorage.getItem('.location') || '';
    var city = localStorage.getItem('city') || '';

    var fd = new FormData();
    fd.append('action', 'zs_set_location_session');
    fd.append('service_area', sa);
    fd.append('city', city);
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });

    if (!city) { return; }

    function fillCity() {
        var field = document.getElementById('event_city_checkout');
        if (field && field.value === '') {
            field.value = city;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fillCity);
    } else {
        fillCity();
    }
})();
