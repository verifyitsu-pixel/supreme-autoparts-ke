/* Supreme Autoparts - contextual loading spinners (Rock 2026-10-17).
   Dots ring = search suggestions, bars ring = add-to-cart (CSS only),
   arc ring = payment waits. Also: pending-order re-checks and the get-the-app platform flag. Runs alongside sa-loader.js (enqueued first). */
(function () {
  'use strict';

  /* ---- 1) Search suggestions: dots ring while the sa_suggest request is in flight ---- */
  var suggestInFlight = 0;
  function setSuggestLoading(on) {
    suggestInFlight = Math.max(0, suggestInFlight + (on ? 1 : -1));
    var forms = document.querySelectorAll('[data-sa-hx-search]');
    for (var i = 0; i < forms.length; i++) {
      forms[i].classList.toggle('is-suggest-loading', suggestInFlight > 0);
    }
  }
  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function (input, init) {
      var url = '';
      try {
        url = typeof input === 'string' ? input : (input && input.url) || '';
      } catch (e) {}
      if (url.indexOf('action=sa_suggest') !== -1) {
        setSuggestLoading(true);
        return origFetch.apply(this, arguments).then(
          function (r) { setSuggestLoading(false); return r; },
          function (err) { setSuggestLoading(false); throw err; }
        );
      }
      return origFetch.apply(this, arguments);
    };
  }

  /* ---- 2) Payment waits: arc-ring variant + friendly label on the sitewide overlay ---- */
  var ARC_CLASS = 'sa-loader--arc';
  var PAYMENT_MSG = 'Please wait - taking you to secure payment. Do not close this page.';
  function setArc(on) {
    var ov = document.getElementById('sa-loader');
    if (ov) ov.classList.toggle(ARC_CLASS, !!on);
  }
  function paymentWait() {
    if (window.saLoader && window.saLoader.show) {
      window.saLoader.show('payment', { failsafeMs: 90000 });
    }
    setArc(true);
    if (window.saLoader && window.saLoader.setLabel) {
      window.saLoader.setLabel(PAYMENT_MSG);
    }
  }
  function bindPayment() {
    if (!window.jQuery) return;
    var $ = window.jQuery;
    // This file enqueues after sa-loader.js, so these handlers run after its own
    // and the arc variant + label win for payment waits.
    $(document.body).on('click', '#place_order', function () {
      paymentWait();
    });
    $(document.body).on('checkout_place_order_success', function () {
      paymentWait();
    });
    $(document.body).on('checkout_error', function () {
      setArc(false);
    });
    // Strip the arc variant whenever the overlay hides so normal navigation
    // shows the branded tyre again.
    var ov = document.getElementById('sa-loader');
    if (ov && window.MutationObserver) {
      new MutationObserver(function () {
        if (!ov.classList.contains('is-visible')) setArc(false);
      }).observe(ov, { attributes: true, attributeFilter: ['class'] });
    }
  }

  /* ---- 3) Pending order on the thank-you page: re-check automatically ---- */
  function bindPendingOrder() {
    var note = document.querySelector('[data-sa-pending-order]');
    if (!note) return;
    var oid = note.getAttribute('data-sa-pending-order') || '0';
    var key = 'sa_ppw_' + oid;
    var tries = 0;
    try { tries = parseInt(window.sessionStorage.getItem(key) || '0', 10) || 0; } catch (e) {}
    if (tries >= 6) return; // ~1 minute of checks, then stop; the note stays as guidance
    try { window.sessionStorage.setItem(key, String(tries + 1)); } catch (e) {}
    window.setTimeout(function () {
      window.location.reload();
    }, 10000);
  }

  /* ---- 4) Get-the-app block on the thank-you page: lead with the visitor's platform ---- */
  function bindGetApp() {
    var block = document.querySelector('[data-sa-get-app]');
    if (!block) return;
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/i.test(ua)) {
      block.classList.add('is-ios');
    } else if (/Android/i.test(ua)) {
      block.classList.add('is-android');
    }
  }

  function init() {
    bindPayment();
    bindPendingOrder();
    bindGetApp();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
