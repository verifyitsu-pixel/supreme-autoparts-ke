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
  var PAYMENT_STAGES = [
    'Validating your order…',
    'Taking you to secure payment…',
    'Confirming — do not close this page.'
  ];
  var stageTimer = null;
  function startStages() {
    stopStages();
    var i = 0;
    if (window.saLoader && window.saLoader.setLabel) {
      window.saLoader.setLabel(PAYMENT_STAGES[0]);
    }
    stageTimer = window.setInterval(function () {
      i += 1;
      if (i >= PAYMENT_STAGES.length) { stopStages(); return; }
      if (window.saLoader && window.saLoader.setLabel) {
        window.saLoader.setLabel(PAYMENT_STAGES[i]);
      }
    }, 2800);
  }
  function stopStages() {
    if (stageTimer !== null) { window.clearInterval(stageTimer); stageTimer = null; }
  }
  function setArc(on) {
    var ov = document.getElementById('sa-loader');
    if (ov) ov.classList.toggle(ARC_CLASS, !!on);
  }
  function paymentStalled() {
    /* The payment handoff never finished within the 90s failsafe (slow network,
       stalled session write, payment page that never opened). Order/payment state
       is UNKNOWN here - never claim otherwise; guide a manual, informed retry. */
    stopStages();
    setArc(false);
    var ov = document.getElementById('sa-loader');
    if (!ov) return;
    var dots = ov.querySelector('.sa-loader__dots');
    if (dots) dots.style.display = 'none';
    var label = ov.querySelector('[data-sa-loader-label]');
    if (!label) return;
    label.innerHTML =
      '<strong style="display:block;margin-bottom:8px;">This is taking longer than usual</strong>' +
      '<span style="display:block;margin-bottom:14px;">The secure payment page did not open as expected, so your order may not have been placed. If you already entered card or M-Pesa/Airtel details on a payment page, check for a confirmation email before paying again. Otherwise check your connection, then tap Try again.</span>' +
      '<button type="button" class="sa-btn" data-sa-pay-retry style="margin-right:10px;padding:10px 18px;">Try again</button>' +
      '<a data-sa-pay-help href="https://wa.me/supremeautopart?text=Hi%2C%20my%20checkout%20payment%20page%20did%20not%20open" target="_blank" rel="noopener">Get help on WhatsApp</a>';
    var btn = label.querySelector('[data-sa-pay-retry]');
    if (btn) {
      btn.addEventListener('click', function () {
        if (window.saLoader && window.saLoader.hide) window.saLoader.hide(true);
        var po = document.getElementById('place_order');
        if (po) po.focus();
      });
    }
  }
  function paymentWait() {
    if (window.saLoader && window.saLoader.show) {
      window.saLoader.show('payment', { failsafeMs: 90000, onFailsafe: paymentStalled });
    }
    setArc(true);
    startStages();
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
        if (!ov.classList.contains('is-visible')) { setArc(false); stopStages(); }
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
