<?php

namespace Drupal\tally;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\tally\Entity\TimeEntry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the render arrays for the Time screen and the sidebar timer card.
 *
 * Everything the templates need is computed here, so the Twig stays dumb.
 */
class TimeScreenBuilder {

  use StringTranslationTrait;

  /**
   * Zoom label (minutes) => pixels per hour.
   */
  public const ZOOMS = ['15' => 96, '30' => 64, '60' => 48];

  /**
   * Smallest height of a calendar block, so short entries stay readable.
   */
  protected const MIN_BLOCK_HEIGHT = 34;

  public function __construct(
    protected TimeTracker $tracker,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected EntryEditor $entryEditor,
    protected Settings $settings,
    protected OwnerScope $owner,
  ) {}

  /**
   * Wall-clock minutes since local midnight of $day, 0 to 1440.
   *
   * Clock changes make some days 23 or 25 hours long. The grid is labelled
   * with wall-clock hours, and the drag script turns a position into HH:MM, so
   * positions are wall-clock too, not elapsed time.
   */
  public static function minutesIntoDay(int $timestamp, \DateTimeImmutable $day): float {
    if ($timestamp <= $day->getTimestamp()) {
      return 0.0;
    }
    if ($timestamp >= $day->modify('+1 day')->getTimestamp()) {
      return 1440.0;
    }
    $local = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($day->getTimezone());
    return (int) $local->format('G') * 60 + (int) $local->format('i') + (int) $local->format('s') / 60;
  }

  /**
   * Reads and validates the screen options (date, zoom, view, range).
   *
   * @return array{day: \DateTimeImmutable, zoom: string, view: string, range: string, edit: ?string, new: ?string, rows: int[], q: string, proj: ?string, bill: string}
   */
  public function options(Request $request): array {
    $tz = $this->timeZone();
    $date = (string) ($request->query->get('date') ?? $request->request->get('date'));
    $day = Dates::parse($date, $tz) ?? FALSE;
    $pick = fn (string $key, array $allowed, string $default) => in_array($value = (string) ($request->query->get($key) ?? $request->request->get($key)), $allowed, TRUE) ? $value : $default;
    $view = $pick('view', ['calendar', 'list', 'timesheet'], 'calendar');
    // Project rows added to the timesheet by hand, kept in the URL as ?rows=3,5.
    $rows = array_filter(explode(',', (string) ($request->query->get('rows') ?? $request->request->get('rows'))), 'ctype_digit');
    $add = (string) ($request->query->get('add') ?? $request->request->get('add'));
    if (ctype_digit($add) && $add !== '') {
      $rows[] = $add;
    }
    return [
      'day' => $day ?: $this->today(),
      'zoom' => $pick('zoom', array_map('strval', array_keys(static::ZOOMS)), (string) $this->settings->get('zoom')),
      'view' => $view,
      // The timesheet always shows a full week.
      'range' => $view === 'timesheet' ? 'week' : $pick('range', ['day', 'week'], 'day'),
      // The time entry open in the edit dialog.
      'edit' => ctype_digit($edit = (string) ($request->query->get('edit') ?? $request->request->get('edit'))) && $edit !== '' ? $edit : NULL,
      // A new entry's "HH:MM-HH:MM", from dragging on the day calendar.
      'new' => preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $new = (string) ($request->query->get('new') ?? $request->request->get('new'))) ? $new : NULL,
      'rows' => array_values(array_unique(array_map('intval', $rows))),
      // The List view's filters: a search text, a project and billable or not.
      'q' => mb_substr(trim((string) ($request->query->get('q') ?? $request->request->get('q'))), 0, 100),
      'proj' => ctype_digit($proj = (string) ($request->query->get('proj') ?? $request->request->get('proj'))) && $proj !== '' ? $proj : NULL,
      'bill' => $pick('bill', ['bill', 'non'], 'all'),
    ];
  }

  /**
   * Reads the timer card fields posted by the form.
   */
  public function draftFromRequest(Request $request): array {
    $project = (string) $request->request->get('project');
    $project_id = ctype_digit($project) ? (int) $project : NULL;
    if ($project_id !== NULL && !isset($this->loadProjects()[$project_id])) {
      $project_id = NULL;
    }
    return [
      'description' => mb_substr(trim((string) $request->request->get('description')), 0, 255),
      'project' => $project_id,
      'billable' => (bool) $request->request->get('billable'),
      'notes' => trim((string) $request->request->get('notes')),
    ];
  }

  /**
   * Builds the whole Time screen.
   *
   * @param array|null $draft
   *   Timer card values from the request. Used while no timer is running.
   * @param bool $with_sidebar
   *   Add the sidebar card too, for HTMX to swap in out of band.
   * @param array $invalid
   *   Timesheet cells whose text was not a duration, keyed "project-date".
   * @param array $entry_state
   *   The edit dialog's unsaved 'draft' and its 'error', after a failed save
   *   or a live preview.
   */
  public function build(array $options, ?array $draft = NULL, bool $with_sidebar = FALSE, array $invalid = [], array $entry_state = []): array {
    $day = $options['day'];
    $is_week = $options['range'] === 'week';
    $from = $day->getTimestamp();
    $to = $day->modify('+1 day')->getTimestamp();
    $week_start = $day->modify('monday this week');
    $week_from = $week_start->getTimestamp();
    $week_to = $week_start->modify('+7 days')->getTimestamp();
    $entries = $this->tracker->getEntries($this->currentUser, $week_from, $week_to);
    $today_start = $this->today();
    $step = $is_week ? '7 days' : '1 day';

    if ($is_week) {
      $week = $this->weekVariables($options, $entries, $week_start);
      $is_current = $today_start >= $week_start && $today_start < $week_start->modify('+7 days');
      $diff = (int) round(($week_from - $today_start->modify('monday this week')->getTimestamp()) / (7 * 86400));
      $eyebrow = match (TRUE) {
        $is_current => $this->t('This week'),
        $diff === -1 => $this->t('Last week'),
        $diff === 1 => $this->t('Next week'),
        default => $this->format($week_from, 'Y'),
      };
      $title = $week['title'];
      $date_pill = $week['pill'];
      $totals = $week['totals'];
      $calendar = match ($options['view']) {
        'timesheet' => $this->timesheetVariables($options, $entries, $week_start, $week['totals'], $invalid),
        'list' => $this->listVariables($options, $entries, $week_from, $week_to),
        default => $week['calendar'],
      };
    }
    else {
      $counted = $this->tracker->counted($entries);
      $summary_today = $this->tracker->summarize($counted, $from, $to);
      $summary_week = $this->tracker->summarize($counted, $week_from, $week_to);
      $summary_billable = $this->tracker->summarize($counted, $from, $to, TRUE);
      $is_current = $from === $today_start->getTimestamp();
      $diff = (int) round(($from - $today_start->getTimestamp()) / 86400);
      $eyebrow = match (TRUE) {
        $is_current => $this->t('Today'),
        $diff === -1 => $this->t('Yesterday'),
        $diff === 1 => $this->t('Tomorrow'),
        default => $this->format($from, 'Y'),
      };
      $title = $this->format($from, 'l, F j');
      $date_pill = $this->format($from, 'M j');
      $totals = [
        ['label' => $this->t('Today'), 'value' => $this->total($summary_today)],
        ['label' => $this->t('This week'), 'value' => $this->total($summary_week)],
        ['label' => $this->t('Billable today'), 'value' => $this->total($summary_billable)],
      ];
      $calendar = $options['view'] === 'list' ? $this->listVariables($options, $entries, $from, $to) : $this->calendarVariables($options, $entries, $summary_today);
    }

    $screen = [
      'options' => $options,
      'eyebrow' => $eyebrow,
      'title' => $title,
      'date_pill' => $date_pill,
      'date_value' => $day->format('Y-m-d'),
      'is_today' => $is_current,
      'is_week' => $is_week,
      'urls' => [
        'previous' => $this->url($options, ['date' => $day->modify('-' . $step)->format('Y-m-d')]),
        'next' => $this->url($options, ['date' => $day->modify('+' . $step)->format('Y-m-d')]),
        'today' => $this->url($options, ['date' => NULL]),
        'base' => Url::fromRoute('tally.time')->toString(),
        'calendar' => Url::fromRoute('tally.time.calendar', [], ['query' => $this->query($options)])->toString(),
      ],
      'ranges' => $this->choices($options, 'range', ['day' => $this->t('Day'), 'week' => $this->t('Week')]),
      'views' => $this->choices($options, 'view', ['list' => $this->t('List'), 'calendar' => $this->t('Calendar'), 'timesheet' => $this->t('Timesheet')]),
      'zooms' => $this->choices($options, 'zoom', ['15' => $this->t('15 min'), '30' => $this->t('30 min'), '60' => $this->t('1 hr')]),
      'timer' => $this->timer($draft),
      'totals' => $totals,
      'calendar' => $calendar,
      'editor' => $this->editorPanel($options, $entry_state),
    ];

    $build = [
      '#theme' => 'tally_time_screen',
      '#screen' => $screen,
      '#attached' => ['library' => ['core/drupal.htmx']],
      '#cache' => ['max-age' => 0],
    ];
    if ($with_sidebar) {
      $build['#sidebar'] = $this->buildSidebar();
    }
    return $build;
  }

  /**
   * The Time screen URL with some options changed. NULL drops an option.
   */
  public function screenUrl(array $options, array $changes = []): string {
    return $this->url($options, $changes);
  }

  /**
   * The edit dialog for the entry named in the options, or NULL.
   */
  public function editorPanel(array $options, array $entry_state = []): ?array {
    $entry = $this->entryEditor->forOptions($options);
    if (!$entry) {
      return NULL;
    }
    $draft = $entry_state['draft'] ?? $this->entryEditor->draft(new Request(), $entry);
    $error = $entry_state['error'] ?? NULL;
    return $this->entryEditor->panel($options, $entry, $draft, $error, $this->url($options, ['edit' => NULL, 'new' => NULL]));
  }

  /**
   * Builds only the day or week calendar, for the HTMX refresh.
   */
  public function buildCalendar(array $options): array {
    if ($options['range'] === 'week') {
      $week_start = $options['day']->modify('monday this week');
      $entries = $this->tracker->getEntries($this->currentUser, $week_start->getTimestamp(), $week_start->modify('+7 days')->getTimestamp());
      return [
        '#theme' => 'tally_week_calendar',
        '#calendar' => $this->weekVariables($options, $entries, $week_start)['calendar'],
        '#attached' => ['library' => ['core/drupal.htmx']],
        '#cache' => ['max-age' => 0],
      ];
    }
    $from = $options['day']->getTimestamp();
    $to = $options['day']->modify('+1 day')->getTimestamp();
    $entries = $this->tracker->getEntries($this->currentUser, $from, $to);
    return [
      '#theme' => 'tally_day_calendar',
      '#calendar' => $this->calendarVariables($options, $entries, $this->tracker->summarize($this->tracker->counted($entries), $from, $to)),
      '#attached' => ['library' => ['core/drupal.htmx']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds the sidebar card: the running timer, or this week's total.
   */
  public function buildSidebar(): array {
    $running = $this->tracker->getRunning($this->currentUser);
    $status = ['running' => (bool) $running];
    if ($running) {
      $project = $running->get('project')->entity;
      $start = (int) $running->get('start')->value;
      $status += [
        'title' => $running->get('description')->value ?: $this->t('Untitled'),
        'project' => $project?->label(),
        'clock' => TimeTracker::formatClock($this->time->getRequestTime() - $start),
        'live_start' => $start,
      ];
    }
    else {
      $monday = $this->today()->modify('monday this week');
      $week_from = $monday->getTimestamp();
      // Calendar days, not 7 * 86400 seconds: a clock change makes the week 167 or 169 hours.
      $week_to = $monday->modify('+7 days')->getTimestamp();
      $week = $this->tracker->summarize(
        $this->tracker->counted($this->tracker->getEntries($this->currentUser, $week_from, $week_to)),
        $week_from,
        $week_to,
      );
      $status['week'] = $this->total($week);
    }
    return [
      '#theme' => 'tally_sidebar_status',
      '#status' => $status,
      // The Stop button posts with htmx, and this card shows on every page.
      '#attached' => ['library' => ['core/drupal.htmx']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The timer card's state and values.
   *
   * While a timer runs the card shows that entry. Otherwise it shows a draft
   * for the next one: the posted values, or empty ones (the billable default
   * comes from Settings). Stopping clears the card; an earlier entry is
   * started again with Resume in its dialog.
   */
  protected function timer(?array $draft): array {
    $now = $this->time->getRequestTime();
    $running = $this->tracker->getRunning($this->currentUser);
    $projects = $this->loadProjects();

    $timer = ['projects' => [], 'running' => (bool) $running, 'live_start' => NULL];
    if ($running) {
      $values = $this->valuesOf($running);
      $state = 'running';
      $seconds = $now - (int) $running->get('start')->value;
      $timer['live_start'] = (int) $running->get('start')->value;
    }
    else {
      $values = $draft ?? ['description' => '', 'project' => NULL, 'billable' => (bool) $this->settings->get('billable'), 'notes' => ''];
      $state = 'ready';
      $seconds = 0;
    }

    $selected = $projects[$values['project']] ?? NULL;
    foreach ($projects as $project) {
      $timer['projects'][] = $project + ['selected' => $selected && $selected['id'] === $project['id']];
    }
    return $timer + $values + [
      'state' => $state,
      'status_label' => match ($state) {
        'running' => $this->t('Timer running'),
        default => $this->t('Ready'),
      },
      'action' => match ($state) {
        'running' => 'stop',
        default => 'start',
      },
      'clock' => TimeTracker::formatClock($seconds),
      'project_color' => $selected['color'] ?? NULL,
    ];
  }

  /**
   * What a Resume click posts: the entry and the screen it came from, so the
   * answer redraws the same date, view and filters. JSON for hx-vals.
   */
  protected function resumeVals(array $options, TimeEntry $entry): string {
    return json_encode(array_filter([
      'edit' => (string) $entry->id(),
      'date' => $options['day']->format('Y-m-d'),
      'zoom' => $options['zoom'],
      'view' => $options['view'],
      'range' => $options['range'],
      'rows' => implode(',', $options['rows']),
      'q' => $options['q'],
      'proj' => $options['proj'],
      'bill' => $options['bill'],
    ], fn ($value) => $value !== NULL && $value !== ''));
  }

  /**
   * What the drag script needs to move or resize a block: the screen options
   * and the entry's wall-clock minutes. Only a finished entry that sits inside
   * one day can be dragged (js/calendar-move.js).
   *
   * @return array{move_vals: ?string, start_min: int, end_min: int}
   */
  protected function moveVariables(array $options, TimeEntry $entry, int $start, int $end, int $day_start, float $start_min, float $end_min): array {
    $movable = !$entry->isRunning() && $start >= $day_start && $end_min < 1440 && floor($end_min) > floor($start_min);
    return [
      'move_vals' => $movable ? $this->resumeVals($options, $entry) : NULL,
      'start_min' => (int) floor($start_min),
      'end_min' => (int) floor($end_min),
    ];
  }

  /**
   * The editable values of an entry, in the shape the card uses.
   */
  protected function valuesOf(TimeEntry $entry): array {
    return [
      'description' => (string) $entry->get('description')->value,
      'project' => $entry->get('project')->target_id ? (int) $entry->get('project')->target_id : NULL,
      'billable' => (bool) $entry->get('billable')->value,
      'notes' => (string) $entry->get('notes')->value,
    ];
  }

  /**
   * One of the user's projects by ID, or NULL.
   */
  public function loadOwnedProject(string $id): ?Entity\Project {
    $project = $this->owner->load('tally_project', $id);
    return $project instanceof Entity\Project ? $project : NULL;
  }

  /**
   * The user's active projects, keyed by ID.
   *
   * @return array<int, array{id: int, name: string, color: string}>
   */
  protected function loadProjects(): array {
    $storage = $this->entityTypeManager->getStorage('tally_project');
    $ids = $this->owner->query('tally_project')
      ->condition('archived', 0)
      ->sort('name')
      ->execute();
    $projects = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      $projects[(int) $project->id()] = [
        'id' => (int) $project->id(),
        'name' => (string) $project->label(),
        'color' => $project->getColor(),
      ];
    }
    return $projects;
  }

  /**
   * Variables for the day calendar.
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *   Entries around the day; the ones outside it are skipped.
   */
  protected function calendarVariables(array $options, array $entries, array $today): array {
    $now = $this->time->getRequestTime();
    $from = $options['day']->getTimestamp();
    $to = $options['day']->modify('+1 day')->getTimestamp();
    $is_today = $from === $this->today()->getTimestamp();
    $hour_px = static::ZOOMS[$options['zoom']];

    // The whole day is on the grid; the scroll area starts around the current time.
    $first = 0;
    $last = 24;
    $spans = [];
    foreach ($entries as $entry) {
      $start = (int) $entry->get('start')->value;
      $end = $entry->isRunning() ? $now : (int) $entry->get('end')->value;
      if ($start >= $to || $end <= $from) {
        continue;
      }
      $start_min = static::minutesIntoDay($start, $options['day']);
      $end_min = static::minutesIntoDay($end, $options['day']);
      $spans[] = [$entry, $start, $end, $start_min, $end_min];
    }
    $now_min = static::minutesIntoDay($now, $options['day']);
    $to_y = fn (float $minutes) => round(($minutes - $first * 60) / 60 * $hour_px, 1);

    $hours = [];
    for ($h = $first; $h <= $last; $h++) {
      $hours[] = ['label' => sprintf('%02d:00', $h), 'top' => ($h - $first) * $hour_px, 'height' => $hour_px];
    }

    $blocks = [];
    foreach ($spans as [$entry, $start, $end, $start_min, $end_min]) {
      $project = $entry->get('project')->entity;
      $top = $to_y($start_min);
      $move = $this->moveVariables($options, $entry, $start, $end, $from, $start_min, $end_min);
      $range = $this->format($start, 'H:i') . ' – ' . ($entry->isRunning() ? $this->t('now') : $this->format($end, 'H:i'));
      $blocks[] = [
        'id' => (int) $entry->id(),
        'url' => $this->url($options, ['edit' => (string) $entry->id()]),
        'resume_vals' => $entry->isRunning() ? NULL : $this->resumeVals($options, $entry),
        'billable' => (bool) $entry->get('billable')->value,
        'selected' => $options['edit'] === (string) $entry->id(),
        'top' => $top,
        'height' => max($to_y($end_min) - $top, static::MIN_BLOCK_HEIGHT),
        'color' => $project ? $project->getColor() : NULL,
        'title' => $entry->get('description')->value ?: $this->t('Untitled'),
        'meta' => $project ? $project->label() . ' · ' . $range : $range,
        'running' => $entry->isRunning(),
      ] + $move;
    }

    $blocks = $this->assignLanes($blocks);

    return [
      'options' => $options,
      'hours' => $hours,
      'grid_height' => ($last - $first) * $hour_px + 8,
      // For dragging out a new entry: the grid's first hour, scale and link.
      'first' => $first,
      'last' => $last,
      'hour_px' => $hour_px,
      'new_url' => $this->url($options, ['new' => '__RANGE__']),
      'blocks' => $blocks,
      'now' => $is_today ? ['top' => $to_y($now_min), 'label' => $this->format($now, 'H:i'), 'minutes' => $now_min, 'at' => $now] : NULL,
      'scroll_key' => 'day:' . $this->format($from, 'Y-m-d') . ':' . $hour_px,
      'dow' => strtoupper($this->format($from, 'D')),
      'day_number' => $this->format($from, 'j'),
      'weekday' => $this->format($from, 'l'),
      'tracked' => $this->total($today),
      'running' => (bool) array_filter($blocks, fn ($b) => $b['running']) && $is_today,
      'poll_url' => Url::fromRoute('tally.time.calendar', [], ['query' => $this->query($options)])->toString(),
    ];
  }

  /**
   * Everything the week view needs: title, totals and the calendar.
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *   Entries overlapping the week.
   */
  protected function weekVariables(array $options, array $entries, \DateTimeImmutable $week_start): array {
    $now = $this->time->getRequestTime();
    $week_from = $week_start->getTimestamp();
    $week_end = $week_start->modify('+7 days');
    $week_to = $week_end->getTimestamp();
    $today = $this->today();
    $hour_px = static::ZOOMS[$options['zoom']];

    // First pass: each entry's span within each day. An entry crossing
    // midnight shows in both days, cut at the edges.
    $spans = [];
    $first = 0;
    $last = 24;
    for ($i = 0; $i < 7; $i++) {
      $from = $week_start->modify("+$i days")->getTimestamp();
      $to = $week_start->modify('+' . ($i + 1) . ' days')->getTimestamp();
      foreach ($entries as $entry) {
        $start = (int) $entry->get('start')->value;
        $end = $entry->isRunning() ? $now : (int) $entry->get('end')->value;
        // Skip entries outside the day; a zero-length one still counts.
        if ($start >= $to || $end < $from || ($end == $from && $end > $start)) {
          continue;
        }
        $start_min = static::minutesIntoDay($start, $week_start->modify("+$i days"));
        $end_min = static::minutesIntoDay($end, $week_start->modify("+$i days"));
        $spans[$i][] = [$entry, $start, $end, $start_min, $end_min];
      }
    }
    $to_y = fn (float $minutes) => round(($minutes - $first * 60) / 60 * $hour_px, 1);

    $hours = [];
    for ($h = $first; $h <= $last; $h++) {
      $hours[] = ['label' => sprintf('%02d:00', $h), 'top' => ($h - $first) * $hour_px];
    }

    // Second pass: the day columns and their blocks.
    $counted = $this->tracker->counted($entries);
    $days = [];
    $total_seconds = 0;
    $worked_days = 0;
    $now_top = NULL;
    $now_min = NULL;
    $running = FALSE;
    for ($i = 0; $i < 7; $i++) {
      $day = $week_start->modify("+$i days");
      $from = $day->getTimestamp();
      $to = $week_start->modify('+' . ($i + 1) . ' days')->getTimestamp();
      $is_today = $day == $today;
      $is_future = $day > $today;
      $summary = $this->tracker->summarize($counted, $from, $to);
      $total_seconds += $summary['seconds'];
      $worked_days += $summary['seconds'] > 0 ? 1 : 0;

      $blocks = [];
      foreach ($spans[$i] ?? [] as [$entry, $start, $end, $start_min, $end_min]) {
        $top = $to_y($start_min);
        $height = max($to_y($end_min) - $top - 2, 22);
        $project = $entry->get('project')->entity;
        $billable = (bool) $entry->get('billable')->value;
        $range = $this->format($start, 'H:i') . ' – ' . ($entry->isRunning() ? $this->t('now') : $this->format($end, 'H:i'));
        $title = (string) ($entry->get('description')->value ?: $this->t('Untitled'));
        $is_selected = $options['edit'] === (string) $entry->id();
        $color = $project ? $project->getColor() : NULL;
        $blocks[] = [
          'top' => $top,
          'height' => $height,
          'color' => $color,
          'title' => $title,
          'project' => $project ? (string) $project->label() : '',
          'range' => $range,
          'tip' => $title . ($project ? ' · ' . $project->label() : '') . ' · ' . $range,
          'billable' => $billable,
          'running' => $entry->isRunning(),
          'selected' => $is_selected,
          'show_meta' => $height >= 58,
          'compact' => $height < 40,
          'url' => $this->url($options, ['edit' => (string) $entry->id()]),
          'resume_vals' => $entry->isRunning() ? NULL : $this->resumeVals($options, $entry),
        ] + $this->moveVariables($options, $entry, $start, $end, $from, $start_min, $end_min);
        $running = $running || ($entry->isRunning() && $is_today);
      }

      $now_in_day = $is_today ? $to_y(static::minutesIntoDay($now, $day)) : NULL;
      $now_top = $is_today ? $now_in_day : $now_top;
      $now_min = $is_today ? static::minutesIntoDay($now, $day) : $now_min;
      $days[] = [
        'dow' => strtoupper($this->format($from, 'D')),
        'num' => $this->format($from, 'j'),
        'date' => $this->format($from, 'Y-m-d'),
        'total' => $is_future ? '-' : TimeTracker::formatDuration($summary['seconds']),
        'live' => $summary['live_start'] !== NULL ? ['start' => $summary['live_start'], 'base' => $summary['base']] : NULL,
        'today' => $is_today,
        'future' => $is_future,
        'blocks' => $this->assignLanes($blocks),
        'now_top' => $now_in_day,
      ];
    }

    // The amount: billable hours at each project's rate, rounded per entry.
    $billable_summary = $this->tracker->summarize($counted, $week_from, $week_to, TRUE);
    $cents = [];
    foreach ($entries as $entry) {
      if (!$entry->get('billable')->value) {
        continue;
      }
      $seconds = $this->tracker->summarize([$entry], $week_from, $week_to)['seconds'];
      $project = $entry->get('project')->entity;
      $currency = $project?->getClient()?->getCurrency() ?? 'CAD';
      $cents[$currency] = ($cents[$currency] ?? 0) + Money::forSeconds($seconds, $project?->getEffectiveRate() ?? 0);
    }
    $summary_week = $this->tracker->summarize($counted, $week_from, $week_to);
    $last_day = $week_end->modify('-1 day');
    $same_month = $week_start->format('Y-m') === $last_day->format('Y-m');
    $pill = $this->format($week_from, 'M j') . ' – ' . ($same_month ? Dates::lastDayOfRange($last_day) : $this->format($last_day->getTimestamp(), 'M j'));
    $title = $this->format($week_from, 'F j') . ' – ' . ($same_month ? Dates::lastDayOfRange($last_day, TRUE) : $this->format($last_day->getTimestamp(), 'F j'));

    return [
      'title' => $title,
      'pill' => $pill,
      'totals' => [
        ['label' => $this->t('This week'), 'value' => $this->total($summary_week)],
        ['label' => $this->t('Billable this week'), 'value' => $this->total($billable_summary)],
        ['label' => $this->t('Billable amount'), 'value' => ['text' => Money::formatSums($cents), 'live_start' => NULL, 'base' => 0]],
        ['label' => $this->t('Daily average'), 'value' => ['text' => TimeTracker::formatDuration($worked_days ? intdiv($total_seconds, $worked_days) : 0), 'live_start' => NULL, 'base' => 0]],
      ],
      'calendar' => [
        'options' => $options,
        'zone' => $this->format($week_from, 'T'),
        'hours' => $hours,
        'grid_height' => ($last - $first) * $hour_px + 4,
        // For dragging out a new entry, see the day calendar.
        'first' => $first,
        'last' => $last,
        'hour_px' => $hour_px,
        'new_url' => $this->url($options, ['new' => '__RANGE__', 'date' => '__DATE__']),
        'days' => $days,
        'now' => $now_top !== NULL ? ['top' => $now_top, 'label' => $this->format($now, 'H:i'), 'minutes' => $now_min, 'at' => $now] : NULL,
        'scroll_key' => 'week:' . $this->format($week_from, 'Y-m-d') . ':' . $hour_px,
        'running' => $running,
        'poll_url' => Url::fromRoute('tally.time.calendar', [], ['query' => $this->query($options)])->toString(),
      ],
    ];
  }

  /**
   * The timesheet grid: a row per project, a column per day.
   *
   * A row is any project with time this week plus the rows added by hand
   * (?rows=). Cell ids are "project-date" ("0" is time with no project).
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *   Entries overlapping the week.
   * @param array $totals
   *   The week's totals, as weekVariables() builds them.
   * @param array<string, string> $invalid
   *   Cells whose text was not a duration, with what was typed.
   */
  protected function timesheetVariables(array $options, array $entries, \DateTimeImmutable $week_start, array $totals, array $invalid): array {
    $now = $this->time->getRequestTime();
    $today = $this->today();
    $week_from = $week_start->getTimestamp();
    $week_to = $week_start->modify('+7 days')->getTimestamp();

    $by_project = [];
    foreach ($entries as $entry) {
      $by_project[(int) $entry->get('project')->target_id][] = $entry;
    }

    // The projects to show, by name so rows do not jump around while editing.
    $storage = $this->entityTypeManager->getStorage('tally_project');
    $want = array_unique(array_merge(array_filter(array_keys($by_project)), $options['rows']));
    $projects = [];
    foreach ($storage->loadMultiple($want) as $project) {
      if ($project->getOwnerId() == $this->currentUser->id()) {
        $projects[] = $project;
      }
    }
    usort($projects, fn ($a, $b) => strnatcasecmp((string) $a->label(), (string) $b->label()));
    $shown = array_map(fn ($project) => (int) $project->id(), $projects);

    $days = [];
    for ($i = 0; $i < 7; $i++) {
      $day = $week_start->modify("+$i days");
      $days[] = [
        'date' => $day->format('Y-m-d'),
        'from' => $day->getTimestamp(),
        'to' => $week_start->modify('+' . ($i + 1) . ' days')->getTimestamp(),
        'dow' => strtoupper($this->format($day->getTimestamp(), 'D')),
        'num' => $this->format($day->getTimestamp(), 'j'),
        'long' => $this->format($day->getTimestamp(), 'l, F j'),
        'today' => $day == $today,
        'future' => $day > $today,
      ];
    }

    $rows = [];
    $day_seconds = array_fill(0, 7, 0);
    $day_base = array_fill(0, 7, 0);
    $day_live = array_fill(0, 7, NULL);
    $rows_spec = array_map(fn ($project) => [$project, (int) $project->id()], $projects);
    if (isset($by_project[0])) {
      $rows_spec[] = [NULL, 0];
    }
    foreach ($rows_spec as [$project, $pid]) {
      $row_entries = $by_project[$pid] ?? [];
      $cells = [];
      $row_total = 0;
      $row_base = 0;
      $row_live_start = NULL;
      $cents = 0;
      $rate = $project ? $project->getEffectiveRate() : 0;
      foreach ($row_entries as $entry) {
        if ($entry->get('billable')->value && $rate > 0) {
          $cents += Money::forSeconds($this->tracker->summarize([$entry], $week_from, $week_to)['seconds'], $rate);
        }
      }
      foreach ($days as $i => $day) {
        $summary = $this->tracker->summarize($row_entries, $day['from'], $day['to']);
        $key = $pid . '-' . $day['date'];
        $raw = $invalid[$key] ?? NULL;
        $row_total += $summary['seconds'];
        $row_base += $summary['base'];
        $day_seconds[$i] += $summary['seconds'];
        $day_base[$i] += $summary['base'];
        if ($summary['live_start'] !== NULL) {
          $row_live_start = $summary['live_start'];
          $day_live[$i] = $summary['live_start'];
        }
        $cells[] = [
          'id' => 'ts-cell-' . $key,
          'key' => $key,
          'project' => $pid,
          'date' => $day['date'],
          'today' => $day['today'],
          'live' => $summary['live_start'] !== NULL ? ['start' => $summary['live_start'], 'base' => $summary['base'], 'text' => Timesheet::format($summary['seconds'])] : NULL,
          'text' => $raw ?? Timesheet::format($summary['seconds']),
          'invalid' => $raw !== NULL,
          'label' => ($project ? $project->label() : $this->t('No project')) . ', ' . $day['long'],
        ];
      }
      $client = $project?->getClient();
      $rows[] = [
        'pid' => $pid,
        'name' => $project ? (string) $project->label() : (string) $this->t('No project'),
        'client' => $project ? ($client ? (string) $client->label() : (string) $this->t('No client')) . (!$project->isBillable() ? ' · ' . $this->t('not billable') : '') : '',
        'color' => $project ? $project->getColor() : NULL,
        'cells' => $cells,
        'total' => ['text' => Timesheet::format($row_total) ?: '-', 'live' => $row_live_start !== NULL ? ['start' => $row_live_start, 'base' => $row_base] : NULL],
        'amount' => !$project ? '-' : (!$project->isBillable() ? $this->t('Not billable') : ($rate > 0 ? Money::format($cents, $project->getClient()?->getCurrency() ?? 'CAD') : $this->t('No rate'))),
        'amount_muted' => !$project || !$project->isBillable() || $rate <= 0,
      ];
    }

    // Time with no project is only listed when it exists. Everything else
    // shown is a row above, so the day totals add up what is on screen.
    $day_totals = [];
    foreach ($days as $i => $day) {
      $day_totals[] = [
        'text' => Timesheet::format($day_seconds[$i]) ?: '-',
        'live' => $day_live[$i] !== NULL ? ['start' => $day_live[$i], 'base' => $day_base[$i]] : NULL,
        'today' => $day['today'],
      ];
    }

    // Projects that could still get a row, for the "Add project row" select.
    $all = $this->owner->query('tally_project')->condition('archived', 0)->sort('name')->execute();
    $addable = [];
    foreach ($storage->loadMultiple($all) as $project) {
      if (!in_array((int) $project->id(), $shown, TRUE)) {
        $addable[] = ['id' => (int) $project->id(), 'name' => (string) $project->label()];
      }
    }

    // Rows from last week that this week does not show yet.
    $last_from = $week_start->modify('-7 days')->getTimestamp();
    $last_entries = $this->tracker->getEntries($this->currentUser, $last_from, $week_from);
    $copyable = [];
    foreach ($last_entries as $entry) {
      $id = (int) $entry->get('project')->target_id;
      if ($id && !in_array($id, $shown, TRUE) && isset($this->loadProjects()[$id])) {
        $copyable[$id] = $id;
      }
    }

    $first_error = reset($invalid);
    return [
      'options' => $options,
      'date_value' => $options['day']->format('Y-m-d'),
      'zone' => $this->format($week_from, 'T'),
      'days' => $days,
      'rows' => $rows,
      'day_totals' => $day_totals,
      'week_total' => $totals[0]['value'],
      'week_amount' => $totals[2]['value']['text'],
      'addable' => $addable,
      'state_rows' => implode(',', array_unique(array_merge($options['rows'], $shown))),
      'copy_url' => $copyable ? $this->url($options, ['rows' => implode(',', array_unique(array_merge($options['rows'], array_values($copyable))))]) : NULL,
      'hint_error' => $first_error !== FALSE,
      'hint' => $first_error !== FALSE
        ? $this->t('Use hours and minutes (1:30), decimal hours (1.5) or minutes (45m).')
        : $this->t('Type hours as 1:30, 1.5 or 45m. Changes save when you leave a cell.'),
      'live' => (bool) array_filter($day_live),
    ];
  }

  /**
   * The List view: entries grouped by day, newest first, with the filters.
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *   Entries overlapping the week.
   */
  protected function listVariables(array $options, array $entries, int $from, int $to): array {
    $now = $this->time->getRequestTime();
    $projects = $this->loadProjects();
    $needle = mb_strtolower($options['q']);
    $filtered = $needle !== '' || $options['proj'] !== NULL || $options['bill'] !== 'all';

    $groups = [];
    $shown = 0;
    $shown_seconds = 0;
    $project_ids = [];
    foreach ($entries as $entry) {
      $start = (int) $entry->get('start')->value;
      // An entry belongs to the day it started on.
      if ($start < $from || $start >= $to) {
        continue;
      }
      $project = $entry->get('project')->entity;
      if ($project) {
        $project_ids[(int) $project->id()] = (string) $project->label();
      }
      $billable = (bool) $entry->get('billable')->value;
      if ($options['proj'] !== NULL && (string) $entry->get('project')->target_id !== $options['proj']) {
        continue;
      }
      if (($options['bill'] === 'bill' && !$billable) || ($options['bill'] === 'non' && $billable)) {
        continue;
      }
      $client = $project?->getClient();
      $title = (string) $entry->get('description')->value;
      $notes = (string) $entry->get('notes')->value;
      if ($needle !== '' && mb_strpos(mb_strtolower($title . ' ' . $project?->label() . ' ' . $client?->label() . ' ' . $notes), $needle) === FALSE) {
        continue;
      }

      $running = $entry->isRunning();
      $end = $running ? $now : (int) $entry->get('end')->value;
      $seconds = max(0, $end - $start);
      $rate = $project?->getEffectiveRate() ?? 0;
      $currency = $client?->getCurrency() ?? 'CAD';
      [$badge, $badge_kind] = match (TRUE) {
        !$project => [$this->t('No project'), 'none'],
        !$project->isBillable() => [$this->t('Internal'), 'none'],
        !$billable => [$this->t('Not billable'), 'none'],
        $rate <= 0 => [$this->t('No rate'), 'missing'],
        default => [Money::format(Money::forSeconds($seconds, $rate), $currency), 'custom'],
      };

      $day_key = $this->format($start, 'Y-m-d');
      $groups[$day_key]['start'] = $groups[$day_key]['start'] ?? $start;
      $groups[$day_key]['seconds'] = ($groups[$day_key]['seconds'] ?? 0) + $seconds;
      $shown++;
      $shown_seconds += $seconds;
      $groups[$day_key]['rows'][] = [
        'start' => $start,
        'id' => (int) $entry->id(),
        'title' => $title !== '' ? $title : $this->t('Untitled entry'),
        'project' => $project ? (string) $project->label() : (string) $this->t('No project'),
        'client' => $client ? (string) $client->label() : (string) $this->t('No client'),
        'color' => $project ? $project->getColor() : NULL,
        'notes' => $notes,
        'badge' => $badge,
        'badge_kind' => $badge_kind,
        'range' => $this->format($start, 'H:i') . ' – ' . ($running ? $this->t('now') : $this->format($end, 'H:i')),
        'duration' => Timesheet::format($seconds) ?: '0:00',
        'live_start' => $running ? $start : NULL,
        'running' => $running,
        'editing' => $options['edit'] === (string) $entry->id(),
        'url' => $this->url($options, ['edit' => (string) $entry->id()]),
        // Play and the right-click menu start a new timer with the same details.
        'resume_vals' => $running ? NULL : $this->resumeVals($options, $entry),
      ];
    }

    krsort($groups);
    $today_key = $this->today()->format('Y-m-d');
    $out = [];
    foreach ($groups as $key => $group) {
      usort($group['rows'], fn ($a, $b) => $b['start'] <=> $a['start']);
      $out[] = [
        'label' => $this->format($group['start'], 'l, F j'),
        'today' => $key === $today_key,
        'count' => $this->formatPlural(count($group['rows']), '1 entry', '@count entries'),
        'total' => TimeTracker::formatDuration($group['seconds']),
        'rows' => $group['rows'],
      ];
    }

    // The project filter lists every project that could match, not just the
    // ones with time in this range.
    $choices = [['value' => '', 'label' => $this->t('All projects'), 'selected' => $options['proj'] === NULL]];
    $names = [];
    foreach ($projects as $project) {
      $names[(int) $project['id']] = $project['name'];
    }
    $names += $project_ids;
    asort($names, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($names as $id => $name) {
      $choices[] = ['value' => (string) $id, 'label' => $name, 'selected' => $options['proj'] === (string) $id];
    }

    $range_label = $options['range'] === 'week' ? $this->t('this week') : $this->t('this day');
    return [
      'options' => $options,
      'date_value' => $options['day']->format('Y-m-d'),
      'groups' => $out,
      'empty' => !$out,
      'filtered' => $filtered,
      'project_choices' => $choices,
      'bill_filters' => array_map(fn ($key, $label) => [
        'label' => $label,
        'pressed' => $options['bill'] === $key,
        'url' => $this->url($options, ['bill' => $key === 'all' ? NULL : $key]),
      ], ['all', 'bill', 'non'], [$this->t('All'), $this->t('Billable'), $this->t('Not billable')]),
      'clear_url' => $this->url($options, ['q' => NULL, 'proj' => NULL, 'bill' => NULL]),
      'footer' => $this->formatPlural($shown, '1 entry', '@count entries') . ' · ' . TimeTracker::formatDuration($shown_seconds) . ($filtered ? ' ' . $this->t('shown (filtered)') : ' ' . $range_label),
      'state' => ['date' => $options['day']->format('Y-m-d'), 'view' => 'list', 'range' => $options['range'], 'rows' => implode(',', $options['rows']), 'bill' => $options['bill'] === 'all' ? '' : $options['bill']],
      'base_url' => Url::fromRoute('tally.time')->toString(),
    ];
  }

  /**
   * Puts blocks that overlap on screen side by side.
   *
   * Works on the drawn rectangles, so a very short entry that is stretched to
   * the minimum height still makes room for its neighbours. Blocks that touch
   * each other form a group sharing the same number of lanes; each block takes
   * the first lane that is free when it starts.
   *
   * @param array[] $blocks
   *   Blocks with 'top' and 'height'.
   *
   * @return array[]
   *   The same blocks in order of 'top', each with 'lane' and 'lanes'.
   */
  protected function assignLanes(array $blocks): array {
    usort($blocks, fn ($a, $b) => $a['top'] <=> $b['top']);
    $group = [];
    $lane_ends = [];
    $group_end = NULL;
    $result = [];
    $flush = function () use (&$group, &$lane_ends, &$result) {
      foreach ($group as $block) {
        $result[] = $block + ['lanes' => count($lane_ends)];
      }
      $group = [];
      $lane_ends = [];
    };
    foreach ($blocks as $block) {
      if ($group_end !== NULL && $block['top'] >= $group_end) {
        $flush();
        $group_end = NULL;
      }
      $lane = NULL;
      foreach ($lane_ends as $index => $end) {
        if ($block['top'] >= $end) {
          $lane = $index;
          break;
        }
      }
      $lane ??= count($lane_ends);
      $bottom = $block['top'] + $block['height'];
      $lane_ends[$lane] = $bottom;
      $group_end = max($group_end ?? $bottom, $bottom);
      $group[] = $block + ['lane' => $lane];
    }
    $flush();
    return $result;
  }

  /**
   * A duration with what the browser needs to keep it ticking.
   */
  protected function total(array $summary): array {
    return [
      'text' => TimeTracker::formatDuration($summary['seconds']),
      'base' => $summary['base'],
      'live_start' => $summary['live_start'],
    ];
  }

  /**
   * Choice links (segmented control): label, URL and pressed state.
   */
  protected function choices(array $options, string $key, array $labels): array {
    $choices = [];
    foreach ($labels as $value => $label) {
      $choices[] = [
        'label' => $label,
        'url' => $this->url($options, [$key => (string) $value]),
        'pressed' => $options[$key] === (string) $value,
        // The timesheet always shows a full week, so "Day" makes no sense there.
        'disabled' => $key === 'range' && $options['view'] === 'timesheet' && $value === 'day',
      ];
    }
    return $choices;
  }

  /**
   * The screen URL with some options changed. NULL drops an option.
   */
  protected function url(array $options, array $changes): string {
    // An open entry only belongs to the page it was opened on.
    $changes += ['edit' => NULL, 'new' => NULL];
    return Url::fromRoute('tally.time', [], ['query' => $this->query($options, $changes)])->toString();
  }

  /**
   * The non-default options as a query array.
   */
  protected function query(array $options, array $changes = []): array {
    $values = [
      'date' => $options['day']->format('Y-m-d'),
      'zoom' => $options['zoom'],
      'view' => $options['view'],
      'range' => $options['range'],
      'edit' => $options['edit'],
      'new' => $options['new'],
      'rows' => $options['rows'] ? implode(',', $options['rows']) : NULL,
      'q' => $options['q'] !== '' ? $options['q'] : NULL,
      'proj' => $options['proj'],
      'bill' => $options['bill'] !== 'all' ? $options['bill'] : NULL,
    ];
    $values = array_merge($values, $changes);
    $defaults = ['date' => $this->today()->format('Y-m-d'), 'zoom' => (string) $this->settings->get('zoom'), 'view' => 'calendar', 'range' => 'day', 'edit' => NULL, 'new' => NULL, 'rows' => NULL, 'q' => NULL, 'proj' => NULL, 'bill' => NULL];
    foreach ($values as $key => $value) {
      if ($value === NULL || $value === $defaults[$key]) {
        unset($values[$key]);
      }
    }
    return $values;
  }


  /**
   * Midnight today in the user's time zone.
   */
  protected function today(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($this->timeZone())
      ->setTime(0, 0);
  }

  protected function timeZone(): \DateTimeZone {
    return new \DateTimeZone($this->currentUser->getTimeZone());
  }

  /**
   * Formats a timestamp in the user's time zone.
   */
  protected function format(int $timestamp, string $pattern): string {
    return $this->dateFormatter->format($timestamp, 'custom', Dates::pattern($pattern), $this->currentUser->getTimeZone());
  }

}
