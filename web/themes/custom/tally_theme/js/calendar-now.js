/**
 * @file
 * The calendar grids show the whole day inside a scroll area ([data-cal-scroll]).
 * - The now-line and its label move in the browser. The grid carries
 *   data-now-min (minutes into the day when the server rendered it) and
 *   data-now-at (the server's clock then); the elapsed time is added to those,
 *   so the device's timezone does not matter.
 * - A new grid scrolls so the now-line sits a third of the way down (07:00
 *   when the day is not today). HTMX swaps replace the grid, and the scroll
 *   position is carried over when the key (view, date, zoom) is the same, so
 *   the 30 second refresh and the edit dialog do not jump back.
 */
(function () {
  'use strict';

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  var serverNow = Number(document.documentElement.dataset.serverNow);
  var offset = serverNow ? serverNow - Math.floor(Date.now() / 1000) : 0;

  function now() {
    return Math.floor(Date.now() / 1000) + offset;
  }

  // Minutes into the day right now, or null when the grid is not today's.
  function minutesNow(grid) {
    if (!grid || !grid.dataset.nowAt) {
      return null;
    }
    return Math.min(Number(grid.dataset.nowMin) + (now() - Number(grid.dataset.nowAt)) / 60, 24 * 60);
  }

  function tick() {
    document.querySelectorAll('[data-now-min]').forEach(function (grid) {
      var minutes = minutesNow(grid);
      var top = (minutes - Number(grid.dataset.first) * 60) / 60 * Number(grid.dataset.hourPx);
      grid.querySelectorAll('[data-now-line]').forEach(function (line) {
        line.style.top = top.toFixed(1) + 'px';
      });
      var clock = pad(Math.floor(minutes / 60) % 24) + ':' + pad(Math.floor(minutes) % 60);
      grid.querySelectorAll('[data-now-label]').forEach(function (label) {
        if (label.textContent !== clock) {
          label.textContent = clock;
        }
      });
    });
  }

  function place(scroller, saved) {
    var grid = scroller.querySelector('[data-hour-px]');
    if (!grid) {
      return;
    }
    if (saved !== undefined) {
      scroller.scrollTop = saved;
      return;
    }
    var px = Number(grid.dataset.hourPx);
    var minutes = minutesNow(grid);
    var target = (minutes === null ? 7 * 60 : minutes) / 60 * px;
    scroller.scrollTop = Math.max(0, target - (minutes === null ? 0 : scroller.clientHeight / 3));
  }

  var remembered = {};

  document.addEventListener('htmx:beforeSwap', function () {
    remembered = {};
    document.querySelectorAll('[data-cal-scroll]').forEach(function (scroller) {
      remembered[scroller.dataset.scrollKey] = scroller.scrollTop;
    });
  });

  function settle() {
    document.querySelectorAll('[data-cal-scroll]').forEach(function (scroller) {
      if (scroller.dataset.scrolled) {
        return;
      }
      scroller.dataset.scrolled = '1';
      place(scroller, remembered[scroller.dataset.scrollKey]);
    });
    remembered = {};
    tick();
  }

  document.addEventListener('htmx:afterSwap', settle);
  document.addEventListener('DOMContentLoaded', settle);
  setInterval(tick, 1000);
})();
