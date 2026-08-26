(function ($) {
  'use strict';

  function setCount(count) {
    var value = Math.max(0, Number(count) || 0);
    document.querySelectorAll('[data-cvd-cart-count]').forEach(function (badge) {
      badge.textContent = String(value);
      badge.hidden = value < 1;
      badge.setAttribute('aria-label', value === 1 ? '1 producto en el carrito' : value + ' productos en el carrito');
    });
  }

  function syncCount() {
    if (!window.cvdCustomerNav || !window.cvdCustomerNav.ajaxUrl) return;
    var body = new URLSearchParams({ action: 'cvd_cart_count' });
    fetch(window.cvdCustomerNav.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function (response) { return response.json(); })
      .then(function (result) { if (result && result.success && result.data) setCount(result.data.count); })
      .catch(function () {});
  }

  function optimisticCartQuantity() {
    var inputs = document.querySelectorAll('.woocommerce-cart-form input.qty');
    if (!inputs.length) return;
    var total = 0;
    inputs.forEach(function (input) { total += Math.max(0, Number(input.value) || 0); });
    setCount(total);
  }

  document.addEventListener('input', function (event) {
    if (event.target.matches('.woocommerce-cart-form input.qty')) optimisticCartQuantity();
  });

  if ($) {
    $(document.body).on('added_to_cart removed_from_cart updated_wc_div wc_fragments_refreshed', syncCount);
  }

  window.addEventListener('pageshow', syncCount);

  document.addEventListener('click', function (event) {
    document.querySelectorAll('details.cv-mobile-nav[open]').forEach(function (menu) {
      if (!menu.contains(event.target) || event.target.closest('.cv-mobile-nav__panel a')) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') document.querySelectorAll('details.cv-mobile-nav[open]').forEach(function (menu) { menu.removeAttribute('open'); });
  });
})(window.jQuery);
