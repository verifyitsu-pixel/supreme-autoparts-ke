/**
 * My Account — embedded Whop verify (Add card / Add bank, separate).
 * Clean chrome: hide ToS / Join; our Verify CTA; $1 fee note.
 * Submit uses wco.submit(embedId STRING). Errors + watchdog always surface.
 */
(function () {
  'use strict';

  var cfg = window.saWhopPmEmbed || {};
  var panel = null;
  var mount = null;
  var statusEl = null;
  var feeEl = null;
  var titleEl = null;
  var verifyBtn = null;
  var addBtns = [];
  var busy = false;
  var iframeWatch = null;
  var submitWatchdog = null;
  var mountGeneration = 0;
  var activeMethod = 'card';
  var checkoutEl = null;
  var embedReady = false;
  var warmByMethod = { card: null, bank: null };
  var warmPromiseByMethod = { card: null, bank: null };

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
    statusEl.className =
      'sa-pm-embed__status' + (kind ? ' sa-pm-embed__status--' + kind : '');
  }

  function i18n(key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  }

  function clearSubmitWatchdog() {
    if (submitWatchdog) {
      window.clearTimeout(submitWatchdog);
      submitWatchdog = null;
    }
  }

  function setVerifyEnabled(on) {
    if (!verifyBtn) return;
    verifyBtn.hidden = !on;
    verifyBtn.disabled = !on;
  }

  function applyMethodChrome(method) {
    activeMethod = method === 'bank' ? 'bank' : 'card';
    if (panel) panel.setAttribute('data-sa-whop-method', activeMethod);
    if (titleEl) {
      titleEl.textContent =
        activeMethod === 'bank'
          ? i18n('titleBank', 'Add bank')
          : i18n('titleCard', 'Add card');
    }
    if (feeEl) {
      feeEl.textContent =
        activeMethod === 'bank'
          ? i18n(
              'feeBank',
              'Enter your US bank routing and account numbers in the form below (no Plaid Link). Whop may charge $1.00 USD once to verify the account is active. Multiple banks are allowed.'
            )
          : i18n(
              'feeCard',
              'You will be charged $1.00 USD once to verify this card is active. The charge is non-refundable.'
            );
    }
    if (verifyBtn) {
      verifyBtn.textContent =
        activeMethod === 'bank'
          ? i18n('verifyBank', 'Verify bank')
          : i18n('verifyCard', 'Verify card');
    }
  }

  function clearMount() {
    if (!mount) return;
    if (iframeWatch) {
      window.clearInterval(iframeWatch);
      iframeWatch = null;
    }
    clearSubmitWatchdog();
    mount.innerHTML = '';
    checkoutEl = null;
    embedReady = false;
    setVerifyEnabled(false);
  }

  function buildCheckoutEl(data, gen) {
    var el = document.createElement('div');
    el.className = 'sa-pm-embed__checkout';
    // Unique id per mount so remounts never collide in wco.identifiedFrames.
    el.id = 'sa-whop-pm-checkout-' + String(gen || mountGeneration || 1);
    el.setAttribute('data-whop-checkout-plan-id', data.plan_id);
    if (data.session_id) {
      el.setAttribute('data-whop-checkout-session', data.session_id);
    }
    el.setAttribute(
      'data-whop-checkout-return-url',
      data.return_url || cfg.returnUrl || ''
    );
    el.setAttribute('data-whop-checkout-theme', 'dark');
    el.setAttribute('data-whop-checkout-theme-accent-color', 'amber');
    el.setAttribute('data-whop-checkout-theme-background-color', '#0B0B0D');
    el.setAttribute(
      'data-whop-checkout-theme-button-text',
      activeMethod === 'bank'
        ? i18n('verifyBank', 'Verify bank')
        : i18n('verifyCard', 'Verify card')
    );
    el.setAttribute('data-whop-checkout-setup-future-usage', 'off_session');
    el.setAttribute('data-whop-checkout-skip-redirect', 'true');
    el.setAttribute('data-whop-checkout-on-complete', 'saWhopPmComplete');
    el.setAttribute('data-whop-checkout-on-payment-error', 'saWhopPmPaymentError');
    el.setAttribute('data-whop-checkout-on-state-change', 'saWhopPmStateChange');
    if (activeMethod === 'bank') {
      // Belt-and-suspenders with server PMC: bank fields only, never Plaid-only card mix.
      el.setAttribute('data-whop-checkout-methods', 'us_bank_account');
    } else {
      el.setAttribute('data-whop-checkout-methods', 'card');
    }
    if (data.email || cfg.email) {
      el.setAttribute(
        'data-whop-checkout-prefill-email',
        data.email || cfg.email
      );
      el.setAttribute('data-whop-checkout-hide-email', 'true');
    }
    el.setAttribute('data-whop-checkout-hide-address', 'true');
    el.setAttribute('data-whop-checkout-hide-price', 'true');
    el.setAttribute('data-whop-checkout-hide-tos', 'true');
    el.setAttribute('data-whop-checkout-hide-submit-button', 'true');
    el.setAttribute('data-whop-checkout-style-container-padding-x', '0');
    el.setAttribute('data-whop-checkout-style-container-padding-y', '8');
    el.style.width = '100%';
    el.style.minHeight = activeMethod === 'bank' ? '420px' : '280px';
    el.style.height = 'fit-content';
    el.style.overflow = 'hidden';
    return el;
  }

  function embedIsRegistered(embedId) {
    try {
      if (!window.wco || !window.wco.identifiedFrames) return false;
      if (typeof window.wco.identifiedFrames.get === 'function') {
        return !!window.wco.identifiedFrames.get(embedId);
      }
      if (window.wco.identifiedFrames instanceof Map) {
        return window.wco.identifiedFrames.has(embedId);
      }
      return !!window.wco.identifiedFrames[embedId];
    } catch (e) {
      return false;
    }
  }

  function watchForIframe(el, gen) {
    if (iframeWatch) {
      window.clearInterval(iframeWatch);
      iframeWatch = null;
    }
    var ticks = 0;
    var remounted = false;
    iframeWatch = window.setInterval(function () {
      if (gen !== mountGeneration) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        return;
      }
      ticks += 1;
      var frame = el && el.querySelector && el.querySelector('iframe');
      var registered = el && el.id && embedIsRegistered(el.id);
      if (frame || registered) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        embedReady = true;
        setStatus('');
        try {
          if (frame && !frame.style.minHeight) {
            frame.style.minHeight = activeMethod === 'bank' ? '400px' : '260px';
          }
          if (frame) frame.style.width = '100%';
        } catch (e) {}
        setVerifyEnabled(true);
        try {
          document.dispatchEvent(new CustomEvent('sa-whop-embed-ready'));
        } catch (e2) {}
        return;
      }
      // One gentle remount only (avoid thrashing that breaks wco registration).
      if (!remounted && ticks === 20) {
        remounted = true;
        if (!mount || !el.parentNode) return;
        var data = {
          plan_id: el.getAttribute('data-whop-checkout-plan-id'),
          session_id: el.getAttribute('data-whop-checkout-session'),
          return_url: el.getAttribute('data-whop-checkout-return-url'),
          email:
            el.getAttribute('data-whop-checkout-prefill-email') ||
            cfg.email ||
            '',
        };
        if (!data.plan_id) return;
        var fresh = buildCheckoutEl(data, gen);
        mount.innerHTML = '';
        mount.appendChild(fresh);
        checkoutEl = fresh;
        el = fresh;
        ensureWhopIndex();
        return;
      }
      if (ticks >= 50) {
        window.clearInterval(iframeWatch);
        iframeWatch = null;
        setStatus(i18n('error', 'Could not load form. Please try again.'), 'error');
        busy = false;
        addBtns.forEach(function (b) {
          b.disabled = false;
        });
      }
    }, 200);
  }

  function preloadWhopAssets() {
    [
      'https://js.whop.com/static/checkout/loader.js',
      'https://js.whop.com/static/checkout/index.js',
    ].forEach(function (href) {
      if (document.querySelector('link[rel="preload"][href="' + href + '"]')) return;
      var l = document.createElement('link');
      l.rel = 'preload';
      l.as = 'script';
      l.href = href;
      l.crossOrigin = 'anonymous';
      document.head.appendChild(l);
    });
  }

  function ensureWhopIndex() {
    if (window.wco && window.wco.listening) return;
    var loader = document.querySelector(
      'script[src*="js.whop.com/static/checkout/loader.js"]'
    );
    if (!loader) {
      var s = document.createElement('script');
      s.src = 'https://js.whop.com/static/checkout/loader.js';
      s.async = true;
      s.defer = true;
      document.head.appendChild(s);
      return;
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
    maxMs = maxMs || 8000;
    return new Promise(function (resolve, reject) {
      var start = Date.now();
      (function tick() {
        if (window.wco && typeof window.wco.submit === 'function') {
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

  function fetchEmbedSession(method) {
    method = method === 'bank' ? 'bank' : 'card';
    var body = new FormData();
    body.append('action', 'sa_whop_start_embed_verify');
    body.append('nonce', cfg.nonce || '');
    body.append('method', method);
    return fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
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
            (json && json.data && json.data.message) || i18n('error', 'error')
          );
        }
        json.data.method = method;
        return json.data;
      });
  }

  function warmEmbedSession(method) {
    method = method === 'bank' ? 'bank' : 'card';
    if (warmPromiseByMethod[method]) return warmPromiseByMethod[method];
    warmPromiseByMethod[method] = fetchEmbedSession(method)
      .then(function (data) {
        warmByMethod[method] = data;
        return data;
      })
      .catch(function () {
        warmPromiseByMethod[method] = null;
        warmByMethod[method] = null;
        return null;
      });
    return warmPromiseByMethod[method];
  }

  function mountEmbed(data) {
    if (!mount || !data || !data.plan_id) return;
    ensureWhopIndex();
    clearMount();
    mountGeneration += 1;
    var gen = mountGeneration;
    var el = buildCheckoutEl(data, gen);
    mount.appendChild(el);
    checkoutEl = el;
    embedReady = false;
    setStatus(i18n('loadingForm', 'Loading secure form…'), 'loading');
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

  function startSession(method) {
    if (busy) return;
    method = method === 'bank' ? 'bank' : 'card';
    busy = true;
    applyMethodChrome(method);
    addBtns.forEach(function (b) {
      b.disabled = true;
    });
    ensureWhopIndex();
    openPanel();
    setStatus(i18n('loadingForm', 'Loading secure form…'), 'loading');

    var useWarm = warmByMethod[method];
    warmByMethod[method] = null;
    var pending = useWarm
      ? Promise.resolve(useWarm)
      : warmPromiseByMethod[method] || fetchEmbedSession(method);

    pending
      .then(function (data) {
        if (!data || !data.plan_id) return fetchEmbedSession(method);
        return data;
      })
      .then(function (data) {
        mountEmbed(data);
        busy = false;
        addBtns.forEach(function (b) {
          b.disabled = false;
        });
        warmPromiseByMethod[method] = null;
        // Pre-warm the *other* method only; avoid stacking stale same-method sessions.
        window.setTimeout(function () {
          var other = method === 'bank' ? 'card' : 'bank';
          warmEmbedSession(other);
        }, 1500);
      })
      .catch(function (err) {
        setStatus(
          (err && err.message) || i18n('error', 'Could not start.'),
          'error'
        );
        busy = false;
        addBtns.forEach(function (b) {
          b.disabled = false;
        });
        warmPromiseByMethod[method] = null;
      });
  }

  function resolveEmbedId() {
    if (checkoutEl && checkoutEl.id) {
      return String(checkoutEl.id);
    }
    var el = mount && mount.querySelector('[id^="sa-whop-pm-checkout"]');
    if (el && el.id) {
      checkoutEl = el;
      return String(el.id);
    }
    return '';
  }

  function unhideNativeSubmit() {
    if (!checkoutEl) return;
    try {
      checkoutEl.removeAttribute('data-whop-checkout-hide-submit-button');
      // Nudge Whop to refresh chrome so native Pay appears as fallback.
      checkoutEl.setAttribute('data-whop-checkout-hide-submit-button', 'false');
    } catch (e) {}
  }

  function submitCheckout() {
    clearSubmitWatchdog();
    var embedId = resolveEmbedId();
    if (!embedId || typeof embedId !== 'string') {
      setStatus(
        i18n('notReady', 'Form not ready yet. Wait a moment and try again.'),
        'error'
      );
      setVerifyEnabled(true);
      return;
    }

    setStatus(i18n('syncing', 'Verifying…'), 'loading');
    if (verifyBtn) verifyBtn.disabled = true;

    waitForWco(6000)
      .then(function (wco) {
        if (!embedIsRegistered(embedId) && !embedReady) {
          throw new Error(
            i18n('notReady', 'Form not ready yet. Wait a moment and try again.')
          );
        }
        // Docs: wco.submit("element-id-string") — never a DOM node.
        var ret = wco.submit(String(embedId));
        if (ret && typeof ret.then === 'function') {
          return ret;
        }
        return undefined;
      })
      .then(function () {
        // Submit accepted by embed; completion arrives via saWhopPmComplete.
        // Watchdog covers silent validation / hung iframe cases.
        submitWatchdog = window.setTimeout(function () {
          setStatus(
            i18n(
              'checkForm',
              'Still waiting. Check the form for missing fields, then try Verify again — or use the Pay button inside the form.'
            ),
            'error'
          );
          if (verifyBtn) verifyBtn.disabled = false;
          unhideNativeSubmit();
        }, 12000);
      })
      .catch(function (err) {
        var msg =
          (err && err.message) ||
          i18n('error', 'Verification failed. Please try again.');
        // Common Whop miss after remount — remount fresh session once.
        if (/No embed with identifier/i.test(String(msg))) {
          setStatus(
            i18n('reloadForm', 'Form lost connection. Reloading…'),
            'loading'
          );
          fetchEmbedSession(activeMethod)
            .then(function (data) {
              mountEmbed(data);
              setStatus(
                i18n('tryAgain', 'Form reloaded. Fill details and tap Verify.'),
                'error'
              );
            })
            .catch(function (e2) {
              setStatus((e2 && e2.message) || msg, 'error');
              if (verifyBtn) verifyBtn.disabled = false;
            });
          return;
        }
        setStatus(msg, 'error');
        if (verifyBtn) verifyBtn.disabled = false;
        unhideNativeSubmit();
      });
  }

  window.saWhopPmComplete = function (planId, receiptId) {
    clearSubmitWatchdog();
    setStatus(i18n('syncing', 'Saving…'), 'loading');
    if (verifyBtn) verifyBtn.disabled = true;
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
        setStatus(i18n('success', 'Saved.'), 'success');
        window.setTimeout(function () {
          var url = new URL(window.location.href);
          url.searchParams.delete('sa_whop_embed');
          url.searchParams.delete('sa_whop_method');
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
    clearSubmitWatchdog();
    var msg =
      (error && (error.message || error.code)) ||
      'Payment failed. Please try another method.';
    setStatus(String(msg), 'error');
    if (verifyBtn) verifyBtn.disabled = false;
  };

  window.saWhopPmStateChange = function (state) {
    // Surface actionable states so Verify never feels like a dead click.
    if (!state) return;
    var s = String(state);
    if (s === 'error' || s === 'failed') {
      clearSubmitWatchdog();
      setStatus(
        i18n('error', 'Something went wrong in the form. Please try again.'),
        'error'
      );
      if (verifyBtn) verifyBtn.disabled = false;
    } else if (s === 'needs_info' || s === 'requires_action') {
      clearSubmitWatchdog();
      setStatus(
        i18n('checkForm', 'Complete the highlighted fields, then tap Verify.'),
        'error'
      );
      if (verifyBtn) verifyBtn.disabled = false;
    }
  };

  function bind() {
    panel = $('#sa-whop-embed');
    mount = $('#sa-whop-pm-embed');
    statusEl = $('#sa-whop-embed-status');
    feeEl = $('#sa-whop-embed-fee');
    titleEl = $('#sa-whop-embed-title');
    verifyBtn = $('#sa-whop-embed-verify');
    if (!panel || !mount) return;

    addBtns = Array.prototype.slice.call(
      document.querySelectorAll('[data-sa-whop-add-card],[data-sa-whop-add-bank]')
    );
    addBtns.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var method =
          btn.getAttribute('data-sa-whop-method') ||
          (btn.hasAttribute('data-sa-whop-add-bank') ? 'bank' : 'card');
        startSession(method);
      });
    });

    var cancel = $('#sa-whop-embed-cancel');
    if (cancel) {
      cancel.addEventListener('click', function (e) {
        e.preventDefault();
        closePanel();
      });
    }
    if (verifyBtn) {
      verifyBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        submitCheckout();
      });
    }

    preloadWhopAssets();
    ensureWhopIndex();

    if (cfg.warm && cfg.warm.plan_id) {
      var wm = cfg.warm.method === 'bank' ? 'bank' : 'card';
      warmByMethod[wm] = {
        plan_id: cfg.warm.plan_id,
        session_id: cfg.warm.session_id || '',
        return_url: cfg.warm.return_url || cfg.returnUrl || '',
        email: cfg.warm.email || cfg.email || '',
        fee: cfg.warm.fee,
        method: wm,
      };
      warmPromiseByMethod[wm] = Promise.resolve(warmByMethod[wm]);
    } else {
      warmEmbedSession('card');
    }
    window.setTimeout(function () {
      warmEmbedSession('bank');
    }, 800);

    if (cfg.autoOpen) {
      var m =
        (panel.getAttribute('data-sa-whop-method') || 'card') === 'bank'
          ? 'bank'
          : 'card';
      startSession(m);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
