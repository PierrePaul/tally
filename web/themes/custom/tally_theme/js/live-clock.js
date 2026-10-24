/**
 * @file
 * Keeps running timers ticking in the browser, so the server isn't asked for
 * a new clock every second. Elements opt in with:
 *   data-live-start   epoch seconds when the running part began
 *   data-live-base    seconds already counted before that (default 0)
 *   data-live-format  "clock" (HH:MM:SS), "duration" (1h 05m) or "hm" (1:05)
 * Also opens the native date picker from the day pill.
 */
(function () {
  'use strict';

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function clock(seconds) {
    return pad(Math.floor(seconds / 3600)) + ':' + pad(Math.floor(seconds / 60) % 60) + ':' + pad(seconds % 60);
  }

  function duration(seconds) {
    var minutes = Math.floor(seconds / 60);
    return minutes >= 60 ? Math.floor(minutes / 60) + 'h ' + pad(minutes % 60) + 'm' : minutes + 'm';
  }

  function hm(seconds) {
    var minutes = Math.floor(seconds / 60);
    return Math.floor(minutes / 60) + ':' + pad(minutes % 60);
  }

  // How far the device clock is from the server's, taken once when the page
  // loads. data-live-start is a server timestamp, so elapsed time must be
  // measured against the server's clock.
  var serverNow = Number(document.documentElement.dataset.serverNow);
  var offset = serverNow ? serverNow - Math.floor(Date.now() / 1000) : 0;

  function tick() {
    var now = Math.floor(Date.now() / 1000) + offset;
    document.querySelectorAll('[data-live-start]').forEach(function (element) {
      var seconds = Math.max(0, now - Number(element.dataset.liveStart)) + Number(element.dataset.liveBase || 0);
      var format = element.dataset.liveFormat;
      element.textContent = format === 'clock' ? clock(seconds) : (format === 'hm' ? hm(seconds) : duration(seconds));
    });
  }

  setInterval(tick, 1000);

  document.addEventListener('click', function (event) {
    var input = event.target.closest('[data-show-picker]');
    if (input && typeof input.showPicker === 'function') {
      input.showPicker();
    }
  });
})();
