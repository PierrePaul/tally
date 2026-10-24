/**
 * @file
 * Drag on an empty part of the day calendar (or on a day column of the week) to pick a time range. The range
 * goes into the grid's hidden link (?new=HH:MM-HH:MM) and the link is clicked,
 * so HTMX opens the new-entry dialog and updates the address.
 *
 * Mouse and pen only. With a finger, dragging on the calendar scrolls it, and
 * the browser cancels the gesture halfway, which left this half-working; touch
 * screens do without drag-to-create (the timer and Resume still add entries).
 */
(function () {
  'use strict';

  var SNAP = 15;
  var drag = null;

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function clock(minutes) {
    return pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60);
  }

  function minutesAt(grid, event) {
    var y = event.clientY - grid.getBoundingClientRect().top;
    var minutes = Number(grid.dataset.first) * 60 + y / Number(grid.dataset.hourPx) * 60;
    return Math.min(Math.max(minutes, Number(grid.dataset.first) * 60), Number(grid.dataset.last) * 60);
  }

  function paint(event) {
    var a = drag.from;
    var b = minutesAt(drag.grid, event);
    drag.start = Math.floor(Math.min(a, b) / SNAP) * SNAP;
    drag.end = Math.min(Math.ceil(Math.max(a, b) / SNAP) * SNAP, 24 * 60 - 1);
    var px = Number(drag.grid.dataset.hourPx) / 60;
    var first = Number(drag.grid.dataset.first) * 60;
    drag.ghost.style.top = (drag.start - first) * px + 'px';
    drag.ghost.style.height = (drag.end - drag.start) * px + 'px';
    drag.ghost.textContent = clock(drag.start) + ' – ' + clock(drag.end);
  }

  document.addEventListener('pointerdown', function (event) {
    var grid = event.target.closest && event.target.closest('[data-drag-new]');
    // The week grid has a column per day (data-date); the day grid has none.
    var column = event.target.closest('[data-date]');
    var weekly = grid && grid.querySelector('[data-date]');
    if (!grid || event.pointerType === 'touch' || event.button !== 0 || event.target.closest('a, [data-move]') || (weekly && !column)) {
      return;
    }
    var ghost = document.createElement('div');
    ghost.className = 'day-cal__ghost mono' + (column ? ' day-cal__ghost--column' : '');
    (column || grid).appendChild(ghost);
    drag = {grid: grid, date: column && column.dataset.date, ghost: ghost, from: minutesAt(grid, event), y: event.clientY, moved: false};
    event.preventDefault();
  });

  document.addEventListener('pointermove', function (event) {
    if (!drag) {
      return;
    }
    drag.moved = drag.moved || Math.abs(event.clientY - drag.y) > 4;
    if (drag.moved) {
      paint(event);
    }
  });

  document.addEventListener('pointerup', function (event) {
    if (!drag) {
      return;
    }
    var done = drag;
    drag = null;
    done.ghost.remove();
    if (!done.moved || done.end <= done.start) {
      return;
    }
    // htmx reads hx-get once, when it processes an element, so each drag gets
    // a fresh copy of the link instead of a changed attribute.
    var url = done.grid.dataset.newUrl.replace('__RANGE__', encodeURIComponent(clock(done.start) + '-' + clock(done.end))).replace('__DATE__', done.date);
    var link = done.grid.querySelector('.day-cal__new-link').cloneNode(false);
    link.setAttribute('href', url);
    link.setAttribute('hx-get', url);
    done.grid.appendChild(link);
    htmx.process(link);
    link.click();
  });

  // The browser took the gesture over (a scroll, a call): drop the half-made range.
  document.addEventListener('pointercancel', function () {
    if (drag) {
      drag.ghost.remove();
      drag = null;
    }
  });

  document.addEventListener('keydown', function (event) {
    if (drag && event.key === 'Escape') {
      drag.ghost.remove();
      drag = null;
    }
  });
})();
