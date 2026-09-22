/**
 * My Account — embedded Whop verify checkout (Add card / bank).
 * Mounts Whop loader iframe on-page; never redirects the top window to whop.com.
 */
(function () {
  'use strict';

  var cfg = window.saWhopPmEmbed || {};
  var panel = null;
  var mount = null;
  var statusEl = null;
  var addBtns = [];
  var busy = false;

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
    mount.innerHTML = '';
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
      'data-whop-checkout-style-container-padding-x',
      'data-whop-checkout-style-container-padding-y',
    ].forEach(function (a) {
      mount.removeAttribute(a);
    });
  }

  function mountEmbed(data) {
    if (!mount) return;
    clearMount();
    mount.setAttribute('data-whop-checkout-plan-id', data.plan_id);
    mount.setAttribute('data-whop-checkout-session', data.session_id);
    mount.setAttribute('data-whop-checkout-return-url', data.return_url || cfg.returnUrl || '');
    mount.setAttribute('data-whop-checkout-theme', 'dark');
    mount.setAttribute('data-whop-checkout-theme-accent-color', 'amber');
    mount.setAttribute('data-whop-checkout-theme-background-color', '#0B0B0D');
    mount.setAttribute('data-whop-checkout-setup-future-usage', 'off_session');
    mount.setAttribute('data-whop-checkout-skip-redirect', 'true');
    mount.setAttribute('data-whop-checkout-on-complete', 'saWhopPmComplete');
    mount.setAttribute('data-whop-checkout-on-payment-error', 'saWhopPmPaymentError');
    if (data.email || cfg.email) {
      mount.setAttribute('data-whop-checkout-prefill-email', data.email || cfg.email);
      mount.setAttribute('data-whop-checkout-hide-email', 'true');
    }
    mount.setAttribute('data-whop-checkout-style-container-padding-x', '0');
    mount.setAttribute('data-whop-checkout-style-container-padding-y', '8');
    // Nudge loader to (re)scan if already loaded.
    if (window.wco && typeof window.wco.loadCheckouts === 'function') {
      try {
        window.wco.loadCheckouts();
      } catch (e) {}
    }
  }

  function openPanel() {
    if (!panel) return;
    show(panel, true);
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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

  function startSession(prefetched) {
    if (busy) return;
    busy = true;
    addBtns.forEach(function (b) {
      b.disabled = true;
    });
    openPanel();
    setStatus((cfg.i18n && cfg.i18n.starting) || 'Preparing…', 'loading');

    function apply(data) {
      var emailEl = $('#sa-whop-embed-email');
      if (emailEl && (data.email || cfg.email)) {
        emailEl.textContent = data.email || cfg.email;
      }
      mountEmbed(data);
      setStatus('');
      busy = false;
      addBtns.forEach(function (b) {
        b.disabled = false;
      });
    }

    if (prefetched && prefetched.plan_id && prefetched.session_id) {
      apply(prefetched);
      return;
    }

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
        apply(json.data);
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
        // Hard fallback: full-page flow that still stays on-site (?sa_whop_embed=1).
        if (cfg.fallbackAdd) {
          window.setTimeout(function () {
            window.location.href = cfg.fallbackAdd;
          }, 1200);
        }
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
        // Still reload via embed_done so server can sync.
        var url = new URL(window.location.href);
        url.searchParams.set('sa_whop_embed_done', '1');
        window.location.href = url.toString();
      });
  };

  window.saWhopPmPaymentError = function (error) {
    var msg =
      (error && (error.message || error.code)) ||
      'Payment failed. Please try another card or bank.';
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
        startSession(null);
      });
    });

    var cancel = $('#sa-whop-embed-cancel');
    if (cancel) {
      cancel.addEventListener('click', function (e) {
        e.preventDefault();
        closePanel();
      });
    }

    if (cfg.autoOpen) {
      var pref = null;
      if (cfg.planId && cfg.sessionId) {
        pref = {
          plan_id: cfg.planId,
          session_id: cfg.sessionId,
          return_url: cfg.returnUrl,
          email: cfg.email,
        };
      }
      startSession(pref);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
