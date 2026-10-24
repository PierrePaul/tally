<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\EntityValidation;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class EntityValidationTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'options', 'tally'];

  protected function client(array $values) {
    $this->installEntitySchema('user');
    $user = User::load(1) ?? User::create(['name' => 'owner', 'uid' => 1, 'status' => 1]);
    $user->save();
    return $this->container->get('entity_type.manager')->getStorage('tally_client')->create($values + ['name' => 'Acme', 'uid' => $user->id()]);
  }

  public function testUnknownCurrencyIsReportedUnderItsKey(): void {
    $errors = EntityValidation::byField($this->client(['currency' => 'XXX'])->validate(), ['currency' => 'currency'], 'name');
    $this->assertArrayHasKey('currency', $errors);
  }

  public function testLongNameIsReportedUnderName(): void {
    $errors = EntityValidation::byField($this->client(['name' => str_repeat('a', 300)])->validate(), ['name' => 'name'], 'name');
    $this->assertArrayHasKey('name', $errors);
  }

  public function testUnmappedFieldGoesToTheFallback(): void {
    $errors = EntityValidation::byField($this->client(['currency' => 'XXX'])->validate(), [], 'name');
    $this->assertArrayHasKey('name', $errors);
  }

  public function testFirstOfAValidEntityIsNull(): void {
    $this->assertNull(EntityValidation::first($this->client([])->validate()));
    $this->assertNotNull(EntityValidation::first($this->client(['currency' => 'XXX'])->validate()));
  }

}
