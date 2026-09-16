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

})();
