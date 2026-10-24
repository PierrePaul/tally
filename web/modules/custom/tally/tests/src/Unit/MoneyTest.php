<?php

namespace Drupal\Tests\tally\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\tally\Money;

/**
 * @group tally
 */
class MoneyTest extends UnitTestCase {

  public function testEuroRateInEnglish(): void {
    $rate = Money::rate(10500, 'EUR');
    $this->assertSame('105.00 €/h', $rate);
    $this->assertTrue(mb_check_encoding($rate, 'UTF-8'));
  }

  public function testCadAndUsdRate(): void {
    $this->assertSame('$105.00/h', Money::rate(10500));
    $this->assertSame('$105.00/h', Money::rate(10500, 'USD'));
  }

  public function testFrenchRateEndsWithPerHour(): void {
    $rate = Money::rate(10500, 'EUR', 'fr');
    $this->assertTrue(mb_check_encoding($rate, 'UTF-8'));
    $this->assertStringEndsWith('/h', $rate);
  }

  public function testFormatNegative(): void {
    $this->assertSame('-$1,234.56', Money::format(-123456));
  }

  public function testSumsStayPerCurrency(): void {
    $this->assertSame('$500.00 + 1,000.00 €', Money::formatSums(['EUR' => 100000, 'CAD' => 50000]));
  }

  public function testSingleCurrencySum(): void {
    $this->assertSame('$123.45', Money::formatSums(['CAD' => 12345]));
  }

  public function testEmptySumIsZero(): void {
    $this->assertSame('$0.00', Money::formatSums([]));
    $this->assertSame('$0.00', Money::formatSums(['EUR' => 0]));
  }

  public function testForSeconds(): void {
    $this->assertSame(10500, Money::forSeconds(3600, 10500));
    $this->assertSame(5250, Money::forSeconds(1800, 10500));
    $this->assertSame(3, Money::forSeconds(1, 10500));
  }

  public function testSafeColor(): void {
    $this->assertSame('#3451D1', \Drupal\tally\Entity\Project::safeColor('not-a-color'));
    $this->assertSame('#0e7490', \Drupal\tally\Entity\Project::safeColor('#0e7490'));
  }

}
