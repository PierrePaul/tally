<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group tally
 */
class ProjectSummaryCurrencyTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'options', 'tally'];

  public function testCustomRateSummaryUsesTheClientsCurrency(): void {
    $client = $this->container->get('entity_type.manager')->getStorage('tally_client')->create(['name' => 'Euro Co', 'currency' => 'EUR']);
    $screen = $this->container->get('tally.project_screen');
    $method = new \ReflectionMethod($screen, 'summary');
    $summary = $method->invoke($screen, ['mode' => 'custom', 'rate' => '105', 'client' => ''], $client);
    $this->assertSame('105.00 €/h', $summary['title']);
  }

}
