/**
 * @file
 * The right-click menu on a time entry: Resume, Duplicate, Edit, Delete.
 *
 * HTMX cannot listen for a right click, so this is the one place plain
 * JavaScript is needed. The menu only calls what the page already offers: Resume
 * posts to the resume route with the values the entry carries in
 * data-resume-vals, Edit follows the entry's own edit link, and Delete opens
 * that same dialog and presses its Delete button, so deleting still asks first.
 * One listener on the document, so entries that HTMX swaps in work too.
 */
(function (Drupal) {
  let menu = null;
  let deleteAfterOpen = false;

  const close = () => {
    if (menu) {
      menu.remove();
      menu = null;
    }
  };

  const item = (label, onClick, extra) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('role', 'menuitem');
    button.className = 'entry-menu__item' + (extra ? ' ' + extra : '');
    button.textContent = label;
    button.addEventListener('click', () => {
      close();
      onClick();
    });
    return button;
  };

  document.addEventListener('contextmenu', (event) => {
    const entry = event.target.closest('[data-entry-menu]');
    const edit = entry && entry.querySelector('[data-entry-edit]');
    if (!entry || !edit || !window.htmx) {
      return;
    }
    event.preventDefault();
    close();

    menu = document.createElement('div');
    menu.className = 'entry-menu';
    menu.setAttribute('role', 'menu');

    // A running entry is already ticking; there is nothing to resume.
    if (entry.dataset.resumeVals) {
      menu.append(item(Drupal.t('Resume'), () => {
        window.htmx.ajax('POST', entry.dataset.resumeUrl, {
          values: JSON.parse(entry.dataset.resumeVals),
          target: '#time-screen',
          select: '#time-screen',
          swap: 'outerHTML',
        });
      }));
    }
    // Same screen options as Resume; the copy lands after the original.
    if (entry.dataset.duplicateUrl && entry.dataset.resumeVals) {
      menu.append(item(Drupal.t('Duplicate'), () => {
        window.htmx.ajax('POST', entry.dataset.duplicateUrl, {
          values: JSON.parse(entry.dataset.resumeVals),
          target: '#time-screen',
          select: '#time-screen',
          swap: 'outerHTML',
        });
      }));
    }
    menu.append(item(Drupal.t('Edit'), () => edit.click()));
    menu.append(item(Drupal.t('Delete'), () => {
      deleteAfterOpen = true;
      edit.click();
    }, 'entry-menu__item--danger'));

    document.body.append(menu);
    // Keep the menu on screen near the pointer.
    const box = menu.getBoundingClientRect();
    menu.style.left = Math.max(4, Math.min(event.clientX, window.innerWidth - box.width - 4)) + 'px';
    menu.style.top = Math.max(4, Math.min(event.clientY, window.innerHeight - box.height - 4)) + 'px';
    menu.querySelector('button').focus();
  });

  // Delete from the menu: once the dialog has opened, press its Delete button,
  // which shows the usual "are you sure" step.
  document.addEventListener('htmx:afterSettle', () => {
    if (deleteAfterOpen) {
      const ask = document.querySelector('#entry-edit .entry-foot__delete');
      // Whatever came back, this was the response to the click: if the dialog
      // did not open, the request is forgotten and cannot fire on a later one.
      deleteAfterOpen = false;
      if (ask) {
        ask.click();
      }
    }
  });

  document.addEventListener('click', (event) => {
    if (menu && !menu.contains(event.target)) {
      close();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (!menu) {
      return;
    }
    if (event.key === 'Escape') {
      close();
    }
    else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const buttons = [...menu.querySelectorAll('button')];
      const next = buttons.indexOf(document.activeElement) + (event.key === 'ArrowDown' ? 1 : -1);
      buttons[(next + buttons.length) % buttons.length].focus();
    }
  });
  window.addEventListener('scroll', close, true);
  window.addEventListener('resize', close);
})(Drupal);
