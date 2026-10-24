<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tally\Entity\Project;
use Drupal\tally\Entity\TimeEntry;

/**
 * Turns an hours-per-day figure typed in the timesheet into time entries.
 *
 * A timesheet cell is the total of one project on one day, while time is
 * stored as entries with a start and an end. Setting a cell therefore works
 * on the difference:
 * - more time adds one entry for the difference, placed after the last entry
 *   of the day (or at 09:00 when the day is empty);
 * - less time shortens that project's latest entries first, and removes an
 *   entry that ends up empty.
 * Entries that already fit are left alone.
 */
class Timesheet {

  use StringTranslationTrait;

  /**
   * Where a day with no entries starts adding time.
   */
  protected const DEFAULT_START_HOUR = 9;

  /**
   * Differences under this many seconds are rounding, not an edit.
   */
  protected const TOLERANCE = 30;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Parses "1:30", "1.5", "1,5" or "45m" into seconds.
   *
   * @return int|null
   *   Seconds (0 for an empty cell), or NULL when it is not a duration or is
   *   longer than a day.
   */
  public static function parse(string $text): ?int {
    $text = str_replace(',', '.', trim($text));
    if ($text === '') {
      return 0;
    }
    if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $text, $m)) {
      $seconds = ((int) $m[1] * 60 + (int) $m[2]) * 60;
    }
    elseif (preg_match('/^\d{1,2}(\.\d+)?$/', $text)) {
      $seconds = (int) round((float) $text * 3600);
    }
    elseif (preg_match('/^(\d{1,4})m$/', $text, $m)) {
      $seconds = (int) $m[1] * 60;
    }
    else {
      return NULL;
    }
    return $seconds <= 86400 ? $seconds : NULL;
  }

  /**
   * "4:30" for a duration in seconds; empty for under half a minute.
   */
  public static function format(int $seconds): string {
    $minutes = (int) round($seconds / 60);
    return $minutes === 0 ? '' : intdiv($minutes, 60) . ':' . sprintf('%02d', $minutes % 60);
  }

  /**
   * Sets a project's total for one day.
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *   The user's entries overlapping the day.
   */
  public function setDayTotal(AccountInterface $account, ?Project $project, \DateTimeImmutable $day, int $target, array $entries): void {
    $from = $day->getTimestamp();
    $to = $day->modify('+1 day')->getTimestamp();
    $project_id = $project ? (int) $project->id() : 0;

    $mine = [];
    $last_end = $day->setTime(static::DEFAULT_START_HOUR, 0)->getTimestamp();
    foreach ($entries as $entry) {
      if ($entry->isRunning()) {
        continue;
      }
      $start = (int) $entry->get('start')->value;
      $end = (int) $entry->get('end')->value;
      $last_end = max($last_end, min($end, $to));
      if ((int) $entry->get('project')->target_id === $project_id) {
        $mine[] = $entry;
      }
    }
    $current = 0;
    foreach ($mine as $entry) {
      $current += max(0, min((int) $entry->get('end')->value, $to) - max((int) $entry->get('start')->value, $from));
    }

    $delta = $target - $current;
    if (abs($delta) < static::TOLERANCE) {
      return;
    }
    if ($delta > 0) {
      $start = min($last_end, $to - $delta);
      $entry = $this->entityTypeManager->getStorage('tally_time_entry')->create([
        'uid' => $account->id(),
        'description' => (string) $this->t('Timesheet'),
        'project' => $project_id ?: NULL,
        'start' => max($from, $start),
        'end' => max($from, $start) + $delta,
        'billable' => $project ? $project->isBillable() : FALSE,
      ]);
      $entry->save();
      return;
    }

    // Trim from the latest entry backwards. An entry that crosses midnight is
    // left alone: cutting it would also change the neighbouring day.
    $remove = -$delta;
    usort($mine, fn (TimeEntry $a, TimeEntry $b) => (int) $b->get('end')->value <=> (int) $a->get('end')->value);
    foreach ($mine as $entry) {
      $start = (int) $entry->get('start')->value;
      $end = (int) $entry->get('end')->value;
      if ($start < $from || $end > $to) {
        continue;
      }
      $length = $end - $start;
      if ($remove >= $length) {
        $entry->delete();
        $remove -= $length;
      }
      else {
        $entry->set('end', $end - $remove)->save();
        $remove = 0;
      }
      if ($remove <= 0) {
        break;
      }
    }
  }

}
