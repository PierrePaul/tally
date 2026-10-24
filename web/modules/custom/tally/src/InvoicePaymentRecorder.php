<?php

namespace Drupal\tally;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Invoice;

/**
 * Marks an invoice paid from a paid Stripe Checkout Session.
 *
 * The webhook has no signed-in user, so invoices are found by uuid. Nothing
 * here throws for business reasons: a webhook answered with a 5xx is retried
 * by Stripe for days, so every outcome is a return value and a log line.
 */
class InvoicePaymentRecorder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InvoiceManager $manager,
    protected TimeInterface $time,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Records the payment.
   *
   * @param object $session
   *   A \Stripe\Checkout\Session.
   * @param int|null $uid
   *   The user whose Stripe account sent it. The invoice must be theirs.
   *
   * @return string
   *   'paid', 'already' (it was paid before), 'ignored' (not paid yet, or the
   *   invoice is not awaiting payment), 'mismatch' (wrong amount or currency)
   *   or 'unknown' (no such invoice).
   */
  public function record(object $session, ?int $uid = NULL): string {
    if (($session->payment_status ?? NULL) !== 'paid') {
      return 'ignored';
    }
    $uuid = (string) ($session->metadata->invoice_uuid ?? '');
    $invoices = $uuid !== '' ? $this->entityTypeManager->getStorage('tally_invoice')->loadByProperties(['uuid' => $uuid]) : [];
    $invoice = reset($invoices);
    if ($invoice instanceof Invoice && $uid !== NULL && (int) $invoice->getOwnerId() !== $uid) {
      // One user's Stripe account must never mark another user's invoice paid.
      $this->logger->error('Stripe payment from the account of user @uid for an invoice that is not theirs (@uuid). Nothing was changed.', ['@uid' => $uid, '@uuid' => $uuid]);
      return 'unknown';
    }
    if (!$invoice instanceof Invoice) {
      $this->logger->error('Stripe payment for an unknown invoice (@uuid).', ['@uuid' => $uuid]);
      return 'unknown';
    }

    $currency = strtolower($invoice->getCurrency());
    if ((int) ($session->amount_total ?? -1) !== $invoice->getTotal() || strtolower((string) ($session->currency ?? '')) !== $currency) {
      $this->logger->error('Stripe payment for invoice @number does not match it: Stripe says @paid @paid_currency, the invoice is @total @currency. Nothing was changed.', [
        '@number' => $invoice->get('number')->value,
        '@paid' => $session->amount_total ?? '?',
        '@paid_currency' => $session->currency ?? '?',
        '@total' => $invoice->getTotal(),
        '@currency' => $currency,
      ]);
      return 'mismatch';
    }

    $status = $invoice->getStatus();
    if ($status === 'paid') {
      return 'already';
    }
    if ($status !== 'issued') {
      $this->logger->error('Stripe payment received for invoice @number, which is @status. Nothing was changed; check the Stripe dashboard.', ['@number' => $invoice->get('number')->value, '@status' => $status]);
      return 'ignored';
    }

    $invoice->set('stripe_payment_intent', (string) ($session->payment_intent ?? ''));
    // The date in the owner's timezone, like a manual "Mark as paid".
    $timezone = new \DateTimeZone($invoice->getOwner()?->getTimeZone() ?: date_default_timezone_get());
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($timezone)->format('Y-m-d');
    if ($problem = $this->manager->transition($invoice, 'pay', $today)) {
      $this->logger->error('Could not mark invoice @number paid: @problem', ['@number' => $invoice->get('number')->value, '@problem' => $problem]);
      return 'ignored';
    }
    return 'paid';
  }

}
