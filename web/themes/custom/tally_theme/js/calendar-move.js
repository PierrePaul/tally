/**
 * @file
 * Drag a block on the day or week calendar to move it (start and end shift
 * together, and in the week view it can change day), or drag its bottom edge to
 * change the end. Times snap to 15 minutes. On release the new day and times
 * are posted to the move route and HTMX swaps in the refreshed screen.
 *
 * A block opts in with data-move (finished entries inside one day). It is also
 * a link to the edit dialog, so nothing happens until the pointer has moved a
 * few pixels, and the click that ends a real drag is swallowed.
 *
 * Mouse and pen only, like drag-to-create: a finger drags to scroll.
 */
(function () {
  'use strict';

  var SNAP = 15;
  var THRESHOLD = 4;
  var EDGE = 40;
  var drag = null;
  var swallowClick = false;

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function clock(minutes) {
    return pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60);
  }

  function snap(minutes) {
    return Math.round(minutes / SNAP) * SNAP;
  }

  function gridY(event) {
    return event.clientY - drag.grid.getBoundingClientRect().top;
  }

  function columnAt(x) {
    var found = null;
    drag.grid.querySelectorAll('[data-date]').forEach(function (column) {
      var box = column.getBoundingClientRect();
      if (x >= box.left && x < box.right) {
        found = column;
      }
    });
    return found;
  }

  function paint(event) {
    var px = drag.px;
    var delta = (gridY(event) - drag.startY) / px * 60;
    var first = drag.first * 60;
    var duration = drag.end0 - drag.start0;
    if (drag.resize) {
      drag.start = drag.start0;
      drag.end = Math.min(Math.max(snap(drag.end0 + delta), drag.start0 + SNAP), 24 * 60 - 1);
    }
    else {
      drag.start = Math.min(Math.max(snap(drag.start0 + delta), 0), 24 * 60 - 1 - duration);
      drag.end = drag.start + duration;
      var column = columnAt(event.clientX);
      if (column && column !== drag.column) {
        drag.column = column;
        column.appendChild(drag.block);
        drag.block.style.setProperty('--lane', 0);
        drag.block.style.setProperty('--lanes', 1);
      }
    }
    drag.block.style.top = (drag.start - first) * px / 60 + 'px';
    drag.block.style.height = Math.max((drag.end - drag.start) * px / 60 - drag.trim, 22) + 'px';

    var tip = drag.tip;
    tip.textContent = clock(drag.start) + ' – ' + clock(drag.end);
    var box = drag.block.getBoundingClientRect();
    var grid = drag.grid.getBoundingClientRect();
    tip.style.top = Math.max(box.top - grid.top - 24, 0) + 'px';
    tip.style.left = box.left - grid.left + 'px';
  }

  // Puts the block back where it was, with its old size.
  function restore(d) {
    d.home.insertBefore(d.block, d.next);
    d.block.style.top = d.style.top;
    d.block.style.height = d.style.height;
    d.block.style.setProperty('--lane', d.lane);
    d.block.style.setProperty('--lanes', d.lanes);
  }

  function finish(d) {
    clearInterval(d.scroller);
    d.block.classList.remove('is-dragging');
    d.tip.remove();
  }

  // Keeps the block under the pointer while the grid scrolls at its edges.
  function autoscroll() {
    var box = drag.area.getBoundingClientRect();
    var y = drag.last.clientY;
    var step = y < box.top + EDGE ? -12 : (y > box.bottom - EDGE ? 12 : 0);
    if (step) {
      drag.area.scrollTop += step;
      paint(drag.last);
    }
  }

  document.addEventListener('pointerdown', function (event) {
    var block = event.target.closest && event.target.closest('[data-move]');
    var grid = block && block.closest('[data-drag-new]');
    if (!block || !grid || event.pointerType === 'touch' || event.button !== 0 || event.target.closest('.entry-resume')) {
      return;
    }
    drag = {
      block: block,
      grid: grid,
      area: grid.closest('[data-cal-scroll]') || document.scrollingElement,
      resize: !!event.target.closest('[data-resize]'),
      px: Number(grid.dataset.hourPx),
      first: Number(grid.dataset.first),
      start0: Number(block.dataset.startMin),
      end0: Number(block.dataset.endMin),
      column: block.closest('[data-date]'),
      home: block.parentNode,
      next: block.nextSibling,
      style: {top: block.style.top, height: block.style.height},
      lane: block.style.getPropertyValue('--lane'),
      lanes: block.style.getPropertyValue('--lanes'),
      pointerX: event.clientX,
      moved: false,
      last: event,
    };
    drag.startY = gridY(event);
    drag.date = drag.column && drag.column.dataset.date;
    // The block is drawn a little shorter (or taller, at the minimum height) than its duration.
    drag.trim = (drag.end0 - drag.start0) * drag.px / 60 - parseFloat(block.style.height);
  });

  document.addEventListener('pointermove', function (event) {
    if (!drag) {
      return;
    }
    drag.last = event;
    if (!drag.moved) {
      if (Math.abs(gridY(event) - drag.startY) < THRESHOLD && Math.abs(event.clientX - drag.pointerX) < THRESHOLD) {
        return;
      }
      drag.moved = true;
      drag.block.classList.add('is-dragging');
      drag.tip = document.createElement('div');
      drag.tip.className = 'cal-move__tip mono';
      drag.grid.appendChild(drag.tip);
      drag.scroller = setInterval(autoscroll, 30);
    }
    paint(event);
  });

  document.addEventListener('pointerup', function () {
    var done = drag;
    drag = null;
    if (!done || !done.moved) {
      return;
    }
    finish(done);
    // The click that follows the release would open the edit dialog.
    swallowClick = true;
    setTimeout(function () {
      swallowClick = false;
    }, 0);

    var date = done.column && done.column.dataset.date;
    if (done.start === done.start0 && done.end === done.end0 && date === done.date) {
      restore(done);
      return;
    }
    var values = JSON.parse(done.block.dataset.moveVals);
    values.start = clock(done.start);
    values.end = clock(done.end);
    if (date) {
      values.move_date = date;
    }
    window.htmx.ajax('POST', done.grid.dataset.moveUrl, {
      values: values,
      target: '#time-screen',
      select: '#time-screen',
      swap: 'outerHTML',
    });
  });

  document.addEventListener('click', function (event) {
    if (swallowClick) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);

  // A block is a link, and a dragged link starts the browser's own drag and
  // drop, which cancels the pointer events (Firefox does it at once).
  document.addEventListener('dragstart', function (event) {
    var target = event.target.nodeType === 1 ? event.target : event.target.parentElement;
    if (target && target.closest('[data-move]')) {
      event.preventDefault();
    }
  });

  // The browser took the gesture over, or Escape: put the block back.
  function cancel() {
    if (drag && drag.moved) {
      finish(drag);
      restore(drag);
    }
    drag = null;
  }

  document.addEventListener('pointercancel', cancel);
  document.addEventListener('keydown', function (event) {
    if (drag && event.key === 'Escape') {
      cancel();
    }
  });
})();
