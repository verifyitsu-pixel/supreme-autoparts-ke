(function () {
  var f = document.querySelector('[data-sa-svc-form]');
  if (!f) return;
  var q = function (s) { return f.querySelector(s); }, qa = function (s) { return Array.prototype.slice.call(f.querySelectorAll(s)); };
  var slotsBox = q('[data-sa-slots]'), dateIn = q('[data-sa-date]'), optBox = q('[data-sa-option]'), optSel = optBox.querySelector('select');
  var summary = document.querySelector('[data-sa-summary]'), prog = document.querySelectorAll('[data-sa-progress] li');
  var steps = qa('[data-step]'), cur = 0, next = q('[data-sa-next]'), back = q('[data-sa-back]'), submit = q('[data-sa-submit]');
  function val(n) { var el = q('[name="' + n + '"]:checked') || q('[name="' + n + '"]'); return el ? el.value : ''; }
  function region() { var l = q('[name=location]:checked'); return l ? l.getAttribute('data-region') : 'US'; }
  function fmt(m) { return m >= 60 ? Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '') : m + ' min'; }
  function addonMins() { return qa('[name="addons[]"]:checked').reduce(function (a, c) { return a + (+c.getAttribute('data-dur')); }, 0); }
  function show(i) {
    cur = i;
    steps.forEach(function (s, k) { s.classList.toggle('is-active', k === i); });
    Array.prototype.forEach.call(prog, function (p, k) { p.classList.toggle('is-on', k <= i); });
    back.hidden = i === 0; next.hidden = i === steps.length - 1; submit.hidden = i !== steps.length - 1;
    var top = f.getBoundingClientRect().top + window.scrollY - 140; if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' });
  }
  function valid(i) {
    var ok = true;
    steps[i].querySelectorAll('input,select,textarea').forEach(function (el) { if (ok && !el.closest('[hidden]') && !el.checkValidity()) { el.reportValidity(); ok = false; } });
    if (ok && i === 3 && !q('[name=time]:checked')) { slotsBox.classList.add('is-error'); ok = false; }
    return ok;
  }
  next.addEventListener('click', function () { if (valid(cur)) show(cur + 1); });
  back.addEventListener('click', function () { show(cur - 1); });
  function refreshPrices() {
    var r = region();
    qa('[data-price-ke]').forEach(function (em) { em.textContent = em.getAttribute(r === 'KE' ? 'data-price-ke' : 'data-price-us'); });
    var s = q('[name=service]:checked'), opts = s ? JSON.parse(s.getAttribute('data-kes') || '[]') : [];
    optSel.innerHTML = '';
    if (r === 'KE' && opts.length) {
      opts.forEach(function (o) { var op = document.createElement('option'); op.value = o.label; op.textContent = o.label + ' — KES ' + Number(o.price).toLocaleString(); optSel.appendChild(op); });
      optBox.hidden = false;
    } else optBox.hidden = true;
    qa('[data-addon]').forEach(function (a) { var same = s && a.getAttribute('data-addon') === s.value; a.hidden = same; if (same) a.querySelector('input').checked = false; });
    renderSummary();
  }
  function renderSummary() {
    var s = q('[name=service]:checked'), l = q('[name=location]:checked'), t = q('[name=time]:checked'), md = q('[name=mode]:checked');
    if (!s) { summary.innerHTML = '<p class="sa-svc-muted">Choose a service.</p>'; return; }
    var lab = s.parentNode, price = lab.querySelector('em').textContent;
    if (!optBox.hidden && optSel.value) price = optSel.options[optSel.selectedIndex].textContent.split(' — ')[1];
    var mins = +s.getAttribute('data-dur') + addonMins();
    var rows = [['Service', lab.querySelector('strong').textContent], ['Estimate', price]];
    qa('[name="addons[]"]:checked').forEach(function (c) { var sp = c.parentNode.querySelector('span'); rows.push(['Add-on', sp.firstChild.textContent.trim() + ' · ' + sp.querySelector('em').textContent]); });
    rows.push(['Duration', '≈ ' + fmt(mins) + ' (estimate)']);
    var veh = [val('year'), q('[name=make]').value, q('[name=model]').value].join(' ').trim(); if (veh) rows.unshift(['Vehicle', veh]);
    if (l) rows.push(['Where', l.parentNode.querySelector('strong').textContent]);
    if (md) rows.push(['Handover', md.value === 'pickup' ? 'Pickup (fee confirmed on booking)' : 'Drop-off']);
    if (dateIn.value) rows.push(['When', dateIn.value + (t ? ' · ' + t.value : '')]);
    rows.push(['Pay', 'At service, after you approve the quote']);
    summary.innerHTML = '<dl>' + rows.map(function (r) { return '<dt>' + r[0] + '</dt><dd>' + r[1].replace(/</g, '&lt;') + '</dd>'; }).join('') + '</dl>';
  }
  function loadSlots() {
    if (!dateIn.value) return;
    slotsBox.classList.remove('is-error');
    slotsBox.innerHTML = '<p class="sa-svc-muted">Checking availability…</p>';
    fetch(saSvc.ajax + '?action=sa_svc_slots&date=' + encodeURIComponent(dateIn.value) + '&location=' + encodeURIComponent(val('location')) + '&service=' + encodeURIComponent(val('service')) + '&extra=' + addonMins(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (j) {
        var s = (j && j.data && j.data.slots) || [];
        if (!s.length) { slotsBox.innerHTML = '<p class="sa-svc-muted">No free slots that day for this job length (we are closed on Sundays). Try another date.</p>'; renderSummary(); return; }
        slotsBox.innerHTML = s.map(function (t) { return '<label class="sa-svc-slot"><input type="radio" name="time" value="' + t + '" required><span>' + t + '</span></label>'; }).join('');
        renderSummary();
      }).catch(function () { slotsBox.innerHTML = '<p class="sa-svc-muted">Could not load times. Please retry.</p>'; });
  }
  function togglePickup() {
    var us = region() === 'US', po = q('[data-sa-pickup-opt]'), pk = q('[data-sa-pickup]'), ta = pk.querySelector('textarea');
    po.hidden = us; if (us) q('[name=mode][value=dropoff]').checked = true;
    var on = !us && val('mode') === 'pickup'; pk.hidden = !on; ta.required = on; renderSummary();
  }
  var vdbEl = document.querySelector('[data-sa-vdb]'), vdb = vdbEl ? JSON.parse(vdbEl.textContent) : {};
  function models() { var mk = q('[name=make]').value.trim().toLowerCase(), dl = document.getElementById('sa-models'), list = [];
    Object.keys(vdb).forEach(function (k) { if (k.toLowerCase() === mk) list = vdb[k]; });
    dl.innerHTML = list.map(function (m) { return '<option value="' + m + '">'; }).join(''); }
  f.addEventListener('input', function (e) { if (e.target.name === 'make') models(); if (['make', 'model'].indexOf(e.target.name) > -1) renderSummary(); });
  f.addEventListener('change', function (e) {
    var n = e.target.name;
    if (n === 'service' || n === 'location') refreshPrices();
    if (n === 'location' || n === 'service' || n === 'addons[]' || e.target === dateIn) loadSlots();
    if (n === 'mode' || n === 'location') togglePickup();
    renderSummary();
  });
  var veh = q('[data-sa-vehicle]');
  if (veh) veh.addEventListener('change', function () {
    var o = veh.options[veh.selectedIndex]; if (!o.getAttribute('data-v')) return;
    var v = JSON.parse(o.getAttribute('data-v'));
    ['make', 'model', 'year', 'reg'].forEach(function (k) { q('[name=' + k + ']').value = v[k] || ''; }); models(); renderSummary();
  });
  f.addEventListener('submit', function (e) { if (!q('[name=time]:checked')) { e.preventDefault(); show(3); slotsBox.classList.add('is-error'); } });
  models(); togglePickup(); refreshPrices(); show(0);
})();
