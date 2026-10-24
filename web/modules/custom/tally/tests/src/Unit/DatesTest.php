<?php

namespace Drupal\Tests\tally\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tally\Dates;

/**
 * @group tally
 */
class DatesTest extends UnitTestCase {

  public function testRealDateParses(): void {
    $this->assertSame('2026-02-28', Dates::parse('2026-02-28')->format('Y-m-d'));
  }

  /**
   * @dataProvider badDates
   */
  public function testBadDatesAreRefused(?string $value): void {
    $this->assertNull(Dates::parse($value));
  }

  public static function badDates(): array {
    return [['2026-02-31'], ['2026-2-3'], [''], [NULL], ['2026-13-01'], ['abc']];
  }

  public function testTimezoneIsKept(): void {
    $date = Dates::parse('2026-06-01', new \DateTimeZone('America/Toronto'));
    $this->assertSame('America/Toronto', $date->getTimezone()->getName());
  }

}
