(function () {
  'use strict';

  var toggle = document.querySelector('[data-sa-nav-toggle]');
  var nav = document.querySelector('[data-sa-nav]');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      nav.classList.toggle('is-open');
      var open = nav.classList.contains('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-sa-mega-parent]').forEach(function (item) {
    var link = item.querySelector(':scope > .sa-nav__link');
    if (!link) return;
    link.addEventListener('click', function (e) {
      if (window.matchMedia('(max-width: 900px)').matches) {
        e.preventDefault();
        item.classList.toggle('is-open');
      }
    });
  });
})();
