/**
 * @file
 * Adds a show/hide button to password fields.
 */
(function (Drupal, once) {
  'use strict';

  var EYE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

  Drupal.behaviors.tallyPasswordToggle = {
    attach: function (context) {
      once('tally-pw', 'input[type="password"]', context).forEach(function (input) {
        var wrap = document.createElement('div');
        wrap.className = 'pw-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'pw-field__toggle';
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', Drupal.t('Show password'));
        button.innerHTML = EYE;
        wrap.appendChild(button);

        button.addEventListener('click', function () {
          var show = input.type === 'password';
          input.type = show ? 'text' : 'password';
          button.setAttribute('aria-pressed', show ? 'true' : 'false');
          button.setAttribute('aria-label', show ? Drupal.t('Hide password') : Drupal.t('Show password'));
          button.innerHTML = show ? EYE_OFF : EYE;
        });
      });
    }
  };
})(Drupal, once);
