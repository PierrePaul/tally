<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class InvoiceItemsBatchTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'tally'];

  public function testLoadItemsMatchesGetItems(): void {
    foreach (['user', 'tally_client', 'tally_invoice', 'tally_invoice_item'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('user', ['users_data']);
    $user = User::create(['name' => 'owner', 'timezone' => 'America/Toronto']);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $this->container->get('entity_type.manager')->getStorage('tally_client')->create(['uid' => $user->id(), 'name' => 'Acme'])->save();
    $manager = $this->container->get('tally.invoice_manager');

    $invoices = [];
    foreach ([2, 0, 1] as $count) {
      $invoice = $manager->createDraft();
      for ($i = 0; $i < $count; $i++) {
        $manager->addItem($invoice, 'fixed');
      }
      $invoices[] = $invoice;
    }

    $batched = $manager->loadItems($invoices);
    foreach ($invoices as $invoice) {
      $this->assertSame(array_keys($invoice->getItems()), array_keys($batched[$invoice->id()]));
    }
  }

}
