<?php

namespace Drupal\tally\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tally\InvoiceLanguage;
use Drupal\tally\Money;
use Drupal\tally\ProjectReport;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\tally\TallyAccessControlHandler;
use Drupal\tally\TallyListBuilder;

/**
 * Defines the Invoice item entity.
 *
 * One entity type, three kinds: fixed price, bank of hours (hours x rate) and
 * hosting (months x price per month). Unused fields stay empty.
 */
#[ContentEntityType(
  id: 'tally_invoice_item',
  label: new TranslatableMarkup('Invoice item'),
  label_collection: new TranslatableMarkup('Invoice items'),
  label_singular: new TranslatableMarkup('invoice item'),
  label_plural: new TranslatableMarkup('invoice items'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'description',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => TallyAccessControlHandler::class,
    'list_builder' => TallyListBuilder::class,
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: ['collection' => '/admin/content/tally/invoice-items'],
  admin_permission: 'administer tally',
  base_table: 'tally_invoice_item',
  label_count: [
    'singular' => '@count invoice item',
    'plural' => '@count invoice items',
  ],
)]
class InvoiceItem extends TallyEntityBase {

  public const KINDS = [
    'fixed' => 'Fixed price',
    'bank' => 'Bank of hours',
    'hosting' => 'Hosting',
    'line' => 'Line item',
  ];

  public const TYPES = ['service' => 'Service', 'product' => 'Product'];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['invoice'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Invoice'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'tally_invoice');
    $fields['kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', static::KINDS)
      ->setDefaultValue('fixed');
    $fields['description'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setSetting('max_length', 255);
    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      ->setSetting('target_type', 'tally_project');
    $fields['details'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Details'));
    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDefaultValue(0);
    // Fixed price: the price in cents.
    $fields['price'] = static::centsField('Price (cents)');
    // Bank of hours: hours x rate.
    $fields['hours'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Hours'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);
    $fields['rate'] = static::centsField('Hourly rate (cents)');
    // Hosting: months x price per month.
    $fields['months'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Months'))
      ->setSetting('unsigned', TRUE);
    $fields['monthly_price'] = static::centsField('Price per month (cents)');
    // Line item: quantity x unit price, as a service or a product.
    $fields['item_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setSetting('allowed_values', static::TYPES)
      ->setDefaultValue('service');
    $fields['quantity'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Quantity'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);
    $fields['unit_price'] = static::centsField('Unit price (cents)');

    return $fields;
  }

  /**
   * Line total in cents.
   */
  public function getTotal(): int {
    return match ($this->get('kind')->value) {
      'bank' => $this->times((string) $this->get('hours')->value, (int) $this->get('rate')->value),
      'line' => $this->times((string) $this->get('quantity')->value, (int) $this->get('unit_price')->value),
      'hosting' => (int) $this->get('months')->value * (int) $this->get('monthly_price')->value,
      default => (int) $this->get('price')->value,
    };
  }

  /**
   * How the total is made up: "2h 30m × $105.00/h", "3 months × $20.00 per month".
   */
  public function getDetail(string $currency, string $lang = 'en'): string {
    $kind = (string) $this->get('kind')->value;
    $project = $this->get('project')->entity;
    $quantity = static::plainDecimal((string) $this->get('quantity')->value) ?: '0';
    $months = (int) $this->get('months')->value;
    return match ($kind) {
      'bank' => InvoiceLanguage::hours((int) round((float) $this->get('hours')->value * 3600), $lang) . ' × ' . Money::rate((int) $this->get('rate')->value, $currency, $lang),
      'hosting' => InvoiceLanguage::plural($months, '1 month', '@count months', $lang) . ' × ' . Money::format((int) $this->get('monthly_price')->value, $currency, $lang) . ' ' . InvoiceLanguage::t('per month', $lang),
      'line' => InvoiceLanguage::t('@type: @quantity × @price', $lang, [
        '@type' => InvoiceLanguage::labels($lang)[($this->get('item_type')->value ?: 'service') === 'product' ? 'product' : 'service'],
        '@quantity' => InvoiceLanguage::number($quantity, $lang),
        '@price' => Money::format((int) $this->get('unit_price')->value, $currency, $lang),
      ]),
      default => $project ? InvoiceLanguage::t('Project: @project', $lang, ['@project' => $project->label()]) : InvoiceLanguage::labels($lang)['no_project'],
    };
  }

  /**
   * "2.50" as "2.5", "0" as "": the form of a quantity people read and type.
   */
  public static function plainDecimal(string $value): string {
    return (float) $value > 0 ? rtrim(rtrim($value, '0'), '.') : '';
  }

  /**
   * A quantity (a decimal string, hours for example) times a price in cents,
   * rounded half up to cents.
   */
  protected function times(string $quantity, int $cents): int {
    // Quantities carry two decimals, so work in hundredths.
    $hundredths = (int) round((float) $quantity * 100);
    return intdiv($hundredths * $cents + 50, 100);
  }

}
