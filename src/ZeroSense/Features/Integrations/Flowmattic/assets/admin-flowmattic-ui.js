(function(){
  'use strict';

  function onReady(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function(){
    // .zs-toggle inside metabox and other containers
    document.addEventListener('click', function(e){
      var t = e.target;
      if (!t || !t.classList) return;
      if (t.classList.contains('zs-toggle')){
        e.preventDefault();
        var targetId = t.getAttribute('data-toggle-target');
        if (!targetId) return;
        var el = document.getElementById(targetId);
        if (!el) return;
        var isHidden = (el.style.display === '' ? el.offsetParent === null : el.style.display === 'none');
        el.style.display = isHidden ? 'block' : 'none';
        t.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        var showText = t.getAttribute('data-show-text') || 'Show';
        var hideText = t.getAttribute('data-hide-text') || 'Hide';
        t.textContent = isHidden ? hideText : showText;
      }
    }, false);

    // .zs-toggle-btn in orders list column
    document.addEventListener('click', function(e){
      var t = e.target;
      if (!t || !t.classList) return;
      if (t.classList.contains('zs-toggle-btn')){
        e.preventDefault();
        var targetId = t.getAttribute('data-target');
        if (!targetId) return;
        var el = document.getElementById(targetId);
        if (!el) return;
        var isHidden = (el.style.display === '' ? el.offsetParent === null : el.style.display === 'none');
        el.style.display = isHidden ? 'block' : 'none';
        t.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        var showText = t.getAttribute('data-show-text') || 'Show all';
        var hideText = t.getAttribute('data-hide-text') || 'Hide';
        t.textContent = isHidden ? hideText : showText;
      }
    }, false);
  });
})();
