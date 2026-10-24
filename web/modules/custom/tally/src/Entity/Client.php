<?php

namespace Drupal\tally\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tally\InvoiceLanguage;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\tally\TallyAccessControlHandler;
use Drupal\tally\TallyListBuilder;

/**
 * Defines the Client entity.
 */
#[ContentEntityType(
  id: 'tally_client',
  label: new TranslatableMarkup('Client'),
  label_collection: new TranslatableMarkup('Clients'),
  label_singular: new TranslatableMarkup('client'),
  label_plural: new TranslatableMarkup('clients'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => TallyAccessControlHandler::class,
    'list_builder' => TallyListBuilder::class,
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: ['collection' => '/admin/content/tally/clients'],
  admin_permission: 'administer tally',
  base_table: 'tally_client',
  label_count: [
    'singular' => '@count client',
    'plural' => '@count clients',
  ],
)]
class Client extends TallyEntityBase {

  public const CURRENCIES = ['CAD' => 'CAD', 'USD' => 'USD', 'EUR' => 'EUR'];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['contact_first_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Billing contact first name'))
      ->setSetting('max_length', 255);
    $fields['contact_last_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Billing contact name'))
      ->setSetting('max_length', 255);
    // The column predates the name fields, so it keeps its name and holds the email.
    $fields['billing_contact'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Billing contact email'))
      ->setSetting('max_length', 255);
    // Where the invoice is addressed. All optional; "attention" is the
    // "c/o" line under the client's name (a contact and title, for example).
    foreach (['attention' => 'Attention line', 'address_street' => 'Street', 'address_city' => 'City', 'address_region' => 'Province or state', 'address_postal_code' => 'Postal code'] as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel(new TranslatableMarkup($label))
        ->setSetting('max_length', 255);
    }
    $fields['hourly_rate'] = static::centsField('Default hourly rate (cents)')
      ->setDefaultValue(0);
    $fields['currency'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Currency'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', static::CURRENCIES)
      ->setDefaultValue('CAD');
    // The language this client's invoices are written in; a new invoice starts with it.
    $fields['language'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Invoice language'))
      ->setSetting('allowed_values', InvoiceLanguage::LANGUAGES)
      ->setDefaultValue('en');
    $fields['archived'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Archived'))
      ->setDefaultValue(FALSE);

    return $fields;
  }

  /**
   * The billing contact's full name, or an empty string.
   */
  public function getContactName(): string {
    return trim($this->get('contact_first_name')->value . ' ' . $this->get('contact_last_name')->value);
  }

  /**
   * Default hourly rate in cents.
   */
  public function getHourlyRate(): int {
    return (int) $this->get('hourly_rate')->value;
  }

  /**
   * Currency code (CAD, USD or EUR).
   */
  public function getCurrency(): string {
    return $this->get('currency')->value ?: 'CAD';
  }

  /**
   * 'en' or 'fr': the language this client's invoices start in.
   */
  public function getLanguage(): string {
    return InvoiceLanguage::normalize($this->get('language')->value);
  }

  /**
   * Whether the client is archived.
   */
  public function isArchived(): bool {
    return (bool) $this->get('archived')->value;
  }

}
