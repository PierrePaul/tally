<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class OwnerScopeTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'options', 'tally'];

  public function testOnlyTheCurrentUsersEntitiesAreSeen(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('tally_client');
    $a = User::create(['name' => 'a']);
    $a->save();
    $b = User::create(['name' => 'b']);
    $b->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('tally_client');
    $mine = $storage->create(['uid' => $a->id(), 'name' => 'Mine']);
    $mine->save();
    $theirs = $storage->create(['uid' => $b->id(), 'name' => 'Theirs']);
    $theirs->save();
    $this->container->get('current_user')->setAccount($a);
    $scope = $this->container->get('tally.owner_scope');

    $this->assertSame([$mine->id() => $mine->id()], $scope->query('tally_client')->execute());
    $this->assertNull($scope->load('tally_client', (string) $theirs->id()));
    $this->assertSame($mine->id(), $scope->load('tally_client', (string) $mine->id())->id());
    $this->assertNull($scope->load('tally_client', 'abc'));
    $this->assertNull($scope->load('tally_client', NULL));
    $this->assertSame([$theirs->id() => $theirs->id()], $scope->query('tally_client', (int) $b->id())->execute());
  }

}
