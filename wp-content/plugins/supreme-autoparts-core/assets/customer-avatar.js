/* Greeting word from the visitor's own clock. */
(function () {
  'use strict';
  var h = new Date().getHours();
  var word = h >= 5 && h < 12 ? 'Good morning' : h >= 12 && h < 17 ? 'Good afternoon' : 'Good evening';
  document.querySelectorAll('[data-sa-greet-word]').forEach(function (el) {
    el.textContent = word;
  });
})();
