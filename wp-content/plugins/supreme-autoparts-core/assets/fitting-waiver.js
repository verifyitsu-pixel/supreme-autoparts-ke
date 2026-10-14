/* Fitting waiver: order lookup on the booking form (no login). */
(function () {
  var root = document.querySelector('[data-sa-fw]');
  if (!root || !window.saSvc) return;
  var form = root.closest('form'), on = root.querySelector('[data-fw-on]'), box = root.querySelector('[data-fw-box]'),
    ord = root.querySelector('[data-fw-order]'), con = root.querySelector('[data-fw-contact]'), key = root.querySelector('[data-fw-key]'),
    btn = root.querySelector('[data-fw-check]'), msg = root.querySelector('[data-fw-msg]'), sel = root.querySelector('[data-fw-item]'),
    wrap = root.querySelector('[data-fw-itemwrap]'), feeW = document.querySelector('[data-fw-feebox]'), feeN = document.querySelectorAll('[data-fw-normalfee]'),
    feeT = document.querySelector('[data-fw-feetext]'), lbl = document.querySelector('[data-fw-btn]'), date = form.querySelector('[data-sa-date]'),
    part = form.querySelector('input[name="part"]'), ok = false, normalLbl = lbl ? lbl.textContent : '', baseMin = date ? date.min : '';
  function setState(v, order, earliest) {
    ok = v;
    if (feeW) feeW.hidden = !v; feeN.forEach(function (n) { n.hidden = v; });
    if (feeT && v) feeT.textContent = 'Booking fee: waived (part bought from us, order #' + order + ')';
    if (lbl) lbl.textContent = v ? lbl.getAttribute('data-free') : normalLbl;
    if (date) { date.min = v && earliest && earliest > baseMin ? earliest : baseMin; if (date.value && date.value < date.min) { date.value = ''; date.dispatchEvent(new Event('change')); } }
    var sum = document.querySelector('[data-sa-summary]');
    if (sum) { var n = sum.parentNode.querySelector('.sa-fw-sumrow'); if (v) { if (!n) { n = document.createElement('p'); n.className = 'sa-fw-sumrow'; sum.after(n); } n.innerHTML = '<strong>Booking fee:</strong> waived (part bought from us, order #' + order + ')'; } else if (n) n.remove(); }
  }
  function check() {
    msg.textContent = 'Checking…'; msg.className = 'sa-fw__msg';
    var fd = new FormData();
    fd.append('action', 'sa_fw_check'); fd.append('order', ord.value); fd.append('contact', con ? con.value : '');
    fd.append('key', key ? key.value : ''); fd.append('part', part ? part.value : ''); fd.append('item', sel.value || '');
    fetch(saSvc.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { setState(false); msg.textContent = d.msg; msg.className = 'sa-fw__msg is-bad'; return; }
      var cur = sel.value; sel.innerHTML = '';
      d.items.forEach(function (it) { var o = document.createElement('option'); o.value = it.id; o.textContent = it.name; if (String(it.id) === String(d.item || cur)) o.selected = true; sel.appendChild(o); });
      wrap.hidden = d.items.length < 2 && !!d.item;
      setState(true, d.order, d.earliest);
      var when = d.earliest ? ' Pick a date on or after ' + new Date(d.earliest + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + '.' : '';
      msg.textContent = 'Order #' + d.order + ' verified. No booking fee for this fitting.' + when; msg.className = 'sa-fw__msg is-ok';
    }).catch(function () { setState(false); msg.textContent = 'Could not check right now. Please try again.'; msg.className = 'sa-fw__msg is-bad'; });
  }
  on.addEventListener('change', function () { box.hidden = !on.checked; if (!on.checked) { setState(false); msg.textContent = ''; } });
  btn.addEventListener('click', check);
  sel.addEventListener('change', function () { if (ok) check(); });
  [ord, con].forEach(function (el) { if (el) el.addEventListener('input', function () { if (ok) { setState(false); msg.textContent = 'Press Check order again.'; } }); });
  form.addEventListener('submit', function (e) { if (on.checked && !ok) { e.preventDefault(); msg.textContent = 'Check your order first, or untick the box to book with the normal fee.'; msg.className = 'sa-fw__msg is-bad'; on.scrollIntoView({ block: 'center' }); } }, true);
  if (on.checked && ord.value && key && key.value) check();
})();
