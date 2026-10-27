/* Supreme Autoparts — first-party personalisation (no third-party trackers). */
(function () {
  'use strict';
  var C = window.saPzCtx || {};
  var dnt = navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true;
  var KEY = 'sa_pz', pz;
  function load() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } }
  function slug(s) { return String(s || '').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  function bump(map, k, w) {
    if (!k) return;
    Object.keys(map).forEach(function (x) { map[x] = Math.round(map[x] * 0.9 * 1000) / 1000; if (map[x] < 0.05) delete map[x]; });
    map[k] = Math.min(1, (map[k] || 0) + w);
  }
  function top(map, n) {
    var o = {}; Object.keys(map).sort(function (a, b) { return map[b] - map[a]; }).slice(0, n).forEach(function (k) { o[k] = Math.round(map[k] * 100) / 100; }); return o;
  }
  function save() {
    pz.t = Date.now();
    try { localStorage.setItem(KEY, JSON.stringify(pz)); } catch (e) {}
    var ck = { c: top(pz.c, 8), b: top(pz.b, 5), mk: pz.mk || '', mo: pz.mo || '', y: pz.y || '', p: (pz.p || []).slice(0, 8), q: (pz.q || []).slice(0, 3), cart: (pz.cart || []).slice(0, 12) };
    document.cookie = KEY + '=' + encodeURIComponent(JSON.stringify(ck)) + ';path=/;max-age=' + (30 * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
  }
  function setVeh(mk, mo, y) {
    mk = slug(mk); if (!mk) return;
    pz.mk = mk; pz.mo = mo ? mk + '/' + slug(mo) : ''; pz.y = /^(19|20)\d\d$/.test(y || '') ? y : '';
  }

  if (dnt) {
    try { localStorage.removeItem(KEY); } catch (e) {}
    document.cookie = KEY + '=;path=/;max-age=0';
  } else {
    pz = load();
    if (pz.t && Date.now() - pz.t > 30 * 864e5) pz = {};
    pz.c = pz.c || {}; pz.b = pz.b || {}; pz.p = pz.p || []; pz.q = pz.q || []; pz.cart = pz.cart || [];
    if (C.t === 'product' && C.id) {
      pz.p = [C.id].concat(pz.p.filter(function (x) { return x !== C.id; })).slice(0, 12);
      bump(pz.c, C.leaf, 0.6); if (C.top && C.top !== C.leaf) pz.c[C.top] = Math.min(1, (pz.c[C.top] || 0) + 0.2);
      bump(pz.b, C.b, 0.4);
      if (C.mk && !pz.mk) { /* a viewed part hints the car, but never overrides a chosen vehicle */ pz.hintMk = C.mk; }
      try {
        var sk = 'sa_pzv_' + C.id;
        if (!sessionStorage.getItem(sk)) {
          sessionStorage.setItem(sk, '1');
          var body = JSON.stringify({ pid: C.id });
          if (navigator.sendBeacon) navigator.sendBeacon(C.api + 'pz', new Blob([body], { type: 'application/json' }));
          else fetch(C.api + 'pz', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true });
        }
      } catch (e) {}
    }
    if (C.t === 'shop' && C.leaf) { bump(pz.c, C.leaf, 0.3); }
    if (C.t === 'shop' && C.b) { bump(pz.b, C.b, 0.3); }
    if (C.q) { pz.q = [C.q].concat(pz.q.filter(function (x) { return x !== C.q; })).slice(0, 5); }
    if (C.t === 'cart' && C.cart) { pz.cart = C.cart.slice(0, 20); }
    var u = new URLSearchParams(location.search);
    if (u.get('sa_make')) setVeh(u.get('sa_make'), u.get('sa_model'), u.get('sa_year'));
    save();

    document.addEventListener('change', function (e) {
      var el = e.target; if (!el || !el.name || !/^sa_(make|model|year)$/.test(el.name)) return;
      var f = el.form || document;
      var g = function (n) { var x = f.querySelector('[name=' + n + ']'); return x ? x.value : ''; };
      if (g('sa_make')) { setVeh(g('sa_make'), g('sa_model'), g('sa_year')); save(); }
    });
    var addCart = function (id) { id = parseInt(id, 10); if (!id) return; pz.cart = [id].concat(pz.cart.filter(function (x) { return x !== id; })).slice(0, 20); save(); };
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-product_id].add_to_cart_button, button[name=add-to-cart]');
      if (b) addCart(b.getAttribute('data-product_id') || b.value);
    });
    if (window.jQuery) window.jQuery(document.body).on('added_to_cart', function (ev, f, h, btn) { if (btn && btn.attr) addCart(btn.attr('data-product_id')); });
  }

  /* ---- Recommendation rows ---- */
  var ctx = { home: 'home', product: 'product', cart: 'cart', shop: 'shop' }[C.t];
  if (!ctx || !C.api) return;
  if (ctx === 'shop' && (dnt || (C.paged || 1) > 1 || !(pz && (pz.mk || Object.keys(pz.c).length || pz.p.length)))) return;
  if (ctx === 'home' && (dnt || !(pz && (pz.mk || pz.p.length || Object.keys(pz.c).length)))) return;
  var anchor = function () {
    if (ctx === 'home') { var h = document.getElementById('sa-ed-pop'); var s = h && h.closest('section'); return s ? { el: s, where: 'afterend' } : null; }
    if (ctx === 'shop') { var l = document.querySelector('.sa-market__results ul.products'); return l ? { el: l, where: 'afterend' } : null; } // below the category's own products, never above
    if (ctx === 'product') { var r = document.querySelector('.related.products, .up-sells') || document.querySelector('.woocommerce-tabs') || document.querySelector('div.product'); return r ? { el: r, where: r.matches('div.product') ? 'beforeend' : 'beforebegin' } : null; }
    if (ctx === 'cart') { var c = document.querySelector('.cart-collaterals') || document.querySelector('.woocommerce-cart-form'); return c ? { el: c, where: 'afterend' } : null; }
  };
  var q = '?ctx=' + ctx + (C.id ? '&pid=' + C.id : '') + (ctx === 'cart' && C.cart ? '&cart=' + C.cart.join(',') : '');
  // Keep prices in the same currency as a pinned Kenya Merchant landing.
  // This changes rendering context only, never recommendation selection/scoring.
  if (new URLSearchParams(location.search).get('sa_market') === 'ke') q += '&sa_market=ke';
  var run = function () {
    var a = anchor(); if (!a) return;
    fetch(C.api + 'recs' + q, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d || !d.html) return;
      var wrap = document.createElement('div'); wrap.className = 'sa-reco-wrap' + (ctx === 'shop' ? '' : ' sa-container'); wrap.innerHTML = d.html;
      a.el.insertAdjacentElement(a.where, wrap);
      document.body.dispatchEvent(new CustomEvent('sa:reco', { detail: d.rows }));
    }).catch(function () {});
  };
  if ('requestIdleCallback' in window) requestIdleCallback(run, { timeout: 1500 }); else setTimeout(run, 300);
})();
