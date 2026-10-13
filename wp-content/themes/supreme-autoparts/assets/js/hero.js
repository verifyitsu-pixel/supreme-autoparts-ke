/* Supreme Autoparts homepage hero: live suggestions (Woo Store API), vehicle quick-select, reveal. No deps. */
(function () {
  var cfg = window.saHero || {};
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.documentElement.classList.add('sa-hx-js');

  /* ---- live suggestions ---- */
  var form = document.querySelector('[data-sa-hx-search]');
  if (form && cfg.store) {
    var input = form.querySelector('input[name=s]'), list = form.querySelector('.sa-hx__sugg'), t = 0, ctl = null, active = -1;
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var close = function () { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; };
    var paint = function (rows, q) {
      if (!rows.length) { close(); return; }
      list.innerHTML = rows.map(function (p, i) {
        var img = p.images && p.images[0] ? (p.images[0].thumbnail || p.images[0].src) : '';
        var name = document.createElement('textarea'); name.innerHTML = p.name;
        return '<li role="option" id="sa-hx-o' + i + '"><a href="' + esc(p.permalink) + '">' + (img ? '<img src="' + esc(img) + '" alt="" width="40" height="40" loading="lazy">' : '<span class="sa-hx__noimg"></span>') + '<span>' + esc(name.value) + '</span></a></li>';
      }).join('') + '<li class="sa-hx__all"><a href="' + esc(form.action + '?post_type=product&s=' + encodeURIComponent(q)) + '">See all results for “' + esc(q) + '”</a></li>';
      list.hidden = false; input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('input', function () {
      var q = input.value.trim(); clearTimeout(t);
      if (q.length < 2) { close(); return; }
      t = setTimeout(function () {
        if (ctl) ctl.abort(); ctl = 'AbortController' in window ? new AbortController() : null;
        fetch(cfg.store + '?per_page=6&search=' + encodeURIComponent(q), { signal: ctl ? ctl.signal : undefined, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : []; }).then(function (rows) { if (input.value.trim() === q) paint(rows || [], q); }).catch(function () {});
      }, 220);
    });
    input.addEventListener('keydown', function (e) {
      var items = list.querySelectorAll('li a'); if (list.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; items.forEach(function (a, i) { a.classList.toggle('is-active', i === active); }); input.setAttribute('aria-activedescendant', 'sa-hx-o' + active); }
      else if (e.key === 'Enter' && active > -1) { e.preventDefault(); location.href = items[active].href; }
      else if (e.key === 'Escape') { close(); }
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
  }

  /* ---- vehicle quick-select (same data as the header filter panel) ---- */
  var veh = document.querySelector('[data-sa-hx-veh]');
  if (veh && cfg.ajaxUrl) {
    var mk = veh.querySelector('[name=sa_make]'), md = veh.querySelector('[name=sa_model]'), yr = veh.querySelector('[name=sa_year]'), loaded = false;
    var get = function (p) {
      var u = new URL(cfg.ajaxUrl, location.href); u.searchParams.set('action', 'sa_search_facets'); u.searchParams.set('vehicle_only', '1');
      Object.keys(p).forEach(function (k) { if (p[k]) u.searchParams.set(k, p[k]); });
      return fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { return j && j.success ? j.data : null; });
    };
    var fill = function (sel, rows, first) {
      sel.innerHTML = '<option value="">' + first + '</option>';
      (rows || []).forEach(function (r) { var o = document.createElement('option'); o.value = r.v; o.textContent = r.l; sel.appendChild(o); });
      sel.disabled = !rows || !rows.length;
    };
    var loadMakes = function () { if (loaded) return; loaded = true; get({}).then(function (d) { if (d) { fill(mk, d.makes, 'Make'); mk.disabled = false; } }); };
    mk.addEventListener('focus', loadMakes, { once: true }); mk.addEventListener('pointerdown', loadMakes, { once: true });
    if ('requestIdleCallback' in window) requestIdleCallback(loadMakes, { timeout: 2500 }); else setTimeout(loadMakes, 1500);
    mk.addEventListener('change', function () { fill(md, [], 'Model'); fill(yr, [], 'Year'); if (mk.value) get({ sa_make: mk.value }).then(function (d) { if (d) fill(md, d.models, 'Model'); }); });
    md.addEventListener('change', function () { fill(yr, [], 'Year'); if (md.value) get({ sa_make: mk.value, sa_model: md.value }).then(function (d) { if (d) fill(yr, d.years, 'Year'); }); });
    veh.addEventListener('submit', function () { [mk, md, yr].forEach(function (s) { if (!s.value) s.disabled = true; }); });
  }

  /* ---- scroll reveal ---- */
  var rev = document.querySelectorAll('[data-sa-reveal] > *');
  if (reduce || !('IntersectionObserver' in window)) { rev.forEach(function (n) { n.classList.add('is-in'); }); return; }
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
  }, { rootMargin: '0px 0px -8% 0px' });
  rev.forEach(function (n, i) { n.style.transitionDelay = (i * 90) + 'ms'; io.observe(n); });
})();
