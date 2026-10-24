<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Entity\TimeEntry;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class SidebarWeekDstTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  public function testLastEveningOfA25HourWeekIsCounted(): void {
    $tz = new \DateTimeZone('America/Toronto');
    $at = fn (string $when) => (new \DateTimeImmutable($when, $tz))->getTimestamp();
    // Sunday 1 November 2026: the week of Monday 26 October has 169 hours.
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($at('2026-11-01 23:50'));
    $this->container->set('datetime.time', $time);

    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_time_entry');
    $user = User::create(['name' => 'tester', 'status' => 1, 'timezone' => 'America/Toronto']);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    TimeEntry::create(['uid' => $user->id(), 'start' => $at('2026-11-01 23:10'), 'end' => $at('2026-11-01 23:40')])->save();

    $sidebar = $this->container->get('tally.time_screen')->buildSidebar();
    $this->assertSame('30m', (string) $sidebar['#status']['week']['text']);
  }

}
