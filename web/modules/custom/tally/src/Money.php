<?php

namespace Drupal\tally;

/**
 * Formats money held as integer cents.
 */
final class Money {

  /**
   * "$1,234.56", or "1,234.56 €" for euros.
   */
  public static function format(int $cents, string $currency = 'CAD', ?string $langcode = NULL): string {
    if (static::language($langcode) === 'fr') {
      // Québec French: "1 140,00 $", "1 140,00 $ US", "1 140,00 €".
      return (string) (new \NumberFormatter('fr_CA', \NumberFormatter::CURRENCY))->formatCurrency($cents / 100, $currency);
    }
    $number = static::amount($cents);
    $sign = $cents < 0 ? '-' : '';
    return $currency === 'EUR' ? $sign . $number . ' €' : $sign . '$' . $number;
  }

  /**
   * The given language, or the interface language when none is given.
   */
  protected static function language(?string $langcode): string {
    if ($langcode !== NULL) {
      return $langcode;
    }
    return \Drupal::hasContainer() ? \Drupal::languageManager()->getCurrentLanguage()->getId() : 'en';
  }

  /**
   * The unsigned number with grouping and two decimals: "1,234.56".
   */
  protected static function amount(int $cents): string {
    return number_format(intdiv(abs($cents), 100)) . '.' . sprintf('%02d', abs($cents) % 100);
  }

  /**
   * Sums per currency as one text: "$500.00 + 1,000.00 €". Amounts of
   * different currencies are never added together.
   *
   * @param array<string, int> $cents_by_currency
   */
  public static function formatSums(array $cents_by_currency, ?string $langcode = NULL): string {
    ksort($cents_by_currency);
    $parts = [];
    foreach (array_filter($cents_by_currency) as $currency => $cents) {
      $parts[] = static::format($cents, $currency, $langcode);
    }
    return $parts ? implode(' + ', $parts) : static::format(0, 'CAD', $langcode);
  }

  /**
   * "$105.00/h", or "105.00 €/h" for euros.
   */
  public static function rate(int $cents, string $currency = 'CAD', ?string $langcode = NULL): string {
    if (static::language($langcode) === 'fr') {
      return static::format($cents, $currency, 'fr') . '/h';
    }
    return $currency === 'EUR' ? ($cents < 0 ? '-' : '') . static::amount($cents) . ' €/h' : static::format($cents, $currency) . '/h';
  }

  /**
   * The amount for a duration at an hourly rate, rounded to the nearest cent.
   */
  public static function forSeconds(int $seconds, int $cents_per_hour): int {
    return intdiv($seconds * $cents_per_hour + 1800, 3600);
  }

  /**
   * The symbol shown in front of a rate input.
   */
  public static function symbol(string $currency): string {
    return $currency === 'EUR' ? '€' : '$';
  }

  /**
   * Parses "120", "120.5" or "120,50" into cents. NULL when it isn't money.
   */
  public static function parse(string $input): ?int {
    if (!preg_match('/^\s*(\d{1,7})(?:[.,](\d{1,2}))?\s*$/', $input, $m)) {
      return NULL;
    }
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
  }

  /**
   * Cents as an editable number: "120" or "120.50".
   */
  public static function plain(int $cents): string {
    return $cents % 100 === 0 ? (string) intdiv($cents, 100) : intdiv($cents, 100) . '.' . sprintf('%02d', $cents % 100);
  }

}
