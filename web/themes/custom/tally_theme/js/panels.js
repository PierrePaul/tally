/**
 * @file
 * Escape closes an open dialog by following its close link, which HTMX
 * handles like any other click. Also stops Enter submitting HTMX-driven forms.
 */
document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape') {
    return;
  }
  var close = document.querySelector('[data-panel-close]');
  if (close) {
    close.click();
  }
});

// Forms marked data-no-submit are driven by HTMX; Enter must not reload the page.
document.addEventListener('submit', function (event) {
  if (event.target.matches('[data-no-submit]')) {
    event.preventDefault();
  }
});
