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


  function saScheduleCartUpdate() {
    var form = document.querySelector('form.woocommerce-cart-form');
    if (!form) return;
    var btn = form.querySelector('button[name="update_cart"]');
    if (!btn) return;
    btn.disabled = false;
    clearTimeout(window.__saCartUpdateT);
    window.__saCartUpdateT = setTimeout(function () {
      if (typeof btn.click === 'function') btn.click();
    }, 350);
  }

  function saCheckoutQtyUpdate(cartKey, qty) {
    var cfg = window.saTheme || {};
    var url = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
    var body = new FormData();
    body.append('action', 'sa_checkout_update_qty');
    body.append('nonce', cfg.checkoutQtyNonce || '');
    body.append('cart_key', cartKey);
    body.append('qty', String(qty));
    document.documentElement.classList.add('sa-checkout-qty-busy');
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        document.documentElement.classList.remove('sa-checkout-qty-busy');
        if (!json || !json.success) {
          var msg = (json && json.data && json.data.message) || (cfg.i18n && cfg.i18n.qtyUpdateFailed) || 'Update failed';
          if (window.console) console.warn('[sa] checkout qty', msg);
          return;
        }
        if (json.data && json.data.empty && json.data.redirect) {
          window.location.href = json.data.redirect;
          return;
        }
        if (window.jQuery) {
          jQuery(document.body).trigger('update_checkout');
        }
      })
      .catch(function () {
        document.documentElement.classList.remove('sa-checkout-qty-busy');
      });
  }

  function saScheduleCheckoutQty(wrap) {
    var key = wrap.getAttribute('data-sa-checkout-qty');
    var input = wrap.querySelector('input.qty');
    if (!key || !input) return;
    clearTimeout(wrap.__saQtyT);
    wrap.__saQtyT = setTimeout(function () {
      var q = parseFloat(input.value);
      if (isNaN(q)) q = 1;
      saCheckoutQtyUpdate(key, q);
    }, 280);
  }

  function bindQty(root) {
    (root || document).querySelectorAll('[data-sa-qty]').forEach(function (wrap) {
      if (wrap.dataset.saQtyBound) return;
      wrap.dataset.saQtyBound = '1';
      var input = wrap.querySelector('input.qty');
      if (!input) return;
      var isCheckout = !!wrap.getAttribute('data-sa-checkout-qty');
      var minus = wrap.querySelector('[data-sa-qty-minus]');
      var plus = wrap.querySelector('[data-sa-qty-plus]');
      function afterChange() {
        if (isCheckout) saScheduleCheckoutQty(wrap);
        else saScheduleCartUpdate();
      }
      if (minus) {
        minus.addEventListener('click', function () {
          var v = parseFloat(input.value) || 0;
          var min = parseFloat(input.min);
          if (isNaN(min)) min = 0;
          input.value = Math.max(min, v - 1);
          input.dispatchEvent(new Event('change', { bubbles: true }));
          afterChange();
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
          afterChange();
        });
      }
      if (isCheckout) {
        input.addEventListener('change', function () {
          saScheduleCheckoutQty(wrap);
        });
      }
    });
  }
  bindQty(document);
  if (window.jQuery) {
    jQuery(document.body).on('updated_cart_totals updated_wc_div updated_checkout', function () {
      bindQty(document);
    });
  }

  // Part enquire — build WhatsApp / Email / SMS links from form fields
  function saEnquireCollect(form) {
    var get = function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      return el ? String(el.value || '').trim() : '';
    };
    return {
      product: get('product'),
      car: get('car'),
      brand: get('brand'),
      year: get('year'),
      notes: get('notes')
    };
  }

  function saEnquireValid(fields) {
    return !!(fields.product && fields.car && fields.year);
  }

  function saEnquireBody(fields) {
    var lines = [
      'Hi Supreme Autoparts — I am looking for a part:',
      '',
      'Product: ' + fields.product,
      'Car / model: ' + fields.car,
      'Brand: ' + (fields.brand || '—'),
      'Year: ' + fields.year
    ];
    if (fields.notes) lines.push('Notes: ' + fields.notes);
    lines.push('');
    lines.push('Please let me know availability and price. Thank you.');
    return lines.join('\n');
  }

  function saEnquireReadConfig(form) {
    var node = form.querySelector('[data-sa-enquire-config]');
    if (!node) return { whatsapp: '254714498451', phone: '+254714498451', email: 'calvin@supremeautoparts.co.ke', subject: 'Part enquiry — Supreme Autoparts' };
    try { return JSON.parse(node.textContent || '{}'); } catch (e) {
      return { whatsapp: '254714498451', phone: '+254714498451', email: 'calvin@supremeautoparts.co.ke', subject: 'Part enquiry — Supreme Autoparts' };
    }
  }

  function saEnquireMarkInvalid(form, fields) {
    ['product', 'car', 'year'].forEach(function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      if (!el) return;
      if (!fields[name]) el.classList.add('is-invalid');
      else el.classList.remove('is-invalid');
    });
  }

  document.querySelectorAll('[data-sa-enquire]').forEach(function (root) {
    var form = root.querySelector('[data-sa-enquire-form]');
    if (!form) return;
    var err = form.querySelector('[data-sa-enquire-error]');
    var cfg = saEnquireReadConfig(form);

    form.querySelectorAll('[data-sa-enquire-channel]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        var fields = saEnquireCollect(form);
        if (!saEnquireValid(fields)) {
          e.preventDefault();
          saEnquireMarkInvalid(form, fields);
          if (err) err.hidden = false;
          var first = form.querySelector('.is-invalid');
          if (first) first.focus();
          return;
        }
        saEnquireMarkInvalid(form, fields);
        if (err) err.hidden = true;

        var body = saEnquireBody(fields);
        var channel = btn.getAttribute('data-sa-enquire-channel');
        if (channel === 'whatsapp') {
          btn.href = 'https://wa.me/' + cfg.whatsapp + '?text=' + encodeURIComponent(body);
        } else if (channel === 'email') {
          var emailBody = body + '\n\n—\nSMS / WhatsApp: ' + cfg.phone;
          btn.href = 'mailto:' + cfg.email
            + '?subject=' + encodeURIComponent(cfg.subject || 'Part enquiry — Supreme Autoparts')
            + '&body=' + encodeURIComponent(emailBody);
        } else if (channel === 'sms') {
          // iOS uses &body=, Android often accepts ?body=
          var smsBody = encodeURIComponent(body);
          btn.href = 'sms:' + cfg.phone + '?body=' + smsBody;
        }
      });
    });

    form.addEventListener('input', function () {
      if (err) err.hidden = true;
      form.querySelectorAll('.is-invalid').forEach(function (el) {
        el.classList.remove('is-invalid');
      });
    });
  });



  // Checkout: block Place order until terms checkbox is ticked; Remember me default.
  function saSyncCheckoutTerms() {
    var form = document.querySelector('form.checkout, form.woocommerce-checkout');
    if (!form) return;
    var box = form.querySelector('[data-sa-terms-checkbox], #terms');
    var btn = form.querySelector('#place_order');
    var hint = form.querySelector('[data-sa-terms-hint]');
    if (!box || !btn) return;

    function sync() {
      var ok = !!box.checked;
      btn.disabled = !ok;
      btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
      if (hint) hint.hidden = ok;
    }

    sync();
    box.addEventListener('change', sync);
    // Woo updates payment fragment via AJAX — rebind after refresh.
    if (window.jQuery) {
      jQuery(document.body).on('updated_checkout', function () {
        box = form.querySelector('[data-sa-terms-checkbox], #terms') || document.querySelector('[data-sa-terms-checkbox], #terms');
        btn = document.querySelector('#place_order');
        hint = document.querySelector('[data-sa-terms-hint]');
        if (box && btn) {
          box.removeEventListener('change', sync);
          box.addEventListener('change', sync);
          sync();
        }
      });
    }

    form.addEventListener('submit', function (e) {
      var current = form.querySelector('[data-sa-terms-checkbox], #terms');
      if (current && !current.checked) {
        e.preventDefault();
        e.stopPropagation();
        if (hint) hint.hidden = false;
        current.focus();
        var msg = (window.saTheme && saTheme.i18n && saTheme.i18n.termsRequired)
          ? saTheme.i18n.termsRequired
          : 'Please accept the store policies to place your order.';
        if (window.jQuery && jQuery.fn && document.body) {
          // Soft notice without fighting Woo validation.
        }
        return false;
      }
    }, true);
  }

  function saEnsureRememberMe() {
    document.querySelectorAll('input[name="rememberme"]').forEach(function (el) {
      if (el.hasAttribute('data-sa-remember-default') || !el.checked) {
        el.checked = true;
      }
    });
  }


  // Checkout: highlight steps from visible panels; mobile sticky Place order.
  function saCheckoutSteps() {
    var steps = document.querySelectorAll('[data-sa-checkout-steps] [data-sa-step]');
    var panels = document.querySelectorAll('[data-sa-checkout-panel]');
    if (!steps.length || !panels.length || !('IntersectionObserver' in window)) return;

    var order = ['contact', 'shipping', 'payment'];
    var visible = { contact: false, shipping: false, payment: false };

    function paint() {
      var active = 'contact';
      for (var i = order.length - 1; i >= 0; i--) {
        if (visible[order[i]]) { active = order[i]; break; }
      }
      var activeIdx = order.indexOf(active);
      steps.forEach(function (el) {
        var key = el.getAttribute('data-sa-step');
        var idx = order.indexOf(key);
        el.classList.toggle('is-active', key === active);
        el.classList.toggle('is-done', idx > -1 && idx < activeIdx);
      });
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var key = entry.target.getAttribute('data-sa-checkout-panel');
        if (!key) return;
        visible[key] = entry.isIntersecting && entry.intersectionRatio > 0.2;
      });
      paint();
    }, { rootMargin: '-20% 0px -45% 0px', threshold: [0, 0.2, 0.5, 1] });

    panels.forEach(function (p) { io.observe(p); });
  }

  function saCheckoutStickyPay() {
    var bar = document.querySelector('[data-sa-checkout-sticky]');
    if (!bar) return;
    var btn = bar.querySelector('[data-sa-checkout-sticky-pay]');
    var totalEl = bar.querySelector('[data-sa-checkout-sticky-total]');
    var mq = window.matchMedia('(max-width: 960px)');

    function sync() {
      var place = document.querySelector('#place_order');
      var orderTotal = document.querySelector('.sa-checkout-review-table .order-total td, .order-total td');
      if (mq.matches) {
        bar.hidden = false;
      } else {
        bar.hidden = true;
      }
      if (place && btn) {
        btn.disabled = !!place.disabled;
        btn.setAttribute('aria-disabled', place.disabled ? 'true' : 'false');
        var label = place.getAttribute('data-value') || place.textContent || 'Place order';
        btn.textContent = label.trim();
      }
      if (totalEl && orderTotal) {
        totalEl.innerHTML = orderTotal.innerHTML;
      }
    }

    if (btn) {
      btn.addEventListener('click', function () {
        var place = document.querySelector('#place_order');
        if (!place) return;
        if (place.disabled) {
          var terms = document.querySelector('[data-sa-terms-checkbox], #terms');
          var hint = document.querySelector('[data-sa-terms-hint]');
          if (hint) hint.hidden = false;
          if (terms) {
            terms.focus();
            terms.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          return;
        }
        place.click();
      });
    }

    sync();
    if (mq.addEventListener) mq.addEventListener('change', sync);
    else if (mq.addListener) mq.addListener(sync);

    if (window.jQuery) {
      jQuery(document.body).on('updated_checkout', sync);
    }
    // Observe place_order disabled attribute changes (terms tick).
    var place = document.querySelector('#place_order');
    if (place && window.MutationObserver) {
      new MutationObserver(sync).observe(place, { attributes: true, attributeFilter: ['disabled', 'aria-disabled', 'data-value'] });
    }
  }

  saCheckoutSteps();
  saCheckoutStickyPay();

  saSyncCheckoutTerms();
  saEnsureRememberMe();
  if (window.jQuery) {
    jQuery(document.body).on('updated_checkout', function () {
      saSyncCheckoutTerms();
      saEnsureRememberMe();
    });
  }

})();
