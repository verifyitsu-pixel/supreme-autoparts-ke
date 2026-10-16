/* Greeting word from the visitor's own clock + avatar form niceties. */
(function () {
  'use strict';
  var h = new Date().getHours();
  var word = h >= 5 && h < 12 ? 'Good morning' : h >= 12 && h < 17 ? 'Good afternoon' : 'Good evening';
  document.querySelectorAll('[data-sa-greet-word]').forEach(function (el) {
    el.textContent = word;
  });
  document.querySelectorAll('[data-sa-avatar-file]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (!input.files || !input.files[0]) {
        return;
      }
      var f = input.files[0];
      if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) {
        window.alert('Please choose a JPG, PNG or WebP image.');
        input.value = '';
        return;
      }
      if (f.size > 5 * 1024 * 1024) {
        window.alert('Please choose an image up to 5 MB.');
        input.value = '';
        return;
      }
      var form = input.closest('form');
      if (form) {
        form.submit();
      }
    });
  });
})();
