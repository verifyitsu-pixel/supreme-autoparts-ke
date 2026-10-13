(function () {
  'use strict';
  var box = document.querySelector('[data-sa-pf]');
  if (!box) return;
  var form = box.querySelector('[data-sa-pf-form]');
  var mq = window.matchMedia('(max-width: 900px)');

  function open(o) {
    box.classList.toggle('is-open', o);
    document.body.classList.toggle('sa-pf-lock', o);
    document.querySelectorAll('[data-sa-pf-open]').forEach(function (b) { b.setAttribute('aria-expanded', o ? 'true' : 'false'); });
  }
  document.querySelectorAll('[data-sa-pf-open]').forEach(function (b) { b.addEventListener('click', function () { open(true); }); });
  box.querySelectorAll('[data-sa-pf-close]').forEach(function (b) { b.addEventListener('click', function () { open(false); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') open(false); });

  // Multi-select checkboxes -> one comma list field.
  function syncMulti() {
    box.querySelectorAll('[data-sa-pf-multi]').forEach(function (h) {
      var vals = [];
      box.querySelectorAll('[data-sa-pf-multi-opt="' + h.name + '"]:checked').forEach(function (c) { vals.push(c.value); });
      h.value = vals.join(',');
    });
  }

  // Price slider.
  var range = box.querySelector('[data-sa-pf-range]');
  var pmin = 0, pmax = 0;
  if (range) {
    pmin = +range.dataset.min; pmax = +range.dataset.max;
    var lo = range.querySelector('[data-lo]'), hi = range.querySelector('[data-hi]');
    var loN = range.querySelector('[data-lo-num]'), hiN = range.querySelector('[data-hi-num]');
    var fill = range.querySelector('[data-fill]');
    var paint = function () {
      var a = (+lo.value - pmin) / (pmax - pmin) * 100, b = (+hi.value - pmin) / (pmax - pmin) * 100;
      fill.style.left = a + '%'; fill.style.width = Math.max(0, b - a) + '%';
    };
    lo.addEventListener('input', function () { if (+lo.value > +hi.value) lo.value = hi.value; loN.value = lo.value; paint(); });
    hi.addEventListener('input', function () { if (+hi.value < +lo.value) hi.value = lo.value; hiN.value = hi.value; paint(); });
    loN.addEventListener('input', function () { if (loN.value !== '') lo.value = loN.value; paint(); });
    hiN.addEventListener('input', function () { if (hiN.value !== '') hi.value = hiN.value; paint(); });
    paint();
    if (!mq.matches) {
      lo.addEventListener('change', function () { submit(); });
      hi.addEventListener('change', function () { submit(); });
    }
  }

  function submit() {
    syncMulti();
    // Drop empty / default fields for clean, shareable URLs.
    form.querySelectorAll('input[name], select[name]').forEach(function (el) {
      var v = (el.type === 'checkbox') ? (el.checked ? el.value : '') : el.value;
      if (el.name === 'min_price' && range && +v <= pmin) v = '';
      if (el.name === 'max_price' && range && v !== '' && +v >= pmax) v = '';
      if (v === '' || el.disabled) el.disabled = true;
    });
    form.submit();
  }
  form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });

  // Vehicle cascade: changing make resets model/year; reload so dependent lists fill.
  box.querySelectorAll('[data-sa-pf-vehicle]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var k = sel.getAttribute('data-sa-pf-vehicle');
      if (k === 'make') {
        ['model', 'year'].forEach(function (n) { var s = box.querySelector('[data-sa-pf-vehicle="' + n + '"]'); if (s) s.value = ''; });
      } else if (k === 'model') {
        var y = box.querySelector('[data-sa-pf-vehicle="year"]'); if (y) y.value = '';
      }
      if (mq.matches) sessionStorage.setItem('saPfReopen', '1');
      submit();
    });
  });
  if (mq.matches && sessionStorage.getItem('saPfReopen') === '1') { sessionStorage.removeItem('saPfReopen'); open(true); }

  // Desktop: checkboxes apply instantly. Mobile: wait for Apply.
  box.querySelectorAll('input[type="checkbox"]').forEach(function (c) {
    c.addEventListener('change', function () { if (!mq.matches) submit(); });
  });

  box.querySelectorAll('[data-sa-pf-more]').forEach(function (b) {
    b.addEventListener('click', function () { var l = b.previousElementSibling; l.classList.toggle('is-expanded'); b.textContent = l.classList.contains('is-expanded') ? 'Show less' : 'Show all'; });
  });
  box.querySelectorAll('[data-sa-pf-find]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      inp.parentNode.querySelectorAll('.sa-pf__check[data-name]').forEach(function (l) { l.style.display = l.dataset.name.indexOf(q) === -1 ? 'none' : ''; });
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
  });
})();
