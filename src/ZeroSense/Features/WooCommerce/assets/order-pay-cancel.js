(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

  ready(function(){
    if (typeof ZS_CancelOrder === 'undefined') return;

    var btn = document.getElementById('zs-cancel-order-btn');
    var overlay = document.getElementById('zs-cancel-overlay');
    if (!btn || !overlay) return;

    function showOverlay(){ overlay.style.display = 'flex'; document.body.classList.add('zs-cancelling'); }
    function hideOverlay(){ overlay.style.display = 'none'; document.body.classList.remove('zs-cancelling'); }

    btn.addEventListener('click', function(){
      if (!ZS_CancelOrder.orderId) return;
      var msg = (ZS_CancelOrder.i18n && ZS_CancelOrder.i18n.confirm) || 'Are you sure?';
      if (!window.confirm(msg)) return;

      showOverlay();

      var params = new URLSearchParams();
      params.set('action', 'zs_cancel_order');
      params.set('order_id', String(ZS_CancelOrder.orderId));
      params.set('_wpnonce', ZS_CancelOrder.nonce);

      fetch(ZS_CancelOrder.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        credentials: 'same-origin'
      }).then(function(r){ return r.json().catch(function(){ return { success:false, data:'Invalid response' }; }); })
        .then(function(res){
          if (res && res.success) {
            window.location.href = ZS_CancelOrder.redirectUrl || '/';
          } else {
            hideOverlay();
            window.alert(res && res.data ? String(res.data) : 'Could not cancel order.');
          }
        })
        .catch(function(){ hideOverlay(); window.alert('Network error.'); });
    });
  });
})();
