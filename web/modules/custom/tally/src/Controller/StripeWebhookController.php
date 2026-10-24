<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\InvoicePaymentRecorder;
use Drupal\tally\StripeAccounts;
use Drupal\tally\StripeCheckout;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use Stripe\WebhookSignature;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where Stripe tells us a payment went through.
 *
 * Open to everyone, so the signature is the only protection. Each user has
 * their own URL (/stripe/webhook/{id}) and their own signing secret: without
 * it, or with a bad signature, nothing is read, and the payment may only
 * touch that user's invoices. Everything that is properly signed answers 200, even when it changes
 * nothing, so that Stripe does not keep retrying. The one exception is
 * Stripe being unreachable when we look the payment up: 503 makes Stripe retry.
 *
 * Stripe delivers events in two shapes. A snapshot event ("object": "event")
 * carries the Checkout Session. A thin event ("object": "v2.core.event", type
 * prefixed "v1.") only points at it, so the session is fetched from Stripe.
 */
class StripeWebhookController extends ControllerBase {

  protected const TYPES = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

  public function __construct(
    protected InvoicePaymentRecorder $recorder,
    protected StripeCheckout $stripe,
    protected StripeAccounts $accounts,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.payment_recorder'), $container->get('tally.stripe_checkout'), $container->get('tally.stripe_accounts'));
  }

  public function handle(Request $request, string $endpoint): Response {
    $uid = $this->accounts->uidByEndpoint($endpoint) ?? throw new NotFoundHttpException();
    $secret = $this->accounts->webhookSecret($uid);
    if ($secret === '') {
      $this->getLogger('tally')->error('A Stripe webhook arrived for user @uid, who has not saved a webhook signing secret.', ['@uid' => $uid]);
      return new Response('Not configured', 503);
    }
    $payload = $request->getContent();
    try {
      WebhookSignature::verifyHeader($payload, (string) $request->headers->get('Stripe-Signature'), $secret);
      $data = json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (SignatureVerificationException | \JsonException $e) {
      // The reason helps with setup (usually a signing secret from another listener or endpoint). No secrets are logged.
      $this->getLogger('tally')->warning('Stripe webhook rejected (@class): @message', ['@class' => (new \ReflectionClass($e))->getShortName(), '@message' => $e->getMessage()]);
      return new Response('Invalid', 400);
    }
    if (!is_array($data)) {
      return new Response('Invalid', 400);
    }

    $type = (string) ($data['type'] ?? '');
    $session = NULL;
    if (($data['object'] ?? '') === 'event' && in_array($type, static::TYPES, TRUE)) {
      $session = StripeObject::constructFrom($data['data']['object'] ?? []);
    }
    elseif (($data['object'] ?? '') === 'v2.core.event' && in_array(preg_replace('/^v1\./', '', $type), static::TYPES, TRUE)) {
      $id = (string) ($data['related_object']['id'] ?? '');
      if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $id)) {
        return new Response('OK', 200);
      }
      try {
        $session = $this->stripe->retrieveSession($id, $uid);
      }
      catch (\Throwable $e) {
        $this->getLogger('tally')->error('Could not fetch Stripe session @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
        return new Response('Try again', 503);
      }
    }
    if ($session) {
      $this->recorder->record($session, $uid);
    }
    return new Response('OK', 200);
  }

}
