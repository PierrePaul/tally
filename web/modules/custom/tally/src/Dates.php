<?php

namespace Drupal\tally;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Reads dates typed or posted as "YYYY-MM-DD".
 */
final class Dates {

  /**
   * Midnight of that day, or NULL when the text is not a real date.
   *
   * PHP would turn 2026-02-31 into 3 March; this refuses it.
   */
  public static function parse(?string $value, ?\DateTimeZone $timezone = NULL): ?\DateTimeImmutable {
    if ($value === NULL || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return NULL;
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : NULL;
  }

  /**
   * Formats a date in the interface language, with month and day names and
   * the order of the parts as that language writes them.
   */
  public static function format(\DateTimeInterface $date, string $pattern): string {
    $date = DrupalDateTime::createFromTimestamp($date->getTimestamp(), $date->getTimezone());
    return $date->format(static::pattern($pattern));
  }

  /**
   * The pattern as the interface language orders it: "M j" is "Oct 12" in
   * English and "12 oct." in French. Patterns of a single part stay as they are.
   */
  public static function pattern(string $pattern): string {
    $ordered = ['l, F j', 'M j', 'F j', 'M j, Y', 'D M j', 'D M j, Y'];
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return in_array($pattern, $ordered, TRUE) ? (string) new TranslatableMarkup($pattern, [], ['context' => 'Date pattern']) : $pattern;
  }

  /**
   * The last day of a week that stays in one month: "18" in English, "18 oct."
   * in French, where "12 – 18 oct." would read as if the month were missing
   * from the first day. With $long the month is written out in full.
   */
  public static function lastDayOfRange(\DateTimeInterface $date, bool $long = FALSE): string {
    $date = DrupalDateTime::createFromTimestamp($date->getTimestamp(), $date->getTimezone());
    $context = $long ? 'Last day of a range in one month, long month name' : 'Last day of a range in one month';
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return $date->format((string) new TranslatableMarkup('j', [], ['context' => $context]));
  }

}
