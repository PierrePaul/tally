<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Entity\TimeEntry;
use Drupal\user\Entity\User;

/**
 * Duplicating an entry from the calendar's right-click menu.
 *
 * @group tally
 */
class EntryDuplicateTest extends KernelTestBase {

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
      'billable' => FALSE,
      'notes' => 'Some notes',
      'start' => strtotime($start . ' UTC'),
      'end' => $end === NULL ? NULL : strtotime($end . ' UTC'),
    ]);
    $entry->save();
    return $entry;
  }

  public function testTheCopyFollowsTheOriginalWithTheSameDetails(): void {
    $entry = $this->entry('2026-10-20 09:00', '2026-10-20 10:30');
    $copy = $this->container->get('tally.entry_editor')->duplicate($entry);
    $this->assertNotSame($entry->id(), $copy->id());
    $this->assertSame(strtotime('2026-10-20 10:30 UTC'), (int) $copy->get('start')->value);
    $this->assertSame(strtotime('2026-10-20 12:00 UTC'), (int) $copy->get('end')->value);
    $this->assertSame('Work', $copy->get('description')->value);
    $this->assertSame('Some notes', $copy->get('notes')->value);
    $this->assertFalse((bool) $copy->get('billable')->value);
    // The original is untouched.
    $this->assertSame(strtotime('2026-10-20 10:30 UTC'), (int) TimeEntry::load($entry->id())->get('end')->value);
  }

  public function testACopyThatWouldPassMidnightTakesTheSameSlot(): void {
    $entry = $this->entry('2026-10-20 21:00', '2026-10-20 23:00');
    $copy = $this->container->get('tally.entry_editor')->duplicate($entry);
    $this->assertSame(strtotime('2026-10-20 21:00 UTC'), (int) $copy->get('start')->value);
    $this->assertSame(strtotime('2026-10-20 23:00 UTC'), (int) $copy->get('end')->value);
  }

  public function testARunningTimerIsNotCopied(): void {
    $running = $this->entry('2026-10-20 09:00', NULL);
    $this->assertNull($this->container->get('tally.entry_editor')->duplicate($running));
    $this->assertCount(1, TimeEntry::loadMultiple());
  }

}
