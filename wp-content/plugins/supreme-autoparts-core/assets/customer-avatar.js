/* Greeting word from the visitor's own clock. */
(function () {
  'use strict';
  var h = new Date().getHours();
  var word = h >= 5 && h < 12 ? 'Good morning' : h >= 12 && h < 17 ? 'Good afternoon' : 'Good evening';
  document.querySelectorAll('[data-sa-greet-word]').forEach(function (el) {
    el.textContent = word;
  });
})();


/* Click the avatar to pick a photo; upload starts right away. */
(function () {
  'use strict';
  document.querySelectorAll('.sa-avatar-pick__input').forEach(function (input) {
    input.addEventListener('change', function () {
      var form = input.form;
      var f = input.files && input.files[0];
      if (!form || !f) { return; }
      var max = parseInt(form.getAttribute('data-max') || '5242880', 10);
      var host = form.parentNode.querySelector('.sa-dash__hero-text') || form.parentNode;
      var msg = host.querySelector('.sa-avatar-pick__err');
      if (!msg) {
        msg = document.createElement('p');
        msg.className = 'sa-greet__msg sa-greet__msg--error sa-avatar-pick__err';
        msg.setAttribute('role', 'alert');
        host.appendChild(msg);
      }
      msg.textContent = '';
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
        msg.textContent = 'Please choose a JPG, PNG or WebP image.';
        input.value = '';
        return;
      }
      if (f.size > max) {
        msg.textContent = 'Please choose an image up to 5 MB.';
        input.value = '';
        return;
      }
      var img = form.querySelector('.sa-avatar img');
      var av = form.querySelector('.sa-avatar');
      if (av && window.URL && URL.createObjectURL) {
        if (!img) {
          av.classList.remove('sa-avatar--initials');
          av.textContent = '';
          img = document.createElement('img');
          img.alt = '';
          av.appendChild(img);
        }
        img.src = URL.createObjectURL(f);
      }
      form.classList.add('is-busy');
      form.submit();
    });
  });
})();
