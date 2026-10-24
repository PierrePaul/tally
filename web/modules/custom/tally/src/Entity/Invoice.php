<?php

namespace Drupal\tally\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\tally\InvoiceLanguage;
use Drupal\tally\InvoiceStorageSchema;
use Drupal\tally\TallyAccessControlHandler;
use Drupal\tally\TallyListBuilder;

/**
 * Defines the Invoice entity.
 */
#[ContentEntityType(
  id: 'tally_invoice',
  label: new TranslatableMarkup('Invoice'),
  label_collection: new TranslatableMarkup('Invoices'),
  label_singular: new TranslatableMarkup('invoice'),
  label_plural: new TranslatableMarkup('invoices'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'number',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => TallyAccessControlHandler::class,
    'list_builder' => TallyListBuilder::class,
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
    'storage_schema' => InvoiceStorageSchema::class,
  ],
  links: ['collection' => '/admin/content/tally/invoices'],
  admin_permission: 'administer tally',
  base_table: 'tally_invoice',
  label_count: [
    'singular' => '@count invoice',
    'plural' => '@count invoices',
  ],
)]
class Invoice extends TallyEntityBase {

  public const STATUSES = [
    'draft' => 'Draft',
    'issued' => 'Awaiting payment',
    'paid' => 'Paid',
    // Sent, then written off: keeps its number, is no longer owed.
    'cancelled' => 'Cancelled',
  ];

  public const DISCOUNT_KINDS = ['percent' => 'Percent', 'amount' => 'Amount'];

  // Tax rates are stored on each invoice as a percentage times 1000 (5 % is
  // 5000, 9.975 % is 9975), so a later rate change never touches old invoices.
  // These are the rates of invoices that predate the fields, and the defaults.
  public const GST_RATE = 5000;
  public const QST_RATE = 9975;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Empty while the invoice is a draft; the number is taken when it is sent.
    $fields['number'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Number'))
      ->setSetting('max_length', 64);
    // The Stripe payment that paid the invoice, for the owner's records.
    $fields['stripe_payment_intent'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Stripe payment'))
      ->setSetting('max_length', 255);
    $fields['subject'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject'))
      ->setSetting('max_length', 255);
    $fields['client'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Client'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'tally_client');
    $fields['issue_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Issue date'))
      ->setSetting('datetime_type', 'date');
    $fields['due_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Due date'))
      ->setSetting('datetime_type', 'date');
    // Set when the invoice is marked as paid, and cleared if it is marked unpaid.
    $fields['paid_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Paid date'))
      ->setSetting('datetime_type', 'date');
    // Taken from the client when the invoice is sent, so a later change to the
    // client's currency cannot alter an invoice that went out.
    $fields['currency'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Currency'))
      ->setSetting('allowed_values', Client::CURRENCIES);
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', static::STATUSES)
      ->setDefaultValue('draft');
    $fields['charge_taxes'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Charge GST and QST'))
      ->setDefaultValue(TRUE);
    // Taken off the subtotal before taxes. The value is hundredths of a
    // percent for 'percent' (2500 is 25 %) and cents for 'amount'.
    $fields['discount_kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Discount type'))
      ->setSetting('allowed_values', static::DISCOUNT_KINDS)
      ->setDefaultValue('percent');
    $fields['discount_value'] = static::centsField('Discount (hundredths of a percent, or cents)');
    $fields['gst_rate'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('GST rate'))
      ->setDefaultValue(static::GST_RATE);
    $fields['qst_rate'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('QST rate'))
      ->setDefaultValue(static::QST_RATE);
    // Free text for the client, shown under the line items.
    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'));
    // The language the client reads the invoice in: public page, PDF, Stripe page.
    $fields['language'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Invoice language'))
      ->setSetting('allowed_values', InvoiceLanguage::LANGUAGES)
      ->setDefaultValue('en');
    // The secret in the public link (/i/{token}). Made when the invoice is sent.
    $fields['share_token'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Share token'))
      ->setSetting('max_length', 64);

    return $fields;
  }

  /**
   * The invoice's line items, in order.
   *
   * @return \Drupal\tally\Entity\InvoiceItem[]
   */
  public function getItems(): array {
    $storage = $this->entityTypeManager()->getStorage('tally_invoice_item');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('invoice', $this->id())
      ->sort('weight')
      ->sort('id')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->get('number')->isEmpty() ? (string) new TranslatableMarkup('Draft') : parent::label();
  }

  /**
   * The discount in cents for a subtotal, never more than the subtotal.
   */
  public function getDiscount(int $subtotal): int {
    $value = (int) $this->get('discount_value')->value;
    $discount = $this->get('discount_kind')->value === 'amount' ? $value : intdiv($subtotal * $value + 5000, 10000);
    return min($discount, $subtotal);
  }

  /**
   * Subtotal, discount, taxes and total in cents, from a single read of the items.
   *
   * @param \Drupal\tally\Entity\InvoiceItem[]|null $items
   *   The items, when the caller already has them.
   *
   * @return array{subtotal: int, discount: int, gst: int, qst: int, total: int}
   *   The taxes are worked out on the subtotal minus the discount.
   */
  public function getTotals(?array $items = NULL): array {
    $subtotal = array_sum(array_map(fn (InvoiceItem $item) => $item->getTotal(), $items ?? $this->getItems()));
    $discount = $this->getDiscount($subtotal);
    $rates = $this->getRates();
    $gst = $this->tax($subtotal - $discount, $rates['gst']);
    $qst = $this->tax($subtotal - $discount, $rates['qst']);
    return ['subtotal' => $subtotal, 'discount' => $discount, 'gst' => $gst, 'qst' => $qst, 'total' => $subtotal - $discount + $gst + $qst];
  }

  /**
   * The rates this invoice charges, as percentage times 1000.
   *
   * @return array{gst: int, qst: int}
   */
  public function getRates(): array {
    $gst = $this->get('gst_rate')->value;
    $qst = $this->get('qst_rate')->value;
    return [
      'gst' => $gst === NULL ? static::GST_RATE : (int) $gst,
      'qst' => $qst === NULL ? static::QST_RATE : (int) $qst,
    ];
  }

  /**
   * A stored rate as text for people: 5000 is "5", 9975 is "9.975".
   */
  public static function formatRate(int $rate): string {
    return rtrim(rtrim(number_format($rate / 1000, 3, '.', ''), '0'), '.');
  }

  /**
   * Subtotal minus discount plus taxes, in cents.
   */
  public function getTotal(): int {
    return $this->getTotals()['total'];
  }

  /**
   * Currency code. A draft follows its client; a sent invoice keeps its own.
   */
  public function getCurrency(): string {
    $client = $this->get('client')->entity;
    $current = $client instanceof Client ? $client->getCurrency() : 'CAD';
    return $this->getStatus() === 'draft' ? $current : ((string) $this->get('currency')->value ?: $current);
  }

  /**
   * 'en' or 'fr': the language the client reads this invoice in.
   */
  public function getLanguage(): string {
    return InvoiceLanguage::normalize($this->get('language')->value);
  }

  /**
   * The token of the public link, or '' when the invoice has none yet.
   */
  public function getShareToken(): string {
    return (string) $this->get('share_token')->value;
  }

  /**
   * Gives the invoice a share token if it has none. Does not save.
   *
   * 32 random bytes as base64url: 43 characters, 256 bits.
   */
  public function ensureShareToken(): string {
    if ($this->getShareToken() === '') {
      $this->set('share_token', rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }
    return $this->getShareToken();
  }

  /**
   * The status key: 'draft', 'issued', 'paid' or 'cancelled'.
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * A date field as 'Y-m-d', or '' when empty.
   */
  public function getDate(string $field): string {
    return (string) $this->get($field)->value;
  }

  /**
   * Integer-only tax math; $rate is the percentage times 1000.
   */
  protected function tax(int $subtotal, int $rate): int {
    if (!$this->get('charge_taxes')->value) {
      return 0;
    }
    return intdiv($subtotal * $rate + 50000, 100000);
  }

}
