<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Entity\TimeEntry;
use Drupal\tally\TimeTracker;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class TimerStartTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  public function testStartingTwiceLeavesOneRunningEntry(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_time_entry');
    $user = User::create(['name' => 'tester', 'status' => 1]);
    $user->save();
    $tracker = $this->container->get('tally.time_tracker');
    $first = $tracker->start($user, 'One', NULL, TRUE);
    $second = $tracker->start($user, 'Two', NULL, TRUE);
    $this->assertSame($second->id(), $tracker->getRunning($user)->id());
    $this->assertFalse(TimeEntry::load($first->id())->isRunning());
  }

  public function testAHeldLockMakesAnotherStartWaitThenFail(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_time_entry');
    $user = User::create(['name' => 'tester', 'status' => 1]);
    $user->save();
    // Another process (a second lock backend) holds the lock for this user.
    $database = $this->container->get('database');
    $this->assertTrue((new DatabaseLockBackend($database))->acquire('tally_timer_' . $user->id(), 30));
    $this->expectException(\RuntimeException::class);
    $tracker = new TimeTracker($this->container->get('entity_type.manager'), $this->container->get('datetime.time'), $this->container->get('tally.owner_scope'), new DatabaseLockBackend($database));
    $tracker->start($user, 'Blocked', NULL, TRUE);
  }

}
