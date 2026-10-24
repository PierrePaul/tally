<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\InvoiceDocument;
use Drupal\tally\InvoiceManager;
use Drupal\tally\InvoicePdf;
use Drupal\tally\InvoiceScreenBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Invoices screen and the HTMX requests that change an invoice.
 *
 * Every change answers with the refreshed screen. HX-Push-Url tells htmx what
 * to do with the address bar: a URL to push, or "false" to leave it alone.
 */
class InvoiceController extends ControllerBase {

  use PushUrlTrait;

  public function __construct(
    protected InvoiceScreenBuilder $builder,
    protected InvoiceManager $manager,
    protected InvoicePdf $pdf,
    protected InvoiceDocument $document,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.invoice_screen'), $container->get('tally.invoice_manager'), $container->get('tally.invoice_pdf'), $container->get('tally.invoice_document'));
  }

  /**
   * The full screen. HTMX navigation asks for it too and takes #invoices-screen.
   */
  public function page(Request $request): array {
    $options = $this->builder->options($request);
    if ($options['invoice'] !== NULL && !$this->manager->load($options['invoice'])) {
      throw new NotFoundHttpException();
    }
    return $this->builder->build($options);
  }

  /**
   * The invoice as a PDF download. A draft may be previewed here too.
   */
  public function pdf(string $invoice): Response {
    $entity = $this->manager->load($invoice);
    if (!$entity) {
      throw new NotFoundHttpException();
    }
    return new Response($this->pdf->render($entity), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'attachment; filename="' . $this->document->data($entity)['filename'] . '"',
      'Cache-Control' => 'no-store, private',
    ]);
  }

  /**
   * Starts a new draft and opens it.
   */
  public function newInvoice(Request $request): array {
    $options = ['filter' => 'all', 'from' => NULL, 'to' => NULL, 'invoice' => NULL];
    $invoice = $this->manager->createDraft();
    if (!$invoice) {
      return $this->withPushUrl($this->builder->build($options), FALSE);
    }
    $options['invoice'] = (string) $invoice->id();
    return $this->withPushUrl($this->builder->build($options), $this->builder->url($options));
  }

  /**
   * One change to one invoice: autosave, add or remove an item, move its status.
   */
  public function action(Request $request, string $invoice, string $action): array|Response {
    $entity = $this->manager->load($invoice);
    if (!$entity) {
      throw new NotFoundHttpException();
    }
    $options = ['invoice' => (string) $entity->id()] + $this->builder->options($request);
    $input = $request->request->all();
    $message = NULL;
    $push = FALSE;

    switch ($action) {
      case 'update':
        $message = $this->manager->apply($entity, $input);
        break;

      case 'add-fixed':
      case 'add-bank':
      case 'add-hosting':
      case 'add-line':
        $this->manager->addItem($entity, substr($action, 4));
        break;

      case 'remove':
        $this->manager->removeItem($entity, (string) ($input['item'] ?? ''));
        break;

      case 'reorder':
        // The page already shows the new order, so there is nothing to redraw.
        $this->manager->reorder($entity, (string) ($input['order'] ?? ''));
        return new Response('', 204);

      case 'delete':
        $this->manager->delete($entity);
        $options['invoice'] = NULL;
        $push = $this->builder->url($options);
        break;

      default:
        // Save what is on the form first, so "Mark as sent" sends what you see.
        $message = $this->manager->apply($entity, $input) ?? $this->manager->transition($entity, $action, is_string($input['paid_date'] ?? NULL) ? $input['paid_date'] : NULL);
    }
    return $this->withPushUrl($this->builder->build($options, $message), $push);
  }

}
