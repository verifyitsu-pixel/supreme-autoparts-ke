/* Supreme Workshop — mechanic inspection card (damage diagram, photos, signatures, GPS). */
(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  function drawMarks(svg, marks) {
    var g = svg.querySelector('.sa-g-car__marks');
    if (!g) return;
    g.innerHTML = '';
    marks.forEach(function (m, i) {
      var x = m.x * 2, y = m.y * 4;
      var c = document.createElementNS(NS, 'circle');
      c.setAttribute('cx', x); c.setAttribute('cy', y); c.setAttribute('r', 11);
      c.setAttribute('fill', '#c13515'); c.setAttribute('data-i', i);
      var t = document.createElementNS(NS, 'text');
      t.setAttribute('x', x); t.setAttribute('y', y + 4); t.setAttribute('text-anchor', 'middle');
      t.setAttribute('font-size', '11'); t.setAttribute('fill', '#fff'); t.setAttribute('font-weight', '700');
      t.setAttribute('data-i', i); t.textContent = (i + 1) + m.t;
      g.appendChild(c); g.appendChild(t);
    });
  }
  // Read-only diagram on the report page.
  document.querySelectorAll('[data-car-static]').forEach(function (w) {
    try { drawMarks(w.querySelector('svg'), JSON.parse(w.getAttribute('data-car-static')) || []); } catch (e) {}
  });

  var form = document.getElementById('sa-g-insp');
  if (!form) return;
  var names = { S: 'Scratch', D: 'Dent', C: 'Crack', X: 'Chip', M: 'Missing part' };

  // GPS
  var gps = form.querySelector('[data-gps]');
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function (p) {
      form.lat.value = p.coords.latitude.toFixed(7); form.lng.value = p.coords.longitude.toFixed(7);
      gps.textContent = '📍 Location saved (±' + Math.round(p.coords.accuracy) + ' m)'; gps.classList.add('is-ok');
    }, function () { gps.textContent = '📍 Location not available'; }, { enableHighAccuracy: true, timeout: 15000 });
  } else { gps.textContent = '📍 Location not available'; }

  // Fuel
  var fuel = form.querySelector('[data-fuel]'), fo = form.querySelector('[data-fuel-out]');
  fuel.addEventListener('input', function () { fo.textContent = fuel.value + '/8'; });

  // Damage diagram
  var marks = [];
  var svg = form.querySelector('[data-car] svg'), list = form.querySelector('[data-dmg-list]');
  function sync() {
    form.damage.value = JSON.stringify(marks);
    drawMarks(svg, marks);
    list.innerHTML = '';
    marks.forEach(function (m, i) {
      var li = document.createElement('li');
      li.innerHTML = '<b>' + (i + 1) + m.t + '</b> ' + names[m.t] + ' <input type="text" placeholder="Note (e.g. rear bumper, 5 cm)" value="">';
      var inp = li.querySelector('input'); inp.value = m.n || '';
      inp.addEventListener('input', function () { m.n = inp.value; form.damage.value = JSON.stringify(marks); });
      list.appendChild(li);
    });
  }
  svg.addEventListener('click', function (e) {
    var tgt = e.target;
    if (tgt.hasAttribute && tgt.hasAttribute('data-i')) { marks.splice(+tgt.getAttribute('data-i'), 1); sync(); return; }
    var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY;
    var p = pt.matrixTransform(svg.getScreenCTM().inverse());
    var t = (form.querySelector('[name=dmg_type]:checked') || {}).value || 'S';
    marks.push({ x: Math.round(p.x / 2 * 10) / 10, y: Math.round(p.y / 4 * 10) / 10, t: t, n: '' });
    sync();
  });

  // Photos: compress to JPEG (max 1400 px) client-side
  var thumbs = form.querySelector('[data-thumbs]'), photoIn = form.querySelector('[data-photos]'), count = 0;
  photoIn.addEventListener('change', function () {
    Array.prototype.slice.call(photoIn.files).forEach(function (f) {
      if (count >= 12 || !/^image\//.test(f.type)) return;
      count++;
      var img = new Image(), url = URL.createObjectURL(f);
      img.onload = function () {
        var s = Math.min(1, 1400 / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        var data = c.toDataURL('image/jpeg', 0.78);
        var wrap = document.createElement('div'); wrap.className = 'sa-g-thumb';
        wrap.innerHTML = '<img alt=""><button type="button" aria-label="Remove photo">×</button><input type="hidden" name="photos[]">';
        wrap.querySelector('img').src = data; wrap.querySelector('input').value = data;
        wrap.querySelector('button').onclick = function () { wrap.remove(); count--; };
        thumbs.appendChild(wrap); URL.revokeObjectURL(url);
      };
      img.onerror = function () { count--; alert('This photo format could not be read. Please use the camera or a JPEG/PNG.'); };
      img.src = url;
    });
    photoIn.value = '';
  });

  // Signatures
  form.querySelectorAll('canvas[data-sig]').forEach(function (cv) {
    var ctx = cv.getContext('2d'), drawing = false, dirty = false;
    function blank() { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height); dirty = false; }
    blank(); ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
    function pos(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height }; }
    cv.addEventListener('pointerdown', function (e) { drawing = true; dirty = true; cv.setPointerCapture(e.pointerId); var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); });
    cv.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { cv.addEventListener(ev, function () { drawing = false; }); });
    cv._dirty = function () { return dirty; };
    form.querySelector('[data-sig-clear="' + cv.dataset.sig + '"]').addEventListener('click', blank);
  });

  form.addEventListener('submit', function (e) {
    var req = ['name', 'phone', 'plate', 'make', 'model', 'mileage'];
    for (var i = 0; i < req.length; i++) {
      if (!form[req[i]].value.trim()) { e.preventDefault(); form[req[i]].focus(); form[req[i]].reportValidity && form[req[i]].setCustomValidity('Required'); form[req[i]].reportValidity(); form[req[i]].setCustomValidity(''); return; }
    }
    form.querySelectorAll('canvas[data-sig]').forEach(function (cv) {
      form[cv.dataset.sig].value = cv._dirty() ? cv.toDataURL('image/jpeg', 0.85) : '';
    });
    var b = form.querySelector('[data-submit]'); b.disabled = true; b.textContent = 'Saving…';
  });
})();
