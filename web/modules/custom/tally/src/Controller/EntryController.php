<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\EntryEditor;
use Drupal\tally\TimeScreenBuilder;
use Drupal\tally\TimeTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "Edit time entry" dialog: live preview, save and delete.
 *
 * Save and delete answer with the refreshed Time screen (calendar, totals and
 * sidebar) and an HX-Push-Url header that closes the dialog's address.
 */
class EntryController extends ControllerBase {

  use PushUrlTrait;

  public function __construct(
    protected TimeScreenBuilder $builder,
    protected EntryEditor $editor,
    protected TimeTracker $tracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.time_screen'), $container->get('tally.entry_editor'), $container->get('tally.time_tracker'));
  }

  /**
   * The dialog redrawn from the form's current values: duration, rate line,
   * the error, the project's color and the billable default.
   */
  public function preview(Request $request): array {
    [$options, $entry] = $this->context($request);
    // htmx names the element that made the request in this header.
    $project_changed = $request->headers->get('HX-Trigger-Name') === 'project';
    $draft = $this->editor->adjust($this->editor->draft($request, $entry), $project_changed);
    $error = $this->editor->validate($draft, $entry, $options['day']->modify('monday this week'));
    return [
      '#theme' => 'tally_entry_editor',
      '#panel' => $this->editor->panel($options, $entry, $draft, $error, $this->builder->screenUrl($options, ['edit' => NULL])),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Saves the dialog. Invalid values reopen it with the message.
   */
  public function save(Request $request): array {
    [$options, $entry] = $this->context($request);
    $draft = $this->editor->draft($request, $entry);
    $error = $this->editor->validate($draft, $entry, $options['day']->modify('monday this week'));
    if ($error) {
      return $this->withPushUrl($this->builder->build($options, NULL, FALSE, [], ['draft' => $draft, 'error' => $error]), FALSE);
    }
    if ($error = $this->editor->apply($entry, $draft)) {
      return $this->withPushUrl($this->builder->build($options, NULL, FALSE, [], ['draft' => $draft, 'error' => $error]), FALSE);
    }
    $options['edit'] = $options['new'] = NULL;
    return $this->withPushUrl($this->builder->build($options, NULL, TRUE), $this->builder->screenUrl($options));
  }

  /**
   * Starts a new timer with this entry's description, project, billable
   * status and notes, as they are saved (not as the form shows them). A timer
   * already running is stopped first, like any start.
   */
  public function resume(Request $request): array {
    [$options, $entry] = $this->context($request);
    if (!$entry->isRunning()) {
      $this->tracker->start(
        $this->currentUser(),
        (string) $entry->get('description')->value,
        $entry->get('project')->target_id ? (int) $entry->get('project')->target_id : NULL,
        (bool) $entry->get('billable')->value,
        (string) $entry->get('notes')->value,
      );
    }
    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options, NULL, TRUE), $this->builder->screenUrl($options));
  }

  /**
   * Copies an entry (right-click, Duplicate); the copy lands after the
   * original, ready to be dragged.
   */
  public function duplicate(Request $request): array {
    [$options, $entry] = $this->context($request);
    $this->editor->duplicate($entry);
    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options, NULL, TRUE), $this->builder->screenUrl($options));
  }

  /**
   * Moves or resizes an entry dragged on the calendar: the new day (week view
   * only) and the start and end as HH:MM. A refused move leaves the entry as
   * it was, and the redrawn screen puts the block back.
   */
  public function move(Request $request): array {
    [$options, $entry] = $this->context($request);
    $this->editor->move(
      $entry,
      (string) ($request->request->get('move_date') ?: $options['day']->format('Y-m-d')),
      (string) $request->request->get('start'),
      (string) $request->request->get('end'),
      $options['day']->modify('monday this week'),
    );
    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options, NULL, TRUE), $this->builder->screenUrl($options));
  }

  /**
   * Deletes the entry (for a running one: discards the timer).
   */
  public function delete(Request $request): array {
    [$options, $entry] = $this->context($request);
    $entry->delete();
    $options['edit'] = $options['new'] = NULL;
    return $this->withPushUrl($this->builder->build($options, NULL, TRUE), $this->builder->screenUrl($options));
  }

  /**
   * The screen options and the entry being edited; 404 when it is not yours.
   */
  protected function context(Request $request): array {
    $options = $this->builder->options($request);
    $entry = $this->editor->forOptions($options);
    if (!$entry) {
      throw new NotFoundHttpException();
    }
    return [$options, $entry];
  }

}
