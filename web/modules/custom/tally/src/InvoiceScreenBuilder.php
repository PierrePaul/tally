<?php

namespace Drupal\tally;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\tally\Entity\Invoice;
use Drupal\tally\Entity\InvoiceItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the render arrays for the Invoices screen and its editor.
 *
 * The open invoice lives in the URL (?invoice=18), like the dialogs of
 * Projects and Clients.
 */
class InvoiceScreenBuilder {

  use StringTranslationTrait;

  /**
   * Status filter key => label.
   */
  protected const FILTERS = ['all' => 'All', 'draft' => 'Drafts', 'issued' => 'Awaiting payment', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

  public function __construct(
    protected InvoiceManager $manager,
    protected ProjectReport $report,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Reads what the URL says: the status filter, the issue date range and the
   * open invoice.
   *
   * @return array{filter: string, from: ?string, to: ?string, invoice: ?string}
   */
  public function options(Request $request): array {
    $filter = (string) ($request->query->get('filter') ?? $request->request->get('filter'));
    $invoice = (string) ($request->query->get('invoice') ?? $request->request->get('invoice'));
    $date = function (string $key) use ($request): ?string {
      $value = (string) ($request->query->get($key) ?? $request->request->get($key));
      return Dates::parse($value) ? $value : NULL;
    };
    $from = $date('from');
    $to = $date('to');
    // A backwards range would match nothing: put the dates the right way round.
    if ($from !== NULL && $to !== NULL && $from > $to) {
      [$from, $to] = [$to, $from];
    }
    return [
      'filter' => isset(static::FILTERS[$filter]) ? $filter : 'all',
      'from' => $from,
      'to' => $to,
      'invoice' => ctype_digit($invoice) && $invoice !== '' ? $invoice : NULL,
    ];
  }

  /**
   * The screen URL with some options changed. NULL drops an option.
   */
  public function url(array $options, array $changes = []): string {
    $values = array_merge($options, $changes);
    $query = [];
    if ($values['filter'] !== 'all') {
      $query['filter'] = $values['filter'];
    }
    foreach (['from', 'to'] as $key) {
      if ($values[$key] !== NULL) {
        $query[$key] = $values[$key];
      }
    }
    if ($values['invoice'] !== NULL) {
      $query['invoice'] = $values['invoice'];
    }
    return Url::fromRoute('tally.invoices', [], ['query' => $query])->toString();
  }

  /**
   * The third card's label: the year, or the chosen range.
   */
  protected function invoicedLabel(array $options, string $year): string {
    $from = $options['from'];
    $to = $options['to'];
    return (string) match (TRUE) {
      $from === NULL && $to === NULL => $this->t('Invoiced in @year', ['@year' => $year]),
      $from !== NULL && $to !== NULL => $this->t('Invoiced @from to @to', ['@from' => $this->dateLabel($from), '@to' => $this->dateLabel($to)]),
      $from !== NULL => $this->t('Invoiced since @date', ['@date' => $this->dateLabel($from)]),
      default => $this->t('Invoiced until @date', ['@date' => $this->dateLabel($to)]),
    };
  }

  /**
   * Quick ranges: this month, this year and last year.
   *
   * @return array<int, array{label: mixed, url: string, pressed: bool}>
   */
  protected function presets(array $options, string $today): array {
    $day = new \DateTimeImmutable($today);
    $year = (int) $day->format('Y');
    $ranges = [
      [$this->t('This month'), $day->format('Y-m-01'), $day->format('Y-m-t')],
      [$this->t('This year'), $year . '-01-01', $year . '-12-31'],
      [$this->t('Last year'), ($year - 1) . '-01-01', ($year - 1) . '-12-31'],
    ];
    return array_map(fn (array $r) => [
      'label' => $r[0],
      'url' => $this->url($options, ['from' => $r[1], 'to' => $r[2], 'invoice' => NULL]),
      'pressed' => $options['from'] === $r[1] && $options['to'] === $r[2],
    ], $ranges);
  }

  /**
   * The whole screen.
   *
   * @param string|null $message
   *   A problem to show in the open editor (a move that was refused).
   */
  public function build(array $options, ?string $message = NULL): array {
    $invoices = $this->manager->loadAll();
    $today = $this->manager->today()->format('Y-m-d');
    // Amounts are kept per currency: [sums by currency, count(, overdue)].
    $strip = ['issued' => [[], 0, 0], 'draft' => [[], 0], 'invoiced' => [[], 0]];
    $has_range = $options['from'] !== NULL || $options['to'] !== NULL;
    $year = substr($today, 0, 4);
    // Open-ended sides are widened, so the comparisons below need no special cases.
    $range = $has_range
      ? ['from' => $options['from'] ?? '0000-01-01', 'to' => $options['to'] ?? '9999-12-31']
      : ['from' => $year . '-01-01', 'to' => $year . '-12-31'];
    $rows = [];
    $all_items = $this->manager->loadItems($invoices);
    foreach ($invoices as $invoice) {
      $items = $all_items[$invoice->id()];
      $total = $invoice->getTotals($items)['total'];
      $currency = $this->currency($invoice);
      $state = $this->state($invoice, $today);
      match ($state) {
        'issued', 'overdue' => [$strip['issued'][0][$currency] = ($strip['issued'][0][$currency] ?? 0) + $total, $strip['issued'][1]++, $strip['issued'][2] += $state === 'overdue' ? 1 : 0],
        'draft' => [$strip['draft'][0][$currency] = ($strip['draft'][0][$currency] ?? 0) + $total, $strip['draft'][1]++],
        default => NULL,
      };
      $issued = $invoice->getDate('issue_date');
      // Invoiced in the range (this year when none is chosen): everything sent,
      // paid or not. Drafts and written-off invoices are not.
      if (in_array($state, ['issued', 'overdue', 'paid'], TRUE) && $issued >= $range['from'] && $issued <= $range['to']) {
        $strip['invoiced'][0][$currency] = ($strip['invoiced'][0][$currency] ?? 0) + $total;
        $strip['invoiced'][1]++;
      }
      if ($options['filter'] !== 'all' && $invoice->getStatus() !== $options['filter']) {
        continue;
      }
      // The list follows the range only when one is chosen, unlike the card.
      if ($has_range && ($issued < $range['from'] || $issued > $range['to'])) {
        continue;
      }
      $rows[] = [
        'id' => (int) $invoice->id(),
        'number' => (string) $invoice->label(),
        'issued' => $this->dateLabel($invoice->getDate('issue_date')),
        'client' => (string) $invoice->get('client')->entity?->label(),
        'items' => $items ? implode(' · ', array_map(fn (InvoiceItem $item) => $this->itemShort($item), $items)) : $this->t('No items'),
        'status' => $this->statusBadge($state),
        // Only a paid invoice has a payment date worth showing.
        'paid' => $state === 'paid' && $invoice->getDate('paid_date') !== '' ? $this->dateLabel($invoice->getDate('paid_date')) : NULL,
        'total' => Money::format($total, $currency),
        'open' => $options['invoice'] === (string) $invoice->id(),
        'label' => $invoice->getStatus() === 'draft' ? $this->t('Edit') : $this->t('View', [], ['context' => 'Open an invoice']),
        'url' => $this->url($options, ['invoice' => (string) $invoice->id()]),
      ];
    }

    $screen = [
      'options' => $options,
      'strip' => [
        ['label' => $this->t('Awaiting payment'), 'value' => Money::formatSums($strip['issued'][0]), 'note' => $this->formatPlural($strip['issued'][1], '1 invoice', '@count invoices') . ($strip['issued'][2] ? ' · ' . $this->t('@count overdue', ['@count' => $strip['issued'][2]]) : '')],
        ['label' => $this->t('Drafts'), 'value' => Money::formatSums($strip['draft'][0]), 'note' => $this->formatPlural($strip['draft'][1], '1 draft', '@count drafts')],
        ['label' => $this->invoicedLabel($options, $year), 'value' => Money::formatSums($strip['invoiced'][0]), 'note' => $this->formatPlural($strip['invoiced'][1], '1 invoice', '@count invoices') . ', ' . $this->t('taxes included')],
      ],
      'filters' => array_map(fn ($key, $label) => ['label' => $this->t($label), 'pressed' => $options['filter'] === $key, 'url' => $this->url($options, ['filter' => $key, 'invoice' => NULL])], array_keys(static::FILTERS), static::FILTERS),
      'dates' => [
        'from' => (string) $options['from'],
        'to' => (string) $options['to'],
        'active' => $has_range,
        'presets' => $this->presets($options, $today),
        'clear' => $this->url($options, ['from' => NULL, 'to' => NULL, 'invoice' => NULL]),
      ],
      'rows' => $rows,
      'count_label' => $this->formatPlural(count($rows), '1 invoice', '@count invoices'),
      'can_create' => (bool) $this->manager->loadClients(),
      'urls' => [
        'base' => Url::fromRoute('tally.invoices')->toString(),
        'close' => $this->url($options, ['invoice' => NULL]),
        'clients' => Url::fromRoute('tally.clients')->toString(),
      ],
      'editor' => NULL,
    ];
    if ($options['invoice'] !== NULL && ($invoice = $this->manager->load($options['invoice']))) {
      $screen['editor'] = $this->editor($options, $invoice, $today, $message);
    }

    return [
      '#theme' => 'tally_invoices_screen',
      '#screen' => $screen,
      '#attached' => ['library' => ['core/drupal.htmx']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Everything the editor dialog needs.
   */
  protected function editor(array $options, Invoice $invoice, string $today, ?string $message): array {
    $id = (string) $invoice->id();
    $status = $invoice->getStatus();
    $locked = $status !== 'draft';
    $client = $invoice->get('client')->entity;
    $currency = $this->currency($invoice);
    $items = $invoice->getItems();
    $totals = $invoice->getTotals($items);
    $projects = $this->manager->projectsOf($client);

    $lines = [];
    foreach ($items as $item) {
      $lines[] = $this->line($invoice, $item, $projects, $currency, $locked);
    }

    $client_options = [];
    foreach ($this->manager->loadClients((string) $invoice->get('client')->target_id) as $candidate) {
      $client_options[] = ['value' => (string) $candidate->id(), 'label' => (string) $candidate->label(), 'selected' => $candidate->id() == $invoice->get('client')->target_id];
    }

    $problem = $status === 'draft' ? $this->manager->sendProblem($invoice) : NULL;
    [$primary, $secondary] = match ($status) {
      'draft' => [['label' => $this->t('Mark as sent'), 'action' => 'send', 'disabled' => (bool) $problem, 'tone' => 'accent'], ['label' => $this->t('Delete draft'), 'action' => 'delete', 'tone' => 'danger']],
      'issued' => [['label' => $this->t('Mark as paid'), 'action' => 'pay', 'disabled' => FALSE, 'tone' => 'success'], ['label' => $this->t('Back to draft'), 'action' => 'reopen', 'tone' => 'plain']],
      'cancelled' => [['label' => $this->t('Cancelled'), 'action' => '', 'disabled' => TRUE, 'tone' => 'plain'], ['label' => $this->t('Reinstate invoice'), 'action' => 'uncancel', 'tone' => 'plain']],
      default => [['label' => $this->t('Paid'), 'action' => '', 'disabled' => TRUE, 'tone' => 'success'], ['label' => $this->t('Mark as unpaid'), 'action' => 'unpay', 'tone' => 'plain']],
    };
    $extra = $status === 'issued' ? ['label' => $this->t('Cancel invoice'), 'action' => 'cancel', 'tone' => 'danger'] : NULL;

    $oob = ['#invoice-totals', '#invoice-strip', '#invoice-rows'];
    foreach ($lines as $line) {
      $oob[] = '#line-' . $line['id'] . '-total';
      $oob[] = '#line-' . $line['id'] . '-note';
    }

    return [
      'id' => $id,
      'filter' => $options['filter'],
      'from' => (string) $options['from'],
      'to' => (string) $options['to'],
      'number' => (string) $invoice->label(),
      'number_value' => (string) $invoice->get('number')->value,
      'subject' => (string) $invoice->get('subject')->value,
      'can_pay' => $status === 'issued',
      'paid' => $invoice->getDate('paid_date') !== '' ? ['value' => $invoice->getDate('paid_date'), 'label' => $this->dateLabel($invoice->getDate('paid_date'))] : NULL,
      'pay_default' => $today,
      'discount_kind' => (string) $invoice->get('discount_kind')->value ?: 'percent',
      'discount_value' => $invoice->get('discount_kind')->value === 'amount' ? $this->plain((int) $invoice->get('discount_value')->value) : $this->plainPercent((int) $invoice->get('discount_value')->value),
      'discount_options' => ['percent' => '%', 'amount' => $currency === 'EUR' ? '€' : '$'],
      'status' => $this->statusBadge($this->state($invoice, $today)),
      'locked' => $locked,
      'lock_note' => match ($status) {
        'issued' => $this->t('This invoice was sent, so it is locked. Its bank hours count once it is marked as paid.'),
        'paid' => $this->t('Paid invoices are locked. Its bank hours are added to the project balance.'),
        'cancelled' => $this->t('This invoice was cancelled. It keeps its number, is left out of the totals, and its bank hours never count.'),
        default => NULL,
      },
      'message' => $message ?? ($status === 'draft' ? $problem : NULL),
      'client_name' => (string) $client?->label(),
      'notes' => (string) $invoice->get('notes')->value,
      'language' => ['value' => $invoice->getLanguage(), 'label' => InvoiceLanguage::LANGUAGES[$invoice->getLanguage()], 'options' => InvoiceLanguage::LANGUAGES],
      'client_options' => $client_options,
      'issue' => ['value' => $invoice->getDate('issue_date'), 'label' => $this->dateLabel($invoice->getDate('issue_date'))],
      'due' => ['value' => $invoice->getDate('due_date'), 'label' => $this->dateLabel($invoice->getDate('due_date'))],
      'lines' => $lines,
      'tax' => (bool) $invoice->get('charge_taxes')->value,
      'rates' => array_map(fn (int $rate) => InvoiceLanguage::number(Invoice::formatRate($rate), \Drupal::languageManager()->getCurrentLanguage()->getId()), $invoice->getRates()),
      'totals' => [
        'subtotal' => Money::format($totals['subtotal'], $currency),
        'discount' => $totals['discount'] > 0 ? '-' . Money::format($totals['discount'], $currency) : NULL,
        'discount_label' => $invoice->get('discount_kind')->value === 'percent' ? $this->t('Discount (@percent%)', ['@percent' => $this->plainPercent((int) $invoice->get('discount_value')->value)]) : $this->t('Discount'),
        'gst' => Money::format($totals['gst'], $currency),
        'qst' => Money::format($totals['qst'], $currency),
        'total' => Money::format($totals['total'], $currency),
      ],
      'primary' => $primary,
      'secondary' => $secondary,
      'extra' => $extra,
      'currency' => $currency,
      'symbol' => Money::symbol($currency),
      'oob' => implode(',', $oob),
      'urls' => [
        'close' => $this->url($options, ['invoice' => NULL]),
        'pdf' => Url::fromRoute('tally.invoices.pdf', ['invoice' => $id])->toString(),
        // Only a sent invoice has a public page.
        'share' => $status !== 'draft' && $invoice->getShareToken() !== '' ? Url::fromRoute('tally.public_invoice', ['token' => $invoice->getShareToken()], ['absolute' => TRUE])->toString() : NULL,
      ],
    ];
  }

  /**
   * One item, as the editor and the read-only table need it.
   *
   * @param \Drupal\tally\Entity\Project[] $projects
   *   The client's projects, for the project selects.
   */
  protected function line(Invoice $invoice, InvoiceItem $item, array $projects, string $currency, bool $locked): array {
    $kind = (string) $item->get('kind')->value;
    $id = (int) $item->id();
    $project = $item->get('project')->entity;
    $client = $invoice->get('client')->entity;

    $project_options = [['value' => '', 'label' => $kind === 'bank' ? ($projects ? $this->t('Choose a project') : $this->t('This client has no projects')) : $this->t('No project'), 'selected' => !$project]];
    foreach ($projects as $candidate) {
      $project_options[] = ['value' => (string) $candidate->id(), 'label' => (string) $candidate->label(), 'selected' => $project && $candidate->id() == $project->id()];
    }

    $hours_seconds = (int) round((float) $item->get('hours')->value * 3600);
    $hint = '';
    $hint_tone = '';
    $rate_note = '';
    $rate_tone = '';
    $reset = NULL;
    if ($kind === 'bank') {
      $bank = $project ? $this->report->bank($project) : NULL;
      if (!$project) {
        $hint = $this->t('Pick the project these hours will be used on.');
        $hint_tone = 'warn';
      }
      elseif ($bank) {
        $hint = $this->t('@project balance: @left left today', ['@project' => $project->label(), '@left' => ProjectReport::formatBank($bank['paid_seconds'] - $bank['used_seconds'])]);
        if ($invoice->getStatus() !== 'paid' && $hours_seconds > 0) {
          $hint .= ' · ' . $this->t('adds @hours once paid', ['@hours' => ProjectReport::formatBank($hours_seconds)]);
        }
        $hint_tone = $bank['over'] ? 'over' : '';
      }
      $suggestion = $this->manager->suggestRate($project, $client);
      $rate = (int) $item->get('rate')->value;
      if ($suggestion['rate'] > 0 && $rate === $suggestion['rate']) {
        $rate_note = $suggestion['source'] === 'project' ? $this->t('Rate from the project (@project)', ['@project' => $project?->label()]) : $this->t('Rate from @client’s default rate', ['@client' => ($project?->getClient() ?? $client)?->label() ?? $this->t('the client')]);
      }
      elseif ($rate > 0) {
        $rate_note = $this->t('Rate changed for this invoice');
        if ($suggestion['rate'] > 0) {
          $reset = $this->t('Use @rate (@source)', ['@rate' => Money::rate($suggestion['rate'], $currency), '@source' => $suggestion['source'] === 'project' ? $this->t('project') : $this->t('client')]);
        }
      }
      else {
        $rate_note = $this->t('No rate on this project or client. Enter one for this invoice.');
        $rate_tone = 'warn';
      }
    }
    elseif ($kind === 'hosting') {
      $hint = $this->t('@months × @price per month', ['@months' => (int) $item->get('months')->value, '@price' => Money::format((int) $item->get('monthly_price')->value, $currency)]);
    }

    $detail = $item->getDetail($currency);

    return [
      'id' => $id,
      'kind' => $kind,
      'label' => $this->t(InvoiceItem::KINDS[$kind]),
      'locked' => $locked,
      'description' => (string) $item->get('description')->value,
      'details' => (string) $item->get('details')->value,
      'item_type' => (string) $item->get('item_type')->value ?: 'service',
      'type_options' => array_map(fn (string $label) => $this->t($label), InvoiceItem::TYPES),
      'quantity' => $this->hoursPlain((string) $item->get('quantity')->value),
      'unit_price' => $this->plain((int) $item->get('unit_price')->value),
      'price' => $this->plain((int) $item->get('price')->value),
      'hours' => $this->hoursPlain((string) $item->get('hours')->value),
      'rate' => $this->plain((int) $item->get('rate')->value),
      'months' => (int) $item->get('months')->value ?: '',
      'monthly_price' => $this->plain((int) $item->get('monthly_price')->value),
      'project_options' => $project_options,
      'hint' => $hint,
      'hint_tone' => $hint_tone,
      'rate_note' => $rate_note,
      'rate_tone' => $rate_tone,
      'reset' => $reset,
      'title' => $kind === 'bank' ? ($project ? (string) $project->label() : $this->t('No project')) : ((string) $item->get('description')->value ?: $this->t(InvoiceItem::KINDS[$kind])),
      'detail' => $detail,
      'total' => Money::format($item->getTotal(), $currency),
    ];
  }

  /**
   * "Bank · 20h" style item summary for the list.
   */
  protected function itemShort(InvoiceItem $item): string {
    return match ($item->get('kind')->value) {
      'bank' => ProjectReport::formatBank((int) round((float) $item->get('hours')->value * 3600)) . ' ' . $this->t('bank'),
      'hosting' => $this->t('Hosting @months mo', ['@months' => (int) $item->get('months')->value]),
      'line' => $this->t(InvoiceItem::TYPES[$item->get('item_type')->value] ?? 'Service'),
      default => $this->t('Fixed price'),
    };
  }

  /**
   * The state shown to the user: the status, or 'overdue'.
   */
  protected function state(Invoice $invoice, string $today): string {
    $status = $invoice->getStatus();
    return $status === 'issued' && $invoice->getDate('due_date') !== '' && $invoice->getDate('due_date') < $today ? 'overdue' : $status;
  }

  protected function statusBadge(string $state): array {
    $labels = ['draft' => $this->t('Draft'), 'issued' => $this->t('Awaiting payment'), 'overdue' => $this->t('Overdue'), 'paid' => $this->t('Paid'), 'cancelled' => $this->t('Cancelled')];
    return ['kind' => $state, 'label' => $labels[$state]];
  }

  protected function currency(Invoice $invoice): string {
    return $invoice->getCurrency();
  }

  protected function dateLabel(string $date): string {
    $parsed = $date !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : FALSE;
    return $parsed ? Dates::format($parsed, 'M j, Y') : '-';
  }

  /**
   * Cents as an editable number, empty for zero.
   */
  protected function plain(int $cents): string {
    return $cents > 0 ? Money::plain($cents) : '';
  }

  /**
   * "25" or "12.5" from hundredths of a percent; empty for zero.
   */
  protected function plainPercent(int $hundredths): string {
    return $hundredths > 0 ? rtrim(rtrim(sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100), '0'), '.') : '';
  }

  /**
   * "20" or "7.5" from a stored "20.00" / "7.50"; empty for zero.
   */
  protected function hoursPlain(string $hours): string {
    return InvoiceItem::plainDecimal($hours);
  }

}
