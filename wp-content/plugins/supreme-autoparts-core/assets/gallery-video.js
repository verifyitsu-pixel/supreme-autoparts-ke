(function () {
  'use strict';
  function reset(slide) {
    slide.querySelectorAll('.sa-gallery-video__frame').forEach(function (frame) {
      if (frame.tagName === 'VIDEO') { frame.pause(); frame.removeAttribute('src'); frame.load(); }
      frame.remove();
    });
    slide.classList.remove('sa-gallery-video--playing');
    var button = slide.querySelector('.sa-gallery-video__play');
    if (button) button.hidden = false;
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.sa-gallery-video__play');
    if (!button) return;
    var src = button.getAttribute('data-embed');
    var native = button.getAttribute('data-video-kind') === 'native';
    var safeNative = /^https:\/\/www\.supremeautoparts\.co\.ke\/wp-content\/uploads\/[A-Za-z0-9/_-]+\.mp4$/.test(src || '');
    if (native ? !safeNative : !/^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}\?autoplay=1&rel=0&playsinline=1$/.test(src || '')) return;
    event.preventDefault();
    event.stopPropagation();
    var slide = button.closest('.sa-gallery-video');
    if (!slide || slide.querySelector('.sa-gallery-video__frame')) return;
    var frame = document.createElement(native ? 'video' : 'iframe');
    frame.className = 'sa-gallery-video__frame';
    frame.src = src;
    frame.title = button.getAttribute('aria-label');
    if (native) {
      frame.controls = true; frame.playsInline = true; frame.muted = true; frame.preload = 'none';
      frame.setAttribute('aria-label', frame.title);
    }
    frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    frame.allowFullscreen = true;
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    button.hidden = true;
    slide.classList.add('sa-gallery-video--playing');
    button.parentNode.appendChild(frame);
    frame.focus();
    if (native) {
      var play = frame.play();
      if (play && play.catch) play.catch(function () { /* Native controls remain available if autoplay is blocked. */ });
    }
  });
  document.querySelectorAll('.woocommerce-product-gallery').forEach(function (gallery) {
    function sync() {
      var slides = gallery.querySelectorAll('.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image');
      var thumbs = gallery.querySelectorAll('.flex-control-thumbs li');
      var videoActive = false;
      slides.forEach(function (slide, index) {
        if (!slide.classList.contains('sa-gallery-video')) return;
        if (thumbs[index] && !thumbs[index].classList.contains('sa-gallery-video__thumb')) thumbs[index].classList.add('sa-gallery-video__thumb');
        var active = slide.classList.contains('flex-active-slide');
        videoActive = videoActive || active;
        if (gallery.querySelector('.flex-viewport') && !active && slide.querySelector('.sa-gallery-video__frame')) reset(slide);
      });
      if (gallery.classList.contains('sa-gallery-video-active') !== videoActive) gallery.classList.toggle('sa-gallery-video-active', videoActive);
    }
    new MutationObserver(sync).observe(gallery, {subtree: true, childList: true, attributes: true, attributeFilter: ['class']});
    sync();
  });
}());
