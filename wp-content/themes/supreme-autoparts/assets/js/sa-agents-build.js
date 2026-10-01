/**
 * Supreme Autoparts — staged "agents assemble the page" intro overlay.
 * Fake labeled cursors (Grok, GPT, Gemini, Opus, Muse) build a mock shop UI,
 * then reveal the real site. sessionStorage flag skips same-tab revisits.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'sap-built';
  var HARD_CAP_MS = 10000;
  var WA_DISPLAY = '+1 917 437 5121';
  var WA_URL = 'https://wa.me/19174375121';

  var cfg = (window.saAgentsBuild && typeof window.saAgentsBuild === 'object')
    ? window.saAgentsBuild
    : {};
  if (cfg.waDisplay) WA_DISPLAY = String(cfg.waDisplay);
  if (cfg.waUrl) WA_URL = String(cfg.waUrl);
  if (cfg.storageKey) STORAGE_KEY = String(cfg.storageKey);
  var CRITICAL_UI = !!(cfg.criticalUi && Number(cfg.criticalUi) === 1);

  var POINTER_SVG =
    '<svg class="sa-agents-build__cursor-pointer" viewBox="0 0 18 22" width="18" height="22" aria-hidden="true" focusable="false">' +
    '<path fill="currentColor" stroke="#0B0B0D" stroke-width="1.2" d="M1.2 1.2l15.2 8.4-6.6 1.7 3.8 7.4-2.7 1.4-3.9-7.5-5.8 4.4z"/>' +
    '</svg>';

  var WA_SVG =
    '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' +
    '<path fill="currentColor" d="M12.04 2C6.58 2 2.15 6.4 2.15 11.84c0 1.97.58 3.8 1.58 5.35L2 22l4.97-1.64a9.86 9.86 0 0 0 5.07 1.4h.01c5.46 0 9.89-4.4 9.89-9.84C21.94 6.4 17.5 2 12.04 2zm5.75 13.98c-.24.68-1.4 1.3-1.93 1.38-.5.08-1.13.11-1.82-.11-.42-.14-.96-.31-1.66-.61-2.92-1.26-4.82-4.2-4.97-4.39-.14-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.26-.3.58-.37.77-.37h.56c.18 0 .42-.07.66.5.24.58.82 2 .89 2.15.07.14.12.32.02.51-.1.2-.14.32-.28.5-.14.17-.3.38-.42.51-.14.14-.28.29-.12.56.16.28.7 1.15 1.5 1.86 1.03.92 1.9 1.2 2.17 1.34.27.14.43.12.59-.07.16-.2.68-.79.86-1.06.18-.28.36-.23.61-.14.24.1 1.56.74 1.83.87.27.14.45.2.52.31.07.12.07.68-.17 1.36z"/>' +
    '</svg>';

  var ICON_ENGINE =
    '<svg class="sa-agents-build__cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h3l2-3h4l2 3h3v7H4v-7z"/><path d="M14 10V7h3"/><circle cx="9" cy="14" r="1.2"/><circle cx="15" cy="14" r="1.2"/></svg>';
  var ICON_TRANS =
    '<svg class="sa-agents-build__cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7" cy="12" r="3"/><circle cx="17" cy="8" r="2.5"/><circle cx="17" cy="16" r="2.5"/><path d="M10 12h4.5M17 10.5v3"/></svg>';
  var ICON_AXLE =
    '<svg class="sa-agents-build__cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5" cy="12" r="2.5"/><circle cx="19" cy="12" r="2.5"/><path d="M7.5 12h9"/><path d="M12 9v6"/></svg>';
  var ICON_LIGHT =
    '<svg class="sa-agents-build__cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 14c0-3.5 2.5-6 7-6s7 2.5 7 6v2H5v-2z"/><path d="M9 18h6"/><path d="M8 8l-1.5-2M16 8l1.5-2M12 6V3.5"/></svg>';
  var ICON_PART =
    '<svg viewBox="0 0 48 40" width="40" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="10" width="32" height="20" rx="3"/><path d="M14 20h20M18 14v12M30 14v12"/></svg>';
  var ICON_SHIP =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h13v10H3z"/><path d="M16 10h3l2 3v4h-5V10z"/><circle cx="7.5" cy="18.5" r="1.5"/><circle cx="17.5" cy="18.5" r="1.5"/></svg>';
  var ICON_SHIELD =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>';
  var ICON_LOCK =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h16v8H4z"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>';

  var AGENTS = [
    { id: 'grok', label: 'Grok' },
    { id: 'gpt', label: 'GPT' },
    { id: 'gemini', label: 'Gemini' },
    { id: 'opus', label: 'Opus' },
    { id: 'muse', label: 'Muse' },
  ];

  var state = {
    root: null,
    cursors: {},
    timers: [],
    rafs: [],
    finished: false,
    hardCap: null,
    reduced: false,
  };

  function prefersReducedMotion() {
    try {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  function alreadyBuilt() {
    try {
      return sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function markBuilt() {
    try {
      sessionStorage.setItem(STORAGE_KEY, '1');
    } catch (e) { /* ignore */ }
  }

  function clearBuilt() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (e) { /* ignore */ }
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

  function typeText(el, text, cps, onDone) {
    var i = 0;
    var caret = document.createElement('span');
    caret.className = 'sab-caret';
    el.textContent = '';
    el.appendChild(caret);
    var delay = Math.max(18, Math.floor(1000 / (cps || 28)));

    function tick() {
      if (state.finished) return;
      if (i >= text.length) {
        caret.classList.add('is-off');
        if (onDone) onDone();
        return;
      }
      el.insertBefore(document.createTextNode(text.charAt(i)), caret);
      i += 1;
      later(tick, delay);
    }
    tick();
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
    var dur = Math.max(120, duration || 500);

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
        var raf = window.requestAnimationFrame(frame);
        state.rafs.push(raf);
      } else if (onDone) {
        onDone();
      }
    }
    var raf0 = window.requestAnimationFrame(frame);
    state.rafs.push(raf0);
  }

  function hideCursor(agentId) {
    var node = state.cursors[agentId];
    if (node) node.classList.remove('is-on');
  }

  function stagePoint(sel, ox, oy) {
    var root = state.root;
    var el = root.querySelector(sel);
    if (!el) return { x: 80, y: 80 };
    var rr = root.getBoundingClientRect();
    var er = el.getBoundingClientRect();
    return {
      x: er.left - rr.left + (ox != null ? ox : er.width * 0.35),
      y: er.top - rr.top + (oy != null ? oy : er.height * 0.45),
    };
  }

  function buildDOM() {
    var root = document.createElement('div');
    root.id = 'sa-agents-build';
    root.className = 'sa-agents-build';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Building the shop experience');
    root.setAttribute('aria-hidden', 'false');
    root.tabIndex = -1;

    root.innerHTML =
      '<div class="sa-agents-build__canvas" aria-hidden="true"></div>' +
      '<p class="sa-agents-build__skip">Click anywhere to skip</p>' +
      '<div class="sa-agents-build__stage">' +
        '<div class="sa-agents-build__brand">' +
          '<h1 class="sa-agents-build__logo" data-sab-logo></h1>' +
          '<p class="sa-agents-build__tagline" data-sab-tagline></p>' +
        '</div>' +
        '<a class="sa-agents-build__wa" data-sab-wa href="' + WA_URL + '" target="_blank" rel="noopener noreferrer" tabindex="-1">' +
          WA_SVG + '<span>WhatsApp ' + WA_DISPLAY + '</span>' +
        '</a>' +
        '<div class="sa-agents-build__cats" data-sab-cats>' +
          '<div class="sa-agents-build__cat" data-sab-cat="0">' + ICON_ENGINE + '<strong>Engines</strong></div>' +
          '<div class="sa-agents-build__cat" data-sab-cat="1">' + ICON_TRANS + '<strong>Transmissions</strong></div>' +
          '<div class="sa-agents-build__cat" data-sab-cat="2">' + ICON_AXLE + '<strong>Axles</strong></div>' +
          '<div class="sa-agents-build__cat" data-sab-cat="3">' + ICON_LIGHT + '<strong>Lights</strong></div>' +
        '</div>' +
        '<div class="sa-agents-build__feature" data-sab-feature>' +
          '<div class="sa-agents-build__feature-img">' + ICON_PART + '</div>' +
          '<div class="sa-agents-build__feature-meta">' +
            '<p class="sa-agents-build__feature-label">Featured OEM</p>' +
            '<p class="sa-agents-build__feature-title">2JZ-GTE Complete Engine Assembly</p>' +
            '<p class="sa-agents-build__feature-price">From $4,850 · used OEM</p>' +
            '<div class="sab-line sab-line--title" aria-hidden="true"></div>' +
            '<div class="sab-line sab-line--sub" aria-hidden="true"></div>' +
            '<div class="sab-line sab-line--price" aria-hidden="true"></div>' +
          '</div>' +
        '</div>' +
        '<ul class="sa-agents-build__trust" data-sab-trust>' +
          '<li data-sab-trust-i="0">' + ICON_SHIP + '<span>Worldwide shipping</span></li>' +
          '<li data-sab-trust-i="1">' + ICON_SHIELD + '<span>Inspected · warranty options</span></li>' +
          '<li data-sab-trust-i="2">' + ICON_LOCK + '<span>Secure checkout</span></li>' +
        '</ul>' +
      '</div>' +
      '<div class="sa-agents-build__cursors" data-sab-cursors aria-hidden="true"></div>' +
      '<p class="sa-agents-build__status" data-sab-status>Agents assembling your shop…</p>';

    var cursorsWrap = root.querySelector('[data-sab-cursors]');
    AGENTS.forEach(function (a, idx) {
      var c = document.createElement('div');
      c.className = 'sa-agents-build__cursor sa-agents-build__cursor--' + a.id;
      c.dataset.agent = a.id;
      c.innerHTML = POINTER_SVG + '<span class="sa-agents-build__cursor-pill">' + a.label + '</span>';
      c._x = 40 + idx * 28;
      c._y = 40 + idx * 18;
      c.style.transform = 'translate3d(' + c._x + 'px,' + c._y + 'px,0)';
      cursorsWrap.appendChild(c);
      state.cursors[a.id] = c;
    });

    document.body.appendChild(root);
    document.body.classList.add('sa-agents-building');
    state.root = root;
    return root;
  }

  function setStatus(text) {
    if (!state.root) return;
    var el = state.root.querySelector('[data-sab-status]');
    if (el) el.textContent = text;
  }

  function finish(reason) {
    if (state.finished) return;
    state.finished = true;
    clearAllTimers();
    markBuilt();

    var root = state.root;
    // Always restore page interactivity immediately (critical on cart/checkout).
    document.body.classList.remove('sa-agents-building');
    if (!root) {
      return;
    }

    root.style.pointerEvents = 'none';
    root.classList.add('is-done');
    root.setAttribute('aria-hidden', 'true');
    root.removeAttribute('aria-modal');
    root.removeAttribute('aria-label');

    // Return focus to body / first focusable so we don't trap
    try {
      if (document.activeElement && root.contains(document.activeElement)) {
        document.activeElement.blur();
      }
    } catch (e) { /* ignore */ }

    // Skip/cap/critical UI: tear down ASAP so pay buttons stay usable.
    var removeMs = (reason === 'skip' || reason === 'cap' || reason === 'wa' || CRITICAL_UI) ? 80 : 420;
    window.setTimeout(function () {
      if (root && root.parentNode) root.parentNode.removeChild(root);
      state.root = null;
      state.cursors = {};
    }, removeMs);

    // Expose for replay debugging
    try {
      window.dispatchEvent(new CustomEvent('sa-agents-build:done', { detail: { reason: reason || 'complete' } }));
    } catch (e) { /* ignore */ }
  }

  function runSequence() {
    var logo = state.root.querySelector('[data-sab-logo]');
    var tagline = state.root.querySelector('[data-sab-tagline]');
    var wa = state.root.querySelector('[data-sab-wa]');
    var feature = state.root.querySelector('[data-sab-feature]');
    var cats = state.root.querySelectorAll('[data-sab-cat]');
    var trustItems = state.root.querySelectorAll('[data-sab-trust-i]');

    // Hard failsafe
    state.hardCap = window.setTimeout(function () {
      finish('cap');
    }, HARD_CAP_MS);

    // t≈0: Grok types logo
    setStatus('Grok is writing the brand…');
    var p0 = stagePoint('[data-sab-logo]', 24, 20);
    moveCursor('grok', p0.x, p0.y, 420, function () {
      typeText(logo, 'Supreme Autoparts', 32, function () {
        hideCursor('grok');

        // t≈1.2: Gemini types tagline
        setStatus('Gemini is drafting the tagline…');
        var p1 = stagePoint('[data-sab-tagline]', 20, 12);
        moveCursor('gemini', p1.x, p1.y, 380, function () {
          typeText(
            tagline,
            'Genuine used OEM parts — engines, drivetrain & lighting. Ships US & worldwide.',
            42,
            function () {
              hideCursor('gemini');

              // t≈3.2: GPT places WhatsApp pill
              setStatus('GPT is placing WhatsApp…');
              var p2 = stagePoint('[data-sab-wa]', 30, 18);
              moveCursor('gpt', p2.x, p2.y, 360, function () {
                wa.classList.add('is-in');
                later(function () {
                  hideCursor('gpt');

                  // t≈4.0: Opus draws category cards
                  setStatus('Opus is laying out categories…');
                  var i = 0;
                  function nextCat() {
                    if (state.finished) return;
                    if (i >= cats.length) {
                      hideCursor('opus');
                      // Featured card
                      setStatus('Muse is filling a featured part…');
                      var pf = stagePoint('[data-sab-feature]', 40, 30);
                      moveCursor('muse', pf.x, pf.y, 340, function () {
                        feature.classList.add('is-in');
                        later(function () {
                          feature.classList.add('is-filled');
                          // hide placeholder lines once text is visible
                          later(function () {
                            hideCursor('muse');
                            // Trust row
                            setStatus('Grok is adding trust signals…');
                            var ti = 0;
                            function nextTrust() {
                              if (state.finished) return;
                              if (ti >= trustItems.length) {
                                hideCursor('grok');
                                setStatus('Shop ready');
                                later(function () { finish('complete'); }, 420);
                                return;
                              }
                              var item = trustItems[ti];
                              var pt = stagePoint('[data-sab-trust-i="' + ti + '"]', 18, 10);
                              moveCursor('grok', pt.x, pt.y, 220, function () {
                                item.classList.add('is-in');
                                ti += 1;
                                later(nextTrust, 160);
                              });
                            }
                            nextTrust();
                          }, 380);
                        }, 180);
                      });
                      return;
                    }
                    var cat = cats[i];
                    var pc = stagePoint('[data-sab-cat="' + i + '"]', 28, 24);
                    moveCursor('opus', pc.x, pc.y, 260, function () {
                      cat.classList.add('is-in');
                      later(function () { cat.classList.add('is-drawn'); }, 80);
                      i += 1;
                      later(nextCat, 200);
                    });
                  }
                  nextCat();
                }, 220);
              });
            }
          );
        });
      });
    });
  }

  function bindSkip(root) {
    function onSkip(e) {
      if (state.finished) return;
      // Allow WhatsApp link click to also skip (and navigate)
      if (e && e.target && e.target.closest && e.target.closest('[data-sab-wa]')) {
        finish('wa');
        return;
      }
      if (e) {
        e.preventDefault();
      }
      finish('skip');
    }
    root.addEventListener('click', onSkip);
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') {
        onSkip(e);
      }
    });
  }

  function start(force) {
    if (state.root && !state.finished) return;
    state.finished = false;
    state.cursors = {};
    state.timers = [];
    state.rafs = [];

    if (!force && prefersReducedMotion()) {
      markBuilt();
      return;
    }
    if (!force && alreadyBuilt()) return;

    buildDOM();
    bindSkip(state.root);
    // Focus overlay for a11y without trapping forever (finish removes it)
    try { state.root.focus({ preventScroll: true }); } catch (e) {
      try { state.root.focus(); } catch (e2) { /* ignore */ }
    }
    // Small delay so layout measures correctly
    later(runSequence, 60);
  }

  function attachReplayControls() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('[data-sa-agents-replay]');
      if (!btn) return;
      e.preventDefault();
      clearBuilt();
      // If one is mid-flight, finish first
      if (state.root && !state.finished) {
        finish('replay-reset');
        later(function () { start(true); }, 520);
      } else {
        start(true);
      }
    });
  }

  function boot() {
    attachReplayControls();
    start(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // Public hook for console / future
  window.saAgentsBuildReplay = function () {
    clearBuilt();
    start(true);
  };
})();
