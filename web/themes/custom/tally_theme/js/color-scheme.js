/**
 * @file
 * Light/dark toggle. The saved choice is applied before first paint by the
 * inline script in html.html.twig; this only handles clicks on the toggle.
 */
(function (Drupal, once) {
  'use strict';

  function current() {
    var set = document.documentElement.getAttribute('data-theme');
    if (set) {
      return set;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  Drupal.behaviors.tallyColorScheme = {
    attach: function (context) {
      once('tally-scheme', '[data-scheme-toggle]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var next = current() === 'dark' ? 'light' : 'dark';
          document.documentElement.setAttribute('data-theme', next);
          try {
            localStorage.setItem('tally-theme', next);
          }
          catch (e) {}
        });
      });
    }
  };
})(Drupal, once);
