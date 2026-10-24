<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Url;
use Drupal\tally\Entity\Invoice;
use Drupal\tally\InvoiceDocument;
use Drupal\tally\InvoiceLanguage;
use Drupal\tally\InvoiceManager;
use Drupal\tally\InvoicePdf;
use Drupal\tally\Money;
use Drupal\tally\StripeCheckout;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public side of an invoice: the page a client opens from the shared link.
 *
 * There is no signed-in user here, so nothing may depend on the current user.
 * Every miss (unknown token, malformed token, draft invoice) is the same 404.
 */
class PublicInvoiceController extends ControllerBase {

  /**
   * How long the page waits for Stripe's webhook after the visitor comes back.
   */
  protected const WAIT_SECONDS = 30;

  public function __construct(
    protected InvoiceManager $manager,
    protected InvoiceDocument $document,
    protected InvoicePdf $pdf,
    protected StripeCheckout $stripe,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.invoice_manager'), $container->get('tally.invoice_document'), $container->get('tally.invoice_pdf'), $container->get('tally.stripe_checkout'));
  }

  /**
   * The invoice behind a token, or a 404.
   */
  protected function invoice(string $token): Invoice {
    // Before the lookup, so a 404 is not cached either: a draft that is sent
    // later must get its link back.
    \Drupal::service('page_cache_kill_switch')->trigger();
    return $this->manager->loadByToken($token) ?? throw new NotFoundHttpException();
  }

  /**
   * The invoice, laid out like the PDF. Never cached: its status changes when it is paid.
   */
  public function page(Request $request, string $token): array {
    $invoice = $this->invoice($token);
    return [
      '#title' => InvoiceLanguage::t('Invoice @number', $invoice->getLanguage(), ['@number' => $invoice->get('number')->value]),
      'document' => $this->document->build($invoice),
      'pay' => $this->payBlock($invoice, $token, $request->query->get('payment')),
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'library' => ['core/drupal.htmx'],
        'html_head' => [
          [['#tag' => 'meta', '#attributes' => ['name' => 'robots', 'content' => 'noindex, nofollow']], 'tally_robots'],
          [['#tag' => 'meta', '#attributes' => ['name' => 'referrer', 'content' => 'no-referrer']], 'tally_referrer'],
        ],
        'http_header' => [
          ['X-Robots-Tag', 'noindex, nofollow'],
          ['Referrer-Policy', 'no-referrer'],
          ['Cache-Control', 'no-store, private'],
        ],
      ],
    ];
  }

  /**
   * The PDF, shown in the browser.
   */
  public function pdf(string $token): Response {
    $invoice = $this->invoice($token);
    return new Response($this->pdf->render($invoice), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="' . $this->document->data($invoice)['filename'] . '"',
      'Cache-Control' => 'no-store, private',
      'X-Robots-Tag' => 'noindex, nofollow',
      'Referrer-Policy' => 'no-referrer',
    ]);
  }

  /**
   * Starts a Stripe Checkout payment and sends the visitor to Stripe's page.
   */
  public function pay(string $token): TrustedRedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse {
    $invoice = $this->invoice($token);
    if (!$this->stripe->canPay($invoice)) {
      throw new NotFoundHttpException();
    }
    try {
      return new TrustedRedirectResponse($this->stripe->createSession($invoice, $token), 303);
    }
    catch (\Throwable $e) {
      // The visitor gets a short message; the reason stays in the log.
      $this->getLogger('tally')->error('Could not start a Stripe payment for invoice @number: @message', ['@number' => $invoice->get('number')->value, '@message' => $e->getMessage()]);
      return new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromRoute('tally.public_invoice', ['token' => $token], ['query' => ['payment' => 'error']])->toString(), 303);
    }
  }

  /**
   * The payment block under the invoice.
   */
  protected function payBlock(Invoice $invoice, string $token, ?string $payment, ?int $since = NULL): array {
    $now = \Drupal::time()->getRequestTime();
    $state = match (TRUE) {
      $invoice->getStatus() === 'paid' => 'paid',
      // Back from Stripe before the webhook has landed: wait for it, but not forever.
      $invoice->getStatus() === 'issued' && ($payment === 'return' || $since !== NULL) => $now - ($since ?? $now) > static::WAIT_SECONDS ? 'processing' : 'confirming',
      $this->stripe->canPay($invoice) => 'pay',
      $invoice->getStatus() === 'cancelled' => 'cancelled',
      default => 'none',
    };
    $currency = $invoice->getCurrency();
    return [
      '#theme' => 'tally_invoice_pay',
      '#pay' => [
        'state' => $state,
        'error' => $payment === 'error',
        'lang' => $invoice->getLanguage(),
        'l' => InvoiceLanguage::labels($invoice->getLanguage()),
        'total' => Money::format($invoice->getTotal(), $currency, $invoice->getLanguage()),
        'pdf_url' => Url::fromRoute('tally.public_invoice.pdf', ['token' => $token])->toString(),
        'url' => Url::fromRoute('tally.public_invoice.pay', ['token' => $token])->toString(),
        'status_url' => Url::fromRoute('tally.public_invoice.status', ['token' => $token], ['query' => ['since' => $since ?? $now]])->toString(),
        'paid_text' => InvoiceLanguage::t('Paid on @date. Thank you!', $invoice->getLanguage(), ['@date' => InvoiceLanguage::date($invoice->getDate('paid_date') ?: date('Y-m-d'), $invoice->getLanguage())]),
      ],
    ];
  }

  /**
   * The payment block alone, for the page's HTMX polling.
   */
  public function status(Request $request, string $token): Response {
    $invoice = $this->invoice($token);
    if ($invoice->getStatus() === 'paid') {
      return new Response('', 200, (new Htmx())->refreshHeader(TRUE)->getHeaders()->all() + ['Cache-Control' => 'no-store, private']);
    }
    $build = $this->payBlock($invoice, $token, NULL, (int) $request->query->get('since'));
    return new Response((string) \Drupal::service('renderer')->renderInIsolation($build), 200, ['Cache-Control' => 'no-store, private']);
  }

}
