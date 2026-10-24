<?php

namespace Drupal\Tests\tally\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tally\TimeScreenBuilder;

/**
 * @group tally
 */
class WallClockTest extends UnitTestCase {

  protected function minutes(string $day, string $when): float {
    $tz = new \DateTimeZone('America/Toronto');
    return TimeScreenBuilder::minutesIntoDay((new \DateTimeImmutable($when, $tz))->getTimestamp(), new \DateTimeImmutable($day, $tz));
  }

  public function testLongDayStillPlacesNineAtNine(): void {
    $this->assertSame(540.0, $this->minutes('2026-11-01 00:00', '2026-11-01 09:00'));
  }

  public function testShortDayStillPlacesNineAtNine(): void {
    $this->assertSame(540.0, $this->minutes('2026-03-08 00:00', '2026-03-08 09:00'));
  }

  public function testBeforeAndAfterTheDayAreClamped(): void {
    $this->assertSame(0.0, $this->minutes('2026-06-15 00:00', '2026-06-14 23:00'));
    $this->assertSame(1440.0, $this->minutes('2026-06-15 00:00', '2026-06-16 01:00'));
  }

  public function testSeconds(): void {
    $this->assertSame(810.5, $this->minutes('2026-06-15 00:00', '2026-06-15 13:30:30'));
  }

}
