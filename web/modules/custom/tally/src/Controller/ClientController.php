<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\ClientScreenBuilder;
use Drupal\tally\EntityValidation;
use Drupal\tally\Money;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Clients screen and the HTMX requests that change it.
 *
 * Works like ProjectController: the screen is the URL, saves answer with the
 * refreshed screen and an HX-Push-Url header.
 */
class ClientController extends ControllerBase {

  use PushUrlTrait;

  public function __construct(protected ClientScreenBuilder $builder) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.client_screen'));
  }

  /**
   * The full screen. HTMX navigation asks for it too and takes #clients-screen.
   */
  public function page(Request $request): array {
    $options = $this->builder->options($request);
    if ($options['edit'] !== NULL && $options['edit'] !== 'new' && !$this->builder->loadClient($options['edit'])) {
      throw new NotFoundHttpException();
    }
    if ($options['report'] !== NULL && !$this->builder->loadClient((string) $options['report'])) {
      throw new NotFoundHttpException();
    }
    return $this->builder->build($options);
  }

  /**
   * The edit dialog redrawn from the form's current values (currency symbol
   * and the help text under the rate).
   */
  public function preview(Request $request): array {
    $options = $this->builder->options($request);
    $client = $this->builder->loadClient($options['edit']);
    if ($options['edit'] !== 'new' && !$client) {
      throw new NotFoundHttpException();
    }
    return $this->builder->buildPanel($options, $client, $this->builder->draft($request, $client));
  }

  /**
   * Saves the edit dialog. Invalid values reopen it with messages.
   */
  public function save(Request $request): array {
    $options = $this->builder->options($request);
    $client = $this->builder->loadClient($options['edit']);
    if ($options['edit'] !== 'new' && !$client) {
      throw new NotFoundHttpException();
    }
    $draft = $this->builder->draft($request, $client);
    $errors = $this->builder->validate($draft);
    if ($errors) {
      return $this->withPushUrl($this->builder->build($options, $draft, $errors), FALSE);
    }

    if (!$client) {
      $client = $this->entityTypeManager()->getStorage('tally_client')->create(['uid' => $this->currentUser()->id()]);
    }
    $client->set('name', $draft['name']);
    $client->set('contact_first_name', $draft['first_name']);
    $client->set('contact_last_name', $draft['last_name']);
    $client->set('billing_contact', $draft['email']);
    $client->set('attention', $draft['attention']);
    $client->set('address_street', $draft['street']);
    $client->set('address_city', $draft['city']);
    $client->set('address_region', $draft['region']);
    $client->set('address_postal_code', $draft['postal_code']);
    $client->set('hourly_rate', $draft['rate'] !== '' ? Money::parse($draft['rate']) : 0);
    $client->set('currency', $draft['currency']);
    $client->set('language', $draft['language']);
    // The entity's own constraints, behind the friendlier checks above.
    $errors = EntityValidation::byField($client->validate(), [
      'name' => 'name',
      'contact_first_name' => 'first_name',
      'contact_last_name' => 'last_name',
      'billing_contact' => 'email',
      'attention' => 'attention',
      'address_street' => 'street',
      'address_city' => 'city',
      'address_region' => 'region',
      'address_postal_code' => 'postal_code',
      'hourly_rate' => 'rate',
    ], 'name');
    if ($errors) {
      return $this->withPushUrl($this->builder->build($options, $draft, $errors), FALSE);
    }
    $client->save();

    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options), $this->builder->url($options, []));
  }

  /**
   * Archives or restores the client open in the dialog.
   */
  public function archive(Request $request): array {
    $options = $this->builder->options($request);
    $client = $this->builder->loadClient($options['edit']);
    if (!$client) {
      throw new NotFoundHttpException();
    }
    $client->set('archived', !$client->isArchived())->save();
    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options), $this->builder->url($options, []));
  }

}
