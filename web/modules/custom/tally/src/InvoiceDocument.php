<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Invoice;

/**
 * Builds the invoice document: what the public page and the PDF both show.
 *
 * It reads the invoice owner's business details, never the current user's,
 * because the public page has no signed-in user.
 */
class InvoiceDocument {

  use StringTranslationTrait;

  public function __construct(
    protected Settings $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {}

  /**
   * The document as a render array.
   */
  public function build(Invoice $invoice): array {
    return [
      '#theme' => 'tally_invoice_document',
      '#doc' => $this->data($invoice),
      '#attached' => ['library' => ['tally/invoice_document']],
    ];
  }

  /**
   * Everything the document template shows.
   */
  public function data(Invoice $invoice): array {
    $client = $invoice->get('client')->entity;
    $currency = $invoice->getCurrency();
    $business = $this->settings->forUser((int) $invoice->getOwnerId());
    $items = $invoice->getItems();
    $totals = $invoice->getTotals($items);
    $state = $this->state($invoice);
    $lang = $invoice->getLanguage();
    $labels = InvoiceLanguage::labels($lang);
    $number = (string) $invoice->get('number')->value;

    $rows = [];
    foreach ($items as $item) {
      $kind = (string) $item->get('kind')->value;
      $project = $item->get('project')->entity;
      $description = (string) $item->get('description')->value;
      $rows[] = [
        'title' => $kind === 'bank' ? (string) ($project?->label() ?: $labels['bank']) : ($description ?: $labels[$kind]),
        'description' => $kind === 'bank' ? $description : '',
        'details' => (string) $item->get('details')->value,
        'detail' => $item->getDetail($currency, $lang),
        'total' => Money::format($item->getTotal(), $currency, $lang),
      ];
    }

    $percent = $invoice->get('discount_kind')->value === 'percent';
    return [
      'business' => [
        'name' => trim((string) $business['company_name']),
        'text_lines' => $this->lines((string) $business['company_text']),
        'logo' => $this->logo((int) $business['logo']),
        'address_lines' => $this->lines((string) $business['company_address']),
        'gst' => trim((string) $business['gst_number']),
        'qst' => trim((string) $business['qst_number']),
      ],
      'client' => [
        'name' => $client ? (string) $client->label() : '',
        'attention' => $client ? trim((string) $client->get('attention')->value) : '',
        'address_lines' => $client ? $this->clientAddress($client) : [],
      ],
      'number' => $number,
      'subject' => (string) $invoice->get('subject')->value,
      'notes' => trim((string) $invoice->get('notes')->value),
      'lang' => $lang,
      'l' => $labels,
      'issue' => InvoiceLanguage::date($invoice->getDate('issue_date'), $lang),
      'due' => InvoiceLanguage::date($invoice->getDate('due_date'), $lang),
      'paid' => InvoiceLanguage::date($invoice->getDate('paid_date'), $lang),
      'state' => $state,
      'state_label' => $labels['state_' . $state],
      'items' => $rows,
      'totals' => [
        'subtotal' => Money::format($totals['subtotal'], $currency, $lang),
        'discount' => $totals['discount'] > 0 ? '-' . Money::format($totals['discount'], $currency, $lang) : '',
        'discount_label' => $percent ? InvoiceLanguage::t('Discount (@percent%)', $lang, ['@percent' => InvoiceLanguage::number(rtrim(rtrim(number_format((int) $invoice->get('discount_value')->value / 100, 2, '.', ''), '0'), '.'), $lang)]) : $labels['discount'],
        'gst' => Money::format($totals['gst'], $currency, $lang),
        'qst' => Money::format($totals['qst'], $currency, $lang),
        'total' => Money::format($totals['total'], $currency, $lang),
      ],
      'gst_label' => InvoiceLanguage::t('GST (@rate%)', $lang, ['@rate' => InvoiceLanguage::number(Invoice::formatRate($invoice->getRates()['gst']), $lang)]),
      'qst_label' => InvoiceLanguage::t('QST (@rate%)', $lang, ['@rate' => InvoiceLanguage::number(Invoice::formatRate($invoice->getRates()['qst']), $lang)]),
      'gst_no' => $business['gst_number'] !== '' ? InvoiceLanguage::t('GST no. @number', $lang, ['@number' => trim((string) $business['gst_number'])]) : '',
      'qst_no' => $business['qst_number'] !== '' ? InvoiceLanguage::t('QST no. @number', $lang, ['@number' => trim((string) $business['qst_number'])]) : '',
      'rates' => array_map([Invoice::class, 'formatRate'], $invoice->getRates()),
      'charge_taxes' => (bool) $invoice->get('charge_taxes')->value,
      'currency' => $currency,
      'total_cents' => $totals['total'],
      'filename' => $labels['invoice'] . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $number ?: 'draft') . '.pdf',
    ];
  }

  /**
   * The state shown to the reader: the status, or 'overdue'.
   *
   * Today is the owner's date, since the visitor has no timezone of their own.
   */
  public function state(Invoice $invoice): string {
    $status = $invoice->getStatus();
    if ($status !== 'issued' || $invoice->getDate('due_date') === '') {
      return $status;
    }
    $timezone = new \DateTimeZone($invoice->getOwner()?->getTimeZone() ?: date_default_timezone_get());
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($timezone)->format('Y-m-d');
    return $invoice->getDate('due_date') < $today ? 'overdue' : 'issued';
  }

  /**
   * Non-empty lines of a multi-line text.
   *
   * @return string[]
   */
  protected function lines(string $text): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen'));
  }

  /**
   * The client's address as lines: street, then "City, Region Postal code".
   *
   * @return string[]
   */
  protected function clientAddress(Client $client): array {
    $street = trim((string) $client->get('address_street')->value);
    $region = trim(implode(' ', array_filter([trim((string) $client->get('address_region')->value), trim((string) $client->get('address_postal_code')->value)], 'strlen')));
    $place = implode(', ', array_filter([trim((string) $client->get('address_city')->value), $region], 'strlen'));
    return array_values(array_filter([$street, $place], 'strlen'));
  }

  /**
   * The logo as a data URI, so the page and the PDF need no file access. '' for none.
   */
  protected function logo(int $fid): string {
    $file = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
    $bytes = $file ? @file_get_contents($file->getFileUri()) : FALSE;
    return $bytes ? 'data:' . $file->getMimeType() . ';base64,' . base64_encode($bytes) : '';
  }

}
