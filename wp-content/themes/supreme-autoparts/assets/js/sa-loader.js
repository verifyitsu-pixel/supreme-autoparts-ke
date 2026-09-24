/**
 * Supreme Autoparts — sitewide branded tyre loading overlay.
 * Triggers on real navigations / form posts / WC + Whop payment waits.
 * Skips tabs, accordions, qty +/- and other harmless UI.
 */
(function () {
  'use strict';

  var LABELS = {
    loading: 'Loading…',
    processing: 'Processing…',
    payment: 'Processing payment…',
  };

  var i18n = (window.saTheme && window.saTheme.i18n) || {};
  if (i18n.loading) LABELS.loading = i18n.loading;
  if (i18n.processing) LABELS.processing = i18n.processing;
  if (i18n.processingPayment) LABELS.payment = i18n.processingPayment;

  var overlay = null;
  var labelEl = null;
  var hideTimer = null;
  var failsafeTimer = null;
  var visible = false;
  var navPending = false;

  var TYRE_SVG =
    '<svg class="sa-loader__wheel" viewBox="0 0 64 64" width="72" height="72" aria-hidden="true" focusable="false">' +
    '<circle cx="32" cy="32" r="30" fill="#121214" stroke="currentColor" stroke-width="2"/>' +
    '<circle cx="32" cy="32" r="27.5" fill="none" stroke="#2A2A2E" stroke-width="5"/>' +
    '<circle cx="32" cy="32" r="27.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-dasharray="3.2 4.8" opacity="0.85"/>' +
    '<circle cx="32" cy="32" r="17" fill="#0B0B0D" stroke="currentColor" stroke-width="1.6"/>' +
    '<g stroke="currentColor" stroke-width="3.4" stroke-linecap="round">' +
    '<line x1="32" y1="17.5" x2="32" y2="28"/>' +
    '<line x1="45.8" y1="26.5" x2="36.8" y2="31.2"/>' +
    '<line x1="40.5" y1="43.5" x2="34.5" y2="35.2"/>' +
    '<line x1="23.5" y1="43.5" x2="29.5" y2="35.2"/>' +
    '<line x1="18.2" y1="26.5" x2="27.2" y2="31.2"/>' +
    '</g>' +
    '<circle cx="32" cy="32" r="5" fill="currentColor"/>' +
    '<circle cx="32" cy="32" r="2.2" fill="#0B0B0D"/>' +
    '</svg>';

  function resolveLabel(keyOrText) {
    if (!keyOrText) return LABELS.loading;
    if (LABELS[keyOrText]) return LABELS[keyOrText];
    return String(keyOrText);
  }

  function ensureOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'sa-loader';
    overlay.className = 'sa-loader';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'polite');
    overlay.setAttribute('aria-busy', 'false');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML =
      '<div class="sa-loader__panel">' +
      TYRE_SVG +
      '<p class="sa-loader__label" data-sa-loader-label>' +
      LABELS.loading +
      '</p>' +
      '</div>';
    document.body.appendChild(overlay);
    labelEl = overlay.querySelector('[data-sa-loader-label]');
    return overlay;
  }

  function clearTimers() {
    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }
    if (failsafeTimer) {
      window.clearTimeout(failsafeTimer);
      failsafeTimer = null;
    }
  }

  function show(labelKey, opts) {
    ensureOverlay();
    var text = resolveLabel(labelKey);
    if (labelEl) labelEl.textContent = text;
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-busy', 'true');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sa-loader-active');
    document.documentElement.setAttribute('aria-busy', 'true');
    visible = true;
    clearTimers();
    var ms = (opts && opts.failsafeMs) || 45000;
    failsafeTimer = window.setTimeout(function () {
      if (visible && !navPending) hide(true);
    }, ms);
  }

  function hide(force) {
    if (!overlay && !visible) return;
    clearTimers();
    navPending = false;
    if (!overlay) return;
    if (!force) {
      // Tiny delay avoids flicker on very fast AJAX.
      hideTimer = window.setTimeout(function () {
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-busy', 'false');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sa-loader-active');
        document.documentElement.removeAttribute('aria-busy');
        visible = false;
      }, 80);
      return;
    }
    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-busy', 'false');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sa-loader-active');
    document.documentElement.removeAttribute('aria-busy');
    visible = false;
  }

  function setLabel(labelKey) {
    ensureOverlay();
    if (labelEl) labelEl.textContent = resolveLabel(labelKey);
  }

  function sameOrigin(url) {
    try {
      var u = new URL(url, window.location.href);
      return u.origin === window.location.origin;
    } catch (e) {
      return false;
    }
  }

  function isHashOnly(href) {
    try {
      var u = new URL(href, window.location.href);
      return (
        u.origin === window.location.origin &&
        u.pathname === window.location.pathname &&
        u.search === window.location.search &&
        u.hash !== '' &&
        u.hash !== window.location.hash
      );
    } catch (e) {
      return typeof href === 'string' && href.charAt(0) === '#';
    }
  }

  function isIgnorableLink(a, e) {
    if (!a || !a.getAttribute) return true;
    if (e && (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1)) {
      return true;
    }
    var target = (a.getAttribute('target') || '').toLowerCase();
    if (target === '_blank' || target === '_new') return true;
    if (a.hasAttribute('download')) return true;
    var rel = (a.getAttribute('rel') || '').toLowerCase();
    if (rel.indexOf('external') !== -1 && target === '_blank') return true;

    var href = a.getAttribute('href');
    if (!href || href === '#' || href.indexOf('javascript:') === 0) return true;
    if (/^(mailto:|tel:|sms:|whatsapp:)/i.test(href)) return true;
    if (isHashOnly(href)) return true;
    if (!sameOrigin(href)) return true;

    // Harmless UI / in-page controls
    if (
      a.closest(
        '[data-sa-cart-open],[data-sa-cart-close],[data-sa-nav-toggle],' +
          '[data-sa-qty],[data-sa-qty-minus],[data-sa-qty-plus],' +
          '[data-sa-enquire-channel],[role="tab"],[data-sa-tab],' +
          'summary,.woocommerce-tabs,.sa-accordion,[data-sa-accordion],' +
          '.sa-mega,[data-sa-mega-parent]'
      )
    ) {
      return true;
    }

    // AJAX add-to-cart buttons that stay on page
    if (a.classList.contains('add_to_cart_button') && a.classList.contains('ajax_add_to_cart')) {
      return true;
    }

    return false;
  }

  function isIgnorableForm(form) {
    if (!form) return true;
    if (form.hasAttribute('data-sa-loader-skip')) return true;
    if (form.getAttribute('target') === '_blank') return true;
    // Search that only filters via GET hash/local — still navigates; allow loader.
    // Enquire / contact channel buttons are not form submits.
    if (form.closest('[data-sa-enquire]')) return true;
    // Quantity forms that AJAX-update cart without full reload are handled via WC events.
    if (form.classList.contains('woocommerce-cart-form') && form.querySelector('[name="update_cart"]')) {
      // Only skip if submitter is update_cart (handled below via submitter check).
    }
    return false;
  }

  function labelForForm(form, submitter) {
    if (!form) return 'loading';
    var id = (form.getAttribute('id') || '') + ' ' + (form.getAttribute('name') || '') + ' ' + (form.className || '');
    var action = (form.getAttribute('action') || '').toLowerCase();
    if (
      form.classList.contains('checkout') ||
      form.classList.contains('woocommerce-checkout') ||
      (submitter && (submitter.id === 'place_order' || submitter.name === 'woocommerce_checkout_place_order'))
    ) {
      return 'payment';
    }
    if (/lost.?password|reset.?password/i.test(id + action)) return 'processing';
    if (/login|register|woocommerce-form-login|woocommerce-form-register/i.test(id)) return 'processing';
    if (/add.?to.?cart|cart/i.test(id) && form.method && form.method.toLowerCase() === 'post') return 'processing';
    return 'loading';
  }

  function onDocumentClick(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if (isIgnorableLink(a, e)) return;
    navPending = true;
    show('loading', { failsafeMs: 20000 });
  }

  function onDocumentSubmit(e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (isIgnorableForm(form)) return;

    var submitter = e.submitter || document.activeElement;
    if (submitter && submitter.name === 'update_cart') {
      // Cart qty update — WC AJAX will fire; show brief processing.
      show('processing');
      return;
    }
    if (submitter && submitter.name === 'apply_coupon') {
      show('processing');
      return;
    }

    // Invalid HTML5 forms that never navigate
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      return;
    }

    var method = (form.getAttribute('method') || 'get').toLowerCase();
    var action = form.getAttribute('action') || window.location.href;
    if (method === 'get' && sameOrigin(action)) {
      try {
        var u = new URL(action, window.location.href);
        if (u.pathname === window.location.pathname && u.search === window.location.search) {
          // Same URL GET — may not navigate; still show briefly for filters.
        }
      } catch (err) {}
    }

    navPending = method === 'post' || sameOrigin(action);
    show(labelForForm(form, submitter), { failsafeMs: method === 'post' ? 60000 : 20000 });
  }

  function bindWcAjax() {
    if (!window.jQuery) return;
    var $ = window.jQuery;

    $(document.body).on('update_checkout', function () {
      show('processing');
    });
    $(document.body).on('updated_checkout', function () {
      hide();
    });
    $(document.body).on('checkout_error', function () {
      hide(true);
    });
    $(document.body).on('checkout_place_order_success', function () {
      show('payment', { failsafeMs: 90000 });
      navPending = true;
    });
    $(document.body).on('payment_method_selected', function () {
      show('processing');
    });

    // Place order click — WC may AJAX; keep overlay until leave or error.
    $(document.body).on('click', '#place_order', function () {
      var form = document.querySelector('form.checkout, form.woocommerce-checkout');
      var terms = form && form.querySelector('#terms, [data-sa-terms-checkbox]');
      if (terms && !terms.checked) return;
      show('payment', { failsafeMs: 90000 });
      navPending = true;
    });

    $(document.body).on('adding_to_cart', function () {
      // AJAX add — drawer opens; no full-page overlay.
    });

    // Cart fragments / update
    $(document.body).on('wc_fragments_refreshed updated_cart_totals updated_wc_div', function () {
      hide();
    });

    // Observe Woo .processing on checkout form
    var checkout = document.querySelector('form.checkout, form.woocommerce-checkout');
    if (checkout && window.MutationObserver) {
      var mo = new MutationObserver(function () {
        if (checkout.classList.contains('processing')) {
          show(visible && labelEl && /payment/i.test(labelEl.textContent) ? 'payment' : 'processing');
        } else if (visible && !navPending) {
          hide();
        }
      });
      mo.observe(checkout, { attributes: true, attributeFilter: ['class'] });
    }
  }

  function bindWhopEmbed() {
    var statusEl = document.getElementById('sa-whop-embed-status');
    var mountEl = document.getElementById('sa-whop-pm-embed');
    if (!statusEl || !window.MutationObserver) return;

    function embedHasIframe() {
      return !!(mountEl && mountEl.querySelector('iframe'));
    }

    var mo = new MutationObserver(function () {
      // Once Whop iframe is mounted, never cover it with the full-page overlay.
      if (embedHasIframe()) {
        if (!navPending && !statusEl.classList.contains('sa-pm-embed__status--success')) {
          hide();
        }
        return;
      }
      if (statusEl.classList.contains('sa-pm-embed__status--loading')) {
        var msg = (statusEl.textContent || '').toLowerCase();
        // Only full-page "payment" after submit/sync — not while the form is loading.
        if (/sav|sync/.test(msg)) {
          show('payment');
        } else {
          // Preparing / loading form: light overlay or none (inline status is enough).
          show('loading');
        }
      } else if (
        statusEl.classList.contains('sa-pm-embed__status--error') ||
        statusEl.classList.contains('sa-pm-embed__status--success') ||
        !statusEl.textContent
      ) {
        if (statusEl.classList.contains('sa-pm-embed__status--success')) {
          show('processing');
          navPending = true;
        } else if (!navPending) {
          hide();
        }
      }
    });
    mo.observe(statusEl, {
      attributes: true,
      attributeFilter: ['class'],
      childList: true,
      characterData: true,
      subtree: true,
    });

    // Hide overlay as soon as Whop injects its iframe into the mount.
    if (mountEl) {
      var mountMo = new MutationObserver(function () {
        if (embedHasIframe() && !navPending) {
          hide();
        }
      });
      mountMo.observe(mountEl, { childList: true, subtree: true });
    }

    // Add-card buttons also kick off embed load
    document.querySelectorAll('[data-sa-whop-add-card]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        show('loading');
      });
    });
  }

  function bindLifecycle() {
    window.addEventListener('pageshow', function (e) {
      // bfcache restore or normal show after back — clear stuck overlay
      hide(true);
      if (e.persisted) navPending = false;
    });
    window.addEventListener('pagehide', function () {
      // leave overlay state; next page starts clean
    });
    window.addEventListener('popstate', function () {
      hide(true);
    });
    // If user cancels navigation somehow and stays put
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible' && visible && !navPending) {
        // no-op
      }
    });
  }

  function init() {
    ensureOverlay();
    document.addEventListener('click', onDocumentClick, true);
    document.addEventListener('submit', onDocumentSubmit, true);
    bindWcAjax();
    bindWhopEmbed();
    bindLifecycle();
  }

  window.saLoader = {
    show: show,
    hide: hide,
    setLabel: setLabel,
    labels: LABELS,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
