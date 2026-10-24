/**
 * @file
 * Reorders invoice items by dragging the grip, or with the up and down arrow
 * keys on it. The line moves in the page as you drag; on drop the new order
 * goes into the hidden "order" field and the "reordered" event tells HTMX to
 * post it.
 */
(function (Drupal, once) {
  Drupal.behaviors.tallyInvoiceLines = {
    attach(context) {
      once('tally-ilines', '[data-sortable]', context).forEach((list) => {
        const lines = () => [...list.querySelectorAll('.iline')];
        const order = () => lines().map((line) => line.dataset.item).join(',');
        const save = () => {
          list.querySelector('input[name="order"]').value = order();
          htmx.trigger(list, 'reordered');
        };
        let dragged = null;
        let before = '';

        list.addEventListener('dragstart', (event) => {
          const grip = event.target.closest('[data-grip]');
          if (!grip) {
            return;
          }
          dragged = grip.closest('.iline');
          before = order();
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', dragged.dataset.item);
          event.dataTransfer.setDragImage(dragged, 24, 24);
          // After the browser has taken the drag image, so it is not faded too.
          requestAnimationFrame(() => dragged.classList.add('is-dragging'));
        });

        list.addEventListener('dragover', (event) => {
          if (!dragged) {
            return;
          }
          event.preventDefault();
          const next = lines().find((line) => {
            const box = line.getBoundingClientRect();
            return line !== dragged && event.clientY < box.top + box.height / 2;
          });
          list.insertBefore(dragged, next ?? null);
        });

        list.addEventListener('drop', (event) => event.preventDefault());

        list.addEventListener('dragend', () => {
          if (!dragged) {
            return;
          }
          dragged.classList.remove('is-dragging');
          dragged = null;
          if (order() !== before) {
            save();
          }
        });

        list.addEventListener('keydown', (event) => {
          const grip = event.target.closest('[data-grip]');
          if (!grip || !['ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
          }
          event.preventDefault();
          const line = grip.closest('.iline');
          const up = event.key === 'ArrowUp';
          const other = up ? line.previousElementSibling : line.nextElementSibling;
          if (!other?.matches('.iline')) {
            return;
          }
          list.insertBefore(line, up ? other : other.nextElementSibling);
          grip.focus();
          save();
        });
      });
    },
  };
})(Drupal, once);
