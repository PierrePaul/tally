<?php

namespace Drupal\tally;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\tally\Entity\TimeEntry;

/**
 * Timer rules and time totals for the signed-in user.
 */
class TimeTracker {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected OwnerScope $owner,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * The user's running entry, if any.
   */
  public function getRunning(AccountInterface $account): ?TimeEntry {
    $storage = $this->entityTypeManager->getStorage('tally_time_entry');
    $ids = $this->owner->query('tally_time_entry', (int) $account->id())
      ->notExists('end')
      ->range(0, 1)
      ->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * The user's most recently stopped entry, if any.
   */
  public function getLatestStopped(AccountInterface $account): ?TimeEntry {
    $storage = $this->entityTypeManager->getStorage('tally_time_entry');
    $ids = $this->owner->query('tally_time_entry', (int) $account->id())
      ->exists('end')
      ->sort('end', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Starts a new running entry. Any running entry is stopped first.
   */
  public function start(AccountInterface $account, string $description, ?int $project_id, bool $billable, string $notes = ''): TimeEntry {
    // Two tabs starting a timer at once must not both find nothing running and
    // each create one: the second waits here until the first has saved.
    $name = 'tally_timer_' . $account->id();
    if (!$this->lock->acquire($name, 10)) {
      $this->lock->wait($name, 5);
      if (!$this->lock->acquire($name, 10)) {
        throw new \RuntimeException('Another timer is being started. Try again.');
      }
    }
    try {
      if ($running = $this->getRunning($account)) {
        $this->stop($running);
      }
      $entry = $this->entityTypeManager->getStorage('tally_time_entry')->create([
        'uid' => $account->id(),
        'description' => $description,
        'project' => $project_id,
        'billable' => $billable,
        'notes' => $notes,
        'start' => $this->time->getRequestTime(),
      ]);
      $entry->save();
    }
    finally {
      $this->lock->release($name);
    }
    return $entry;
  }

  /**
   * Stops a running entry now.
   */
  public function stop(TimeEntry $entry): void {
    $start = (int) $entry->get('start')->value;
    $entry->set('end', max($this->time->getRequestTime(), $start))->save();
  }

  /**
   * Entries that overlap [$from, $to), oldest first.
   *
   * @return \Drupal\tally\Entity\TimeEntry[]
   */
  public function getEntries(AccountInterface $account, int $from, int $to): array {
    $storage = $this->entityTypeManager->getStorage('tally_time_entry');
    $query = $this->owner->query('tally_time_entry', (int) $account->id())
      ->condition('start', $to, '<');
    $open_or_later = $query->orConditionGroup()
      ->notExists('end')
      ->condition('end', $from, '>');
    $ids = $query->condition($open_or_later)->sort('start')->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Totals of entries clipped to [$from, $to).
   *
   * A running entry counts up to now. The result splits that running part
   * out, so the browser can keep it ticking without asking the server:
   * 'base' is the total without it and 'live_start' is when it began counting
   * (NULL when nothing is ticking in this window).
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *
   * @return array{seconds: int, base: int, live_start: ?int}
   */
  public function summarize(array $entries, int $from, int $to, bool $billable_only = FALSE): array {
    $now = $this->time->getRequestTime();
    $base = 0;
    $live = 0;
    $live_start = NULL;
    foreach ($entries as $entry) {
      if ($billable_only && !$entry->get('billable')->value) {
        continue;
      }
      $start = max((int) $entry->get('start')->value, $from);
      if ($entry->isRunning()) {
        // Nothing counts in a window that starts after now or after the
        // entry's start has been passed over.
        if ($start >= $to || $start > $now) {
          continue;
        }
        if ($now >= $to) {
          // The window is over: the entry's part of it is fixed, not ticking.
          $base += $to - $start;
          continue;
        }
        $live_start = $start;
        $live = max(0, $now - $start);
      }
      else {
        $base += max(0, min((int) $entry->get('end')->value, $to) - $start);
      }
    }
    return ['seconds' => $base + $live, 'base' => $base, 'live_start' => $live_start];
  }

  /**
   * The entries whose project counts toward the daily and weekly totals.
   *
   * @param \Drupal\tally\Entity\TimeEntry[] $entries
   *
   * @return \Drupal\tally\Entity\TimeEntry[]
   */
  public function counted(array $entries): array {
    return array_values(array_filter($entries, fn ($entry) => !$entry->get('project')->entity?->isExcludedFromTotals()));
  }

  /**
   * Formats seconds like the design: "1h 05m", "12m".
   */
  public static function formatDuration(int $seconds): string {
    $minutes = intdiv(max(0, $seconds), 60);
    return $minutes >= 60 ? intdiv($minutes, 60) . 'h ' . sprintf('%02d', $minutes % 60) . 'm' : $minutes . 'm';
  }

  /**
   * Formats seconds as a clock, "HH:MM:SS".
   */
  public static function formatClock(int $seconds): string {
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
  }

}
