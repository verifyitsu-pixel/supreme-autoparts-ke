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
    el.setAttribute('data-whop-checkout-theme', 'dark');
    el.setAttribute('data-whop-checkout-theme-accent-color', 'amber');
    el.setAttribute('data-whop-checkout-theme-background-color', '#0B0B0D');
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

  function init() {
    mount = $('#sa-whop-pay-mount');
    statusEl = $('#sa-whop-pay-status');
    if (!mount) return;
    if (!cfg.planId) {
      setStatus(i18n('error', 'Missing payment session.'), 'error');
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
