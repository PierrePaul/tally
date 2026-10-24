/**
 * @file
 * Opens and closes the sidebar drawer on narrow screens. The open state is a
 * class on .app, which the CSS turns into the slide-in.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.tallyNavDrawer = {
    attach: function (context) {
      once('tally-nav', '[data-nav]', context).forEach(function (app) {
        var opener = app.querySelector('[data-nav-open]');
        var backdrop = app.querySelector('.nav-backdrop');
        var narrow = window.matchMedia('(max-width: 799.98px)');

        function set(open) {
          app.classList.toggle('is-nav-open', open);
          opener.setAttribute('aria-expanded', open ? 'true' : 'false');
          backdrop.hidden = !open;
          if (open) {
            // Focus moves into the drawer; closing hands it back to the button.
            app.querySelector('.nav__link').focus();
          }
        }

        opener.addEventListener('click', function () {
          set(true);
        });
        app.querySelectorAll('[data-nav-close]').forEach(function (el) {
          el.addEventListener('click', function () {
            set(false);
            opener.focus();
          });
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && app.classList.contains('is-nav-open')) {
            set(false);
            opener.focus();
          }
        });
        // HTMX swaps only the main area, so the drawer stays open after a nav
        // link is clicked unless we close it.
        app.querySelector('.nav').addEventListener('click', function (event) {
          if (event.target.closest('a') && narrow.matches) {
            set(false);
          }
        });
        narrow.addEventListener('change', function () {
          set(false);
        });
      });
    }
  };
})(Drupal, once);
