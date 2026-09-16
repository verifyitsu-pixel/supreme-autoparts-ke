(function () {
  'use strict';

  document.documentElement.classList.remove('no-js');

  var toggle = document.querySelector('[data-sa-nav-toggle]');
  var nav = document.querySelector('[data-sa-nav]');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      nav.classList.toggle('is-open');
      var open = nav.classList.contains('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-sa-mega-parent]').forEach(function (item) {
    var link = item.querySelector(':scope > .sa-nav__link');
    if (!link) return;
    link.addEventListener('click', function (e) {
      if (window.matchMedia('(max-width: 900px)').matches) {
        e.preventDefault();
        item.classList.toggle('is-open');
      }
    });
  });

  var drawer = document.querySelector('[data-sa-cart-drawer]');
  function openCart() {
    if (!drawer) return;
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sa-cart-open');
    document.querySelectorAll('[data-sa-cart-open]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'true');
    });
  }
  function closeCart() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sa-cart-open');
    document.querySelectorAll('[data-sa-cart-open]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
    });
  }
  document.querySelectorAll('[data-sa-cart-open]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      openCart();
    });
  });
  document.querySelectorAll('[data-sa-cart-close]').forEach(function (btn) {
    btn.addEventListener('click', closeCart);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCart();
  });

  // Open drawer after AJAX add-to-cart
  if (window.jQuery) {
    jQuery(document.body).on('added_to_cart', function () {
      openCart();
    });
  }

  function bindQty(root) {
    (root || document).querySelectorAll('[data-sa-qty]').forEach(function (wrap) {
      if (wrap.dataset.saQtyBound) return;
      wrap.dataset.saQtyBound = '1';
      var input = wrap.querySelector('input.qty');
      if (!input) return;
      var minus = wrap.querySelector('[data-sa-qty-minus]');
      var plus = wrap.querySelector('[data-sa-qty-plus]');
      if (minus) {
        minus.addEventListener('click', function () {
          var v = parseFloat(input.value) || 0;
          var min = parseFloat(input.min);
          if (isNaN(min)) min = 0;
          input.value = Math.max(min, v - 1);
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      if (plus) {
        plus.addEventListener('click', function () {
          var v = parseFloat(input.value) || 0;
          var max = parseFloat(input.max);
          var next = v + 1;
          if (!isNaN(max) && max > 0) next = Math.min(max, next);
          input.value = next;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
    });
  }
  bindQty(document);
  if (window.jQuery) {
    jQuery(document.body).on('updated_cart_totals updated_wc_div', function () {
      bindQty(document);
    });
  }
})();
