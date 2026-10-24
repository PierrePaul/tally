<?php

namespace Drupal\tally;

use Drupal\Core\Url;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Invoice;
use Stripe\StripeClient;

/**
 * Starts a Stripe Checkout payment for an invoice.
 *
 * The invoice is sent to Stripe as one line for its total in cents, so tax and
 * discount math stays in Tally and the two can never disagree. The key comes
 * is the invoice owner's own Stripe secret key, saved in their Settings.
 */
class StripeCheckout {

  public function __construct(protected StripeAccounts $accounts) {}

  /**
   * Stripe refuses a payment under 50 cents in CAD, USD and EUR.
   */
  public const MINIMUM = 50;

  /**
   * Whether this user has saved a Stripe secret key.
   */
  public function isConfigured(int $uid): bool {
    return $this->accounts->secretKey($uid) !== '';
  }

  /**
   * Whether the public page may offer to pay this invoice.
   */
  public function canPay(Invoice $invoice): bool {
    return $this->isConfigured((int) $invoice->getOwnerId()) && $invoice->getStatus() === 'issued' && $invoice->getTotal() >= static::MINIMUM;
  }

  /**
   * Creates a Checkout Session and returns the hosted payment page's URL.
   *
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function createSession(Invoice $invoice, string $token): string {
    $client = $invoice->get('client')->entity;
    $currency = $invoice->getCurrency();
    $page = Url::fromRoute('tally.public_invoice', ['token' => $token], ['absolute' => TRUE])->toString();
    $email = $client instanceof Client ? trim((string) $client->get('billing_contact')->value) : '';
    $total = $invoice->getTotal();

    $params = [
      'mode' => 'payment',
      // The language of Stripe's own page.
      'locale' => $invoice->getLanguage() === 'fr' ? 'fr-CA' : 'en',
      'line_items' => [[
        'quantity' => 1,
        'price_data' => [
          'currency' => strtolower($currency),
          'unit_amount' => $total,
          'product_data' => ['name' => InvoiceLanguage::t('Invoice @number', $invoice->getLanguage(), ['@number' => $invoice->get('number')->value])],
        ],
      ]],
      'client_reference_id' => $invoice->uuid(),
      'metadata' => ['invoice_uuid' => $invoice->uuid()],
      'payment_intent_data' => ['metadata' => ['invoice_uuid' => $invoice->uuid()]],
      'success_url' => $page . '?payment=return',
      'cancel_url' => $page,
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $params['customer_email'] = $email;
      $params['payment_intent_data']['receipt_email'] = $email;
    }
    $session = (new StripeClient($this->accounts->secretKey((int) $invoice->getOwnerId())))->checkout->sessions->create($params, [
      // The same invoice at the same total and revision gives the same session.
      'idempotency_key' => 'tally-' . $invoice->uuid() . '-' . $total . '-' . $currency . '-' . substr(sha1($email), 0, 8) . '-' . $invoice->getChangedTime(),
    ]);
    return (string) $session->url;
  }

  /**
   * Fetches a Checkout Session from Stripe by its id.
   *
   * Thin webhook events only point at the session; asking Stripe for it also
   * means the amounts we compare come straight from Stripe.
   *
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function retrieveSession(string $id, int $uid): object {
    return (new StripeClient($this->accounts->secretKey($uid)))->checkout->sessions->retrieve($id);
  }

}
