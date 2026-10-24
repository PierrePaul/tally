<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class SidebarTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'tally'];

  public function testSidebarAttachesHtmx(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_time_entry');
    $user = User::create(['name' => 'tester', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $this->container->get('tally.time_tracker')->start($user, 'Work', NULL, TRUE);

    $sidebar = $this->container->get('tally.time_screen')->buildSidebar();
    $this->assertContains('core/drupal.htmx', $sidebar['#attached']['library'] ?? []);
  }

}
