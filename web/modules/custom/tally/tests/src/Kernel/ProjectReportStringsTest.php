<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class ProjectReportStringsTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'tally'];

  public function testBankCardTextIsTranslatable(): void {
    foreach (['user', 'tally_client', 'tally_project', 'tally_invoice', 'tally_invoice_item', 'tally_time_entry'] as $type) {
      $this->installEntitySchema($type);
    }
    $user = User::create(['name' => 'owner', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $storage = $this->container->get('entity_type.manager');
    $client = $storage->getStorage('tally_client')->create(['uid' => $user->id(), 'name' => 'Acme']);
    $client->save();
    $project = $storage->getStorage('tally_project')->create(['uid' => $user->id(), 'name' => 'Site', 'client' => $client->id()]);
    $project->save();
    $invoice = $storage->getStorage('tally_invoice')->create(['uid' => $user->id(), 'client' => $client->id(), 'status' => 'paid']);
    $invoice->save();
    $storage->getStorage('tally_invoice_item')->create(['uid' => $user->id(), 'invoice' => $invoice->id(), 'kind' => 'bank', 'project' => $project->id(), 'hours' => '10.00', 'rate' => 10000])->save();

    $bank = $this->container->get('tally.project_report')->bank($project);
    foreach (['meta', 'short', 'aria'] as $key) {
      $this->assertInstanceOf(TranslatableMarkup::class, $bank[$key], $key);
    }
    $this->assertStringContainsString('10h paid · 0h used', (string) $bank['meta']);
  }

}
