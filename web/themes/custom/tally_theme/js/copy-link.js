/**
 * @file
 * The Share button: copies the invoice's public link to the clipboard and
 * says so for two seconds. The link is already in the button's data-copy-link,
 * so the copy needs no server round trip (a fetch first would lose the click
 * that the browser requires before it lets a page write to the clipboard).
 * HTMX swaps the dialog in, so this is a behavior that runs on each swap.
 */
(function (Drupal, once) {
  Drupal.behaviors.tallyCopyLink = {
    attach(context) {
      once('tally-copy-link', '[data-copy-link]', context).forEach((button) => {
        const label = button.querySelector('span');
        const original = label.textContent;
        button.addEventListener('click', async () => {
          const url = button.dataset.copyLink;
          try {
            await navigator.clipboard.writeText(url);
          }
          catch (error) {
            // No clipboard access (an insecure page, a blocked permission):
            // show the link so it can be copied by hand.
            window.prompt(Drupal.t('Copy this link'), url);
            return;
          }
          label.textContent = button.dataset.copied;
          setTimeout(() => { label.textContent = original; }, 2000);
        });
      });
    },
  };
})(Drupal, once);
