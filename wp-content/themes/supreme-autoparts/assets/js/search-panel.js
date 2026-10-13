/* Header search panel: optional shop filters under the search bar. */
(function () {
  'use strict';
  var forms = document.querySelectorAll('form.sa-search[role="search"]');
  if (!forms.length || !window.saSearchPanel) return;
  var cfg = window.saSearchPanel;
  var mq = window.matchMedia('(max-width: 900px)');
  var qs = new URLSearchParams(location.search);
  var data = null, loading = null, activeForm = null;

  var panel = document.createElement('div');
  panel.className = 'sa-sp';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Search and filters');
  panel.innerHTML =
    '<form class="sa-sp__form" action="#" novalidate>' +
    '<div class="sa-sp__top"><input type="search" class="sa-sp__q" name="s" placeholder="Part number, brand, or keyword…" autocomplete="off" enterkeyhint="search">' +
    '<button type="button" class="sa-sp__x" aria-label="Close">&times;</button></div>' +
    '<div class="sa-sp__body">' +
    '<p class="sa-sp__hint">Optional filters — set any, or just search.</p>' +
    '<div class="sa-sp__sec"><div class="sa-sp__h">Your vehicle</div><div class="sa-sp__row3">' +
    '<select data-k="make" aria-label="Make"><option value="">Make</option></select>' +
    '<select data-k="model" aria-label="Model" disabled><option value="">Model</option></select>' +
    '<select data-k="year" aria-label="Year" disabled><option value="">Year</option></select></div></div>' +
    '<div class="sa-sp__row2"><div class="sa-sp__sec"><div class="sa-sp__h">Category</div><select data-k="cat" aria-label="Category"><option value="">All categories</option></select></div>' +
    '<div class="sa-sp__sec"><div class="sa-sp__h">Brand</div><select data-k="brand" aria-label="Brand"><option value="">All brands</option></select></div></div>' +
    '<div class="sa-sp__sec"><div class="sa-sp__h">Price</div><div class="sa-sp__price">' +
    '<input type="number" inputmode="decimal" min="0" data-k="min" placeholder="Min"><span>–</span><input type="number" inputmode="decimal" min="0" data-k="max" placeholder="Max"></div></div>' +
    '<div class="sa-sp__checks"><label><input type="checkbox" data-k="instock"> In stock</label>' +
    '<label data-free hidden><input type="checkbox" data-k="freeship"> <span>Free shipping</span></label></div>' +
    '<p class="sa-sp__load" data-load>Loading filters…</p>' +
    '</div>' +
    '<div class="sa-sp__foot"><button type="button" class="sa-sp__clear">Clear</button><button type="submit" class="sa-sp__go">Search</button></div>' +
    '</form>';
  document.body.appendChild(panel);
  var f = panel.querySelector('form');
  var q = panel.querySelector('.sa-sp__q');
  var el = function (k) { return panel.querySelector('[data-k="' + k + '"]'); };

  function fill(sel, rows, first, keep) {
    var cur = keep !== undefined ? keep : sel.value;
    sel.innerHTML = '';
    var o = document.createElement('option'); o.value = ''; o.textContent = first; sel.appendChild(o);
    (rows || []).forEach(function (r) {
      var op = document.createElement('option');
      op.value = r.v; op.textContent = r.l + (r.n ? ' (' + r.n.toLocaleString() + ')' : '');
      if (r.u) op.dataset.u = r.u;
      sel.appendChild(op);
    });
    sel.value = cur; if (sel.value !== cur) sel.value = '';
    sel.disabled = !rows || !rows.length;
    if (sel === el('make') || sel === el('cat') || sel === el('brand')) sel.disabled = false;
  }
  function get(params) {
    var u = new URL(cfg.ajaxUrl, location.href);
    u.searchParams.set('action', 'sa_search_facets');
    Object.keys(params || {}).forEach(function (k) { if (params[k]) u.searchParams.set(k, params[k]); });
    return fetch(u.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { return j && j.success ? j.data : null; });
  }
  function vehicle(make, model) {
    return get({ vehicle_only: '1', sa_make: make, sa_model: model });
  }
  function load() {
    if (loading) return loading;
    var mk = qs.get('sa_make') || '', md = qs.get('sa_model') || '';
    loading = get({ sa_make: mk, sa_model: md }).then(function (d) {
      panel.querySelector('[data-load]').hidden = true;
      if (!d) return;
      data = d;
      fill(el('make'), d.makes, 'Make', mk);
      fill(el('model'), d.models, 'Model', md);
      fill(el('year'), d.years, 'Year', qs.get('sa_year') || '');
      if (!mk) el('model').disabled = true;
      if (!md) el('year').disabled = true;
      var curCat = (location.pathname.match(/product-category\/(?:.*\/)?([^\/]+)\/?$/) || [])[1] || '';
      fill(el('cat'), d.cats, 'All categories', curCat);
      fill(el('brand'), d.brands, 'All brands', (qs.get('filter_brand') || '').split(',')[0]);
      if (d.price && d.price.max) { el('min').placeholder = 'Min ' + d.cur + d.price.min; el('max').placeholder = 'Max ' + d.cur + d.price.max; }
      var fr = panel.querySelector('[data-free]');
      fr.hidden = !d.freeship;
      if (d.freeship) fr.querySelector('span').textContent = 'Free shipping (' + d.cur + d.freeMin + '+)';
    }).catch(function () { panel.querySelector('[data-load]').textContent = 'Filters unavailable — you can still search.'; });
    return loading;
  }
  el('make').addEventListener('change', function () {
    el('year').value = ''; fill(el('year'), [], 'Year', ''); el('year').disabled = true;
    fill(el('model'), [], 'Model', ''); el('model').disabled = true;
    if (!this.value) return;
    el('model').innerHTML = '<option value="">Loading…</option>';
    vehicle(this.value, '').then(function (d) { fill(el('model'), d ? d.models : [], 'Model', ''); });
  });
  el('model').addEventListener('change', function () {
    fill(el('year'), [], 'Year', ''); el('year').disabled = true;
    if (!this.value) return;
    el('year').innerHTML = '<option value="">Loading…</option>';
    vehicle(el('make').value, this.value).then(function (d) { fill(el('year'), d ? d.years : [], 'Year', ''); });
  });

  function place() {
    if (!activeForm) return;
    if (mq.matches) { panel.classList.add('is-sheet'); panel.style.top = panel.style.left = panel.style.width = ''; return; }
    panel.classList.remove('is-sheet');
    var r = activeForm.getBoundingClientRect();
    panel.style.top = (r.bottom + window.scrollY + 6) + 'px';
    panel.style.left = (r.left + window.scrollX) + 'px';
    panel.style.width = Math.max(r.width, 520) + 'px';
  }
  function open(form) {
    activeForm = form;
    var src = form.querySelector('input[name="s"]');
    if (!panel.hidden) return;
    q.value = src ? src.value : '';
    panel.hidden = false;
    place();
    if (mq.matches) { document.body.classList.add('sa-sp-lock'); }
    q.focus();
    try { q.setSelectionRange(q.value.length, q.value.length); } catch (e) {}
    load();
  }
  function close() {
    if (panel.hidden) return;
    panel.hidden = true;
    document.body.classList.remove('sa-sp-lock');
  }
  forms.forEach(function (form) {
    var inp = form.querySelector('input[name="s"]');
    if (!inp) return;
    inp.addEventListener('focus', function () { open(form); });
    inp.addEventListener('click', function () { open(form); });
  });
  panel.querySelector('.sa-sp__x').addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.addEventListener('mousedown', function (e) {
    if (panel.hidden || mq.matches) return;
    if (panel.contains(e.target) || (activeForm && activeForm.contains(e.target))) return;
    close();
  });
  window.addEventListener('resize', place);
  panel.querySelector('.sa-sp__clear').addEventListener('click', function () {
    q.value = '';
    ['make', 'cat', 'brand'].forEach(function (k) { el(k).value = ''; });
    fill(el('model'), [], 'Model', ''); el('model').disabled = true;
    fill(el('year'), [], 'Year', ''); el('year').disabled = true;
    el('min').value = el('max').value = ''; el('instock').checked = el('freeship').checked = false;
    q.focus();
  });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var cat = el('cat'), opt = cat.options[cat.selectedIndex];
    var base = (opt && opt.dataset.u) || '';
    var p = new URLSearchParams();
    var s = q.value.trim();
    if (s) { p.set('s', s); p.set('post_type', 'product'); }
    var add = function (k, v) { if (v) p.set(k, v); };
    add('sa_make', el('make').value);
    if (el('make').value) add('sa_model', el('model').value);
    if (el('model').value) add('sa_year', el('year').value);
    add('filter_brand', el('brand').value);
    add('min_price', el('min').value);
    add('max_price', el('max').value);
    if (el('instock').checked) p.set('instock', '1');
    if (el('freeship').checked && !panel.querySelector('[data-free]').hidden) p.set('freeship', '1');
    if (!base) base = s ? cfg.home : ((data && data.shop) || cfg.shop);
    var qstr = p.toString();
    location.href = base + (qstr ? (base.indexOf('?') === -1 ? '?' : '&') + qstr : '');
  });
})();
