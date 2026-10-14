/* Fitting appointment on checkout: slots, earliest date, pickup fields. */
(function ($) {
  var box = document.querySelector('[data-sa-fitco]');
  if (!box) return;
  var date = box.querySelector('[data-fit-date]'), time = box.querySelector('[data-fit-time]'), pk = box.querySelector('[data-fit-pk]'), note = box.querySelector('[data-fit-earliest]');
  function storePickup() { var m = document.querySelector('input[name^="shipping_method"]:checked') || document.querySelector('input[name^="shipping_method"][type=hidden]'); return !!(m && /pickup/.test(m.value)); }
  function earliest() {
    var e = storePickup() ? box.dataset.e1 : box.dataset.e3; date.min = e;
    note.textContent = 'Earliest date: ' + new Date(e + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + (storePickup() ? ' (the day after you collect the part).' : ' (after your part is delivered).');
    if (date.value && date.value < e) { date.value = ''; time.innerHTML = '<option value="">Choose a date first</option>'; }
  }
  function slots() {
    if (!date.value) return;
    time.innerHTML = '<option value="">Loading…</option>';
    var u = box.dataset.ajax + '?action=sa_svc_slots&location=nairobi&service=' + encodeURIComponent(box.dataset.service) + '&extra=' + (box.dataset.extra || 0) + '&date=' + encodeURIComponent(date.value);
    fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var s = (d && d.data && d.data.slots) || [], cur = time.dataset.cur;
      time.innerHTML = s.length ? '<option value="">Choose a time</option>' : '<option value="">No free times that day</option>';
      s.forEach(function (t) { var o = document.createElement('option'); o.value = o.textContent = t; if (t === cur) o.selected = true; time.appendChild(o); });
    }).catch(function () { time.innerHTML = '<option value="">Could not load times</option>'; });
  }
  box.addEventListener('change', function (e) {
    if (e.target.name === 'sa_fit_mode') pk.hidden = e.target.value !== 'pickup';
    if (e.target === date) slots();
    if (e.target === time) time.dataset.cur = time.value;
    if (e.target === date || e.target === time || e.target.name === 'sa_fit_mode') $(document.body).trigger('update_checkout');
  });
  $(document.body).on('updated_checkout', earliest);
  earliest(); slots();
})(jQuery);
