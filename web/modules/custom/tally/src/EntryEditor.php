<?php

namespace Drupal\tally;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tally\Entity\Project;
use Drupal\tally\Entity\TimeEntry;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Edit time entry" dialog: reads the form, checks it, saves it.
 *
 * Times are typed as a date plus clock times in the user's time zone. A
 * running entry only has a start; its end is "now".
 */
class EntryEditor {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected OwnerScope $owner,
  ) {}

  /**
   * Loads one of the user's time entries, or NULL.
   */
  public function load(?string $id): ?TimeEntry {
    $entry = $this->owner->load('tally_time_entry', $id);
    return $entry instanceof TimeEntry && $entry->access('update') ? $entry : NULL;
  }

  /**
   * The entry the dialog is about: the one being edited, or a new unsaved
   * one for the range dragged on the calendar. NULL when neither is valid.
   */
  public function forOptions(array $options): ?TimeEntry {
    if ($options['edit'] !== NULL || $options['new'] === NULL) {
      return $this->load($options['edit']);
    }
    [$from, $to] = explode('-', $options['new']);
    $start = $this->stamp($options['day'], $from);
    $end = $this->stamp($options['day'], $to);
    if ($start === NULL || $end === NULL || $end <= $start) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('tally_time_entry')->create([
      'uid' => $this->currentUser->id(),
      'description' => '',
      'billable' => TRUE,
      'start' => $start,
      'end' => $end,
    ]);
  }

  /**
   * The form's values: as stored, or as posted when the form was submitted.
   *
   * @return array{description: string, project: string, date: string, start: string, end: string, billable: bool, notes: string, ask_delete: bool}
   */
  public function draft(Request $request, TimeEntry $entry): array {
    $start = (int) $entry->get('start')->value;
    $draft = [
      'description' => (string) $entry->get('description')->value,
      'project' => (string) ($entry->get('project')->target_id ?? ''),
      'date' => $this->local($start, 'Y-m-d'),
      'start' => $this->local($start, 'H:i'),
      'end' => $entry->isRunning() ? '' : $this->local((int) $entry->get('end')->value, 'H:i'),
      'billable' => (bool) $entry->get('billable')->value,
      'notes' => (string) $entry->get('notes')->value,
      'ask_delete' => FALSE,
    ];
    $input = $request->request->all() + $request->query->all();
    if (isset($input['form'])) {
      $text = fn (string $key) => is_string($input[$key] ?? NULL) ? trim($input[$key]) : NULL;
      $draft['description'] = $text('description') ?? $draft['description'];
      $draft['project'] = $text('project') ?? $draft['project'];
      // The locked date of a running entry is not posted: keep the stored one.
      $draft['date'] = $text('entry_date') ?? $draft['date'];
      $draft['start'] = $text('start') ?? $draft['start'];
      $draft['end'] = $entry->isRunning() ? '' : ($text('end') ?? $draft['end']);
      $draft['billable'] = ($input['billable'] ?? '0') === '1';
      $draft['notes'] = is_string($input['notes'] ?? NULL) ? trim($input['notes']) : $draft['notes'];
      $draft['ask_delete'] = ($input['ask_delete'] ?? '0') === '1';
    }
    return $draft;
  }

  /**
   * Choosing another project offers that project's billable setting.
   */
  public function adjust(array $draft, bool $project_changed): array {
    if ($project_changed && ($project = $this->ownedProject($draft['project']))) {
      $draft['billable'] = $project->isBillable();
    }
    return $draft;
  }

  /**
   * Why the draft cannot be saved, or NULL when it can.
   *
   * @param \DateTimeImmutable $week_start
   *   Monday of the week on screen; the date has to be in it.
   */
  public function validate(array $draft, TimeEntry $entry, \DateTimeImmutable $week_start): ?string {
    $error = NULL;
    if ($draft['description'] === '') {
      $error = (string) $this->t('Add a description.');
    }
    $day = $this->parseDate($draft['date']);
    if (!$day || $day < $week_start || $day >= $week_start->modify('+7 days')) {
      $error = (string) $this->t('Pick a date in this week (@from – @to).', [
        '@from' => Dates::format($week_start, 'M j'),
        '@to' => Dates::lastDayOfRange($week_start->modify('+6 days')),
      ]);
    }
    $start = $day ? $this->stamp($day, $draft['start']) : NULL;
    $end = $day && !$entry->isRunning() ? $this->stamp($day, $draft['end']) : NULL;
    $now = $this->time->getRequestTime();
    if ($start === NULL) {
      $error = (string) $this->t('Enter a start time.');
    }
    elseif (!$entry->isRunning() && $end === NULL) {
      $error = (string) $this->t('Enter an end time.');
    }
    elseif ($entry->isRunning() && $start > $now) {
      $error = (string) $this->t('Start can’t be later than now (@time).', ['@time' => $this->local($now, 'H:i')]);
    }
    elseif (!$entry->isRunning() && $end <= $start) {
      $error = (string) $this->t('End time must be after the start time.');
    }
    return $error;
  }

  /**
   * Saves a valid draft onto the entry.
   *
   * A time the user did not change keeps its stored seconds, so saving a
   * note does not round the entry to the minute.
   *
   * @return string|null
   *   A message when the entry breaks one of its field constraints; nothing is
   *   saved then.
   */
  public function apply(TimeEntry $entry, array $draft): ?string {
    $day = $this->parseDate($draft['date']);
    $old_start = (int) $entry->get('start')->value;
    $start = $this->stamp($day, $draft['start']);
    if ($this->local($old_start, 'Y-m-d H:i') === $draft['date'] . ' ' . $draft['start']) {
      $start = $old_start;
    }
    $entry->set('start', $start);
    if (!$entry->isRunning()) {
      $old_end = (int) $entry->get('end')->value;
      $end = $this->stamp($day, $draft['end']);
      if ($this->local($old_end, 'Y-m-d H:i') === $draft['date'] . ' ' . $draft['end']) {
        $end = $old_end;
      }
      $entry->set('end', $end);
    }
    $entry->set('description', mb_substr($draft['description'], 0, 255));
    $entry->set('project', $this->ownedProject($draft['project'])?->id());
    $entry->set('billable', $draft['billable']);
    $entry->set('notes', $draft['notes']);
    // The entity's own constraints, behind the checks in validate().
    if ($message = EntityValidation::first($entry->validate())) {
      return $message;
    }
    $entry->save();
    return NULL;
  }

  /**
   * Moves or resizes a finished entry to the day and times given, from a drag
   * on the calendar. A time that did not change keeps its stored seconds.
   *
   * @param \DateTimeImmutable $week_start
   *   Monday of the week on screen; the day has to be in it.
   *
   * @return string|null
   *   Why nothing was saved, or NULL.
   */
  public function move(TimeEntry $entry, string $date, string $start, string $end, \DateTimeImmutable $week_start): ?string {
    if ($entry->isRunning()) {
      return (string) $this->t('A running timer can’t be moved.');
    }
    $day = $this->parseDate($date);
    if (!$day || $day < $week_start || $day >= $week_start->modify('+7 days')) {
      return (string) $this->t('That day is not in this week.');
    }
    $new_start = $this->stamp($day, $start);
    $new_end = $this->stamp($day, $end);
    if ($new_start === NULL || $new_end === NULL || $new_end <= $new_start) {
      return (string) $this->t('End time must be after the start time.');
    }
    $old_start = (int) $entry->get('start')->value;
    $old_end = (int) $entry->get('end')->value;
    $entry->set('start', $this->local($old_start, 'Y-m-d H:i') === $date . ' ' . $start ? $old_start : $new_start);
    $entry->set('end', $this->local($old_end, 'Y-m-d H:i') === $date . ' ' . $end ? $old_end : $new_end);
    if ($message = EntityValidation::first($entry->validate())) {
      return $message;
    }
    $entry->save();
    return NULL;
  }

  /**
   * A copy of a finished entry, placed right after it with the same length so
   * it can be dragged where it belongs. When that would run past midnight, the
   * copy takes the same slot instead and shows beside the original.
   */
  public function duplicate(TimeEntry $entry): ?TimeEntry {
    if ($entry->isRunning()) {
      return NULL;
    }
    $start = (int) $entry->get('start')->value;
    $end = (int) $entry->get('end')->value;
    $midnight = (new \DateTimeImmutable('@' . $start))->setTimezone($this->timeZone())->modify('midnight +1 day')->getTimestamp();
    $length = $end - $start;
    if ($end + $length >= $midnight - 60) {
      $copy_start = $start;
      $copy_end = $end;
    }
    else {
      $copy_start = $end;
      $copy_end = $end + $length;
    }
    $copy = $this->entityTypeManager->getStorage('tally_time_entry')->create([
      'uid' => $this->currentUser->id(),
      'description' => $entry->get('description')->value,
      'project' => $entry->get('project')->target_id,
      'billable' => (bool) $entry->get('billable')->value,
      'notes' => $entry->get('notes')->value,
      'start' => $copy_start,
      'end' => $copy_end,
    ]);
    $copy->save();
    return $copy;
  }

  /**
   * Everything the dialog template needs.
   *
   * @param array $options
   *   The Time screen options (for the week and the close URL).
   */
  public function panel(array $options, TimeEntry $entry, array $draft, ?string $error, string $close_url): array {
    $running = $entry->isRunning();
    $week_start = $options['day']->modify('monday this week');
    $day = $this->parseDate($draft['date']);
    $project = $this->ownedProject($draft['project']);
    $now = $this->time->getRequestTime();
    $start = $day ? $this->stamp($day, $draft['start']) : NULL;
    $end = $running ? $now : ($day ? $this->stamp($day, $draft['end']) : NULL);
    $seconds = $start !== NULL && $end !== NULL && $end > $start ? $end - $start : 0;

    $options_list = [['value' => '', 'label' => $this->t('No project'), 'selected' => !$project]];
    foreach ($this->projects($draft['project']) as $candidate) {
      $client = $candidate->getClient();
      $options_list[] = [
        'value' => (string) $candidate->id(),
        'label' => $candidate->label() . ($client ? ' - ' . $client->label() : ''),
        'selected' => $project && $candidate->id() == $project->id(),
      ];
    }

    $rate = $project?->getEffectiveRate() ?? 0;
    $currency = $project?->getClient()?->getCurrency() ?? 'CAD';
    if (!$project) {
      $rate_line = $this->t('No project, so no rate and nothing to invoice.');
    }
    elseif (!$project->isBillable()) {
      $rate_line = $this->t('Not billable project: no rate, never invoiced.');
    }
    elseif ($rate <= 0) {
      $rate_line = $this->t('This project has no rate yet. Set one in Projects.');
    }
    elseif ($draft['billable']) {
      $rate_line = Money::rate($rate, $currency) . ' · ' . $this->t('@amount for this entry', ['@amount' => Money::format(Money::forSeconds($seconds, $rate), $currency)]);
    }
    else {
      $rate_line = $this->t('Won’t be invoiced · project rate @rate', ['@rate' => Money::rate($rate, $currency)]);
    }

    return [
      'id' => (string) $entry->id(),
      'is_new' => $entry->isNew(),
      'running' => $running,
      'day_label' => $day ? $this->local($day->getTimestamp(), 'l, F j') : $this->t('Date'),
      'clock' => $running ? [
        'start' => (int) $entry->get('start')->value,
        'text' => TimeTracker::formatClock($now - (int) $entry->get('start')->value),
      ] : NULL,
      'draft' => $draft,
      'projects' => $options_list,
      'color' => $project ? $project->getColor() : NULL,
      'date_min' => $week_start->format('Y-m-d'),
      'date_max' => $week_start->modify('+6 days')->format('Y-m-d'),
      'end_label' => $running ? $this->t('Running') : '',
      'error' => $error,
      'summary_label' => $error ?? $this->t('Duration'),
      'duration' => $error ? '-' : TimeTracker::formatDuration((int) (round($seconds / 60) * 60)),
      'rate_line' => $rate_line,
      'delete_verb' => $running ? $this->t('Discard timer') : $this->t('Delete entry'),
      'delete_question' => $running ? $this->t('Discard this running timer?') : $this->t('Delete this entry?'),
      'close_url' => $close_url,
      // What the screen needs to redraw itself after a save or a stop.
      'state' => [
        'date' => $options['day']->format('Y-m-d'),
        'zoom' => $options['zoom'],
        'view' => $options['view'],
        'range' => $options['range'],
        'rows' => implode(',', $options['rows']),
        'new' => $options['new'] ?? '',
      ],
    ];
  }

  /**
   * The user's projects that are not archived, plus the one already chosen.
   *
   * @return \Drupal\tally\Entity\Project[]
   */
  protected function projects(string $keep): array {
    $storage = $this->entityTypeManager->getStorage('tally_project');
    $ids = $this->owner->query('tally_project')->sort('name')->execute();
    return array_filter($storage->loadMultiple($ids), fn (Project $project) => !$project->isArchived() || (string) $project->id() === $keep);
  }

  protected function ownedProject(string $id): ?Project {
    $project = $this->owner->load('tally_project', $id);
    return $project instanceof Project ? $project : NULL;
  }

  protected function timeZone(): \DateTimeZone {
    return new \DateTimeZone($this->currentUser->getTimeZone());
  }

  protected function local(int $timestamp, string $pattern): string {
    return Dates::format((new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timeZone()), $pattern);
  }

  protected function parseDate(string $date): \DateTimeImmutable|false {
    return Dates::parse($date, $this->timeZone()) ?? FALSE;
  }

  /**
   * A day plus "HH:MM" as a timestamp, or NULL when it is not a time.
   */
  protected function stamp(\DateTimeImmutable $day, string $clock): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
      return NULL;
    }
    return $day->setTime((int) $m[1], (int) $m[2])->getTimestamp();
  }


}
