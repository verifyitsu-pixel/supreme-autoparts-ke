(function () {
  'use strict';

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        resolve();
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(ta);
      }
    });
  }

  function flash(btn, ok) {
    var prev = btn.getAttribute('data-label') || btn.textContent;
    btn.setAttribute('data-label', prev);
    btn.textContent = ok ? 'Copied' : 'Failed';
    btn.disabled = true;
    window.setTimeout(function () {
      btn.textContent = prev;
      btn.disabled = false;
    }, 1200);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-sa-copy]');
    if (!btn) {
      return;
    }
    e.preventDefault();
    var sel = btn.getAttribute('data-sa-copy');
    var input = sel ? document.querySelector(sel) : btn.previousElementSibling;
    if (!input) {
      return;
    }
    var val = input.value || input.textContent || '';
    copyText(val).then(
      function () {
        flash(btn, true);
        if (input.select) {
          input.select();
        }
      },
      function () {
        flash(btn, false);
      }
    );
  });
})();
