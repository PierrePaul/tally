<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Entity\Project;
use Drupal\tally\Entity\TimeEntry;
use Drupal\tally\TimeTracker;

/**
 * @group tally
 */
class TimeTrackerSummarizeTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'options', 'tally'];

  protected \DateTimeZone $tz;

  protected function setUp(): void {
    parent::setUp();
    $this->tz = new \DateTimeZone('America/Toronto');
  }

  protected function at(string $when): int {
    return (new \DateTimeImmutable($when, $this->tz))->getTimestamp();
  }

  /**
   * A timer started Monday 22:00, still running Tuesday 08:00.
   */
  protected function summarize(string $from, string $to): array {
    $now = $this->at('2026-10-20 08:00');
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $tracker = new TimeTracker($this->container->get('entity_type.manager'), $time, $this->container->get('tally.owner_scope'), $this->container->get('lock'));
    $entry = TimeEntry::create(['start' => $this->at('2026-10-19 22:00')]);
    return $tracker->summarize([$entry], $this->at($from), $this->at($to));
  }

  public function testPastDayCountsTheEveningPart(): void {
    $result = $this->summarize('2026-10-19 00:00', '2026-10-20 00:00');
    $this->assertSame(7200, $result['seconds']);
    $this->assertSame(7200, $result['base']);
    $this->assertNull($result['live_start']);
  }

  public function testTodayIsLive(): void {
    $result = $this->summarize('2026-10-20 00:00', '2026-10-21 00:00');
    $this->assertSame(28800, $result['seconds']);
    $this->assertSame(0, $result['base']);
    $this->assertSame($this->at('2026-10-20 00:00'), $result['live_start']);
  }

  public function testFutureWindowIsEmpty(): void {
    $result = $this->summarize('2026-10-21 00:00', '2026-10-22 00:00');
    $this->assertSame(0, $result['seconds']);
    $this->assertNull($result['live_start']);
  }

  public function testWindowBeforeTheStartIsEmpty(): void {
    $this->assertSame(0, $this->summarize('2026-10-18 00:00', '2026-10-19 00:00')['seconds']);
  }

  public function testCountedLeavesOutExcludedProjects(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_project');
    $time = $this->createStub(TimeInterface::class);
    $tracker = new TimeTracker($this->container->get('entity_type.manager'), $time, $this->container->get('tally.owner_scope'), $this->container->get('lock'));
    $work = Project::create(['name' => 'Work']);
    $work->save();
    $lunch = Project::create(['name' => 'Lunch', 'exclude_from_totals' => TRUE]);
    $lunch->save();
    $entries = [
      TimeEntry::create(['project' => $work->id(), 'start' => 0, 'end' => 3600]),
      TimeEntry::create(['project' => $lunch->id(), 'start' => 3600, 'end' => 5400]),
      TimeEntry::create(['start' => 5400, 'end' => 7200]),
    ];
    $counted = $tracker->counted($entries);
    $this->assertCount(2, $counted);
    $this->assertSame(5400, $tracker->summarize($counted, 0, 10000)['seconds']);
  }

}
