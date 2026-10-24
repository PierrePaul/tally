<?php

namespace Drupal\tally;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The words, dates and hours of an invoice in English or French.
 *
 * Only what the client reads: the public page, the PDF and the Stripe Checkout
 * page. The language belongs to each invoice, not to the visitor or the site,
 * so strings are translated with an explicit langcode through Drupal's locale
 * system; the French is in translations/fr.po. Dates and money use PHP's intl.
 */
final class InvoiceLanguage {

  public const LANGUAGES = ['en' => 'English', 'fr' => 'Français'];

  /**
   * A language code Tally knows, 'en' when it is not one.
   */
  public static function normalize(mixed $code): string {
    return is_string($code) && isset(static::LANGUAGES[$code]) ? $code : 'en';
  }

  /**
   * One string in a language, with its placeholders filled in.
   */
  public static function t(string $english, string $lang, array $args = []): string {
    return (string) new TranslatableMarkup($english, $args, ['langcode' => $lang]);
  }

  /**
   * The same for a count: "1 month", "@count months".
   */
  public static function plural(int $count, string $singular, string $plural, string $lang): string {
    return (string) \Drupal::translation()->formatPlural($count, $singular, $plural, [], ['langcode' => $lang]);
  }

  /**
   * The plain (placeholder-free) strings of a language, for the templates.
   *
   * @return array<string, string>
   */
  public static function labels(string $lang): array {
    static $cache = [];
    return $cache[$lang] ??= [
      'invoice' => static::t('Invoice', $lang),
      'draft' => static::t('Draft', $lang),
      'billed_to' => static::t('Billed to', $lang),
      'issued' => static::t('Issued', $lang),
      'due' => static::t('Due', $lang),
      'date_paid' => static::t('Paid on', $lang),
      'description' => static::t('Description', $lang),
      'amount' => static::t('Amount', $lang),
      'subtotal' => static::t('Subtotal', $lang),
      'discount' => static::t('Discount', $lang),
      'total' => static::t('Total', $lang),
      'notes' => static::t('Notes', $lang),
      'state_issued' => static::t('Awaiting payment', $lang),
      'state_overdue' => static::t('Overdue', $lang),
      'state_paid' => static::t('Paid', $lang),
      'state_cancelled' => static::t('Cancelled', $lang),
      'state_draft' => static::t('Draft', $lang),
      'bank' => static::t('Bank of hours', $lang),
      'fixed' => static::t('Fixed price', $lang),
      'hosting' => static::t('Hosting', $lang),
      'line' => static::t('Line item', $lang),
      'per_month' => static::t('per month', $lang),
      'no_project' => static::t('Not tied to a project', $lang),
      'service' => static::t('Service', $lang),
      'product' => static::t('Product', $lang),
      'pay' => static::t('Pay with credit card', $lang),
      'pay_error' => static::t('We could not start the payment. Please try again.', $lang),
      'pay_cancelled' => static::t('This invoice was cancelled.', $lang),
      'pay_confirming' => static::t('Confirming your payment…', $lang),
      'pay_processing' => static::t('Your payment is being processed. You will get a receipt by email.', $lang),
    ];
  }

  /**
   * A 'Y-m-d' date for people: "Jan 22, 2025" or "22 janvier 2025". '' when empty.
   */
  public static function date(string $date, string $lang): string {
    $parsed = $date !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : FALSE;
    if (!$parsed) {
      return '';
    }
    if ($lang !== 'fr') {
      return $parsed->format('M j, Y');
    }
    $formatter = new \IntlDateFormatter('fr_CA', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'd MMMM y');
    $text = (string) $formatter->format($parsed->setTime(12, 0));
    // French writes the first of the month as "1er".
    return (int) $parsed->format('j') === 1 ? preg_replace('/^1 /', '1er ', $text) : $text;
  }

  /**
   * Seconds as hours: "2h 30m" or "2 h 30".
   */
  public static function hours(int $seconds, string $lang): string {
    if ($lang !== 'fr') {
      return ProjectReport::formatBank($seconds);
    }
    $minutes = (int) round(abs($seconds) / 60);
    return intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . sprintf('%02d', $minutes % 60) : '');
  }

  /**
   * A quantity as people read it: "2.5" in English, "2,5" in French.
   */
  public static function number(string $value, string $lang): string {
    return $lang === 'fr' ? str_replace('.', ',', $value) : $value;
  }

}
