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

  function saCloseAdded() {
    var layer = document.querySelector('[data-sa-added]');
    if (!layer) return;
    layer.hidden = true;
    document.body.classList.remove('sa-added-open');
  }

  function saOpenAdded(html) {
    var layer = document.querySelector('[data-sa-added]');
    if (!layer) {
      layer = document.createElement('div');
      layer.className = 'sa-added';
      layer.setAttribute('data-sa-added', '');
      layer.hidden = true;
      document.body.appendChild(layer);
      layer.addEventListener('click', function (e) {
        if (e.target === layer || (e.target.closest && e.target.closest('[data-sa-added-close]'))) {
          saCloseAdded();
        }
      });
    }
    layer.innerHTML = html;
    layer.hidden = false;
    document.body.classList.add('sa-added-open');
    var go = layer.querySelector('.sa-added__go');
    if (go) go.focus();
  }

  function saFetchAdded(productId) {
    var cfg = window.saTheme || {};
    var url = (cfg.ajaxUrl || '/wp-admin/admin-ajax.php') + '?action=sa_added_sheet&product_id=' + encodeURIComponent(productId);
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success && json.data && json.data.html) {
          saOpenAdded(json.data.html);
        }
      })
      .catch(function () {});
  }

  if (window.jQuery) {
    jQuery(document.body).on('added_to_cart', function (e, fragments, hash, $button) {
      var id = $button && $button.data ? $button.data('product_id') : 0;
      if (id) saFetchAdded(id);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') saCloseAdded();
  });

  try {
    var addedParams = new URLSearchParams(window.location.search);
    var addedId = addedParams.get('added-to-cart');
    if (addedId && addedParams.get('sa_buy_now') !== '1') {
      saFetchAdded(addedId);
    }
  } catch (err) {}

  document.querySelectorAll('[data-sa-filters-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var box = btn.closest('.sa-filters');
      if (!box) return;
      var open = box.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });


  function saScheduleCartUpdate() {
    var form = document.querySelector('form.woocommerce-cart-form');
    if (!form) return;
    var btn = form.querySelector('button[name="update_cart"]');
    if (!btn) return;
    btn.disabled = false;
    var cart=document.querySelector('.sa-cart-page');if(cart){cart.dataset.saCartBusy='true';cart.querySelectorAll('a.checkout-button,.sa-cart-line__buy').forEach(function(a){a.setAttribute('aria-disabled','true');});var status=cart.querySelector('.sa-cart-update-status');if(status)status.hidden=false;}
    clearTimeout(window.__saCartUpdateT);
    window.__saCartUpdateT = setTimeout(function () {
      if (typeof btn.click === 'function') btn.click();
    }, 350);
  }

  // Hold payment from the first quantity edit, including its debounce window.
  // Serialize writes so an older response cannot overwrite a newer quantity.
  var saCheckoutQtyState = { pending: 0, requests: 0, failed: false, queue: Promise.resolve() };
  function saLockCheckoutQty() {
    document.documentElement.classList.add('sa-checkout-qty-busy');
    var form = document.querySelector('form.checkout');
    if (!form) return;
    form.dataset.saTotalsBusy = 'true';
    var pay = form.querySelector('#place_order');
    if (pay) { pay.disabled = true; pay.setAttribute('aria-disabled', 'true'); }
    var recovery = form.querySelector('.sa-checkout-recovery');
    if (recovery) recovery.hidden = false;
  }
  function saFailCheckoutQty() {
    saCheckoutQtyState.failed = true;
    var form = document.querySelector('form.checkout');
    if (form) {
      form.dataset.saQtyFailed = 'true';
      var status = form.querySelector('.sa-checkout-recovery p');
      if (status) status.textContent = "We couldn't update the quantity. Reload checkout before paying.";
    }
    saLockCheckoutQty();
  }
  function saCheckoutQtyUpdate(cartKey, qty) {
    var cfg = window.saTheme || {};
    var url = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
    saLockCheckoutQty();
    saCheckoutQtyState.requests++;
    saCheckoutQtyState.queue = saCheckoutQtyState.queue.then(function () {
      if (saCheckoutQtyState.failed) return;
      var body = new FormData();
      body.append('action', 'sa_checkout_update_qty');
      body.append('nonce', cfg.checkoutQtyNonce || '');
      body.append('cart_key', cartKey);
      body.append('qty', String(qty));
      return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('Quantity update failed'); return r.json(); })
        .then(function (json) {
          if (!json || !json.success) { saFailCheckoutQty(); return; }
          if (json.data && json.data.empty && json.data.redirect) {
            window.location.href = json.data.redirect;
          }
        }).catch(saFailCheckoutQty);
    }).then(function () {
      saCheckoutQtyState.requests--;
      if (saCheckoutQtyState.failed || saCheckoutQtyState.pending || saCheckoutQtyState.requests) return;
      // Release only the quantity-write lock. The totals lock remains until Woo
      // confirms a successful authoritative update_checkout response.
      if (window.jQuery) {
        document.documentElement.classList.remove('sa-checkout-qty-busy');
        jQuery(document.body).trigger('update_checkout');
      } else saFailCheckoutQty();
    });
    return saCheckoutQtyState.queue;
  }

  function saScheduleCheckoutQty(wrap) {
    var key = wrap.getAttribute('data-sa-checkout-qty');
    var input = wrap.querySelector('input.qty');
    if (!key || !input) return;
    saLockCheckoutQty();
    if (!wrap.__saQtyPending) { wrap.__saQtyPending = true; saCheckoutQtyState.pending++; }
    clearTimeout(wrap.__saQtyT);
    wrap.__saQtyT = setTimeout(function () {
      wrap.__saQtyPending = false;
      saCheckoutQtyState.pending--;
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
    if (!node) return { whatsapp: 'supremeautopart', phone: '+19174375121', email: 'calvin@supremeautoparts.co.ke', subject: 'Part enquiry — Supreme Autoparts' };
    try { return JSON.parse(node.textContent || '{}'); } catch (e) {
      return { whatsapp: 'supremeautopart', phone: '+19174375121', email: 'calvin@supremeautoparts.co.ke', subject: 'Part enquiry — Supreme Autoparts' };
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
    if (!box || !btn || form.dataset.saTermsBound) return;
    form.dataset.saTermsBound='true';
    var recovery=document.createElement('div');recovery.className='sa-checkout-recovery';recovery.hidden=true;
    var status=document.createElement('p');status.textContent='Updating your order total. Please wait before placing your order.';
    var reload=document.createElement('button');reload.type='button';reload.textContent='Reload checkout';reload.addEventListener('click',function(){window.location.reload();});recovery.append(status,reload);(form.querySelector('.sa-checkout-final')||form).prepend(recovery);


    function sync() {
      var station=form.querySelector('#sa_pickup_station');
      var stationOK=!station||!station.required||(!station.disabled&&!!station.value);
      var addressOK=!form.classList.contains('sa-checkout-onepage')||form.dataset.saAddressReady==='true';
      var ok = addressOK && !!box.checked && stationOK && form.dataset.saTotalsBusy!=='true' && !document.documentElement.classList.contains('sa-checkout-qty-busy');
      btn.disabled = !ok;
      recovery.hidden=form.dataset.saTotalsBusy!=='true';
      btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
      var failed = form.dataset.saQtyFailed==='true' || form.dataset.saTotalsFailed==='true';
      var failMsg = "We couldn't update the order total. Reload checkout before continuing.";
      status.textContent = failed ? failMsg : 'Updating your order total. Please wait before placing your order.';
      if (hint) {
        var busy = form.dataset.saTotalsBusy==='true' || document.documentElement.classList.contains('sa-checkout-qty-busy');
        hint.textContent = failed ? failMsg
          : !addressOK ? 'Confirm your address and delivery option above to continue.'
          : !stationOK ? 'Choose a pickup station above to continue.'
          : busy ? 'Updating your order total. Please wait.'
          : 'Accept the terms above to enable Pay.';
        hint.hidden = ok;
      }
    }

    sync();
    if(window.MutationObserver)new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['class']});
    box.addEventListener('change', sync);
    form.addEventListener('sa:addresschange',sync);
    form.addEventListener('change',function(e){if(e.target&&/sa_pickup_station|shipping_method/.test(e.target.name||''))sync();});
    // Woo updates payment fragment via AJAX — rebind after refresh.
    if (window.jQuery) {
      jQuery(document.body).on('update_checkout', function () { form.dataset.saTotalsBusy='true';sync(); });
      // Woo's update_order_review request has no error callback. Show a reload message when the
      // newest totals request fails at transport level or returns a non-success body. The lock stays
      // closed; an intentional abort or an older superseded request is ignored.
      var latestTotals = null;
      var isTotals = function (o) { return !!o && typeof o.url === 'string' && o.url.indexOf('update_order_review') !== -1; };
      jQuery(document).on('ajaxSend', function (e, xhr, o) {
        if (!isTotals(o)) return;
        latestTotals = xhr; form.dataset.saTotalsFailed = 'false'; sync();
      });
      jQuery(document).on('ajaxComplete', function (e, xhr, o) {
        if (!isTotals(o) || xhr !== latestTotals || xhr.statusText === 'abort') return;
        // A 200 with result 'failure' is Woo's own validation answer: its messages are shown and the
        // customer can correct the input, so only transport or unparseable responses count here.
        var r = xhr.responseJSON && xhr.responseJSON.result;
        var ok = xhr.status === 200 && (r === 'success' || r === 'failure');
        if (!ok) { form.dataset.saTotalsFailed = 'true'; form.dataset.saTotalsBusy = 'true'; sync(); }
      });
      jQuery(document.body).on('updated_checkout', function (event, data) {
        // Woo reports result 'failure' whenever any notice was printed, even a success notice such as
        // "Coupon code applied successfully". Its fragments are still the authoritative totals, so only a
        // response whose notices are all recognised plain success/info notices counts. Real validation errors keep the lock until a later update succeeds.
        var authoritative = !!data && !!data.fragments && data.reload !== true && (data.result === 'success' ||
          (data.result === 'failure' && data.has_errors !== true && (data.has_errors === false ||
            (typeof data.messages === 'string' && data.messages.trim() !== '' &&
              data.messages.replace(/<div class="woocommerce-(?:message|info)" role="alert">[^<]*<\/div>/g, '').trim() === ''))));
        if(data&&data.result==='success'&&!data.fragments)authoritative=true;
        if(authoritative&&!document.documentElement.classList.contains('sa-checkout-qty-busy')&&form.dataset.saQtyFailed!=='true')form.dataset.saTotalsBusy='false';
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
      if(form.dataset.saTotalsBusy==='true'||document.documentElement.classList.contains('sa-checkout-qty-busy')){e.preventDefault();e.stopImmediatePropagation();return false;}
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


  // Google-inspired cart/checkout hierarchy, using the existing WooCommerce controls.
  // No prices, gateways, shipping rules or account data are changed here.
  function saCheckoutExperience() {
    var form=document.querySelector('form.checkout'),layout=document.querySelector('.sa-checkout-layout');
    if(!form||!layout)return;
    var main=layout.querySelector('.sa-checkout-layout__main');
    document.body.classList.add('sa-purchase-workspace');
    var payment=layout.querySelector('[data-sa-checkout-panel="payment"]');
    var shipping=layout.querySelector('[data-sa-checkout-panel="shipping"] .sa-checkout-section__body');
    if(!main||!payment||!shipping)return;
    var delivery=document.createElement('div');delivery.className='sa-delivery-options';var notes=shipping.querySelector('.woocommerce-additional-fields');shipping.insertBefore(delivery,notes);
    var station=document.getElementById('sa_pickup_station_field');if(station)shipping.insertBefore(station,notes);
    var comments=shipping.querySelector('#order_comments_field');if(comments&&!comments.classList.contains('validate-required')){var extra=document.createElement('details');extra.className='sa-checkout-extras';var toggle=document.createElement('summary');toggle.textContent='Add order notes (optional)';extra.appendChild(toggle);comments.replaceWith(extra);extra.appendChild(comments);}
    var final=document.createElement('div');final.className='sa-checkout-final';(layout.querySelector('.sa-checkout-layout__summary')||layout).appendChild(final);
    main.appendChild(payment);
    form.classList.add('sa-checkout-refreshed');
    function field(id){var el=document.getElementById(id);return el?String(el.value||'').trim():'';}
    function selectedLabel(root){var input=root.querySelector('input.shipping_method:checked, input.shipping_method[type="hidden"]');var li=input&&input.closest('li');if(!li)return '';var name=li.querySelector('.sa-ship__name'),price=li.querySelector('.sa-ship__price');if(!name)return li.textContent.replace(/\s+/g,' ').trim();var copy=name.cloneNode(true),sub=copy.querySelector('small');if(sub)sub.remove();var title=copy.textContent.replace(' · Estimated delivery 1-2 working days from order, excluding weekends','').replace(' · Estimated delivery 1-3 working days from order, excluding weekends','').trim();return [title,sub&&sub.textContent.trim(),price&&price.textContent.replace(/\s+/g,' ').trim()].filter(Boolean).join(' · ');}
    function summaries(){
      var different=document.querySelector('#ship-to-different-address-checkbox:checked'),country=field(different?'shipping_country':'billing_country');
      var hint=document.querySelector('[data-sa-checkout-panel="shipping"] .sa-checkout-section__sub');if(hint)hint.textContent=country==='KE'?'Choose a courier or free store pickup.':country==='US'?'Review your US delivery address and shipping option.':'Review your delivery address and shipping options.';
      var contact=document.querySelector('[data-sa-checkout-panel="contact"] .sa-checkout-fold');
      if(contact)contact.textContent=[field('billing_first_name')+' '+field('billing_last_name'),field('billing_email'),field('billing_address_1'),field('billing_city')].map(function(s){return s.trim();}).filter(Boolean).join(' · ');
      var summary=document.querySelector('[data-sa-checkout-panel="shipping"] .sa-checkout-fold');
      if(summary){var station=document.getElementById('sa_pickup_station');summary.textContent=[selectedLabel(delivery),station&&station.value?station.options[station.selectedIndex].textContent:''].filter(Boolean).join(' · ')||'Review your delivery address and shipping options.';}
    }
    function tabs(container){
      var different=document.querySelector('#ship-to-different-address-checkbox:checked');
      var country=field(different?'shipping_country':'billing_country');
      var lists=container.querySelectorAll('ul.woocommerce-shipping-methods,ul#shipping_method');
      if(country!=='KE'||lists.length!==1)return; // Do not combine multiple shipping packages.
      var items=[].slice.call(lists[0].querySelectorAll('li'));
      items.forEach(function(li){var label=li.querySelector('label');if(label&&/^Store pickup\s*[,·]/i.test(label.textContent.trim()))li.dataset.saPickup='true';});
      if(!items.some(function(li){return li.dataset.saPickup;})||!items.some(function(li){return !li.dataset.saPickup;}))return;
      var buttons=document.createElement('div');buttons.className='sa-delivery-tabs';buttons.setAttribute('role','group');buttons.setAttribute('aria-label','Delivery or store pickup');
      var chosen=items.find(function(li){return !!li.querySelector('input:checked');});
      function show(pickup,choose){
        items.forEach(function(li){li.hidden=!!li.dataset.saPickup!==pickup;});
        buttons.querySelectorAll('button').forEach(function(b,i){b.setAttribute('aria-pressed',String(pickup===(i===1)));});
        var estimate=container.querySelector('.sa-delivery-estimate');if(estimate)estimate.hidden=pickup;
        if(choose){var target=items.find(function(li){return !!li.dataset.saPickup===pickup&&li.querySelector('input:not(:disabled)');});var input=target&&target.querySelector('input');if(input&&!input.checked){input.checked=true;input.dispatchEvent(new Event('change',{bubbles:true}));}}
      }
      ['Courier delivery','Store pickup'].forEach(function(text,i){var b=document.createElement('button');b.type='button';b.textContent=text;b.addEventListener('click',function(){show(i===1,true);});buttons.appendChild(b);});
      var card=document.querySelector('[data-sa-checkout-panel="contact"]'),old=document.querySelector('.sa-fulfilment-tabs');if(old)old.remove();buttons.classList.add('sa-fulfilment-tabs');if(card)card.insertBefore(buttons,card.firstChild);else container.insertBefore(buttons,container.firstChild);show(!!(chosen&&chosen.dataset.saPickup),false);
    }
    function moveFinal(fresh){
      var old=final.querySelector('.place-order');
      if(old){
        // Woo restores terms before updated_checkout. Its ID lookup can still
        // see the moved old control, so carry each user's checkbox state forward.
        fresh.querySelectorAll('input[type="checkbox"]').forEach(function(box){var prior=[].slice.call(old.querySelectorAll('input[type="checkbox"]')).find(function(el){return box.name?el.name===box.name:box.id&&el.id===box.id;});if(prior)box.checked=prior.checked;});
        old.remove();
      }
      fresh.hidden=form.dataset.saActiveStep!==undefined&&form.dataset.saActiveStep!=='payment';final.appendChild(fresh);
    }
    function sync(){
      // #payment and the review table are Woo AJAX fragments. Keep only the newest
      // controls, including nonce/terms, and move them, never copy IDs or inputs.
      var fresh=payment.querySelector('#payment .place-order');
      if(fresh)moveFinal(fresh);
      var review=layout.querySelector('#order_review .woocommerce-checkout-review-order-table');if(review)review.dataset.saFlowReady='true';
      var rows=[].slice.call(layout.querySelectorAll('#order_review tr.woocommerce-shipping-totals'));
      var stationField=document.getElementById('sa_pickup_station_field');if(stationField&&delivery.contains(stationField))shipping.appendChild(stationField);
      delivery.replaceChildren();
      if(rows.length){
        var table=document.createElement('table');table.className='sa-delivery-table';var tbody=document.createElement('tbody');table.appendChild(tbody);delivery.appendChild(table);
        rows.forEach(function(row){
          var selected=selectedLabel(row),replacement=document.createElement('tr');replacement.className='sa-shipping-selection';
          var th=document.createElement('th');th.textContent='Delivery';var td=document.createElement('td');td.textContent=selected||'Choose at the delivery step';replacement.appendChild(th);replacement.appendChild(td);
          row.replaceWith(replacement);tbody.appendChild(row);
        });
        delivery.querySelectorAll('.sa-ship__name').forEach(function(name){var node=name.firstChild;if(!node||node.nodeType!==3)return;var parts=node.nodeValue.match(/^(.*?) · (Estimated (?:delivery|arrival|ready).*?)$/);if(!parts)return;node.nodeValue=parts[1];var estimate=document.createElement('small');estimate.className='sa-option-estimate';estimate.textContent=parts[2];name.insertBefore(estimate,node.nextSibling);});
        tabs(delivery);
        delivery.querySelectorAll('input.shipping_method').forEach(function(input){var brand=/fargo/.test(input.value)?'fargo':/g4s/.test(input.value)?'g4s':/speedaf/.test(input.value)?'speedaf':'';var label=input.closest('li').querySelector('label');if(brand&&label){if(/_pickup/.test(input.value)){var title=label.querySelector('.sa-ship__name');if(title&&title.firstChild&&title.firstChild.nodeType===3)title.firstChild.nodeValue=title.firstChild.nodeValue.replace(/pickup at station\s*\([^)]*\)/i,'pickup at station');}var img=document.createElement('img');img.className='sa-carrier-logo';img.src='/wp-content/themes/supreme-autoparts/assets/couriers/'+brand+'.png';img.alt=brand==='g4s'?'G4S':brand==='fargo'?'Fargo Courier':'Speedaf';label.prepend(img);}});
      }
      summaries();
    }
    form.addEventListener('change',summaries);form.addEventListener('sa:stepchange',summaries);
    // Do not clear moved shipping controls on unrelated repeated events. Woo's
    // update replaces its table with new shipping rows before this handler runs.
    function refreshed(){var review=layout.querySelector('#order_review .woocommerce-checkout-review-order-table');if(review&&!review.dataset.saFlowReady)sync();else{var fresh=payment.querySelector('#payment .place-order');if(fresh)moveFinal(fresh);summaries();}}
    sync();
    if(window.jQuery)jQuery(document.body).on('updated_checkout',refreshed);
  }

  // One checkout page: address/fulfilment first, then visibly gated payment.
  // Confirmation is local form state, never an account-address save.
  function saCheckoutOnePage() {
    var form=document.querySelector('form.checkout'),contact=document.querySelector('[data-sa-checkout-panel="contact"]'),shipping=document.querySelector('[data-sa-checkout-panel="shipping"]'),payment=document.querySelector('[data-sa-checkout-panel="payment"]');
    if(!form||!contact||!shipping||!payment)return;
    form.classList.add('sa-checkout-onepage');form.dataset.saAddressReady='false';
    var address=contact.querySelector('.sa-checkout-section__body'),shipBody=shipping.querySelector('.sa-checkout-section__body');
    var details=document.createElement('div');details.className='sa-address-fields';address.replaceWith(details);details.appendChild(address);details.appendChild(shipBody);shipping.remove();
    var fold=document.createElement('div');fold.className='sa-address-confirmed';fold.hidden=true;
    var summary=document.createElement('p'),edit=document.createElement('button');edit.type='button';edit.className='sa-address-edit';edit.textContent='Change';fold.appendChild(summary);edit.hidden=true;contact.querySelector('.sa-checkout-section__head').appendChild(edit);contact.appendChild(fold);
    var confirm=document.createElement('button');confirm.type='button';confirm.className='sa-address-confirm';confirm.textContent='Use this address';details.appendChild(confirm);
    var pickupInfo=document.createElement('div');pickupInfo.className='sa-pickup-context';pickupInfo.hidden=true;contact.querySelector('.sa-checkout-section__head').after(pickupInfo);
    var note=document.createElement('p');note.className='sa-payment-gate-note';note.textContent='Confirm your address and delivery option above to choose payment.';payment.querySelector('.sa-checkout-section__head').appendChild(note);
    function valid(report){
      var fields=[].slice.call(details.querySelectorAll('input,select,textarea'));
      var bad=fields.find(function(e){if(e.disabled||e.type==='hidden'||e.type==='checkbox'||e.type==='radio'||(!e.getClientRects().length&&!(e.tagName==='SELECT'&&e.classList.contains('select2-hidden-accessible')&&e.parentElement.getClientRects().length))||e.closest('.shipping_address')&&!document.querySelector('#ship-to-different-address-checkbox:checked'))return false;var row=e.closest('.form-row'),required=e.required||row&&row.classList.contains('validate-required');return required&&!String(e.value||'').trim()||e.type==='email'&&e.value&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e.value);});
      var station=document.querySelector('#sa_pickup_station');if(station&&station.required&&(station.disabled||!station.value))bad=station;
      if(bad&&report){bad.setAttribute('aria-invalid','true');var row=bad.closest('.form-row');if(row)row.classList.add('woocommerce-invalid');bad.focus();if(bad.reportValidity)bad.reportValidity();}
      return !bad;
    }
    function gate(ready){if(ready)pickupInfo.hidden=true;form.dataset.saAddressReady=String(ready);payment.classList.toggle('sa-payment-locked',!ready);var body=payment.querySelector('.sa-checkout-section__body');body.inert=!ready;body.setAttribute('aria-disabled',String(!ready));note.hidden=ready;form.dispatchEvent(new Event('sa:addresschange'));var pay=form.querySelector('#place_order');if(!ready&&pay){pay.disabled=true;pay.setAttribute('aria-disabled','true');}}
    function reopen(){details.hidden=false;fold.hidden=true;edit.hidden=true;gate(false);title();}
    function title(){var different=document.querySelector('#ship-to-different-address-checkbox:checked'),country=document.getElementById(different?'shipping_country':'billing_country'),pickup=!!document.querySelector('.sa-fulfilment-tabs button:nth-child(2)[aria-pressed="true"]');contact.querySelector('h2').textContent=pickup?'Pickup contact & billing':'Deliver to';confirm.textContent=pickup?'Use these pickup details':'Use this address';var chosen=document.querySelector('input.shipping_method:checked,input.shipping_method[type="hidden"]'),li=chosen&&chosen.closest('li'),label=li&&li.querySelector('label');pickupInfo.hidden=!pickup||form.dataset.saAddressReady==='true';pickupInfo.textContent=pickup&&label?label.innerText.trim():'';}
    confirm.addEventListener('click',function(){if(!valid(true))return;var get=function(id){return (document.getElementById(id)?.value||'').trim();},different=!!document.querySelector('#ship-to-different-address-checkbox:checked'),pickup=!!document.querySelector('.sa-fulfilment-tabs button:nth-child(2)[aria-pressed="true"]');var lines=[(get('billing_first_name')+' '+get('billing_last_name')).trim(),get('billing_email')];var dest=different?'shipping_':'billing_';if(different)lines.push('Deliver to: '+[get('shipping_first_name'),get('shipping_last_name')].filter(Boolean).join(' '));lines.push((pickup?'Billing: ':'')+[get(dest+'address_1'),get(dest+'address_2'),get(dest+'city'),get(dest+'postcode')].filter(Boolean).join(', '));var chosen=document.querySelector('input.shipping_method:checked,input.shipping_method[type="hidden"]'),li=chosen&&chosen.closest('li'),label=li&&li.querySelector('label');if(label){var name=label.querySelector('.sa-ship__name');if(name){var copy=name.cloneNode(true);copy.querySelectorAll('small').forEach(function(x){x.remove();});lines.push(copy.textContent.trim());var estimate=name.querySelector('.sa-option-estimate');if(estimate)lines.push(estimate.textContent);var weight=[].slice.call(name.querySelectorAll('small:not(.sa-option-estimate)')).map(function(x){return x.textContent.trim();}).filter(Boolean).join(' · ');if(weight)lines.push(weight);}else lines.push(label.innerText.replace(/\s+/g,' ').trim());var price=label.querySelector('.sa-ship__price');if(price)lines.push('Delivery: '+price.textContent.trim());}var st=document.querySelector('.sa-station-selected');if(st&&document.querySelector('#sa_pickup_station')?.value)lines.push(st.textContent);summary.textContent=lines.filter(Boolean).join('\n');details.hidden=true;fold.hidden=false;edit.hidden=false;gate(true);payment.querySelector('h2').setAttribute('tabindex','-1');payment.querySelector('h2').focus({preventScroll:true});payment.scrollIntoView({behavior:'smooth',block:'start'});});
    edit.addEventListener('click',reopen);
    form.addEventListener('change',function(e){if(/^(billing_|shipping_|ship_to_different)/.test(e.target.name||'')&&!/^shipping_method/.test(e.target.name||'')){reopen();}else if(/shipping_method|sa_pickup_station/.test(e.target.name||'')){if(form.dataset.saAddressReady==='true'){reopen();}}title();});
    form.addEventListener('submit',function(e){if(form.dataset.saAddressReady!=='true'){e.preventDefault();e.stopImmediatePropagation();details.hidden=false;fold.hidden=true;valid(true);contact.scrollIntoView({behavior:'smooth',block:'start'});}},true);
    if(window.jQuery)jQuery(document.body).on('checkout_error',reopen).on('updated_checkout',function(){title();if(form.dataset.saAddressReady!=='true')gate(false);});
    title();gate(false);
  }

  function saCartWorkspace(){
    var root=document.querySelector('.sa-cart-page');if(!root)return;
    root.classList.add('sa-cart-coherent');
    var status=document.createElement('div');status.className='sa-cart-update-status';status.hidden=true;status.setAttribute('role','status');var message=document.createElement('p');message.textContent='Updating your cart total. Please wait before checkout.';var reload=document.createElement('button');reload.type='button';reload.textContent='Reload cart';reload.addEventListener('click',function(){location.reload();});status.append(message,reload);root.querySelector('.sa-cart-summary').appendChild(status);
    root.addEventListener('click',function(e){if(root.dataset.saCartBusy==='true'&&e.target.closest('a.checkout-button,.sa-cart-line__buy')){e.preventDefault();e.stopImmediatePropagation();}},true);
    document.querySelectorAll('.woocommerce-message').forEach(function(n){if(/has been added to your cart|have been added to your cart/.test(n.textContent))n.hidden=true;});
    function sync(){
      var target=root.querySelector('.sa-cart-delivery__options'),table=root.querySelector('.sa-cart-totals-table');
      if(target&&table){var rows=[].slice.call(table.querySelectorAll('tr.woocommerce-shipping-totals'));if(rows.length){target.replaceChildren();var t=document.createElement('table'),body=document.createElement('tbody');t.appendChild(body);target.appendChild(t);rows.forEach(function(row,index){var replacement=document.createElement('tr');replacement.className='sa-cart-shipping-summary';var th=document.createElement('th'),td=document.createElement('td');th.textContent='Delivery';var price=row.querySelector('input:checked, input[type="hidden"]');var li=price&&price.closest('li');var disclosure=root.querySelector('.sa-cart-delivery-current');if(disclosure){if(index===0)disclosure.textContent='';var lab=li&&li.querySelector('label'),copy=lab&&lab.cloneNode(true);if(copy){copy.querySelectorAll('.sa-ship__price,.woocommerce-Price-amount').forEach(function(x){x.remove();});copy.querySelectorAll('small').forEach(function(x){x.replaceWith(document.createTextNode(' '+x.textContent+' '));});disclosure.textContent+=(index?'\n':'')+copy.textContent.replace(/\s+/g,' ').trim()+' · Change options';}else disclosure.textContent+=(index?'\n':'')+'Review delivery options';}var amount=li&&li.querySelector('.sa-ship__price,.woocommerce-Price-amount');td.textContent=amount?amount.textContent.replace(/\s+/g,' ').trim():li&&/\bFree\b/i.test(li.textContent)?'Free':'Calculated at checkout';replacement.append(th,td);row.replaceWith(replacement);body.appendChild(row);});}}
      root.querySelectorAll('[data-sa-qty]').forEach(function(wrap){if(wrap.dataset.saSelectReady)return;var input=wrap.querySelector('input.qty');if(!input)return;wrap.dataset.saSelectReady='true';var select=document.createElement('select');select.className='sa-cart-quantity-select';select.setAttribute('aria-label','Product quantity');var current=parseInt(input.value,10)||1,max=parseInt(input.max,10),limit=Math.min(max>0?max:20,20);for(var i=1;i<=limit;i++){select.add(new Option(String(i),String(i)));}if(current>limit)select.add(new Option(String(current),String(current)));select.add(new Option('Other quantity…','custom'));select.value=String(current);wrap.appendChild(select);select.addEventListener('change',function(){if(select.value==='custom'){wrap.classList.add('sa-custom-quantity');input.focus();return;}input.value=select.value;input.dispatchEvent(new Event('change',{bubbles:true}));saScheduleCartUpdate();});input.addEventListener('change',function(){saScheduleCartUpdate();});});
      var totals=root.querySelector('.cart_totals'),proceed=root.querySelector('.sa-cart-proceed'),coupon=root.querySelector('.sa-cart-coupon');if(proceed&&coupon)proceed.before(coupon);var hint=root.querySelector('.sa-geo-currency-note');if(hint&&totals&&proceed){hint.classList.add('sa-cart-currency-note');proceed.before(hint);}
      var panel=root.querySelector('.sa-cart-delivery');if(panel)panel.hidden=!panel.querySelector('.woocommerce-shipping-totals');
    }
    sync();if(window.jQuery)jQuery(document.body).on('updated_cart_totals',sync).on('updated_wc_div',function(){root.dataset.saCartBusy='false';root.querySelectorAll('a.checkout-button,.sa-cart-line__buy').forEach(function(a){a.removeAttribute('aria-disabled');});var status=root.querySelector('.sa-cart-update-status');if(status)status.hidden=true;sync();});
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
      bar.hidden = true;
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

  saCartWorkspace();
  saCheckoutExperience();
  saCheckoutOnePage();
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

/* Horizontal-scroller overscroll guard (iOS <16 lacks overscroll-behavior). Blocks only horizontal-dominant swipes past an edge. */
(function(){
  function isH(el){var s=getComputedStyle(el);return /(auto|scroll)/.test(s.overflowX)&&el.scrollWidth>el.clientWidth+1;}
  function mark(){document.querySelectorAll('ul.products,[class*="scroll"],[class*="chips"],[class*="rail"],[class*="carousel"],[class*="thumbs"],[class*="__list"],.sa-sp *').forEach(function(el){if(!el.classList.contains('sa-hscroll')&&isH(el))el.classList.add('sa-hscroll');});}
  if(document.readyState!=='loading')mark();else document.addEventListener('DOMContentLoaded',mark);
  window.addEventListener('load',mark);window.addEventListener('resize',mark);setTimeout(mark,2500);
  var sx=0,sy=0,sc=null,dir=0;
  document.addEventListener('touchstart',function(e){if(e.touches.length!==1){sc=null;return;}var t=e.touches[0];sx=t.clientX;sy=t.clientY;dir=0;sc=null;var el=e.target;while(el&&el!==document.body){if(el.nodeType===1&&isH(el)){sc=el;break;}el=el.parentElement;}},{passive:true});
  document.addEventListener('touchmove',function(e){if(!sc||e.touches.length!==1)return;var t=e.touches[0],dx=t.clientX-sx,dy=t.clientY-sy;
    if(!dir){if(Math.abs(dx)<6&&Math.abs(dy)<6)return;dir=Math.abs(dx)>Math.abs(dy)?1:2;}
    if(dir!==1)return;var max=sc.scrollWidth-sc.clientWidth;
    if((dx>0&&sc.scrollLeft<=0)||(dx<0&&sc.scrollLeft>=max-1)){if(e.cancelable)e.preventDefault();}
  },{passive:false});
})();
