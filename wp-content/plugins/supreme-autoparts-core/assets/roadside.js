/* Roadside Rescue: request map/geolocation, live status polling, responder dashboard, admin map. */
(function () {
  'use strict';
  var NBO = [-1.2864, 36.8172];
  var ajax = (window.saRs && saRs.ajax) || '/wp-admin/admin-ajax.php';
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function mkMap(el, center, zoom) {
    if (!window.L || !el) return null;
    var m = L.map(el, { scrollWheelZoom: false }).setView(center, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(m);
    return m;
  }
  var provIcon = function () { return L.divIcon({ className: 'sa-rs-pin sa-rs-pin--prov', html: '<span></span>', iconSize: [22, 22] }); };

  /* ---- Request form ---- */
  var form = $('[data-rs-form]');
  if (form) {
    var mapEl = $('[data-rs-map]', form), lat = $('[data-rs-lat]', form), lng = $('[data-rs-lng]', form);
    var addr = $('[data-rs-address]', form), msg = $('[data-rs-locmsg]', form), marker = null;
    var map = mkMap(mapEl, NBO, 11);
    var setPin = function (la, lo, text) {
      lat.value = la.toFixed(6); lng.value = lo.toFixed(6);
      if (!map) return;
      if (!marker) {
        marker = L.marker([la, lo], { draggable: true }).addTo(map);
        marker.on('dragend', function () { var p = marker.getLatLng(); setPin(p.lat, p.lng, 'Pin moved.'); });
      } else marker.setLatLng([la, lo]);
      map.setView([la, lo], Math.max(map.getZoom(), 15));
      msg.textContent = (text || 'Location set.') + ' Drag the pin if it is not exact.';
      if (addr && !addr.value) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=17&lat=' + la + '&lon=' + lo, { headers: { 'Accept-Language': 'en' } })
          .then(function (r) { return r.json(); }).then(function (j) { if (j && j.display_name && !addr.value) addr.value = j.display_name.split(',').slice(0, 4).join(','); }).catch(function () {});
      }
    };
    if (map) map.on('click', function (e) { setPin(e.latlng.lat, e.latlng.lng, 'Pin dropped.'); });
    var loc = $('[data-rs-locate]', form);
    if (loc) loc.addEventListener('click', function () {
      if (!navigator.geolocation) { msg.textContent = 'Location is not available on this device. Tap the map or type your address.'; return; }
      msg.textContent = 'Finding you…';
      navigator.geolocation.getCurrentPosition(function (p) {
        setPin(p.coords.latitude, p.coords.longitude, 'Found you (accurate to about ' + Math.round(p.coords.accuracy) + ' m).');
      }, function () { msg.textContent = 'We could not get your location. Tap the map to drop a pin, or type an address or landmark.'; }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
    var saved = $('[data-rs-saved]', form);
    if (saved) saved.addEventListener('click', function () {
      var s = JSON.parse(saved.getAttribute('data-rs-saved'));
      if (addr && s.address) addr.value = s.address;
      if (form.landmark && s.landmark) form.landmark.value = s.landmark;
      setPin(parseFloat(s.lat), parseFloat(s.lng), 'Saved location used.');
    });
    var veh = $('[data-rs-veh]', form);
    if (veh) veh.addEventListener('change', function () {
      if (!veh.value) return; var v = JSON.parse(veh.value);
      ['make', 'model', 'year', 'reg'].forEach(function (k) { if (form[k]) form[k].value = v[k] || ''; });
    });
    var dest = $('[data-rs-dest]', form);
    var syncDest = function () { var c = $('input[name=type]:checked', form); dest.hidden = !(c && (c.value === 'towing' || c.value === 'winching')); };
    $$('input[name=type]', form).forEach(function (i) { i.addEventListener('change', syncDest); });
    syncDest();
    form.addEventListener('submit', function (e) {
      if (!lat.value && !addr.value.trim() && !form.landmark.value.trim()) {
        e.preventDefault(); msg.textContent = 'Please share your location, tap the map, or type an address or landmark.'; msg.classList.add('is-err'); msg.scrollIntoView({ block: 'center' }); return;
      }
      var b = $('.sa-rs-submit', form); if (b) { b.disabled = true; b.textContent = 'Sending…'; }
    });
  }

  /* ---- Live status ---- */
  var st = $('[data-rs-status]');
  if (st) {
    var cl = parseFloat(st.dataset.lat), cg = parseFloat(st.dataset.lng), smap = null, pm = null, ver = null;
    var sEl = $('[data-rs-map]', st);
    if (!isNaN(cl)) { smap = mkMap(sEl, [cl, cg], 14); L.marker([cl, cg]).addTo(smap).bindPopup('You'); } else if (sEl) sEl.hidden = true;
    var poll = function () {
      fetch(ajax + '?action=sa_rs_poll&r=' + st.dataset.r + '&k=' + encodeURIComponent(st.dataset.k), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (!j || !j.success) return; var d = j.data;
          if (ver && ver !== d.v && !document.activeElement.matches('input,textarea')) { location.reload(); return; }
          ver = d.v;
          var e = $('[data-rs-eta]', st);
          if (e) { if (d.eta !== null && (d.status === 'dispatched' || d.status === 'en_route')) { e.hidden = false; $('[data-rs-eta-min]', e).textContent = d.eta; } else e.hidden = true; }
          if (smap && d.plat !== null) {
            if (!pm) pm = L.marker([d.plat, d.plng], { icon: provIcon() }).addTo(smap).bindPopup('Your responder');
            else pm.setLatLng([d.plat, d.plng]);
            smap.fitBounds(L.latLngBounds([[cl, cg], [d.plat, d.plng]]).pad(0.3));
          }
        }).catch(function () {});
    };
    poll(); setInterval(poll, 15000);
  }

  /* ---- Responder dashboard ---- */
  var pv = $('[data-rs-pv]');
  if (pv) {
    var err = $('[data-rs-err]', pv), smsg = $('[data-rs-sharemsg]', pv), watching = false, last = 0;
    var post = function (data) {
      var fd = new FormData(); fd.append('action', 'sa_rs_pv'); fd.append('_n', pv.dataset.n);
      Object.keys(data).forEach(function (k) { if (Array.isArray(data[k])) data[k].forEach(function (v) { fd.append(k + '[]', v); }); else fd.append(k, data[k]); });
      return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    };
    var share = function () {
      if (watching || !navigator.geolocation) return; watching = true;
      navigator.geolocation.watchPosition(function (p) {
        if (Date.now() - last < 20000) return; last = Date.now();
        post({ do: 'loc', lat: p.coords.latitude, lng: p.coords.longitude }).then(function (j) { if (j && j.success) smsg.textContent = j.data.msg; });
      }, function () { smsg.textContent = 'Location blocked. Allow location for this site to share it.'; watching = false; }, { enableHighAccuracy: true, maximumAge: 15000 });
    };
    var sb = $('[data-rs-share]', pv); if (sb) sb.addEventListener('click', share);
    if (pv.dataset.share === '1') share();
    pv.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pv]'); if (!b) return; e.preventDefault();
      var job = b.closest('[data-job]'), d = { do: b.dataset.pv };
      if (job) {
        d.r = job.dataset.job;
        var eta = $('[data-eta]', job), tx = b.parentNode.querySelector('[data-text]') || $('[data-text]', job), m = $('[data-msg]', job);
        if (eta) d.eta = eta.value; if (tx) d.text = tx.value; if (d.do === 'msg' && m) d.text = m.value;
      }
      if (d.do === 'profile') {
        var f = b.closest('form'); d.phone = f.phone.value; d.vehicle = f.vehicle.value; d.radius = f.radius.value;
        d.services = $$('input[name="services[]"]:checked', f).map(function (i) { return i.value; });
      }
      if (d.do === 'completed' && !confirm('Mark this job as completed? The customer will be asked to rate it.')) return;
      b.disabled = true;
      post(d).then(function (j) {
        if (j && j.success) { location.reload(); return; }
        err.hidden = false; err.textContent = (j && j.data && j.data.msg) || 'Something went wrong.'; b.disabled = false;
      }).catch(function () { err.hidden = false; err.textContent = 'No connection. Try again.'; b.disabled = false; });
    });
    setInterval(function () { if (!document.activeElement.matches('input,textarea,select')) location.reload(); }, 60000);
  }

  /* ---- Admin map ---- */
  $$('[data-rs-map][data-mode=admin]').forEach(function (el) {
    var pins = JSON.parse(el.dataset.pins || '[]'), m = mkMap(el, NBO, 11); if (!m) return;
    var b = [];
    pins.forEach(function (p) {
      var mk = L.marker([p.lat, p.lng], p.prov ? { icon: provIcon() } : {}).addTo(m);
      mk.bindPopup(p.url ? '<a href="' + p.url + '">' + p.label + '</a>' : p.label); b.push([p.lat, p.lng]);
    });
    if (b.length) m.fitBounds(L.latLngBounds(b).pad(0.3), { maxZoom: 15 });
  });
})();
