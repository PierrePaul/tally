<?php

namespace Drupal\Tests\tally\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tally\Entity\Invoice;
use Drupal\user\Entity\User;

/**
 * @group tally
 */
class InvoiceCurrencyTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'tally'];

  protected $client;

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'tally_client', 'tally_project', 'tally_invoice', 'tally_invoice_item', 'tally_time_entry'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('user', ['users_data']);
    $user = User::create(['name' => 'owner', 'mail' => 'o@example.com', 'timezone' => 'America/Toronto']);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $this->client = $this->container->get('entity_type.manager')->getStorage('tally_client')->create(['uid' => $user->id(), 'name' => 'Acme', 'currency' => 'CAD']);
    $this->client->save();
  }

  /**
   * A sent invoice of 100.00 for the client.
   */
  protected function sentInvoice(): Invoice {
    $manager = $this->container->get('tally.invoice_manager');
    $invoice = $manager->createDraft();
    $invoice->set('charge_taxes', FALSE)->save();
    $manager->addItem($invoice, 'fixed');
    $item = array_values($invoice->getItems())[0];
    $item->set('price', 10000)->save();
    $this->assertNull($manager->transition($invoice, 'send'));
    return $invoice;
  }

  /**
   * Changes the client's currency; returns the invoice freshly loaded, since
   * the old object still holds the client it loaded earlier.
   */
  protected function switchClientTo(string $currency, Invoice $invoice): Invoice {
    $this->client->set('currency', $currency)->save();
    $this->container->get('entity_type.manager')->getStorage('tally_invoice')->resetCache();
    return Invoice::load($invoice->id());
  }

  public function testDraftFollowsTheClient(): void {
    $invoice = $this->container->get('tally.invoice_manager')->createDraft();
    $invoice = $this->switchClientTo('USD', $invoice);
    $this->assertSame('USD', $invoice->getCurrency());
  }

  public function testSentInvoiceKeepsItsCurrency(): void {
    $invoice = $this->sentInvoice();
    $invoice = $this->switchClientTo('USD', $invoice);
    $this->assertSame('CAD', $invoice->getCurrency());
  }

  public function testLateCadWebhookStillPays(): void {
    $invoice = $this->sentInvoice();
    $invoice = $this->switchClientTo('USD', $invoice);
    $session = (object) [
      'payment_status' => 'paid',
      'metadata' => (object) ['invoice_uuid' => $invoice->uuid()],
      'amount_total' => 10000,
      'currency' => 'cad',
      'payment_intent' => 'pi_1',
    ];
    $this->assertSame('paid', $this->container->get('tally.payment_recorder')->record($session));
  }

  public function testDocumentUsesTheInvoiceCurrency(): void {
    $invoice = $this->sentInvoice();
    $invoice = $this->switchClientTo('USD', $invoice);
    $this->assertSame('CAD', $this->container->get('tally.invoice_document')->data($invoice)['currency']);
  }

}
