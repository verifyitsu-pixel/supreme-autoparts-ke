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

    function sync() {
      var ok = !!box.checked && form.dataset.saTotalsBusy!=='true' && !document.documentElement.classList.contains('sa-checkout-qty-busy');
      btn.disabled = !ok;
      btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
      if (hint) hint.hidden = ok;
    }

    sync();
    if(window.MutationObserver)new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['class']});
    box.addEventListener('change', sync);
    // Woo updates payment fragment via AJAX — rebind after refresh.
    if (window.jQuery) {
      jQuery(document.body).on('update_checkout', function () { form.dataset.saTotalsBusy='true';sync(); });
      jQuery(document.body).on('updated_checkout', function (event, data) {
        if(data&&data.result==='success')form.dataset.saTotalsBusy='false';
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
    var payment=layout.querySelector('[data-sa-checkout-panel="payment"]');
    var shipping=layout.querySelector('[data-sa-checkout-panel="shipping"] .sa-checkout-section__body');
    if(!main||!payment||!shipping)return;
    var delivery=document.createElement('div');delivery.className='sa-delivery-options';var notes=shipping.querySelector('.woocommerce-additional-fields');shipping.insertBefore(delivery,notes);
    var comments=shipping.querySelector('#order_comments_field');if(comments&&!comments.classList.contains('validate-required')){var extra=document.createElement('details');extra.className='sa-checkout-extras';var toggle=document.createElement('summary');toggle.textContent='Add order notes (optional)';extra.appendChild(toggle);comments.replaceWith(extra);extra.appendChild(comments);}
    var final=document.createElement('div');final.className='sa-checkout-final';layout.appendChild(final);
    main.appendChild(payment);
    form.classList.add('sa-checkout-refreshed');
    function field(id){var el=document.getElementById(id);return el?String(el.value||'').trim():'';}
    function selectedLabel(root){var input=root.querySelector('input.shipping_method:checked, input.shipping_method[type="hidden"]');var li=input&&input.closest('li');if(!li)return '';var name=li.querySelector('.sa-ship__name'),price=li.querySelector('.sa-ship__price');if(!name)return li.textContent.replace(/\s+/g,' ').trim();var copy=name.cloneNode(true),sub=copy.querySelector('small');if(sub)sub.remove();var title=copy.textContent.replace(' · Estimated delivery 1-2 working days from order, excluding weekends','').replace(' · Estimated delivery 1-3 working days from order, excluding weekends','').trim();return [title,sub&&sub.textContent.trim(),price&&price.textContent.replace(/\s+/g,' ').trim()].filter(Boolean).join(' · ');}
    function summaries(){
      var contact=document.querySelector('[data-sa-checkout-panel="contact"] .sa-checkout-fold');
      if(contact)contact.textContent=[field('billing_first_name')+' '+field('billing_last_name'),field('billing_email'),field('billing_address_1'),field('billing_city')].map(function(s){return s.trim();}).filter(Boolean).join(' · ');
      var summary=document.querySelector('[data-sa-checkout-panel="shipping"] .sa-checkout-fold');
      if(summary)summary.textContent=selectedLabel(delivery)||'Review your delivery address and shipping options.';
    }
    function compressEstimates(container){
      // Only remove a literally identical, known estimate, and only from courier labels.
      // Unknown dates / special-order text and all pickup readiness windows stay intact.
      var labels=[].slice.call(container.querySelectorAll('li label')).filter(function(l){return !/^Store pickup\s*[,·]/i.test(l.textContent.trim());});
      var estimate=' · Estimated delivery 1-2 working days from order, excluding weekends';
      if(!labels.length||!labels.every(function(l){return l.textContent.indexOf(estimate)!==-1;}))return;
      var shared=document.createElement('p');shared.className='sa-delivery-estimate';
      shared.textContent='All courier options: estimated delivery 1-2 working days from order, excluding weekends. Product availability applies.';
      container.insertBefore(shared,container.firstChild);
      labels.forEach(function(l){var walker=document.createTreeWalker(l,4),node;while((node=walker.nextNode())){if(node.nodeValue.indexOf(estimate)!==-1)node.nodeValue=node.nodeValue.replace(estimate,'');}});
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
      container.insertBefore(buttons,container.firstChild);show(!!(chosen&&chosen.dataset.saPickup),false);
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
      delivery.replaceChildren();
      if(rows.length){
        var table=document.createElement('table');table.className='sa-delivery-table';var tbody=document.createElement('tbody');table.appendChild(tbody);delivery.appendChild(table);
        rows.forEach(function(row){
          var selected=selectedLabel(row),replacement=document.createElement('tr');replacement.className='sa-shipping-selection';
          var th=document.createElement('th');th.textContent='Delivery';var td=document.createElement('td');td.textContent=selected||'Choose at the delivery step';replacement.appendChild(th);replacement.appendChild(td);
          row.replaceWith(replacement);tbody.appendChild(row);
        });
        compressEstimates(delivery);tabs(delivery);
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

  // Accessible, reversible checkout steps. Next never submits or pays.
  function saCheckoutSteps() {
    var panels=[].slice.call(document.querySelectorAll('[data-sa-checkout-panel]'));
    if(panels.length!==3)return;
    var keys=['contact','shipping','payment'],active=0,furthest=0;
    var actions=document.createElement('div');actions.className='sa-checkout-step-actions';(document.querySelector('.sa-checkout-final')||document.querySelector('form.checkout')).appendChild(actions);
    function body(p){return p.querySelector('.sa-checkout-section__body');}
    function open(index,focus){active=index;actions.querySelectorAll('[data-sa-next-index]').forEach(function(n){n.hidden=Number(n.dataset.saNextIndex)!==index;});actions.hidden=index===2;var place=document.querySelector('.sa-checkout-final > .place-order');if(place)place.hidden=index!==2;document.querySelector('form.checkout').dataset.saActiveStep=keys[index];furthest=Math.max(furthest,index);panels.forEach(function(p,i){body(p).hidden=i!==index;p.classList.toggle('is-active',i===index);p.classList.toggle('is-complete',i<index);var fold=p.querySelector('.sa-checkout-fold');if(fold)fold.hidden=i>=index;var edit=p.querySelector('[data-sa-edit-step]');edit.hidden=i>=index;edit.setAttribute('aria-expanded',String(i===index));});
      document.querySelectorAll('[data-sa-step]').forEach(function(el,i){el.classList.toggle('is-active',i===index);el.classList.toggle('is-done',i<index);el.setAttribute('aria-current',i===index?'step':'false');});
      document.querySelector('form.checkout').dispatchEvent(new Event('sa:stepchange'));
      if(focus){var title=panels[index].querySelector('h2');title.setAttribute('tabindex','-1');title.focus({preventScroll:true});panels[index].scrollIntoView({behavior:'smooth',block:'start'});}
    }
    function valid(p){var fields=[].slice.call(p.querySelectorAll('input,select,textarea'));var bad=fields.find(function(e){if(e.disabled||e.type==='hidden'||(!e.getClientRects().length&&!(e.tagName==='SELECT'&&e.classList.contains('select2-hidden-accessible')&&e.parentElement.getClientRects().length))||e.closest('.shipping_address')&& !document.querySelector('#ship-to-different-address-checkbox:checked'))return false;var row=e.closest('.form-row'),required=e.required||row&&row.classList.contains('validate-required');return required&&!String(e.value||'').trim()||e.type==='email'&&e.value&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e.value);});if(bad){bad.setAttribute('aria-invalid','true');bad.focus();var row=bad.closest('.form-row');if(row)row.classList.add('woocommerce-invalid');return false;}return true;}
    panels.forEach(function(p,i){var h=p.querySelector('.sa-checkout-section__head'),b=body(p);b.id=b.id||'sa-checkout-step-body-'+keys[i];var edit=document.createElement('button');edit.type='button';edit.className='sa-checkout-edit-step';edit.textContent='Change';edit.setAttribute('aria-label','Change '+keys[i]+' details');edit.setAttribute('data-sa-edit-step',keys[i]);edit.setAttribute('aria-controls',b.id);edit.addEventListener('click',function(){if(i<active)open(i,true);});h.appendChild(edit);var fold=document.createElement('p');fold.className='sa-checkout-fold';fold.hidden=true;h.insertAdjacentElement('afterend',fold);
      if(i<2){var next=document.createElement('button');next.type='button';next.className='sa-checkout-next';next.dataset.saNextIndex=String(i);next.textContent=i===0?'Next: Shipping':'Next: Payment';next.addEventListener('click',function(){if(i===active&&valid(p))open(i+1,true);});actions.appendChild(next);}
    });
    document.querySelector('.sa-checkout-page').classList.add('sa-checkout-is-stepped');open(0,false);
    if(window.jQuery)jQuery(document.body).on('checkout_error',function(){var invalid=document.querySelector('.woocommerce-invalid');var p=invalid&&invalid.closest('[data-sa-checkout-panel]');if(p)open(keys.indexOf(p.dataset.saCheckoutPanel),true);});
    document.querySelector('form.checkout').addEventListener('submit',function(e){if(active<2){e.preventDefault();e.stopImmediatePropagation();if(valid(panels[active]))open(active+1,true);}},true);
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

  saCheckoutExperience();
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
