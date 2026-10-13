(function () {
  var f = document.querySelector('[data-sa-svc-form]');
  if (!f) return;
  var slotsBox = f.querySelector('[data-sa-slots]'), dateIn = f.querySelector('[data-sa-date]');
  var optBox = f.querySelector('[data-sa-option]'), optSel = optBox.querySelector('select');
  var summary = f.querySelector('[data-sa-summary]');
  function val(n) { var el = f.querySelector('[name="' + n + '"]:checked') || f.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }
  function region() { var l = f.querySelector('[name=location]:checked'); return l ? l.getAttribute('data-region') : 'US'; }
  function refreshPrices() {
    var r = region();
    f.querySelectorAll('[data-price-ke]').forEach(function (em) { em.textContent = em.getAttribute(r === 'KE' ? 'data-price-ke' : 'data-price-us'); });
    var s = f.querySelector('[name=service]:checked'), opts = s ? JSON.parse(s.getAttribute('data-kes') || '[]') : [];
    optSel.innerHTML = '';
    if (r === 'KE' && opts.length) {
      opts.forEach(function (o) { var op = document.createElement('option'); op.value = o.label; op.textContent = o.label + ' — KES ' + Number(o.price).toLocaleString(); optSel.appendChild(op); });
      optBox.hidden = false;
    } else { optBox.hidden = true; }
    renderSummary();
  }
  function renderSummary() {
    var s = f.querySelector('[name=service]:checked'), l = f.querySelector('[name=location]:checked'), t = f.querySelector('[name=time]:checked');
    if (!s) { summary.innerHTML = ''; return; }
    var name = s.parentNode.querySelector('strong').textContent, price = s.parentNode.querySelector('em').textContent;
    if (!optBox.hidden && optSel.value) price = optSel.options[optSel.selectedIndex].textContent.split(' — ')[1];
    summary.innerHTML = '<strong>' + name + '</strong> · ' + price + (dateIn.value ? ' · ' + dateIn.value : '') + (t ? ' ' + t.value : '') + (l ? '<br><small>' + l.parentNode.querySelector('strong').textContent + '</small>' : '');
  }
  function loadSlots() {
    if (!dateIn.value) return;
    slotsBox.innerHTML = '<p class="sa-svc-muted">Checking availability…</p>';
    fetch(saSvc.ajax + '?action=sa_svc_slots&date=' + encodeURIComponent(dateIn.value) + '&location=' + encodeURIComponent(val('location')), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (j) {
        var s = (j && j.data && j.data.slots) || [];
        if (!s.length) { slotsBox.innerHTML = '<p class="sa-svc-muted">No free slots that day (we are closed Sundays). Try another date.</p>'; renderSummary(); return; }
        slotsBox.innerHTML = s.map(function (t, i) { return '<label class="sa-svc-slot"><input type="radio" name="time" value="' + t + '" required' + (i === 0 ? '' : '') + '><span>' + t + '</span></label>'; }).join('');
        renderSummary();
      }).catch(function () { slotsBox.innerHTML = '<p class="sa-svc-muted">Could not load times. Please retry.</p>'; });
  }
  f.addEventListener('change', function (e) {
    var n = e.target.name;
    if (n === 'service' || n === 'location') refreshPrices();
    if (n === 'location' || e.target === dateIn) loadSlots();
    if (n === 'time' || n === 'option') renderSummary();
  });
  var veh = f.querySelector('[data-sa-vehicle]');
  if (veh) veh.addEventListener('change', function () {
    var o = veh.options[veh.selectedIndex]; if (!o.getAttribute('data-v')) return;
    var v = JSON.parse(o.getAttribute('data-v'));
    ['make', 'model', 'year', 'reg'].forEach(function (k) { f.querySelector('[name=' + k + ']').value = v[k] || ''; });
  });
  f.addEventListener('submit', function (e) { if (!f.querySelector('[name=time]:checked')) { e.preventDefault(); slotsBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); slotsBox.classList.add('is-error'); } });
  refreshPrices();
})();
