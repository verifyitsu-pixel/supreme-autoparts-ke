/**
 * My Account — embedded Whop verify checkout (Add card).
 * Mounts Whop loader iframe on-page; never redirects the top window to whop.com.
 *
 * Whop's loader only mounts on:
 *  1) initial querySelectorAll of [data-whop-checkout-plan-id], or
 *  2) MutationObserver childList addedNodes that already have the attrs.
 * Setting attrs on an existing empty div does NOT remount — so we always
 * append a fresh child element with attrs pre-set.
 */
(function () {
  'use strict';

  var cfg = window.saWhopPmEmbed || {};
  var panel = null;
  var mount = null;
  var statusEl = null;
  var addBtns = [];
  var busy = false;
  var iframeWatch = null;
  var mountGeneration = 0;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function show(el, on) {
    if (!el) return;
    el.hidden = !on;
    el.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function setStatus(msg, kind) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.className = 'sa-pm-embed__status' + (kind ? ' sa-pm-embed__status--' + kind : '');
  }

  function clearMount() {
    if (!mount) return;
    if (iframeWatch) {
      window.clearInterval(iframeWatch);
      iframeWatch = null;
    }
    mount.innerHTML = '';
    mount.removeAttribute('data-whop-checkout-mounted');
    [
      'data-whop-checkout-plan-id',
      'data-whop-checkout-session',
      'data-whop-checkout-return-url',
      'data-whop-checkout-theme',
      'data-whop-checkout-theme-accent-color',
      'data-whop-checkout-theme-background-color',
      'data-whop-checkout-setup-future-usage',
      'data-whop-checkout-skip-redirect',
      'data-whop-checkout-on-complete',
      'data-whop-checkout-on-payment-error',
      'data-whop-checkout-prefill-email',
      'data-whop-checkout-hide-email',
      'data-whop-checkout-hide-address',
      'data-whop-checkout-style-container-padding-x',
      'data-whop-checkout-style-container-padding-y',
    ].forEach(function (a) {
      mount.removeAttribute(a);
    });
  }

  function buildCheckoutEl(data) {
    var el = document.createElement('div');
    el.className = 'sa-pm-embed__checkout';
    el.setAttribute('data-whop-checkout-plan-id', data.plan_id);
    if (data.session_id) {
      el.setAttribute('data-whop-checkout-session', data.session_id);
    }
    el.setAttribute('data-whop-checkout-return-url', data.return_url || cfg.returnUrl || '');
    el.setAttribute('data-whop-checkout-theme', 'dark');
    el.setAttribute('data-whop-checkout-theme-accent-color', 'amber');
    el.setAttribute('data-whop-checkout-theme-background-color', '#0B0B0D');
    el.setAttribute('data-whop-checkout-setup-future-usage', 'off_session');
    el.setAttribute('data-whop-checkout-skip-redirect', 'true');
    el.setAttribute('data-whop-checkout-on-complete', 'saWhopPmComplete');
    el.setAttribute('data-whop-checkout-on-payment-error', 'saWhopPmPaymentError');
    if (data.email || cfg.email) {
      el.setAttribute('data-whop-checkout-prefill-email', data.email || cfg.email);
      el.setAttribute('data-whop-checkout-hide-email', 'true');
    }
    // Card-first: hide shipping/address chrome Whop may show.
    el.setAttribute('data-whop-checkout-hide-address', 'true');
    el.setAttribute('data-whop-checkout-style-container-padding-x', '0');
    el.setAttribute('data-whop-checkout-style-container-padding-y', '8');
    el.style.width = '100%';
    el.style.minHeight = '460px';
    el.style.height = 'fit-content';
    el.style.overflow = 'hidden';
    return el;
  }

  function watchForIframe(el, gen) {
    if (iframeWatch) {
      window.clearInterval(iframeWatch);
      iframeWatch = null;
    }
    var ticks = 0;
    iframeWatch = window.setInterval(function () {
      if (gen !== mountGeneration) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        return;
      }
      ticks += 1;
      var frame = el && el.querySelector && el.querySelector('iframe');
      if (frame) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        setStatus('');
        try {
          if (!frame.style.minHeight) {
            frame.style.minHeight = '420px';
          }
          frame.style.width = '100%';
        } catch (e) {}
        try {
          document.dispatchEvent(new CustomEvent('sa-whop-embed-ready'));
        } catch (e2) {}
        return;
      }
      // Every ~2s, re-append a fresh node to retrigger MutationObserver
      // (covers race where index.js scanned before attrs existed).
      if (ticks === 5 || ticks === 10 || ticks === 15) {
        if (!mount || !el.parentNode) return;
        var data = {
          plan_id: el.getAttribute('data-whop-checkout-plan-id'),
          session_id: el.getAttribute('data-whop-checkout-session'),
          return_url: el.getAttribute('data-whop-checkout-return-url'),
          email:
            el.getAttribute('data-whop-checkout-prefill-email') || cfg.email || '',
        };
        if (!data.plan_id) return;
        var fresh = buildCheckoutEl(data);
        mount.innerHTML = '';
        mount.appendChild(fresh);
        el = fresh;
      }
      if (ticks >= 25) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        setStatus(
          (cfg.i18n && cfg.i18n.error) ||
            'Could not load card form. Please try again.',
          'error'
        );
        busy = false;
        addBtns.forEach(function (b) {
          b.disabled = false;
        });
      }
    }, 400);
  }


  function ensureWhopIndex() {
    if (window.wco && window.wco.listening) return;
    // WP ?ver= on loader.js breaks Whop's replace(/loader\.js$/, "index.js").
    // Ensure index.js is present even when the stub failed to inject it.
    var existing = document.querySelector('script[src*="js.whop.com/static/checkout/index.js"]');
    if (existing) return;
    var s = document.createElement('script');
    s.src = 'https://js.whop.com/static/checkout/index.js';
    s.async = true;
    s.defer = true;
    document.head.appendChild(s);
  }

  function mountEmbed(data) {
    if (!mount || !data || !data.plan_id) return;
    ensureWhopIndex();
    clearMount();
    mountGeneration += 1;
    var gen = mountGeneration;
    var el = buildCheckoutEl(data);
    // Append AFTER attrs are set so Whop MutationObserver / initial scan sees them.
    mount.appendChild(el);
    setStatus((cfg.i18n && cfg.i18n.loadingForm) || 'Loading card form…', 'loading');
    watchForIframe(el, gen);
  }

  function openPanel() {
    if (!panel) return;
    show(panel, true);
    try {
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {}
  }

  function closePanel() {
    if (!panel) return;
    show(panel, false);
    clearMount();
    setStatus('');
    busy = false;
    addBtns.forEach(function (b) {
      b.disabled = false;
    });
  }

  function startSession() {
    if (busy) return;
    busy = true;
    addBtns.forEach(function (b) {
      b.disabled = true;
    });
    openPanel();
    setStatus((cfg.i18n && cfg.i18n.starting) || 'Preparing…', 'loading');

    var body = new FormData();
    body.append('action', 'sa_whop_start_embed_verify');
    body.append('nonce', cfg.nonce || '');

    fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success || !json.data) {
          throw new Error(
            (json && json.data && json.data.message) ||
              (cfg.i18n && cfg.i18n.error) ||
              'error'
          );
        }
        // Always mount a fresh session — never reuse stale cookie/meta IDs.
        mountEmbed(json.data);
        busy = false;
        addBtns.forEach(function (b) {
          b.disabled = false;
        });
      })
      .catch(function (err) {
        setStatus(
          (err && err.message) || (cfg.i18n && cfg.i18n.error) || 'Could not start.',
          'error'
        );
        busy = false;
        addBtns.forEach(function (b) {
          b.disabled = false;
        });
      });
  }

  window.saWhopPmComplete = function (planId, receiptId) {
    setStatus((cfg.i18n && cfg.i18n.syncing) || 'Saving…', 'loading');
    var body = new FormData();
    body.append('action', 'sa_whop_sync_payment_methods');
    body.append('nonce', cfg.syncNonce || '');
    if (planId) body.append('plan_id', planId);
    if (receiptId) body.append('receipt_id', receiptId);

    fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    })
      .then(function (r) {
        return r.json();
      })
      .then(function () {
        setStatus((cfg.i18n && cfg.i18n.success) || 'Saved.', 'success');
        window.setTimeout(function () {
          var url = new URL(window.location.href);
          url.searchParams.delete('sa_whop_embed');
          url.searchParams.set('sa_whop_embed_done', '1');
          window.location.href = url.toString();
        }, 600);
      })
      .catch(function () {
        var url = new URL(window.location.href);
        url.searchParams.set('sa_whop_embed_done', '1');
        window.location.href = url.toString();
      });
  };

  window.saWhopPmPaymentError = function (error) {
    var msg =
      (error && (error.message || error.code)) ||
      'Payment failed. Please try another card.';
    setStatus(String(msg), 'error');
  };

  function bind() {
    panel = $('#sa-whop-embed');
    mount = $('#sa-whop-pm-embed');
    statusEl = $('#sa-whop-embed-status');
    if (!panel || !mount) return;

    addBtns = Array.prototype.slice.call(
      document.querySelectorAll('[data-sa-whop-add-card]')
    );
    addBtns.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        startSession();
      });
    });

    var cancel = $('#sa-whop-embed-cancel');
    if (cancel) {
      cancel.addEventListener('click', function (e) {
        e.preventDefault();
        closePanel();
      });
    }

    // Only auto-open when explicitly requested (?sa_whop_embed=1).
    // Never auto-open from leftover user-meta session IDs (those expire → empty box).
    if (cfg.autoOpen) {
      startSession();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
