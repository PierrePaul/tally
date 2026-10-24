<?php

namespace Drupal\tally;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tally\Entity\Project;

/**
 * Hours tracked on one project: totals, a chart by period, and the bank of hours.
 */
class ProjectReport {

  use StringTranslationTrait;

  /**
   * Period key => label shown on the segmented control.
   */
  public const PERIODS = ['week' => 'Week', 'last' => 'Last week', 'month' => 'Month', 'year' => 'Year', 'custom' => 'Custom'];

  public function __construct(
    protected TimeTracker $tracker,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected OwnerScope $owner,
  ) {}

  /**
   * Builds the report of one project.
   *
   * @param string[] $kinds
   *   Which kinds of hours to include: 'billable' and/or 'non'.
   */
  public function build(Project $project, string $period, ?string $from, ?string $to, array $kinds): array {
    return $this->buildFor([$project], (string) $project->label(), $period, $from, $to, $kinds);
  }

  /**
   * Builds the report of several projects together (a client's).
   *
   * With more than one project the report also carries a per-project
   * breakdown.
   *
   * @param \Drupal\tally\Entity\Project[] $projects
   * @param string[] $kinds
   */
  public function buildFor(array $projects, string $subject, string $period, ?string $from, ?string $to, array $kinds, string $currency = 'CAD'): array {
    $range = $this->range($period, $from, $to);
    $wanted = array_flip($kinds);

    $loaded = [];
    foreach ($projects as $project) {
      $loaded[] = ['project' => $project, 'entries' => $this->loadEntries($project, $range['start'], $range['end']), 'billable' => $project->isBillable(), 'rate' => $project->getEffectiveRate()];
    }

    $totals = ['billable' => 0, 'non' => 0];
    $bars = [];
    $max = 0;
    foreach ($range['bins'] as $bin) {
      $seconds = ['billable' => 0, 'non' => 0];
      foreach ($loaded as $item) {
        $all = $this->tracker->summarize($item['entries'], $bin['from'], $bin['to'])['seconds'];
        $billable = $item['billable'] ? $this->tracker->summarize($item['entries'], $bin['from'], $bin['to'], TRUE)['seconds'] : 0;
        $seconds['billable'] += $billable;
        $seconds['non'] += $all - $billable;
      }
      $totals['billable'] += $seconds['billable'];
      $totals['non'] += $seconds['non'];
      $shown = array_sum(array_intersect_key($seconds, $wanted));
      $max = max($max, $shown);
      $bars[] = ['bin' => $bin, 'seconds' => $seconds];
    }

    foreach ($bars as &$bar) {
      $segments = [];
      // Non-billable stacks on top of billable, like the design.
      foreach (['non', 'billable'] as $kind) {
        if (isset($wanted[$kind]) && $bar['seconds'][$kind] > 0) {
          $segments[] = ['kind' => $kind, 'percent' => round($bar['seconds'][$kind] / $max * 100, 2)];
        }
      }
      $bar = [
        'label' => $bar['bin']['label'],
        'future' => $bar['bin']['future'],
        'title' => $bar['bin']['title'] . ' · ' . ($bar['bin']['future'] ? $this->t('upcoming') : $this->describe($bar['seconds'], $kinds)),
        'segments' => $segments,
      ];
    }
    unset($bar);

    // Per project: hours shown and the amount they come to.
    $rows = [];
    $amount_cents = 0;
    $rated = FALSE;
    foreach ($loaded as $item) {
      $all = $this->tracker->summarize($item['entries'], $range['start']->getTimestamp(), $range['end']->getTimestamp())['seconds'];
      $billable = $item['billable'] ? $this->tracker->summarize($item['entries'], $range['start']->getTimestamp(), $range['end']->getTimestamp(), TRUE)['seconds'] : 0;
      $seconds = ['billable' => $billable, 'non' => $all - $billable];
      $shown_seconds = array_sum(array_intersect_key($seconds, $wanted));
      $billed_seconds = isset($wanted['billable']) ? $billable : 0;
      $cents = $item['rate'] > 0 ? Money::forSeconds($billed_seconds, $item['rate']) : 0;
      $amount_cents += $cents;
      $rated = $rated || $item['rate'] > 0;
      $rows[] = [
        'name' => (string) $item['project']->label(),
        'color' => $item['project']->getColor(),
        'seconds' => $shown_seconds,
        'hours' => static::formatHours($shown_seconds),
        'amount' => $item['rate'] > 0 ? Money::format($cents, $currency) : ($item['billable'] ? $this->t('No rate') : $this->t('Not billable')),
      ];
    }
    usort($rows, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

    $shown_total = array_sum(array_intersect_key($totals, $wanted));
    $all_total = $totals['billable'] + $totals['non'];
    foreach ($rows as &$row) {
      $row['percent'] = $shown_total ? round($row['seconds'] / $shown_total * 100, 1) : 0;
    }
    unset($row);

    if (count($projects) === 1) {
      $project = $projects[0];
      $rate = $project->getEffectiveRate();
      $client = $project->getClient();
      if ($rate > 0) {
        $amount = Money::format($amount_cents, $currency);
        $amount_note = $project->getRateMode() === 'client' && $client
          ? $this->t('at @rate from @client', ['@rate' => Money::rate($rate, $currency), '@client' => $client->label()])
          : $this->t('at @rate', ['@rate' => Money::rate($rate, $currency)]);
        $has_amount = TRUE;
      }
      elseif (!$project->isBillable()) {
        [$amount, $amount_note, $has_amount] = [$this->t('Not billable'), $this->t('This project is not billed'), FALSE];
      }
      else {
        [$amount, $amount_note, $has_amount] = [$this->t('No rate set'), $this->t('Set a rate to see an amount'), FALSE];
      }
    }
    elseif ($rated) {
      $amount = Money::format($amount_cents, $currency);
      $amount_note = (string) $this->formatPlural(count($projects), 'across 1 project', 'across @count projects');
      $has_amount = TRUE;
    }
    else {
      [$amount, $amount_note, $has_amount] = [$this->t('No rate set'), $this->t('Set a rate to see an amount'), FALSE];
    }

    $labels = ['billable' => $this->t('Billable'), 'non' => $this->t('Non-billable')];
    $kind_list = [];
    foreach ($labels as $key => $label) {
      $kind_list[] = ['key' => $key, 'label' => $label, 'hours' => static::formatHours($totals[$key]), 'on' => isset($wanted[$key])];
    }

    return [
      'period' => $period,
      'range_label' => $range['label'],
      'chart_title' => $range['chart_title'],
      'kinds' => $kind_list,
      'total' => static::formatHours($shown_total),
      'total_note' => $shown_total === $all_total ? $this->t('All hours in this period') : $this->t('of @all tracked', ['@all' => static::formatHours($all_total)]),
      'amount' => $amount,
      'amount_note' => $amount_note,
      'has_amount' => $has_amount,
      'peak' => static::formatHours($max),
      'chart_aria' => $this->t('@title for @subject, @range: @hours total', ['@title' => $range['chart_title'], '@subject' => $subject, '@range' => $range['label'], '@hours' => static::formatHours($shown_total)]),
      'bars' => $bars,
      'breakdown' => count($projects) > 1 && $shown_total > 0 ? $rows : [],
      'empty' => $shown_total === 0,
      'empty_text' => $kinds ? $this->t('No matching time in this period.') : $this->t('Pick at least one type of hours to include.'),
      'custom_from' => $range['start']->format('Y-m-d'),
      'custom_to' => $range['end']->modify('-1 day')->format('Y-m-d'),
    ];
  }

  /**
   * The bank of hours of a project, or NULL when no invoice buys any.
   *
   * A bank is worked out from the project's "Bank of hours" invoice items:
   * hours on paid invoices are paid, on issued ones awaiting payment, on
   * drafts still in draft. Hours used are everything tracked on the project
   * so far.
   */
  public function bank(Project $project): ?array {
    $storage = $this->entityTypeManager->getStorage('tally_invoice_item');
    $ids = $this->owner->query('tally_invoice_item')->condition('kind', 'bank')->condition('project', $project->id())->execute();
    if (!$ids) {
      return NULL;
    }
    $seconds = ['paid' => 0, 'issued' => 0, 'draft' => 0];
    $pending_numbers = [];
    foreach ($storage->loadMultiple($ids) as $item) {
      $invoice = $item->get('invoice')->entity;
      if (!$invoice) {
        continue;
      }
      $status = $invoice->getStatus();
      if (!isset($seconds[$status])) {
        // A cancelled invoice never adds hours.
        continue;
      }
      $seconds[$status] += (int) round((float) $item->get('hours')->value * 3600);
      if ($status === 'issued') {
        $pending_numbers[(string) $invoice->label()] = TRUE;
      }
    }
    $paid = $seconds['paid'];
    $entries = $this->loadEntries($project);
    $used = $this->tracker->summarize($entries, 0, PHP_INT_MAX)['seconds'];
    $left = $paid - $used;
    $over = $left < 0;
    $low = !$over && $paid > 0 && $left / $paid < 0.2;
    $state = $over ? 'over' : ($low ? 'low' : 'ok');
    return [
      'state' => $state,
      'over' => $over,
      'paid_seconds' => $paid,
      'used_seconds' => $used,
      'left' => static::formatBank($left),
      'left_note' => $over ? $this->t('over the paid hours') : ($low ? $this->t('left, running low') : $this->t('left')),
      'short' => $over ? static::formatBank($left) : $this->t('@hours left', ['@hours' => static::formatBank($left)]),
      'percent' => $paid > 0 ? round(min(100, $used / $paid * 100), 1) : ($used > 0 ? 100 : 0),
      'meta' => $this->t('@paid paid · @used used', ['@paid' => static::formatBank($paid), '@used' => static::formatBank($used)]),
      'extra' => implode(' · ', array_filter([
        $seconds['issued'] > 0 ? $this->t('+@hours awaiting payment (@numbers)', ['@hours' => static::formatBank($seconds['issued']), '@numbers' => implode(', ', array_keys($pending_numbers))]) : '',
        $seconds['draft'] > 0 ? $this->t('+@hours in draft', ['@hours' => static::formatBank($seconds['draft'])]) : '',
      ])),
      'aria' => $this->t('@used used of @paid paid', ['@used' => static::formatBank($used), '@paid' => static::formatBank($paid)]),
    ];
  }

  /**
   * A date for display, with day and month names in the interface language.
   */
  protected function localFormat(\DateTimeImmutable $date, string $pattern): string {
    return $this->dateFormatter->format($date->getTimestamp(), 'custom', Dates::pattern($pattern), $date->getTimezone()->getName());
  }

  /**
   * "3h 00m" (report figures).
   */
  public static function formatHours(int $seconds): string {
    $minutes = (int) round($seconds / 60);
    return $minutes === 0 ? '0h' : intdiv($minutes, 60) . 'h ' . sprintf('%02d', $minutes % 60) . 'm';
  }

  /**
   * "40h", "8h 30m", "−1h 05m" (bank figures).
   */
  public static function formatBank(int $seconds): string {
    $minutes = (int) round(abs($seconds) / 60);
    $sign = $seconds < 0 && $minutes > 0 ? '−' : '';
    if ($minutes === 0) {
      return '0h';
    }
    return $sign . intdiv($minutes, 60) . 'h' . ($minutes % 60 ? ' ' . sprintf('%02d', $minutes % 60) . 'm' : '');
  }

  /**
   * Time entries of the project overlapping [$start, $end), or all of them.
   *
   * @return \Drupal\tally\Entity\TimeEntry[]
   */
  protected function loadEntries(Project $project, ?\DateTimeImmutable $start = NULL, ?\DateTimeImmutable $end = NULL): array {
    $storage = $this->entityTypeManager->getStorage('tally_time_entry');
    $query = $this->owner->query('tally_time_entry')
      ->condition('project', $project->id());
    if ($start && $end) {
      $query->condition('start', $end->getTimestamp(), '<');
      $query->condition($query->orConditionGroup()->notExists('end')->condition('end', $start->getTimestamp(), '>'));
    }
    return $storage->loadMultiple($query->sort('start')->execute());
  }

  /**
   * "Billable 3h 00m · Non-billable 1h 15m" for a chart tooltip.
   */
  protected function describe(array $seconds, array $kinds): string {
    $labels = ['billable' => $this->t('Billable'), 'non' => $this->t('Non-billable')];
    $parts = [];
    foreach ($labels as $key => $label) {
      if (in_array($key, $kinds, TRUE) && $seconds[$key] > 0) {
        $parts[] = $label . ' ' . static::formatHours($seconds[$key]);
      }
    }
    return $parts ? implode(' · ', $parts) : '0h';
  }

  /**
   * Resolves a period into its start, end (exclusive), labels and chart bins.
   */
  protected function range(string $period, ?string $from, ?string $to): array {
    $tz = new \DateTimeZone($this->currentUser->getTimeZone());
    $now = $this->time->getRequestTime();
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $day = fn (\DateTimeImmutable $d, string $pattern) => $this->localFormat($d, $pattern);

    if ($period === 'week' || $period === 'last') {
      $start = $today->modify('monday this week');
      if ($period === 'last') {
        $start = $start->modify('-7 days');
      }
      $end = $start->modify('+7 days');
      $last = $end->modify('-1 day');
      $span = $day($start, 'D M j') . ' – ' . $day($last, 'D M j, Y');
      return $this->withBins($start, $end, $period === 'week' ? $this->t('This week') . ' · ' . $span : $span, $this->t('Hours per day'), 'day', $now);
    }
    if ($period === 'month') {
      $start = $today->modify('first day of this month');
      $end = $start->modify('+1 month');
      return $this->withBins($start, $end, $day($start, 'F Y') . ' · ' . $this->t('to date'), $this->t('Hours per day'), 'day', $now, [1, 8, 15, 22, 29]);
    }
    if ($period === 'year') {
      $start = $today->setDate((int) $today->format('Y'), 1, 1);
      $end = $start->modify('+1 year');
      return $this->withBins($start, $end, $day($start, 'Y') . ' · ' . $this->t('to date'), $this->t('Hours per month'), 'month', $now);
    }

    // Custom: dates from the form, a month to date when missing or broken.
    $parse = fn (?string $value) => Dates::parse($value, $tz) ?? FALSE;
    $a = $parse($from) ?: $today->modify('first day of this month');
    $b = $parse($to) ?: $today;
    if ($b < $a) {
      [$a, $b] = [$b, $a];
    }
    $end = $b->modify('+1 day');
    $count = (int) $a->diff($end)->days;
    $unit = $count <= 31 ? 'day' : ($count <= 182 ? 'week' : 'month');
    $label = $day($a, 'M j, Y') . ' – ' . $day($b, 'M j, Y') . ' · ' . $this->formatPlural($count, '1 day', '@count days');
    $titles = ['day' => $this->t('Hours per day'), 'week' => $this->t('Hours per week'), 'month' => $this->t('Hours per month')];
    return $this->withBins($a, $end, $label, $titles[$unit], $unit, $now);
  }

  /**
   * Adds chart bins (one per day, week or month) to a resolved range.
   *
   * @param int[]|null $label_days
   *   Days of the month whose bars get a label; null labels by bin count.
   */
  protected function withBins(\DateTimeImmutable $start, \DateTimeImmutable $end, string $label, string $chart_title, string $unit, int $now, ?array $label_days = NULL): array {
    $cursor = $start;
    $step = ['day' => '+1 day', 'week' => '+7 days', 'month' => '+1 month'][$unit];
    $slices = [];
    while ($cursor < $end) {
      $next = $unit === 'month' ? $cursor->modify('first day of next month') : $cursor->modify($step);
      $slices[] = [$cursor, min($next, $end)];
      $cursor = $next;
    }
    // Keep the labels readable: at most about 8 on the axis.
    $skip = max(1, (int) ceil(count($slices) / 8));
    if ($unit === 'day' && count($slices) <= 7) {
      $skip = 1;
    }
    $bins = [];
    foreach ($slices as $i => [$from, $to]) {
      $last = $to->modify('-1 day');
      $title = match ($unit) {
        'day' => $this->localFormat($from, 'D M j'),
        'week' => $this->localFormat($from, 'M j') . ' – ' . $this->localFormat($last, 'M j'),
        'month' => $this->localFormat($from, 'F Y'),
      };
      if ($label_days !== NULL) {
        $text = in_array((int) $from->format('j'), $label_days, TRUE) ? $from->format('j') : '';
      }
      else {
        $text = $i % $skip === 0 ? match ($unit) {
          'day' => count($slices) <= 7 ? $this->localFormat($from, 'D') : $from->format('j'),
          'week' => $this->localFormat($from, 'M j'),
          'month' => $this->localFormat($from, 'M'),
        } : '';
      }
      $bins[] = ['from' => $from->getTimestamp(), 'to' => $to->getTimestamp(), 'label' => $text, 'title' => $title, 'future' => $from->getTimestamp() > $now];
    }
    return ['start' => $start, 'end' => $end, 'label' => $label, 'chart_title' => $chart_title, 'bins' => $bins];
  }

}
