/**
 * Supreme Autoparts — Sai-style agents assemble the REAL page DOM.
 * Labeled cursors (Grok, GPT, Gemini, Opus, Muse) move to live sections
 * (header, nav, hero, cards, PDP, cart, checkout…) and place them into
 * position. Plays on every customer page load/refresh. Skip anytime.
 * prefers-reduced-motion → instant show. Does not mutate Whop iframes.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'sap-built';
  var WA_DISPLAY = '+1 917 437 5121';
  var WA_URL = 'https://wa.me/19174375121';

  var cfg = (window.saAgentsBuild && typeof window.saAgentsBuild === 'object')
    ? window.saAgentsBuild
    : {};
  if (cfg.waDisplay) WA_DISPLAY = String(cfg.waDisplay);
  if (cfg.waUrl) WA_URL = String(cfg.waUrl);
  if (cfg.storageKey) STORAGE_KEY = String(cfg.storageKey);
  var CRITICAL_UI = !!(cfg.criticalUi && Number(cfg.criticalUi) === 1);
  var PAGE_TYPE = cfg.pageType ? String(cfg.pageType) : 'store';

  var POINTER_SVG =
    '<svg class="sa-agents-build__cursor-pointer" viewBox="0 0 18 22" width="18" height="22" aria-hidden="true" focusable="false">' +
    '<path fill="currentColor" stroke="#0B0B0D" stroke-width="1.2" d="M1.2 1.2l15.2 8.4-6.6 1.7 3.8 7.4-2.7 1.4-3.9-7.5-5.8 4.4z"/>' +
    '</svg>';

  var AGENTS = [
    { id: 'grok', label: 'Grok' },
    { id: 'gpt', label: 'GPT' },
    { id: 'gemini', label: 'Gemini' },
    { id: 'opus', label: 'Opus' },
    { id: 'muse', label: 'Muse' },
  ];

  var TYPEABLE_SEL = [
    'h1', 'h2', 'h3',
    '.sa-hero__eyebrow', '.sa-hero__lead',
    '.sa-archive-header__eyebrow',
    '.sa-checkout-hero__eyebrow', '.sa-checkout-hero__title', '.sa-checkout-hero__lead',
    '.sa-cart-hero__eyebrow', '.sa-cart-hero__title',
    '.product_title', '.sa-product__title',
  ].join(',');

  var state = {
    root: null,
    cursors: {},
    timers: [],
    rafs: [],
    finished: false,
    hardCap: null,
    slots: [],
    typedRestore: [],
  };

  function prefersReducedMotion() {
    try {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  function clearBuilt() {
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
  }

  function later(fn, ms) {
    var id = window.setTimeout(fn, ms);
    state.timers.push(id);
    return id;
  }

  function clearAllTimers() {
    state.timers.forEach(function (id) { window.clearTimeout(id); });
    state.timers = [];
    state.rafs.forEach(function (id) { window.cancelAnimationFrame(id); });
    state.rafs = [];
    if (state.hardCap) {
      window.clearTimeout(state.hardCap);
      state.hardCap = null;
    }
  }

  function isUnsafe(el) {
    if (!el || el.nodeType !== 1) return true;
    var tag = (el.tagName || '').toLowerCase();
    if (tag === 'iframe' || tag === 'script' || tag === 'style' || tag === 'noscript' || tag === 'link') {
      return true;
    }
    if (el.closest && (
      el.closest('iframe') ||
      el.closest('[data-whop]') ||
      el.closest('.whop-checkout') ||
      el.closest('.whop-embedded-checkout') ||
      el.closest('#whop-checkout') ||
      el.closest('.StripeElement') ||
      el.closest('.payment_box iframe')
    )) {
      return true;
    }
    // Never hide/mutate payment field guts — only their section wrappers.
    if (el.matches && el.matches('input, select, textarea, button') && el.closest && el.closest('#payment, .payment_box, .woocommerce-checkout-payment')) {
      return true;
    }
    return false;
  }

  function qsa(sel, root) {
    try {
      return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    } catch (e) {
      return [];
    }
  }

  function first(sel) {
    try { return document.querySelector(sel); } catch (e) { return null; }
  }

  function pushUnique(list, el, meta) {
    if (!el || isUnsafe(el)) return;
    if (el.id === 'sa-agents-build' || (el.closest && el.closest('#sa-agents-build'))) return;
    for (var i = 0; i < list.length; i++) {
      if (list[i].el === el) return;
    }
    // Skip if an ancestor is already queued (place parents, not every child).
    for (var j = 0; j < list.length; j++) {
      if (list[j].el.contains && list[j].el.contains(el)) return;
    }
    // Drop previously queued descendants of this new parent.
    for (var k = list.length - 1; k >= 0; k--) {
      if (el.contains && el.contains(list[k].el)) list.splice(k, 1);
    }
    list.push({
      el: el,
      label: (meta && meta.label) || 'section',
      agent: (meta && meta.agent) || null,
      type: !!(meta && meta.type),
      mode: (meta && meta.mode) || 'place',
    });
  }

  function take(sel, limit) {
    var nodes = qsa(sel);
    if (limit && nodes.length > limit) nodes = nodes.slice(0, limit);
    return nodes;
  }

  function collectTargets() {
    var list = [];
    var commonChrome = function () {
      pushUnique(list, first('.sa-announce'), { label: 'announcement bar', agent: 'gpt' });
      pushUnique(list, first('.sa-logo'), { label: 'logo', agent: 'grok', type: true });
      pushUnique(list, first('.sa-search'), { label: 'search', agent: 'gemini' });
      pushUnique(list, first('.sa-header__actions'), { label: 'account & cart', agent: 'opus' });
      pushUnique(list, first('.sa-nav'), { label: 'navigation', agent: 'muse' });
    };

    commonChrome();

    if (PAGE_TYPE === 'home' || PAGE_TYPE === 'store') {
      pushUnique(list, first('.sa-hero__eyebrow'), { label: 'hero eyebrow', agent: 'grok', type: true });
      pushUnique(list, first('#sa-hero-title, .sa-hero h1'), { label: 'hero headline', agent: 'gemini', type: true });
      pushUnique(list, first('.sa-hero__lead'), { label: 'hero lead', agent: 'gpt', type: true });
      pushUnique(list, first('.sa-hero__search'), { label: 'hero search', agent: 'opus' });
      pushUnique(list, first('.sa-hero__actions'), { label: 'hero CTAs', agent: 'muse' });
      take('.sa-trust__item', 4).forEach(function (el, i) {
        pushUnique(list, el, { label: 'trust signal', agent: AGENTS[i % AGENTS.length].id });
      });
      take('.sa-type-tile', 8).forEach(function (el, i) {
        pushUnique(list, el, { label: 'category tile', agent: AGENTS[i % AGENTS.length].id, mode: 'card' });
      });
      take('.sa-latest-parts .sa-product-card, .sa-products .sa-product-card', 8).forEach(function (el, i) {
        pushUnique(list, el, { label: 'product card', agent: AGENTS[i % AGENTS.length].id, mode: 'card' });
      });
      take('.sa-how__step', 4).forEach(function (el, i) {
        pushUnique(list, el, { label: 'how-it-works step', agent: AGENTS[i % AGENTS.length].id });
      });
      pushUnique(list, first('.sa-ship-banner'), { label: 'shipping banner', agent: 'gpt' });
      pushUnique(list, first('.sa-blurb'), { label: 'about blurb', agent: 'muse' });
    } else if (PAGE_TYPE === 'shop' || PAGE_TYPE === 'category' || PAGE_TYPE === 'search') {
      pushUnique(list, first('.sa-archive-header'), { label: 'catalog header', agent: 'grok', type: true });
      pushUnique(list, first('.sa-catalog-filters, .sa-filters, .woocommerce-notices-wrapper'), { label: 'filters', agent: 'gemini' });
      take('ul.products .sa-product-card, ul.products > li.product', 12).forEach(function (el, i) {
        pushUnique(list, el, { label: 'catalog card', agent: AGENTS[i % AGENTS.length].id, mode: 'card' });
      });
      pushUnique(list, first('.woocommerce-pagination, .sa-pagination'), { label: 'pagination', agent: 'opus' });
    } else if (PAGE_TYPE === 'product') {
      pushUnique(list, first('.woocommerce-product-gallery, .sa-product__gallery, .sa-single .images'), { label: 'product gallery', agent: 'muse', mode: 'image' });
      pushUnique(list, first('.product_title, .sa-product__title, .summary .product_title, h1.product_title'), { label: 'product title', agent: 'grok', type: true });
      pushUnique(list, first('.summary .price, .sa-product__price, .product .price'), { label: 'price', agent: 'gemini' });
      pushUnique(list, first('.sa-product-card__sku, .product_meta, .sku_wrapper'), { label: 'part number', agent: 'gpt' });
      pushUnique(list, first('form.cart, .sa-product__actions, .single_add_to_cart_button'), { label: 'add to cart', agent: 'opus' });
      pushUnique(list, first('.woocommerce-tabs, .sa-product__tabs, #tab-description, .woocommerce-product-details__short-description'), { label: 'details', agent: 'muse' });
      take('.related .sa-product-card, .related products .product, .upsells .sa-product-card', 4).forEach(function (el, i) {
        pushUnique(list, el, { label: 'related part', agent: AGENTS[i % AGENTS.length].id, mode: 'card' });
      });
    } else if (PAGE_TYPE === 'cart') {
      pushUnique(list, first('.sa-cart-hero'), { label: 'cart header', agent: 'grok', type: true });
      take('.woocommerce-cart-form__cart-item, .sa-cart-table tbody tr.cart_item, tr.woocommerce-cart-form__cart-item', 8).forEach(function (el, i) {
        pushUnique(list, el, { label: 'cart line', agent: AGENTS[i % AGENTS.length].id, mode: 'card' });
      });
      pushUnique(list, first('.sa-cart-summary, .cart-collaterals, .sa-cart-layout__summary'), { label: 'order summary', agent: 'opus' });
      pushUnique(list, first('.wc-proceed-to-checkout, .checkout-button, a.checkout-button'), { label: 'checkout CTA', agent: 'muse' });
    } else if (PAGE_TYPE === 'checkout') {
      pushUnique(list, first('.sa-checkout-hero'), { label: 'checkout header', agent: 'grok', type: true });
      pushUnique(list, first('.sa-checkout-steps'), { label: 'checkout steps', agent: 'gemini' });
      pushUnique(list, first('.sa-checkout-section--contact, #sa-checkout-contact'), { label: 'contact fields', agent: 'gpt' });
      pushUnique(list, first('.sa-checkout-section--shipping, #sa-checkout-shipping'), { label: 'shipping fields', agent: 'opus' });
      // Place payment SECTION only — never iframes / payment inputs inside.
      pushUnique(list, first('.sa-checkout-section--payment, #sa-checkout-payment-note, #payment'), { label: 'payment section', agent: 'muse' });
      pushUnique(list, first('.sa-checkout-layout__summary, .sa-checkout-summary'), { label: 'order summary', agent: 'grok' });
    } else if (PAGE_TYPE === 'account') {
      pushUnique(list, first('.sa-account-nav, .woocommerce-MyAccount-navigation'), { label: 'account nav', agent: 'grok' });
      pushUnique(list, first('.sa-account-panel, .woocommerce-MyAccount-content, .sa-account-auth'), { label: 'account panel', agent: 'gemini' });
      take('.sa-account-tile', 6).forEach(function (el, i) {
        pushUnique(list, el, { label: 'account tile', agent: AGENTS[i % AGENTS.length].id, mode: 'card' });
      });
    } else {
      // Generic store page fallback
      pushUnique(list, first('main, #main, .sa-main, .site-main'), { label: 'page content', agent: 'muse' });
    }

    // Footer last on every page (contact / trust)
    pushUnique(list, first('.sa-footer__grid'), { label: 'footer', agent: 'gpt' });
    pushUnique(list, first('.sa-footer-contact'), { label: 'contact form', agent: 'opus' });

    // Soft density cap — allow fuller Sai-style place-through (~20–40s).
    var MAX = CRITICAL_UI ? 18 : 32;
    if (list.length > MAX) list = list.slice(0, MAX);
    return list;
  }

  function hardCapMs(n) {
    // Deliberate real-DOM assemble: ~20–40s by density (skip anytime). Max ~45–60s.
    var ms = 10000 + n * 900;
    if (CRITICAL_UI) ms = Math.min(ms, 45000);
    return Math.min(60000, Math.max(20000, ms));
  }

  function statusBoot() {
    if (PAGE_TYPE === 'product') return 'Agents assembling this product…';
    if (PAGE_TYPE === 'cart') return 'Agents assembling your cart…';
    if (PAGE_TYPE === 'checkout') return 'Agents assembling checkout…';
    if (PAGE_TYPE === 'account') return 'Agents assembling your account…';
    if (PAGE_TYPE === 'shop' || PAGE_TYPE === 'category' || PAGE_TYPE === 'search') return 'Agents assembling the catalog…';
    return 'Agents assembling your shop…';
  }

  function setStatus(text) {
    if (!state.root) return;
    var el = state.root.querySelector('[data-sab-status]');
    if (el) el.textContent = text;
  }

  function agentLabel(id) {
    for (var i = 0; i < AGENTS.length; i++) {
      if (AGENTS[i].id === id) return AGENTS[i].label;
    }
    return 'Agent';
  }

  function pointFor(el) {
    var er = el.getBoundingClientRect();
    var x = er.left + Math.min(Math.max(er.width * 0.28, 12), 96);
    var y = er.top + Math.min(Math.max(er.height * 0.35, 10), 56);
    // Keep cursor on-screen
    x = Math.max(8, Math.min(window.innerWidth - 48, x));
    y = Math.max(8, Math.min(window.innerHeight - 48, y));
    return { x: x, y: y };
  }

  function moveCursor(agentId, x, y, duration, onDone) {
    var node = state.cursors[agentId];
    if (!node) {
      if (onDone) onDone();
      return;
    }
    node.classList.add('is-on');
    var start = performance.now();
    var fromX = node._x || 0;
    var fromY = node._y || 0;
    var dur = Math.max(220, duration || 520);

    function frame(now) {
      if (state.finished) return;
      var t = Math.min(1, (now - start) / dur);
      var ease = 1 - Math.pow(1 - t, 3);
      var cx = fromX + (x - fromX) * ease;
      var cy = fromY + (y - fromY) * ease;
      node._x = cx;
      node._y = cy;
      node.style.transform = 'translate3d(' + cx + 'px,' + cy + 'px,0)';
      if (t < 1) {
        state.rafs.push(window.requestAnimationFrame(frame));
      } else if (onDone) {
        onDone();
      }
    }
    state.rafs.push(window.requestAnimationFrame(frame));
  }

  function hideCursor(agentId) {
    var node = state.cursors[agentId];
    if (node) node.classList.remove('is-on');
  }

  function hideOtherCursors(keepId) {
    AGENTS.forEach(function (a) {
      if (a.id !== keepId) hideCursor(a.id);
    });
  }

  function canType(el) {
    if (!el || !el.matches) return false;
    try {
      if (!el.matches(TYPEABLE_SEL)) return false;
    } catch (e) {
      return false;
    }
    if (el.querySelector && el.querySelector('img, svg, input, iframe, a .sa-logo__img')) return false;
    // Prefer leaf-ish text nodes
    var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (text.length < 2 || text.length > 140) return false;
    if (el.children && el.children.length > 2) return false;
    return true;
  }

  function typeText(el, text, cps, onDone) {
    var i = 0;
    var caret = document.createElement('span');
    caret.className = 'sab-caret';
    el.textContent = '';
    el.appendChild(caret);
    // ~40–60ms per character (cps ~17–25).
    var delay = Math.max(40, Math.min(60, Math.floor(1000 / (cps || 20))));

    function tick() {
      if (state.finished) return;
      if (i >= text.length) {
        caret.classList.add('is-off');
        try { if (caret.parentNode) caret.parentNode.removeChild(caret); } catch (e) { /* ignore */ }
        if (onDone) onDone();
        return;
      }
      el.insertBefore(document.createTextNode(text.charAt(i)), caret);
      i += 1;
      later(tick, delay);
    }
    tick();
  }

  function ensureVisible(el, onDone) {
    try {
      var er = el.getBoundingClientRect();
      var pad = 72;
      if (er.top < pad || er.bottom > window.innerHeight - pad) {
        el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
        later(onDone, 480);
        return;
      }
    } catch (e) { /* ignore */ }
    onDone();
  }

  function placeSlot(item, onDone) {
    var el = item.el;
    if (!el || !el.classList) {
      if (onDone) onDone();
      return;
    }
    el.classList.add('sa-agents-placed');
    if (item.mode === 'card') el.classList.add('sa-agents-placed--card');
    if (item.mode === 'image') el.classList.add('sa-agents-placed--image');
    el.classList.remove('sa-agents-slot');
    later(function () {
      if (onDone) onDone();
    }, item.mode === 'card' ? 280 : 200);
  }

  function revealAllSlots() {
    state.slots.forEach(function (item) {
      if (!item.el || !item.el.classList) return;
      item.el.classList.add('sa-agents-placed');
      item.el.classList.remove('sa-agents-slot');
    });
    state.typedRestore.forEach(function (rec) {
      try {
        if (rec.el && rec.html != null) rec.el.innerHTML = rec.html;
      } catch (e) { /* ignore */ }
    });
    state.typedRestore = [];
  }

  function unbindSkip() {
    if (state._onKey) {
      document.removeEventListener('keydown', state._onKey, true);
      state._onKey = null;
    }
  }

  function finish(reason) {
    if (state.finished) return;
    state.finished = true;
    clearAllTimers();
    unbindSkip();

    revealAllSlots();

    // Always restore interactivity immediately (critical on cart/checkout / Whop).
    document.body.classList.remove('sa-agents-building');
    document.body.classList.add('sa-agents-built');
    document.documentElement.classList.remove('sa-agents-building');

    var root = state.root;
    if (!root) {
      try {
        window.dispatchEvent(new CustomEvent('sa-agents-build:done', { detail: { reason: reason || 'complete' } }));
      } catch (e0) { /* ignore */ }
      return;
    }

    root.style.pointerEvents = 'none';
    root.classList.add('is-done');
    root.setAttribute('aria-hidden', 'true');
    root.removeAttribute('aria-modal');
    root.removeAttribute('aria-label');

    try {
      if (document.activeElement && root.contains(document.activeElement)) {
        document.activeElement.blur();
      }
    } catch (e) { /* ignore */ }

    var removeMs = (reason === 'skip' || reason === 'cap' || reason === 'wa' || reason === 'replay-reset') ? 60 : 380;
    window.setTimeout(function () {
      if (root && root.parentNode) root.parentNode.removeChild(root);
      state.root = null;
      state.cursors = {};
      state.slots = [];
    }, removeMs);

    try {
      window.dispatchEvent(new CustomEvent('sa-agents-build:done', { detail: { reason: reason || 'complete' } }));
    } catch (e2) { /* ignore */ }
  }

  function buildOverlay() {
    var root = document.createElement('div');
    root.id = 'sa-agents-build';
    root.className = 'sa-agents-build sa-agents-build--live';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Building the shop experience');
    root.setAttribute('aria-hidden', 'false');
    root.tabIndex = -1;

    root.innerHTML =
      '<div class="sa-agents-build__veil" aria-hidden="true"></div>' +
      '<p class="sa-agents-build__skip">Click / Esc / Enter / Space to skip</p>' +
      '<div class="sa-agents-build__cursors" data-sab-cursors aria-hidden="true"></div>' +
      '<p class="sa-agents-build__status" data-sab-status>' + statusBoot() + '</p>';

    var cursorsWrap = root.querySelector('[data-sab-cursors]');
    AGENTS.forEach(function (a, idx) {
      var c = document.createElement('div');
      c.className = 'sa-agents-build__cursor sa-agents-build__cursor--' + a.id;
      c.dataset.agent = a.id;
      c.innerHTML = POINTER_SVG + '<span class="sa-agents-build__cursor-pill">' + a.label + '</span>';
      c._x = 36 + idx * 30;
      c._y = 48 + idx * 20;
      c.style.transform = 'translate3d(' + c._x + 'px,' + c._y + 'px,0)';
      cursorsWrap.appendChild(c);
      state.cursors[a.id] = c;
    });

    document.body.appendChild(root);
    state.root = root;
    return root;
  }

  function prepareSlots(targets) {
    targets.forEach(function (item) {
      if (!item.el || !item.el.classList) return;
      item.el.classList.add('sa-agents-slot');
      item.el.classList.remove('sa-agents-placed', 'sa-agents-placed--card', 'sa-agents-placed--image');
    });
    state.slots = targets;
  }

  function runSequence(targets) {
    var cap = hardCapMs(targets.length);
    state.hardCap = window.setTimeout(function () { finish('cap'); }, cap);

    var idx = 0;

    function next() {
      if (state.finished) return;
      if (idx >= targets.length) {
        AGENTS.forEach(function (a) { hideCursor(a.id); });
        setStatus(PAGE_TYPE === 'checkout' ? 'Checkout ready' : (PAGE_TYPE === 'product' ? 'Product ready' : 'Shop ready'));
        later(function () { finish('complete'); }, 600);
        return;
      }

      var item = targets[idx];
      idx += 1;
      var agentId = item.agent || AGENTS[(idx - 1) % AGENTS.length].id;
      var label = agentLabel(agentId);

      if (!item.el || !document.contains(item.el)) {
        later(next, 40);
        return;
      }

      setStatus(label + ' is placing ' + item.label + '…');
      hideOtherCursors(agentId);

      ensureVisible(item.el, function () {
        if (state.finished) return;
        var pt = pointFor(item.el);
        // Deliberate cursor travel (~400–560ms)
        moveCursor(agentId, pt.x, pt.y, 400 + Math.min(160, (idx % 3) * 50), function () {
          if (state.finished) return;

          var doPlace = function () {
            placeSlot(item, function () {
              // Longer pause between placements (~400–800ms)
              later(next, 420 + (item.mode === 'card' ? 220 : 80) + ((idx % 3) * 60));
            });
          };

          if (item.type && canType(item.el)) {
            var originalHtml = item.el.innerHTML;
            var text = (item.el.textContent || '').replace(/\s+/g, ' ').trim();
            state.typedRestore.push({ el: item.el, html: originalHtml });
            item.el.classList.add('sa-agents-typing');
            placeSlot(item, function () {
              // cps ~18–22 → ~45–55ms/char
              typeText(item.el, text, text.length > 60 ? 22 : 18, function () {
                item.el.classList.remove('sa-agents-typing');
                // Restore exact markup (links etc.) after typewriter
                try { item.el.innerHTML = originalHtml; } catch (e) { /* ignore */ }
                later(next, 320);
              });
            });
          } else {
            doPlace();
          }
        });
      });
    }

    next();
  }

  function bindSkip(root) {
    function onSkip(e) {
      if (state.finished) return;
      if (e) {
        // Don't block default for links outside overlay — overlay catches clicks.
        if (e.type === 'keydown') e.preventDefault();
        if (e.type === 'click') e.preventDefault();
      }
      finish('skip');
    }
    root.addEventListener('click', onSkip);
    function onKey(e) {
      if (state.finished) return;
      if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') {
        onSkip(e);
      }
    }
    document.addEventListener('keydown', onKey, true);
    state._onKey = onKey;
  }

  function start(force) {
    if (state.root && !state.finished) return;
    state.finished = false;
    state.cursors = {};
    state.timers = [];
    state.rafs = [];
    state.slots = [];
    state.typedRestore = [];

    if (!force && prefersReducedMotion()) {
      document.body.classList.add('sa-agents-built');
      return;
    }

    var targets = collectTargets();
    if (!targets.length) {
      document.body.classList.add('sa-agents-built');
      return;
    }

    document.documentElement.classList.add('sa-agents-building');
    document.body.classList.add('sa-agents-building');
    document.body.classList.remove('sa-agents-built');

    prepareSlots(targets);
    buildOverlay();
    bindSkip(state.root);

    try { state.root.focus({ preventScroll: true }); } catch (e) {
      try { state.root.focus(); } catch (e2) { /* ignore */ }
    }

    // Let layout settle so getBoundingClientRect is accurate
    later(function () { runSequence(targets); }, 100);
  }

  function attachReplayControls() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('[data-sa-agents-replay]');
      if (!btn) return;
      e.preventDefault();
      clearBuilt();
      if (state.root && !state.finished) {
        finish('replay-reset');
        later(function () { start(true); }, 400);
      } else {
        start(true);
      }
    });
  }

  function boot() {
    clearBuilt();
    attachReplayControls();
    start(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.saAgentsBuildReplay = function () {
    clearBuilt();
    if (state.root && !state.finished) finish('replay-reset');
    later(function () { start(true); }, 120);
  };
})();
