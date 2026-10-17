/**
 * On-site Whop payment embed (checkout /pay + order-pay).
 * Mounts data-whop-checkout-* element; skip-redirect keeps top frame on supremeautoparts.co.ke.
 */
(function () {
  'use strict';

  var cfg = window.saWhopCheckoutEmbed || {};
  var mount = null;
  var statusEl = null;
  var checkoutEl = null;
  var embedReady = false;
  var iframeWatch = null;
  var mountGeneration = 0;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function i18n(key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  }

  function setStatus(msg, kind) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.className =
      'sa-open-pay__notice sa-pm-embed__status' +
      (kind === 'error'
        ? ' sa-open-pay__notice--err sa-pm-embed__status--error'
        : kind === 'success'
          ? ' sa-open-pay__notice--ok sa-pm-embed__status--success'
          : kind === 'loading'
            ? ' sa-pm-embed__status--loading'
            : '');
  }

  function embedIsRegistered(embedId) {
    try {
      if (!window.wco || !window.wco.identifiedFrames) return false;
      if (typeof window.wco.identifiedFrames.get === 'function') {
        return !!window.wco.identifiedFrames.get(embedId);
      }
      if (typeof window.wco.identifiedFrames.has === 'function') {
        return window.wco.identifiedFrames.has(embedId);
      }
      return !!window.wco.identifiedFrames[embedId];
    } catch (e) {
      return false;
    }
  }

  function ensureWhopIndex() {
    if (window.wco && window.wco.listening) return;
    if (!document.querySelector('script[src*="js.whop.com/static/checkout/loader.js"]')) {
      var s = document.createElement('script');
      s.src = 'https://js.whop.com/static/checkout/loader.js';
      s.async = true;
      s.defer = true;
      document.head.appendChild(s);
    }
    if (!document.querySelector('script[src*="js.whop.com/static/checkout/index.js"]')) {
      var s2 = document.createElement('script');
      s2.src = 'https://js.whop.com/static/checkout/index.js';
      s2.async = true;
      s2.defer = true;
      document.head.appendChild(s2);
    }
  }

  function waitForWco(maxMs) {
    maxMs = maxMs || 10000;
    return new Promise(function (resolve, reject) {
      var start = Date.now();
      (function tick() {
        if (window.wco && (window.wco.listening || typeof window.wco.submit === 'function')) {
          resolve(window.wco);
          return;
        }
        ensureWhopIndex();
        if (Date.now() - start >= maxMs) {
          reject(new Error(i18n('error', 'Payment form script did not load.')));
          return;
        }
        window.setTimeout(tick, 100);
      })();
    });
  }

  function watchForIframe(el, gen) {
    if (iframeWatch) {
      window.clearInterval(iframeWatch);
      iframeWatch = null;
    }
    var started = Date.now();
    iframeWatch = window.setInterval(function () {
      if (gen !== mountGeneration) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        return;
      }
      var frame = el && el.querySelector && el.querySelector('iframe');
      var registered = el && el.id && embedIsRegistered(el.id);
      if (frame || registered) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        embedReady = true;
        setStatus(i18n('ready', 'Enter payment details below.'), '');
        return;
      }
      if (Date.now() - started > 20000) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        setStatus(i18n('error', 'Could not load payment form.'), 'error');
      }
    }, 200);
  }

  function buildCheckoutEl(gen) {
    var el = document.createElement('div');
    el.className = 'sa-whop-pay-embed__checkout';
    el.id = 'sa-whop-pay-checkout-' + String(gen || 1);
    el.setAttribute('data-whop-checkout-plan-id', cfg.planId || '');
    if (cfg.sessionId) {
      el.setAttribute('data-whop-checkout-session', cfg.sessionId);
    }
    el.setAttribute('data-whop-checkout-return-url', cfg.returnUrl || '');
    el.setAttribute('data-whop-checkout-theme', 'light');
    el.setAttribute('data-whop-checkout-theme-accent-color', '#F5A623');
    el.setAttribute('data-whop-checkout-theme-background-color', '#FFFFFF');
    el.setAttribute('data-whop-checkout-theme-border-radius', '10');
    // Stay on supremeautoparts.co.ke — never top-level navigate to whop.com.
    el.setAttribute('data-whop-checkout-skip-redirect', 'true');
    el.setAttribute('data-whop-checkout-on-complete', 'saWhopPayComplete');
    el.setAttribute('data-whop-checkout-on-payment-error', 'saWhopPayPaymentError');
    if (cfg.email) {
      el.setAttribute('data-whop-checkout-prefill-email', cfg.email);
      el.setAttribute('data-whop-checkout-hide-email', 'true');
    }
    el.style.width = '100%';
    el.style.minHeight = '420px';
    return el;
  }

  function mountEmbed() {
    if (!mount || !cfg.planId) {
      setStatus(i18n('error', 'Missing payment session.'), 'error');
      return;
    }
    mountGeneration += 1;
    var gen = mountGeneration;
    mount.innerHTML = '';
    embedReady = false;
    setStatus(i18n('loading', 'Loading secure payment…'), 'loading');
    checkoutEl = buildCheckoutEl(gen);
    mount.appendChild(checkoutEl);
    watchForIframe(checkoutEl, gen);
    waitForWco().catch(function (err) {
      if (gen !== mountGeneration) return;
      setStatus((err && err.message) || i18n('error', 'error'), 'error');
    });
  }

  window.saWhopPayComplete = function () {
    setStatus(i18n('success', 'Payment received. Redirecting…'), 'success');
    var dest = cfg.completeUrl || cfg.returnUrl || '/';
    try {
      var u = new URL(dest, window.location.href);
      // Never leave our origin.
      if (u.origin === window.location.origin) {
        window.location.href = u.toString();
        return;
      }
    } catch (e) {}
    window.location.href = cfg.completeUrl || '/';
  };

  window.saWhopPayPaymentError = function (err) {
    var msg =
      (err && (err.message || err.error || err.msg)) ||
      i18n('payError', 'Payment failed.');
    setStatus(String(msg), 'error');
  };


  // ---------------------------------------------------------------------
  // Store-styled pay form (Whop Elements). Admin-only preview until switched on.
  // Any problem falls back to the standard embed (mountEmbed).
  // ---------------------------------------------------------------------
  var customState = { handle: null, done: false, fellBack: false };

  function fallbackToEmbed(reason) {
    if (customState.fellBack) return;
    customState.fellBack = true;
    try {
      if (customState.handle && typeof customState.handle.destroy === 'function') {
        customState.handle.destroy();
      }
    } catch (e) {}
    if (window.console) console.warn('[sa-pay] custom form unavailable, using standard form:', reason);
    mountEmbed();
  }

  function loadElementsScript() {
    return new Promise(function (resolve, reject) {
      if (window.WhopElements) return resolve();
      var s = document.createElement('script');
      s.src = 'https://cdn.whop.com/elements/amber/elements.js';
      s.setAttribute('data-whop-elements', '');
      s.async = true;
      s.onload = function () {
        window.WhopElements ? resolve() : reject(new Error('WhopElements missing'));
      };
      s.onerror = function () {
        reject(new Error('elements script failed'));
      };
      document.head.appendChild(s);
      window.setTimeout(function () {
        reject(new Error('elements script timeout'));
      }, 10000);
    });
  }

  function initCustom() {
    if (!cfg.accountId) return fallbackToEmbed('no account id');
    mount.innerHTML = '';
    setStatus(i18n('loading', 'Loading secure payment…'), 'loading');
    var wrap = document.createElement('div');
    wrap.className = 'sa-cpay';
    wrap.innerHTML =
      '<div class="sa-cpay__note" id="sa-cpay-note">Admin preview of the new payment form. Charging is not enabled yet.</div>' +
      '<div id="sa-cpay-payment" class="sa-cpay__payment"></div>' +
      '<div id="sa-cpay-branding" class="sa-cpay__branding"></div>' +
      '<p class="sa-cpay__error" id="sa-cpay-error" role="alert" hidden></p>' +
      '<button type="button" class="sa-cpay__btn" id="sa-cpay-btn" disabled></button>' +
      '<p class="sa-cpay__alt"><a href="#" id="sa-cpay-alt">Use the standard payment form instead</a></p>';
    mount.appendChild(wrap);
    var btn = $('#sa-cpay-btn', wrap);
    btn.textContent = cfg.payLabel || 'Pay';
    $('#sa-cpay-alt', wrap).addEventListener('click', function (ev) {
      ev.preventDefault();
      fallbackToEmbed('user chose standard form');
    });
    loadElementsScript()
      .then(function () {
        var whop = window.WhopElements();
        var payments = whop.payments.create({
          accountId: cfg.accountId,
          plan: cfg.planId,
          appearance: {
            theme: { appearance: 'light', accentColor: 'amber', grayColor: 'gray' },
            variables: { '--radius': '10px' }
          }
        });
        customState.handle = payments;
        payments
          .create('payment', {
            layout: 'accordion',
            onChange: function (e) {
              var ok = !!(e && (e.isComplete || e.complete));
              btn.disabled = true; // Stage 1: preview only
              btn.setAttribute('data-ready', ok ? '1' : '');
            },
            onReady: function () {
              setStatus('', '');
            },
            onLoadError: function () {
              fallbackToEmbed('payment element load error');
            }
          })
          .mount('#sa-cpay-payment');
        payments.create('branding').mount('#sa-cpay-branding');
        window.setTimeout(function () {
          var f = $('#sa-cpay-payment iframe');
          if (!f && !customState.fellBack) fallbackToEmbed('no iframe after 12s');
        }, 12000);
        setStatus('', '');
      })
      .catch(function (err) {
        fallbackToEmbed(err && err.message);
      });
  }

  function init() {
    mount = $('#sa-whop-pay-mount');
    statusEl = $('#sa-whop-pay-status');
    if (!mount) return;
    if (!cfg.planId) {
      setStatus(i18n('error', 'Missing payment session.'), 'error');
      return;
    }
    if (cfg.customPay === '1') {
      try {
        initCustom();
      } catch (e) {
        fallbackToEmbed(e && e.message);
      }
      return;
    }
    ensureWhopIndex();
    mountEmbed();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
