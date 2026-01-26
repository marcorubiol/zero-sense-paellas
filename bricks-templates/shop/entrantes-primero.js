document.addEventListener('DOMContentLoaded', () => {
  const debug = (window.location.hostname === 'localhost') || (window.location.hostname?.includes('staging')) || (typeof WP_DEBUG !== 'undefined' && WP_DEBUG);
  const log = (...args) => { if (debug) console.log(...args); };

  const ids = ['entrantes', 'entrants', 'starters'];

  const moveOnce = () => {
    const el = ids.map(id => document.getElementById(id)).find(Boolean);
    const list = document.querySelector('.shop-products');
    if (!el || !list) return false;
    // prepend moves the node if it's already in the DOM
    list.prepend(el);
    log(`Moved "${el.id}" to the beginning of .shop-products`);
    return true;
  };

  let attempts = 0;
  const tryMove = () => {
    if (moveOnce()) return;
    if (++attempts <= 3) {
      // Retry in case Bricks or other scripts render late
      setTimeout(tryMove, 250);
    } else if (debug) {
      console.warn('Menu section or .shop-products not found after retries');
    }
  };

  tryMove();
});
 