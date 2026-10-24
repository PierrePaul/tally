<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Entity\TimeEntry;
use Drupal\user\Entity\User;

/**
 * Moving and resizing an entry by dragging it on the calendar.
 *
 * @group tally
 */
class EntryMoveTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_time_entry');
    $user = User::create(['name' => 'tester', 'status' => 1, 'timezone' => 'UTC']);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  protected function entry(string $start, ?string $end): TimeEntry {
    $entry = TimeEntry::create([
      'uid' => $this->container->get('current_user')->id(),
      'description' => 'Work',
      'billable' => TRUE,
      'start' => strtotime($start . ' UTC'),
      'end' => $end === NULL ? NULL : strtotime($end . ' UTC'),
    ]);
    $entry->save();
    return $entry;
  }

  protected function monday(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-10-19', new \DateTimeZone('UTC'));
  }

  public function testMoveToAnotherDayAndTime(): void {
    $entry = $this->entry('2026-10-20 09:00', '2026-10-20 10:30');
    $error = $this->container->get('tally.entry_editor')->move($entry, '2026-10-22', '13:15', '14:45', $this->monday());
    $this->assertNull($error);
    $saved = TimeEntry::load($entry->id());
    $this->assertSame(strtotime('2026-10-22 13:15 UTC'), (int) $saved->get('start')->value);
    $this->assertSame(strtotime('2026-10-22 14:45 UTC'), (int) $saved->get('end')->value);
  }

  public function testAnUnchangedTimeKeepsItsSeconds(): void {
    $entry = $this->entry('2026-10-20 09:00:30', '2026-10-20 10:00:45');
    $error = $this->container->get('tally.entry_editor')->move($entry, '2026-10-20', '09:00', '11:00', $this->monday());
    $this->assertNull($error);
    $saved = TimeEntry::load($entry->id());
    $this->assertSame(strtotime('2026-10-20 09:00:30 UTC'), (int) $saved->get('start')->value);
    $this->assertSame(strtotime('2026-10-20 11:00 UTC'), (int) $saved->get('end')->value);
  }

  public function testRefusedMovesLeaveTheEntryAlone(): void {
    $editor = $this->container->get('tally.entry_editor');
    $entry = $this->entry('2026-10-20 09:00', '2026-10-20 10:00');
    $start = strtotime('2026-10-20 09:00 UTC');
    $this->assertNotNull($editor->move($entry, '2026-10-27', '09:00', '10:00', $this->monday()), 'A day outside the week');
    $this->assertNotNull($editor->move($entry, '2026-10-20', '10:00', '10:00', $this->monday()), 'No duration');
    $this->assertNotNull($editor->move($entry, '2026-10-20', '09:00', '24:00', $this->monday()), 'Not a time');
    $this->assertSame($start, (int) TimeEntry::load($entry->id())->get('start')->value);
    $this->assertSame(strtotime('2026-10-20 10:00 UTC'), (int) TimeEntry::load($entry->id())->get('end')->value);
  }

  public function testARunningTimerCannotBeMoved(): void {
    $running = $this->entry('2026-10-20 09:00', NULL);
    $this->assertNotNull($this->container->get('tally.entry_editor')->move($running, '2026-10-20', '10:00', '11:00', $this->monday()));
    $this->assertTrue(TimeEntry::load($running->id())->isRunning());
  }

}
